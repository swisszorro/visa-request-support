<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Fail-closed plausibility gate for passport reads.
 *
 * LLM engines return structured fields AND the raw MRZ lines. This class
 * re-derives the fields deterministically from the raw lines (MrzParser),
 * validates the ICAO check digits and cross-checks them against the structured
 * fields. Anything unverifiable ends up in $data['review'] (list of reasons);
 * the Matcher turns a non-empty list into status "review" so the applicant is
 * NOT written into the letter.
 *
 * Gating check digits: document number, date of birth, expiry date.
 * The composite digit and the line-length check only warn: LLM transcriptions
 * frequently add/drop a filler '<' behind the data fields (observed in 30 % of
 * reads), which breaks the composite but not the individually verified fields.
 */
final class MrzVerifier
{
    /** check digits that must be valid for an automatic release */
    private const REQUIRED = ['document' => 'passport number', 'dob' => 'date of birth', 'expiry' => 'expiry date'];

    /**
     * @param array<string,mixed> $data reader record (mrz, visual, raw_mrz_lines)
     * @return array<string,mixed> same record + check_digits, mrz_format, review
     */
    public static function apply(array $data, int $pivot, ?string $today = null): array
    {
        $data['mrz'] = $data['mrz'] ?? [];
        $data['visual'] = $data['visual'] ?? [];
        $raw = array_values(array_filter((array) ($data['raw_mrz_lines'] ?? []), 'is_string'));
        $data['raw_mrz_lines'] = $raw;
        $review = [];

        $fmt = MrzCheck::classifyLines($raw);
        $data['mrz_format'] = $fmt;

        $parsed = $raw !== [] ? (new MrzParser())->parse($raw, $pivot) : null;
        if ($parsed === null) {
            $data['check_digits'] = [];
            $data['review'] = ['MRZ lines missing or not parseable - fields cannot be verified'];
            return $data;
        }

        $checks = $parsed['check_digits'];
        $pm = $parsed['mrz'];
        $llm = $data['mrz'];

        // Second read of the passport number (LLM field) may repair an ambiguous OCR/LLM line read.
        if (empty($checks['document'])) {
            $line2 = (string) end($parsed['raw_mrz_lines']);
            $cd = strlen($line2) >= 10 ? $line2[9] : '';
            $alt = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($llm['document_number'] ?? '')));
            if ($alt !== '' && ctype_digit($cd)) {
                $field = substr(str_pad($alt, 9, '<'), 0, 9);
                $canon = MrzCheck::valid($field, $cd) ? rtrim($field, '<') : null;
                if ($canon === null) {
                    $rep = MrzCheck::repairDocNumber($field, $cd);
                    $canon = $rep !== null ? rtrim($rep, '<') : null;
                }
                if ($canon !== null) {
                    $pm['document_number'] = $canon;
                    $checks['document'] = true;
                }
            }
        }

        // Check-digit-verified fields: the parsed value is authoritative.
        $final = $llm;
        $pairs = ['document' => 'document_number', 'dob' => 'date_of_birth', 'expiry' => 'expiry_date'];
        foreach ($pairs as $chk => $field) {
            if (!empty($checks[$chk]) && !empty($pm[$field])) {
                $final[$field] = $pm[$field];
            }
        }

        // Fields without own check digit: both reads must agree, otherwise review.
        foreach (['surname', 'given_names'] as $f) {
            $a = self::alpha((string) ($pm[$f] ?? ''));
            $b = self::alpha((string) ($llm[$f] ?? ''));
            if ($b === '' && $a !== '') {
                $final[$f] = $pm[$f];
            } elseif ($a !== '' && $b !== '' && $a !== $b) {
                $review[] = "name read disagrees between MRZ lines and structured field ({$f})";
            }
        }
        $pn = strtoupper((string) ($pm['nationality'] ?? ''));
        $ln = strtoupper((string) ($llm['nationality'] ?? ''));
        if ($ln === '' && $pn !== '') {
            $final['nationality'] = $pn;
        } elseif ($pn !== '' && $ln !== '' && $pn !== $ln && !str_starts_with($ln, $pn) && !str_starts_with($pn, $ln)) {
            $review[] = 'nationality disagrees between MRZ lines and structured field';
        }
        $ps = (string) ($pm['sex'] ?? '');
        $ls = strtoupper((string) ($llm['sex'] ?? ''));
        if (empty($ls) && $ps !== '') {
            $final['sex'] = $ps;
        } elseif ($ps !== '' && $ls !== '' && $ps !== $ls) {
            $review[] = 'sex disagrees between MRZ lines and structured field';
        }

        // Only a passport (document code P*) may be released.
        $code = (string) ($parsed['document_code'] ?? '');
        if ($code !== '' && $code[0] !== 'P') {
            $review[] = "document is not a passport (MRZ document code '" . rtrim($code, '<') . "')";
        }

        $data['mrz'] = $final;
        $checks['mrz_lines'] = $fmt['valid'];
        $data['check_digits'] = $checks;
        $data['review'] = array_merge(self::reviewFromChecks($data), $review);
        return self::sanitizeVisual($data, $today);
    }

    /**
     * Reasons that block automatic release, derived from check digits and
     * mandatory fields. Also used for the OCR engine (which already has checks).
     *
     * @param array<string,mixed> $data
     * @return string[]
     */
    public static function reviewFromChecks(array $data): array
    {
        $out = [];
        $checks = $data['check_digits'] ?? [];
        foreach (self::REQUIRED as $key => $label) {
            if (($checks[$key] ?? false) !== true) {
                $out[] = "check digit failed or missing: {$label}";
            }
        }
        foreach (['document_number', 'date_of_birth', 'expiry_date', 'surname'] as $f) {
            if (trim((string) ($data['mrz'][$f] ?? '')) === '') {
                $out[] = "missing field: {$f}";
            }
        }
        return $out;
    }

    /**
     * The visual zone is read by a vision model without any check digit, so its
     * values are only used when plausible: place of birth is cleaned and
     * length-capped (no control characters / markup that could end up in the
     * letter), issue date must be a real date within dob < issue <= today <= expiry.
     * Implausible values are dropped (the Matcher then falls back to the Excel value).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function sanitizeVisual(array $data, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $v = $data['visual'] ?? [];
        $warn = [];

        $pob = (string) ($v['place_of_birth'] ?? '');
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F<>]+|\s{2,}/u', ' ', $pob));
        if (mb_strlen($clean) > 60 || preg_match('/https?:|ignore|instruction/i', $clean)) {
            $warn[] = 'place of birth read from the visual zone looks implausible and was dropped';
            $clean = '';
        }
        $v['place_of_birth'] = $clean !== '' ? $clean : null;

        $issue = $v['issue_date'] ?? null;
        if ($issue !== null && $issue !== '') {
            $dob = (string) ($data['mrz']['date_of_birth'] ?? '');
            $exp = (string) ($data['mrz']['expiry_date'] ?? '');
            $ok = self::isDate((string) $issue)
                && (string) $issue <= $today
                && ($dob === '' || (string) $issue > $dob)
                && ($exp === '' || (string) $issue < $exp);
            if (!$ok) {
                $warn[] = 'issue date read from the visual zone is not plausible and was dropped';
                $issue = null;
            }
        }
        $v['issue_date'] = $issue ?: null;

        $data['visual'] = $v;
        $data['warnings'] = array_merge((array) ($data['warnings'] ?? []), $warn);
        return $data;
    }

    public static function isDate(string $iso): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function alpha(string $s): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', $s));
    }
}

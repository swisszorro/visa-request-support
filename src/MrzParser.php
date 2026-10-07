<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Robust pure-PHP parser for the passport Machine Readable Zone.
 * Supports TD3 (passport, 2x44), TD2 (2x36) and TD1 (3x30).
 *
 * Two OCR hardening strategies:
 *  1. Field-typed character coercion — each MRZ field has a fixed type, so we
 *     fix the classic OCR confusions positionally: in numeric fields (dates,
 *     check digits) O->0, I->1, S->5, B->8 …; in alphabetic fields (names,
 *     issuing country) the reverse 0->O, 1->I, 5->S …
 *  2. Check-digit-guided alignment — the correct MRZ line *and* its left offset
 *     are chosen by maximising the number of valid ICAO check digits (incl. the
 *     composite). This repairs a shifted expiry date caused by a stray leading
 *     character or visual-zone text mixed into the OCR output.
 */
final class MrzParser
{
    /** letter -> digit (numeric fields) */
    private const TO_DIGIT = [
        'O' => '0', 'Q' => '0', 'D' => '0', 'U' => '0',
        'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5',
        'B' => '8', 'G' => '6', 'T' => '7',
    ];
    /** digit -> letter (alphabetic fields) */
    private const TO_ALPHA = [
        '0' => 'O', '1' => 'I', '2' => 'Z', '5' => 'S', '8' => 'B', '6' => 'G',
    ];

    /**
     * @param string[] $lines candidate OCR lines
     * @param int $pivot two-digit year pivot for the date-of-birth century
     * @return array<string,mixed>|null
     */
    public function parse(array $lines, int $pivot): ?array
    {
        // normalise: uppercase, keep only the MRZ charset, drop blanks
        $norm = [];
        foreach ($lines as $l) {
            $s = strtoupper((string) preg_replace('/[^A-Z0-9<]/i', '', $l));
            if ($s === '' || strlen($s) < 10) {
                continue;
            }
            $norm[] = $s;
            // OCR sometimes merges the two MRZ lines into one long string –
            // also offer the split halves as candidates (TD3≈88, TD2≈72).
            $len = strlen($s);
            if ($len >= 80 && $len <= 92) {
                $norm[] = substr($s, 0, 44);
                $norm[] = substr($s, $len - 44);
            } elseif ($len >= 66 && $len <= 76) {
                $norm[] = substr($s, 0, 36);
                $norm[] = substr($s, $len - 36);
            }
        }
        if ($norm === []) {
            return null;
        }

        // TD3 (passport) is the common case; fall back to TD2 / TD1.
        return $this->parseTd3($norm, $pivot)
            ?? $this->parseTd2($norm, $pivot)
            ?? $this->parseTd1($norm, $pivot);
    }

    // ---- TD3 (2 x 44) -----------------------------------------------------

    private function parseTd3(array $norm, int $pivot): ?array
    {
        $best = null;
        foreach ($norm as $idx => $line) {
            if (strlen($line) < 40 || strlen($line) > 48) {
                continue;
            }
            // try small left offsets to absorb a stray leading character
            foreach ([0, 1, 2, -1, -2] as $shift) {
                $adj = $shift >= 0 ? substr($line, $shift) : str_repeat('<', -$shift) . $line;
                $s = $this->fit($adj, 44);
                $cand = $this->scoreTd3Line2($s, $pivot);
                $cand['_idx'] = $idx;
                $cand['_shift'] = $shift;
                if ($best === null || $this->better($cand, $best)) {
                    $best = $cand;
                }
            }
        }

        // Best-effort: the check digits only RANK candidates, they do not gate.
        // We still require a plausible date so arbitrary text is not mistaken
        // for a passport line.
        if ($best === null || ($best['dob'] === null && $best['exp'] === null)) {
            return null;
        }

        $l1 = $this->findNameLine($norm, (int) $best['_idx']);
        [$surname, $given] = $l1 !== null ? $this->names($l1, 5) : ['', ''];

        return $this->result($surname, $given, $best, [$l1 ?? '', $best['_line']]);
    }

    /** Parse + score a 44-char candidate as a TD3 second line. */
    private function scoreTd3Line2(string $s, int $pivot): array
    {
        $docField = substr($s, 0, 9);
        $docCd    = $this->coerceDigit(substr($s, 9, 1));
        // repair O/0, I/1 … in the alphanumeric document number using its check digit
        $repaired = $this->repairDocNumber($docField, $docCd);
        if ($repaired !== null) {
            $docField = $repaired;
        }
        $nat      = $this->coerceAlpha(substr($s, 10, 3));
        $dob      = $this->coerceDigit(substr($s, 13, 6));
        $dobCd    = $this->coerceDigit(substr($s, 19, 1));
        $sex      = substr($s, 20, 1);
        $exp      = $this->coerceDigit(substr($s, 21, 6));
        $expCd    = $this->coerceDigit(substr($s, 27, 1));
        $compCd   = $this->coerceDigit(substr($s, 43, 1));
        $compositeField = substr($s, 0, 10) . substr($s, 13, 7) . substr($s, 21, 22);

        $checks = [
            'document' => $this->verify($docField, $docCd),
            'dob'      => $this->verify($dob, $dobCd),
            'expiry'   => $this->verify($exp, $expCd),
            'composite'=> $this->verify($compositeField, $compCd),
        ];
        // composite counts double: it spans the whole line, so it is the
        // strongest evidence the alignment is correct.
        $score = ($checks['document'] ? 1 : 0)
            + ($checks['dob'] ? 1 : 0)
            + ($checks['expiry'] ? 1 : 0)
            + ($checks['composite'] ? 2 : 0);

        return [
            'score'   => $score,
            'digits'  => strlen((string) preg_replace('/[^0-9]/', '', $s)),
            '_line'   => $s,
            'doc'     => rtrim($docField, '<'),
            'nat'     => rtrim($nat, '<'),
            'dob'     => $this->toIso($dob, $pivot, false),
            'sex'     => $this->normSex($sex),
            'exp'     => $this->toIso($exp, $pivot, true),
            'checks'  => $checks,
        ];
    }

    /**
     * Ranking between two TD3 candidates:
     *  1. more valid check digits  2. more digit characters (line 2 is
     *  numeric-heavy, the name line is not)  3. prefer no shift  4. prefer a
     *  later line (the MRZ sits at the bottom of the page).
     */
    private function better(array $a, array $b): bool
    {
        if ($a['score'] !== $b['score']) {
            return $a['score'] > $b['score'];
        }
        if ($a['digits'] !== $b['digits']) {
            return $a['digits'] > $b['digits'];
        }
        $as = $a['_shift'] === 0 ? 1 : 0;
        $bs = $b['_shift'] === 0 ? 1 : 0;
        if ($as !== $bs) {
            return $as > $bs;
        }
        return $a['_idx'] >= $b['_idx'];
    }

    // ---- TD2 (2 x 36) -----------------------------------------------------

    private function parseTd2(array $norm, int $pivot): ?array
    {
        foreach ($norm as $idx => $line) {
            if (abs(strlen($line) - 36) > 2) {
                continue;
            }
            $s = $this->fit($line, 36);
            $docField = substr($s, 0, 9);
            $docCd = $this->coerceDigit(substr($s, 9, 1));
            $nat   = $this->coerceAlpha(substr($s, 10, 3));
            $dob   = $this->coerceDigit(substr($s, 13, 6));
            $dobCd = $this->coerceDigit(substr($s, 19, 1));
            $sex   = substr($s, 20, 1);
            $exp   = $this->coerceDigit(substr($s, 21, 6));
            $expCd = $this->coerceDigit(substr($s, 27, 1));

            $checks = [
                'document' => $this->verify($docField, $docCd),
                'dob'      => $this->verify($dob, $dobCd),
                'expiry'   => $this->verify($exp, $expCd),
            ];
            $isoDob = $this->toIso($dob, $pivot, false);
            $isoExp = $this->toIso($exp, $pivot, true);
            if ($isoDob === null && $isoExp === null) {
                continue; // not a plausible MRZ line 2
            }
            $l1 = $this->findNameLine($norm, $idx);
            [$surname, $given] = $l1 !== null ? $this->names($l1, 5) : ['', ''];
            return $this->result($surname, $given, [
                'doc' => rtrim($docField, '<'), 'nat' => rtrim($nat, '<'),
                'dob' => $isoDob, 'sex' => $this->normSex($sex),
                'exp' => $isoExp, 'checks' => $checks,
            ], [$l1 ?? '', $s]);
        }
        return null;
    }

    // ---- TD1 (3 x 30) -----------------------------------------------------

    private function parseTd1(array $norm, int $pivot): ?array
    {
        $rows = array_values(array_filter($norm, static fn ($l) => abs(strlen($l) - 30) <= 2));
        if (count($rows) < 3) {
            return null;
        }
        $rows = array_slice($rows, -3);
        $l1 = $this->fit($rows[0], 30);
        $l2 = $this->fit($rows[1], 30);
        $l3 = $rows[2];

        $docField = substr($l1, 5, 9);
        $docCd = $this->coerceDigit(substr($l1, 14, 1));
        $dob   = $this->coerceDigit(substr($l2, 0, 6));
        $dobCd = $this->coerceDigit(substr($l2, 6, 1));
        $sex   = substr($l2, 7, 1);
        $exp   = $this->coerceDigit(substr($l2, 8, 6));
        $expCd = $this->coerceDigit(substr($l2, 14, 1));
        $nat   = $this->coerceAlpha(substr($l2, 15, 3));

        $checks = [
            'document' => $this->verify($docField, $docCd),
            'dob'      => $this->verify($dob, $dobCd),
            'expiry'   => $this->verify($exp, $expCd),
        ];
        $isoDob = $this->toIso($dob, $pivot, false);
        $isoExp = $this->toIso($exp, $pivot, true);
        if ($isoDob === null && $isoExp === null) {
            return null;
        }
        [$surname, $given] = $this->names($l3, 0);
        return $this->result($surname, $given, [
            'doc' => rtrim($docField, '<'), 'nat' => rtrim($nat, '<'),
            'dob' => $isoDob, 'sex' => $this->normSex($sex),
            'exp' => $isoExp, 'checks' => $checks,
        ], [$l1, $l2, $l3]);
    }

    // ---- shared helpers ---------------------------------------------------

    /** @return array<string,mixed> */
    private function result(string $surname, string $given, array $f, array $rawLines): array
    {
        return [
            'passport_detected' => true,
            'mrz' => [
                'surname'         => $surname ?: null,
                'given_names'     => $given ?: null,
                'document_number' => ($f['doc'] ?? '') ?: null,
                'nationality'     => ($f['nat'] ?? '') ?: null,
                'date_of_birth'   => $f['dob'] ?? null,
                'sex'             => $f['sex'] ?? null,
                'expiry_date'     => $f['exp'] ?? null,
            ],
            'visual' => ['place_of_birth' => null, 'issue_date' => null],
            'raw_mrz_lines' => $rawLines,
            'document_code' => strtoupper(substr((string) ($rawLines[0] ?? ''), 0, 2)),
            'check_digits'  => $f['checks'] ?? [],
        ];
    }

    /** Truncate-or-pad a line to exactly $len characters (left aligned). */
    private function fit(string $s, int $len): string
    {
        return strlen($s) >= $len ? substr($s, 0, $len) : str_pad($s, $len, '<');
    }

    /**
     * The name line (MRZ line 1). Preference order:
     *  1. a line containing "<<" just before line 2,
     *  2. any other line containing "<<",
     *  3. fallback: the 40–48 char line (other than line 2) richest in letters.
     */
    private function findNameLine(array $norm, int $l2idx): ?string
    {
        for ($i = $l2idx - 1; $i >= 0; $i--) {
            if (str_contains($norm[$i], '<<')) {
                return $norm[$i];
            }
        }
        foreach ($norm as $i => $line) {
            if ($i !== $l2idx && str_contains($line, '<<')) {
                return $line;
            }
        }
        // fallback: most letters among plausible-length lines (names are alpha)
        $best = null;
        $bestAlpha = 0;
        foreach ($norm as $i => $line) {
            if ($i === $l2idx || strlen($line) < 40 || strlen($line) > 48) {
                continue;
            }
            $alpha = (int) preg_match_all('/[A-Z]/', $line);
            if ($alpha > $bestAlpha) {
                $bestAlpha = $alpha;
                $best = $line;
            }
        }
        return $best;
    }

    /** @return array{0:string,1:string} surname, given (alpha-coerced) */
    private function names(string $line, int $start): array
    {
        $field = substr($line, $start);
        $parts = explode('<<', $field, 2);
        $surname = $this->cleanName($this->coerceAlpha($parts[0]));
        $given = isset($parts[1]) ? $this->cleanName($this->coerceAlpha($parts[1])) : '';
        return [$surname, $given];
    }

    private function cleanName(string $s): string
    {
        $s = str_replace('<', ' ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    private function coerceDigit(string $s): string
    {
        $out = '';
        foreach (str_split($s) as $c) {
            $out .= $c === '<' ? '<' : (self::TO_DIGIT[$c] ?? $c);
        }
        return $out;
    }

    private function coerceAlpha(string $s): string
    {
        $out = '';
        foreach (str_split($s) as $c) {
            $out .= $c === '<' ? '<' : (self::TO_ALPHA[$c] ?? $c);
        }
        return $out;
    }

    /** Repair the document number via its check digit (shared util). */
    private function repairDocNumber(string $field, string $cd): ?string
    {
        return MrzCheck::repairDocNumber($field, $cd);
    }

    private function verify(string $field, string $cd): bool
    {
        return MrzCheck::valid($field, $cd);
    }

    private function normSex(string $s): ?string
    {
        $s = strtoupper(trim($s));
        return match ($s) {
            'M' => 'M',
            'F' => 'F',
            default => null,
        };
    }

    private function toIso(string $yymmdd, int $pivot, bool $isExpiry): ?string
    {
        if (!preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }
        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);
        // Expiry is always 20xx; DOB uses a pivot year and must never lie in the future.
        $year = $isExpiry ? 2000 + $yy : ($yy <= $pivot ? 2000 + $yy : 1900 + $yy);
        if (!$isExpiry && sprintf('%04d-%02d-%02d', $year, $mm, $dd) > date('Y-m-d')) {
            $year -= 100;
        }
        if (!checkdate($mm, $dd, $year)) {
            return null; // e.g. 31 Feb, 29 Feb in a non-leap year
        }
        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }
}

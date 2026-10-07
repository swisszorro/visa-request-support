<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Builds a human-readable log block describing what was read from one passport
 * copy: the raw OCR text (if any), the raw MRZ lines and every parsed field
 * together with its ICAO check-digit status.
 */
final class ExtractionLog
{
    /**
     * @param array<string,mixed> $parsed result from a PassportReader (may be null fields)
     */
    public static function format(string $engine, string $source, int $page, ?string $rawOcr, ?array $parsed): string
    {
        $nl = "\n";
        $out = str_repeat('─', 70) . $nl;

        if ($parsed === null) {
            $out .= sprintf("NO PASSPORT DETECTED  ·  file: %s (page %d)  ·  engine: %s%s", $source, $page, $engine, $nl);
            if ($rawOcr !== null && trim($rawOcr) !== '') {
                $out .= 'Raw OCR text:' . $nl . self::indent($rawOcr) . $nl;
            }
            return $out;
        }

        $mrz = $parsed['mrz'] ?? [];
        $visual = $parsed['visual'] ?? [];
        $checks = $parsed['check_digits'] ?? [];
        $rawLines = $parsed['raw_mrz_lines'] ?? [];

        $out .= sprintf("PASSPORT READ  ·  file: %s (page %d)  ·  engine: %s%s", $source, $page, $engine, $nl);

        if ($rawOcr !== null && trim($rawOcr) !== '') {
            $out .= 'Raw OCR text (MRZ pass):' . $nl . self::indent($rawOcr) . $nl;
        }

        if ($rawLines !== []) {
            $out .= 'Raw MRZ lines (used):' . $nl;
            foreach ($rawLines as $line) {
                if (trim((string) $line) !== '') {
                    $out .= '    ' . $line . $nl;
                }
            }
        }

        $fmt = $parsed['mrz_format'] ?? null;
        if (is_array($fmt)) {
            $out .= sprintf(
                'MRZ format: %s (expected line length %d) — %s%s',
                $fmt['type'] ?? '?',
                $fmt['expected'] ?? 0,
                ($fmt['valid'] ?? false) ? 'VALID' : 'INVALID: ' . ($fmt['reason'] ?? ''),
                $nl
            );
            foreach ($fmt['lines'] ?? [] as $i => $l) {
                $out .= sprintf("    line %d: len %d   [%s]%s", $i + 1, $l['len'], $l['ok'] ? 'OK' : 'FAIL', $nl);
            }
        }

        $out .= 'Parsed fields:' . $nl;
        $out .= self::field('Surname',        $mrz['surname'] ?? null);
        $out .= self::field('Given names',    $mrz['given_names'] ?? null);
        $out .= self::field('Passport no.',   $mrz['document_number'] ?? null, $checks['document'] ?? null);
        $out .= self::field('Nationality',    $mrz['nationality'] ?? null);
        $out .= self::field('Date of birth',  $mrz['date_of_birth'] ?? null, $checks['dob'] ?? null);
        $out .= self::field('Sex',            $mrz['sex'] ?? null);
        $out .= self::field('Expiry date',    $mrz['expiry_date'] ?? null, $checks['expiry'] ?? null);
        if (array_key_exists('composite', $checks)) {
            $out .= self::field('Composite',  '(whole line 2)', $checks['composite']);
        }
        $out .= self::field('Place of birth', ($visual['place_of_birth'] ?? null) ? $visual['place_of_birth'] . ' (visual zone)' : null);
        $out .= self::field('Issue date',     ($visual['issue_date'] ?? null) ? $visual['issue_date'] . ' (visual zone)' : null);

        if ($checks !== []) {
            $valid = count(array_filter($checks, static fn ($v) => $v === true));
            $out .= sprintf('Check digits valid: %d/%d%s', $valid, count($checks), $nl);
        }

        return $out;
    }

    private static function field(string $label, ?string $value, ?bool $check = null): string
    {
        $val = ($value === null || $value === '') ? '–' : $value;
        $line = sprintf('    %-15s: %s', $label, $val);
        if ($check !== null) {
            $line .= '   [check: ' . ($check ? 'OK' : 'FAIL') . ']';
        }
        return $line . "\n";
    }

    private static function indent(string $text): string
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];
        return implode("\n", array_map(static fn ($l) => '    | ' . $l, $lines));
    }
}

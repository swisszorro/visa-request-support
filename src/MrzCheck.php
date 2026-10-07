<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * ICAO 9303 check-digit utilities shared by all engines.
 *
 * Key use: the passport number carries its own check digit in the MRZ, so a
 * 0/O (and S/5, B/8, I/1 …) confusion can be detected and repaired
 * deterministically — independent of how the number was read (OCR or LLM).
 */
final class MrzCheck
{
    /** Bidirectional OCR/LLM-confusable sets for repairing an alphanumeric field. */
    private const CONFUSE = [
        '0' => ['0', 'O', 'Q', 'D'], 'O' => ['O', '0', 'Q', 'D'],
        'Q' => ['Q', '0', 'O'],      'D' => ['D', '0', 'O'],
        '1' => ['1', 'I', 'L'],      'I' => ['I', '1', 'L'], 'L' => ['L', '1', 'I'],
        '2' => ['2', 'Z'],           'Z' => ['Z', '2'],
        '5' => ['5', 'S'],           'S' => ['S', '5'],
        '8' => ['8', 'B'],           'B' => ['B', '8'],
        '6' => ['6', 'G'],           'G' => ['G', '6'],
        '7' => ['7', 'T'],           'T' => ['T', '7'],
    ];

    /** ICAO document formats: required line count + exact line length. */
    private const FORMATS = [
        'TD1' => ['lines' => 3, 'len' => 30],
        'TD2' => ['lines' => 2, 'len' => 36],
        'TD3' => ['lines' => 2, 'len' => 44], // passport
    ];

    /**
     * Validate MRZ line lengths against the ICAO spec. Wrong lengths shift every
     * field offset, so this must be checked before trusting parsed fields.
     *
     * The data-bearing line (line 2 of TD2/TD3, line 1 of TD1 — the digit-heavy
     * one) must match the spec length EXACTLY. The name line may legitimately
     * arrive with trailing '<' fillers dropped, so it is only required not to
     * exceed the spec length (short = padding, harmless; longer = suspicious).
     *
     * @param string[] $rawLines
     * @return array{type:?string,expected:int,required_lines:int,lines:array<int,array{text:string,len:int,ok:bool}>,valid:bool,reason:string}
     */
    public static function classifyLines(array $rawLines): array
    {
        $norm = [];
        foreach ($rawLines as $l) {
            $s = strtoupper((string) preg_replace('/[^A-Z0-9<]/i', '', (string) $l));
            if (strlen($s) >= 10) {
                $norm[] = $s;
            }
        }

        $count = count($norm);
        if ($count < 2) {
            return ['type' => null, 'expected' => 0, 'required_lines' => 0,
                'lines' => array_map(fn ($s) => ['text' => $s, 'len' => strlen($s), 'ok' => false], $norm),
                'valid' => false, 'reason' => 'too few MRZ lines (' . $count . ')'];
        }

        // Anchor on the data-bearing line (most digits, tie → longest): its
        // length determines the format. Robust against a short/over-padded name
        // line and stray non-MRZ lines.
        $dataLine = $norm[0];
        $bestScore = [-1, -1];
        foreach ($norm as $s) {
            $score = [strlen((string) preg_replace('/[^0-9]/', '', $s)), strlen($s)];
            if ($score > $bestScore) {
                $bestScore = $score;
                $dataLine = $s;
            }
        }
        $dlen = strlen($dataLine);
        $type = match (true) {
            $dlen >= 28 && $dlen <= 32 => 'TD1',
            $dlen >= 34 && $dlen <= 38 => 'TD2',
            default                    => 'TD3', // 40-50 and anything else → passport
        };
        $expected = self::FORMATS[$type]['len'];
        $required = self::FORMATS[$type]['lines'];

        // name line(s) = the longest lines other than the data line
        $others = array_values(array_filter($norm, fn ($s) => $s !== $dataLine));
        usort($others, fn ($a, $b) => strlen($b) <=> strlen($a));
        $use = array_merge([$dataLine], array_slice($others, 0, $required - 1));

        $lines = [];
        $allOk = true;
        foreach ($use as $s) {
            $len = strlen($s);
            // Only the fixed-field data line must match the spec length exactly;
            // the name line may carry more/fewer trailing '<' fillers harmlessly.
            $ok = $s === $dataLine ? ($len === $expected) : ($len >= 5);
            $lines[] = ['text' => $s, 'len' => $len, 'ok' => $ok];
            $allOk = $allOk && $ok;
        }

        $valid = $allOk && count($use) === $required;
        $reason = $valid ? 'ok' : 'line length / count mismatch for ' . $type . ' (expected ' . $expected . ')';
        return ['type' => $type, 'expected' => $expected, 'required_lines' => $required,
            'lines' => $lines, 'valid' => $valid, 'reason' => $reason];
    }

    /** ICAO check digit (weights 7-3-1; '<'=0, digits=value, A-Z=10..35). */
    public static function digit(string $s): int
    {
        $weights = [7, 3, 1];
        $sum = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '<') {
                $v = 0;
            } elseif (ctype_digit($c)) {
                $v = (int) $c;
            } elseif ($c >= 'A' && $c <= 'Z') {
                $v = ord($c) - 55;
            } else {
                $v = 0;
            }
            $sum += $v * $weights[$i % 3];
        }
        return $sum % 10;
    }

    public static function valid(string $field, string $cd): bool
    {
        return ctype_digit($cd) && self::digit($field) === (int) $cd;
    }

    /**
     * Repair an alphanumeric field (e.g. the 9-char document number) using its
     * check digit by trying only OCR/LLM-confusable substitutions (O/0, I/1 …).
     *
     * The check digit is mod-10, so several substitutions can satisfy it. To
     * avoid silently picking a wrong one we accept a repair ONLY if it is the
     * UNIQUE check-valid candidate at the minimum number of changes. Otherwise
     * null (caller treats the number as unverified → flagged for manual check).
     *
     * Returns null if already valid, no fix, ambiguous, or too large to search.
     */
    public static function repairDocNumber(string $field, string $cd): ?string
    {
        if (!ctype_digit($cd) || self::digit($field) === (int) $cd) {
            return null;
        }

        $choices = [];
        $combos = 1;
        foreach (str_split($field) as $c) {
            $opts = self::CONFUSE[$c] ?? [$c];
            $choices[] = $opts;
            $combos *= count($opts);
        }
        if ($combos > 50000) {
            return null;
        }

        $target = (int) $cd;
        $valid = [];
        foreach (self::expand($choices) as $cand) {
            if (self::digit($cand) === $target) {
                $valid[] = $cand;
            }
        }
        if ($valid === []) {
            return null;
        }

        // group by edit distance from the original read; require a unique winner
        $byDist = [];
        foreach ($valid as $cand) {
            $byDist[self::editDistance($field, $cand)][$cand] = true;
        }
        ksort($byDist);
        $closest = array_keys(reset($byDist));
        return count($closest) === 1 ? $closest[0] : null;
    }

    private static function editDistance(string $a, string $b): int
    {
        $n = 0;
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                $n++;
            }
        }
        return $n + abs(strlen($a) - strlen($b));
    }

    /**
     * Cartesian product of per-position character choices.
     * @param array<int,array<int,string>> $choices
     * @return string[]
     */
    private static function expand(array $choices): array
    {
        $out = [''];
        foreach ($choices as $opts) {
            $next = [];
            foreach ($out as $prefix) {
                foreach ($opts as $c) {
                    $next[] = $prefix . $c;
                }
            }
            $out = $next;
        }
        return $out;
    }
}

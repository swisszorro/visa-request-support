<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Matches each Excel applicant against the passports detected by the vision API
 * and verifies the required fields. Produces a per-applicant result object.
 */
final class Matcher
{
    /**
     * @param int         $minValidityMonths passport must be valid this many months beyond the departure date
     * @param string|null $today             ISO date, injectable for tests
     * @param string|null $eventStart        optional ISO date; arrival/departure outside the window only warn
     */
    public function __construct(
        private readonly bool $placeOfBirthWarnOnly = true,
        private readonly int $minValidityMonths = 6,
        private readonly ?string $today = null,
        private readonly ?string $eventStart = null,
        private readonly ?string $eventEnd = null,
    ) {
    }

    /**
     * @param array<int,array<string,string>> $applicants  Excel rows
     * @param array<int,array<string,mixed>>  $passports    detected passports
     * @return array<int,array<string,mixed>> results aligned with $applicants
     */
    public function match(array $applicants, array $passports): array
    {
        $results = [];
        $usedPassportIdx = [];

        foreach ($applicants as $app) {
            [$idx, $pp] = $this->findPassport($app, $passports, $usedPassportIdx);
            if ($pp === null) {
                $results[] = [
                    'no'            => $app['no'] ?? '',
                    'full_name'     => $app['full_name'] ?? '',
                    'date_of_birth' => $app['date_of_birth'] ?? '',
                    'status'        => 'failed',
                    'matched'       => false,
                    'source_image'  => null,
                    'reason'        => 'No matching passport found among the uploaded images.',
                    'mismatches'    => [],
                    'checks'        => [],
                    'verified'      => null,
                ];
                continue;
            }
            $usedPassportIdx[$idx] = true;
            $results[] = $this->verify($app, $pp);
        }

        return $results;
    }

    /**
     * @return array{0:?int,1:?array<string,mixed>}
     */
    private function findPassport(array $app, array $passports, array $used): array
    {
        $wantNo = $this->normNumber((string) ($app['passport_number'] ?? ''));

        // 1) strong match by passport number
        if ($wantNo !== '') {
            foreach ($passports as $i => $pp) {
                if (isset($used[$i])) {
                    continue;
                }
                if ($this->normNumber((string) ($pp['mrz']['document_number'] ?? '')) === $wantNo) {
                    return [$i, $pp];
                }
            }
        }

        // 2) fallback: name + date of birth
        $appDob = (string) ($app['date_of_birth'] ?? '');
        $best = null;
        $bestIdx = null;
        $bestScore = 0.0;
        foreach ($passports as $i => $pp) {
            if (isset($used[$i])) {
                continue;
            }
            $score = $this->nameScore((string) ($app['full_name'] ?? ''), ($pp['mrz']['surname'] ?? '') . ' ' . ($pp['mrz']['given_names'] ?? ''));
            $dobOk = $appDob !== '' && $appDob === (string) ($pp['mrz']['date_of_birth'] ?? '');
            if ($dobOk) {
                $score += 0.5;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $pp;
                $bestIdx = $i;
            }
        }
        // require a reasonably confident name match to avoid wrong pairing
        if ($best !== null && $bestScore >= 0.6) {
            return [$bestIdx, $best];
        }

        return [null, null];
    }

    private function verify(array $app, array $pp): array
    {
        $mrz = $pp['mrz'] ?? [];
        $visual = $pp['visual'] ?? [];

        $checks = [];

        // name
        $ppName = trim(($mrz['surname'] ?? '') . ' ' . ($mrz['given_names'] ?? ''));
        $nameScore = $this->nameScore((string) ($app['full_name'] ?? ''), $ppName);
        $checks['name'] = $this->check(
            $app['full_name'] ?? '',
            $ppName,
            $nameScore >= 0.7,
            true
        );

        // date of birth
        $checks['date_of_birth'] = $this->check(
            $app['date_of_birth'] ?? '',
            (string) ($mrz['date_of_birth'] ?? ''),
            ($app['date_of_birth'] ?? '') !== '' && ($app['date_of_birth'] ?? '') === ($mrz['date_of_birth'] ?? ''),
            true
        );

        // passport number
        $checks['passport_number'] = $this->check(
            $app['passport_number'] ?? '',
            (string) ($mrz['document_number'] ?? ''),
            $this->normNumber((string) ($app['passport_number'] ?? '')) === $this->normNumber((string) ($mrz['document_number'] ?? '')),
            true
        );

        // gender
        $appSex = $this->normSex((string) ($app['gender'] ?? ''));
        $ppSex = $this->normSex((string) ($mrz['sex'] ?? ''));
        $checks['gender'] = $this->check($app['gender'] ?? '', $mrz['sex'] ?? '', $appSex !== '' && $appSex === $ppSex, true);

        // nationality
        $checks['nationality'] = $this->check(
            $app['nationality'] ?? '',
            (string) ($mrz['nationality'] ?? ''),
            $this->nationalityMatches((string) ($app['nationality'] ?? ''), (string) ($mrz['nationality'] ?? '')),
            true
        );

        // expiry
        $checks['expiry_date'] = $this->check(
            $app['passport_expiry_date'] ?? '',
            (string) ($mrz['expiry_date'] ?? ''),
            ($app['passport_expiry_date'] ?? '') !== '' && ($app['passport_expiry_date'] ?? '') === ($mrz['expiry_date'] ?? ''),
            true
        );

        // validity: expiry must lie minValidityMonths beyond the departure (else arrival, else today)
        $today = $this->today ?? date('Y-m-d');
        $arr = (string) ($app['date_of_arrival'] ?? '');
        $dep = (string) ($app['date_of_departure'] ?? '');
        $ref = MrzVerifier::isDate($dep) ? $dep : (MrzVerifier::isDate($arr) ? $arr : $today);
        $needUntil = (new \DateTimeImmutable($ref))->modify("+{$this->minValidityMonths} months")->format('Y-m-d');
        $expiry = (string) ($mrz['expiry_date'] ?? '');
        $checks['passport_validity'] = $this->check(
            "valid until at least {$needUntil}",
            $expiry,
            $expiry !== '' && $expiry >= $needUntil,
            true
        );

        // travel dates: both present, real dates, departure not before arrival
        $datesOk = MrzVerifier::isDate($arr) && MrzVerifier::isDate($dep) && $dep >= $arr;
        $checks['travel_dates'] = $this->check(
            'arrival and departure present, departure >= arrival',
            "{$arr} / {$dep}",
            $datesOk,
            true
        );

        // place of birth (visual zone — not in MRZ). Fuzzy & optionally warn-only.
        $pobOk = $this->fuzzyContains((string) ($app['place_of_birth'] ?? ''), (string) ($visual['place_of_birth'] ?? ''));
        $checks['place_of_birth'] = $this->check(
            $app['place_of_birth'] ?? '',
            (string) ($visual['place_of_birth'] ?? ''),
            $pobOk,
            !$this->placeOfBirthWarnOnly
        );

        // overall – collect the failing fields together with both compared values
        $mismatches = [];
        foreach ($checks as $field => $c) {
            if ($c['required'] && !$c['ok']) {
                $mismatches[] = [
                    'field'    => $field,
                    'expected' => $c['expected'],   // value from the Excel list
                    'found'    => $c['found'],       // value read from the passport (MRZ / visual)
                ];
            }
        }
        $warnings = array_values((array) ($pp['warnings'] ?? []));
        if ($datesOk && $this->eventStart && $this->eventEnd && ($arr > $this->eventEnd || $dep < $this->eventStart)) {
            $warnings[] = "travel dates {$arr} - {$dep} lie outside the event window {$this->eventStart} - {$this->eventEnd}";
        }

        // Fail-closed: an unverifiable MRZ read is never released automatically.
        $review = array_values((array) ($pp['review'] ?? []));
        $status = $mismatches !== [] ? 'failed' : ($review !== [] ? 'review' : 'success');

        $reason = $status === 'success'
            ? 'All required fields verified against the MRZ.'
            : ($mismatches === []
                ? 'Manual review required: ' . implode('; ', $review)
                : 'Mismatch in: ' . implode('; ', array_map(
                static fn ($m) => sprintf(
                    "%s (Excel: '%s' ≠ Pass: '%s')",
                    $m['field'],
                    $m['expected'] === '' ? '–' : $m['expected'],
                    $m['found'] === '' ? '–' : $m['found']
                ),
                $mismatches
            )) . ($review !== [] ? ' | Manual review: ' . implode('; ', $review) : ''));

        return [
            'no'            => $app['no'] ?? '',
            'full_name'     => $app['full_name'] ?? '',
            'date_of_birth' => $app['date_of_birth'] ?? '',
            'status'        => $status,
            'review'        => $review,
            'warnings'      => $warnings,
            'matched'       => true,
            'source_image'  => $pp['_source'] ?? null,
            'reason'        => $reason,
            'mismatches'    => $mismatches,
            'checks'        => $checks,
            // values written into the Word letter (verified-from-passport where available)
            'verified'      => [
                'full_name'            => $ppName !== '' ? $this->titleCaseName($ppName) : ($app['full_name'] ?? ''),
                'gender'               => $app['gender'] ?? '',
                'nationality'          => $app['nationality'] ?? '',
                'date_of_birth'        => $mrz['date_of_birth'] ?? ($app['date_of_birth'] ?? ''),
                // Excel value is authoritative for the letter (visual-zone reads may be Cyrillic/CJK); passport value only as fallback
                'place_of_birth'       => ($app['place_of_birth'] ?? '') !== '' ? $app['place_of_birth'] : ($visual['place_of_birth'] ?? ''),
                'passport_number'      => $mrz['document_number'] ?? ($app['passport_number'] ?? ''),
                'passport_issue_date'  => $visual['issue_date'] ?: ($app['passport_issue_date'] ?? ''),
                'passport_expiry_date' => $mrz['expiry_date'] ?? ($app['passport_expiry_date'] ?? ''),
                'role'                 => $app['role'] ?? '',
                'date_of_arrival'      => $app['date_of_arrival'] ?? '',
                'date_of_departure'    => $app['date_of_departure'] ?? '',
            ],
        ];
    }

    // ---- helpers ----------------------------------------------------------

    private function check(string $expected, string $found, bool $ok, bool $required): array
    {
        return ['expected' => $expected, 'found' => $found, 'ok' => $ok, 'required' => $required];
    }

    private function normNumber(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? '');
    }

    private function normSex(string $s): string
    {
        $s = strtolower(trim($s));
        return match (true) {
            $s === '' => '',
            str_starts_with($s, 'm') => 'M',
            str_starts_with($s, 'f') || str_starts_with($s, 'w') => 'F',
            default => 'X',
        };
    }

    /**
     * Name similarity (0..1) between the Excel name and the MRZ name.
     * Tries every accepted spelling of the Excel name against the MRZ name:
     *  - plain ASCII folding (Ü -> U),
     *  - ICAO 9303 expansion (Ü -> UE, ß -> SS, Ø -> OE, Å -> AA ...),
     * both with apostrophes dropped (D'Angelo -> DANGELO) and, as a last resort,
     * with all spaces/hyphens removed (Jean-Pierre vs JEANPIERRE).
     */
    private function nameScore(string $excelName, string $mrzName): float
    {
        $mrzTokens = $this->nameTokens($mrzName, false);
        if ($mrzTokens === []) {
            return 0.0;
        }
        $best = 0.0;
        foreach ([false, true] as $icao) {
            $excelTokens = $this->nameTokens($excelName, $icao);
            $best = max($best, $this->tokenOverlap($excelTokens, $mrzTokens));
            if ($excelTokens !== [] && $this->compact($excelTokens) === $this->compact($mrzTokens)) {
                $best = 1.0;
            }
        }
        return $best;
    }

    /** @param string[] $tokens */
    private function compact(array $tokens): string
    {
        sort($tokens);
        return implode('', $tokens);
    }

    /** ICAO 9303 part 3 recommended transliteration of national characters. */
    private const ICAO = [
        'Ä' => 'AE', 'ä' => 'AE', 'Ö' => 'OE', 'ö' => 'OE', 'Ü' => 'UE', 'ü' => 'UE', 'ß' => 'SS', 'ẞ' => 'SS',
        'Ø' => 'OE', 'ø' => 'OE', 'Å' => 'AA', 'å' => 'AA', 'Æ' => 'AE', 'æ' => 'AE', 'Œ' => 'OE', 'œ' => 'OE',
        'Þ' => 'TH', 'þ' => 'TH', 'Đ' => 'D', 'đ' => 'D', 'Ð' => 'D', 'ð' => 'D', 'Ł' => 'L', 'ł' => 'L',
    ];

    /** @return string[] */
    private function nameTokens(string $s, bool $icao = false): array
    {
        $s = preg_replace('/[\'’ʼ`´]/u', '', $s) ?? $s;       // D'Angelo -> DAngelo (MRZ drops apostrophes)
        if ($icao) {
            $s = strtr($s, self::ICAO);
        }
        $s = $this->transliterate($s);
        $s = strtoupper((string) preg_replace('/[^A-Za-z ]+/', ' ', $s));
        $tokens = array_filter(explode(' ', $s), static fn ($t) => strlen($t) > 1);
        return array_values(array_unique($tokens));
    }

    /** Fraction of the smaller token set that also appears in the other set. */
    private function tokenOverlap(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $common = count(array_intersect($a, $b));
        return $common / min(count($a), count($b));
    }

    private function nationalityMatches(string $excel, string $mrzCode): bool
    {
        if ($mrzCode !== '' && strtoupper(trim($excel)) === strtoupper(trim($mrzCode))) {
            return true; // identical code, even if not in the ISO table
        }
        $m = CountryCodes::resolve($mrzCode);
        $e = CountryCodes::resolve($excel);
        return $m !== null && $e !== null && $m === $e;
    }

    private function fuzzyContains(string $a, string $b): bool
    {
        $na = $this->simplify($a);
        $nb = $this->simplify($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb || str_contains($na, $nb) || str_contains($nb, $na)) {
            return true;
        }
        // token overlap (handles "Milano (MI)" vs "Milano")
        return $this->tokenOverlap(
            array_filter(explode(' ', $na)),
            array_filter(explode(' ', $nb))
        ) >= 0.5;
    }

    private function simplify(string $s): string
    {
        $s = strtolower($this->transliterate($s));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    private function titleCaseName(string $s): string
    {
        return mb_convert_case(mb_strtolower(trim($s)), MB_CASE_TITLE, 'UTF-8');
    }

    private function transliterate(string $s): string
    {
        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
            if (is_string($t)) {
                return $t;
            }
        }
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t !== false ? $t : $s;
    }
}

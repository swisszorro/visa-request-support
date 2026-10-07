<?php

declare(strict_types=1);

/** Minimal dependency-free test harness (no PHPUnit). */

foreach (['MrzCheck', 'MrzParser', 'MrzVerifier', 'CountryCodes', 'Matcher'] as $c) {
    require_once __DIR__ . '/../src/' . $c . '.php';
}

use BWC\Visa\MrzCheck;

$GLOBALS['t_fail'] = 0;
$GLOBALS['t_count'] = 0;

function t_eq(string $name, mixed $got, mixed $want): void
{
    $GLOBALS['t_count']++;
    if ($got === $want) {
        echo "  ✓ {$name}\n";
        return;
    }
    $GLOBALS['t_fail']++;
    echo "  ✗ {$name}\n      want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n      got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
}

function t_done(): never
{
    $f = $GLOBALS['t_fail'];
    echo "\n" . ($f === 0 ? "ALL {$GLOBALS['t_count']} TESTS PASSED ✓\n" : "{$f}/{$GLOBALS['t_count']} TESTS FAILED ✗\n");
    exit($f === 0 ? 0 : 1);
}

/** Synthetic TD3 MRZ with correct check digits (no real person). */
function make_td3(string $surname, string $given, string $doc, string $nat, string $dob, string $sex, string $exp, string $type = 'P<'): array
{
    $l1 = str_pad($type . $nat . $surname . '<<' . str_replace(' ', '<', $given), 44, '<');
    $docF = str_pad($doc, 9, '<');
    $pers = str_repeat('<', 14);
    $l2 = $docF . MrzCheck::digit($docF) . $nat . $dob . MrzCheck::digit($dob) . $sex . $exp . MrzCheck::digit($exp) . $pers . '0';
    $comp = substr($l2, 0, 10) . substr($l2, 13, 7) . substr($l2, 21, 22);
    return [$l1, $l2 . MrzCheck::digit($comp)];
}

/** Gemini-like record for a synthetic MRZ. */
function llm_record(array $lines, array $mrzOverride = []): array
{
    return [
        'passport_detected' => true,
        'mrz' => $mrzOverride + [
            'surname' => 'TESTER', 'given_names' => 'ANNA MARIE', 'document_number' => 'AB1234567',
            'nationality' => 'CHE', 'date_of_birth' => '1985-03-12', 'sex' => 'F', 'expiry_date' => '2032-06-30',
        ],
        'visual' => ['place_of_birth' => 'Utopolis', 'issue_date' => '2022-07-01'],
        'raw_mrz_lines' => $lines,
    ];
}

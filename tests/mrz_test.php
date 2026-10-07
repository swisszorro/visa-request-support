<?php

declare(strict_types=1);

/**
 * Dependency-free MRZ parser test (no PHPUnit needed).
 * Run:  php tests/mrz_test.php
 *
 * Synthetic passports only (no real persons): clean MRZ for several issuers plus
 * deliberately "dirty" OCR variants (O/0 confusion, leading junk that shifts the
 * expiry date, visual-zone text contamination).
 */

require __DIR__ . '/bootstrap.php';

use BWC\Visa\MrzParser;

$parser = new MrzParser();
$pivot = 26; // two-digit pivot for 2026

function parse_case(string $name, MrzParser $parser, int $pivot, array $lines, array $expected): void
{
    $mrz = $parser->parse($lines, $pivot)['mrz'] ?? [];
    $got = [
        'surname' => $mrz['surname'] ?? null, 'given' => $mrz['given_names'] ?? null, 'doc' => $mrz['document_number'] ?? null,
        'nat' => $mrz['nationality'] ?? null, 'dob' => $mrz['date_of_birth'] ?? null, 'sex' => $mrz['sex'] ?? null,
        'expiry' => $mrz['expiry_date'] ?? null,
    ];
    t_eq($name, array_intersect_key($got, $expected), $expected);
}

echo "Synthetic passports (clean MRZ)\n";
parse_case('CHE', $parser, $pivot, make_td3('MUSTERMANN', 'ERIKA MARIA', 'A0B49C45', 'CHE', '720915', 'F', '340620'),
    ['surname' => 'MUSTERMANN', 'given' => 'ERIKA MARIA', 'doc' => 'A0B49C45', 'nat' => 'CHE', 'dob' => '1972-09-15', 'sex' => 'F', 'expiry' => '2034-06-20']);
parse_case('ITA', $parser, $pivot, make_td3('ROSSI', 'MARIO', 'YB1234567', 'ITA', '820723', 'M', '310915'),
    ['surname' => 'ROSSI', 'given' => 'MARIO', 'doc' => 'YB1234567', 'nat' => 'ITA', 'dob' => '1982-07-23', 'sex' => 'M', 'expiry' => '2031-09-15']);
parse_case('KAZ (born 2006 -> 20xx)', $parser, $pivot, make_td3('IVANOV', 'ALEXEY', 'M90817265', 'KAZ', '060321', 'M', '360512'),
    ['surname' => 'IVANOV', 'given' => 'ALEXEY', 'doc' => 'M90817265', 'nat' => 'KAZ', 'dob' => '2006-03-21', 'sex' => 'M', 'expiry' => '2036-05-12']);
parse_case('CHN short surname', $parser, $pivot, make_td3('LI', 'WEI', 'EK1357924', 'CHN', '980604', 'F', '330301', 'PO'),
    ['surname' => 'LI', 'given' => 'WEI', 'doc' => 'EK1357924', 'nat' => 'CHN', 'dob' => '1998-06-04', 'sex' => 'F', 'expiry' => '2033-03-01']);

echo "\nDirty OCR (must still recover the same data)\n";
$good = make_td3('MUSTERMANN', 'ERIKA MARIA', 'A0B49C45', 'CHE', '720915', 'F', '340620', 'PM');
$want = ['surname' => 'MUSTERMANN', 'given' => 'ERIKA MARIA', 'doc' => 'A0B49C45', 'nat' => 'CHE', 'dob' => '1972-09-15', 'sex' => 'F', 'expiry' => '2034-06-20'];
$dirty1 = str_replace('0', 'O', $good[1]);                       // 0 -> O in doc number, dates, check digits
$dirtyName = str_replace('I', '1', $good[0]);                    // I -> 1 in the name line
parse_case('O instead of 0 (digits + doc number)', $parser, $pivot, [$dirtyName, $dirty1], $want);
parse_case('Leading junk shifts expiry + O zeros + contamination', $parser, $pivot, ['PLACE OF BIRTH BADEN AG', $dirtyName, 'K' . $dirty1], $want);

echo "\nMRZ line-length validation (MrzCheck::classifyLines)\n";
[$L1, $L2] = $good;
$fmt = fn (array $lines): bool => \BWC\Visa\MrzCheck::classifyLines($lines)['valid'];
t_eq('TD3 44/44 valid', $fmt([$L1, $L2]), true);
t_eq('data line short (43)', $fmt([$L1, substr($L2, 0, 43)]), false);
t_eq('data line long (45)', $fmt([$L1, $L2 . '<']), false);
t_eq('name line short, ok', $fmt([rtrim($L1, '<'), $L2]), true);
t_eq('stray line ignored', $fmt(['RANDOM JUNK TEXT', $L1, $L2]), true);

t_done();

<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use BWC\Visa\Matcher;
use BWC\Visa\MrzVerifier;

$pivot = 26;
$today = '2026-10-05';
$m = new Matcher(true, 6, $today);

/** Run one Excel row against one synthetic passport; returns the per-field check map. */
function run_case(Matcher $m, array $excel, array $mrz, int $pivot, string $today, array $mrzOverride = []): array
{
    $lines = make_td3($mrz['surname'], $mrz['given'], $mrz['doc'] ?? 'AB1234567', str_pad($mrz['nat'] ?? 'CHE', 3, '<'), '850312', $mrz['sex'] ?? 'F', '320630');
    $rec = MrzVerifier::apply(llm_record($lines, [
        'surname' => $mrz['surname'], 'given_names' => $mrz['given'], 'document_number' => $mrz['doc'] ?? 'AB1234567',
        'nationality' => $mrz['nat'] ?? 'CHE', 'sex' => $mrz['sex'] ?? 'F',
    ] + $mrzOverride), $pivot, $today);
    $app = $excel + ['no' => '1', 'gender' => 'F', 'nationality' => 'CHE', 'date_of_birth' => '1985-03-12', 'place_of_birth' => 'Utopolis',
        'passport_number' => $mrz['doc'] ?? 'AB1234567', 'passport_expiry_date' => '2032-06-30',
        'date_of_arrival' => '2026-11-11', 'date_of_departure' => '2026-11-15'];
    $r = $m->match([$app], [$rec])[0];
    return $r['checks'] ?: ['name' => ['ok' => false], 'nationality' => ['ok' => false], 'passport_number' => ['ok' => false]];
}

$name = fn (string $excel, string $surname, string $given) => run_case($m, ['full_name' => $excel], ['surname' => $surname, 'given' => $given], $pivot, $today)['name']['ok'];

echo "B1-B5 names / transliteration\n";
t_eq('B1 Müller Hans = MUELLER HANS (ICAO UE)', $name('Müller Hans', 'MUELLER', 'HANS'), true);
t_eq('B1 Müller Hans = MULLER HANS (plain U)', $name('Müller Hans', 'MULLER', 'HANS'), true);
t_eq('B1 Mueller Hans = MUELLER HANS', $name('Mueller Hans', 'MUELLER', 'HANS'), true);
t_eq('B2 Weiß Anna = WEISS ANNA', $name('Weiß Anna', 'WEISS', 'ANNA'), true);
t_eq('B2 Ødegaard Lars = ODEGAARD LARS', $name('Ødegaard Lars', 'ODEGAARD', 'LARS'), true);
t_eq('B2 Ødegaard Lars = OEDEGAARD LARS', $name('Ødegaard Lars', 'OEDEGAARD', 'LARS'), true);
t_eq("B3 D'Angelo Marco = DANGELO MARCO", $name("D'Angelo Marco", 'DANGELO', 'MARCO'), true);
t_eq("B3 O’Brien Sean (typographic apostrophe) = OBRIEN SEAN", $name('O’Brien Sean', 'OBRIEN', 'SEAN'), true);
t_eq('B3 Jean-Pierre Dupont = DUPONT JEAN PIERRE', $name('Dupont Jean-Pierre', 'DUPONT', 'JEAN PIERRE'), true);
t_eq('B3 hyphen dropped in MRZ: JEANPIERRE', $name('Dupont Jean-Pierre', 'DUPONT', 'JEANPIERRE'), true);
t_eq('B4 Nguyễn Văn An = NGUYEN VAN AN', $name('Nguyễn Văn An', 'NGUYEN', 'VAN AN'), true);
t_eq('B4 Cyrillic Каримова Сабина = KARIMOVA SABINA', $name('Каримова Сабина', 'KARIMOVA', 'SABINA'), true);
t_eq('B5 reversed order', $name('Anna Tester', 'TESTER', 'ANNA'), true);
t_eq('B5 second given name missing in MRZ-truncated name', $name('Tester Anna Marie Louise', 'TESTER', 'ANNA MARIE'), true);
t_eq('different person is NOT matched', $name('Schmidt Peter', 'MUELLER', 'HANS'), false);
t_eq('CJK name never produces a false match', $name('徐玉清', 'MUELLER', 'HANS'), false);

echo "\nB8 nationality\n";
$nat = fn (string $excel, string $mrz) => run_case($m, ['nationality' => $excel], ['surname' => 'TESTER', 'given' => 'ANNA', 'nat' => $mrz], $pivot, $today, ['nationality' => $mrz])['nationality']['ok'];
foreach ([['Switzerland', 'CHE'], ['Swiss', 'CHE'], ['CH', 'CHE'], ['CHE', 'CHE'], ['Deutschland', 'D'], ['DEU', 'D'], ['Germany', 'DEU'],
          ['GBR', 'GBD'], ['British', 'GBR'], ['Kosovo', 'RKS'], ['XKX', 'RKS'], ['Taiwan', 'TWN'], ['AZE', 'AZE']] as [$e, $mm]) {
    t_eq("Excel '{$e}' = MRZ '{$mm}'", $nat($e, $mm), true);
}
t_eq("Excel 'Italy' != MRZ 'CHE'", $nat('Italy', 'CHE'), false);
t_eq('empty Excel nationality != MRZ', $nat('', 'CHE'), false);

echo "\nB7 passport number\n";
$num = fn (string $excel) => run_case($m, ['passport_number' => $excel], ['surname' => 'TESTER', 'given' => 'ANNA'], $pivot, $today)['passport_number']['ok'];
t_eq('exact', $num('AB1234567'), true);
t_eq('lower case + spaces + hyphen', $num('ab 123-4567'), true);
t_eq('different number is not accepted', $num('AB1234568'), false);

echo "\nPlace of birth (Excel value is used, mismatch only warns)\n";
$lines = make_td3('TESTER', 'ANNA', 'AB1234567', 'CHE', '850312', 'F', '320630');
$rec = MrzVerifier::apply(llm_record($lines, ['surname' => 'TESTER', 'given_names' => 'ANNA']), $pivot, $today);
$rec['visual']['place_of_birth'] = 'МОСКВА';
$app = ['no' => '1', 'full_name' => 'Tester Anna', 'gender' => 'F', 'nationality' => 'CHE', 'date_of_birth' => '1985-03-12',
    'place_of_birth' => 'Moscow', 'passport_number' => 'AB1234567', 'passport_expiry_date' => '2032-06-30',
    'date_of_arrival' => '2026-11-11', 'date_of_departure' => '2026-11-15'];
$res = (new Matcher(true, 6, $today))->match([$app], [$rec])[0];
t_eq('Cyrillic place on passport vs English Excel value: still success (warn-only)', $res['status'], 'success');
t_eq('letter gets the Excel value, not the Cyrillic text', $res['verified']['place_of_birth'], 'Moscow');
$app['place_of_birth'] = '';
$res = (new Matcher(true, 6, $today))->match([$app], [$rec])[0];
t_eq('empty Excel value falls back to passport value', $res['verified']['place_of_birth'], 'МОСКВА');
$app['place_of_birth'] = 'Moscow';
t_eq('strict mode (warn-only=false) still fails on mismatch', (new Matcher(false, 6, $today))->match([$app], [$rec])[0]['status'], 'failed');

t_done();

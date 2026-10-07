<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use BWC\Visa\ClientError;
use BWC\Visa\ExcelReader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const HEADERS = ['No.', 'Full name (as in passport)', 'Gender', 'Nationality', 'Date of Birth', 'Place of Birth', 'Passport number',
    'Passport Issue Date', 'Passport expiry date', 'Role at the event ', 'Date of arrival', 'Date of departure'];

/** @param array<int,array<int,mixed>> $rows data rows (A..L); row 1 = title, header in row $headerRow, example row follows */
function make_xlsx(array $rows, int $headerRow = 3, ?array $headers = null, string $sheetName = 'Applicants', bool $example = true): string
{
    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet()->setTitle($sheetName);
    $sh->setCellValue('A1', 'Visa request list');
    foreach (($headers ?? HEADERS) as $i => $h) {
        $sh->setCellValue([$i + 1, $headerRow], $h);
    }
    $r = $headerRow + 1;
    if ($example) {
        foreach (['0', 'Example Person', 'M', 'CHE', '1990-01-01', 'Bern', 'X0000000', '2020-01-01', '2030-01-01', 'Athlete', '2026-11-11', '2026-11-15'] as $i => $v) {
            $sh->setCellValue([$i + 1, $r], $v);
        }
        $r++;
    }
    foreach ($rows as $row) {
        foreach ($row as $i => $v) {
            if ($v !== null) {
                $sh->setCellValueExplicit([$i + 1, $r], $v, is_int($v) || is_float($v) ? DataType::TYPE_NUMERIC : (is_string($v) && str_starts_with($v, '=') ? DataType::TYPE_FORMULA : DataType::TYPE_STRING));
            }
        }
        $r++;
    }
    $path = sys_get_temp_dir() . '/xl_' . bin2hex(random_bytes(4)) . '.xlsx';
    (new Xlsx($ss))->save($path);
    return $path;
}

function person(array $o = []): array
{
    return array_replace([1, 'Tester Anna', 'F', 'CHE', '1985-03-12', 'Bern', 'AB1234567', '2022-07-01', '2032-06-30', 'Athlete', '2026-11-11', '2026-11-15'], $o);
}

function read(string $path): array
{
    return (new ExcelReader())->read($path);
}

echo "C1/C2 header position and example row\n";
$r = read(make_xlsx([person()]));
t_eq('example row skipped, first real applicant read', array_column($r, 'full_name'), ['Tester Anna']);
$r = read(make_xlsx([person()], 1));
t_eq('header in row 1 works', count($r), 1);
$r = read(make_xlsx([person()], 10));
t_eq('header in row 10 works', count($r), 1);
$r = read(make_xlsx([person(), person([1 => 'Second Person'])]));
t_eq('two applicants after the example row', count($r), 2);

echo "\nC3 columns / errors\n";
$h = HEADERS; unset($h[8]); // passport expiry date missing
try { read(make_xlsx([person()], 3, array_values($h))); t_eq('missing column -> ClientError', 'no error', 'error'); }
catch (ClientError $e) { t_eq('missing column -> ClientError naming the column', str_contains($e->getMessage(), 'Passport expiry date') || str_contains($e->getMessage(), 'passport expiry date'), true); }
try { read(make_xlsx([person()], 3, null, 'Sheet1')); t_eq('wrong sheet name -> ClientError', 'no error', 'error'); }
catch (ClientError) { t_eq('wrong sheet name -> ClientError', true, true); }
$bad = sys_get_temp_dir() . '/bad.xlsx'; file_put_contents($bad, 'not a zip');
try { read($bad); t_eq('garbage file -> ClientError', 'no error', 'error'); }
catch (ClientError $e) { t_eq('garbage file -> ClientError without internals', str_contains($e->getMessage(), '/'), false); }
$csv = sys_get_temp_dir() . '/x.xlsx'; file_put_contents($csv, "Full name (as in passport),Passport number\nA,B\n");
try { read($csv); t_eq('CSV renamed .xlsx -> ClientError', 'no error', 'error'); }
catch (ClientError) { t_eq('CSV renamed .xlsx -> ClientError', true, true); }

echo "\nC5 rows without name\n";
$rd = new ExcelReader();
$rows = $rd->read(make_xlsx([person([1 => '']), person()]));
t_eq('row with passport number but no name is reported, not lost', [count($rows), count($rd->skippedRows())], [1, 1]);

echo "\nB6 dates\n";
$dates = function (string $dob, string $exp = '2032-06-30'): array {
    $p = person([4 => $dob, 8 => $exp]);
    $r = read(make_xlsx([$p]));
    return [$r[0]['date_of_birth'], $r[0]['passport_expiry_date']];
};
t_eq('ISO text', $dates('1985-03-12')[0], '1985-03-12');
t_eq('d.m.Y', $dates('08.09.1971')[0], '1971-09-08');
t_eq('d.m.y with pivot (71 -> 1971)', $dates('8.9.71')[0], '1971-09-08');
t_eq('d/m/Y unambiguous (day > 12)', $dates('25/12/1971')[0], '1971-12-25');
t_eq('m/d/Y unambiguous (second > 12)', $dates('12/25/1971')[0], '1971-12-25');
t_eq('08/09/1971 is ambiguous -> raw text, never guessed', $dates('08/09/1971')[0], '08/09/1971');
t_eq('11/11/1971 same day+month -> fine', $dates('11/11/1971')[0], '1971-11-11');
t_eq('month spelled out', $dates('8 Sep 1971')[0], '1971-09-08');
t_eq('invalid calendar date stays raw', $dates('31.02.1971')[0], '31.02.1971');
t_eq('number 1971 (not a date) stays raw', $dates('1971')[0], '1971');
t_eq('real Excel serial', $dates((string) (int) Date::PHPToExcel(new DateTime('1985-03-12')))[0], '1985-03-12');
t_eq('expiry d.m.y -> 20xx', $dates('1985-03-12', '30.06.32')[1], '2032-06-30');

echo "\nB7 passport number cells\n";
$num = function (mixed $v, ?string $fmt = null): string {
    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet()->setTitle('Applicants');
    foreach (HEADERS as $i => $h) { $sh->setCellValue([$i + 1, 1], $h); }
    foreach (person([6 => 'x']) as $i => $val) { $sh->setCellValue([$i + 1, 3], $val); }
    $sh->setCellValueExplicit([7, 3], $v, is_string($v) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
    if ($fmt) { $sh->getStyle('G3')->getNumberFormat()->setFormatCode($fmt); }
    $p = sys_get_temp_dir() . '/n_' . bin2hex(random_bytes(3)) . '.xlsx';
    (new Xlsx($ss))->save($p);
    return read($p)[0]['passport_number'];
};
t_eq('text number keeps leading zero', $num('012345678'), '012345678');
t_eq('numeric cell with 000000000 format keeps leading zeros', $num(12345678, '000000000'), '012345678');
t_eq('large numeric cell has no scientific notation', $num(123456789012.0), '123456789012');
t_eq('letter O is preserved as typed', $num('CO3853397'), 'CO3853397');

echo "\nC6 formulas\n";
$f = read(make_xlsx([person([1 => '=CONCATENATE("Tester"," ","Anna")'])]));
t_eq('formula cell returns the calculated value', $f[0]['full_name'], 'Tester Anna');

echo "\nReal test workbook (if present)\n";
$real = glob(__DIR__ . '/../storage/tmp/job_*/applicants.xlsx')[0] ?? null;
if ($real) {
    $rows = read($real);
    t_eq('example row (No. 1 in the old test file) skipped, rest read', [count($rows) > 0, $rows[0]['no'] ?? null], [true, '2']);
}

t_done();

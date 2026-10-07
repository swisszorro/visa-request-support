<?php

declare(strict_types=1);

namespace BWC\Visa;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Reads the "Applicants" table from the uploaded Excel workbook.
 *
 * Returns one associative record per filled-in applicant with canonical keys:
 *   no, full_name, gender, nationality, date_of_birth, place_of_birth,
 *   passport_number, passport_issue_date, passport_expiry_date,
 *   role, date_of_arrival, date_of_departure
 *
 * Date columns are normalised to ISO "YYYY-MM-DD" strings (Excel serials are
 * converted; already-text dates are passed through best-effort).
 */
final class ExcelReader
{
    /** Header text => canonical key. Matching is case-insensitive & trimmed. */
    private const HEADER_MAP = [
        'no.'                        => 'no',
        'full name (as in passport)' => 'full_name',
        'gender'                     => 'gender',
        'nationality'                => 'nationality',
        'date of birth'              => 'date_of_birth',
        'place of birth'             => 'place_of_birth',
        'passport number'            => 'passport_number',
        'passport issue date'        => 'passport_issue_date',
        'passport expiry date'       => 'passport_expiry_date',
        'role at the event'          => 'role',
        'date of arrival'            => 'date_of_arrival',
        'date of departure'          => 'date_of_departure',
    ];

    /** Columns the letter needs; a missing one is reported instead of silently producing empty values. */
    private const REQUIRED_KEYS = [
        'full_name', 'gender', 'nationality', 'date_of_birth', 'place_of_birth', 'passport_number',
        'passport_expiry_date', 'role', 'date_of_arrival', 'date_of_departure',
    ];

    /**
     * The template carries ONE example row directly below the header; it is
     * deliberately skipped (applicants start at header + 1 + EXAMPLE_ROWS).
     */
    private const EXAMPLE_ROWS = 1;

    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_ROWS = 500;

    /** @var array<int,array{row:int,reason:string}> rows that look like an applicant but were not usable */
    private array $skipped = [];

    /** @return array<int,array{row:int,reason:string}> */
    public function skippedRows(): array
    {
        return $this->skipped;
    }

    private const DATE_KEYS = [
        'date_of_birth', 'passport_issue_date', 'passport_expiry_date',
        'date_of_arrival', 'date_of_departure',
    ];

    /**
     * @return array<int, array<string,string>>
     * @throws \RuntimeException
     */
    public function read(string $xlsxPath): array
    {
        if (!is_file($xlsxPath)) {
            throw new ClientError('Excel file not found.');
        }
        if (filesize($xlsxPath) > self::MAX_BYTES) {
            throw new ClientError('Excel file too large (max ' . (self::MAX_BYTES / 1048576) . ' MB).');
        }
        $this->skipped = [];

        try {
            $reader = new Xlsx(); // no format auto-detection: only real .xlsx workbooks
            $reader->setReadDataOnly(false); // we need number-format info to detect dates
            $reader->setLoadSheetsOnly(['Applicants']);
            $spreadsheet = $reader->load($xlsxPath);
        } catch (\Throwable) {
            throw new ClientError('The uploaded file is not a readable .xlsx workbook (or has no "Applicants" worksheet).');
        }

        $sheet = $spreadsheet->getSheetByName('Applicants');
        if ($sheet === null) {
            throw new ClientError('Worksheet "Applicants" not found in the workbook.');
        }

        $highestRow = $sheet->getHighestDataRow();
        if ($highestRow > self::MAX_ROWS) {
            throw new ClientError('Worksheet "Applicants" has too many rows (max ' . self::MAX_ROWS . ').');
        }
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        // 1) locate the header row + build column->key map
        [$headerRow, $colKey] = $this->locateHeader($sheet, $highestRow, $highestCol);
        if ($headerRow === null) {
            throw new ClientError('Could not locate the "Applicants" table header (expecting a "Full name (as in passport)" column).');
        }
        $missing = array_values(array_diff(self::REQUIRED_KEYS, array_values($colKey)));
        if ($missing !== []) {
            $labels = array_keys(array_filter(self::HEADER_MAP, static fn ($k) => in_array($k, $missing, true)));
            throw new ClientError('Missing column(s) in the "Applicants" table: ' . implode(', ', $labels));
        }

        // 2) read data rows (the example row below the header is skipped on purpose)
        $records = [];
        for ($row = $headerRow + 1 + self::EXAMPLE_ROWS; $row <= $highestRow; $row++) {
            $rec = [];
            foreach ($colKey as $col => $key) {
                $rec[$key] = $this->cellValue($sheet->getCell([$col, $row]), $key);
            }
            if (trim((string) ($rec['full_name'] ?? '')) === '') {
                // not an applicant row - but do not lose data silently
                if (trim((string) ($rec['passport_number'] ?? '')) !== '' || trim((string) ($rec['date_of_birth'] ?? '')) !== '') {
                    $this->skipped[] = ['row' => $row, 'reason' => 'Row has a passport number / date of birth but no name'];
                }
                continue;
            }
            $rec['_row'] = (string) $row;
            $records[] = $rec;
        }

        return $records;
    }

    /**
     * @return array{0:?int,1:array<int,string>}
     */
    private function locateHeader($sheet, int $highestRow, int $highestCol): array
    {
        for ($row = 1; $row <= min($highestRow, 12); $row++) {
            $map = [];
            for ($col = 1; $col <= $highestCol; $col++) {
                $raw = trim((string) $sheet->getCell([$col, $row])->getValue());
                $norm = strtolower($raw);
                if (isset(self::HEADER_MAP[$norm])) {
                    $map[$col] = self::HEADER_MAP[$norm];
                }
            }
            // a real header row maps the key identity columns
            if (isset(array_flip($map)['full_name'], array_flip($map)['passport_number'])) {
                return [$row, $map];
            }
        }
        return [null, []];
    }

    private function cellValue($cell, string $key): string
    {
        try {
            $value = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        } catch (\Throwable) {
            $value = $cell->getValue();
        }
        if ($value === null || $value === '') {
            return '';
        }
        if (is_object($value)) {
            $value = (string) $value; // rich text
        }

        if (in_array($key, self::DATE_KEYS, true)) {
            return $this->dateValue($value, $key);
        }

        if ($key === 'passport_number' && (is_int($value) || is_float($value))) {
            // numeric cell: avoid 1.23E+8, and restore leading zeros from the cell's number format (e.g. 000000000)
            $raw = is_float($value) && floor($value) === $value ? sprintf('%.0f', $value) : (string) $value;
            $formatted = trim((string) $cell->getFormattedValue());
            return (ctype_digit($formatted) && strlen($formatted) > strlen($raw)) ? $formatted : $raw;
        }

        return trim((string) $value);
    }

    /**
     * Normalise a date cell to ISO. Anything ambiguous or implausible is returned
     * as the raw text so the mismatch is visible instead of guessed (fail-closed).
     */
    private function dateValue(mixed $value, string $key): string
    {
        $raw = trim((string) $value);
        $iso = null;

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            try {
                $iso = ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                $iso = null;
            }
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $raw, $m)) {
            $iso = $this->ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        } elseif (preg_match('/^(\d{1,2})[.\-](\d{1,2})[.\-](\d{2}|\d{4})$/', $raw, $m)) {
            $iso = $this->ymd($this->year((int) $m[3], strlen($m[3]), $key), (int) $m[2], (int) $m[1]); // d.m.Y
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/', $raw, $m)) {
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            $y = $this->year((int) $m[3], strlen($m[3]), $key);
            if ($a > 12 || $a === $b) {
                $iso = $this->ymd($y, $b, $a);      // d/m/Y
            } elseif ($b > 12) {
                $iso = $this->ymd($y, $a, $b);      // m/d/Y
            }                                       // else: 08/09/1971 is ambiguous -> keep raw
        } elseif (preg_match('/[A-Za-z]{3}/', $raw) && ($ts = strtotime($raw)) !== false) {
            $iso = date('Y-m-d', $ts);              // month spelled out -> unambiguous
        }

        if ($iso === null) {
            return $raw;
        }
        $minYear = $key === 'date_of_birth' ? 1920 : ($key === 'passport_issue_date' ? 1990 : 2000);
        $y = (int) substr($iso, 0, 4);
        if ($y < $minYear || $y > (int) date('Y') + 30) {
            return $raw; // e.g. the number 1971 read as an Excel serial -> 1905
        }
        return $iso;
    }

    private function ymd(int $y, int $m, int $d): ?string
    {
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    private function year(int $y, int $digits, string $key): int
    {
        if ($digits === 4) {
            return $y;
        }
        if ($key === 'date_of_birth') {
            return $y <= (int) date('y') ? 2000 + $y : 1900 + $y;
        }
        return 2000 + $y;
    }
}

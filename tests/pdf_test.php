<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use BWC\Visa\PdfConverter;

$tmp = sys_get_temp_dir() . '/pdf_' . bin2hex(random_bytes(3));
mkdir($tmp);
$docx = "$tmp/letter.docx";
$z = new ZipArchive();
$z->open($docx, ZipArchive::CREATE);
$z->addFromString('word/document.xml', '<x/>');
$z->close();

echo "Gotenberg connection errors are retried (the conversion is free, the image analysis is not)\n";
$t = microtime(true);
try {
    (new PdfConverter('gotenberg', 'soffice', 'http://127.0.0.1:1', '', ''))->convert($docx, $tmp);
    t_eq('unreachable converter -> error', 'converted', 'error');
} catch (\RuntimeException $e) {
    $elapsed = microtime(true) - $t;
    t_eq('gives up after 3 attempts with a clear message', str_contains($e->getMessage(), 'after 3 attempts'), true);
    t_eq('waits between the attempts (0.5 s + 1 s)', $elapsed >= 1.4 && $elapsed < 10, true);
}

try {
    (new PdfConverter('gotenberg', 'soffice', '', '', ''))->convert($docx, $tmp);
    t_eq('missing GOTENBERG_URL -> error', 'converted', 'error');
} catch (\RuntimeException $e) {
    t_eq('missing GOTENBERG_URL -> configuration error', str_contains($e->getMessage(), 'GOTENBERG_URL'), true);
}

t_done();

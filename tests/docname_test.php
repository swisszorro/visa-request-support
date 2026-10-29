<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use BWC\Visa\VisaController;

$call = static function (string $method, string $arg): string {
    $m = new ReflectionMethod(VisaController::class, $method);
    return $m->invoke(null, $arg);
};

echo "Letter file name = name of the Word template without .docx\n";
t_eq('BWC template', $call('documentName', 'resources/BWC Visa Invitation Letter.docx'), 'BWC Visa Invitation Letter');
t_eq('absolute path, other client', $call('documentName', '/app/resources/Basel U20 Visa Invitation Letter.docx'), 'Basel U20 Visa Invitation Letter');
t_eq('umlauts are kept', $call('documentName', 'resources/Einladung Bärn.docx'), 'Einladung Bärn');
t_eq('characters unsafe in a header are replaced', $call('documentName', 'resources/a"b:c.docx'), 'a_b_c');

echo "\nDownload header\n";
t_eq('plain name', $call('contentDisposition', 'BWC Visa Invitation Letter.pdf'), "attachment; filename=\"BWC Visa Invitation Letter.pdf\"; filename*=UTF-8''BWC%20Visa%20Invitation%20Letter.pdf");
t_eq('umlaut: ASCII fallback + exact name', $call('contentDisposition', 'Bärn.pdf'), "attachment; filename=\"B_rn.pdf\"; filename*=UTF-8''B%C3%A4rn.pdf");

t_done();

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use BWC\Visa\ClientError;
use BWC\Visa\ImagePreparer;
use BWC\Visa\ProcRunner;

$dir = sys_get_temp_dir() . '/img_' . bin2hex(random_bytes(3));
mkdir($dir);

/** Landscape test image: left half red, right half blue. */
function lr_image(int $w = 200, int $h = 100): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($im, 0, 0, 255));
    return $im;
}

function jpeg_with_orientation(GdImage $im, int $orientation): string
{
    ob_start();
    imagejpeg($im, null, 95);
    $jpg = (string) ob_get_clean();
    // minimal big-endian EXIF APP1 with one IFD0 entry: Orientation
    $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
    $body = "Exif\x00\x00" . $tiff;
    $app1 = "\xFF\xE1" . pack('n', strlen($body) + 2) . $body;
    return substr($jpg, 0, 2) . $app1 . substr($jpg, 2);
}

function decode(array $items): GdImage
{
    return imagecreatefromstring(base64_decode($items[0]['data']));
}

function is_red(GdImage $im, int $x, int $y): bool
{
    $c = imagecolorat($im, $x, $y);
    return (($c >> 16) & 255) > 180 && ($c & 255) < 90;
}

$prep = new ImagePreparer($dir, 1600, 30, 'pdftoppm', 'poppler');
$put = function (string $name, string $bytes) use ($dir): string {
    file_put_contents("$dir/$name", $bytes);
    return "$dir/$name";
};

echo "EXIF orientation (phone photos)\n";
t_eq('orientation parsed from EXIF', ImagePreparer::jpegOrientation(jpeg_with_orientation(lr_image(), 6)), 6);
t_eq('plain JPEG -> orientation 1', ImagePreparer::jpegOrientation($GLOBALS['x'] = (function () { ob_start(); imagejpeg(lr_image()); return (string) ob_get_clean(); })()), 1);

$im = decode($prep->prepare($put('o6.jpg', jpeg_with_orientation(lr_image(), 6)), 'o6.jpg'));
t_eq('orientation 6 -> 100x200 (rotated 90° CW), left side now on top', [imagesx($im), imagesy($im), is_red($im, 50, 10), is_red($im, 50, 190)], [100, 200, true, false]);
$im = decode($prep->prepare($put('o8.jpg', jpeg_with_orientation(lr_image(), 8)), 'o8.jpg'));
t_eq('orientation 8 -> left side now at the bottom', [imagesx($im), imagesy($im), is_red($im, 50, 10), is_red($im, 50, 190)], [100, 200, false, true]);
$im = decode($prep->prepare($put('o3.jpg', jpeg_with_orientation(lr_image(), 3)), 'o3.jpg'));
t_eq('orientation 3 -> upside down fixed (left red goes right)', [imagesx($im), is_red($im, 10, 50), is_red($im, 190, 50)], [200, false, true]);

$big = new ImagePreparer($dir, 400, 30, 'pdftoppm', 'poppler');
$im = decode($big->prepare($put('big6.jpg', jpeg_with_orientation(lr_image(1000, 500), 6)), 'big6.jpg'));
t_eq('large phone photo: downscaled AND still rotated (regression: EXIF lost on resize)', [imagesx($im), imagesy($im)], [200, 400]);

echo "\nType detection by content\n";
ob_start(); imagepng(lr_image()); $png = (string) ob_get_clean();
$r = $prep->prepare($put('actually_png.jpg', $png), 'actually_png.jpg');
t_eq('PNG named .jpg is accepted by content', $r[0]['media_type'], 'image/png');
foreach ([['HEIC', "\x00\x00\x00\x18ftypheic" . str_repeat("\0", 20)], ['TIFF', "II*\x00" . str_repeat("\0", 20)], ['text', 'hello world, not an image']] as [$n, $bytes]) {
    try { $prep->prepare($put("x_$n.jpg", $bytes), "x_$n.jpg"); t_eq("$n disguised as .jpg -> rejected", 'accepted', 'rejected'); }
    catch (ClientError $e) { t_eq("$n disguised as .jpg -> rejected with hint", str_contains($e->getMessage(), 'Unsupported'), true); }
}
ob_start(); imagebmp(lr_image()); $bmp = (string) ob_get_clean();
t_eq('BMP is converted to JPEG', $prep->prepare($put('a.bmp', $bmp), 'a.bmp')[0]['media_type'], 'image/jpeg');
if (function_exists('imagewebp')) {
    ob_start(); imagewebp(lr_image()); $webp = (string) ob_get_clean();
    t_eq('WebP is converted to JPEG', $prep->prepare($put('a.webp', $webp), 'a.webp')[0]['media_type'], 'image/jpeg');
}

echo "\nDecompression bomb\n";
$bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 20000, 20000) . "\x08\x02\x00\x00\x00" . "\0\0\0\0";
try { $prep->prepare($put('bomb.png', $bomb), 'bomb.png'); t_eq('20000x20000 PNG header -> rejected', 'accepted', 'rejected'); }
catch (ClientError $e) { t_eq('20000x20000 PNG header -> rejected before decoding', true, true); }

echo "\nPDF handling\n";
$pdf3 = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R 4 0 R 5 0 R]/Count 3>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>endobj\n4 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>endobj\n"
    . "5 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 100 100]>>endobj\ntrailer<</Root 1 0 R/Size 6>>\n%%EOF\n";
$p = $put('three.pdf', $pdf3);
$poppler = ImagePreparer::popplerAvailable('pdftoppm');
t_eq('pdftoppm detected for PDF_MODE=auto', $poppler, true);
if ($poppler) {
    $all = (new ImagePreparer($dir, 1600, 30, 'pdftoppm', 'poppler'))->prepare($p, 'three.pdf');
    t_eq('3-page PDF -> 3 page images', array_column($all, 'page'), [1, 2, 3]);
    $cap = new ImagePreparer($dir, 1600, 2, 'pdftoppm', 'poppler');
    $two = $cap->prepare($p, 'three.pdf');
    t_eq('page cap: 2 pages analysed + truncation reported (not silent)', [count($two), $cap->warnings()[0]['status'] ?? null], [2, 'warning']);
}
try { (new ImagePreparer($dir, 1600, 2, 'pdftoppm', 'native'))->prepare($p, 'three.pdf'); t_eq('native mode over page cap -> rejected', 'accepted', 'rejected'); }
catch (ClientError) { t_eq('native mode over page cap -> rejected', true, true); }
t_eq('native mode within cap -> PDF passed through', (new ImagePreparer($dir, 1600, 30, 'pdftoppm', 'native'))->prepare($p, 'three.pdf')[0]['media_type'], 'application/pdf');
try { $prep->prepare($put('broken.pdf', "%PDF-1.4\ngarbage"), 'broken.pdf'); t_eq('corrupt PDF -> ClientError', 'accepted', 'rejected'); }
catch (ClientError $e) { t_eq('corrupt PDF -> ClientError without paths', str_contains($e->getMessage(), $dir), false); }

echo "\nProcRunner timeout\n";
$t = microtime(true);
$r = ProcRunner::run('sleep 10', 1);
t_eq('hanging process is killed after the timeout', [$r['timed_out'], microtime(true) - $t < 4], [true, true]);
$r = ProcRunner::run('echo hello', 5);
t_eq('normal process output is returned', [trim($r['out']), $r['code']], ['hello', 0]);

t_done();

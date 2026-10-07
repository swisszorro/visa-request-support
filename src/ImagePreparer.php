<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Normalises uploaded files into a list of base64 images ready for the vision API.
 *
 * - The file type is detected from the CONTENT (magic bytes), not the file name.
 * - png/jpg/gif/bmp/webp -> decoded with GD, EXIF orientation applied (phone
 *   photos), optionally downscaled. Re-encoding drops the EXIF tag, so the
 *   orientation is baked into the pixels and the model never sees a sideways page.
 * - pdf -> rasterised page-by-page via poppler `pdftoppm`, or (native mode) handed
 *   to the model as is.
 *
 * Each returned item: ['media_type'=>..., 'data'=>base64, 'source'=>filename, 'page'=>int]
 * Non-fatal findings (e.g. truncated PDF) are exposed via warnings().
 */
final class ImagePreparer
{
    /** Hard cap on decoded pixels (decompression-bomb guard; GD needs ~5 bytes/pixel). */
    private const MAX_PIXELS = 40_000_000;
    private const PDFTOPPM_TIMEOUT = 120;

    /** @var array<int,array{source:string,page:int,status:string,message:string}> */
    private array $warnings = [];

    public function __construct(
        private readonly string $workDir,
        private readonly int $maxEdge = 1600,
        private readonly int $maxPdfPages = 30,
        private readonly string $pdftoppm = 'pdftoppm',
        // 'poppler' = rasterise PDF→PNG via pdftoppm (needs the binary);
        // 'native'  = pass the PDF straight to the model (no binary; gemini/vision).
        private readonly string $pdfMode = 'poppler',
    ) {
    }

    /** @return array<int,array{source:string,page:int,status:string,message:string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** True when the pdftoppm binary can be started (used for PDF_MODE=auto). */
    public static function popplerAvailable(string $bin): bool
    {
        $r = ProcRunner::run(escapeshellcmd($bin) . ' -v', 5);
        return $r['code'] === 0 || str_contains(strtolower($r['out']), 'pdftoppm');
    }

    /**
     * Detect the real file type from its first bytes.
     * @return 'pdf'|'png'|'jpeg'|'gif'|'bmp'|'webp'|null
     */
    public static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with(ltrim($head, "\0\r\n\t "), '%PDF')                => 'pdf',
            str_starts_with($head, "\x89PNG\r\n\x1a\n")                        => 'png',
            str_starts_with($head, "\xFF\xD8\xFF")                             => 'jpeg',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'gif',
            str_starts_with($head, 'BM')                                       => 'bmp',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP'   => 'webp',
            default                                                            => null,
        };
    }

    /**
     * @return array<int, array{media_type:string,data:string,source:string,page:int}>
     * @throws ClientError for unusable files (the caller reports it per file)
     */
    public function prepare(string $path, string $originalName): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);
        $type = self::sniff($head);

        if ($type === 'pdf') {
            return $this->pdfMode === 'native'
                ? $this->nativePdf($path, $originalName)
                : $this->fromPdf($path, $originalName);
        }
        if ($type === null) {
            throw new ClientError("Unsupported or corrupt file '{$originalName}' (allowed: JPG, PNG, GIF, BMP, WebP, PDF; convert HEIC/TIFF first).");
        }

        [$binary, $mime] = $this->normaliseImage($path, $type, $originalName);
        return [[
            'media_type' => $mime,
            'data'       => base64_encode($binary),
            'source'     => $originalName,
            'page'       => 1,
        ]];
    }

    /** @return array<int, array{media_type:string,data:string,source:string,page:int}> */
    private function nativePdf(string $path, string $originalName): array
    {
        $raw = (string) file_get_contents($path);
        $pages = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $raw);
        if ($pages > $this->maxPdfPages) {
            throw new ClientError("PDF '{$originalName}' has {$pages} pages (max {$this->maxPdfPages}).");
        }
        if (strlen($raw) > 14 * 1024 * 1024) {
            throw new ClientError("PDF '{$originalName}' is too large for analysis (max 14 MB).");
        }
        return [[
            'media_type' => 'application/pdf',
            'data'       => base64_encode($raw),
            'source'     => $originalName,
            'page'       => 1,
        ]];
    }

    /** @return array<int, array{media_type:string,data:string,source:string,page:int}> */
    private function fromPdf(string $path, string $originalName): array
    {
        $prefix = $this->workDir . '/' . bin2hex(random_bytes(6));
        // -r 200 dpi is enough for legible MRZ; one extra page tells us whether we truncated.
        $cmd = sprintf(
            '%s -png -r 200 -f 1 -l %d %s %s',
            escapeshellcmd($this->pdftoppm),
            $this->maxPdfPages + 1,
            escapeshellarg($path),
            escapeshellarg($prefix)
        );
        $r = ProcRunner::run($cmd, self::PDFTOPPM_TIMEOUT);
        if ($r['timed_out']) {
            throw new ClientError("PDF '{$originalName}' took too long to render.");
        }
        if ($r['code'] !== 0) {
            throw new ClientError("PDF '{$originalName}' could not be rendered (corrupt or password protected?).");
        }

        $pages = glob($prefix . '-*.png') ?: [];
        sort($pages, SORT_NATURAL);
        if ($pages === []) {
            throw new ClientError("No pages rendered from PDF '{$originalName}'.");
        }
        if (count($pages) > $this->maxPdfPages) {
            foreach (array_slice($pages, $this->maxPdfPages) as $extra) {
                @unlink($extra);
            }
            $pages = array_slice($pages, 0, $this->maxPdfPages);
            $this->warnings[] = ['source' => $originalName, 'page' => $this->maxPdfPages, 'status' => 'warning',
                'message' => "PDF has more than {$this->maxPdfPages} pages; only the first {$this->maxPdfPages} were analysed"];
        }

        $items = [];
        foreach ($pages as $i => $pageFile) {
            [$binary, $mime] = $this->normaliseImage($pageFile, 'png', $originalName);
            $items[] = ['media_type' => $mime, 'data' => base64_encode($binary), 'source' => $originalName, 'page' => $i + 1];
            @unlink($pageFile);
        }
        return $items;
    }

    /**
     * Decode with GD, apply EXIF orientation, downscale, re-encode.
     * Files that need neither are passed through untouched.
     *
     * @return array{0:string,1:string} binary, media type
     */
    private function normaliseImage(string $file, string $type, string $originalName): array
    {
        $raw = (string) file_get_contents($file);
        $mime = $type === 'jpeg' ? 'image/jpeg' : "image/{$type}";
        $info = @getimagesizefromstring($raw);
        if ($info === false) {
            throw new ClientError("Image '{$originalName}' is corrupt.");
        }
        [$w, $h] = $info;
        if ($w * $h > self::MAX_PIXELS) {
            throw new ClientError("Image '{$originalName}' is too large ({$w}x{$h} px).");
        }

        $orientation = $type === 'jpeg' ? self::jpegOrientation($raw) : 1;
        $needsResize = max($w, $h) > $this->maxEdge;
        $passThrough = in_array($type, ['png', 'jpeg', 'gif'], true);

        if ($orientation === 1 && !$needsResize && $passThrough) {
            return [$raw, $mime];
        }
        if (!function_exists('imagecreatefromstring')) {
            if ($passThrough) {
                return [$raw, $mime]; // no GD: best effort
            }
            throw new ClientError("Image '{$originalName}' cannot be decoded on this server (GD missing).");
        }
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            throw new ClientError("Image '{$originalName}' could not be decoded.");
        }
        unset($raw);

        $src = self::applyOrientation($src, $orientation);
        $w = imagesx($src);
        $h = imagesy($src);
        $long = max($w, $h);
        if ($long > $this->maxEdge) {
            $scale = $this->maxEdge / $long;
            $dst = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        // keep lossless sources lossless; everything else (jpeg, bmp, webp) -> jpeg
        ob_start();
        if ($type === 'png') {
            imagepng($src, null, 6);
            $out = ['image/png'];
        } elseif ($type === 'gif') {
            imagegif($src);
            $out = ['image/gif'];
        } else {
            imagejpeg($src, null, 92);
            $out = ['image/jpeg'];
        }
        $bin = (string) ob_get_clean();
        imagedestroy($src);
        if ($bin === '') {
            throw new ClientError("Image '{$originalName}' could not be re-encoded.");
        }
        return [$bin, $out[0]];
    }

    /** EXIF orientation (1-8) of a JPEG without needing ext-exif; 1 when absent/unreadable. */
    public static function jpegOrientation(string $jpeg): int
    {
        $len = strlen($jpeg);
        $pos = 2;
        while ($pos + 4 < $len && $jpeg[$pos] === "\xFF") {
            $marker = ord($jpeg[$pos + 1]);
            $size = (ord($jpeg[$pos + 2]) << 8) | ord($jpeg[$pos + 3]);
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                $tiff = substr($jpeg, $pos + 10, $size - 8);
                $le = substr($tiff, 0, 2) === 'II';
                $u16 = static fn (string $s, int $o): int => $le ? (ord($s[$o + 1]) << 8 | ord($s[$o])) : (ord($s[$o]) << 8 | ord($s[$o + 1]));
                $u32 = static fn (string $s, int $o): int => $le
                    ? (ord($s[$o + 3]) << 24 | ord($s[$o + 2]) << 16 | ord($s[$o + 1]) << 8 | ord($s[$o]))
                    : (ord($s[$o]) << 24 | ord($s[$o + 1]) << 16 | ord($s[$o + 2]) << 8 | ord($s[$o + 3]));
                if (strlen($tiff) < 14) {
                    return 1;
                }
                $ifd = $u32($tiff, 4);
                if ($ifd + 2 > strlen($tiff)) {
                    return 1;
                }
                $n = $u16($tiff, $ifd);
                for ($i = 0; $i < $n; $i++) {
                    $e = $ifd + 2 + $i * 12;
                    if ($e + 12 > strlen($tiff)) {
                        break;
                    }
                    if ($u16($tiff, $e) === 0x0112) {
                        $v = $u16($tiff, $e + 8);
                        return ($v >= 1 && $v <= 8) ? $v : 1;
                    }
                }
                return 1;
            }
            if ($marker === 0xDA) { // start of scan: no more metadata
                break;
            }
            $pos += 2 + $size;
        }
        return 1;
    }

    private static function applyOrientation(\GdImage $img, int $o): \GdImage
    {
        $rot = static function (\GdImage $i, float $deg): \GdImage {
            $r = imagerotate($i, $deg, 0);
            return $r === false ? $i : $r;
        };
        switch ($o) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 3:
                return $rot($img, 180);
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                return $img;
            case 5:
                $img = $rot($img, 270);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 6:
                return $rot($img, 270); // 90° clockwise
            case 7:
                $img = $rot($img, 90);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 8:
                return $rot($img, 90);  // 90° counter-clockwise
            default:
                return $img;
        }
    }
}

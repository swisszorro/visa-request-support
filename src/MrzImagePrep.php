<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Image preprocessing focused on making the MRZ band readable for OCR,
 * especially under reflections / uneven lighting.
 *
 * Core idea: estimate the local background (illumination) and threshold each
 * pixel against it ("adaptive mean thresholding"). Glare areas raise the local
 * background, so the bright reflection itself is pushed to white while the
 * (still slightly darker) glyphs become black — a global contrast stretch
 * cannot do this. The dense MRZ rows are then localised via the per-row black
 * pixel density and cropped tightly.
 *
 * Produces several candidate images; the caller OCRs each and keeps the parse
 * with the best ICAO check digits, so a preprocessing step can only help.
 */
final class MrzImagePrep
{
    public function __construct(private readonly int $targetWidth = 1600)
    {
    }

    /**
     * @return string[] PNG binaries of MRZ candidate images (best-effort; may be empty)
     */
    public function variants(string $binary): array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
            return [];
        }
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return [];
        }

        $variants = [];

        // Work on the bottom ~40 % of the page, where the MRZ sits on TD3 passports.
        $band = $this->cropBottom($src, 0.60);
        $band = $this->scaleToWidth($band, $this->targetWidth);
        $gray = $this->grayscale($band);

        // 1) adaptive threshold (the glare/reflection killer) + tight MRZ crop
        $thresh = $this->adaptiveThreshold($gray, 10);
        $tight = $this->localizeBand($thresh);
        $variants[] = $this->toPng($tight ?? $thresh);
        if ($tight !== null) {
            imagedestroy($tight);
        }

        // 2) flat-field normalised grayscale (kept as a softer alternative)
        $variants[] = $this->toPng($this->flatField($gray));

        // 3) simple contrast-enhanced grayscale (robust fallback)
        $plain = $this->grayscale($band);
        imagefilter($plain, IMG_FILTER_CONTRAST, -18);
        $variants[] = $this->toPng($plain);
        imagedestroy($plain);

        imagedestroy($thresh);
        imagedestroy($gray);
        imagedestroy($band);
        imagedestroy($src);

        return array_values(array_filter($variants, static fn ($v) => $v !== ''));
    }

    // ---- pipeline steps ---------------------------------------------------

    private function cropBottom(\GdImage $img, float $fromFrac): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $top = (int) ($h * $fromFrac);
        $bh = max(1, $h - $top);
        $dst = imagecreatetruecolor($w, $bh);
        imagecopy($dst, $img, 0, 0, 0, $top, $w, $bh);
        return $dst;
    }

    private function scaleToWidth(\GdImage $img, int $width): \GdImage
    {
        $w = imagesx($img);
        if ($w <= 0 || $w === $width) {
            return $img;
        }
        $h = (int) round(imagesy($img) * ($width / $w));
        $scaled = imagescale($img, $width, max(1, $h), IMG_BILINEAR_FIXED);
        if ($scaled === false) {
            return $img;
        }
        imagedestroy($img);
        return $scaled;
    }

    private function grayscale(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $g = imagecreatetruecolor($w, $h);
        imagecopy($g, $img, 0, 0, 0, 0, $w, $h);
        imagefilter($g, IMG_FILTER_GRAYSCALE);
        return $g;
    }

    /**
     * Estimate the local background by heavy downscale→upscale (a cheap box
     * blur) and use it as a per-pixel threshold: pixel is foreground (black)
     * when it is at least $c darker than its local background.
     */
    private function adaptiveThreshold(\GdImage $gray, int $c): \GdImage
    {
        $w = imagesx($gray);
        $h = imagesy($gray);
        $bg = $this->localMean($gray);

        $out = imagecreatetruecolor($w, $h);
        $black = imagecolorallocate($out, 0, 0, 0);
        $white = imagecolorallocate($out, 255, 255, 255);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $g = imagecolorat($gray, $x, $y) & 0xFF;
                $m = imagecolorat($bg, $x, $y) & 0xFF;
                imagesetpixel($out, $x, $y, ($g < $m - $c) ? $black : $white);
            }
        }
        imagedestroy($bg);
        return $out;
    }

    /**
     * Flatten illumination: divide each pixel by its local background so the
     * paper becomes uniformly white and reflections are evened out, keeping a
     * grayscale result (some OCR setups read grayscale better than 1-bit).
     */
    private function flatField(\GdImage $gray): \GdImage
    {
        $w = imagesx($gray);
        $h = imagesy($gray);
        $bg = $this->localMean($gray);

        $out = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $g = imagecolorat($gray, $x, $y) & 0xFF;
                $m = imagecolorat($bg, $x, $y) & 0xFF;
                $v = (int) min(255, $g * 255 / max(1, $m));
                imagesetpixel($out, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        imagedestroy($bg);
        return $out;
    }

    /** Smooth local-mean image (downscale then upscale ≈ large box blur). */
    private function localMean(\GdImage $gray): \GdImage
    {
        $w = imagesx($gray);
        $h = imagesy($gray);
        // window ≈ 1/24 of the width → robust against character-sized detail
        $sw = max(1, (int) ($w / 24));
        $sh = max(1, (int) ($h / 6));
        $small = imagescale($gray, $sw, $sh, IMG_BILINEAR_FIXED);
        $blur = $small !== false ? imagescale($small, $w, $h, IMG_BILINEAR_FIXED) : false;
        if ($small !== false) {
            imagedestroy($small);
        }
        if ($blur === false) {
            // fallback: copy of the grayscale
            $blur = imagecreatetruecolor($w, $h);
            imagecopy($blur, $gray, 0, 0, 0, 0, $w, $h);
        }
        return $blur;
    }

    /**
     * Tighten to the MRZ rows using the per-row black-pixel density on the
     * thresholded image. Returns a cropped copy, or null if no clear band.
     */
    private function localizeBand(\GdImage $bin): ?\GdImage
    {
        $w = imagesx($bin);
        $h = imagesy($bin);
        if ($w < 10 || $h < 10) {
            return null;
        }

        $dense = [];
        $minBlack = (int) ($w * 0.12); // MRZ rows are densely filled with glyphs
        for ($y = 0; $y < $h; $y++) {
            $count = 0;
            // sample every 2nd pixel for speed
            for ($x = 0; $x < $w; $x += 2) {
                if ((imagecolorat($bin, $x, $y) & 0xFF) < 128) {
                    $count++;
                }
            }
            $dense[$y] = ($count * 2) >= $minBlack;
        }

        // find the lowest contiguous run of dense rows (the MRZ block)
        $end = null;
        for ($y = $h - 1; $y >= 0; $y--) {
            if ($dense[$y]) {
                $end = $y;
                break;
            }
        }
        if ($end === null) {
            return null;
        }
        $start = $end;
        $gap = 0;
        for ($y = $end; $y >= 0; $y--) {
            if ($dense[$y]) {
                $start = $y;
                $gap = 0;
            } else {
                $gap++;
                if ($gap > (int) ($h * 0.08)) { // allow small gaps between the 2-3 lines
                    break;
                }
            }
        }

        $pad = (int) ($h * 0.03);
        $top = max(0, $start - $pad);
        $bottom = min($h - 1, $end + $pad);
        $ch = $bottom - $top + 1;
        if ($ch < 8 || $ch >= $h) {
            return null; // nothing meaningful gained
        }

        $crop = imagecreatetruecolor($w, $ch);
        imagecopy($crop, $bin, 0, 0, 0, $top, $w, $ch);
        return $crop;
    }

    private function toPng(\GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 4);
        return (string) ob_get_clean();
    }
}

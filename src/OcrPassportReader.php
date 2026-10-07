<?php

declare(strict_types=1);

namespace BWC\Visa;

use Psr\Log\LoggerInterface;

/**
 * Local passport reader: Tesseract OCR -> MRZ lines -> MrzParser.
 * No external API / no API key required.
 *
 * Strategy per image:
 *   1. OCR the whole page, look for MRZ-shaped lines, parse.
 *   2. If that fails, crop the bottom band, upscale & threshold, OCR again.
 *   3. Best-effort place-of-birth via keyword search in the full OCR text.
 */
final class OcrPassportReader implements PassportReaderInterface
{
    public function __construct(
        private readonly string $workDir,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $extractionLogger,
        private readonly string $tesseractBin = 'tesseract',
        private readonly string $lang = 'eng',        // visual zone (place of birth)
        private readonly int $pivotYear = 30,
        private readonly int $prepWidth = 1600,
        private readonly string $mrzLang = 'mrz',      // OCR-B trained model for the MRZ
    ) {
    }

    /**
     * @param array<int,array{media_type:string,data:string,source:string,page:int}> $images
     * @return array<int,array<string,mixed>>
     */
    public function analyzeMany(array $images): array
    {
        $this->diagnostics = [];
        $out = [];
        foreach ($images as $img) {
            try {
                $r = $this->analyze($img);
            } catch (\Throwable) {
                $this->diagnostics[] = ['source' => $img['source'], 'page' => $img['page'], 'status' => 'error', 'message' => 'OCR failed'];
                continue;
            }
            if ($r !== null) {
                $out[] = $r;
            } else {
                $this->diagnostics[] = ['source' => $img['source'], 'page' => $img['page'], 'status' => 'no_passport',
                    'message' => 'No passport / MRZ detected in this file'];
            }
        }
        return $out;
    }

    /** @var array<int,array{source:string,page:int,status:string,message:string}> */
    private array $diagnostics = [];

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function analyze(array $image): ?array
    {
        $binary = base64_decode($image['data'], true);
        if ($binary === false) {
            return null;
        }

        // Collect MRZ parses from several OCR sources and keep the one whose
        // ICAO check digits validate best. This is resilient: a noisy source
        // simply loses the ranking instead of blocking detection. Each entry
        // keeps its raw OCR text + a label so the log shows what actually won.
        $ocr = $this->ocr($binary);
        $candidates = [];
        $this->addCandidate($candidates, 'full-image', $ocr['mrz']);
        $this->addCandidate($candidates, 'full-image-visual-pass', $ocr['visual']);

        // preprocessed MRZ-band variants: glare-killing adaptive threshold,
        // flat-field illumination, contrast fallback (order matches variants()).
        $labels = ['adaptive-threshold', 'flat-field', 'contrast'];
        foreach ((new MrzImagePrep($this->prepWidth))->variants($binary) as $i => $variant) {
            $this->addCandidate($candidates, $labels[$i] ?? "variant-$i", $this->ocrMrz($variant));
        }

        if ($candidates === []) {
            $this->logger->info('No MRZ detected', ['source' => $image['source'], 'page' => $image['page']]);
            $this->extractionLogger->info(
                ExtractionLog::format('ocr', $image['source'], $image['page'], $ocr['mrz'], null)
            );
            return null;
        }

        usort($candidates, fn ($a, $b) => $this->confidence($b['parse']) <=> $this->confidence($a['parse']));
        $winner = $candidates[0];
        $parsed = $winner['parse'];

        // ICAO line-length validation on the winning source's raw OCR text.
        $fmt = MrzCheck::classifyLines(preg_split('/\R/', $winner['raw']) ?: []);
        $parsed['mrz_format'] = $fmt;
        if (!$fmt['valid']) {
            $parsed['check_digits'] = ($parsed['check_digits'] ?? []) + ['mrz_lines' => false];
            $this->logger->warning('MRZ line length invalid — fields may be misaligned', [
                'source' => $image['source'], 'reason' => $fmt['reason'],
                'lengths' => array_map(fn ($l) => $l['len'], $fmt['lines']),
            ]);
        }

        // best-effort place of birth from the (unconstrained) visual-zone text
        $pob = $this->extractPlaceOfBirth($ocr['visual']);
        if ($pob !== null) {
            $parsed['visual']['place_of_birth'] = $pob;
        }

        $parsed['review']  = MrzVerifier::reviewFromChecks($parsed);
        $parsed = MrzVerifier::sanitizeVisual($parsed);
        $parsed['_source'] = $image['source'];
        $parsed['_page']   = $image['page'];

        // write the human-readable extraction record (incl. the winning source)
        $this->extractionLogger->info(
            ExtractionLog::format('ocr (' . $winner['label'] . ')', $image['source'], $image['page'], $winner['raw'], $parsed)
        );

        return $parsed;
    }

    /** Parse $text and, if it yields an MRZ, append it as a labelled candidate. */
    private function addCandidate(array &$candidates, string $label, string $text): void
    {
        $p = $this->tryParse($text);
        if ($p !== null) {
            $candidates[] = ['label' => $label, 'raw' => $text, 'parse' => $p];
        }
    }

    /** Number of valid ICAO check digits in a parse result (ranking key). */
    private function confidence(array $parsed): int
    {
        $c = $parsed['check_digits'] ?? [];
        return count(array_filter($c, static fn ($v) => $v === true));
    }

    /** @return array<string,mixed>|null */
    private function tryParse(string $text): ?array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        // MrzParser normalises (uppercase, strips spaces & non-MRZ chars),
        // applies field-typed coercion and check-digit-guided alignment.
        return (new MrzParser())->parse($lines, $this->pivotYear);
    }

    /**
     * Runs two OCR passes on the full image and returns them separately:
     *   'mrz'    – OCR-B model, charset-constrained (MRZ alphabet only)
     *   'visual' – unconstrained pass for the visual zone (place of birth)
     *
     * @return array{mrz:string,visual:string}
     */
    private function ocr(string $imageBinary): array
    {
        $in = $this->writeTemp($imageBinary);
        $mrz = $this->mrzPass($in);
        $visual = $this->tess($in, $this->lang === 'mrz' ? 'eng' : $this->lang, 3, false);
        @unlink($in);
        return ['mrz' => $mrz, 'visual' => $visual];
    }

    /** Single MRZ OCR pass on a (preprocessed) image binary. */
    private function ocrMrz(string $imageBinary): string
    {
        $in = $this->writeTemp($imageBinary);
        $text = $this->mrzPass($in);
        @unlink($in);
        return $text;
    }

    /**
     * MRZ OCR pass: OCR-B trained model + MRZ char whitelist. The MRZ uses the
     * OCR-B font, which the generic `eng` model misreads — so we use the
     * dedicated OCR-B model ($mrzLang, e.g. "mrz"/"ocrb") and only fall back to
     * `eng` if that model is unavailable (empty output).
     */
    private function mrzPass(string $file): string
    {
        $text = $this->tess($file, $this->mrzLang, 6, true);
        if (trim($text) === '' && $this->mrzLang !== 'eng') {
            $this->logger->warning('OCR-B model unavailable or empty — falling back to eng', ['lang' => $this->mrzLang]);
            $text = $this->tess($file, 'eng', 6, true);
        }
        return $text;
    }

    private function tess(string $file, string $lang, int $psm, bool $mrzWhitelist): string
    {
        $whitelist = $mrzWhitelist
            ? ' -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<'
            : '';
        return $this->run(sprintf(
            '%s %s stdout -l %s --psm %d%s 2>/dev/null',
            escapeshellcmd($this->tesseractBin),
            escapeshellarg($file),
            escapeshellarg($lang),
            $psm,
            $whitelist
        ));
    }

    private function writeTemp(string $binary): string
    {
        $in = $this->workDir . '/ocr_' . bin2hex(random_bytes(5)) . '.png';
        file_put_contents($in, $binary);
        return $in;
    }

    private function run(string $cmd): string
    {
        return ProcRunner::run(rtrim($cmd), 60)['out'];
    }

    /** Look for a place-of-birth keyword and return the value next to it. */
    private function extractPlaceOfBirth(string $text): ?string
    {
        $keywords = [
            'place of birth', 'lieu de naissance', 'luogo di nascita',
            'geburtsort', 'lugar de nacimiento', 'place of issue',
        ];
        $lines = preg_split('/\R/', $text) ?: [];
        foreach ($lines as $i => $line) {
            $low = strtolower($line);
            foreach ($keywords as $kw) {
                if ($kw === 'place of issue') {
                    continue; // negative guard handled below
                }
                $pos = strpos($low, $kw);
                if ($pos !== false) {
                    // value may be after the keyword on the same line, else next line
                    $after = trim(substr($line, $pos + strlen($kw)));
                    $after = trim($after, " :/-\t");
                    if ($this->looksLikeValue($after)) {
                        return $after;
                    }
                    $next = trim($lines[$i + 1] ?? '');
                    if ($this->looksLikeValue($next)) {
                        return $next;
                    }
                }
            }
        }
        return null;
    }

    private function looksLikeValue(string $s): bool
    {
        $s = trim($s);
        return $s !== '' && strlen($s) <= 40 && preg_match('/[A-Za-z]{2,}/', $s) === 1;
    }
}

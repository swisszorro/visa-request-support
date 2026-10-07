<?php

declare(strict_types=1);

namespace BWC\Visa;

use Psr\Log\LoggerInterface;

/**
 * Engine chain, e.g. PASSPORT_ENGINE=vision,ocr: the first reader analyses
 * everything; only files it could not read SAFELY (technical error, no passport,
 * or a passport that needs review) are passed to the next reader. Results are
 * merged per passport number, preferring a read without review reasons. The
 * MrzVerifier still gates every single read, so a fallback can only improve the
 * outcome, never release an unverified passport.
 */
final class FallbackPassportReader implements PassportReaderInterface
{
    /** @var array<int,array{source:string,page:int,status:string,message:string}> */
    private array $diagnostics = [];

    /** @param PassportReaderInterface[] $readers in priority order */
    public function __construct(private readonly array $readers, private readonly LoggerInterface $logger)
    {
        if ($readers === []) {
            throw new \InvalidArgumentException('At least one reader is required.');
        }
    }

    public function analyze(array $image): ?array
    {
        return $this->analyzeMany([$image])[0] ?? null;
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function analyzeMany(array $images): array
    {
        $this->diagnostics = [];
        $first = $this->readers[0];
        $passports = $this->tag($first->analyzeMany($images), $first);
        $diag = $first->diagnostics();
        $remaining = $images;

        foreach (array_slice($this->readers, 1) as $next) {
            $retry = array_values(array_filter($remaining, fn ($img) => !$this->satisfied($img, $passports)));
            if ($retry === []) {
                break;
            }
            $this->logger->info('Engine fallback', [
                'engine' => $next::class, 'files' => array_map(static fn ($i) => $i['source'] . '#' . $i['page'], $retry),
            ]);
            $extra = $this->tag($next->analyzeMany($retry), $next);
            $passports = $this->merge($passports, $extra);
            $diag = $this->mergeDiagnostics($diag, $next->diagnostics(), $retry, $passports);
            $remaining = $retry;
        }

        // diagnostics of images that ended up with a passport are obsolete
        $this->diagnostics = array_values(array_filter($diag, function ($d) use ($passports) {
            foreach ($passports as $p) {
                if (($p['_source'] ?? null) === $d['source'] && ($p['_page'] ?? null) === $d['page']) {
                    return false;
                }
            }
            return true;
        }));
        return $passports;
    }

    /** An image is satisfied when it has at least one passport and none of them needs review. */
    private function satisfied(array $img, array $passports): bool
    {
        $mine = array_filter($passports, static fn ($p) => ($p['_source'] ?? null) === $img['source'] && ($p['_page'] ?? null) === $img['page']);
        if ($mine === []) {
            return false;
        }
        foreach ($mine as $p) {
            if (!empty($p['review'])) {
                return false;
            }
        }
        return true;
    }

    private function tag(array $passports, PassportReaderInterface $reader): array
    {
        foreach ($passports as &$p) {
            $p['_engine'] ??= (new \ReflectionClass($reader))->getShortName();
        }
        return $passports;
    }

    /** Same passport number from two engines: keep the cleaner read (fewer review reasons; tie -> earlier engine). */
    private function merge(array $base, array $extra): array
    {
        $norm = static fn (array $p): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($p['mrz']['document_number'] ?? '')));
        foreach ($extra as $e) {
            $key = $norm($e);
            $hit = null;
            if ($key !== '') {
                foreach ($base as $i => $b) {
                    if ($norm($b) === $key) {
                        $hit = $i;
                        break;
                    }
                }
            }
            if ($hit === null) {
                $base[] = $e;
            } elseif (count($e['review'] ?? []) < count($base[$hit]['review'] ?? [])) {
                $base[$hit] = $e;
            }
        }
        return $base;
    }

    private function mergeDiagnostics(array $old, array $new, array $retried, array $passports): array
    {
        $retriedKeys = array_map(static fn ($i) => $i['source'] . '#' . $i['page'], $retried);
        $out = array_filter($old, static fn ($d) => !in_array($d['source'] . '#' . $d['page'], $retriedKeys, true));
        $seen = [];
        foreach (array_merge(array_filter($old, static fn ($d) => in_array($d['source'] . '#' . $d['page'], $retriedKeys, true)), $new) as $d) {
            $k = $d['source'] . '#' . $d['page'] . '#' . $d['status'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $d;
            }
        }
        return array_values($out);
    }
}

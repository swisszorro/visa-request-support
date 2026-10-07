<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Thin typed accessor over environment variables (loaded from .env via phpdotenv).
 */
final class Config
{
    public static function str(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : (string) $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::str($key, '');
        return $v === '' ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower(self::str($key, ''));
        if ($v === '') {
            return $default;
        }
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return string[] */
    public static function list(string $key): array
    {
        $v = self::str($key, '');
        if ($v === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn ($s) => $s !== ''));
    }
}

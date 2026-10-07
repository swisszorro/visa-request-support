<?php

declare(strict_types=1);

namespace BWC\Visa;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Stateless authentication for automated (server-to-server) callers.
 *
 * Layer 1 (always on): a long random API key sent as either
 *   Authorization: Bearer <key>      or      X-API-Key: <key>
 * compared in constant time against the SERVICE_API_KEYS allow-list.
 *
 * Layer 2 (optional, enabled when SERVICE_HMAC_SECRET is set): a replay-resistant
 * timestamp signature. The caller sends
 *   X-Timestamp: <unix seconds>
 *   X-Signature: hex(hmac_sha256(X-Timestamp, secret))
 * The timestamp must be within ±300s of server time. (A body-HMAC is avoided on
 * purpose: PHP consumes php://input while parsing multipart uploads, so the raw
 * body is not reliably available here.) Recommended for unattended cron/CI callers.
 */
final class Auth
{
    /**
     * @return array{ok:bool, error?:string}
     */
    public static function check(Request $request): array
    {
        $keys = Config::list('SERVICE_API_KEYS');
        if ($keys === []) {
            return ['ok' => false, 'error' => 'Service misconfigured: no SERVICE_API_KEYS set.'];
        }

        $presented = self::extractKey($request);
        if ($presented === null) {
            return ['ok' => false, 'error' => 'Missing API key (Authorization: Bearer <key> or X-API-Key).'];
        }

        $keyOk = false;
        foreach ($keys as $valid) {
            if (hash_equals($valid, $presented)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            return ['ok' => false, 'error' => 'Invalid API key.'];
        }

        $secret = Config::str('SERVICE_HMAC_SECRET', '');
        if ($secret !== '') {
            $ts = trim($request->getHeaderLine('X-Timestamp'));
            $sig = strtolower(trim($request->getHeaderLine('X-Signature')));
            if ($ts === '' || $sig === '') {
                return ['ok' => false, 'error' => 'Missing X-Timestamp / X-Signature (HMAC required).'];
            }
            if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300) {
                return ['ok' => false, 'error' => 'Stale or invalid X-Timestamp (allowed drift ±300s).'];
            }
            $expected = hash_hmac('sha256', $ts, $secret);
            if (!hash_equals($expected, $sig)) {
                return ['ok' => false, 'error' => 'Invalid HMAC signature.'];
            }
        }

        return ['ok' => true];
    }

    private static function extractKey(Request $request): ?string
    {
        // Authorization header — with fallbacks for shared hosting where Apache
        // strips it (exposed via $_SERVER HTTP_AUTHORIZATION / REDIRECT_… or
        // apache_request_headers()).
        $auth = $request->getHeaderLine('Authorization');
        if ($auth === '') {
            $auth = (string) ($_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if ($auth === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $auth = (string) $v;
                    break;
                }
            }
        }
        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        $x = trim($request->getHeaderLine('X-API-Key'));
        if ($x === '') {
            $x = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
        }
        return $x !== '' ? $x : null;
    }
}

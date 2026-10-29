<?php

declare(strict_types=1);

namespace BWC\Visa;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Stateless authentication for automated (server-to-server) callers.
 *
 * Layer 1 (always on): a long random API key sent as either
 *   Authorization: Bearer <key>      or      X-API-Key: <key>
 * compared in constant time against the keys of all clients (config/clients.php) and the
 * allow-list in .env SERVICE_API_KEYS. The key identifies the CLIENT whose SMTP / mail /
 * event / template settings apply (see ClientProfile).
 *
 * Layer 2 (optional, per client or SERVICE_HMAC_SECRET for the .env keys): a replay-resistant
 * timestamp signature. The caller sends
 *   X-Timestamp: <unix seconds>
 *   X-Signature: hex(hmac_sha256(X-Timestamp, secret))
 * The timestamp must be within ±300s of server time. (A body-HMAC is avoided on
 * purpose: PHP consumes php://input while parsing multipart uploads, so the raw
 * body is not reliably available here.)
 */
final class Auth
{
    /**
     * @return array{ok:true,client:ClientProfile}|array{ok:false,error:string,status:int}
     */
    public static function check(Request $request, ?ClientRegistry $registry = null): array
    {
        try {
            $registry ??= ClientRegistry::load();
        } catch (\RuntimeException) {
            return ['ok' => false, 'status' => 500, 'error' => 'Service misconfigured: client configuration is invalid.'];
        }
        if (!$registry->hasKeys()) {
            return ['ok' => false, 'status' => 500, 'error' => 'Service misconfigured: no API keys set.'];
        }

        $presented = self::extractKey($request);
        if ($presented === null) {
            return ['ok' => false, 'status' => 401, 'error' => 'Missing API key (Authorization: Bearer <key> or X-API-Key).'];
        }

        $client = $registry->match($presented);
        if ($client === null) {
            return ['ok' => false, 'status' => 401, 'error' => 'Invalid API key.'];
        }

        $secret = $client->hmacSecret();
        if ($secret !== '') {
            $ts = trim($request->getHeaderLine('X-Timestamp'));
            $sig = strtolower(trim($request->getHeaderLine('X-Signature')));
            if ($ts === '' || $sig === '') {
                return ['ok' => false, 'status' => 401, 'error' => 'Missing X-Timestamp / X-Signature (HMAC required).'];
            }
            if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300) {
                return ['ok' => false, 'status' => 401, 'error' => 'Stale or invalid X-Timestamp (allowed drift ±300s).'];
            }
            $expected = hash_hmac('sha256', $ts, $secret);
            if (!hash_equals($expected, $sig)) {
                return ['ok' => false, 'status' => 401, 'error' => 'Invalid HMAC signature.'];
            }
        }

        return ['ok' => true, 'client' => $client];
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

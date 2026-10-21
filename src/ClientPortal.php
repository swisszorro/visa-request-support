<?php

declare(strict_types=1);

namespace BWC\Visa;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * Server side of the client form (public/client/): loads the connection settings from
 * config/client.php (NOT .env), protects the page with HTTP Basic auth + a CSRF token and
 * forwards the upload to the visa service. The API key stays on the server.
 */
final class ClientPortal
{
    public static function configPath(): string
    {
        $p = getenv('BWC_CLIENT_CONFIG');
        return ($p !== false && $p !== '') ? $p : dirname(__DIR__) . '/config/client.php';
    }

    /**
     * @return array{api_url:string,api_key:string,hmac_secret:string,users:array<string,string>,allow_anonymous:bool,defaults:array<string,string>,timeout:int,max_images:int,verify_tls:bool}
     * @throws \RuntimeException with a message that is safe to show
     */
    public static function load(?string $path = null): array
    {
        $path ??= self::configPath();
        if (!is_file($path)) {
            throw new \RuntimeException('Client configuration missing: copy config/client.example.php to config/client.php and fill it in.');
        }
        $cfg = (static function (string $f) {
            return require $f;
        })($path);
        if (!is_array($cfg)) {
            throw new \RuntimeException('Client configuration is invalid: the file must return an array.');
        }

        $url = rtrim(trim((string) ($cfg['api_url'] ?? '')), '/');
        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
            throw new \RuntimeException('Client configuration: api_url must be a http(s) URL.');
        }
        $key = trim((string) ($cfg['api_key'] ?? ''));
        if ($key === '' || $key === 'change-me') {
            throw new \RuntimeException('Client configuration: api_key is not set.');
        }

        $users = [];
        foreach ((array) ($cfg['users'] ?? []) as $name => $hash) {
            if (is_string($name) && $name !== '' && is_string($hash) && $hash !== '') {
                $users[$name] = $hash;
            }
        }

        return [
            'api_url'         => $url,
            'api_key'         => $key,
            'hmac_secret'     => (string) ($cfg['hmac_secret'] ?? ''),
            'users'           => $users,
            'allow_anonymous' => (bool) ($cfg['allow_anonymous'] ?? false),
            'defaults'        => array_map('strval', array_filter((array) ($cfg['defaults'] ?? []), 'is_scalar')),
            'timeout'         => max(10, (int) ($cfg['timeout'] ?? 280)),
            'max_images'      => max(1, (int) ($cfg['max_images'] ?? 24)),
            'verify_tls'      => (bool) ($cfg['verify_tls'] ?? true),
        ];
    }

    // ---- access protection -------------------------------------------------

    /** The Authorization header, also on Apache/CGI setups that hide it. */
    public static function authorizationHeader(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
            if (!empty($_SERVER[$k])) {
                return (string) $_SERVER[$k];
            }
        }
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? ''));
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    return (string) $v;
                }
            }
        }
        return '';
    }

    /**
     * @param array<string,string> $users username => password_hash()
     * @return string 'ok' | 'denied' | 'unconfigured' (no users and anonymous access not allowed)
     */
    public static function authenticate(array $cfg, string $authorization): string
    {
        $users = $cfg['users'] ?? [];
        if ($users === []) {
            return !empty($cfg['allow_anonymous']) ? 'ok' : 'unconfigured';
        }
        if (stripos($authorization, 'basic ') !== 0) {
            return 'denied';
        }
        $decoded = base64_decode(trim(substr($authorization, 6)), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return 'denied';
        }
        [$user, $pass] = explode(':', $decoded, 2);
        // same work for unknown users: no user enumeration by timing
        $hash = $users[$user] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($pass, $hash);
        return ($ok && isset($users[$user])) ? 'ok' : 'denied';
    }

    // ---- CSRF ----------------------------------------------------------------

    public static function csrfToken(): string
    {
        if (empty($_SESSION['bwc_csrf'])) {
            $_SESSION['bwc_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['bwc_csrf'];
    }

    public static function csrfValid(string $token): bool
    {
        return $token !== '' && !empty($_SESSION['bwc_csrf']) && hash_equals((string) $_SESSION['bwc_csrf'], $token);
    }

    // ---- forwarding ----------------------------------------------------------

    /**
     * Calls POST <api_url>/api/visa-request with the connection data from the config file.
     *
     * @param array<string,string> $fields text fields (name, firstname, ...)
     * @param array<int,array{field:string,path:string,name:string}> $files uploads (field "excel" or "images[]")
     * @param callable|null $handler optional Guzzle handler (tests)
     * @return array{status:int,body:string}  body is always JSON
     */
    public static function forward(array $cfg, array $fields, array $files, ?callable $handler = null): array
    {
        $multipart = [];
        foreach ($fields as $k => $v) {
            $multipart[] = ['name' => (string) $k, 'contents' => (string) $v];
        }
        foreach ($files as $f) {
            $multipart[] = ['name' => $f['field'], 'contents' => fopen($f['path'], 'r'), 'filename' => $f['name']];
        }

        $headers = ['Authorization' => 'Bearer ' . $cfg['api_key'], 'Accept' => 'application/json'];
        if (($cfg['hmac_secret'] ?? '') !== '') {
            $ts = (string) time();
            $headers['X-Timestamp'] = $ts;
            $headers['X-Signature'] = hash_hmac('sha256', $ts, $cfg['hmac_secret']);
        }

        $opts = [
            'timeout'         => $cfg['timeout'],
            'connect_timeout' => 10,
            'http_errors'     => false,
            'allow_redirects' => false, // never replay the API key to a redirect target
            'verify'          => $cfg['verify_tls'],
        ];
        if ($handler !== null) {
            $opts['handler'] = HandlerStack::create($handler);
        }

        try {
            $resp = (new Client($opts))->post($cfg['api_url'] . '/api/visa-request', ['headers' => $headers, 'multipart' => $multipart]);
        } catch (\Throwable) {
            return self::error(502, 'Der Visa-Service ist nicht erreichbar oder hat nicht rechtzeitig geantwortet.');
        } finally {
            foreach ($multipart as $part) {
                if (is_resource($part['contents'] ?? null)) {
                    fclose($part['contents']);
                }
            }
        }

        if (in_array($resp->getStatusCode(), [401, 403], true)) {
            // wrong key / HMAC: a configuration problem, not a login prompt for the user
            return self::error(502, 'Der Visa-Service hat die Zugangsdaten der Client-Konfiguration abgelehnt (api_key / hmac_secret prüfen).');
        }
        $body = (string) $resp->getBody();
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return self::error(502, 'Unerwartete Antwort des Visa-Services (HTTP ' . $resp->getStatusCode() . ').');
        }
        return ['status' => $resp->getStatusCode(), 'body' => $body];
    }

    /** @return array{status:int,body:string} */
    public static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => (string) json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE)];
    }
}

<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Settings of ONE API client (tenant): SMTP server, mail texts, event window and letter template.
 *
 * Two kinds:
 *  - "default" (legacy): the keys in .env SERVICE_API_KEYS; everything comes from .env as before.
 *  - named profiles from config/clients.php: fully self-contained. They deliberately inherit NOTHING
 *    from .env - otherwise a client could silently send with another client's SMTP credentials or
 *    sender address.
 */
final class ClientProfile
{
    public const DEFAULT_SUBJECT = 'Berne World Cup – Visa Invitation Letter';

    private const TOP_KEYS   = ['name', 'api_key', 'api_keys', 'api_key_hashes', 'hmac_secret', 'template', 'event', 'smtp', 'mail'];
    private const SMTP_KEYS  = ['host', 'port', 'secure', 'user', 'pass'];
    private const MAIL_KEYS  = ['enabled', 'default_to', 'subject', 'from', 'from_name', 'html', 'body'];
    private const EVENT_KEYS = ['start', 'end'];

    /** @param array<string,mixed> $cfg validated profile array (named profiles only) */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        private readonly array $cfg,
        private readonly bool $legacy,
    ) {
    }

    public static function legacy(): self
    {
        return new self('default', 'Default (.env)', [], true);
    }

    /**
     * @param array<string,mixed> $cfg
     * @throws \RuntimeException on a malformed profile (typos are errors, not silently ignored)
     */
    public static function fromArray(string $id, array $cfg): self
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id)) {
            throw new \RuntimeException("Client id '{$id}' is invalid (use a-z, 0-9, _ and -, max 40 characters).");
        }
        self::rejectUnknown($id, 'profile', $cfg, self::TOP_KEYS);
        foreach (['smtp' => self::SMTP_KEYS, 'mail' => self::MAIL_KEYS, 'event' => self::EVENT_KEYS] as $section => $allowed) {
            if (isset($cfg[$section])) {
                if (!is_array($cfg[$section])) {
                    throw new \RuntimeException("Client '{$id}': '{$section}' must be an array.");
                }
                self::rejectUnknown($id, $section, $cfg[$section], $allowed);
            }
        }

        $secure = strtolower((string) ($cfg['smtp']['secure'] ?? 'tls'));
        if (!in_array($secure, ['tls', 'ssl', 'none', ''], true)) {
            throw new \RuntimeException("Client '{$id}': smtp.secure must be tls, ssl or none.");
        }
        if (isset($cfg['smtp']['port']) && !is_int($cfg['smtp']['port']) && !ctype_digit((string) $cfg['smtp']['port'])) {
            throw new \RuntimeException("Client '{$id}': smtp.port must be a number.");
        }
        foreach (['start', 'end'] as $k) {
            $v = (string) ($cfg['event'][$k] ?? '');
            if ($v !== '' && !MrzVerifier::isDate($v)) {
                throw new \RuntimeException("Client '{$id}': event.{$k} must be an ISO date (YYYY-MM-DD) or empty.");
            }
        }
        $start = (string) ($cfg['event']['start'] ?? '');
        $end = (string) ($cfg['event']['end'] ?? '');
        if ($start !== '' && $end !== '' && $start > $end) {
            throw new \RuntimeException("Client '{$id}': event.start is after event.end.");
        }
        if (trim((string) ($cfg['template'] ?? '')) === '') {
            throw new \RuntimeException("Client '{$id}': 'template' (path of the Word letter template) is required.");
        }

        return new self($id, (string) ($cfg['name'] ?? $id), $cfg, false);
    }

    /** @param array<string,mixed> $given @param string[] $allowed */
    private static function rejectUnknown(string $id, string $where, array $given, array $allowed): void
    {
        $unknown = array_diff(array_keys($given), $allowed);
        if ($unknown !== []) {
            throw new \RuntimeException("Client '{$id}': unknown key(s) in {$where}: " . implode(', ', $unknown)
                . ' (allowed: ' . implode(', ', $allowed) . ').');
        }
    }

    // ---- API keys (named profiles only; the legacy profile is matched via .env) -------------

    /** @return string[] */
    private function plainKeys(): array
    {
        return array_values(array_filter(array_merge(
            isset($this->cfg['api_key']) ? [(string) $this->cfg['api_key']] : [],
            array_map('strval', (array) ($this->cfg['api_keys'] ?? []))
        ), static fn ($k) => $k !== ''));
    }

    public function matchesKey(string $presented): bool
    {
        $hit = false;
        foreach ($this->plainKeys() as $k) {
            if (hash_equals($k, $presented)) {
                $hit = true;
            }
        }
        $digest = hash('sha256', $presented);
        foreach ((array) ($this->cfg['api_key_hashes'] ?? []) as $h) {
            if (is_string($h) && hash_equals(strtolower($h), $digest)) {
                $hit = true;
            }
        }
        return $hit;
    }

    /** @return string[] sha256 of every configured key / the configured hashes (duplicate detection across clients) */
    public function keyFingerprints(): array
    {
        $out = array_map(static fn ($k) => hash('sha256', $k), $this->plainKeys());
        foreach ((array) ($this->cfg['api_key_hashes'] ?? []) as $h) {
            if (is_string($h) && $h !== '') {
                $out[] = strtolower($h);
            }
        }
        return $out;
    }

    public function hmacSecret(): string
    {
        return $this->legacy ? Config::str('SERVICE_HMAC_SECRET', '') : (string) ($this->cfg['hmac_secret'] ?? '');
    }

    // ---- SMTP / mail / event / template ----------------------------------------------------

    /** @return array{host:string,port:int,secure:string,user:string,pass:string} */
    public function smtp(): array
    {
        if ($this->legacy) {
            return [
                'host'   => Config::str('SMTP_HOST'),
                'port'   => Config::int('SMTP_PORT', 587),
                'secure' => strtolower(Config::str('SMTP_SECURE', 'tls')),
                'user'   => Config::str('SMTP_USER'),
                'pass'   => Config::str('SMTP_PASS'),
            ];
        }
        $s = (array) ($this->cfg['smtp'] ?? []);
        return [
            'host'   => (string) ($s['host'] ?? ''),
            'port'   => (int) ($s['port'] ?? 587),
            'secure' => strtolower((string) ($s['secure'] ?? 'tls')),
            'user'   => (string) ($s['user'] ?? ''),
            'pass'   => (string) ($s['pass'] ?? ''),
        ];
    }

    /** @return array{enabled:bool,default_to:string,subject:string,from:string,from_name:string,html:bool,body:string} */
    public function mail(): array
    {
        if ($this->legacy) {
            return [
                'enabled'    => Config::bool('MAIL_ENABLED', false),
                'default_to' => Config::str('MAIL_DEFAULT_TO', ''),
                'subject'    => Config::str('MAIL_SUBJECT', self::DEFAULT_SUBJECT),
                'from'       => Config::str('MAIL_FROM'),
                'from_name'  => Config::str('MAIL_FROM_NAME', 'Berne World Cup OC'),
                'html'       => Config::bool('MAIL_HTML', false),
                'body'       => Config::str('MAIL_BODY', ''),
            ];
        }
        $m = (array) ($this->cfg['mail'] ?? []);
        return [
            'enabled'    => (bool) ($m['enabled'] ?? false),
            'default_to' => (string) ($m['default_to'] ?? ''),
            'subject'    => (string) (($m['subject'] ?? '') !== '' ? $m['subject'] : 'Visa Invitation Letter'),
            'from'       => (string) ($m['from'] ?? ''),
            'from_name'  => (string) ($m['from_name'] ?? ''),
            'html'       => (bool) ($m['html'] ?? false),
            'body'       => (string) ($m['body'] ?? ''),
        ];
    }

    public function eventStart(): ?string
    {
        $v = $this->legacy ? Config::str('EVENT_START', '') : (string) ($this->cfg['event']['start'] ?? '');
        return $v !== '' ? $v : null;
    }

    public function eventEnd(): ?string
    {
        $v = $this->legacy ? Config::str('EVENT_END', '') : (string) ($this->cfg['event']['end'] ?? '');
        return $v !== '' ? $v : null;
    }

    /**
     * Absolute path of the Word letter template. Relative paths are relative to the project root.
     * Checked before any paid analysis starts: must exist and be a real .docx.
     *
     * @throws \RuntimeException
     */
    public function templatePath(): string
    {
        $configured = $this->legacy
            ? Config::str('LETTER_TEMPLATE', 'resources/BWC Visa Invitation Letter.docx')
            : (string) $this->cfg['template'];
        $path = ($configured !== '' && $configured[0] === '/') ? $configured : dirname(__DIR__) . '/' . $configured;
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Word template not found for client '{$this->id}': {$configured}");
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true || $zip->locateName('word/document.xml') === false) {
            throw new \RuntimeException("Template for client '{$this->id}' is not a valid .docx file: {$configured}");
        }
        $zip->close();
        return $path;
    }
}

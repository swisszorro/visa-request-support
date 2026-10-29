<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Maps an API key to its ClientProfile.
 *
 * Sources:
 *  1. config/clients.php  (named profiles, each with its own SMTP / mail / event / template)
 *  2. .env SERVICE_API_KEYS (the legacy "default" profile; everything from .env)
 * Another location for the file: environment variable BWC_CLIENTS_CONFIG.
 */
final class ClientRegistry
{
    /** @param array<string,ClientProfile> $profiles named profiles */
    public function __construct(private readonly array $profiles)
    {
    }

    public static function configPath(): string
    {
        $p = getenv('BWC_CLIENTS_CONFIG');
        return ($p !== false && $p !== '') ? $p : dirname(__DIR__) . '/config/clients.php';
    }

    /**
     * @throws \RuntimeException if the file exists but is malformed (fail loudly instead of silently dropping a client)
     */
    public static function load(?string $path = null): self
    {
        $path ??= self::configPath();
        $profiles = [];
        if (is_file($path)) {
            $cfg = (static function (string $f) {
                return require $f;
            })($path);
            if (!is_array($cfg)) {
                throw new \RuntimeException('config/clients.php must return an array (client id => settings).');
            }
            $seen = [];
            foreach ($cfg as $id => $profile) {
                if (!is_string($id) || !is_array($profile)) {
                    throw new \RuntimeException('config/clients.php: every entry must be "client-id" => [settings].');
                }
                if ($id === 'default') {
                    throw new \RuntimeException("config/clients.php: the id 'default' is reserved for the keys in .env.");
                }
                $p = ClientProfile::fromArray($id, $profile);
                $fingerprints = $p->keyFingerprints();
                if ($fingerprints === []) {
                    throw new \RuntimeException("Client '{$id}' has no API key (api_key, api_keys or api_key_hashes).");
                }
                foreach ($fingerprints as $fp) {
                    if (isset($seen[$fp])) {
                        throw new \RuntimeException("The same API key is used by the clients '{$seen[$fp]}' and '{$id}'.");
                    }
                    $seen[$fp] = $id;
                }
                $profiles[$id] = $p;
            }
        }
        return new self($profiles);
    }

    public function hasKeys(): bool
    {
        return $this->profiles !== [] || Config::list('SERVICE_API_KEYS') !== [];
    }

    public function match(string $presented): ?ClientProfile
    {
        $found = null;
        foreach ($this->profiles as $p) {            // no early exit: same work for every request
            if ($p->matchesKey($presented) && $found === null) {
                $found = $p;
            }
        }
        foreach (Config::list('SERVICE_API_KEYS') as $k) {
            if (hash_equals($k, $presented) && $found === null) {
                $found = ClientProfile::legacy();
            }
        }
        return $found;
    }

    /** @return array<string,ClientProfile> */
    public function profiles(): array
    {
        return $this->profiles;
    }
}

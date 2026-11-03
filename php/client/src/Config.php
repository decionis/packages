<?php

declare(strict_types=1);

namespace Decionis\Client;

/**
 * Where the client talks to and as whom. The API key is never printed: it is excluded from
 * var_dump, print_r and serialization.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.decionis.com';
    public const DEFAULT_JWKS_URL = 'https://api.decionis.com/v1/.well-known/decision-dossier-jwks.json';
    public const VERSION = '0.1.0';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public readonly string $baseUrl;

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        public readonly ?string $orgId,
        string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly string $jwksUrl = self::DEFAULT_JWKS_URL,
        public readonly string $userAgent = 'decionis-php-client/' . self::VERSION,
    ) {
        if ($apiKey === '' || strlen($apiKey) > 512 || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1) {
            throw new \InvalidArgumentException('CONFIG_API_KEY_INVALID');
        }
        if ($orgId !== null && preg_match(self::UUID, $orgId) !== 1) {
            throw new \InvalidArgumentException('CONFIG_ORG_ID_INVALID');
        }
        $this->baseUrl = self::origin($baseUrl);
        self::origin($jwksUrl, true);
    }

    public function authorization(): string
    {
        return 'Bearer ' . $this->apiKey;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'orgId' => $this->orgId, 'apiKey' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('CONFIG_NOT_SERIALIZABLE');
    }

    /**
     * HTTPS only, without credentials, query or fragment. Plain HTTP is allowed for loopback
     * hosts so tests and local doubles work.
     */
    private static function origin(string $url, bool $withPath = false): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $scheme = $parts['scheme'] ?? '';
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        $valid = $parts !== false && $host !== ''
            && ($scheme === 'https' || ($scheme === 'http' && $loopback))
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && ($withPath || in_array($parts['path'] ?? '', ['', '/'], true));
        if (!$valid) {
            throw new \InvalidArgumentException('CONFIG_URL_INVALID');
        }
        return rtrim($url, '/');
    }
}

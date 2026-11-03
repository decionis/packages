<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

/**
 * A published Decision Dossier key set. Only Ed25519 signing keys count, and a key id must match
 * exactly one of them.
 */
final class Jwks
{
    private const MAX_KEYS = 128;

    /** @param list<array<mixed>> $keys */
    private function __construct(private readonly array $keys)
    {
    }

    public static function fromJson(string $json): self
    {
        try {
            return self::fromArray(json_decode($json, true, 16, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new \InvalidArgumentException('JWKS_INVALID');
        }
    }

    public static function fromArray(mixed $document): self
    {
        if (!is_array($document) || !isset($document['keys']) || !is_array($document['keys'])) {
            throw new \InvalidArgumentException('JWKS_INVALID');
        }
        if (count($document['keys']) > self::MAX_KEYS) {
            throw new \InvalidArgumentException('JWKS_TOO_MANY_KEYS');
        }
        $keys = [];
        foreach ($document['keys'] as $key) {
            if (!is_array($key)) {
                throw new \InvalidArgumentException('JWKS_INVALID');
            }
            $keys[] = $key;
        }
        return new self($keys);
    }

    /**
     * The raw 32-byte public key for a key id, or null when none or several keys match.
     *
     * @return non-empty-string|null
     */
    public function publicKey(string $keyId): ?string
    {
        if (trim($keyId) === '') {
            return null;
        }
        $matches = array_values(array_filter($this->keys, static fn (array $key): bool =>
            ($key['kty'] ?? null) === 'OKP'
            && ($key['crv'] ?? null) === 'Ed25519'
            && ($key['kid'] ?? null) === $keyId
            && (!array_key_exists('alg', $key) || $key['alg'] === 'EdDSA')
            && (!array_key_exists('use', $key) || $key['use'] === 'sig')
            && is_string($key['x'] ?? null)
            && preg_match('/^[A-Za-z0-9_-]{43}$/', $key['x']) === 1));
        if (count($matches) !== 1) {
            return null;
        }
        $raw = sodium_base642bin($matches[0]['x'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        return $raw !== '' && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }

    /** @return list<string> */
    public function keyIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $key): ?string => is_string($key['kid'] ?? null) ? $key['kid'] : null,
            $this->keys,
        )));
    }
}

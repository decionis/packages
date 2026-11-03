<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;

/**
 * The published Decision Dossier JWKS, fetched over HTTPS and cached. A key id the cached set
 * does not know triggers one refetch, so a rotation is picked up without trusting anything new.
 */
final class KeySource
{
    private const TTL = 3600;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $url,
        private readonly Cache $cache = new MemoryCache(),
    ) {
    }

    public function jwks(?string $keyId = null): Jwks
    {
        $cacheKey = 'decionis_jwks:' . hash('sha256', $this->url);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $jwks = Jwks::fromJson($cached);
            if ($keyId === null || $jwks->publicKey($keyId) !== null) {
                return $jwks;
            }
        }
        $body = $this->transport->send('GET', $this->url, [], null, new Retry(2, 200, 1000, 10000));
        $jwks = Jwks::fromJson($body);
        $this->cache->set($cacheKey, $body, self::TTL);
        return $jwks;
    }
}

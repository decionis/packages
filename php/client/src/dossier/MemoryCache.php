<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

/** A per-process cache, the default when the host offers none. */
final class MemoryCache implements Cache
{
    /** @var array<string, array{string, int}> */
    private array $entries = [];

    public function get(string $key): ?string
    {
        $entry = $this->entries[$key] ?? null;
        return $entry !== null && $entry[1] > time() ? $entry[0] : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->entries[$key] = [$value, time() + $ttlSeconds];
    }
}

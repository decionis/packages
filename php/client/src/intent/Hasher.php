<?php

declare(strict_types=1);

namespace Decionis\Client\Intent;

use Decionis\Client\Canonical\SortedJson;

/**
 * The agent-safe.intent/1 hash: SHA-256 over the key-sorted canonical JSON bytes, written as
 * `sha256:<hex>`. It reproduces AgentSafe's CanonicalIntentHasher byte for byte.
 */
final class Hasher
{
    private readonly SortedJson $canonical;

    public function __construct()
    {
        $this->canonical = new SortedJson();
    }

    public function canonical(mixed $binding): string
    {
        return $this->canonical->encode($binding instanceof Intent ? $binding->toArray() : $binding);
    }

    public function hash(mixed $binding): string
    {
        return 'sha256:' . hash('sha256', $this->canonical($binding));
    }

    /** Constant-time comparison of two `sha256:` hashes. */
    public static function matches(string $expected, string $actual): bool
    {
        return preg_match('/^sha256:[0-9a-f]{64}$/', $expected) === 1 && hash_equals($expected, $actual);
    }
}

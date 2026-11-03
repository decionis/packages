<?php

declare(strict_types=1);

namespace Decionis\Client\Canonical;

/**
 * Key-sorted JSON as `JSON.stringify` writes a key-sorted object: the agent-safe.intent/1 hash
 * input, and the DECIONIS_SORTED_JSON_V1 proof-bundle profile.
 */
final class SortedJson extends Encoder
{
    public const PROFILE = 'DECIONIS_SORTED_JSON_V1';

    public function profile(): string
    {
        return self::PROFILE;
    }

    protected function order(array $keys): array
    {
        return Keys::javascript($keys);
    }
}

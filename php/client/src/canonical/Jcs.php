<?php

declare(strict_types=1);

namespace Decionis\Client\Canonical;

/** RFC 8785 JSON Canonicalization Scheme. */
final class Jcs extends Encoder
{
    public const PROFILE = 'RFC8785/JCS';

    public function profile(): string
    {
        return self::PROFILE;
    }

    protected function order(array $keys): array
    {
        return Keys::utf16($keys);
    }
}

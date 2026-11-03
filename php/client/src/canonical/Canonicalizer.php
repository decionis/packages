<?php

declare(strict_types=1);

namespace Decionis\Client\Canonical;

/**
 * Turns a JSON value into the exact bytes a Decionis signer or hasher produced.
 *
 * Values follow PHP's JSON model: a list array is a JSON array, any other array, a stdClass or a
 * JsonSerializable is a JSON object. An empty PHP array is ambiguous and encodes as `[]`; pass
 * `new \stdClass()` for an empty object.
 */
interface Canonicalizer
{
    /** The profile name a proof bundle states for this encoding. */
    public function profile(): string;

    /** @throws CanonicalException when the value cannot be represented exactly. */
    public function encode(mixed $value): string;
}

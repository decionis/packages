<?php

declare(strict_types=1);

namespace Decionis\Client\Canonical;

/**
 * Object key orders. RFC 8785 sorts by UTF-16 code units. A key-sorted JavaScript object that is
 * then stringified also moves array-index keys ('0' to '4294967294') to the front in numeric order,
 * which is the order agent-safe.intent/1 and DECIONIS_SORTED_JSON_V1 produce.
 */
final class Keys
{
    private const MAX_INDEX = 4294967294;

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    public static function utf16(array $keys): array
    {
        $units = [];
        foreach ($keys as $key) {
            $units[$key] = self::codeUnits($key);
        }
        usort($keys, static fn (string $a, string $b): int => strcmp($units[$a], $units[$b]));
        return $keys;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    public static function javascript(array $keys): array
    {
        $indices = [];
        $names = [];
        foreach ($keys as $key) {
            if (self::isIndex($key)) {
                $indices[] = $key;
            } else {
                $names[] = $key;
            }
        }
        usort($indices, static fn (string $a, string $b): int => (int) $a <=> (int) $b);
        return [...$indices, ...self::utf16($names)];
    }

    private static function isIndex(string $key): bool
    {
        return preg_match('/^(0|[1-9][0-9]{0,9})$/', $key) === 1 && (int) $key <= self::MAX_INDEX;
    }

    /** The key as big-endian UTF-16, so a byte comparison is a code-unit comparison. */
    private static function codeUnits(string $key): string
    {
        if (preg_match('//u', $key) !== 1) {
            throw new CanonicalException('CANONICAL_STRING_NOT_UTF8');
        }
        $units = '';
        $length = strlen($key);
        for ($i = 0; $i < $length;) {
            $byte = ord($key[$i]);
            if ($byte < 0x80) {
                $point = $byte;
                $i += 1;
            } elseif ($byte < 0xE0) {
                $point = (($byte & 0x1F) << 6) | (ord($key[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($byte < 0xF0) {
                $point = (($byte & 0x0F) << 12) | ((ord($key[$i + 1]) & 0x3F) << 6)
                    | (ord($key[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $point = (($byte & 0x07) << 18) | ((ord($key[$i + 1]) & 0x3F) << 12)
                    | ((ord($key[$i + 2]) & 0x3F) << 6) | (ord($key[$i + 3]) & 0x3F);
                $i += 4;
            }
            if ($point >= 0x10000) {
                $point -= 0x10000;
                $units .= pack('n', 0xD800 | ($point >> 10)) . pack('n', 0xDC00 | ($point & 0x3FF));
            } else {
                $units .= pack('n', $point);
            }
        }
        return $units;
    }
}

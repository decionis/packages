<?php

declare(strict_types=1);

namespace Decionis\Client\Canonical;

/**
 * Encodes strings and numbers exactly as ECMAScript's JSON.stringify does, which is what the
 * TypeScript signers and hashers use: strings verbatim as UTF-8 with only the mandatory escapes,
 * numbers in Number::toString form (`1e+21`, `1.5`, `-0` as `0`).
 */
final class Scalar
{
    /** Largest integer a JavaScript number holds exactly (2^53 - 1). */
    private const SAFE_INTEGER = 9007199254740991;

    private const STRING_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_THROW_ON_ERROR;

    public static function string(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new CanonicalException('CANONICAL_STRING_NOT_UTF8');
        }
        try {
            return json_encode($value, self::STRING_FLAGS);
        } catch (\JsonException) {
            throw new CanonicalException('CANONICAL_STRING_NOT_UTF8');
        }
    }

    public static function integer(int $value): string
    {
        if ($value > self::SAFE_INTEGER || $value < -self::SAFE_INTEGER) {
            throw new CanonicalException('CANONICAL_INTEGER_UNSAFE');
        }
        return (string) $value;
    }

    public static function float(float $value): string
    {
        if (!is_finite($value)) {
            throw new CanonicalException('CANONICAL_NUMBER_NOT_FINITE');
        }
        if ($value == 0.0) {
            return '0';
        }
        [$digits, $point] = self::shortest(abs($value));
        $sign = $value < 0 ? '-' : '';
        $count = strlen($digits);
        if ($count <= $point && $point <= 21) {
            return $sign . $digits . str_repeat('0', $point - $count);
        }
        if (0 < $point && $point <= 21) {
            return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        if (-6 < $point && $point <= 0) {
            return $sign . '0.' . str_repeat('0', -$point) . $digits;
        }
        $exponent = $point - 1;
        $mantissa = $count === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
        return $sign . $mantissa . 'e' . ($exponent < 0 ? '-' : '+') . abs($exponent);
    }

    /**
     * The fewest significant digits that read back as the same double, and the decimal point
     * position: value = 0.DIGITS x 10^point.
     *
     * @return array{string, int}
     */
    private static function shortest(float $value): array
    {
        for ($precision = 1; $precision <= 17; $precision++) {
            $text = sprintf('%.' . ($precision - 1) . 'e', $value);
            if ((float) $text === $value) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $text);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        return [$digits === '' ? '0' : $digits, (int) $exponent + 1];
    }
}

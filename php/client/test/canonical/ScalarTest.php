<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Canonical;

use Decionis\Client\Canonical\CanonicalException;
use Decionis\Client\Canonical\Jcs;
use Decionis\Client\Canonical\Scalar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The expected bytes are what Node's JSON.stringify prints for the same value. */
final class ScalarTest extends TestCase
{
    /** @return iterable<string, array{float, string}> */
    public static function numbers(): iterable
    {
        yield 'integral' => [42.0, '42'];
        yield 'negative zero' => [-0.0, '0'];
        yield 'fraction' => [1.5, '1.5'];
        yield 'tenth' => [0.1, '0.1'];
        yield 'float sum' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'exponent boundary' => [1e21, '1e+21'];
        yield 'below exponent boundary' => [1e20, '100000000000000000000'];
        yield 'large mantissa' => [1.2345e22, '1.2345e+22'];
        yield 'small decimal' => [0.000001, '0.000001'];
        yield 'small exponent' => [1e-7, '1e-7'];
        yield 'small mantissa' => [1.5e-7, '1.5e-7'];
        yield 'negative' => [-35.25, '-35.25'];
        yield 'max double' => [1.7976931348623157e308, '1.7976931348623157e+308'];
        yield 'min subnormal' => [5e-324, '5e-324'];
        yield 'money' => [1199.99, '1199.99'];
    }

    #[DataProvider('numbers')]
    public function testFloatsEncodeLikeJavaScript(float $value, string $expected): void
    {
        self::assertSame($expected, Scalar::float($value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function strings(): iterable
    {
        yield 'quote and backslash' => ["a\"b\\c", '"a\"b\\\\c"'];
        yield 'short escapes' => ["\x08\t\n\x0c\r", '"\b\t\n\f\r"'];
        yield 'other controls' => ["\x00\x01\x1f", '"\u0000\u0001\u001f"'];
        yield 'slash verbatim' => ['a/b', '"a/b"'];
        yield 'delete verbatim' => ["\x7f", "\"\x7f\""];
        yield 'line separators verbatim' => ["\u{2028}\u{2029}", "\"\u{2028}\u{2029}\""];
        yield 'astral verbatim' => ["\u{1F680}", "\"\u{1F680}\""];
    }

    #[DataProvider('strings')]
    public function testStringsEncodeLikeJavaScript(string $value, string $expected): void
    {
        self::assertSame($expected, Scalar::string($value));
    }

    public function testUnsafeIntegersAreRefused(): void
    {
        self::assertSame('9007199254740991', Scalar::integer(9007199254740991));
        $this->expectExceptionObject(new CanonicalException('CANONICAL_INTEGER_UNSAFE'));
        Scalar::integer(9007199254740992);
    }

    public function testNonFiniteNumbersAreRefused(): void
    {
        $this->expectExceptionObject(new CanonicalException('CANONICAL_NUMBER_NOT_FINITE'));
        Scalar::float(NAN);
    }

    public function testInvalidUtf8IsRefused(): void
    {
        $this->expectExceptionObject(new CanonicalException('CANONICAL_STRING_NOT_UTF8'));
        Scalar::string("\xC3\x28");
    }

    public function testObjectsThatAreNotJsonAreRefused(): void
    {
        $this->expectExceptionObject(new CanonicalException('CANONICAL_VALUE_NOT_JSON'));
        (new Jcs())->encode(['when' => new \DateTimeImmutable()]);
    }
}

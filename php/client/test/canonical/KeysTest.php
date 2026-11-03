<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Canonical;

use Decionis\Client\Canonical\Jcs;
use Decionis\Client\Canonical\Keys;
use Decionis\Client\Canonical\SortedJson;
use PHPUnit\Framework\TestCase;

final class KeysTest extends TestCase
{
    public function testUtf16OrderPutsAstralCharactersBeforeThePrivateUseArea(): void
    {
        // U+1F680 is the surrogate pair D83D DE80, which sorts below U+E000 in UTF-16
        // although its UTF-8 bytes sort above.
        self::assertSame(['a', "\u{1F680}", "\u{E000}"], Keys::utf16(["\u{E000}", "\u{1F680}", 'a']));
    }

    public function testJavaScriptOrderPutsArrayIndicesFirst(): void
    {
        self::assertSame(['0', '2', '10', '4294967294', '01', '4294967295', 'a'], Keys::javascript(['a', '10', '01', '4294967295', '2', '4294967294', '0']));
    }

    public function testJcsDoesNotMoveIndexKeys(): void
    {
        $value = ['10' => 1, '9' => 2, 'a' => 3];
        self::assertSame('{"10":1,"9":2,"a":3}', (new Jcs())->encode($value));
        self::assertSame('{"9":2,"10":1,"a":3}', (new SortedJson())->encode($value));
    }

    public function testEmptyObjectsNeedStdClass(): void
    {
        self::assertSame('{"a":[],"b":{}}', (new SortedJson())->encode(['b' => new \stdClass(), 'a' => []]));
    }
}

<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Decision;

use Decionis\Client\Decision\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerdictTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?Verdict}> */
    public static function outcomes(): iterable
    {
        yield 'APPROVE' => ['APPROVE', Verdict::Allow];
        yield 'REJECT' => ['REJECT', Verdict::Block];
        yield 'REVIEW' => ['REVIEW', Verdict::Escalate];
        yield 'ESCALATE' => ['ESCALATE', Verdict::Escalate];
        yield 'ALLOW' => ['ALLOW', Verdict::Allow];
        yield 'BLOCK' => ['BLOCK', Verdict::Block];
        yield 'lower case' => ['approve', null];
        yield 'padded' => [' APPROVE', null];
        yield 'MCP vocabulary' => ['PROCEED', null];
        yield 'HOLD' => ['HOLD', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'boolean' => [true, null];
        yield 'list' => [['APPROVE'], null];
    }

    #[DataProvider('outcomes')]
    public function testOutcomesNormalizeOrMeanNoDecision(mixed $outcome, ?Verdict $expected): void
    {
        self::assertSame($expected, Verdict::normalize($outcome));
    }
}

<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Intent;

use Decionis\Client\Intent\Hasher;
use Decionis\Client\Test\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every AgentSafe agent-safe.intent/1 vector, base and mutations, reproduces byte for byte, and
 * every mutation hashes differently from the base and from every other.
 */
final class ConformanceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function vectors(): iterable
    {
        // The whole corpus: the canonical vector, the canonicalization vectors and the bindings
        // captured from framework tool-call records.
        foreach (['intent', 'frameworks'] as $set) {
            foreach (glob(Fixtures::path("agentsafe/$set/*.json")) ?: [] as $file) {
                yield $set . '/' . basename($file, '.json') => [$file];
            }
        }
    }

    #[DataProvider('vectors')]
    public function testVectorReproducesExactly(string $file): void
    {
        $vector = Fixtures::object($file);
        $hasher = new Hasher();
        $hashes = [];
        $cases = [$vector, ...($vector->mutations ?? [])];
        foreach ($cases as $case) {
            self::assertSame($case->canonical_json, $hasher->canonical($case->binding));
            self::assertSame($case->intent_hash, $hasher->hash($case->binding));
            $hashes[] = $case->intent_hash;
        }
        self::assertSame($hashes, array_values(array_unique($hashes)), 'mutations must not collide');
    }

    public function testCanonicalVectorIsThePinnedHash(): void
    {
        $vector = Fixtures::object(Fixtures::path('agentsafe/intent/AgentSafeIntentV1.json'));
        self::assertSame('sha256:aff2fd3aa1a7557a77f20afa5583f56eb0c39529304b31f69ee78e1e7b53e316', (new Hasher())->hash($vector->binding));
    }

    public function testHashComparisonIsStrict(): void
    {
        $hash = 'sha256:aff2fd3aa1a7557a77f20afa5583f56eb0c39529304b31f69ee78e1e7b53e316';
        self::assertTrue(Hasher::matches($hash, $hash));
        self::assertFalse(Hasher::matches($hash, strtoupper($hash)));
        self::assertFalse(Hasher::matches('not-a-hash', 'not-a-hash'));
    }
}

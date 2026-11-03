<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Decision;

use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Verdict;
use Decionis\Client\Error\ContractException;
use Decionis\Client\Test\Support\Contract;
use Decionis\Client\Test\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Recorded Commerce Gate responses (synthetic demo authority, 2026-10-04) against the contract. */
final class DecisionTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function recorded(string $name): array
    {
        /** @var array<string, mixed> */
        return Fixtures::at(Fixtures::array(Fixtures::path(Fixtures::EVIDENCE . $name . '.json')), 'response');
    }

    /** @return iterable<string, array{string}> */
    public static function responses(): iterable
    {
        foreach (['Permitted', 'BelowCost', 'MissingContext'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('responses')]
    public function testRecordedResponseMatchesTheContractAndParses(string $name): void
    {
        $response = self::recorded($name);
        // The recordings carry a commerce-preflight-v1 echo from before the contract moved to v2;
        // this client never asks for a preflight, so the echo is not part of its round trip.
        unset($response['commerce_preflight']);
        if (is_array($response['policy_evaluation'] ?? null)) {
            unset($response['policy_evaluation']['commerce_preflight']);
        }
        self::assertNull(Contract::check($response, 'CommerceEvaluationResponse'));
        $decision = Decision::fromResponse($response);
        self::assertSame(Verdict::normalize($response['outcome']), $decision->verdict);
        self::assertSame($response['dossier_id'], $decision->dossierId);
    }

    public function testPermittedIsAllow(): void
    {
        self::assertSame(Verdict::Allow, Decision::fromResponse(self::recorded('Permitted'))->verdict);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function malformed(): iterable
    {
        yield 'not shadow' => [static fn (array $r): array => ['mode' => 'ENFORCEMENT'] + $r];
        yield 'unknown outcome' => [static fn (array $r): array => ['outcome' => 'PROCEED'] + $r];
        yield 'confidence out of range' => [static fn (array $r): array => ['confidence' => 1.5] + $r];
        yield 'dossier id not a uuid' => [static fn (array $r): array => ['dossier_id' => 'x'] + $r];
        yield 'missing metrics' => [static function (array $r): array {
            unset($r['governance_metrics']);
            return $r;
        }];
        yield 'reason codes not a list' => [static fn (array $r): array => ['reason_codes' => 'MARGIN'] + $r];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('malformed')]
    public function testMalformedResponseIsNoDecision(callable $mutate): void
    {
        $this->expectException(ContractException::class);
        Decision::fromResponse($mutate(self::recorded('Permitted')));
    }

    public function testPinnedPolicyMismatchIsNoDecision(): void
    {
        $this->expectExceptionObject(new ContractException('EVALUATION_POLICY_VERSION_MISMATCH'));
        Decision::fromResponse(self::recorded('Permitted'), 'another-policy-v9');
    }
}

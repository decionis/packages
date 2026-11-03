<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Grant;

use Decionis\Client\Config;
use Decionis\Client\Decision\Verdict;
use Decionis\Client\Error\ContractException;
use Decionis\Client\Grant\Authority;
use Decionis\Client\Grant\Grant;
use Decionis\Client\Http\Transport;
use Decionis\Client\Intent\Intent;
use Decionis\Client\Test\Support\FakeClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/** The AgentSafe authority path: grants only for an exact, unexpired ALLOW, claimed once. */
final class AuthorityTest extends TestCase
{
    private const ORG = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
    private const JTI = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';

    private static function intent(float $amount = 1200.0): Intent
    {
        return new Intent(self::ORG, '11111111-1111-4111-8111-111111111111', '2026-11-02T10:00:00.000Z', '2026-11-03T10:00:00.000Z',
            ['id' => 'drupal:user:abc', 'type' => 'HUMAN'], 'commerce.refund', 'drupal:commerce_payment:7',
            ['order_id' => '3', 'amount' => $amount, 'currency' => 'EUR'], ['idempotency_key' => 'drupal:refund:7:0:1200', 'source' => 'drupal'],
            ['system' => 'drupal_commerce', 'operation' => 'refund']);
    }

    private static function token(): string
    {
        $part = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        return $part(['alg' => 'EdDSA']) . '.' . $part(['jti' => self::JTI]) . '.' . str_repeat('s', 43);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function allow(Intent $intent, array $overrides = []): array
    {
        return $overrides + ['decision_id' => 'dec-1', 'chain_id' => null, 'status' => 'ALLOW', 'should_execute' => true, 'reason_codes' => [],
            'action_hash' => $intent->hash(), 'mode' => 'ENFORCEMENT', 'execution_token' => self::token(),
            'execution_token_expires_at' => '2026-11-02T10:05:00.000Z', 'dossier_id' => 'dos-1', 'dossier_url' => null];
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-02T10:01:00Z');
    }

    public function testAnExactUnexpiredAllowIsAGrant(): void
    {
        $intent = self::intent();
        $grant = Grant::fromResponse(self::allow($intent), $intent, self::now());
        self::assertTrue($grant->executable());
        self::assertSame(self::JTI, $grant->jti());
    }

    public function testAnAllowWithoutAUsableTokenFailsClosed(): void
    {
        $intent = self::intent();
        foreach ([['execution_token' => null], ['should_execute' => false], ['mode' => 'SHADOW'], ['dossier_id' => null],
            ['execution_token_expires_at' => '2026-11-02T10:00:30.000Z'], ['execution_token_expires_at' => '2026-11-04T10:00:00.000Z']] as $change) {
            $grant = Grant::fromResponse(self::allow($intent, $change), $intent, self::now());
            self::assertFalse($grant->executable(), json_encode($change) ?: '');
            self::assertSame(Verdict::Block, $grant->verdict);
            self::assertSame(['AUTHORITY_GRANT_MISSING'], $grant->reasonCodes);
        }
    }

    public function testAGrantForAnotherIntentIsRefused(): void
    {
        $this->expectExceptionObject(new ContractException('GRANT_BINDING_MISMATCH'));
        Grant::fromResponse(self::allow(self::intent(40.0)), self::intent(), self::now());
    }

    public function testEscalationsCarryTheirPresenceLink(): void
    {
        $intent = self::intent();
        $grant = Grant::fromResponse(self::allow($intent, ['status' => 'ESCALATE', 'should_execute' => false, 'execution_token' => null,
            'managed_escalation' => ['escalation_id' => 'esc-1'], 'verification' => ['verification_page_url' => 'https://presence.example/r/1']]), $intent, self::now());
        self::assertSame(Verdict::Escalate, $grant->verdict);
        self::assertSame('esc-1', $grant->escalationId);
        self::assertSame('https://presence.example/r/1', $grant->approvalUrl);
    }

    public function testDecideClaimAndFinalize(): void
    {
        $intent = self::intent();
        $claim = ['valid' => true, 'claim_token' => str_repeat('c', 43), 'claims' => ['iss' => 'decionis', 'org_id' => self::ORG, 'dossier_id' => 'dos-1',
            'decision_id' => 'dec-1', 'action' => 'commerce.refund', 'decision' => 'allow', 'scope' => 'execute', 'binding' => ['intent_hash' => $intent->hash()]]];
        $fake = new FakeClient([FakeClient::json(200, self::allow($intent)), FakeClient::json(200, $claim), FakeClient::json(200, ['status' => 'recorded'])]);
        $factory = new Psr17Factory();
        $authority = new Authority(new Config('dcy_test_key_value', self::ORG), new Transport($fake, $factory, $factory, 'test', static function (int $ms): void {
        }));
        $grant = $authority->decide($intent, 'ENFORCEMENT', null, null, self::now());
        $claimed = $authority->claim($grant, $intent);
        $authority->finalize($grant, $claimed, $intent, 'COMMITTED');

        [$decide, $claimRequest, $receipt] = $fake->requests;
        self::assertSame('https://api.decionis.com/v1/authority/grants', (string) $decide->getUri());
        self::assertSame($intent->intentId, $decide->getHeaderLine('Idempotency-Key'));
        $body = json_decode((string) $decide->getBody(), true);
        self::assertIsArray($body);
        self::assertSame($intent->hash(), $body['intent_hash']);
        self::assertSame('ENFORCEMENT', $body['mode']);
        self::assertStringEndsWith('/v1/authority/grants/' . self::JTI . '/claims', (string) $claimRequest->getUri());
        self::assertStringEndsWith('/v1/authority/grants/' . self::JTI . '/receipts', (string) $receipt->getUri());
    }

    public function testAClaimBoundToAnythingElseIsRefused(): void
    {
        $intent = self::intent();
        $grant = Grant::fromResponse(self::allow($intent), $intent, self::now());
        $base = ['valid' => true, 'claim_token' => str_repeat('c', 43), 'claims' => ['iss' => 'decionis', 'org_id' => self::ORG, 'dossier_id' => 'dos-1',
            'decision_id' => 'dec-1', 'action' => 'commerce.refund', 'decision' => 'allow', 'scope' => 'execute', 'binding' => ['intent_hash' => $intent->hash()]]];
        foreach ([['valid' => false], ['claims' => ['binding' => ['intent_hash' => 'sha256:' . str_repeat('0', 64)]] + $base['claims']], ['claims' => ['org_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'] + $base['claims']], ['claim_token' => 'short']] as $change) {
            try {
                \Decionis\Client\Grant\Claim::fromResponse($change + $base, $intent, $grant);
                self::fail('expected a refused claim');
            } catch (ContractException $refused) {
                self::assertSame('GRANT_CLAIM_REFUSED', $refused->getMessage());
            }
        }
    }
}

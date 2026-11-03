<?php

declare(strict_types=1);

namespace Decionis\Client\Test;

use Decionis\Client\Client;
use Decionis\Client\Config;
use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Request;
use Decionis\Client\Decision\Verdict;
use Decionis\Client\Error\ContractException;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Decionis\Client\Test\Support\Contract;
use Decionis\Client\Test\Support\FakeClient;
use Decionis\Client\Test\Support\Fixtures;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/** A commerce evaluation round trip against the recorded contract response, and the evidence reads. */
final class ClientTest extends TestCase
{
    private const ORG = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
    private const KEY = 'dcy_aaaaaaaa_synthetic-test-credential';

    private static function client(FakeClient $fake): Client
    {
        $factory = new Psr17Factory();
        return new Client(new Config(self::KEY, self::ORG), new Transport($fake, $factory, $factory, 'test', static function (int $ms): void {
        }));
    }

    /** @return array<mixed> */
    private static function evidence(string $name): array
    {
        return Fixtures::array(Fixtures::path(Fixtures::EVIDENCE . $name . '.json'));
    }

    public function testEvaluationRoundTrip(): void
    {
        $fake = new FakeClient([FakeClient::json(200, Fixtures::at(self::evidence('Permitted'), 'response'))]);
        $action = Action::price(['type' => 'HUMAN', 'id' => 'drupal:user:1'], 'drupal', 'drupal:price:SKU-1:3', 'SKU-1', 100.0, 80.0, 50.0, 'EUR');
        $decision = self::client($fake)->evaluate(new Request($action, 'drupal-decionis'));

        self::assertSame(Verdict::Allow, $decision->verdict);
        self::assertCount(1, $fake->requests);
        $sent = $fake->requests[0];
        self::assertSame('https://api.decionis.com/v1/protocol/evaluate-decision', (string) $sent->getUri());
        self::assertSame('Bearer ' . self::KEY, $sent->getHeaderLine('Authorization'));
        self::assertSame('drupal:price:SKU-1:3', $sent->getHeaderLine('Idempotency-Key'));
        $body = json_decode((string) $sent->getBody(), true);
        self::assertIsArray($body);
        self::assertNull(Contract::check($body, 'CommerceEvaluationRequest'));
        self::assertSame(self::ORG, $body['org_id']);
    }

    public function testRetriedEvaluationKeepsItsIdempotencyKey(): void
    {
        $fake = new FakeClient([FakeClient::json(503, []), FakeClient::json(200, Fixtures::at(self::evidence('Permitted'), 'response'))]);
        $action = Action::refund(['type' => 'HUMAN', 'id' => 'u'], 'drupal', 'drupal:refund:7:1', '7', 40.0);
        self::client($fake)->evaluate(new Request($action, 'drupal-decionis'), new Retry(2, 1, 1, 5000));
        self::assertSame(['drupal:refund:7:1', 'drupal:refund:7:1'], array_map(static fn ($r) => $r->getHeaderLine('Idempotency-Key'), $fake->requests));
    }

    public function testUnparseableResponseIsNoDecision(): void
    {
        $fake = new FakeClient([FakeClient::json(200, 'not json')]);
        $this->expectExceptionObject(new ContractException('EVALUATION_RESPONSE_MALFORMED'));
        self::client($fake)->evaluate(new Request(Action::refund(['type' => 'HUMAN', 'id' => 'u'], 'drupal', 'k', '7', 4.0), 'drupal'));
    }

    public function testDossierReadVerifiesAgainstThePublishedKeys(): void
    {
        $evidence = self::evidence('Permitted');
        $response = Fixtures::at($evidence, 'response');
        $dossierId = $response['dossier_id'];
        self::assertIsString($dossierId);
        $record = ['service' => 'decionis', 'protocol_version' => '1.1', 'dossier' => [
            'dossier_id' => $dossierId,
            'org_id' => self::ORG,
            'decision_evaluation_id' => $response['evaluation_id'],
            'dossier_payload' => $evidence['dossier'],
            'evidence_hashes' => [],
            'created_at' => '2026-10-04T10:00:00.000Z',
            'updated_at' => '2026-10-04T10:00:00.000Z',
        ]];
        $jwks = file_get_contents(Fixtures::path(Fixtures::EVIDENCE . 'PublicVerificationKeys.json'));
        $fake = new FakeClient([FakeClient::json(200, $record), FakeClient::json(200, $jwks)]);
        $client = self::client($fake);

        $dossier = $client->dossier($dossierId);
        $verification = $client->verify($dossier);

        self::assertTrue($verification->verified, $verification->summary());
        self::assertStringContainsString('org_id=' . self::ORG, (string) $fake->requests[0]->getUri());
        self::assertSame(Config::DEFAULT_JWKS_URL, (string) $fake->requests[1]->getUri());
        self::assertSame('', $fake->requests[1]->getHeaderLine('Authorization'), 'the public key set is fetched without credentials');
    }

    public function testShadowReportAndProofPacketReads(): void
    {
        $report = ['service' => 'decionis', 'generated_at' => '2026-11-02T00:00:00Z', 'org_id' => self::ORG, 'window_days' => 90, 'since' => '2026-08-04T00:00:00Z',
            'summary' => ['total_evaluations' => 12, 'would_block' => 3, 'would_escalate' => 2], 'cards' => [], 'reason_breakdown' => [['reason_code' => 'MARGIN_BELOW_FLOOR', 'count' => 3]],
            'candidate_enforcement_paths' => [], 'near_misses' => [], 'recent_evaluations' => []];
        $packet = ['service' => 'decionis', 'protocol_version' => '1.1', 'packet_type' => 'decionis.decision_dossier.proof_packet', 'packet_version' => '1.0',
            'issued_at' => '2026-11-02T00:00:00Z', 'subject' => ['org_id' => self::ORG, 'dossier_id' => '18962a2d-6842-4f6a-9ff4-901c50acaed8'],
            'ledger_anchor' => ['chain_verification' => ['valid' => true]], 'verification' => ['overall' => 'VERIFIED'], 'verify_instructions' => ['key_id' => 'k1']];
        $fake = new FakeClient([FakeClient::json(200, $report), FakeClient::json(200, $packet)]);
        $client = self::client($fake);

        $shadow = $client->shadowReport(90, 50);
        self::assertSame(3, $shadow->count('would_block'));
        self::assertSame('MARGIN_BELOW_FLOOR', $shadow->reasons[0]['reason_code']);
        self::assertStringContainsString('days=90', (string) $fake->requests[0]->getUri());

        $proof = $client->proofPacket('18962A2D-6842-4F6A-9FF4-901C50ACAED8');
        self::assertSame('VERIFIED', $proof->overall);
        self::assertTrue($proof->chainValid);
        self::assertStringEndsWith('/18962a2d-6842-4f6a-9ff4-901c50acaed8/proof-packet?org_id=' . self::ORG, (string) $fake->requests[1]->getUri());
    }

    public function testCredentialNeverPrints(): void
    {
        $config = new Config(self::KEY, self::ORG);
        self::assertStringNotContainsString(self::KEY, print_r($config, true));
        ob_start();
        var_dump($config);
        self::assertStringNotContainsString(self::KEY, (string) ob_get_clean());
        $this->expectException(\LogicException::class);
        serialize($config);
    }

    public function testOnlyHttpsOrLoopbackOrigins(): void
    {
        self::assertSame('http://127.0.0.1:8080', (new Config(self::KEY, null, 'http://127.0.0.1:8080/'))->baseUrl);
        $this->expectExceptionMessage('CONFIG_URL_INVALID');
        new Config(self::KEY, null, 'http://api.decionis.com');
    }
}

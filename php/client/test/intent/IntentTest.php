<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Intent;

use Decionis\Client\Intent\Intent;
use Decionis\Client\Test\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class IntentTest extends TestCase
{
    private static function vectorIntent(): Intent
    {
        return new Intent(
            '00000000-0000-4000-8000-000000000002',
            '11111111-1111-4111-8111-111111111111',
            '2026-08-14T10:00:00.000Z',
            '2026-08-14T10:01:00.000Z',
            ['id' => 'synthetic-refund-agent', 'type' => 'AI_AGENT', 'runtime' => 'mcp'],
            'refund_order',
            'shopify:order:synthetic-1',
            ['amountMinor' => 35000, 'currency' => 'USD', 'orderId' => 'synthetic-1'],
            ['source' => 'conformance', 'idempotency_key' => 'refund-synthetic-1001-v1'],
            ['system' => 'shopify', 'operation' => 'refund', 'endpoint' => 'POST /refunds'],
        );
    }

    public function testBuiltIntentHashesToTheConformanceVector(): void
    {
        $vector = Fixtures::object(Fixtures::path('agentsafe/intent/AgentSafeIntentV1.json'));
        self::assertSame($vector->intent_hash, self::vectorIntent()->hash());
    }

    public function testExpiryIsInsideTheIntent(): void
    {
        $intent = self::vectorIntent();
        self::assertFalse($intent->expired(new \DateTimeImmutable('2026-08-14T10:00:59Z')));
        self::assertTrue($intent->expired(new \DateTimeImmutable('2026-08-14T10:01:00Z')));
    }

    public function testEmptyParametersStayAnObject(): void
    {
        $intent = new Intent(Intent::uuid(), Intent::uuid(), '2026-08-14T10:00:00.000Z', '2026-08-14T10:01:00.000Z', ['id' => 'a', 'type' => 'SERVICE'], 'order.place', 'drupal:order:1', [], ['idempotency_key' => 'k'], ['system' => 'drupal', 'operation' => 'place']);
        self::assertStringContainsString('"parameters":{}', (new \Decionis\Client\Intent\Hasher())->canonical($intent));
    }

    public function testMalformedIntentsAreRefused(): void
    {
        $this->expectExceptionMessage('INTENT_ACTION_INVALID');
        new Intent(Intent::uuid(), Intent::uuid(), '2026-08-14T10:00:00.000Z', '2026-08-14T10:01:00.000Z', ['id' => 'a', 'type' => 'SERVICE'], 'Refund Order', 'r', [], ['idempotency_key' => 'k'], ['system' => 's', 'operation' => 'o']);
    }

    public function testExpiryMustFollowCapture(): void
    {
        $this->expectExceptionMessage('INTENT_EXPIRY_NOT_AFTER_CAPTURE');
        new Intent(Intent::uuid(), Intent::uuid(), '2026-08-14T10:00:00.000Z', '2026-08-14T10:00:00.000Z', ['id' => 'a', 'type' => 'SERVICE'], 'order.place', 'r', [], ['idempotency_key' => 'k'], ['system' => 's', 'operation' => 'o']);
    }

    public function testInstantsAreUtcMilliseconds(): void
    {
        self::assertSame('2026-08-14T08:00:00.000Z', Intent::instant(new \DateTimeImmutable('2026-08-14T10:00:00+02:00')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Intent::uuid());
    }
}

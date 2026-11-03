<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Decision;

use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Request;
use Decionis\Client\Test\Support\Contract;
use Decionis\Client\Test\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private const ORG = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
    private const ACTOR = ['type' => 'HUMAN', 'id' => 'drupal:user:7f3a'];

    /** @return iterable<string, array{Action}> */
    public static function actions(): iterable
    {
        yield 'refund' => [Action::refund(self::ACTOR, 'drupal', 'drupal:refund:42:1', '42', 1200.0, 'EUR', 'customer_claim', 1500.0, 2)];
        yield 'price' => [Action::price(self::ACTOR, 'drupal', 'drupal:price:SKU-2:1', 'SKU-2', 19.99, 7.5, 9.0, 'EUR')];
        yield 'promotion' => [Action::promotion(self::ACTOR, 'drupal', 'drupal:promotion:3:1', '3', 0.45, null, 'active', ['order_discounts' => true, 'product_discounts' => true, 'shipping_discounts' => false])];
        yield 'order' => [Action::order(['type' => 'WORKFLOW', 'id' => 'drupal-checkout'], 'drupal', 'drupal:order:1001:place', '1001', 100.0, 20.0, 75.0, 'EUR')];
        yield 'stock' => [Action::inventory(['type' => 'HUMAN', 'id' => 'u1'], 'drupal', 'drupal:stock:SKU-1:9', 'SKU-1', 40, 0, 'main')];
        yield 'fulfillment' => [Action::fulfillment(['type' => 'WORKFLOW', 'id' => 'w'], 'drupal', 'drupal:fulfil:1:ship', '1', 'ship')];
        yield 'return' => [Action::returnAuthorization(['type' => 'HUMAN', 'id' => 'u'], 'drupal', 'drupal:return:1:1', '1', 'rma-1', 30.0)];
    }

    #[DataProvider('actions')]
    public function testRequestMatchesTheContract(Action $action): void
    {
        $body = (new Request($action, 'drupal-decionis', 'workflow'))->toArray(self::ORG);
        self::assertNull(Contract::check($body, 'CommerceEvaluationRequest'));
        self::assertSame('SHADOW', $body['mode']);
        self::assertSame($action->idempotencyKey, $body['idempotency_key']);
        self::assertSame($action->toArray(), Fixtures::at($body, 'context', 'commerce_action'));
    }

    public function testOrderFactsAreMarginsInPercentagePoints(): void
    {
        $order = Action::order(['type' => 'WORKFLOW', 'id' => 'c'], 'drupal', 'k1', '1', 100.0, 20.0, 75.0);
        $context = Fixtures::at((new Request($order, 'drupal'))->toArray(null), 'context');
        self::assertSame(80.0, $context['net_revenue']);
        self::assertSame(5.0, $context['net_margin_amount']);
        self::assertSame(6.25, $context['net_margin_percent']);
        self::assertSame(6.25, Fixtures::at($context, 'signals')['net_margin_percent']);
    }

    public function testMissingCostLeavesMarginUnknown(): void
    {
        $price = Action::price(['type' => 'HUMAN', 'id' => 'u'], 'drupal', 'k1', 'SKU-9', 10.0, 12.0, null);
        self::assertNull(Fixtures::at((new Request($price, 'drupal'))->toArray(null), 'context')['net_margin_percent']);
    }

    public function testRefundAboveBalanceIsAFact(): void
    {
        $refund = Action::refund(self::ACTOR, 'drupal', 'k2', '9', 60.0, 'EUR', null, 50.0);
        $context = Fixtures::at((new Request($refund, 'drupal'))->toArray(null), 'context');
        self::assertTrue($context['refund_exceeds_refundable']);
        self::assertSame(60.0, (new Request($refund, 'drupal'))->toArray(null)['amount']);
    }

    public function testAbuseSignalsTravelAsFacts(): void
    {
        $refund = Action::refund(self::ACTOR, 'drupal', 'k3', '9', 10.0);
        $context = Fixtures::at((new Request($refund, 'drupal', 'api', null, ['refunds_per_customer_90d' => 4]))->toArray(self::ORG), 'context');
        self::assertSame(4, Fixtures::at($context, 'signals')['refunds_per_customer_90d']);
    }

    public function testPinnedPolicyIsExact(): void
    {
        $body = (new Request(Action::refund(self::ACTOR, 'drupal', 'k4', '9', 10.0), 'drupal', 'api', 'drupal-commerce-refund-ceiling-v1'))->toArray(self::ORG);
        self::assertTrue($body['require_exact_policy_version']);
        self::assertNull(Contract::check($body, 'CommerceEvaluationRequest'));
    }

    public function testInvalidActionsAreRefused(): void
    {
        $this->expectExceptionMessage('ACTION_AMOUNT_INVALID');
        Action::order(['type' => 'WORKFLOW', 'id' => 'c'], 'drupal', 'k', '1', 10.0, 20.0, 5.0);
    }

    public function testCurrenciesAreIsoCodes(): void
    {
        $this->expectExceptionMessage('ACTION_CURRENCY_INVALID');
        Action::refund(self::ACTOR, 'drupal', 'k5', '9', 10.0, 'eur');
    }

    public function testIdempotencyKeysFollowTheContractPattern(): void
    {
        $this->expectExceptionMessage('ACTION_IDEMPOTENCY_KEY_INVALID');
        Action::refund(self::ACTOR, 'drupal', ' spaced key', '9', 10.0);
    }
}

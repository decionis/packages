<?php

declare(strict_types=1);

namespace Decionis\Client\Decision;

/**
 * A canonical commerce action: what is about to happen, in the Commerce Gate vocabulary, with the
 * facts a policy reads derived as the Commerce Gate MCP derives them. It holds bounded commerce
 * facts only (amounts, SKUs, cost base, counts), never customer PII.
 */
final class Action
{
    public const ACTORS = ['HUMAN', 'ERP', 'REPRICER', 'WORKFLOW', 'AGENT', 'UNKNOWN'];

    private const KEY = '/^[a-z0-9][\w.:\/-]{0,179}$/i';

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $derived
     */
    private function __construct(
        public readonly Type $type,
        public readonly string $actorType,
        public readonly string $actorId,
        public readonly string $platform,
        public readonly string $idempotencyKey,
        public readonly array $payload,
        public readonly ?float $amount,
        public readonly array $derived,
    ) {
        self::require(in_array($actorType, self::ACTORS, true), 'ACTION_ACTOR_TYPE_INVALID');
        self::require($actorId !== '' && strlen($actorId) <= 200, 'ACTION_ACTOR_ID_INVALID');
        self::require($platform !== '' && strlen($platform) <= 120, 'ACTION_PLATFORM_INVALID');
        self::require(preg_match(self::KEY, $idempotencyKey) === 1, 'ACTION_IDEMPOTENCY_KEY_INVALID');
        $currency = $payload['currency'] ?? null;
        self::require($currency === null || (is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1), 'ACTION_CURRENCY_INVALID');
    }

    /** @param array{type: string, id: string} $actor */
    public static function refund(array $actor, string $platform, string $key, string $orderId, float $amount, ?string $currency = null, ?string $reasonCode = null, ?float $remainingRefundable = null, ?int $priorRefundCount = null): self
    {
        self::require($amount >= 0 && ($remainingRefundable === null || $remainingRefundable >= 0), 'ACTION_AMOUNT_INVALID');
        $derived = ['refund_amount' => $amount];
        if ($remainingRefundable !== null) {
            $derived['refund_exceeds_refundable'] = $amount > $remainingRefundable + 0.00001;
        }
        return new self(Type::RefundRequest, $actor['type'], $actor['id'], $platform, $key, self::compact([
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'reason_code' => $reasonCode,
            'remaining_refundable' => $remainingRefundable,
            'prior_refund_count' => $priorRefundCount,
        ], ['order_id', 'amount']), $amount, $derived);
    }

    /** @param array{type: string, id: string} $actor */
    public static function price(array $actor, string $platform, string $key, string $sku, ?float $fromPrice, float $toPrice, ?float $estimatedCost = null, ?string $currency = null): self
    {
        self::require($toPrice >= 0, 'ACTION_AMOUNT_INVALID');
        $derived = ['price_change_amount' => $fromPrice === null ? null : Facts::round($toPrice - $fromPrice)]
            + Facts::margin($toPrice, $estimatedCost);
        return new self(Type::PriceChange, $actor['type'], $actor['id'], $platform, $key, self::compact([
            'sku' => $sku,
            'from_price' => $fromPrice,
            'to_price' => $toPrice,
            'estimated_cost' => $estimatedCost,
            'currency' => $currency,
        ], ['sku', 'from_price', 'to_price', 'estimated_cost']), $toPrice, $derived);
    }

    /**
     * @param array{type: string, id: string} $actor
     * @param array{order_discounts: bool, product_discounts: bool, shipping_discounts: bool}|null $combinesWith
     */
    public static function promotion(array $actor, string $platform, string $key, ?string $promotionId, ?float $percentageFraction, ?string $endsAt, ?string $status, ?array $combinesWith): self
    {
        self::require($percentageFraction === null || ($percentageFraction >= 0 && $percentageFraction <= 1), 'ACTION_PERCENTAGE_INVALID');
        $derived = [
            'discount_percent' => $percentageFraction === null ? null : Facts::round($percentageFraction * 100),
            'combines_with_order_discounts' => $combinesWith['order_discounts'] ?? null,
            'combines_with_product_discounts' => $combinesWith['product_discounts'] ?? null,
            'combines_with_shipping_discounts' => $combinesWith['shipping_discounts'] ?? null,
        ];
        return new self(Type::PromotionChange, $actor['type'], $actor['id'], $platform, $key, [
            'promotion_id' => $promotionId,
            'percentage_fraction' => $percentageFraction,
            'ends_at' => $endsAt,
            'status' => $status,
            'combines_with' => $combinesWith,
        ], null, $derived);
    }

    /**
     * The contract needs a known cost base: without one an order's margin is not covered, and the
     * caller logs it as such instead of evaluating.
     *
     * @param array{type: string, id: string} $actor
     */
    public static function order(array $actor, string $platform, string $key, ?string $orderId, float $grossAmount, float $discountAmount, float $estimatedCost, ?string $currency = null): self
    {
        self::require($grossAmount >= 0 && $discountAmount >= 0 && $discountAmount <= $grossAmount && $estimatedCost >= 0, 'ACTION_AMOUNT_INVALID');
        return new self(Type::OrderAcceptance, $actor['type'], $actor['id'], $platform, $key, self::compact([
            'order_id' => $orderId,
            'gross_amount' => $grossAmount,
            'discount_amount' => $discountAmount,
            'estimated_cost' => $estimatedCost,
            'currency' => $currency,
        ], ['order_id', 'gross_amount', 'discount_amount', 'estimated_cost']), $grossAmount, Facts::margin($grossAmount - $discountAmount, $estimatedCost));
    }

    /**
     * Stock levels are whole, non-negative units. A write that would take stock below zero is
     * expressed as `to` 0 with the shortfall as an extra fact (see Request::$extra).
     *
     * @param array{type: string, id: string} $actor
     */
    public static function inventory(array $actor, string $platform, string $key, string $sku, ?int $from, int $to, ?string $locationId = null): self
    {
        self::require($to >= 0 && ($from === null || $from >= 0), 'ACTION_AMOUNT_INVALID');
        return new self(Type::InventoryMutation, $actor['type'], $actor['id'], $platform, $key, self::compact([
            'sku' => $sku,
            'from' => $from,
            'to' => $to,
            'location_id' => $locationId,
        ], ['sku', 'from', 'to']), null, ['current_inventory' => $from, 'proposed_inventory' => $to, 'available_inventory' => $to]);
    }

    /** @param array{type: string, id: string} $actor */
    public static function fulfillment(array $actor, string $platform, string $key, string $orderId, string $action): self
    {
        self::require(in_array($action, ['acknowledge', 'ship', 'cancel', 'hold'], true), 'ACTION_FULFILLMENT_INVALID');
        return new self(Type::FulfillmentAction, $actor['type'], $actor['id'], $platform, $key, ['order_id' => $orderId, 'action' => $action], null, ['fulfillment_action' => $action]);
    }

    /** @param array{type: string, id: string} $actor */
    public static function returnAuthorization(array $actor, string $platform, string $key, string $orderId, ?string $rmaId = null, ?float $amount = null): self
    {
        return new self(Type::ReturnAuthorization, $actor['type'], $actor['id'], $platform, $key, self::compact([
            'order_id' => $orderId,
            'rma_id' => $rmaId,
            'amount' => $amount,
        ], ['order_id']), $amount, ['return_amount' => $amount]);
    }

    /**
     * The facts a policy reads under `context.signals`: the payload with the derived facts.
     *
     * @return array<string, mixed>
     */
    public function signals(): array
    {
        return array_merge($this->payload, $this->derived);
    }

    /** @return array{action_type: string, actor: array{type: string, id: string}, platform: string, idempotency_key: string, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'action_type' => $this->type->value,
            'actor' => ['type' => $this->actorType, 'id' => $this->actorId],
            'platform' => $this->platform,
            'idempotency_key' => $this->idempotencyKey,
            'payload' => $this->payload,
        ];
    }

    /**
     * Drops optional fields that are null, keeping the ones the contract requires even when null.
     *
     * @param array<string, mixed> $fields
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function compact(array $fields, array $required): array
    {
        return array_filter($fields, static fn (mixed $value, string $key): bool =>
            $value !== null || in_array($key, $required, true), ARRAY_FILTER_USE_BOTH);
    }

    private static function require(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($code);
        }
    }
}

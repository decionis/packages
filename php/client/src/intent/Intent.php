<?php

declare(strict_types=1);

namespace Decionis\Client\Intent;

/**
 * An agent-safe.intent/1 binding: the exact action, captured before it runs, that a grant is
 * bound to. Every field is inside the hash, so a grant for one intent authorises no other.
 */
final class Intent
{
    public const PROTOCOL = 'agent-safe.intent/1';

    private const UUID = '/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}'
        . '|00000000-0000-0000-0000-000000000000|ffffffff-ffff-ffff-ffff-ffffffffffff)$/';
    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';
    private const ACTION_TYPE = '/^[a-z][a-z0-9._:-]*$/';

    private readonly string $key;

    /**
     * @param array<string, string> $actor id and type, optionally runtime and trust_level
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context must include idempotency_key
     * @param array<string, string> $target system and operation, optionally environment and endpoint
     * @param array{decision_type: string, transaction_type: ?string, workflow_key: ?string, channel: ?string}|null $projection
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $intentId,
        public readonly string $capturedAt,
        public readonly string $expiresAt,
        public readonly array $actor,
        public readonly string $actionType,
        public readonly string $resource,
        public readonly array $parameters,
        public readonly array $context,
        public readonly array $target,
        public readonly ?array $projection = null,
    ) {
        $key = $context['idempotency_key'] ?? null;
        self::check(is_string($key) && $key !== '' && strlen($key) <= 180, 'INTENT_IDEMPOTENCY_KEY_INVALID');
        $this->key = is_string($key) ? $key : '';
        self::check(preg_match(self::UUID, $tenantId) === 1, 'INTENT_TENANT_INVALID');
        self::check(preg_match(self::UUID, $intentId) === 1, 'INTENT_ID_INVALID');
        self::check(preg_match(self::INSTANT, $capturedAt) === 1, 'INTENT_CAPTURED_AT_INVALID');
        self::check(preg_match(self::INSTANT, $expiresAt) === 1, 'INTENT_EXPIRES_AT_INVALID');
        self::check($expiresAt > $capturedAt, 'INTENT_EXPIRY_NOT_AFTER_CAPTURE');
        self::check(self::bounded($actor['id'] ?? null, 200) && self::bounded($actor['type'] ?? null, 200), 'INTENT_ACTOR_INVALID');
        self::check(strlen($actionType) <= 120 && preg_match(self::ACTION_TYPE, $actionType) === 1, 'INTENT_ACTION_INVALID');
        self::check(strlen($resource) <= 500, 'INTENT_RESOURCE_INVALID');
        self::check(self::bounded($target['system'] ?? null, 200) && self::bounded($target['operation'] ?? null, 200), 'INTENT_TARGET_INVALID');
    }

    public function idempotencyKey(): string
    {
        return $this->key;
    }

    /** True once the intent's own expiry has passed: a stale intent is refused, never run. */
    public function expired(\DateTimeInterface $now): bool
    {
        $expiry = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $this->expiresAt, new \DateTimeZone('UTC'));
        return $expiry === false || $now >= $expiry;
    }

    /**
     * The binding as JSON-ready data, with empty objects kept as objects.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $binding = [
            'protocol_version' => self::PROTOCOL,
            'tenant_id' => $this->tenantId,
            'intent_id' => $this->intentId,
            'captured_at' => $this->capturedAt,
            'expires_at' => $this->expiresAt,
            'actor' => $this->actor,
            'action' => [
                'type' => $this->actionType,
                'resource' => $this->resource,
                'parameters' => $this->parameters === [] ? new \stdClass() : $this->parameters,
            ],
            'context' => $this->context,
            'downstream_target' => $this->target,
        ];
        if ($this->projection !== null) {
            $binding['policy_projection'] = $this->projection;
        }
        return $binding;
    }

    public function hash(): string
    {
        return (new Hasher())->hash($this);
    }

    /** Formats an instant the way the intent fields require: UTC, milliseconds, `Z`. */
    public static function instant(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /** A random version 4 UUID for intent_id. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function bounded(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max;
    }

    private static function check(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($code);
        }
    }
}

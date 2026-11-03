<?php

declare(strict_types=1);

namespace Decionis\Client\Decision;

/**
 * A CommerceEvaluationRequest for POST /v1/protocol/evaluate-decision. The public contract
 * evaluates in SHADOW; a gate in ENFORCE acts on the returned verdict itself.
 */
final class Request
{
    public const CHANNELS = ['mcp', 'repricer', 'erp', 'workflow', 'api'];

    /** @param array<string, mixed> $extra additional context facts (abuse signals, coverage notes) */
    public function __construct(
        public readonly Action $action,
        public readonly string $source,
        public readonly string $channel = 'api',
        public readonly ?string $policyVersion = null,
        public readonly array $extra = [],
    ) {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('REQUEST_CHANNEL_INVALID');
        }
        if ($source === '' || strlen($source) > 120) {
            throw new \InvalidArgumentException('REQUEST_SOURCE_INVALID');
        }
        if ($policyVersion !== null && ($policyVersion === '' || strlen($policyVersion) > 120)) {
            throw new \InvalidArgumentException('REQUEST_POLICY_VERSION_INVALID');
        }
    }

    public function idempotencyKey(): string
    {
        return $this->action->idempotencyKey;
    }

    /** @return array<string, mixed> */
    public function toArray(?string $orgId): array
    {
        $type = $this->action->type;
        $action = $this->action->toArray();
        $body = [
            'org_id' => $orgId,
            'decision_type' => $type->value,
            'transaction_type' => $type->transactionType(),
            'workflow_key' => $type->workflowKey(),
        ];
        if ($this->action->amount !== null) {
            $body['amount'] = $this->action->amount;
        }
        $body += [
            'channel' => $this->channel,
            'source' => $this->source,
            'mode' => 'SHADOW',
            'idempotency_key' => $this->action->idempotencyKey,
        ];
        if ($this->policyVersion !== null) {
            $body['policy_version'] = $this->policyVersion;
            $body['require_exact_policy_version'] = true;
        }
        $context = [
            'action_type' => $type->value,
            'actor_type' => $action['actor']['type'],
            'actor_id' => $action['actor']['id'],
            'actor' => $action['actor'],
            'platform' => $action['platform'],
            'payload' => $action['payload'],
        ];
        $context = array_merge($context, $action['payload'], $this->action->derived, $this->extra);
        $context['signals'] = array_merge([
            'action_type' => $type->value,
            'actor_type' => $action['actor']['type'],
            'actor_id' => $action['actor']['id'],
            'platform' => $action['platform'],
        ], $this->action->signals(), $this->extra);
        $context['commerce_action'] = $action;
        $body['context'] = $context;
        return $body;
    }
}

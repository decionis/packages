<?php

declare(strict_types=1);

namespace Decionis\Client\Grant;

use Decionis\Client\Config;
use Decionis\Client\Error\ContractException;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Decionis\Client\Intent\Hasher;
use Decionis\Client\Intent\Intent;

/**
 * The AgentSafe execution authority: decide an intent (issuing a single-use grant on ALLOW in
 * enforcement), follow a managed escalation, claim the grant immediately before running the
 * action, and record what happened. A claimed grant cannot be presented again.
 */
final class Authority
{
    public function __construct(private readonly Config $config, private readonly Transport $transport)
    {
    }

    /**
     * @param array<string, mixed>|null $evidence humanApproval evidence after an approval
     * @param array<string, mixed>|null $escalation a managed escalation request
     */
    public function decide(Intent $intent, string $mode = 'ENFORCEMENT', ?array $evidence = null, ?array $escalation = null, ?\DateTimeInterface $now = null): Grant
    {
        $body = $intent->toArray() + ['intent_hash' => $intent->hash(), 'mode' => $mode === 'SHADOW' ? 'SHADOW' : 'ENFORCEMENT'];
        if ($evidence !== null) {
            $body['evidence'] = $evidence;
        }
        if ($escalation !== null) {
            $body['escalation'] = $escalation;
        }
        $text = $this->post('/v1/authority/grants', $body, $intent->intentId);
        return Grant::fromResponse(self::decode($text), $intent, $now ?? new \DateTimeImmutable());
    }

    /** The managed escalation's current decision: a grant once it is GRANT_READY. */
    public function escalation(string $escalationId, Intent $intent, ?\DateTimeInterface $now = null): ?Grant
    {
        if (preg_match('/^[\w.:-]{1,200}$/', $escalationId) !== 1) {
            throw new \InvalidArgumentException('ESCALATION_ID_INVALID');
        }
        $status = self::decode($this->transport->send('GET', $this->config->baseUrl . '/v1/authority/escalations/' . rawurlencode($escalationId), [
            'Authorization' => $this->config->authorization(),
        ], null, new Retry(2, 200, 1000, 10000)));
        if (!is_array($status) || ($status['status'] ?? null) !== 'GRANT_READY' || !is_array($status['decision'] ?? null)) {
            return null;
        }
        return Grant::fromResponse($status['decision'], $intent, $now ?? new \DateTimeImmutable());
    }

    /** Claims the grant once, immediately before the action runs. */
    public function claim(Grant $grant, Intent $intent): Claim
    {
        if (!$grant->executable()) {
            throw new \LogicException('GRANT_NOT_EXECUTABLE');
        }
        $jti = $grant->jti();
        $path = $jti === null ? '/v1/execution/claim-token' : '/v1/authority/grants/' . $jti . '/claims';
        $text = $this->post($path, [
            'execution_token' => $grant->token,
            'intent_hash' => $intent->hash(),
            'intent' => $intent->toArray(),
            'consumed_by' => (string) $intent->actor['id'],
            'commit_correlation_id' => $intent->intentId,
        ], null);
        return Claim::fromResponse(self::decode($text), $intent, $grant);
    }

    /** Records the attempt: COMMITTED, FAILED or INDETERMINATE. */
    public function finalize(Grant $grant, Claim $claim, Intent $intent, string $outcome): void
    {
        if (!in_array($outcome, ['COMMITTED', 'FAILED', 'INDETERMINATE'], true)) {
            throw new \InvalidArgumentException('OUTCOME_INVALID');
        }
        $jti = $grant->jti();
        $this->post($jti === null ? '/v1/execution/finalize-token' : '/v1/authority/grants/' . $jti . '/receipts', [
            'execution_token' => $grant->token,
            'claim_token' => $claim->claimToken,
            'outcome' => $outcome,
            'commit_correlation_id' => $intent->intentId,
        ], null);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?string $idempotencyKey): string
    {
        $headers = ['Authorization' => $this->config->authorization()];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('REQUEST_NOT_ENCODABLE');
        }
        return $this->transport->send('POST', $this->config->baseUrl . $path, $headers, $json, new Retry(2, 100, 1000, 5000));
    }

    private static function decode(string $text): mixed
    {
        try {
            return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ContractException('GRANT_RESPONSE_MALFORMED');
        }
    }

    /** True when a stored hash still describes the action as it stands now. */
    public static function unchanged(string $storedHash, Intent $current): bool
    {
        return Hasher::matches($storedHash, $current->hash());
    }
}

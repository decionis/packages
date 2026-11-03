<?php

declare(strict_types=1);

namespace Decionis\Client\Grant;

use Decionis\Client\Decision\Verdict;
use Decionis\Client\Error\ContractException;
use Decionis\Client\Intent\Intent;

/**
 * The authority's decision on an agent-safe.intent/1, as AgentSafe's gate reads it. Only an ALLOW
 * in enforcement that carries a token, bound to this intent and expiring no later than it, is a
 * grant; an ALLOW without one fails closed as no grant. ESCALATE carries the managed escalation
 * to follow (its id and the Presence page).
 */
final class Grant
{
    /** @param list<string> $reasonCodes */
    private function __construct(
        public readonly Verdict $verdict,
        public readonly string $decisionId,
        public readonly ?string $dossierId,
        public readonly string $intentHash,
        public readonly array $reasonCodes,
        public readonly ?string $token,
        public readonly ?string $expiresAt,
        public readonly ?string $escalationId,
        public readonly ?string $approvalUrl,
    ) {
    }

    /** True when the grant may be claimed and the action run once. */
    public function executable(): bool
    {
        return $this->verdict === Verdict::Allow && $this->token !== null;
    }

    /** @param mixed $response the decoded body of POST /v1/authority/grants */
    public static function fromResponse(mixed $response, Intent $intent, \DateTimeInterface $now): self
    {
        if (!is_array($response) || !is_string($response['decision_id'] ?? null) || ($response['action_hash'] ?? null) !== $intent->hash()) {
            throw new ContractException('GRANT_BINDING_MISMATCH');
        }
        $status = $response['status'] ?? null;
        $verdict = match ($status) {
            'ALLOW' => Verdict::Allow,
            'ESCALATE', 'REVIEW_REQUIRED' => Verdict::Escalate,
            'BLOCK', 'ERROR' => Verdict::Block,
            default => throw new ContractException('GRANT_RESPONSE_MALFORMED'),
        };
        $token = is_string($response['execution_token'] ?? null) ? $response['execution_token'] : null;
        $expires = is_string($response['execution_token_expires_at'] ?? null) ? $response['execution_token_expires_at'] : null;
        $dossier = is_string($response['dossier_id'] ?? null) ? $response['dossier_id'] : null;
        $executable = $verdict === Verdict::Allow
            && ($response['should_execute'] ?? false) === true
            && ($response['mode'] ?? null) === 'ENFORCEMENT'
            && $token !== null && $token !== '' && strlen($token) <= 20000
            && $dossier !== null
            && $expires !== null && self::time($expires) > $now->getTimestamp()
            && self::time($expires) <= self::time($intent->expiresAt);
        if ($verdict === Verdict::Allow && !$executable) {
            return new self(Verdict::Block, $response['decision_id'], $dossier, $intent->hash(), ['AUTHORITY_GRANT_MISSING'], null, null, null, null);
        }
        $escalation = is_array($response['managed_escalation'] ?? null) ? $response['managed_escalation'] : [];
        $verification = is_array($response['verification'] ?? null) ? $response['verification'] : [];
        $url = $verification['verification_page_url'] ?? null;
        return new self(
            $verdict,
            $response['decision_id'],
            $dossier,
            $intent->hash(),
            array_values(array_filter(is_array($response['reason_codes'] ?? null) ? $response['reason_codes'] : [], 'is_string')),
            $executable ? $token : null,
            $executable ? $expires : null,
            is_string($escalation['escalation_id'] ?? null) ? $escalation['escalation_id'] : null,
            is_string($url) && str_starts_with($url, 'https://') ? $url : null,
        );
    }

    /** The grant id the claim resource is addressed by: the token's `jti` when it is a JWT. */
    public function jti(): ?string
    {
        $parts = explode('.', (string) $this->token);
        if (count($parts) !== 3) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $jti = is_array($claims) ? ($claims['jti'] ?? null) : null;
        return is_string($jti) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jti) === 1 ? $jti : null;
    }

    private static function time(string $instant): int
    {
        $parsed = strtotime($instant);
        return $parsed === false ? 0 : $parsed;
    }
}

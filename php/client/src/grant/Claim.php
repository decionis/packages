<?php

declare(strict_types=1);

namespace Decionis\Client\Grant;

use Decionis\Client\Error\ContractException;
use Decionis\Client\Intent\Intent;

/**
 * A claimed grant: the authority consumed it for this intent, once. Every bound field is checked
 * against the intent and the decision before the action may run.
 */
final class Claim
{
    private function __construct(public readonly string $claimToken)
    {
    }

    /** @param mixed $response the decoded body of POST /v1/authority/grants/{jti}/claims */
    public static function fromResponse(mixed $response, Intent $intent, Grant $grant): self
    {
        $claims = is_array($response) ? ($response['claims'] ?? null) : null;
        $token = is_array($response) ? ($response['claim_token'] ?? null) : null;
        $valid = is_array($response) && ($response['valid'] ?? null) === true
            && is_array($claims)
            && is_array($claims['binding'] ?? null)
            && ($claims['binding']['intent_hash'] ?? null) === $intent->hash()
            && ($claims['decision_id'] ?? null) === $grant->decisionId
            && ($claims['dossier_id'] ?? null) === $grant->dossierId
            && ($claims['org_id'] ?? null) === $intent->tenantId
            && ($claims['decision'] ?? null) === 'allow'
            && ($claims['scope'] ?? null) === 'execute'
            && is_string($token) && preg_match('/^[\w-]{43,128}$/', $token) === 1;
        if (!$valid) {
            throw new ContractException('GRANT_CLAIM_REFUSED');
        }
        return new self($token);
    }
}

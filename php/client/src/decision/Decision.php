<?php

declare(strict_types=1);

namespace Decionis\Client\Decision;

use Decionis\Client\Error\ContractException;

/**
 * A validated CommerceEvaluationResponse. A response that is incomplete or malformed never
 * becomes a Decision: it raises ContractException, and the caller's failure policy applies.
 */
final class Decision
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const OUTCOMES = ['APPROVE', 'REJECT', 'REVIEW', 'ESCALATE'];
    private const METRICS = ['override_drift_rate', 'governance_tension_index', 'boundary_volatility_score', 'decision_volume_under_governance'];

    /** @param list<string> $reasonCodes */
    private function __construct(
        public readonly string $outcome,
        public readonly Verdict $verdict,
        public readonly array $reasonCodes,
        public readonly string $policyVersion,
        public readonly string $dossierId,
        public readonly ?string $dossierSha256,
        public readonly string $evaluationId,
        public readonly float $confidence,
        public readonly bool $replay,
    ) {
    }

    /** @param mixed $response the decoded JSON body */
    public static function fromResponse(mixed $response, ?string $policyVersion = null): self
    {
        if (!is_array($response) || ($response['mode'] ?? null) !== 'SHADOW') {
            throw new ContractException('EVALUATION_MODE_UNCONFIRMED');
        }
        $outcome = $response['outcome'] ?? null;
        $confidence = $response['confidence'] ?? null;
        $metrics = $response['governance_metrics'] ?? null;
        $policy = $response['policy_version'] ?? null;
        $dossierId = $response['dossier_id'] ?? null;
        $evaluationId = $response['evaluation_id'] ?? null;
        $replay = $response['idempotent_replay'] ?? null;
        $reasons = self::reasons($response['reason_codes'] ?? []);
        $valid = is_string($outcome) && in_array($outcome, self::OUTCOMES, true)
            && (is_int($confidence) || is_float($confidence)) && $confidence >= 0 && $confidence <= 1
            && is_string($policy) && $policy !== '' && strlen($policy) <= 120
            && is_string($response['objective_profile'] ?? null)
            && is_string($dossierId) && preg_match(self::UUID, $dossierId) === 1
            && is_string($evaluationId) && preg_match(self::UUID, $evaluationId) === 1
            && is_bool($response['fallback_to_legacy'] ?? null)
            && is_bool($replay)
            && is_array($metrics) && self::metrics($metrics)
            && $reasons !== null;
        if (!$valid) {
            throw new ContractException('EVALUATION_RESPONSE_MALFORMED');
        }
        if ($policyVersion !== null && $policy !== $policyVersion) {
            throw new ContractException('EVALUATION_POLICY_VERSION_MISMATCH');
        }
        $verdict = Verdict::normalize($outcome);
        if ($verdict === null) {
            throw new ContractException('EVALUATION_RESPONSE_MALFORMED');
        }
        $sha = $response['dossier_sha256'] ?? null;
        return new self(
            $outcome,
            $verdict,
            $reasons,
            $policy,
            strtolower($dossierId),
            is_string($sha) && preg_match('/^[0-9a-f]{64}$/', $sha) === 1 ? $sha : null,
            strtolower($evaluationId),
            (float) $confidence,
            $replay,
        );
    }

    /** @return list<string>|null the reason codes, or null when they are not a list of bounded strings */
    private static function reasons(mixed $codes): ?array
    {
        if (!is_array($codes) || !array_is_list($codes)) {
            return null;
        }
        $reasons = [];
        foreach ($codes as $code) {
            if (!is_string($code) || $code === '' || strlen($code) > 500) {
                return null;
            }
            $reasons[] = $code;
        }
        return $reasons;
    }

    /** @param array<mixed> $metrics */
    private static function metrics(array $metrics): bool
    {
        foreach (self::METRICS as $metric) {
            $value = $metrics[$metric] ?? null;
            if (!(is_int($value) || is_float($value)) || !is_finite((float) $value)) {
                return false;
            }
        }
        return true;
    }
}

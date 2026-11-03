<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

use Decionis\Client\Canonical\Jcs;

/**
 * Whether the verified artifacts cover what the dossier claims. A Protocol 1.1 dossier must sign
 * its portable artifact and inputs snapshot, commit to the snapshot's digest, and bind any
 * execution binding it carries; an execution-eligible dossier must carry one.
 */
final class Coverage
{
    /**
     * @param array<string, true> $verifiedPaths
     * @return string|null why coverage fails, or null when it holds
     */
    public function check(Bundle $bundle, \stdClass $payload, array $verifiedPaths): ?string
    {
        $machine = self::object(self::object($payload->portable_artifact ?? null)->machine_readable ?? null);
        $protocol11 = ($machine->protocol_version ?? null) === '1.1';
        $portable = isset($verifiedPaths['/portable_artifact']);
        $inputs = property_exists($payload, 'inputs_snapshot') && isset($verifiedPaths['/inputs_snapshot']);
        $bindingPresented = property_exists($payload, 'execution_binding');
        $binding = $bindingPresented && isset($verifiedPaths['/execution_binding'])
            && $this->signedWithJcs($bundle);

        if (!$portable) {
            // Without a signed portable artifact the protocol version itself is unsigned.
            return 'SIGNED_ARTIFACT_COVERAGE_INCOMPLETE';
        }
        if (!$protocol11) {
            return $this->legacy($machine, $bindingPresented, $binding, $bundle);
        }
        if (!$inputs) {
            return 'SIGNED_ARTIFACT_COVERAGE_INCOMPLETE';
        }
        if ($bindingPresented && (!$binding || $bundle->version !== '2.1')) {
            return 'EXECUTION_BINDING_MISMATCH';
        }
        if (Verifier::jcsDigest($payload->inputs_snapshot) !== ($machine->input_snapshot_digest ?? null)) {
            return 'INPUT_SNAPSHOT_DIGEST_MISMATCH';
        }
        $evaluationId = $machine->evaluation_id ?? null;
        foreach ([$payload->evaluation_id ?? null, self::object($payload->routing_decision ?? null)->evaluation_id ?? null] as $projected) {
            if ($projected !== null && $projected !== $evaluationId) {
                return 'AUTHORITY_PROJECTION_MISMATCH';
            }
        }
        if (($payload->dossier_id ?? null) !== null && $payload->dossier_id !== ($machine->dossier_id ?? null)) {
            return 'AUTHORITY_PROJECTION_MISMATCH';
        }
        return $bindingPresented
            ? $this->binding($payload->execution_binding, $machine)
            : (($machine->execution_binding_digest ?? null) !== null || ($machine->execution_eligible ?? false) === true
                ? 'EXECUTION_BINDING_MISMATCH'
                : null);
    }

    private function legacy(\stdClass $machine, bool $presented, bool $binding, Bundle $bundle): ?string
    {
        if (((property_exists($machine, 'mode') && $machine->mode !== 'ENFORCEMENT')
            || (property_exists($machine, 'authority_classification') && $machine->authority_classification !== 'AUTHORITATIVE'))) {
            return 'OBSERVATIONAL_DOSSIER_NOT_EXECUTABLE';
        }
        if (property_exists($machine, 'protocol_version') && !in_array($machine->protocol_version, ['1.0', '1.0.0'], true)) {
            return 'UNSUPPORTED_PROTOCOL_VERSION';
        }
        if ($presented && !($binding && $bundle->version === '2.1')) {
            return 'EXECUTION_BINDING_MISMATCH';
        }
        return null;
    }

    private function binding(mixed $binding, \stdClass $machine): ?string
    {
        $policy = self::object(self::object($binding)->policy ?? null);
        $reference = self::object($machine->policy_reference ?? null);
        $matches = $binding instanceof \stdClass
            && Verifier::jcsDigest($binding) === ($machine->execution_binding_digest ?? null)
            && ($binding->dossier_id ?? null) === ($machine->dossier_id ?? null)
            && ($binding->evaluation_id ?? null) === ($machine->evaluation_id ?? null)
            && ($policy->identifier ?? null) === ($reference->revision_id ?? null)
            && ($policy->version ?? null) === ($reference->version ?? null)
            && ($policy->digest ?? null) === ($reference->digest ?? null);
        return $matches ? null : 'EXECUTION_BINDING_MISMATCH';
    }

    private function signedWithJcs(Bundle $bundle): bool
    {
        foreach ($bundle->artifacts as $artifact) {
            if ($artifact->kind === 'execution_binding') {
                return $artifact->profile === Jcs::PROFILE;
            }
        }
        return false;
    }

    private static function object(mixed $value): \stdClass
    {
        return $value instanceof \stdClass ? $value : new \stdClass();
    }
}

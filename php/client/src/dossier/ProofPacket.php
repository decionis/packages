<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

use Decionis\Client\Error\ContractException;

/**
 * A DecisionProofPacket: the dossier with its policy snapshot, ledger anchor and the server's
 * own verification. The server's `overall` is reported, never trusted in place of verifying the
 * dossier offline.
 */
final class ProofPacket
{
    private function __construct(
        public readonly string $dossierId,
        public readonly string $orgId,
        public readonly string $issuedAt,
        public readonly string $overall,
        public readonly ?string $keyId,
        public readonly bool $chainValid,
        public readonly \stdClass $document,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $packet = json_decode($json, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ContractException('PROOF_PACKET_MALFORMED');
        }
        if (!$packet instanceof \stdClass) {
            throw new ContractException('PROOF_PACKET_MALFORMED');
        }
        $subject = $packet->subject ?? null;
        $overall = $packet->verification->overall ?? null;
        if (($packet->packet_type ?? null) !== 'decionis.decision_dossier.proof_packet'
            || ($packet->packet_version ?? null) !== '1.0'
            || !$subject instanceof \stdClass
            || !is_string($subject->dossier_id ?? null) || !is_string($subject->org_id ?? null)
            || !is_string($packet->issued_at ?? null)
            || !in_array($overall, ['VERIFIED', 'PARTIAL', 'UNVERIFIED'], true)) {
            throw new ContractException('PROOF_PACKET_MALFORMED');
        }
        $keyId = $packet->verify_instructions->key_id ?? null;
        return new self(
            $subject->dossier_id,
            $subject->org_id,
            $packet->issued_at,
            $overall,
            is_string($keyId) ? $keyId : null,
            ($packet->ledger_anchor->chain_verification->valid ?? false) === true,
            $packet,
        );
    }
}

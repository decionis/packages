<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

use Decionis\Client\Error\ContractException;

/** A DecisionDossierRecord as GET /v1/protocol/dossiers/{id} returns it. */
final class Record
{
    /** @param list<string> $evidenceHashes */
    private function __construct(
        public readonly string $dossierId,
        public readonly string $orgId,
        public readonly string $evaluationId,
        public readonly \stdClass $payload,
        public readonly array $evidenceHashes,
        public readonly string $createdAt,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $document = json_decode($json, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ContractException('DOSSIER_RESPONSE_MALFORMED');
        }
        $record = $document instanceof \stdClass ? ($document->dossier ?? null) : null;
        if (!$record instanceof \stdClass
            || !is_string($record->dossier_id ?? null)
            || !is_string($record->org_id ?? null)
            || !is_string($record->decision_evaluation_id ?? null)
            || !($record->dossier_payload ?? null) instanceof \stdClass
            || !is_array($record->evidence_hashes ?? null)
            || !is_string($record->created_at ?? null)) {
            throw new ContractException('DOSSIER_RESPONSE_MALFORMED');
        }
        $hashes = array_values(array_filter($record->evidence_hashes, static fn (mixed $hash): bool =>
            is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1));
        return new self(
            $record->dossier_id,
            $record->org_id,
            $record->decision_evaluation_id,
            $record->dossier_payload,
            $hashes,
            $record->created_at,
        );
    }

    /** The verdict the signed portable artifact states, if any. */
    public function verdict(): ?string
    {
        $verdict = $this->payload->portable_artifact->machine_readable->verdict ?? null;
        return is_string($verdict) ? $verdict : null;
    }
}

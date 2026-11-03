<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

use Decionis\Client\Canonical\Jcs;
use Decionis\Client\Canonical\SortedJson;

/**
 * A Decision Dossier proof bundle, parsed strictly: anything malformed is rejected as a whole
 * rather than partly trusted.
 */
final class Bundle
{
    /** Production rotation, and the static key the archived public corpus declares. */
    private const STRATEGIES = ['JWKS_OVERLAP', 'STATIC_PUBLIC_CORPUS_KEY'];

    /** @param list<Artifact> $artifacts */
    private function __construct(
        public readonly string $version,
        public readonly string $keyId,
        public readonly string $strategy,
        public readonly array $artifacts,
    ) {
    }

    public static function parse(mixed $bundle): ?self
    {
        if (!$bundle instanceof \stdClass) {
            return null;
        }
        $version = $bundle->version ?? null;
        $keyId = $bundle->key_id ?? null;
        $rotation = $bundle->rotation_policy ?? null;
        if (($bundle->bundle_type ?? null) !== 'decionis.decision_dossier.proof_bundle'
            || !in_array($version, ['2.0', '2.1'], true)
            || ($bundle->algorithm ?? null) !== 'Ed25519'
            || !is_string($keyId) || $keyId === '' || strlen($keyId) > 128
            || !$rotation instanceof \stdClass
            || !in_array($rotation->strategy ?? null, self::STRATEGIES, true)
            || ($rotation->active_key_id ?? null) !== $keyId) {
            return null;
        }
        $raw = $bundle->artifacts ?? null;
        if (!is_array($raw) || $raw === [] || count($raw) > count(Artifact::SHAPES)) {
            return null;
        }
        $artifacts = [];
        foreach ($raw as $item) {
            $artifact = self::artifact($item, $version);
            if ($artifact === null || isset($artifacts[$artifact->kind])) {
                return null;
            }
            $artifacts[$artifact->kind] = $artifact;
        }
        return new self($version, $keyId, $rotation->strategy, array_values($artifacts));
    }

    public function has(string $kind): bool
    {
        foreach ($this->artifacts as $artifact) {
            if ($artifact->kind === $kind) {
                return true;
            }
        }
        return false;
    }

    private static function artifact(mixed $item, string $version): ?Artifact
    {
        if (!$item instanceof \stdClass) {
            return null;
        }
        $kind = $item->artifact_kind ?? null;
        if (!is_string($kind) || !isset(Artifact::SHAPES[$kind])) {
            return null;
        }
        [$path, $media] = Artifact::SHAPES[$kind];
        $profile = property_exists($item, 'canonicalization_profile') ? $item->canonicalization_profile : null;
        $sha = $item->canonical_document_sha256 ?? null;
        $signature = $item->signature ?? null;
        $valid = ($item->document_path ?? null) === $path
            && ($item->media_type ?? null) === $media
            && is_string($sha) && preg_match('/^[0-9a-f]{64}$/', $sha) === 1
            && is_string($signature) && preg_match('/^[A-Za-z0-9_-]{86}$/', $signature) === 1
            && in_array($profile, [null, Jcs::PROFILE, SortedJson::PROFILE], true)
            && ($kind !== 'execution_binding' || ($profile === Jcs::PROFILE && $version === '2.1'));
        return $valid ? new Artifact($kind, $path, $sha, $signature, $profile) : null;
    }
}

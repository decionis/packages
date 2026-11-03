<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

use Decionis\Client\Canonical\CanonicalException;
use Decionis\Client\Canonical\Jcs;

/**
 * Verifies a Decision Dossier without calling Decionis: each signed artifact's canonical bytes
 * must match the bundle digest and an Ed25519 signature from the given key set (libsodium), and
 * a Protocol 1.1 dossier's signed commitments must match the documents it carries. The same
 * rules as `@decionis/verify`.
 */
final class Verifier
{
    public function __construct(private readonly Jwks $jwks)
    {
    }

    /** @param string $json a dossier payload, or a dossier record with a `dossier_payload` */
    public function verifyJson(string $json): Verification
    {
        try {
            $decoded = json_decode($json, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Verification::failed('dossier', 'The dossier is not valid JSON.');
        }
        if ($decoded instanceof \stdClass && ($decoded->dossier_payload ?? null) instanceof \stdClass) {
            $decoded = $decoded->dossier_payload;
        }
        return $decoded instanceof \stdClass
            ? $this->verify($decoded)
            : Verification::failed('dossier', 'The dossier is not a JSON object.');
    }

    public function verify(\stdClass $payload): Verification
    {
        $bundle = Bundle::parse($payload->integrity->proof_bundle ?? null);
        if ($bundle === null) {
            return Verification::failed('proof_bundle', 'No well-formed Ed25519 proof bundle was presented.');
        }
        $publicKey = $this->jwks->publicKey($bundle->keyId);
        if ($publicKey === null) {
            return Verification::failed('public_key', 'No Ed25519 key in the key set matched the proof bundle.');
        }

        $checks = [];
        $verifiedPaths = [];
        foreach ($bundle->artifacts as $artifact) {
            $result = $this->artifact($artifact, $payload, $publicKey);
            $checks[] = ['key' => $artifact->kind, 'verified' => $result === null, 'detail' => $result ?? 'Digest and signature verified.'];
            if ($result === null) {
                $verifiedPaths[$artifact->path] = true;
            }
        }
        // The portable artifact is the decision itself: a bundle that does not sign it proves
        // nothing about the outcome, however many other signatures hold.
        $verified = count($verifiedPaths) === count($bundle->artifacts) && isset($verifiedPaths['/portable_artifact']);
        $coverage = (new Coverage())->check($bundle, $payload, $verifiedPaths);
        $checks[] = ['key' => 'required_artifacts', 'verified' => $coverage === null, 'detail' => $coverage ?? 'Signed artifacts cover the dossier.'];

        return new Verification($verified, $verified && $coverage === null, $bundle->keyId, $bundle->version, count($bundle->artifacts), $checks);
    }

    /**
     * @param non-empty-string $publicKey
     * @return string|null why the artifact failed, or null when it verified
     */
    private function artifact(Artifact $artifact, \stdClass $payload, string $publicKey): ?string
    {
        $document = $artifact->document($payload);
        if ($document === null) {
            return 'The signed document is missing from the dossier.';
        }
        try {
            $canonical = $artifact->canonicalizer()->encode($document);
        } catch (CanonicalException) {
            return 'The signed document has no canonical form.';
        }
        if (!hash_equals($artifact->sha256, hash('sha256', $canonical))) {
            return 'The document digest does not match the proof bundle.';
        }
        $signature = sodium_base642bin($artifact->signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        if ($signature === '' || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'The Ed25519 signature is malformed.';
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)
                ? null
                : 'The Ed25519 signature does not verify against the key set.';
        } catch (\SodiumException) {
            return 'The Ed25519 signature is malformed.';
        }
    }

    /** `sha256:<hex>` of a value's RFC 8785 form, or null when it has none. */
    public static function jcsDigest(mixed $value): ?string
    {
        try {
            return 'sha256:' . hash('sha256', (new Jcs())->encode($value));
        } catch (CanonicalException) {
            return null;
        }
    }
}

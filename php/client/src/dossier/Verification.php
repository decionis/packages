<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

/**
 * The result of verifying a Dossier offline. `verified` is historical signature validity: every
 * signed artifact matches its digest and an Ed25519 signature from the key set. `complete` says
 * whether the signed artifacts also cover what the dossier claims (Protocol 1.1 commitments).
 */
final class Verification
{
    /**
     * @param list<array{key: string, verified: bool, detail: string}> $checks
     */
    public function __construct(
        public readonly bool $verified,
        public readonly bool $complete,
        public readonly ?string $keyId,
        public readonly ?string $bundleVersion,
        public readonly int $artifactsChecked,
        public readonly array $checks,
    ) {
    }

    public static function failed(string $key, string $detail): self
    {
        return new self(false, false, null, null, 0, [['key' => $key, 'verified' => false, 'detail' => $detail]]);
    }

    /** One line for logs and the decision log: never contains dossier content. */
    public function summary(): string
    {
        if (!$this->verified) {
            $failed = array_values(array_filter($this->checks, static fn (array $check): bool => !$check['verified']));
            return 'NOT VERIFIED: ' . ($failed[0]['key'] ?? 'unknown');
        }
        return sprintf(
            'VERIFIED with key %s (%d artifacts)%s',
            (string) $this->keyId,
            $this->artifactsChecked,
            $this->complete ? '' : ', coverage incomplete',
        );
    }
}

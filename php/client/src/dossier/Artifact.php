<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

use Decionis\Client\Canonical\Canonicalizer;
use Decionis\Client\Canonical\Jcs;
use Decionis\Client\Canonical\SortedJson;

/** One signed document in a proof bundle: where it lives in the dossier and how it was signed. */
final class Artifact
{
    /** Each artifact kind has exactly one document path and media type. */
    public const SHAPES = [
        'portable_artifact' => ['/portable_artifact', 'application/json'],
        'inputs_snapshot' => ['/inputs_snapshot', 'application/json'],
        'json_ld' => ['/linked_data/document', 'application/ld+json'],
        'execution_binding' => ['/execution_binding', 'application/json'],
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly string $sha256,
        public readonly string $signature,
        public readonly ?string $profile,
    ) {
    }

    public function canonicalizer(): Canonicalizer
    {
        return $this->profile === Jcs::PROFILE ? new Jcs() : new SortedJson();
    }

    /** Resolves the artifact's JSON pointer in a dossier decoded with objects kept as objects. */
    public function document(\stdClass $dossier): mixed
    {
        $current = $dossier;
        foreach (array_slice(explode('/', $this->path), 1) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!$current instanceof \stdClass || !property_exists($current, $segment)) {
                return null;
            }
            $current = $current->{$segment};
        }
        return $current;
    }
}

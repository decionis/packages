<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Dossier;

use Decionis\Client\Dossier\Jwks;
use Decionis\Client\Dossier\Verifier;
use Decionis\Client\Test\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Offline Dossier verification against the public corpus archived as DOI 10.5281/zenodo.22692903
 * (AgentSafe v0.1.4), the current Protocol 1.1 corpus, and this repository's recorded Commerce
 * Gate evidence. No network is used.
 */
final class VerifierTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function corpus(): iterable
    {
        foreach (['zenodo', 'current'] as $set) {
            foreach (glob(Fixtures::path("agentsafe/dossiers/$set/*.json")) ?: [] as $file) {
                if (!str_ends_with($file, 'CorpusJwks.json')) {
                    yield $set . '/' . basename($file, '.json') => [$set, $file];
                }
            }
        }
    }

    private static function verifier(string $set): Verifier
    {
        return new Verifier(Jwks::fromJson((string) file_get_contents(Fixtures::path("agentsafe/dossiers/$set/CorpusJwks.json"))));
    }

    #[DataProvider('corpus')]
    public function testCorpusDossierVerifiesOffline(string $set, string $file): void
    {
        $vector = Fixtures::object($file);
        $result = self::verifier($set)->verify($vector->dossier_payload);
        self::assertSame($vector->expected->verified, $result->verified, $result->summary());
        self::assertTrue($result->complete);
        self::assertSame($vector->expected->artifacts_checked, $result->artifactsChecked);
        self::assertSame($vector->expected->key_id, $result->keyId);
        self::assertSame($vector->expected->proof_bundle_version, $result->bundleVersion);
    }

    #[DataProvider('corpus')]
    public function testCorpusCanonicalBytesMatchTheVector(string $set, string $file): void
    {
        $vector = Fixtures::object($file);
        foreach ($vector->expected_artifacts as $expected) {
            $artifact = null;
            foreach ($vector->dossier_payload->integrity->proof_bundle->artifacts as $candidate) {
                if ($candidate->artifact_kind === $expected->artifact_kind) {
                    $artifact = $candidate;
                }
            }
            self::assertNotNull($artifact);
            $document = $vector->dossier_payload;
            foreach (array_slice(explode('/', $expected->document_path), 1) as $segment) {
                $document = $document->{$segment};
            }
            $encoder = ($artifact->canonicalization_profile ?? null) === 'RFC8785/JCS' ? new \Decionis\Client\Canonical\Jcs() : new \Decionis\Client\Canonical\SortedJson();
            self::assertSame($expected->canonical_json, $encoder->encode($document));
        }
    }

    public function testTamperedVerdictFails(): void
    {
        $vector = Fixtures::object(Fixtures::path('agentsafe/dossiers/zenodo/Block.json'));
        $vector->dossier_payload->portable_artifact->routing_decision->outcome = 'APPROVE';
        $result = self::verifier('zenodo')->verify($vector->dossier_payload);
        self::assertFalse($result->verified);
        self::assertStringStartsWith('NOT VERIFIED: portable_artifact', $result->summary());
    }

    public function testTamperedAmountInInputsFails(): void
    {
        $vector = Fixtures::object(Fixtures::path('agentsafe/dossiers/current/Allow.json'));
        $vector->dossier_payload->inputs_snapshot->amount = 4200000;
        self::assertFalse(self::verifier('current')->verify($vector->dossier_payload)->verified);
    }

    public function testSignatureFromAnotherDocumentFails(): void
    {
        $vector = Fixtures::object(Fixtures::path('agentsafe/dossiers/zenodo/Allow.json'));
        $artifacts = $vector->dossier_payload->integrity->proof_bundle->artifacts;
        [$artifacts[0]->signature, $artifacts[1]->signature] = [$artifacts[1]->signature, $artifacts[0]->signature];
        self::assertFalse(self::verifier('zenodo')->verify($vector->dossier_payload)->verified);
    }

    public function testAKeyOutsideTheKeySetFails(): void
    {
        $vector = Fixtures::object(Fixtures::path('agentsafe/dossiers/zenodo/Allow.json'));
        $other = Jwks::fromJson((string) file_get_contents(Fixtures::path(Fixtures::EVIDENCE . 'PublicVerificationKeys.json')));
        $result = (new Verifier($other))->verify($vector->dossier_payload);
        self::assertFalse($result->verified);
        self::assertSame('NOT VERIFIED: public_key', $result->summary());
    }

    public function testRemovedExecutionBindingIsIncomplete(): void
    {
        $vector = Fixtures::object(Fixtures::path('agentsafe/dossiers/current/Allow.json'));
        $payload = $vector->dossier_payload;
        unset($payload->execution_binding);
        $payload->integrity->proof_bundle->artifacts = array_values(array_filter(
            $payload->integrity->proof_bundle->artifacts,
            static fn (\stdClass $artifact): bool => $artifact->artifact_kind !== 'execution_binding',
        ));
        $result = self::verifier('current')->verify($payload);
        self::assertTrue($result->verified, 'the remaining signatures are still valid');
        self::assertFalse($result->complete, 'an execution-eligible dossier without its binding is incomplete');
    }

    /** @return iterable<string, array{string}> */
    public static function sets(): iterable
    {
        yield 'zenodo' => ['zenodo'];
        yield 'current' => ['current'];
    }

    #[DataProvider('sets')]
    public function testBundleWithoutTheSignedPortableArtifactIsNotVerified(string $set): void
    {
        $payload = Fixtures::object(Fixtures::path("agentsafe/dossiers/$set/Allow.json"))->dossier_payload;
        $payload->integrity->proof_bundle->artifacts = array_values(array_filter(
            $payload->integrity->proof_bundle->artifacts,
            static fn (\stdClass $artifact): bool => $artifact->artifact_kind !== 'portable_artifact',
        ));
        // The downgrade: with the decision unsigned, its protocol version is the attacker's.
        unset($payload->portable_artifact->machine_readable->protocol_version);
        $result = self::verifier($set)->verify($payload);
        self::assertFalse($result->verified, 'the remaining signatures do not prove the decision');
        self::assertFalse($result->complete);
    }

    public function testMalformedBundleIsRefusedAsAWhole(): void
    {
        $vector = Fixtures::object(Fixtures::path('agentsafe/dossiers/zenodo/Allow.json'));
        $vector->dossier_payload->integrity->proof_bundle->algorithm = 'RS256';
        self::assertSame('NOT VERIFIED: proof_bundle', self::verifier('zenodo')->verify($vector->dossier_payload)->summary());
    }

    /** @return iterable<string, array{string}> */
    public static function evidence(): iterable
    {
        foreach (['Permitted', 'BelowCost', 'MissingContext'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('evidence')]
    public function testRecordedCommerceGateDossierVerifies(string $name): void
    {
        $jwks = Jwks::fromJson((string) file_get_contents(Fixtures::path(Fixtures::EVIDENCE . 'PublicVerificationKeys.json')));
        $json = (string) json_encode(Fixtures::object(Fixtures::path(Fixtures::EVIDENCE . $name . '.json'))->dossier);
        $result = (new Verifier($jwks))->verifyJson($json);
        self::assertTrue($result->verified, $result->summary());
        self::assertTrue($result->complete);
    }

    public function testNotJsonIsNotVerified(): void
    {
        $jwks = Jwks::fromArray(['keys' => []]);
        self::assertFalse((new Verifier($jwks))->verifyJson('{')->verified);
        self::assertFalse((new Verifier($jwks))->verifyJson('[]')->verified);
    }
}

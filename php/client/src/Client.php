<?php

declare(strict_types=1);

namespace Decionis\Client;

use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Request;
use Decionis\Client\Dossier\Cache;
use Decionis\Client\Dossier\KeySource;
use Decionis\Client\Dossier\MemoryCache;
use Decionis\Client\Dossier\ProofPacket;
use Decionis\Client\Dossier\Record;
use Decionis\Client\Dossier\Verification;
use Decionis\Client\Dossier\Verifier;
use Decionis\Client\Error\ContractException;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Decionis\Client\Report\ShadowReport;

/**
 * The Decionis execution authority, as one client: evaluate a commerce decision, read its Dossier,
 * proof packet and Shadow report, and verify a Dossier offline against the published keys.
 */
final class Client
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private readonly KeySource $keys;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        Cache $cache = new MemoryCache(),
    ) {
        $this->keys = new KeySource($transport, $config->jwksUrl, $cache);
    }

    /**
     * Evaluates a commerce action. The default retry policy suits admin actions; pass
     * Retry::once(120) on the checkout path.
     */
    public function evaluate(Request $request, ?Retry $retry = null): Decision
    {
        try {
            $body = json_encode($request->toArray($this->config->orgId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('REQUEST_NOT_ENCODABLE');
        }
        $text = $this->transport->send('POST', $this->config->baseUrl . '/v1/protocol/evaluate-decision', [
            'Authorization' => $this->config->authorization(),
            'Idempotency-Key' => $request->idempotencyKey(),
        ], $body, $retry ?? new Retry(3, 100, 1000, 5000));
        return Decision::fromResponse(self::decode($text), $request->policyVersion);
    }

    public function dossier(string $dossierId): Record
    {
        return Record::fromJson($this->read('/v1/protocol/dossiers/' . self::id($dossierId), []));
    }

    public function proofPacket(string $dossierId): ProofPacket
    {
        return ProofPacket::fromJson($this->read('/v1/protocol/dossiers/' . self::id($dossierId) . '/proof-packet', []));
    }

    public function shadowReport(int $days = 30, int $limit = 20): ShadowReport
    {
        if ($days < 1 || $days > 365 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('SHADOW_REPORT_WINDOW_INVALID');
        }
        return ShadowReport::fromJson($this->read('/v1/protocol/shadow-reports', ['days' => $days, 'limit' => $limit]));
    }

    /** Verifies a dossier against the published key set, without asking Decionis about it. */
    public function verify(Record $record): Verification
    {
        $keyId = $record->payload->integrity->proof_bundle->key_id ?? null;
        return (new Verifier($this->keys->jwks(is_string($keyId) ? $keyId : null)))->verify($record->payload);
    }

    /** @param array<string, int|string> $query */
    private function read(string $path, array $query): string
    {
        if ($this->config->orgId === null) {
            throw new \LogicException('CONFIG_ORG_ID_REQUIRED');
        }
        $query = ['org_id' => $this->config->orgId] + $query;
        return $this->transport->send('GET', $this->config->baseUrl . $path . '?' . http_build_query($query), [
            'Authorization' => $this->config->authorization(),
        ], null, new Retry(3, 200, 2000, 15000));
    }

    private static function id(string $id): string
    {
        if (preg_match(self::UUID, $id) !== 1) {
            throw new \InvalidArgumentException('DOSSIER_ID_INVALID');
        }
        return strtolower($id);
    }

    private static function decode(string $text): mixed
    {
        try {
            return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ContractException('EVALUATION_RESPONSE_MALFORMED');
        }
    }
}

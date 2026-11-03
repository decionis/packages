<?php

declare(strict_types=1);

namespace Decionis\Client\Report;

use Decionis\Client\Error\ContractException;

/** A ShadowReportDocument from GET /v1/protocol/shadow-reports. */
final class ShadowReport
{
    /**
     * @param array<string, int|float> $summary
     * @param list<array{reason_code: string, count: int}> $reasons
     * @param list<array<mixed>> $paths candidate enforcement paths
     * @param list<array<mixed>> $recent recent evaluations
     */
    private function __construct(
        public readonly string $orgId,
        public readonly int $windowDays,
        public readonly string $since,
        public readonly string $generatedAt,
        public readonly array $summary,
        public readonly array $reasons,
        public readonly array $paths,
        public readonly array $recent,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $report = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ContractException('SHADOW_REPORT_MALFORMED');
        }
        if (!is_array($report)
            || ($report['service'] ?? null) !== 'decionis'
            || !is_string($report['org_id'] ?? null)
            || !is_int($report['window_days'] ?? null)
            || !is_string($report['since'] ?? null)
            || !is_string($report['generated_at'] ?? null)
            || !is_array($report['summary'] ?? null)
            || !is_array($report['reason_breakdown'] ?? null)
            || !is_array($report['candidate_enforcement_paths'] ?? null)
            || !is_array($report['recent_evaluations'] ?? null)) {
            throw new ContractException('SHADOW_REPORT_MALFORMED');
        }
        $summary = array_filter($report['summary'], static fn (mixed $value): bool => is_int($value) || is_float($value));
        $reasons = array_values(array_filter($report['reason_breakdown'], static fn (mixed $row): bool =>
            is_array($row) && is_string($row['reason_code'] ?? null) && is_int($row['count'] ?? null)));
        return new self(
            $report['org_id'],
            $report['window_days'],
            $report['since'],
            $report['generated_at'],
            $summary,
            $reasons,
            array_values(array_filter($report['candidate_enforcement_paths'], 'is_array')),
            array_values(array_filter($report['recent_evaluations'], 'is_array')),
        );
    }

    public function count(string $field): int
    {
        return (int) ($this->summary[$field] ?? 0);
    }
}

<?php

declare(strict_types=1);

namespace Decionis\Client\Http;

/**
 * Bounded retries with full jitter inside a hard deadline. A retry is only sent when it can still
 * finish inside the budget, so a caller's latency budget is never exceeded by waiting.
 */
final class Retry
{
    public function __construct(
        public readonly int $attempts = 3,
        public readonly int $baseMs = 100,
        public readonly int $capMs = 1000,
        public readonly int $deadlineMs = 15000,
    ) {
        if ($attempts < 1 || $attempts > 5 || $baseMs < 0 || $capMs < $baseMs || $deadlineMs < 1 || $deadlineMs > 60000) {
            throw new \InvalidArgumentException('RETRY_POLICY_INVALID');
        }
    }

    /** A single attempt with a short deadline, for checkout. */
    public static function once(int $deadlineMs): self
    {
        return new self(1, 0, 0, $deadlineMs);
    }

    /** Full-jitter delay before the given retry (1-based). */
    public function delayMs(int $retry): int
    {
        $ceiling = min($this->capMs, $this->baseMs * (2 ** ($retry - 1)));
        return $ceiling <= 0 ? 0 : random_int(0, $ceiling);
    }
}

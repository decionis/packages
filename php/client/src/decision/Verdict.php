<?php

declare(strict_types=1);

namespace Decionis\Client\Decision;

/**
 * The three verdicts a gate acts on. The AgentSafe path answers ALLOW / ESCALATE / BLOCK; the
 * commerce API answers APPROVE / REJECT / REVIEW / ESCALATE. Both normalize here, with REVIEW
 * treated as ESCALATE, as the MCP hooks do.
 */
enum Verdict: string
{
    case Allow = 'ALLOW';
    case Escalate = 'ESCALATE';
    case Block = 'BLOCK';

    private const ALIASES = [
        'ALLOW' => 'ALLOW',
        'APPROVE' => 'ALLOW',
        'ESCALATE' => 'ESCALATE',
        'REVIEW' => 'ESCALATE',
        'BLOCK' => 'BLOCK',
        'REJECT' => 'BLOCK',
    ];

    /**
     * Normalizes an authority outcome. Anything unknown or ambiguous (a different case, padding,
     * a non-string) is no decision: null, and the caller's failure policy applies.
     */
    public static function normalize(mixed $outcome): ?self
    {
        if (!is_string($outcome) || !isset(self::ALIASES[$outcome])) {
            return null;
        }
        return self::from(self::ALIASES[$outcome]);
    }
}

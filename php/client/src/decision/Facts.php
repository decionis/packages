<?php

declare(strict_types=1);

namespace Decionis\Client\Decision;

/**
 * Margin arithmetic as the Commerce Gate MCP does it: percentage points, six decimals. These are
 * policy inputs; the policy decides, never this class.
 */
final class Facts
{
    /**
     * Net margin on revenue. A known positive cost against zero revenue is a total loss (-100%),
     * and without a cost the margin is unknown, so a margin rule reports "not covered".
     *
     * @return array<string, ?float>
     */
    public static function margin(float $revenue, ?float $cost): array
    {
        $amount = $cost === null ? null : $revenue - $cost;
        $fraction = $amount === null ? null : ($revenue > 0 ? $amount / $revenue : ($cost > 0 ? -1.0 : null));
        return [
            'net_revenue' => self::round($revenue),
            'estimated_cost' => $cost === null ? null : self::round($cost),
            'net_margin_amount' => $amount === null ? null : self::round($amount),
            'net_margin_fraction' => $fraction === null ? null : self::round($fraction),
            'net_margin_percent' => $fraction === null ? null : self::round($fraction * 100),
        ];
    }

    public static function round(float $value): float
    {
        return round($value, 6);
    }
}

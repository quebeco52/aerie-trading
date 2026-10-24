<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\MacroStateDTO;

/**
 * The opening macro with the IG and HY spreads at their decade trends: the through-the-cycle funding a franchise is
 * struck on (DebtEngine::chargedWholesaleRate()). The District opens with credit a little calmer than that, so a firm
 * that funds at market books a little more than its target at the opening itself.
 */
final class OpeningCreditAtTrend
{
    public static function macro(): MacroStateDTO
    {
        $opening = new MacroStateDTO();

        return MacroStateDTO::fromArray([
            'macro_credit_spread' => $opening->macroCreditSpreadTrend,
            'macro_credit_spread_ema' => $opening->macroCreditSpreadTrend,
            'high_yield_credit_spread' => $opening->highYieldCreditSpreadTrend,
            'high_yield_credit_spread_ema' => $opening->highYieldCreditSpreadTrend,
        ] + $opening->toArray());
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Math\FinancialConstants;

/**
 * Holds exposure in inverse proportion to realized volatility.
 *
 * Risk parity, volatility-control and VaR-constrained books all size a position as target volatility over
 * realized volatility (Moreira & Muir 2017), so they sell into a rise in volatility and buy back as it
 * subsides — whatever the price did. It is the largest mechanical flow in modern markets and the reason a
 * shock is followed by more selling than the shock itself explained, and by a slow bid as the tape calms.
 *
 * Not a belief. It has no opinion on where the price is going and is not chosen because it beat anyone;
 * the money is there because a mandate put it there, and it holds its size unless volatility changes.
 */
final class VolatilityTargetStrategy implements AgentStrategyInterface
{
    public function identifier(): string
    {
        return 'vol_target';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        $realized = $view->annualizedVolatility;

        // No volatility to run to yet: the book is held at the size it would have at target.
        if ($realized <= 0.0 || !is_finite($realized)) {
            return FinancialConstants::AGENT_VOL_TARGET_BASE_SHARE;
        }

        $leverage = min(
            FinancialConstants::AGENT_VOL_TARGET_MAX_LEVERAGE,
            FinancialConstants::AGENT_VOL_TARGET_VOLATILITY / $realized
        );

        return max(0.0, min(1.0, FinancialConstants::AGENT_VOL_TARGET_BASE_SHARE * $leverage));
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;

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
    // --- Volatility-Targeting Funds (Moreira & Muir 2017; Harvey et al. 2018) ---
    /** Annualized volatility a vol-control book is run to. Exposure scales as target / realized, so a name at this volatility is held at the base share; set at the market's reference name so an ordinary name sits near 1x. */
    public const AGENT_VOL_TARGET_VOLATILITY = 0.25;
    /** Share of agent capital the vol-targeting books hold in a name running at target volatility. */
    public const AGENT_VOL_TARGET_BASE_SHARE = 0.15;
    /** Most a vol-targeting book levers up when realized volatility falls below target. Harvey et al. cap leverage at 2x; without a cap a quiet tape would be bought without limit. */
    public const AGENT_VOL_TARGET_MAX_LEVERAGE = 2.00;

    public function identifier(): string
    {
        return 'vol_target';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        $realized = $view->annualizedVolatility;

        // No volatility to run to yet: the book is held at the size it would have at target.
        if ($realized <= 0.0 || !is_finite($realized)) {
            return self::AGENT_VOL_TARGET_BASE_SHARE;
        }

        $leverage = min(
            self::AGENT_VOL_TARGET_MAX_LEVERAGE,
            self::AGENT_VOL_TARGET_VOLATILITY / $realized
        );

        return max(0.0, min(1.0, self::AGENT_VOL_TARGET_BASE_SHARE * $leverage));
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}

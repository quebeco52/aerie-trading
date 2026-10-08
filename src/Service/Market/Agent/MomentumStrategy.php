<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Math\FinancialConstants;

/**
 * Buys what has been going up.
 *
 * The destabilizing side. Extrapolative demand is self-confirming for as long as it lasts — buying pushes
 * the price up, which strengthens the trend, which attracts more capital to the belief — and it is the
 * switching between this and the fundamentalists that produces the bubbles and the volatility clustering
 * that a pure diffusion cannot generate.
 *
 * Forms the trend the way Jegadeesh & Titman (1993) do: over the half-year the price engine already keeps,
 * skipping the most recent month, whose winners reverse rather than continue (Jegadeesh 1990). Chasing the
 * last tick as hard as the last half-year made every move feed the next one at the shortest horizon, where
 * the data show reversal.
 */
final class MomentumStrategy implements AgentStrategyInterface
{
    public function identifier(): string
    {
        return 'momentum';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        return max(-1.0, min(1.0, FinancialConstants::AGENT_MOMENTUM_GAIN * ($view->momentumTrend - $view->recentMonthTrend)));
    }

    public function competesForCapital(): bool
    {
        return true;
    }
}

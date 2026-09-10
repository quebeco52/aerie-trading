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
 * Reads the same Jegadeesh-Titman formation trend the price engine already maintains, rather than a second
 * trend measure of its own.
 */
final class MomentumStrategy implements AgentStrategyInterface
{
    public function identifier(): string
    {
        return 'momentum';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        return max(-1.0, min(1.0, FinancialConstants::AGENT_MOMENTUM_GAIN * $view->momentumTrend));
    }

    public function competesForCapital(): bool
    {
        return true;
    }
}

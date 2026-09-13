<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Math\FinancialConstants;

/**
 * Buys what is cheap against its published fair value, sells what is dear.
 *
 * The stabilizing side of Brock & Hommes: fundamentalists pull the price back toward what the name is
 * thought to be worth, and a market in which they hold most of the capital tracks fair value closely.
 * Their weakness is that they are early — being right about value says nothing about when, which is
 * exactly what lets the other side take capital from them during a trend.
 */
final class FundamentalistStrategy implements AgentStrategyInterface
{
    public function identifier(): string
    {
        return 'fundamentalist';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        return max(-1.0, min(1.0, FinancialConstants::AGENT_FUNDAMENTALIST_GAIN * $view->logMispricing()));
    }

    public function competesForCapital(): bool
    {
        return true;
    }
}

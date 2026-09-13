<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Math\FinancialConstants;

/**
 * Long what is cheap against the rest of the market, short what is dear against it.
 *
 * The cross-sectional investor of Barberis & Shleifer: it does not ask whether a name is cheap, it asks
 * whether it is cheaper than the average name, and it funds the long with the short. Its signals sum to
 * zero across the market by construction, so a market-wide re-rating leaves it flat and only a spread
 * between names moves it. That is what makes one name's move different from everyone's move — without it
 * a market where every agent trades each name on its own has no participant who sees two names at once.
 *
 * Market-neutral money is a mandate, not a view on direction, so it does not compete with the beliefs for
 * capital. Until the market's cross-section is known it has nothing to compare against and holds nothing.
 */
final class RelativeValueStrategy implements AgentStrategyInterface
{
    public function identifier(): string
    {
        return 'relative_value';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        $conviction = max(-1.0, min(1.0, FinancialConstants::AGENT_RELATIVE_VALUE_GAIN * $view->relativeLogMispricing()));

        return FinancialConstants::AGENT_RELATIVE_VALUE_SHARE * $conviction;
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}

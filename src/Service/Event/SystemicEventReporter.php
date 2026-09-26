<?php

namespace App\Service\Event;

use App\DTO\MacroStateDTO;
use App\Entity\Etf;
use App\Service\Market\PriceChangeFeed;

/**
 * Publishes the tick's district-wide macro event as a headline on the benchmark fund.
 *
 * The number on the card is what the benchmark actually did over the last month, not a size the event is
 * assumed to have: a systemic event moves prices only through the economy the engine runs, so a fixed
 * "+5%" on a rescue or "-5%" on a crisis reported moves that never happened. Without buffered history the
 * card carries no number at all.
 */
class SystemicEventReporter
{
    public function __construct(
        private readonly NarrativeEngine $narrativeEngine,
        private readonly MarketEventPublisher $marketEvent,
        private readonly PriceChangeFeed $priceChangeFeed,
    ) {
    }

    /**
     * @return array<string, mixed>|null The wire copy of the published headline, or null when the tick carries no event.
     */
    public function report(MacroStateDTO $macro, Etf $benchmark): ?array
    {
        if ($macro->eventType === null) {
            return null;
        }

        $context = [
            'interbank_spread_bps' => number_format($macro->interbankLiquiditySpread * 10000.0, 0),
            'hy_spread_pct' => number_format($macro->highYieldCreditSpread * 100.0, 2),
            'recession_prob_pct' => number_format($macro->recessionProbability * 100.0, 1),
            'output_gap_pct' => number_format($macro->outputGap * 100.0, 2),
            'inversion_months' => number_format($macro->inversionDuration * 12.0, 1),
            'erp_pct' => number_format($macro->equityRiskPremium * 100.0, 2),
            'qe_intensity_pct' => number_format($macro->qeIntensity * 100.0, 2),
            'epu_index' => number_format($macro->policyUncertaintyIndexEma, 0),
            'sovereign_spread_bps' => number_format($macro->sovereignRiskSpread * 10000.0, 0),
            'debt_to_gdp_pct' => number_format($macro->sovereignDebtToGdp * 100.0, 0),
            'cat_severity' => number_format($macro->lastCatastropheSeverity, 1),
            'dsr_pct' => number_format($macro->householdDebtServiceRatio * 100.0, 1),
            'debt_to_income_pct' => number_format($macro->householdDebtToIncome * 100.0, 0),
            'credit_gap_pct' => number_format($macro->creditToGdpGapEma * 100.0, 1),
            'swf_trade_pct' => number_format(abs($macro->sovereignFundRebalanceShare) * 100.0, 2),
            'swf_weight_pct' => number_format($macro->sovereignFundDomesticWeight * 100.0, 2),
            'swf_target_pct' => number_format($macro->sovereignFundTargetWeight * 100.0, 2),
            'swf_size_gdp_pct' => number_format($macro->sovereignFundToGdp * 100.0, 0),
            'swf_months' => number_format($macro->sovereignFundRebalanceMonthsLeft, 0),
        ];

        $monthMove = $this->priceChangeFeed->changeForTicker((string) $benchmark->getTicker(), (float) $benchmark->getPrice());

        return $this->marketEvent->publish(
            $benchmark,
            'SHOCK',
            $this->narrativeEngine->generateLore($macro->eventType, $context),
            $monthMove === null ? null : 100.0 * $monthMove
        );
    }
}

<?php

namespace App\Service\Event;

use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\Entity\Etf;
use App\Service\Macro\MacroEngine;
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
        ] + self::electionContext($macro) + self::budgetContext($macro);

        $monthMove = $this->priceChangeFeed->changeForTicker((string) $benchmark->getTicker(), (float) $benchmark->getPrice());

        return $this->marketEvent->publish(
            $benchmark,
            'SHOCK',
            $this->narrativeEngine->generateLore($macro->eventType, $context),
            $monthMove === null ? null : 100.0 * $monthMove
        );
    }

    /**
     * What a budget round enacted, for the budget headline, and whether the Council's debt brake held part of it back.
     *
     * @return array<string, string>
     */
    private static function budgetContext(MacroStateDTO $macro): array
    {
        $names = array_map(static fn(string $name): string => preg_replace('/^The /', '', $name) ?? $name, AerieDiet::PARTY_NAMES);

        return [
            'government' => implode('-', array_map(static fn(string $party): string => $names[$party], AerieDiet::governingParties($macro->governingCoalition))),
            'tax_rate_pct' => number_format((MacroEngine::TARGET_CORPORATE_TAX_RATE + $macro->corporateTaxPolicyShift) * 100.0, 1),
            'tariff_pct' => number_format($macro->importTariffRate * 100.0, 1),
            'labor_growth_pct' => number_format($macro->laborForceGrowthRate * 100.0, 2),
            'council_held' => $macro->lastCouncilBrakeAt === $macro->totalTime ? 'yes' : 'no',
        ];
    }

    /**
     * The vote's result for an election headline: the government it formed, the largest party, and the biggest mover.
     * Empty before the first vote has moved anything.
     *
     * @return array<string, string>
     */
    private static function electionContext(MacroStateDTO $macro): array
    {
        if ($macro->dietVoteSwings === []) {
            return [];
        }

        // Each name as it reads mid-sentence: "the Vanguard", "the Civic Front".
        $names = array_map(static fn(string $name): string => 'the ' . (preg_replace('/^The /', '', $name) ?? $name), AerieDiet::PARTY_NAMES);
        $members = AerieDiet::governingParties($macro->governingCoalition);
        $seats = array_map('intval', $macro->dietSeats);
        arsort($seats);
        $swings = $macro->dietVoteSwings;
        uasort($swings, static fn(float $a, float $b): int => abs($b) <=> abs($a));
        $mover = (string) array_key_first($swings);
        $moverSwing = $swings[$mover] * 100.0;

        return [
            'coalition' => implode(' and ', array_map(static fn(string $party): string => $names[$party], $members)),
            'diet_seats' => (string) AerieDiet::SEATS,
            'coalition_seats' => (string) array_sum(array_map(static fn(string $party): int => $seats[$party] ?? 0, $members)),
            'largest_party' => $names[(string) array_key_first($seats)],
            'mover' => $names[$mover],
            'mover_swing_pp' => ($moverSwing >= 0.0 ? '+' : '') . number_format($moverSwing, 1),
        ];
    }
}

<?php

namespace App\Service\Event;

use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\Etf;
use App\Service\Macro\MacroEngine;
use App\Service\Market\PriceChangeFeed;
use App\Service\Politics\CoalitionFormation;

/**
 * Publishes the tick's district-wide event as a headline on the benchmark fund: the economy's, or when the economy has
 * none, the government's (a vote, a fall, a cabinet taking office, a budget).
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
    public function report(MacroStateDTO $macro, PoliticsStateDTO $politics, Etf $benchmark): ?array
    {
        $eventType = $macro->eventType ?? $politics->eventType;
        if ($eventType === null) {
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
        ] + self::electionContext($politics) + self::fallContext($politics) + self::formationContext($politics) + self::budgetContext($politics);

        $monthMove = $this->priceChangeFeed->changeForTicker((string) $benchmark->getTicker(), (float) $benchmark->getPrice());

        return $this->marketEvent->publish(
            $benchmark,
            'SHOCK',
            $this->narrativeEngine->generateLore($eventType, $context),
            $monthMove === null ? null : 100.0 * $monthMove
        );
    }

    /**
     * What a budget round enacted, for the budget headline, and whether the Council's debt brake held part of it back.
     *
     * @return array<string, string>
     */
    private static function budgetContext(PoliticsStateDTO $politics): array
    {
        $names = array_map(static fn(string $name): string => preg_replace('/^The /', '', $name) ?? $name, AerieDiet::PARTY_NAMES);

        return [
            'government' => implode('-', array_map(static fn(string $party): string => $names[$party], AerieDiet::governingParties($politics->governingCoalition))),
            'tax_rate_pct' => number_format((MacroEngine::TARGET_CORPORATE_TAX_RATE + $politics->corporateTaxPolicyShift) * 100.0, 1),
            'tariff_pct' => number_format($politics->importTariffRate * 100.0, 1),
            'labor_growth_pct' => number_format($politics->laborForceGrowthRate * 100.0, 2),
            'carbon_price' => number_format($politics->carbonPrice, 2),
            'council_held' => $politics->lastCouncilBrakeAt === $politics->totalTime ? 'yes' : 'no',
        ];
    }

    /**
     * The vote's result for an election headline: the largest party, the biggest mover, and either the party that won a
     * majority outright or the party that opens the talks, which leads their first attempt and need not be the largest,
     * and the larger bloc's leader and seats.
     * The talks' outcome is never named here: it is settled on the day of the vote but not known until the cabinet takes
     * office. Empty before the first vote has moved anything.
     *
     * @return array<string, string>
     */
    private static function electionContext(PoliticsStateDTO $politics): array
    {
        if ($politics->dietVoteSwings === []) {
            return [];
        }

        $names = self::midSentenceNames();
        $seats = array_map('intval', $politics->dietSeats);
        $largest = CoalitionFormation::bySize($politics->dietSeats, $politics->dietVoteShares)[0];
        $swings = $politics->dietVoteSwings;
        uasort($swings, static fn(float $a, float $b): int => abs($b) <=> abs($a));
        $mover = (string) array_key_first($swings);
        $moverSwing = $swings[$mover] * 100.0;

        $context = [
            'diet_seats' => (string) AerieDiet::SEATS,
            'majority_seats' => (string) AerieDiet::MAJORITY_SEATS,
            'largest_party' => $names[$largest],
            'largest_seats' => (string) $seats[$largest],
            'mover' => $names[$mover],
            'mover_swing_pp' => ($moverSwing >= 0.0 ? '+' : '') . number_format($moverSwing, 1),
        ];
        if ($seats[$largest] >= AerieDiet::MAJORITY_SEATS) {
            $context['majority_party'] = $names[$largest];
        } elseif ($politics->formationLog !== []) {
            $blocSeats = [];
            foreach ($politics->dietBlocs as $party => $leader) {
                $blocSeats[$leader] = ($blocSeats[$leader] ?? 0) + ($seats[$party] ?? 0);
            }
            arsort($blocSeats);
            $context['bloc_leader'] = $names[(string) array_key_first($blocSeats)];
            $context['bloc_seats'] = (string) reset($blocSeats);
            $lead = $politics->formationLog[0]['formateur'];
            $context['talks_opening'] = $lead === $largest
                ? "{$names[$largest]}, the largest with {$seats[$largest]}, opens coalition talks"
                : "{$names[$lead]} opens coalition talks, though {$names[$largest]} is the largest with {$seats[$largest]}";
        }

        return $context;
    }

    /**
     * The cabinet that fell, for the headline on the day it falls: its parties and supporters, how long it governed, and
     * the party that leads the first attempt at the next cabinet. As after a vote, the talks' outcome is never named.
     * Empty when a party with its own majority takes office on the same day, leaving no caretaker to name.
     *
     * @return array<string, string>
     */
    private static function fallContext(PoliticsStateDTO $politics): array
    {
        if ($politics->lastCabinetFellAt !== $politics->totalTime || $politics->lastGovernmentFormedAt === $politics->totalTime || $politics->formationLog === []) {
            return [];
        }

        $names = self::midSentenceNames();
        $list = static fn(array $parties): string => self::listNames(array_map(static fn(string $party): string => $names[$party], $parties));

        return [
            'fallen_cabinet' => $list(AerieDiet::governingParties($politics->governingCoalition)),
            'fallen_support' => $list(AerieDiet::governingParties($politics->supportParties)),
            'fallen_months' => number_format(12.0 * ($politics->totalTime - $politics->coalitionFormedAt), 0),
            'talks_lead' => $names[$politics->formationLog[0]['formateur']],
        ];
    }

    /**
     * The government the talks produced, for the headline on the day it takes office: its cabinet, the parties that
     * support it from outside, how long the talks took and over how many attempts, and who led the one that succeeded.
     *
     * @return array<string, string>
     */
    private static function formationContext(PoliticsStateDTO $politics): array
    {
        if ($politics->formationLog === [] || $politics->lastGovernmentFormedAt !== $politics->totalTime) {
            return [];
        }

        $names = self::midSentenceNames();
        $list = static fn(array $parties): string => self::listNames(array_map(static fn(string $party): string => $names[$party], $parties));
        $cabinet = AerieDiet::governingParties($politics->governingCoalition);
        $support = AerieDiet::governingParties($politics->supportParties);
        $seatsOf = static fn(array $parties): int => (int) array_sum(array_map(static fn(string $party): float => $politics->dietSeats[$party] ?? 0.0, $parties));
        $final = $politics->formationLog[array_key_last($politics->formationLog)];

        return [
            'cabinet' => $list($cabinet),
            'cabinet_seats' => (string) $seatsOf($cabinet),
            'support' => $list($support),
            'supported_seats' => (string) ($seatsOf($cabinet) + $seatsOf($support)),
            'minority' => $support === [] ? 'no' : 'yes',
            'talk_days' => number_format($final['day'], 0),
            'attempts' => (string) count($politics->formationLog),
            'attempts_phrase' => count($politics->formationLog) === 1 ? 'at the first attempt' : 'after ' . count($politics->formationLog) . ' attempts',
            'lead_party' => $names[$final['formateur']],
            'diet_seats' => (string) AerieDiet::SEATS,
        ];
    }

    /**
     * Each party's name as it reads mid-sentence: "the Vanguard", "the Civic Front".
     *
     * @return array<string, string>
     */
    private static function midSentenceNames(): array
    {
        return array_map(static fn(string $name): string => 'the ' . (preg_replace('/^The /', '', $name) ?? $name), AerieDiet::PARTY_NAMES);
    }

    /**
     * Names joined as prose: "a", "a and b", "a, b and c".
     *
     * @param list<string> $names
     */
    private static function listNames(array $names): string
    {
        if (count($names) < 2) {
            return implode('', $names);
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }
}

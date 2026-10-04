<?php

namespace App\Service\Event;

use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\Etf;
use App\Service\Macro\MacroEngine;
use App\Service\Market\PriceChangeFeed;
use App\Service\Politics\CoalitionFormation;
use App\Service\Politics\MonetaryAuthority;
use App\Service\View\GovernmentPageBuilder;

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
    // --- Stances in Prose ---
    /** How each stance on money reads of one person. */
    private const STANCE_PHRASES = ['hawk' => 'a hawk', 'swing' => 'a swing vote', 'dove' => 'a dove'];
    /** How each stance reads counted, one and many. */
    private const STANCE_PLURALS = ['hawk' => ['hawk', 'hawks'], 'swing' => ['swing vote', 'swing votes'], 'dove' => ['dove', 'doves']];

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
        ] + self::electionContext($politics) + self::fallContext($politics) + self::formationContext($politics) + self::budgetContext($politics)
            + self::authorityContext($politics);

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
     * The Monetary Authority for its headlines: the governor, their age and stance, when their term ends and whom the
     * Council passed over for them; the councillor seated this tick and whom they beat; the committee's make-up and the
     * supermajority it holds; and the last meeting, its rate, its move and its vote. Empty before the Authority has formed.
     *
     * @return array<string, string>
     */
    private static function authorityContext(PoliticsStateDTO $politics): array
    {
        if ($politics->authoritySalt < 0.0) {
            return [];
        }

        $time = $politics->totalTime;
        $stance = static fn(float $stance): string => self::STANCE_PHRASES[MonetaryAuthority::stanceName($stance)];
        $passedOver = static fn(array $candidates): string => self::listNames(array_map(
            static fn(array $candidate): string => "{$candidate['name']}, {$stance($candidate['stance'])}",
            $candidates
        ));
        $context = [
            'governor' => $politics->governorName,
            'governor_age' => (string) (int) floor($time - $politics->governorBirth),
            'governor_stance' => $stance($politics->governorStance),
            'governor_term_ends' => GovernmentPageBuilder::simDate(MonetaryAuthority::governorTermEnd($time)),
            'governor_passed_over' => $passedOver($politics->governorPassedOver),
        ];

        foreach ($politics->councilSince as $seat => $since) {
            if (abs($since - $politics->lastCouncillorSeatedAt) < 1e-6 && isset($politics->councilNames[$seat], $politics->councilBirths[$seat])) {
                $context['councillor'] = $politics->councilNames[$seat];
                $context['councillor_age'] = (string) (int) floor($time - $politics->councilBirths[$seat]);
                $context['councillor_stance'] = $stance($politics->councilStances[$seat] ?? 0.0);
                $context['councillor_passed_over'] = $passedOver($politics->councillorPassedOver);
            }
        }

        $stances = array_merge([$politics->governorStance], $politics->memberStances);
        $counts = array_count_values(array_map(MonetaryAuthority::stanceName(...), $stances));
        $context['committee_counts'] = self::listNames(array_values(array_filter(array_map(
            static fn(string $type): ?string => isset($counts[$type]) ? self::countWord($counts[$type]) . ' ' . self::STANCE_PLURALS[$type][$counts[$type] === 1 ? 0 : 1] : null,
            ['hawk', 'swing', 'dove']
        ))));
        $context['committee_majority'] = match (true) {
            $politics->committeeMajority > 0.0 => 'hawkish',
            $politics->committeeMajority < 0.0 => 'dovish',
            default => 'none',
        };

        if ($politics->lastMeetingAt >= 0.0) {
            $votes = $politics->lastMeetingVotes;
            $higher = count(array_filter($votes, static fn(float $vote): bool => $vote > 0.0));
            $lower = count(array_filter($votes, static fn(float $vote): bool => $vote < 0.0));
            $count = self::countWord(...);
            $member = static fn(int $n): string => $n === 1 ? 'member' : 'members';
            $move = (int) round($politics->lastMeetingChange * 10000.0);
            $rate = number_format($politics->lastMeetingRate * 100.0, 2);
            $context['policy_rate_pct'] = $rate;
            $context['rate_move'] = abs($move) < 13 ? "holds the rate at {$rate}%" : ($move > 0 ? "raises the rate by {$move} basis points to {$rate}%" : 'cuts the rate by ' . abs($move) . " basis points to {$rate}%");
            $context['vote_split'] = (count($votes) - $higher - $lower) . '-' . ($higher + $lower);
            $context['dissent_phrase'] = match (true) {
                $higher > 0 && $lower > 0 => "{$count($higher)} {$member($higher)} wanted a higher rate and {$count($lower)} a lower",
                $higher > 0 => "{$count($higher)} {$member($higher)} wanted a higher rate",
                $lower > 0 => "{$count($lower)} {$member($lower)} wanted a lower rate",
                default => 'the committee was unanimous',
            };
        }

        return $context;
    }

    /** A small count in words. */
    private static function countWord(int $n): string
    {
        return ['no', 'one', 'two', 'three', 'four', 'five', 'six', 'seven'][$n] ?? (string) $n;
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

<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Data\Politics\AerieCouncil;
use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DistrictNews;
use App\Entity\Etf;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Market\Chart\PriceChangeFeed;
use App\Service\Politics\CoalitionFormation;
use App\Service\Politics\CoastGuard;
use App\Service\Politics\Exchequer;
use App\Service\Politics\FairTradingBoard;
use App\Service\Politics\FinancialRegulator;
use App\Service\Politics\MonetaryAuthority;
use App\Service\Politics\PartyLeaders;
use App\Service\Politics\SecuritiesCommission;
use App\Service\Politics\SecurityBureau;
use App\Service\Politics\SovereignReserveFund;
use App\Service\Politics\StateGuard;
use App\Service\View\GovernmentPageBuilder;

/**
 * Publishes the tick's district-wide events as stories on their own desks: the economy's, and the government's (a vote,
 * a fall, a cabinet taking office, a budget, a rate decision). The benchmark fund only supplies the month's move.
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
    /** A head of the Sovereign Reserve Fund's stance on the reserves, as a headline puts it. */
    private const FUND_PHRASES = ['cautious' => 'a cautious investor', 'balanced' => 'a balanced investor', 'bold' => 'a bold investor'];
    /** A head of the Exchequer's stance on the debt, as a headline puts it. */
    private const DEBT_PHRASES = ['short' => 'who favours borrowing short', 'balanced' => 'a middle-of-the-road debt manager', 'long' => 'who favours locking in long-term rates'];
    /** A head of the Fair Trading Board's stance on competition, as a headline puts it. */
    private const COMPETITION_PHRASES = ['light' => 'who would let firms consolidate', 'balanced' => 'a middle-of-the-road reviewer', 'strict' => 'a strict enforcer against concentration'];
    /** A head of the Securities Commission's stance on the markets, as a headline puts it. */
    private const MARKETS_PHRASES = ['light' => 'a light-touch overseer of the markets', 'balanced' => 'a moderate', 'strict' => 'a strict overseer of the markets'];
    /** A head of the Financial Regulator's stance on the banks, as a headline puts it. */
    private const REGULATION_PHRASES = ['light' => 'a light-touch regulator', 'middle' => 'a moderate', 'strict' => 'a strict regulator'];
    private const STANCE_PLURALS = ['hawk' => ['hawk', 'hawks'], 'swing' => ['swing vote', 'swing votes'], 'dove' => ['dove', 'doves']];

    public function __construct(
        private readonly NarrativeEngine $narrativeEngine,
        private readonly MarketEventPublisher $marketEvent,
        private readonly PriceChangeFeed $priceChangeFeed,
    ) {}

    /**
     * Every story the tick carries: the economy's, the government's, and a rate decision that made news when the
     * government's lead story is another, since the meetings share their calendar with the budget rounds.
     *
     * @return list<array<string, mixed>> The wire copy of each published story, in that order; empty when the tick carries none.
     */
    public function report(MacroStateDTO $macro, PoliticsStateDTO $politics, Etf $benchmark): array
    {
        $stories = [];
        if ($macro->eventType !== null) {
            $stories[] = [DistrictNews::DESK_ECONOMY, $macro->eventType];
        }
        if ($politics->eventType !== null) {
            $stories[] = [DistrictNews::DESK_GOVERNMENT, $politics->eventType];
        }
        if ($politics->eventType !== ShockEvent::MONETARY_DECISION && MonetaryAuthority::meetingMadeNews($politics)) {
            $stories[] = [DistrictNews::DESK_GOVERNMENT, ShockEvent::MONETARY_DECISION];
        }
        if ($stories === []) {
            return [];
        }

        $context = [
            'interbank_spread_bps' => number_format($macro->interbankLiquiditySpread * 10000.0, 0),
            'hy_spread_pct' => number_format($macro->highYieldCreditSpread * 100.0, 2),
            'recession_prob_pct' => number_format($macro->recessionProbability * 100.0, 1),
            'unemployment_pct' => number_format($macro->unemploymentRate * 100.0, 1),
            'gdp_fall_pct' => number_format(-$macro->realGdpQuarterlyGrowth * 100.0, 1),
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
            + self::authorityContext($politics)
            + self::leaderContext($politics)
            + self::fundHeadContext($macro, $politics)
            + self::exchequerHeadContext($macro, $politics)
            + self::fairTradingHeadContext($politics)
            + self::securitiesHeadContext($politics)
            + self::securityDirectorContext($politics)
            + self::guardCommandersContext($politics);

        $monthMove = $this->priceChangeFeed->changeForTicker((string) $benchmark->getTicker(), (float) $benchmark->getPrice());

        return array_map(fn(array $story): array => $this->marketEvent->publishDistrict(
            $story[0],
            $story[1],
            $this->narrativeEngine->generateLore($story[1], $context),
            $monthMove === null ? null : 100.0 * $monthMove,
        ), $stories);
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
            'tax_rate_pct' => number_format((MacroEngine::BASE_CORPORATE_TAX_RATE + $politics->corporateTaxPolicyShift) * 100.0, 1),
            'tariff_pct' => number_format($politics->importTariffRate * 100.0, 1),
            'labor_growth_pct' => number_format($politics->laborForceGrowthRate * 100.0, 2),
            'carbon_price' => number_format($politics->carbonPrice, 2),
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
            'prime_minister' => $politics->leaderNames[PartyLeaders::primeMinisterParty($politics) ?? ''] ?? '',
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
        $stance = static fn(float $stance, float $swing = 0.0): string => self::STANCE_PHRASES[MonetaryAuthority::typeName($stance, $swing)]
            . ($swing > 0.0 && $stance !== 0.0 ? ($stance > 0.0 ? ' leaning hawkish' : ' leaning dovish') : '');
        $passedOver = static fn(array $candidates): string => self::listNames(array_map(
            static fn(array $candidate): string => "{$candidate['name']}, {$stance($candidate['stance'], $candidate['swing'] ?? 0.0)}",
            $candidates,
        ));
        $context = [
            'governor' => $politics->governorName,
            'governor_age' => (string) (int) floor($time - $politics->governorBirth),
            'governor_stance' => $stance($politics->governorStance, max(0.0, $politics->governorSwinger)),
            'governor_term_ends' => GovernmentPageBuilder::simDate(MonetaryAuthority::governorTermEnd($time)),
            'governor_passed_over' => $passedOver($politics->governorPassedOver),
        ];

        // The councillor seated last, at a term's start or mid-term in a vacant seat.
        $seatedAt = $politics->councilSeatedAt + $politics->councilSince;
        $seat = $seatedAt === [] ? null : array_search(max($seatedAt), $seatedAt, true);
        if (is_int($seat) && isset($politics->councilNames[$seat], $politics->councilBirths[$seat])) {
            $context['councillor'] = $politics->councilNames[$seat];
            $context['councillor_age'] = (string) (int) floor($time - $politics->councilBirths[$seat]);
            $context['councillor_stance'] = $stance($politics->councilStances[$seat] ?? 0.0, $politics->councilSwingers[$seat] ?? 0.0);
            $context['councillor_passed_over'] = $passedOver($politics->councillorPassedOver);
            $context['councillor_term_ends'] = GovernmentPageBuilder::simDate(($politics->councilSince[$seat] ?? $time) + AerieCouncil::TERM_YEARS);
            if ($politics->lastCouncilVacancyAt === $politics->lastCouncillorSeatedAt && $politics->lastVacancyName !== '') {
                $context['predecessor'] = $politics->lastVacancyName;
                $context['vacancy_cause'] = $politics->lastVacancyCause;
            }
        }

        $stances = array_merge([$politics->governorStance], array_values($politics->memberStances));
        $swingers = array_merge([max(0.0, $politics->governorSwinger)], array_values($politics->memberSwingers));
        $counts = array_count_values(array_map(static fn(float $stance, int $key): string => MonetaryAuthority::typeName($stance, $swingers[$key] ?? 0.0), $stances, array_keys($stances)));
        $context['committee_counts'] = self::listNames(array_values(array_filter(array_map(
            static fn(string $type): ?string => isset($counts[$type]) ? self::countWord($counts[$type]) . ' ' . self::STANCE_PLURALS[$type][$counts[$type] === 1 ? 0 : 1] : null,
            ['hawk', 'swing', 'dove'],
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

        return $context + self::regulatorContext($politics);
    }

    /**
     * A party's change of leader for its headline: the party, the new leader and their age, and the leader they succeed
     * and for how long that one led. Empty outside the tick a party changes its leader.
     *
     * @return array<string, string>
     */
    private static function leaderContext(PoliticsStateDTO $politics): array
    {
        $party = $politics->lastLeaderChangeParty;
        $former = $politics->leaderHistory[$party] ?? [];
        if ($politics->lastLeaderChangeAt < 0.0 || $politics->lastLeaderChangeAt !== $politics->totalTime || $former === [] || !isset($politics->leaderNames[$party], $politics->leaderBirths[$party])) {
            return [];
        }

        $outgoing = $former[array_key_last($former)];
        $led = $outgoing['until'] - $outgoing['since'];

        return [
            'leader_party' => self::midSentenceNames()[$party],
            'leader' => $politics->leaderNames[$party],
            'leader_age' => (string) (int) floor($politics->totalTime - $politics->leaderBirths[$party]),
            'outgoing_leader' => $outgoing['name'],
            'outgoing_led' => $led < 1.0 ? 'less than a year' : (round($led) === 1.0 ? 'a year' : number_format($led, 0) . ' years'),
        ];
    }

    /**
     * The Exchequer's head for their headline: who they are, their stance on the debt, the average maturity they steer
     * the book toward against today's, and what that does to the ten-year once the book has turned over. Empty before the
     * Exchequer has a head.
     *
     * @return array<string, string>
     */
    private static function exchequerHeadContext(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        if ($politics->exchequerHeadName === '') {
            return [];
        }

        $years = static fn(float $maturity): string => number_format($maturity, 1) . ' years';
        $target = Exchequer::averageMaturity($politics->exchequerHeadStance);
        $current = $macro->sovereignDebtAverageMaturity;
        $basisPoints = (int) round(10000.0 * (CreditFiscalSubsystem::maturitySupplyTermPremium($macro->sovereignDebtToGdpEma, $target) - CreditFiscalSubsystem::maturitySupplyTermPremium($macro->sovereignDebtToGdpEma, $current)));

        return [
            'exchequer_head' => $politics->exchequerHeadName,
            'exchequer_head_age' => (string) (int) floor($politics->totalTime - $politics->exchequerHeadBirth),
            'exchequer_head_stance' => self::DEBT_PHRASES[Exchequer::stanceName($target)],
            'exchequer_head_term_ends' => GovernmentPageBuilder::simDate(Exchequer::headTermEnd($politics->totalTime)),
            'exchequer_head_passed_over' => self::listNames(array_map(
                static fn(array $candidate): string => "{$candidate['name']}, who would have borrowed at {$years(Exchequer::averageMaturity($candidate['debt']))} on average",
                $politics->exchequerHeadPassedOver,
            )),
            'debt_maturity_change' => match (true) {
                abs($target - $current) < 0.05 => "keeps the debt's average maturity near {$years($current)}",
                $target > $current => "lengthens the debt toward an average of {$years($target)} from {$years($current)}, which as the debt rolls over adds about {$basisPoints} basis points to the ten-year",
                default => "shortens the debt toward an average of {$years($target)} from {$years($current)}, which as the debt rolls over takes about " . abs($basisPoints) . " basis points off the ten-year",
            },
        ];
    }

    /**
     * The Sovereign Reserve Fund's head for their headline: who they are, their stance on the reserves, the policy equity
     * share they set against the fund's present one, and what moving the board to its new weight comes to as a share of
     * the float, over the fund's transition. Empty before the fund has a head.
     *
     * @return array<string, string>
     */
    private static function fundHeadContext(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        if ($politics->fundHeadName === '') {
            return [];
        }

        $pct = static fn(float $share): string => number_format($share * 100.0, 0) . '%';
        $target = SovereignReserveFund::equityShare($politics->fundHeadStance);
        $current = $macro->sovereignFundPolicyEquityShare;
        $context = [
            'fund_head' => $politics->fundHeadName,
            'fund_head_age' => (string) (int) floor($politics->totalTime - $politics->fundHeadBirth),
            'fund_head_stance' => self::FUND_PHRASES[SovereignReserveFund::stanceName($target)],
            'fund_head_term_ends' => GovernmentPageBuilder::simDate(SovereignReserveFund::headTermEnd($politics->totalTime)),
            'fund_head_passed_over' => self::listNames(array_map(
                static fn(array $candidate): string => "{$candidate['name']}, who would have held {$pct(SovereignReserveFund::equityShare($candidate['fund']))} in shares",
                $politics->fundHeadPassedOver,
            )),
            'fund_equity_target' => $pct($target),
        ];
        if ($current <= 0.0 || $macro->boardFloatCap <= 0.0) {
            return $context + ['fund_mix_change' => "sets its share in equities at {$pct($target)}"];
        }

        $fund = $macro->sovereignFundToGdp * $macro->sovereignFundDollarsPerGdp * $macro->nominalGdpIndex;
        $boardShare = abs(($macro->sovereignFundTargetWeight / $current) * ($target - $current) * $fund / $macro->boardFloatCap);
        $months = (int) SovereignFundSubsystem::POLICY_TRANSITION_MONTHS;

        return $context + ['fund_mix_change' => match (true) {
            abs($target - $current) < 0.005 => "keeps its share in equities at {$pct($target)}",
            $target > $current => "raises its share in equities to {$pct($target)} from {$pct($current)}, buying about " . number_format($boardShare * 100.0, 1) . "% of the board's free float over {$months} months",
            default => "cuts its share in equities to {$pct($target)} from {$pct($current)}, selling about " . number_format($boardShare * 100.0, 1) . "% of the board's free float over {$months} months",
        }];
    }

    /**
     * The Fair Trading Board's head for their headline: who they are, their stance on competition, the concentration line
     * and share presumption they set against their predecessor's, when their term ends and whom the Council passed over.
     * Empty before the Board has a head.
     *
     * @return array<string, string>
     */
    private static function fairTradingHeadContext(PoliticsStateDTO $politics): array
    {
        if ($politics->fairTradingHeadName === '') {
            return [];
        }

        $line = static fn(float $stance): string => number_format(MergerAndAcquisitionEngine::reviewScreens(FairTradingBoard::leniency($stance))['concentrated'], 0);
        $ceiling = MergerAndAcquisitionEngine::reviewScreens(FairTradingBoard::leniency($politics->fairTradingHeadStance))['shareCeiling'];
        $screens = "a concentration line at {$line($politics->fairTradingHeadStance)} on the HHI"
            . ($ceiling < 1.0 ? ' and a merged firm presumed too big above ' . number_format($ceiling * 100.0, 0) . '% of its market' : ' and no cap on a merged firm\'s share');

        return [
            'fair_trading_head' => $politics->fairTradingHeadName,
            'fair_trading_head_age' => (string) (int) floor($politics->totalTime - $politics->fairTradingHeadBirth),
            'fair_trading_head_stance' => self::COMPETITION_PHRASES[FairTradingBoard::stanceName($politics->fairTradingHeadStance)],
            'fair_trading_head_term_ends' => GovernmentPageBuilder::simDate(FairTradingBoard::headTermEnd($politics->totalTime)),
            'fair_trading_head_passed_over' => self::listNames(array_map(
                static fn(array $candidate): string => "{$candidate['name']}, who would have drawn the line at {$line($candidate['competition'])}",
                $politics->fairTradingHeadPassedOver,
            )),
            'merger_review_change' => match (true) {
                abs($politics->fairTradingHeadStance - $politics->fairTradingPriorStance) < 0.05 => "keeps merger review where it stood, with {$screens}",
                $politics->fairTradingHeadStance > $politics->fairTradingPriorStance => "tightens merger review to {$screens}, from a line at {$line($politics->fairTradingPriorStance)}",
                default => "loosens merger review to {$screens}, from a line at {$line($politics->fairTradingPriorStance)}",
            },
        ];
    }

    /**
     * The Securities Commission's head for their headline: who they are, their stance on the markets, the margin and the
     * room they leave companies to steer their results against those in force, when those take effect, when their term ends and whom the
     * Council passed over. Empty before the Commission has a head.
     *
     * @return array<string, string>
     */
    private static function securitiesHeadContext(PoliticsStateDTO $politics): array
    {
        if ($politics->securitiesHeadName === '') {
            return [];
        }

        $pct = static fn(float $share): string => number_format($share * 100.0, 0) . '%';
        $stance = $politics->securitiesHeadStance;
        $prior = $politics->securitiesPriorStance;
        $margin = SecuritiesCommission::initialMargin($stance);
        $scale = SecuritiesCommission::earningsManagementScale($stance);
        $marginBefore = SecuritiesCommission::initialMargin($prior);
        $scaleBefore = SecuritiesCommission::earningsManagementScale($prior);

        return [
            'securities_head' => $politics->securitiesHeadName,
            'securities_head_age' => (string) (int) floor($politics->totalTime - $politics->securitiesHeadBirth),
            'securities_head_stance' => self::MARKETS_PHRASES[SecuritiesCommission::stanceName($stance)],
            'securities_head_term_ends' => GovernmentPageBuilder::simDate(SecuritiesCommission::headTermEnd($politics->totalTime)),
            'securities_rules_from' => GovernmentPageBuilder::simDate($politics->securitiesHeadTermStart + SecuritiesCommission::RULE_NOTICE_YEARS),
            'securities_head_passed_over' => self::listNames(array_map(
                static fn(array $candidate): string => "{$candidate['name']}, who would have set margin at {$pct(SecuritiesCommission::initialMargin($candidate['markets']))}",
                $politics->securitiesHeadPassedOver,
            )),
            'securities_rules_change' => sprintf(
                '%s and %s',
                abs($margin - $marginBefore) < 0.005 ? "keeps the initial margin at {$pct($margin)}" : ($margin > $marginBefore ? 'raises' : 'lowers') . " the initial margin to {$pct($margin)} from {$pct($marginBefore)}",
                match (true) {
                    abs($scale - $scaleBefore) < 0.005 => 'keeps its watch on companies that dress up their results',
                    $scale < $scaleBefore => 'steps up its pursuit of companies that dress up their results',
                    default => 'eases off companies that dress up their results',
                },
            ),
        ];
    }

    /**
     * The Security Bureau's director for their headline: who they are, when their term ends and whom the Council passed
     * over. Empty before the Bureau has a director.
     *
     * @return array<string, string>
     */
    private static function securityDirectorContext(PoliticsStateDTO $politics): array
    {
        if ($politics->securityDirectorName === '') {
            return [];
        }

        return [
            'security_director' => $politics->securityDirectorName,
            'security_director_age' => (string) (int) floor($politics->totalTime - $politics->securityDirectorBirth),
            'security_director_term_ends' => GovernmentPageBuilder::simDate(SecurityBureau::directorTermEnd($politics->totalTime)),
            'security_director_passed_over' => self::listNames(array_map(static fn(array $candidate): string => $candidate['name'], $politics->securityDirectorPassedOver)),
        ];
    }

    /**
     * The Coast Guard's commandant and the State Guard's commander for their headlines: who they are, when their terms
     * end and whom the Council passed over. A guard without a commander adds nothing.
     *
     * @return array<string, string>
     */
    private static function guardCommandersContext(PoliticsStateDTO $politics): array
    {
        $names = static fn(array $passedOver): string => self::listNames(array_map(static fn(array $candidate): string => $candidate['name'], $passedOver));

        return ($politics->coastGuardCommandantName === '' ? [] : [
            'coast_guard_commandant' => $politics->coastGuardCommandantName,
            'coast_guard_commandant_age' => (string) (int) floor($politics->totalTime - $politics->coastGuardCommandantBirth),
            'coast_guard_commandant_term_ends' => GovernmentPageBuilder::simDate(CoastGuard::commandantTermEnd($politics->totalTime)),
            'coast_guard_commandant_passed_over' => $names($politics->coastGuardCommandantPassedOver),
        ]) + ($politics->stateGuardCommanderName === '' ? [] : [
            'state_guard_commander' => $politics->stateGuardCommanderName,
            'state_guard_commander_age' => (string) (int) floor($politics->totalTime - $politics->stateGuardCommanderBirth),
            'state_guard_commander_term_ends' => GovernmentPageBuilder::simDate(StateGuard::commanderTermEnd($politics->totalTime)),
            'state_guard_commander_passed_over' => $names($politics->stateGuardCommanderPassedOver),
        ]);
    }

    /**
     * The Financial Regulator for its headline: the head, their age and stance on the banks, the requirement they will
     * set against the one in force, when their term ends and whom the Council passed over for them. Empty before the
     * Regulator has a head.
     *
     * @return array<string, string>
     */
    private static function regulatorContext(PoliticsStateDTO $politics): array
    {
        if ($politics->regulatorName === '') {
            return [];
        }

        $pct = static fn(float $share): string => number_format($share * 100.0, 1) . '%';
        $target = FinancialRegulator::requirement($politics->regulatorStance);
        $before = $politics->requirementPhaseFrom;

        return [
            'regulator' => $politics->regulatorName,
            'regulator_age' => (string) (int) floor($politics->totalTime - $politics->regulatorBirth),
            'regulator_stance' => self::REGULATION_PHRASES[FinancialRegulator::stanceName($target)],
            'regulator_term_ends' => GovernmentPageBuilder::simDate(FinancialRegulator::headTermEnd($politics->totalTime)),
            'regulator_passed_over' => self::listNames(array_map(
                static fn(array $candidate): string => "{$candidate['name']}, who would have set {$pct(FinancialRegulator::requirement($candidate['regulation']))}",
                $politics->regulatorPassedOver,
            )),
            'requirement_change' => match (true) {
                abs($target - $before) < 0.0005 => "holds the banks' core capital requirement at {$pct($target)} of risk-weighted assets",
                $target > $before => "raises the banks' core capital requirement to {$pct($target)} of risk-weighted assets from {$pct($before)}, phased in over the coming year",
                default => "cuts the banks' core capital requirement to {$pct($target)} of risk-weighted assets from {$pct($before)}, with immediate effect",
            },
        ];
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

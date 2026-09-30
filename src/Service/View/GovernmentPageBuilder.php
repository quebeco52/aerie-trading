<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\AerieCouncil;
use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CoalitionFormation;
use App\Service\Macro\Subsystem\DistrictPoliticsSubsystem;
use App\Service\Math\FinancialConstants;

/**
 * Builds the government page: the Diet as it now sits, the parties in the policy space, the cabinet that governs and
 * the parties that support it, the talks after a vote, the Council, and every vote on record.
 *
 * The talks are settled on the day of the vote; the page shows only the attempts whose day has passed, and no cabinet
 * before it takes office.
 *
 * The live Diet is read off the macro snapshot (DistrictPoliticsSubsystem publishes it every tick); the history and
 * the parties' past positions off diet_election. The hemicycle and compass geometry is computed here so the template
 * only draws.
 */
class GovernmentPageBuilder
{
    // --- Party Colours ---
    /** One colour per party, shared by the hemicycle, the compass and the tables. */
    public const PARTY_COLORS = [
        AerieDiet::CIVIC => '#dc2626',
        AerieDiet::VANGUARD => '#2563eb',
        AerieDiet::IRON_HARBOR => '#78716c',
        AerieDiet::EXCHANGE => '#0d9488',
        AerieDiet::CHARTISTS => '#7c3aed',
        AerieDiet::COMMON_LOT => '#d97706',
    ];

    // --- Hemicycle Geometry ---
    /** Rows of seats in the chamber drawing. */
    private const HEMICYCLE_ROWS = 8;
    /** Innermost row's radius, as a share of the outermost. */
    private const HEMICYCLE_INNER_RADIUS = 0.40;

    // --- Compass Geometry ---
    /** Half-width of the compass drawing, in SVG units, for a policy axis running -1 to +1. */
    private const COMPASS_HALF_WIDTH = 100.0;

    public function __construct(private readonly DietElectionRepository $elections) {}

    /**
     * @return array<string, mixed>
     */
    public function build(MacroStateDTO $macro): array
    {
        $history = $this->elections->findChronological();
        $seats = array_map('intval', $macro->dietSeats + array_fill_keys(AerieDiet::PARTIES, 0.0));
        $coalition = AerieDiet::governingParties($macro->governingCoalition);
        $support = AerieDiet::governingParties($macro->supportParties);
        $sumSeats = static fn(array $members): int => array_sum(array_map(static fn(string $party): int => $seats[$party], $members));
        $coalitionSeats = $sumSeats($coalition);
        $coalitionPosition = DistrictPoliticsSubsystem::coalitionPosition($macro->governingCoalition, $macro->dietSeats, $macro->partyPositions);
        $removalSeats = $sumSeats(array_values(array_diff(array_merge($coalition, $support), AerieDiet::COUNCIL_LOYALISTS)));
        $talking = $macro->coalitionTakesOfficeAt > $macro->totalTime;

        $parties = [];
        foreach (AerieDiet::PARTIES as $party) {
            $position = AerieDiet::position($party, $macro->partyPositions);
            $parties[] = [
                'key' => $party,
                'name' => AerieDiet::PARTY_NAMES[$party],
                'color' => self::PARTY_COLORS[$party],
                'seats' => $seats[$party],
                'share' => $macro->dietVoteShares[$party] ?? 0.0,
                'swing' => $history === [] ? null : ($macro->dietVoteSwings[$party] ?? 0.0),
                'governing' => in_array($party, $coalition, true),
                'supporting' => in_array($party, $support, true),
                'primaryAxis' => AerieDiet::PRIMARY_AXIS[$party],
                'state' => $position[AerieDiet::AXIS_STATE],
                'openness' => $position[AerieDiet::AXIS_OPENNESS],
                'council' => $position[AerieDiet::AXIS_COUNCIL],
            ];
        }

        $term = MacroEngine::ELECTION_TERM_YEARS;
        $nextElection = (floor($macro->totalTime / $term) + 1.0) * $term;
        $member = static fn(string $party): array => [
            'key' => $party,
            'name' => AerieDiet::PARTY_NAMES[$party],
            'color' => self::PARTY_COLORS[$party],
            'seats' => $seats[$party],
        ];

        return [
            'simDate' => self::simDate($macro->totalTime),
            'government' => [
                'members' => array_map($member, $coalition),
                'support' => array_map($member, $support),
                'seats' => $coalitionSeats,
                'supportedSeats' => $coalitionSeats + $sumSeats($support),
                'minority' => $coalitionSeats < AerieDiet::MAJORITY_SEATS,
                'formed' => $macro->lastGovernmentFormedAt < 0.0 ? 'At the founding' : self::simDate($macro->coalitionFormedAt),
                'range' => CoalitionFormation::ideologicalRange($coalition, $macro->partyPositions),
                'state' => $coalitionPosition[AerieDiet::AXIS_STATE],
                'openness' => $coalitionPosition[AerieDiet::AXIS_OPENNESS],
                'council' => $coalitionPosition[AerieDiet::AXIS_COUNCIL],
                'supermajority' => $removalSeats >= AerieDiet::SUPERMAJORITY_SEATS,
                'caretaker' => $talking,
            ],
            'talks' => $this->talks($macro, $talking),
            'election' => [
                'next' => self::simDate($nextElection),
                'yearsLeft' => $nextElection - $macro->totalTime,
                'campaign' => $nextElection - $macro->totalTime <= MacroEngine::ELECTION_CAMPAIGN_WINDOW_YEARS,
                'policyUncertainty' => $macro->policyUncertaintyIndexEma,
            ],
            'rules' => [
                'seats' => AerieDiet::SEATS,
                'majority' => AerieDiet::MAJORITY_SEATS,
                'supermajority' => AerieDiet::SUPERMAJORITY_SEATS,
                'termYears' => $term,
                'councilSeats' => AerieCouncil::SEATS,
                'councilTermYears' => AerieCouncil::TERM_YEARS,
                'growthSlope' => MacroEngine::ELECTION_GROWTH_SLOPE,
                'inflationSlope' => MacroEngine::ELECTION_INFLATION_SLOPE,
                'residual' => MacroEngine::ELECTION_RESIDUAL_SD,
                'campaignMonths' => MacroEngine::ELECTION_CAMPAIGN_WINDOW_YEARS * 12.0,
                'costOfRuling' => MacroEngine::ELECTION_COST_OF_RULING,
                'recordedCostOfRuling' => MacroEngine::ELECTION_RECORDED_COST_OF_RULING,
                'crisisLift' => MacroEngine::ELECTION_CRISIS_CLOSED_PARTY_LIFT,
                'crisisWindowYears' => MacroEngine::ELECTION_CRISIS_WINDOW_YEARS,
                'partyShock' => MacroEngine::ELECTION_PARTY_SHOCK_SD,
                'neutralTaxRate' => MacroEngine::TARGET_CORPORATE_TAX_RATE,
                'manifestoTaxGap' => MacroEngine::POLICY_MANIFESTO_CORPORATE_TAX_GAP,
                'profitsToGdp' => MacroEngine::CORPORATE_PROFITS_TO_GDP,
                'protectionistTariff' => MacroEngine::POLICY_PROTECTIONIST_TARIFF,
                'retaliation' => MacroEngine::TARIFF_RETALIATION_RATIO,
                'tariffOutputLoss' => MacroEngine::TARIFF_OUTPUT_LOSS,
                'migrationClosed' => MacroEngine::MIGRATION_CLOSED_REGIME,
                'migrationOpen' => MacroEngine::MIGRATION_OPEN_REGIME,
                'structuralLaborGrowth' => MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
                'housingElasticity' => MacroEngine::IMMIGRATION_HOUSING_ELASTICITY,
                'debtBrake' => MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD,
                'budgetRoundMonths' => 12.0 * MacroEngine::BUDGET_ROUND_PERIOD_YEARS,
                'minorityUtility' => MacroEngine::FORMATION_MINORITY_UTILITY,
                'minimalWinningUtility' => MacroEngine::FORMATION_MINIMAL_WINNING_UTILITY,
                'partyUtility' => MacroEngine::FORMATION_PARTY_UTILITY,
                'largestPartyUtility' => MacroEngine::FORMATION_LARGEST_PARTY_UTILITY,
                'rangeUtility' => MacroEngine::FORMATION_RANGE_UTILITY,
                'statusQuoUtility' => MacroEngine::FORMATION_STATUS_QUO_UTILITY,
                'reservation' => MacroEngine::FORMATION_RESERVATION,
                'attemptDays' => MacroEngine::FORMATION_ATTEMPT_DAYS,
                'formationMeanDays' => MacroEngine::FORMATION_MEAN_DAYS,
                'supportAccountability' => MacroEngine::ELECTION_SUPPORT_ACCOUNTABILITY,
                'loyalists' => array_map(static fn(string $party): string => AerieDiet::PARTY_NAMES[$party], AerieDiet::COUNCIL_LOYALISTS),
            ],
            'budget' => $this->budget($macro, $coalition, $support, $talking),
            'parties' => $parties,
            'hemicycle' => $this->hemicycle($seats, $parties),
            'compass' => $this->compass($parties, $history, $coalitionPosition, $coalition),
            'council' => [
                'roster' => array_map(static fn(array $seat): array => $seat + [
                    'sinceLabel' => $seat['founding'] ? 'Founding' : self::simDate($seat['since']),
                    'termEndsLabel' => self::simDate($seat['termEnds']),
                ], AerieCouncil::roster($macro->totalTime)),
                'nextVacancy' => (static function (array $seat): array {
                    return $seat + ['termEndsLabel' => self::simDate($seat['termEnds'])];
                })(AerieCouncil::nextVacancy($macro->totalTime)),
                'departments' => AerieCouncil::DEPARTMENTS,
            ],
            'history' => array_map(fn(DietElection $election): array => $this->historyRow($election, $macro->totalTime), array_reverse($history)),
        ];
    }

    /**
     * The talks after the last vote, as far as they have gone: the attempts whose day has passed, who leads the one
     * under way, and, once a cabinet has taken office, how long it took.
     *
     * @return array<string, mixed>|null Null before the first vote.
     */
    private function talks(MacroStateDTO $macro, bool $talking): ?array
    {
        if ($macro->lastElectionAt < 0.0 || $macro->formationLog === []) {
            return null;
        }

        $elapsed = ($macro->totalTime - $macro->lastElectionAt) * FinancialConstants::DAYS_PER_YEAR;
        $names = AerieDiet::PARTY_NAMES;
        $rounds = [
            AerieDiet::ROUND_MAJORITY => 'a majority cabinet',
            AerieDiet::ROUND_SUPPORT => 'a majority, or a minority cabinet with support',
            AerieDiet::ROUND_RUNOFF => 'any cabinet it can carry',
        ];
        $entries = [];
        $leading = null;
        foreach ($macro->formationLog as $attempt => $entry) {
            if ($talking && $entry['day'] > $elapsed) {
                $leading = ['name' => $names[$entry['formateur']], 'color' => self::PARTY_COLORS[$entry['formateur']], 'seeking' => $rounds[$entry['round']], 'attempt' => $attempt + 1];
                break;
            }
            $entries[] = [
                'day' => $entry['day'],
                'attempt' => $attempt + 1,
                'formateur' => $names[$entry['formateur']],
                'color' => self::PARTY_COLORS[$entry['formateur']],
                'seeking' => $rounds[$entry['round']],
                'formed' => $entry['formed'],
                'cabinet' => array_map(static fn(string $party): string => $names[$party], $entry['cabinet']),
                'support' => array_map(static fn(string $party): string => $names[$party], $entry['support']),
            ];
        }
        $last = $macro->formationLog[array_key_last($macro->formationLog)];

        return [
            'underWay' => $talking,
            'day' => $talking ? $elapsed : $last['day'],
            'entries' => $entries,
            'leading' => $leading,
            'attempts' => count($macro->formationLog),
        ];
    }

    /**
     * The levers as the cabinet's platform sets them, as the next budget would enact them, and as the last budget
     * left them, with what stands between: the support parties, the Council's debt brake, or talks still under way.
     *
     * @param list<string> $coalition The cabinet.
     * @param list<string> $support   Its support parties.
     * @return array<string, mixed>
     */
    private function budget(MacroStateDTO $macro, array $coalition, array $support, bool $talking): array
    {
        $standing = [
            'corporateTax' => $macro->corporateTaxPolicyShift,
            'tariff' => $macro->importTariffRate,
            'laborGrowth' => $macro->laborForceGrowthRate,
        ];
        $budget = DistrictPoliticsSubsystem::budget($coalition, $support, $macro->dietSeats, $macro->partyPositions, $standing, $macro->sovereignDebtToGdp);
        $round = MacroEngine::BUDGET_ROUND_PERIOD_YEARS;
        $nextRound = self::simDate((floor($macro->totalTime / $round) + 1.0) * $round);

        $status = static function (string $lever) use ($budget, $standing, $talking): string {
            return match (true) {
                abs($budget['platform'][$lever] - $standing[$lever]) < 1e-9 => 'enacted',
                $talking => 'caretaker',
                $budget['councilHeld'][$lever] => 'held',
                $budget['supportHeld'][$lever] && abs($budget['levers'][$lever] - $standing[$lever]) < 1e-9 => 'blocked',
                $budget['supportHeld'][$lever] => 'partial',
                default => 'pending',
            };
        };
        $neutral = MacroEngine::TARGET_CORPORATE_TAX_RATE;

        return [
            'levers' => [
                [
                    'name' => 'Corporate tax rate',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $neutral + $budget['platform']['corporateTax'],
                    'target' => $neutral + $budget['levers']['corporateTax'],
                    'enacted' => $neutral + $standing['corporateTax'],
                    'status' => $status('corporateTax'),
                    'note' => sprintf('Firms pay %.1f%% today, with the cyclical adjustment', 100.0 * $macro->corporateTaxRate),
                ],
                [
                    'name' => 'Average tariff on imports',
                    'axis' => AerieDiet::AXIS_OPENNESS,
                    'platform' => $budget['platform']['tariff'],
                    'target' => $budget['levers']['tariff'],
                    'enacted' => $standing['tariff'],
                    'status' => $status('tariff'),
                    'note' => sprintf('Partners answer with %.1f%% on the District\'s exports', 100.0 * MacroEngine::TARIFF_RETALIATION_RATIO * $macro->importTariffRate),
                ],
                [
                    'name' => 'Labour force growth',
                    'axis' => AerieDiet::AXIS_OPENNESS,
                    'platform' => $budget['platform']['laborGrowth'],
                    'target' => $budget['levers']['laborGrowth'],
                    'enacted' => $standing['laborGrowth'],
                    'status' => $status('laborGrowth'),
                    'note' => sprintf('Immigration has added %+.1f%% to the population over the structural path', 100.0 * $macro->immigrationPopulationShift),
                ],
            ],
            'debt' => $macro->sovereignDebtToGdp,
            'braking' => $budget['councilGuards'],
            'caretaker' => $talking,
            'lastBrake' => $macro->lastCouncilBrakeAt >= 0.0 ? self::simDate($macro->lastCouncilBrakeAt) : null,
            'lastBudget' => $macro->lastBudgetEnactedAt >= 0.0 ? self::simDate($macro->lastBudgetEnactedAt) : null,
            'nextRound' => $nextRound,
        ];
    }

    /**
     * A simulation time as the page names it: the District's year, counted from its founding, and the quarter.
     */
    public static function simDate(float $simTime): string
    {
        $year = (int) floor($simTime);
        $quarter = (int) floor(($simTime - $year) * 4.0 + 1e-9) + 1;

        return sprintf('Year %d Q%d', $year + 1, min(4, $quarter));
    }

    /**
     * The seats of the chamber drawing, filled with the parties from the largest state on the left to the smallest.
     *
     * Rows hold seats in proportion to their radius so the spacing is even, and seats are dealt out by angle so each
     * party takes a wedge.
     *
     * @param array<string, int>          $seats   Seats by party.
     * @param list<array<string, mixed>>  $parties Party rows, each marked governing or supporting.
     * @return list<array{x: float, y: float, color: string, governing: bool, supporting: bool}>
     */
    private function hemicycle(array $seats, array $parties): array
    {
        $total = array_sum($seats);
        if ($total <= 0) {
            return [];
        }

        $radii = [];
        for ($row = 0; $row < self::HEMICYCLE_ROWS; ++$row) {
            $radii[] = self::HEMICYCLE_INNER_RADIUS + ((1.0 - self::HEMICYCLE_INNER_RADIUS) * $row / (self::HEMICYCLE_ROWS - 1));
        }
        $radiusSum = array_sum($radii);
        $perRow = array_map(static fn(float $radius): int => (int) round($total * $radius / $radiusSum), $radii);
        $perRow[self::HEMICYCLE_ROWS - 1] += $total - array_sum($perRow);

        $slots = [];
        foreach ($radii as $row => $radius) {
            $count = $perRow[$row];
            for ($i = 0; $i < $count; ++$i) {
                $angle = $count === 1 ? M_PI / 2.0 : M_PI * (1.0 - ($i / ($count - 1)));
                $slots[] = ['angle' => $angle, 'radius' => $radius];
            }
        }
        usort($slots, static fn(array $a, array $b): int => [$b['angle'], $a['radius']] <=> [$a['angle'], $b['radius']]);

        $order = $parties;
        usort($order, static fn(array $a, array $b): int => $b['state'] <=> $a['state']);

        $drawn = [];
        $slot = 0;
        foreach ($order as $party) {
            for ($i = 0; $i < $seats[$party['key']]; ++$i, ++$slot) {
                $drawn[] = [
                    'x' => round(cos($slots[$slot]['angle']) * $slots[$slot]['radius'], 4),
                    'y' => round(sin($slots[$slot]['angle']) * $slots[$slot]['radius'], 4),
                    'color' => $party['color'],
                    'governing' => $party['governing'],
                    'supporting' => $party['supporting'],
                ];
            }
        }

        return $drawn;
    }

    /**
     * The policy space: openness across and size of state up, with the Council axis as a strip beneath; each party
     * with its trail of past positions.
     *
     * @param list<array<string, mixed>>  $parties    Party rows.
     * @param list<DietElection>          $history    Votes, oldest first.
     * @param array{state: float, openness: float, council: float} $government The cabinet's seat-weighted position.
     * @param list<string>                $coalition  The cabinet.
     * @return array<string, mixed>
     */
    private function compass(array $parties, array $history, array $government, array $coalition): array
    {
        $scale = self::COMPASS_HALF_WIDTH;
        $point = static fn(float $state, float $openness): array => ['x' => round($openness * $scale, 2), 'y' => round(-$state * $scale, 2)];

        $dots = [];
        foreach ($parties as $party) {
            $trail = [];
            $councilTrail = [];
            foreach ($history as $election) {
                if (!isset($election->getPositions()[$party['key']])) {
                    continue;
                }
                $past = AerieDiet::position($party['key'], $election->getPositions());
                $trail[] = $point($past[AerieDiet::AXIS_STATE], $past[AerieDiet::AXIS_OPENNESS]);
                $councilTrail[] = round($past[AerieDiet::AXIS_COUNCIL] * $scale, 2);
            }
            $dots[] = $party + $point($party['state'], $party['openness']) + [
                'trail' => $trail,
                'councilX' => round($party['council'] * $scale, 2),
                'councilTrail' => $councilTrail,
            ];
        }

        $links = [];
        $members = array_values(array_filter($dots, static fn(array $dot): bool => in_array($dot['key'], $coalition, true)));
        foreach ($members as $i => $a) {
            foreach (array_slice($members, $i + 1) as $b) {
                $links[] = ['x1' => $a['x'], 'y1' => $a['y'], 'x2' => $b['x'], 'y2' => $b['y']];
            }
        }

        return [
            'halfWidth' => $scale,
            'parties' => $dots,
            'links' => $links,
            'government' => $point($government[AerieDiet::AXIS_STATE], $government[AerieDiet::AXIS_OPENNESS]) + [
                'councilX' => round($government[AerieDiet::AXIS_COUNCIL] * $scale, 2),
            ],
        ];
    }

    /**
     * A vote on record. Its government stays hidden until it takes office: the talks are settled on the day of the
     * vote, and the page must not give their outcome away.
     *
     * @return array<string, mixed>
     */
    private function historyRow(DietElection $election, float $now): array
    {
        $seats = $election->getSeats();
        $formed = $election->getTakesOfficeAt() <= $now;
        $coalition = $formed ? $election->getCoalition() : [];
        $support = $formed ? $election->getSupport() : [];
        $named = static fn(string $party): array => [
            'name' => AerieDiet::PARTY_NAMES[$party],
            'color' => self::PARTY_COLORS[$party],
        ];

        return [
            'date' => self::simDate($election->getSimTime()),
            'seats' => array_map(static fn(string $party): array => [
                'key' => $party,
                'seats' => (int) ($seats[$party] ?? 0),
                'swing' => (float) ($election->getVoteSwings()[$party] ?? 0.0),
                'color' => self::PARTY_COLORS[$party],
            ], AerieDiet::PARTIES),
            'formed' => $formed,
            'coalition' => array_map($named, $coalition),
            'support' => array_map($named, $support),
            'formationDays' => $election->getFormationDays(),
            'attempts' => count($election->getFormation()),
            // Both lists are written in party order, so a different government is a different list.
            'changed' => $formed && $coalition !== $election->getOutgoingCoalition(),
            'incumbentSwing' => $election->getIncumbentSwing(),
            'volatility' => $election->getVolatility(),
            'growthGap' => $election->getGrowthGap(),
            'inflationGap' => $election->getInflationGap(),
            'crisisLift' => $election->hasCrisisLift(),
        ];
    }
}

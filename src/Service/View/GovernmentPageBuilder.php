<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\AerieCouncil;
use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Politics\CoalitionFormation;
use App\Service\Politics\PoliticsEngine;

/**
 * Builds the government page: the Diet as it now sits, the parties in the policy space, the cabinet that governs and
 * the parties that support it, the talks after a vote, the Council, and every vote on record.
 *
 * The talks are settled on the day of the vote; the page shows only the attempts whose day has passed, and no cabinet
 * before it takes office.
 *
 * The live Diet is read off the politics snapshot (PoliticsEngine keeps it every tick), and the debt, the rate firms
 * pay and the policy uncertainty off the macro snapshot; the history and the parties' past positions off diet_election. The hemicycle and compass geometry is computed here so the template
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
        AerieDiet::FREE_PORT => '#16a34a',
        AerieDiet::BASTION_GUILDS => '#db2777',
    ];

    // --- Party Labels ---
    /** Short names for the drawings and narrow columns, where the full names would crowd each other. */
    public const PARTY_LABELS = [
        AerieDiet::CIVIC => 'Civic',
        AerieDiet::VANGUARD => 'Vanguard',
        AerieDiet::IRON_HARBOR => 'Iron Harbor',
        AerieDiet::EXCHANGE => 'Exchange',
        AerieDiet::CHARTISTS => 'Chartists',
        AerieDiet::COMMON_LOT => 'Common Lot',
        AerieDiet::FREE_PORT => 'Free Port',
        AerieDiet::BASTION_GUILDS => 'Bastion Guilds',
    ];

    // --- Hemicycle Geometry ---
    /** Rows of seats in the chamber drawing. */
    private const HEMICYCLE_ROWS = 8;
    /** Innermost row's radius, as a share of the outermost. */
    private const HEMICYCLE_INNER_RADIUS = 0.40;

    // --- Compass Geometry ---
    /** Half-width of the compass drawing, in SVG units, for a policy axis running -1 to +1. */
    private const COMPASS_HALF_WIDTH = 100.0;
    /** Margin around the plane for the axis names, in SVG units: wide enough for 'Technocratic' beside the strip. */
    private const COMPASS_MARGIN = 56.0;
    /** Distance from the plane's lower edge to the Council strip's line, in SVG units: clear of 'Small state' for the highest row. */
    private const COMPASS_STRIP_OFFSET = 48.0;
    /** Font size of the axis names and party labels, in SVG units. */
    private const COMPASS_FONT_SIZE = 7.0;
    /** Advance of a bold label's average character as a share of its font size, to size a label's box. */
    public const COMPASS_CHARACTER_WIDTH = 0.6;
    /** Gap kept between a label and its dot, or another label, in SVG units. */
    private const COMPASS_LABEL_GAP = 1.5;
    /** Baselines a Council-strip label may take, above and below the line, nearest first, a label and its gap apart. */
    private const COMPASS_STRIP_ROWS = [-8.0, 15.0, -17.0, 24.0, -26.0, 33.0];

    public function __construct(private readonly DietElectionRepository $elections) {}

    /**
     * @return array<string, mixed>
     */
    public function build(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        $history = $this->elections->findChronological();
        $seats = array_map('intval', $politics->dietSeats + array_fill_keys(AerieDiet::PARTIES, 0.0));
        $coalition = AerieDiet::governingParties($politics->governingCoalition);
        $support = AerieDiet::governingParties($politics->supportParties);
        $sumSeats = static fn(array $members): int => array_sum(array_map(static fn(string $party): int => $seats[$party], $members));
        $coalitionSeats = $sumSeats($coalition);
        $coalitionPosition = PoliticsEngine::coalitionPosition($politics->governingCoalition, $politics->dietSeats, $politics->partyPositions);
        $removalSeats = $sumSeats(array_values(array_diff(array_merge($coalition, $support), AerieDiet::COUNCIL_LOYALISTS)));
        $talking = $politics->coalitionTakesOfficeAt > $macro->totalTime;

        $blocs = $politics->dietBlocs;
        $leaders = array_values(array_unique($blocs));
        $parties = [];
        foreach (AerieDiet::PARTIES as $party) {
            $position = AerieDiet::position($party, $politics->partyPositions);
            $parties[] = [
                'key' => $party,
                'name' => AerieDiet::PARTY_NAMES[$party],
                'label' => self::PARTY_LABELS[$party],
                'color' => self::PARTY_COLORS[$party],
                'seats' => $seats[$party],
                'share' => $politics->dietVoteShares[$party] ?? 0.0,
                'swing' => $history === [] ? null : ($politics->dietVoteSwings[$party] ?? 0.0),
                'governing' => in_array($party, $coalition, true),
                'supporting' => in_array($party, $support, true),
                'fixedAxes' => array_keys(AerieDiet::FIXED_POSITIONS[$party]),
                'leadsBloc' => $blocs[$party] === $party,
                'bloc' => self::PARTY_LABELS[$blocs[$party]],
                'blocColor' => self::PARTY_COLORS[$blocs[$party]],
                'state' => $position[AerieDiet::AXIS_STATE],
                'openness' => $position[AerieDiet::AXIS_OPENNESS],
                'council' => $position[AerieDiet::AXIS_COUNCIL],
            ];
        }

        $term = PoliticsEngine::ELECTION_TERM_YEARS;
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
                'formed' => $politics->lastGovernmentFormedAt < 0.0 ? 'At the founding' : self::simDate($politics->coalitionFormedAt),
                'range' => CoalitionFormation::ideologicalRange($coalition, $politics->partyPositions),
                'state' => $coalitionPosition[AerieDiet::AXIS_STATE],
                'openness' => $coalitionPosition[AerieDiet::AXIS_OPENNESS],
                'council' => $coalitionPosition[AerieDiet::AXIS_COUNCIL],
                'supermajority' => $removalSeats >= AerieDiet::SUPERMAJORITY_SEATS,
                'caretaker' => $talking,
            ],
            'talks' => $this->talks($politics, $talking),
            'election' => [
                'next' => self::simDate($nextElection),
                'yearsLeft' => $nextElection - $macro->totalTime,
                'campaign' => $nextElection - $macro->totalTime <= PoliticsEngine::ELECTION_CAMPAIGN_WINDOW_YEARS,
                'policyUncertainty' => $macro->policyUncertaintyIndexEma,
            ],
            'rules' => [
                'seats' => AerieDiet::SEATS,
                'majority' => AerieDiet::MAJORITY_SEATS,
                'supermajority' => AerieDiet::SUPERMAJORITY_SEATS,
                'termYears' => $term,
                'councilSeats' => AerieCouncil::SEATS,
                'councilTermYears' => AerieCouncil::TERM_YEARS,
                'growthSlope' => PoliticsEngine::ELECTION_GROWTH_SLOPE,
                'inflationSlope' => PoliticsEngine::ELECTION_INFLATION_SLOPE,
                'residual' => PoliticsEngine::ELECTION_RESIDUAL_SD,
                'campaignMonths' => PoliticsEngine::ELECTION_CAMPAIGN_WINDOW_YEARS * 12.0,
                'costOfRuling' => PoliticsEngine::ELECTION_COST_OF_RULING,
                'recordedCostOfRuling' => PoliticsEngine::ELECTION_RECORDED_COST_OF_RULING,
                'shortSwingQuarter' => sqrt(PoliticsEngine::ELECTION_SHORT_TERM_SWING_VARIANCE / 0.25),
                'shortSwingTwentieth' => sqrt(PoliticsEngine::ELECTION_SHORT_TERM_SWING_VARIANCE / 0.05),
                'normalVoteHalfLifeYears' => log(0.5) / log(PoliticsEngine::ELECTION_NORMAL_VOTE_PERSISTENCE),
                'neutralTaxRate' => MacroEngine::TARGET_CORPORATE_TAX_RATE,
                'manifestoTaxGap' => PoliticsEngine::POLICY_MANIFESTO_CORPORATE_TAX_GAP,
                'profitsToGdp' => MacroEngine::CORPORATE_PROFITS_TO_GDP,
                'protectionistTariff' => PoliticsEngine::POLICY_PROTECTIONIST_TARIFF,
                'retaliation' => MacroEngine::TARIFF_RETALIATION_RATIO,
                'tariffOutputLoss' => MacroEngine::TARIFF_OUTPUT_LOSS,
                'migrationClosed' => PoliticsEngine::MIGRATION_CLOSED_REGIME,
                'migrationOpen' => PoliticsEngine::MIGRATION_OPEN_REGIME,
                'structuralLaborGrowth' => MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
                'housingElasticity' => MacroEngine::IMMIGRATION_HOUSING_ELASTICITY,
                'debtBrake' => MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD,
                'strictReviewDelta' => MergerAndAcquisitionEngine::MERGER_REVIEW_HHI_DELTA,
                'strictReviewLine' => MergerAndAcquisitionEngine::MERGER_REVIEW_CONCENTRATED_HHI,
                'strictReviewShare' => MergerAndAcquisitionEngine::MERGER_REVIEW_SHARE_CEILING,
                'lenientReviewDelta' => MergerAndAcquisitionEngine::MERGER_REVIEW_LENIENT_HHI_DELTA,
                'lenientReviewLine' => MergerAndAcquisitionEngine::MERGER_REVIEW_LENIENT_CONCENTRATED_HHI,
                'budgetRoundMonths' => 12.0 * MacroEngine::BUDGET_ROUND_PERIOD_YEARS,
                'minorityUtility' => CoalitionFormation::FORMATION_MINORITY_UTILITY,
                'minimalWinningUtility' => CoalitionFormation::FORMATION_MINIMAL_WINNING_UTILITY,
                'partyUtility' => CoalitionFormation::FORMATION_PARTY_UTILITY,
                'largestPartyUtility' => CoalitionFormation::FORMATION_LARGEST_PARTY_UTILITY,
                'rangeUtility' => CoalitionFormation::FORMATION_RANGE_UTILITY,
                'manifestoPointsPerUnit' => CoalitionFormation::FORMATION_MANIFESTO_POINTS_PER_UNIT,
                'pactUtility' => CoalitionFormation::FORMATION_PACT_UTILITY,
                'antipactUtility' => CoalitionFormation::FORMATION_ANTIPACT_UTILITY,
                'antisystemUtility' => CoalitionFormation::FORMATION_ANTISYSTEM_UTILITY,
                'blocLeaders' => array_map(static fn(string $party): string => AerieDiet::PARTY_NAMES[$party], $leaders),
                'blocSeats' => array_map(
                    static fn(string $leader): array => [
                        'name' => self::PARTY_LABELS[$leader],
                        'color' => self::PARTY_COLORS[$leader],
                        'seats' => $sumSeats(array_keys(array_filter($blocs, static fn(string $bloc): bool => $bloc === $leader))),
                    ],
                    $leaders
                ),
                'statusQuoUtility' => CoalitionFormation::FORMATION_STATUS_QUO_UTILITY,
                'reservation' => CoalitionFormation::FORMATION_RESERVATION,
                'reservationStep' => CoalitionFormation::FORMATION_RESERVATION_STEP,
                'attemptDays' => CoalitionFormation::FORMATION_ATTEMPT_DAYS,
                'formationMeanDays' => CoalitionFormation::FORMATION_MEAN_DAYS,
                'fallSingleMinority' => 1.0 - exp(-PoliticsEngine::CABINET_FALL_HAZARD_SINGLE_PARTY_MINORITY * $term),
                'fallMinorityCoalition' => 1.0 - exp(-PoliticsEngine::CABINET_FALL_HAZARD_MINORITY_COALITION * $term),
                'fallMajorityCoalition' => 1.0 - exp(-PoliticsEngine::CABINET_FALL_HAZARD_MAJORITY_COALITION * $term),
                'supportAccountability' => PoliticsEngine::ELECTION_SUPPORT_ACCOUNTABILITY,
                'loyalists' => array_map(static fn(string $party): string => AerieDiet::PARTY_NAMES[$party], AerieDiet::COUNCIL_LOYALISTS),
            ],
            'budget' => $this->budget($macro, $politics, $coalition, $support, $talking),
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
     * The last talks, after a vote or a fall, as far as they have gone: the attempts whose day has passed, who leads the
     * one under way (but not the cabinet it is trying), and, once a cabinet has taken office, how long it took.
     *
     * @return array<string, mixed>|null Null before the first talks.
     */
    private function talks(PoliticsStateDTO $politics, bool $talking): ?array
    {
        $startedAt = $politics->talksStartedAt >= 0.0 ? $politics->talksStartedAt : $politics->lastElectionAt;
        if ($startedAt < 0.0 || $politics->formationLog === []) {
            return null;
        }

        $elapsed = ($politics->totalTime - $startedAt) * FinancialConstants::DAYS_PER_YEAR;
        $names = AerieDiet::PARTY_NAMES;
        $entries = [];
        $leading = null;
        foreach ($politics->formationLog as $attempt => $entry) {
            if ($talking && $entry['day'] > $elapsed) {
                $leading = ['name' => $names[$entry['formateur']], 'color' => self::PARTY_COLORS[$entry['formateur']], 'attempt' => $attempt + 1];
                break;
            }
            $entries[] = [
                'day' => $entry['day'],
                'attempt' => $attempt + 1,
                'formateur' => $names[$entry['formateur']],
                'color' => self::PARTY_COLORS[$entry['formateur']],
                'formed' => $entry['formed'],
                'cabinet' => array_map(static fn(string $party): string => $names[$party], $entry['cabinet']),
                'support' => array_map(static fn(string $party): string => $names[$party], $entry['support']),
            ];
        }
        $last = $politics->formationLog[array_key_last($politics->formationLog)];

        $afterFall = $politics->lastCabinetFellAt >= 0.0 && $politics->lastCabinetFellAt === $startedAt;

        return [
            'underWay' => $talking,
            'afterFall' => $afterFall,
            'startedOn' => self::simDate($startedAt),
            'day' => $talking ? $elapsed : $last['day'],
            'entries' => $entries,
            'leading' => $leading,
            'attempts' => count($politics->formationLog),
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
    private function budget(MacroStateDTO $macro, PoliticsStateDTO $politics, array $coalition, array $support, bool $talking): array
    {
        $standing = [
            'corporateTax' => $politics->corporateTaxPolicyShift,
            'tariff' => $politics->importTariffRate,
            'laborGrowth' => $politics->laborForceGrowthRate,
            'mergerReviewLeniency' => $politics->mergerReviewLeniency,
        ];
        $budget = PoliticsEngine::budget($coalition, $support, $politics->dietSeats, $politics->partyPositions, $standing, $macro->sovereignDebtToGdp);
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
        $screens = MergerAndAcquisitionEngine::reviewScreens($politics->mergerReviewLeniency);

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
                    'note' => sprintf('Partners answer with %.1f%% on the District\'s exports', 100.0 * MacroEngine::TARIFF_RETALIATION_RATIO * $standing['tariff']),
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
                [
                    'name' => 'Merger review line',
                    'axis' => AerieDiet::AXIS_COUNCIL,
                    'unit' => 'hhi',
                    'platform' => MergerAndAcquisitionEngine::reviewScreens($budget['platform']['mergerReviewLeniency'])['concentrated'],
                    'target' => MergerAndAcquisitionEngine::reviewScreens($budget['levers']['mergerReviewLeniency'])['concentrated'],
                    'enacted' => $screens['concentrated'],
                    'status' => $status('mergerReviewLeniency'),
                    'note' => sprintf('A deal adding %.0f points of HHI past the line is blocked%s', 10000.0 * $screens['delta'], $screens['shareCeiling'] < 1.0 ? sprintf(', as is one making a firm of %.0f%% of its market', 100.0 * $screens['shareCeiling']) : ''),
                ],
            ],
            'debt' => $macro->sovereignDebtToGdp,
            'braking' => $budget['councilGuards'],
            'caretaker' => $talking,
            'lastBrake' => $politics->lastCouncilBrakeAt >= 0.0 ? self::simDate($politics->lastCouncilBrakeAt) : null,
            'lastBudget' => $politics->lastBudgetEnactedAt >= 0.0 ? self::simDate($politics->lastBudgetEnactedAt) : null,
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
     * with its trail of past positions, and its label placed where it crowds nothing.
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
        $margin = self::COMPASS_MARGIN;
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
                'r' => 3.0 + ($party['seats'] / 20.0),
                'trail' => $trail,
                'councilX' => round($party['council'] * $scale, 2),
                'councilR' => 2.5 + ($party['seats'] / 25.0),
                'councilTrail' => $councilTrail,
            ];
        }

        $size = self::COMPASS_FONT_SIZE;
        $axes = [
            ['text' => 'Big state', 'x' => 0.0, 'y' => -$scale - 5.0, 'anchor' => 'middle'],
            ['text' => 'Small state', 'x' => 0.0, 'y' => $scale + 11.0, 'anchor' => 'middle'],
            ['text' => 'Closed', 'x' => -$scale - 4.0, 'y' => 2.0, 'anchor' => 'end'],
            ['text' => 'Open', 'x' => $scale + 4.0, 'y' => 2.0, 'anchor' => 'start'],
        ];
        $bounds = [-$scale - $margin, -$scale - 16.0, $scale + $margin, $scale + 16.0];
        $plane = self::placePlaneLabels($dots, array_map(static fn(array $axis): array => self::labelBox($axis['text'], $axis['x'], $axis['y'], $axis['anchor']), $axes), $bounds);
        $strip = self::placeStripLabels($dots);
        foreach ($dots as $i => $dot) {
            $dots[$i] = $dot + $plane[$i] + $strip[$i];
        }

        $links = [];
        $members = array_values(array_filter($dots, static fn(array $dot): bool => in_array($dot['key'], $coalition, true)));
        foreach ($members as $i => $a) {
            foreach (array_slice($members, $i + 1) as $b) {
                $links[] = ['x1' => $a['x'], 'y1' => $a['y'], 'x2' => $b['x'], 'y2' => $b['y']];
            }
        }

        $stripY = $scale + self::COMPASS_STRIP_OFFSET;
        $bottom = $stripY + max(self::COMPASS_STRIP_ROWS) + (0.3 * $size);

        return [
            'halfWidth' => $scale,
            'viewBox' => [-$scale - $margin, -$scale - 16.0, 2.0 * ($scale + $margin), $bottom + $scale + 16.0],
            'fontSize' => $size,
            'axes' => $axes,
            'stripY' => $stripY,
            'stripAxes' => [
                ['text' => 'Populist', 'x' => -$scale - 4.0, 'y' => 2.0, 'anchor' => 'end'],
                ['text' => 'Technocratic', 'x' => $scale + 4.0, 'y' => 2.0, 'anchor' => 'start'],
            ],
            'parties' => $dots,
            'links' => $links,
            'government' => $point($government[AerieDiet::AXIS_STATE], $government[AerieDiet::AXIS_OPENNESS]) + [
                'councilX' => round($government[AerieDiet::AXIS_COUNCIL] * $scale, 2),
            ],
        ];
    }

    /**
     * Where each party's label goes in the plane: above its dot, below, right or left, the first spot inside the
     * drawing that clears the axis names, the other dots and every label placed before it, the larger parties placed
     * first; above when no spot is clear.
     *
     * @param list<array<string, mixed>>                          $dots   The parties, with x, y, r and label.
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $taken  Boxes already drawn on.
     * @param array{0: float, 1: float, 2: float, 3: float}       $bounds The box a label must stay inside.
     * @return array<int, array{labelX: float, labelY: float, labelAnchor: string}> By the dots' index.
     */
    private static function placePlaneLabels(array $dots, array $taken, array $bounds): array
    {
        $size = self::COMPASS_FONT_SIZE;
        $gap = self::COMPASS_LABEL_GAP;
        $circles = array_map(static fn(array $dot): array => [$dot['x'] - $dot['r'], $dot['y'] - $dot['r'], $dot['x'] + $dot['r'], $dot['y'] + $dot['r']], $dots);

        $placed = [];
        foreach (self::bySeats($dots) as $i) {
            $dot = $dots[$i];
            $candidates = [
                [$dot['x'], $dot['y'] - $dot['r'] - $gap - (0.2 * $size), 'middle'],
                [$dot['x'], $dot['y'] + $dot['r'] + $gap + (0.8 * $size), 'middle'],
                [$dot['x'] + $dot['r'] + $gap, $dot['y'] + (0.3 * $size), 'start'],
                [$dot['x'] - $dot['r'] - $gap, $dot['y'] + (0.3 * $size), 'end'],
            ];
            $others = array_merge($taken, array_values(array_diff_key($circles, [$i => true])));
            $choice = $candidates[0];
            foreach ($candidates as $candidate) {
                $box = self::labelBox($dot['label'], ...$candidate);
                $inside = $box[0] >= $bounds[0] && $box[1] >= $bounds[1] && $box[2] <= $bounds[2] && $box[3] <= $bounds[3];
                if ($inside && !self::crowds($box, $others)) {
                    $choice = $candidate;
                    break;
                }
            }
            $taken[] = self::labelBox($dot['label'], ...$choice);
            $placed[$i] = ['labelX' => round($choice[0], 2), 'labelY' => round($choice[1], 2), 'labelAnchor' => $choice[2]];
        }

        return $placed;
    }

    /**
     * Where each party's label goes on the Council strip: centred over its dot (the margin holds half the longest
     * label past either end), in the nearest row above or below the line that no label placed before it crowds, the
     * larger parties placed first; the nearest row when every row is crowded.
     *
     * @param list<array<string, mixed>> $dots The parties, with councilX and label.
     * @return array<int, array{stripLabelX: float, stripLabelY: float}> By the dots' index.
     */
    private static function placeStripLabels(array $dots): array
    {
        $taken = [];
        $placed = [];
        foreach (self::bySeats($dots) as $i) {
            $x = (float) $dots[$i]['councilX'];
            $row = self::COMPASS_STRIP_ROWS[0];
            foreach (self::COMPASS_STRIP_ROWS as $candidate) {
                if (!self::crowds(self::labelBox($dots[$i]['label'], $x, $candidate, 'middle'), $taken)) {
                    $row = $candidate;
                    break;
                }
            }
            $taken[] = self::labelBox($dots[$i]['label'], $x, $row, 'middle');
            $placed[$i] = ['stripLabelX' => round($x, 2), 'stripLabelY' => $row];
        }

        return $placed;
    }

    /**
     * The dots' indexes from the most seats to the fewest, ties in party order.
     *
     * @param list<array<string, mixed>> $dots
     * @return list<int>
     */
    private static function bySeats(array $dots): array
    {
        $order = array_keys($dots);
        usort($order, static fn(int $a, int $b): int => [$dots[$b]['seats'], $a] <=> [$dots[$a]['seats'], $b]);

        return $order;
    }

    /**
     * The box a label covers: its width from its length, from the cap height above its baseline to the descenders.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} Left, top, right, bottom.
     */
    private static function labelBox(string $text, float $x, float $baseline, string $anchor): array
    {
        $width = self::labelWidth($text);
        $left = match ($anchor) {
            'start' => $x,
            'end' => $x - $width,
            default => $x - ($width / 2.0),
        };

        return [$left, $baseline - (0.8 * self::COMPASS_FONT_SIZE), $left + $width, $baseline + (0.2 * self::COMPASS_FONT_SIZE)];
    }

    /**
     * A label's width at the compass font, from its length.
     */
    private static function labelWidth(string $text): float
    {
        return mb_strlen($text) * self::COMPASS_FONT_SIZE * self::COMPASS_CHARACTER_WIDTH;
    }

    /**
     * Whether a box comes within the label gap of any of the others.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}       $box
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $others
     */
    private static function crowds(array $box, array $others): bool
    {
        $gap = self::COMPASS_LABEL_GAP;
        foreach ($others as $other) {
            if ($box[0] < $other[2] + $gap && $other[0] < $box[2] + $gap && $box[1] < $other[3] + $gap && $other[1] < $box[3] + $gap) {
                return true;
            }
        }

        return false;
    }

    /**
     * A vote on record, with each cabinet that fell before the next. A government stays hidden until it takes office: the
     * talks are settled on the day of the vote or the fall, and the page must not give their outcome away.
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
            'falls' => array_map(static function (array $fall) use ($named, $now): array {
                $formed = $fall['fellAt'] + ($fall['formationDays'] / FinancialConstants::DAYS_PER_YEAR) <= $now;

                return [
                    'date' => self::simDate($fall['fellAt']),
                    'fallen' => array_map($named, $fall['fallen']),
                    'formed' => $formed,
                    'coalition' => $formed ? array_map($named, $fall['cabinet']) : [],
                    'support' => $formed ? array_map($named, $fall['support']) : [],
                    'formationDays' => $fall['formationDays'],
                    'attempts' => count($fall['formation']),
                ];
            }, $election->getFalls()),
        ];
    }
}

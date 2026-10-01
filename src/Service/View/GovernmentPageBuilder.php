<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\AerieCouncil;
use App\Data\AerieDiet;
use App\Data\AeriePartyProfiles;
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
    /** Past votes whose positions are drawn behind each party, the oldest faintest. */
    private const COMPASS_TRAIL_VOTES = 6;
    /** Faintest and strongest opacity of a past position, the oldest drawn and the last vote's. */
    private const COMPASS_TRAIL_OPACITY = [0.10, 0.40];
    /** How far the cabinet's shaded area reaches past the edge of its parties' dots, in SVG units. */
    private const COMPASS_HALO_PAD = 5.0;
    /** Radius of the ring marking where the cabinet governs from, in SVG units. */
    private const COMPASS_HUB_RADIUS = 3.5;

    // --- Party Pages ---
    /** The policy questions as a party page names them: the axis, and its two ends. */
    private const AXIS_NAMES = [
        AerieDiet::AXIS_STATE => ['name' => 'Size of the state', 'low' => 'Small state', 'high' => 'Big state'],
        AerieDiet::AXIS_OPENNESS => ['name' => 'Openness', 'low' => 'Closed', 'high' => 'Open'],
        AerieDiet::AXIS_COUNCIL => ['name' => 'The Council', 'low' => 'Populist', 'high' => 'Technocratic'],
    ];

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
        $parties = $this->partyRows($politics, $history);

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
                'debtBrake' => MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD,
                'budgetRoundMonths' => 12.0 * MacroEngine::BUDGET_ROUND_PERIOD_YEARS,
                'blocLeaders' => array_map(self::midSentenceName(...), $leaders),
                'blocSeats' => array_map(
                    static fn(string $leader): array => [
                        'name' => self::PARTY_LABELS[$leader],
                        'color' => self::PARTY_COLORS[$leader],
                        'seats' => $sumSeats(array_keys(array_filter($blocs, static fn(string $bloc): bool => $bloc === $leader))),
                    ],
                    $leaders
                ),
                'loyalists' => array_map(self::midSentenceName(...), AerieDiet::COUNCIL_LOYALISTS),
            ],
            'budget' => $this->budget($macro, $politics, $coalition, $support, $talking),
            'parties' => $parties,
            'hemicycle' => $this->hemicycle($seats, $parties),
            'compass' => $this->compass($parties, $history, $coalitionPosition, $coalition, $support),
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
     * A party's page: who it is, where it stands, what it would enact governing alone from there today against what is
     * in force, and its record at the polls since the founding.
     *
     * @return array<string, mixed>
     */
    public function buildParty(MacroStateDTO $macro, PoliticsStateDTO $politics, string $party): array
    {
        $history = $this->elections->findChronological();
        $rows = array_column($this->partyRows($politics, $history), null, 'key');
        $row = $rows[$party];
        $position = AerieDiet::position($party, $politics->partyPositions);
        $platform = PoliticsEngine::platform($position);
        $neutral = MacroEngine::TARGET_CORPORATE_TAX_RATE;
        $concentrated = static fn(float $leniency): float => MergerAndAcquisitionEngine::reviewScreens($leniency)['concentrated'];
        $talking = $politics->coalitionTakesOfficeAt > $macro->totalTime;

        $axes = [];
        foreach (AerieDiet::AXES as $axis) {
            $axes[] = self::AXIS_NAMES[$axis] + [
                'key' => $axis,
                'value' => $position[$axis],
                'home' => AerieDiet::HOME_POSITIONS[$party][$axis],
                'fixed' => AerieDiet::isFixed($party, $axis),
                'others' => array_values(array_map(static fn(array $other): array => [
                    'label' => $other['label'],
                    'color' => $other['color'],
                    'value' => $other[$axis],
                ], array_filter($rows, static fn(array $other): bool => $other['key'] !== $party))),
            ];
        }

        $record = [['date' => 'Founding', 'seats' => (int) AerieDiet::SEED_SEATS[$party], 'share' => AerieDiet::SEED_VOTE_SHARES[$party],
            'cabinet' => (AerieDiet::SEED_COALITION[$party] ?? 0.0) > 0.5, 'support' => (AerieDiet::SEED_SUPPORT[$party] ?? 0.0) > 0.5]];
        foreach ($history as $election) {
            $formed = $election->getTakesOfficeAt() <= $macro->totalTime;
            $record[] = [
                'date' => self::simDate($election->getSimTime()),
                'seats' => (int) ($election->getSeats()[$party] ?? 0),
                'share' => (float) ($election->getVoteShares()[$party] ?? 0.0),
                'cabinet' => $formed && in_array($party, $election->getCoalition(), true),
                'support' => $formed && in_array($party, $election->getSupport(), true),
            ];
        }
        $votes = array_slice($record, 1);

        return [
            'simDate' => self::simDate($macro->totalTime),
            'party' => $row + AeriePartyProfiles::PROFILES[$party] + [
                'caretaker' => $talking,
                'blocLeader' => self::midSentenceName($politics->dietBlocs[$party]),
                'definedBy' => array_map(static fn(string $axis): string => self::AXIS_NAMES[$axis]['name'], array_keys(AerieDiet::FIXED_POSITIONS[$party])),
            ],
            'axes' => $axes,
            'levers' => [
                ['name' => 'Corporate tax rate', 'unit' => 'pct', 'platform' => $neutral + $platform['corporateTax'], 'enacted' => $neutral + $politics->corporateTaxPolicyShift],
                ['name' => 'Average tariff on imports', 'unit' => 'pct', 'platform' => $platform['tariff'], 'enacted' => $politics->importTariffRate],
                ['name' => 'Labour force growth', 'unit' => 'pct', 'platform' => $platform['laborGrowth'], 'enacted' => $politics->laborForceGrowthRate],
                ['name' => 'Merger review line', 'unit' => 'hhi', 'platform' => $concentrated($platform['mergerReviewLeniency']), 'enacted' => $concentrated($politics->mergerReviewLeniency)],
            ],
            'record' => $record,
            'recordSummary' => [
                'votes' => count($votes),
                'cabinet' => count(array_filter($votes, static fn(array $vote): bool => $vote['cabinet'])),
                'support' => count(array_filter($votes, static fn(array $vote): bool => $vote['support'])),
                'best' => max(array_column($record, 'seats')),
                'scale' => max(AerieDiet::MAJORITY_SEATS / 2, (int) ceil(max(array_column($record, 'seats')) * 1.15)),
            ],
            'parties' => array_values(array_map(static fn(array $other): array => [
                'key' => $other['key'],
                'name' => $other['name'],
                'slug' => $other['slug'],
                'color' => $other['color'],
            ], $rows)),
            'rules' => ['seats' => AerieDiet::SEATS, 'majority' => AerieDiet::MAJORITY_SEATS, 'termYears' => PoliticsEngine::ELECTION_TERM_YEARS],
        ];
    }

    /**
     * Every party as both pages list it: its seats and vote, its part in the government, its bloc, and where it stands.
     *
     * @param list<DietElection> $history Votes, oldest first.
     * @return list<array<string, mixed>>
     */
    private function partyRows(PoliticsStateDTO $politics, array $history): array
    {
        $seats = array_map('intval', $politics->dietSeats + array_fill_keys(AerieDiet::PARTIES, 0.0));
        $coalition = AerieDiet::governingParties($politics->governingCoalition);
        $support = AerieDiet::governingParties($politics->supportParties);
        $blocs = $politics->dietBlocs;

        $parties = [];
        foreach (AerieDiet::PARTIES as $party) {
            $position = AerieDiet::position($party, $politics->partyPositions);
            $parties[] = [
                'key' => $party,
                'slug' => AeriePartyProfiles::PROFILES[$party]['slug'],
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

        return $parties;
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
     * A party's name as it reads mid-sentence: "the Vanguard", "the Civic Front".
     */
    private static function midSentenceName(string $party): string
    {
        return 'the ' . (preg_replace('/^The /', '', AerieDiet::PARTY_NAMES[$party]) ?? AerieDiet::PARTY_NAMES[$party]);
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
     * The policy space: openness across and size of state up, with the Council axis as a strip beneath. Each party is a
     * dot sized by its seats, with its last few positions fading behind it and its label placed where it crowds nothing.
     * The cabinet is a shaded area over its parties, a ring where it governs from (its parties weighted by seats; a party
     * governing alone is its own), and dotted lines from there to the parties supporting it from outside.
     *
     * @param list<array<string, mixed>>  $parties    Party rows.
     * @param list<DietElection>          $history    Votes, oldest first.
     * @param array{state: float, openness: float, council: float} $government The cabinet's seat-weighted position.
     * @param list<string>                $coalition  The cabinet.
     * @param list<string>                $support    Its support parties.
     * @return array<string, mixed>
     */
    private function compass(array $parties, array $history, array $government, array $coalition, array $support): array
    {
        $scale = self::COMPASS_HALF_WIDTH;
        $margin = self::COMPASS_MARGIN;
        $point = static fn(float $state, float $openness): array => ['x' => round($openness * $scale, 2), 'y' => round(-$state * $scale, 2)];
        [$faintest, $strongest] = self::COMPASS_TRAIL_OPACITY;
        $recent = array_values(array_slice($history, -self::COMPASS_TRAIL_VOTES));

        $dots = [];
        foreach ($parties as $party) {
            $trail = [];
            $councilTrail = [];
            foreach ($recent as $k => $election) {
                if (!isset($election->getPositions()[$party['key']])) {
                    continue;
                }
                $past = AerieDiet::position($party['key'], $election->getPositions());
                $opacity = round($faintest + (($strongest - $faintest) * ($k + 1) / count($recent)), 3);
                $trail[] = $point($past[AerieDiet::AXIS_STATE], $past[AerieDiet::AXIS_OPENNESS]) + ['opacity' => $opacity];
                $councilTrail[] = ['x' => round($past[AerieDiet::AXIS_COUNCIL] * $scale, 2), 'opacity' => $opacity];
            }
            $dots[] = $party + $point($party['state'], $party['openness']) + [
                'r' => 3.0 + ($party['seats'] / 20.0),
                'trail' => $trail,
                'councilX' => round($party['council'] * $scale, 2),
                'councilR' => 2.5 + ($party['seats'] / 25.0),
                'councilTrail' => $councilTrail,
            ];
        }

        $members = array_values(array_filter($dots, static fn(array $dot): bool => in_array($dot['key'], $coalition, true)));
        $alone = count($members) === 1;
        $hub = $alone ? ['x' => $members[0]['x'], 'y' => $members[0]['y']] : $point($government[AerieDiet::AXIS_STATE], $government[AerieDiet::AXIS_OPENNESS]);
        $spokes = [];
        foreach ($dots as $dot) {
            $supporter = in_array($dot['key'], $support, true);
            if ($supporter || (!$alone && in_array($dot['key'], $coalition, true))) {
                $spokes[] = ['x1' => $hub['x'], 'y1' => $hub['y'], 'x2' => $dot['x'], 'y2' => $dot['y'], 'supporter' => $supporter];
            }
        }

        $size = self::COMPASS_FONT_SIZE;
        $axes = [
            ['text' => 'Big state', 'x' => 0.0, 'y' => -$scale - 5.0, 'anchor' => 'middle'],
            ['text' => 'Small state', 'x' => 0.0, 'y' => $scale + 11.0, 'anchor' => 'middle'],
            ['text' => 'Closed', 'x' => -$scale - 4.0, 'y' => 2.0, 'anchor' => 'end'],
            ['text' => 'Open', 'x' => $scale + 4.0, 'y' => 2.0, 'anchor' => 'start'],
        ];
        $taken = array_map(static fn(array $axis): array => self::labelBox($axis['text'], $axis['x'], $axis['y'], $axis['anchor']), $axes);
        if (!$alone && $members !== []) {
            $ring = self::COMPASS_HUB_RADIUS;
            $taken[] = [$hub['x'] - $ring, $hub['y'] - $ring, $hub['x'] + $ring, $hub['y'] + $ring];
        }
        $bounds = [-$scale - $margin, -$scale - 16.0, $scale + $margin, $scale + 16.0];
        $plane = self::placePlaneLabels($dots, $taken, $bounds);
        $strip = self::placeStripLabels($dots);
        foreach ($dots as $i => $dot) {
            $dots[$i] = $dot + $plane[$i] + $strip[$i];
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
            'cabinet' => [
                'halo' => self::convexHull(array_map(static fn(array $dot): array => [$dot['x'], $dot['y']], $members)),
                'pad' => ($members === [] ? 0.0 : max(array_column($members, 'r'))) + self::COMPASS_HALO_PAD,
                'hub' => $hub,
                'ring' => !$alone && $members !== [],
                'hubRadius' => self::COMPASS_HUB_RADIUS,
                'spokes' => $spokes,
            ],
        ];
    }

    /**
     * The convex hull of a few points, in drawing order (Andrew's monotone chain); two points or fewer come back as given.
     *
     * @param list<array{0: float, 1: float}> $points
     * @return list<array{0: float, 1: float}>
     */
    private static function convexHull(array $points): array
    {
        $points = array_values(array_unique($points, SORT_REGULAR));
        if (count($points) < 3) {
            return $points;
        }
        usort($points, static fn(array $a, array $b): int => $a <=> $b);
        $turn = static fn(array $o, array $a, array $b): float => (($a[0] - $o[0]) * ($b[1] - $o[1])) - (($a[1] - $o[1]) * ($b[0] - $o[0]));

        $chain = static function (array $ordered) use ($turn): array {
            $hull = [];
            foreach ($ordered as $p) {
                while (count($hull) >= 2 && $turn($hull[count($hull) - 2], $hull[count($hull) - 1], $p) <= 0.0) {
                    array_pop($hull);
                }
                $hull[] = $p;
            }
            array_pop($hull);

            return $hull;
        };

        return array_merge($chain($points), $chain(array_reverse($points)));
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

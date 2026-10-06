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
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\CoalitionFormation;
use App\Service\Politics\CouncilAppointments;
use App\Service\Politics\ElectionForecast;
use App\Service\Politics\FinancialRegulator;
use App\Service\Politics\MonetaryAuthority;
use App\Service\Politics\PartyLeaders;
use App\Service\Politics\PoliticalPressure;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\SovereignReserveFund;
use App\Twig\Extension\NumberFormatExtension;

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
    /** One colour per party, shared by the hemicycle, the compass and the tables, no two hues close enough to confuse. */
    public const PARTY_COLORS = [
        AerieDiet::CIVIC => '#dc2626',
        AerieDiet::VANGUARD => '#2563eb',
        AerieDiet::IRON_HARBOR => '#78716c',
        AerieDiet::EXCHANGE => '#0891b2',
        AerieDiet::CHARTISTS => '#7c3aed',
        AerieDiet::COMMON_LOT => '#d97706',
        AerieDiet::TIDELINE => '#16a34a',
        AerieDiet::NEW_HORIZON => '#ec4899',
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
        AerieDiet::TIDELINE => 'Tideline',
        AerieDiet::NEW_HORIZON => 'New Horizon',
    ];

    // --- Hemicycle Geometry ---
    /** Each law's name and unit on the market's forecast, in the budget's order. */
    private const LEVER_DISPLAY = [
        'corporateTax' => ['Corporate tax rate', 'pct'],
        'tariff' => ['Average tariff on imports', 'pct'],
        'laborGrowth' => ['Labour force growth', 'pct'],
        'mergerReviewLeniency' => ['Merger review line', 'hhi'],
        'greenBeltStringency' => ['Housing schemes refused', 'pct'],
        'carbonPrice' => ['Carbon price', 'usd_t'],
        'extractionStringency' => ['Extraction compliance cost', 'pct'],
        'stampDutyRate' => ['Stamp duty on share trades', 'pct'],
        'bankLevyRate' => ['Bank levy', 'pct'],
    ];
    /** The polls chart's plot area in its 600 by 210 drawing: left, right, top and bottom edges. */
    private const POLL_CHART_PLOT = [34.0, 590.0, 8.0, 182.0];
    /** Rows of seats in the chamber drawing. */
    private const HEMICYCLE_ROWS = 8;
    /** Innermost row's radius, as a share of the outermost. */
    private const HEMICYCLE_INNER_RADIUS = 0.40;

    // --- Compass Geometry ---
    /** The drawing at each screen width: the half-length of a line running -1 to +1 and the margin either side, in SVG units, and whether the end words sit beside the line (the margin then fits 'Technocratic') or beneath it (half the longest label). A phone gets the shorter line, so its labels stay legible. */
    private const COMPASS_LAYOUTS = [
        'wide' => [150.0, 56.0, true],
        'narrow' => [80.0, 24.0, false],
    ];
    /** The words at either end of each question's line, short enough for the margin. */
    private const COMPASS_ROW_ENDS = [
        AerieDiet::AXIS_STATE => ['Small state', 'Big state'],
        AerieDiet::AXIS_OPENNESS => ['Closed', 'Open'],
        AerieDiet::AXIS_COUNCIL => ['Populist', 'Technocratic'],
        AerieDiet::AXIS_ENVIRONMENT => ['Growth', 'Environment'],
    ];
    /** The levers as a question's heading lists them. */
    private const LEVER_SHORT_NAMES = [
        'corporateTax' => 'corporate tax',
        'tariff' => 'tariffs',
        'laborGrowth' => 'immigration',
        'mergerReviewLeniency' => 'merger review',
        'greenBeltStringency' => 'green belts',
        'carbonPrice' => 'carbon price',
        'extractionStringency' => 'extraction rules',
        'stampDutyRate' => 'stamp duty',
        'bankLevyRate' => 'bank levy',
    ];
    /** Font size of the end words and party labels, in SVG units. */
    private const COMPASS_FONT_SIZE = 7.0;
    /** Advance of a bold label's average character as a share of its font size, to size a label's box. */
    public const COMPASS_CHARACTER_WIDTH = 0.6;
    /** Gap kept between a label and its dot, or another label, in SVG units. */
    private const COMPASS_LABEL_GAP = 1.5;
    /** Least clearance between the outermost dot or ring and a row of labels beyond the label gap, in SVG units. */
    private const COMPASS_LABEL_LIFT = 2.0;
    /** Least rise of a leader line per unit it runs sideways, so a leader never lies along the line. */
    private const COMPASS_LEADER_SLOPE = 0.4;
    /** Gap left between a leader line's ends and the dot or label it joins, in SVG units. */
    private const COMPASS_LEADER_GAP = 0.6;
    /** Radius of the smallest party's dot, in SVG units. */
    private const COMPASS_DOT_RADIUS = 2.5;
    /** Seats that add one SVG unit to a party's dot radius. */
    private const COMPASS_SEATS_PER_RADIUS = 25.0;
    /** Past votes whose positions are drawn behind each party, the oldest faintest. */
    private const COMPASS_TRAIL_VOTES = 6;
    /** Faintest and strongest opacity of a past position, the oldest drawn and the last vote's. */
    private const COMPASS_TRAIL_OPACITY = [0.10, 0.40];
    /** How far the mark where a coalition governs from reaches past the ring of its largest party, in SVG units. */
    private const COMPASS_HUB_PAD = 2.5;
    /** Gap between a party's dot and the ring round it, solid in the cabinet and dotted in support, in SVG units. */
    private const COMPASS_RING_GAP = 1.8;
    /** Margin kept above and below each question's drawing, in SVG units. */
    private const COMPASS_ROW_PAD = 2.0;

    // --- Policy Questions ---
    /** The policy questions as the pages name them: the axis, and its two ends. */
    private const AXIS_NAMES = [
        AerieDiet::AXIS_STATE => ['name' => 'Size of the state', 'low' => 'Small state', 'high' => 'Big state'],
        AerieDiet::AXIS_OPENNESS => ['name' => 'Openness', 'low' => 'Closed', 'high' => 'Open'],
        AerieDiet::AXIS_COUNCIL => ['name' => 'The Council', 'low' => 'Populist', 'high' => 'Technocratic'],
        AerieDiet::AXIS_ENVIRONMENT => ['name' => 'The environment', 'low' => 'Growth first', 'high' => 'Environment first'],
    ];
    /** The questions as the table of positions heads its columns. */
    private const AXIS_COLUMNS = [
        AerieDiet::AXIS_STATE => 'Size of state',
        AerieDiet::AXIS_OPENNESS => 'Openness',
        AerieDiet::AXIS_COUNCIL => 'Council',
        AerieDiet::AXIS_ENVIRONMENT => 'Environment',
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
            'axes' => array_map(static fn(string $axis): array => ['key' => $axis, 'name' => self::AXIS_COLUMNS[$axis]], AerieDiet::AXES),
            'government' => [
                'members' => array_map($member, $coalition),
                'support' => array_map($member, $support),
                'seats' => $coalitionSeats,
                'supportedSeats' => $coalitionSeats + $sumSeats($support),
                'minority' => $coalitionSeats < AerieDiet::MAJORITY_SEATS,
                'formed' => $politics->lastGovernmentFormedAt < 0.0 ? 'Before Year 1' : self::simDate($politics->coalitionFormedAt),
                'range' => CoalitionFormation::ideologicalRange($coalition, $politics->partyPositions),
                'supermajority' => $removalSeats >= AerieDiet::SUPERMAJORITY_SEATS,
                'caretaker' => $talking,
                'primeMinister' => self::primeMinister($politics),
            ] + $coalitionPosition,
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
            'compass' => $this->compass($parties, $history, $coalitionPosition, $coalition),
            'council' => [
                'roster' => array_map(static fn(array $seat): array => $seat + [
                    'name' => self::councillorName($politics, $seat),
                    'sinceLabel' => ($politics->councilSeatedAt[$seat['seat'] - 1] ?? $seat['since']) > $seat['since'] + 1e-9
                        ? self::simDate($politics->councilSeatedAt[$seat['seat'] - 1])
                        : ($seat['beforeYearOne'] ? 'Before Year 1' : self::simDate($seat['since'])),
                    'termEndsLabel' => self::simDate($seat['termEnds']),
                ] + (isset($politics->councilBirths[$seat['seat'] - 1], $politics->councilStances[$seat['seat'] - 1]) ? [
                    'age' => (int) floor($macro->totalTime - $politics->councilBirths[$seat['seat'] - 1]),
                    'stance' => MonetaryAuthority::typeName($politics->councilStances[$seat['seat'] - 1], $politics->councilSwingers[$seat['seat'] - 1] ?? 0.0),
                    'leaning' => self::leaning($politics->councilStances[$seat['seat'] - 1], $politics->councilSwingers[$seat['seat'] - 1] ?? 0.0),
                    'banks' => isset($politics->councilRegulationStances[$seat['seat'] - 1])
                        ? FinancialRegulator::stanceName(FinancialRegulator::requirement($politics->councilRegulationStances[$seat['seat'] - 1]))
                        : null,
                    'reserves' => isset($politics->councilFundStances[$seat['seat'] - 1])
                        ? SovereignReserveFund::stanceName(SovereignReserveFund::equityShare($politics->councilFundStances[$seat['seat'] - 1]))
                        : null,
                ] : []), AerieCouncil::roster($macro->totalTime)),
                'nextVacancy' => (static function (array $seat) use ($politics): array {
                    return $seat + ['name' => self::councillorName($politics, $seat), 'termEndsLabel' => self::simDate($seat['termEnds'])];
                })(AerieCouncil::nextVacancy($macro->totalTime)),
                'board' => $politics->boardMembers === [] ? null : self::stanceCounts(
                    array_map(static fn(array $member): float => $member['stance'], $politics->boardMembers),
                    array_map(static fn(array $member): float => $member['swing'] ?? 0.0, $politics->boardMembers)
                ) + ['seats' => count($politics->boardMembers), 'median' => MonetaryAuthority::stanceName(CouncilAppointments::boardMedians($politics)[CouncilAppointments::AXIS_MONEY])],
                'lean' => $politics->councilStances === [] ? null : self::stanceCounts($politics->councilStances, $politics->councilSwingers) + [
                    'median' => MonetaryAuthority::stanceName(CouncilAppointments::median($politics->councilStances)),
                    'banks' => $politics->councilRegulationStances === [] ? null : FinancialRegulator::requirement(CouncilAppointments::median($politics->councilRegulationStances)),
                    'reserves' => $politics->councilFundStances === [] ? null : SovereignReserveFund::equityShare(CouncilAppointments::median($politics->councilFundStances)),
                ],
                'departments' => AerieCouncil::DEPARTMENTS,
            ],
            'authority' => $this->authority($politics),
            'regulator' => $this->regulator($politics, $macro),
            'fundHead' => $this->fundHead($politics, $macro),
            'history' => array_map(fn(DietElection $election): array => $this->historyRow($election, $macro->totalTime), array_reverse($history)),
            'polls' => self::polls($politics, $nextElection),
            'market' => self::market($politics),
        ];
    }

    /**
     * What the market expects: each party's chance of leading the next government, the likeliest governments, and the
     * laws expected from the first budget of whichever is seated, against those in force.
     *
     * @return array<string, mixed>|null Null before the market has a forecast.
     */
    private static function market(PoliticsStateDTO $politics): ?array
    {
        if ($politics->forecastAt < 0.0 || $politics->forecastLevers === []) {
            return null;
        }

        $leaders = [];
        foreach ($politics->forecastLeaders as $party => $chance) {
            if ($chance >= 0.01) {
                $leaders[] = ['name' => AerieDiet::PARTY_NAMES[$party], 'color' => self::PARTY_COLORS[$party], 'chance' => $chance];
            }
        }
        usort($leaders, static fn(array $a, array $b): int => $b['chance'] <=> $a['chance']);

        $names = static fn(array $parties): array => array_map(static fn(string $party): string => AerieDiet::PARTY_NAMES[$party], $parties);
        $standing = PoliticsEngine::standingLevers($politics);
        $levers = [];
        foreach (self::LEVER_DISPLAY as $lever => [$name, $unit]) {
            $enacted = self::leverDisplay($lever, $standing[$lever]);
            $expected = self::leverDisplay($lever, $politics->forecastLevers[$lever] ?? $standing[$lever]);
            // A move shows only when it shows at the precision the page prints: hundredths of a point, whole HHI points, cents.
            $places = $unit === 'usd_t' ? 2 : 4;
            $levers[] = ['name' => $name, 'unit' => $unit, 'enacted' => $enacted, 'expected' => $expected, 'moves' => round($expected, $places) !== round($enacted, $places)];
        }

        return [
            'talks' => $politics->forecastFor <= $politics->totalTime,
            'vote' => self::simDate($politics->forecastFor),
            'takesEffect' => self::simDate(ElectionForecast::takesEffect($politics)),
            'leaders' => $leaders,
            'cabinets' => array_map(static fn(array $option): array => [
                'cabinet' => $names($option['cabinet']),
                'support' => $names($option['support']),
                'chance' => $option['chance'],
            ], array_slice($politics->forecastCabinets, 0, 4)),
            'levers' => $levers,
        ];
    }

    /** A law's value as the page shows it: the corporate rate itself, the merger line as an HHI, the green belt as schemes refused, the extraction rules as their cost. */
    private static function leverDisplay(string $lever, float $value): float
    {
        return match ($lever) {
            'corporateTax' => MacroEngine::TARGET_CORPORATE_TAX_RATE + $value,
            'mergerReviewLeniency' => MergerAndAcquisitionEngine::reviewScreens($value)['concentrated'],
            'greenBeltStringency' => AssetMarketSubsystem::planningRefusalRate($value),
            'extractionStringency' => self::extractionCostUplift($value),
            default => $value,
        };
    }

    /**
     * The polls since the last vote: each party's line across the term from the result on the day, and the Diet the
     * latest poll would elect.
     *
     * @param float $nextElection When the next vote falls.
     * @return array<string, mixed>|null Null before the term's first poll.
     */
    private static function polls(PoliticsStateDTO $politics, float $nextElection): ?array
    {
        if ($politics->polls === []) {
            return null;
        }

        $start = max(0.0, $politics->lastElectionAt);
        $span = max(1e-9, $nextElection - $start);
        $points = array_merge([['t' => $start, 'shares' => $politics->dietVoteShares]], $politics->polls);
        $latest = $politics->polls[array_key_last($politics->polls)];
        $top = max(array_map(static fn(array $point): float => max($point['shares']), $points));
        $step = $top > 0.3 ? 0.1 : 0.05;
        $scale = ceil(($top + 0.01) / $step) * $step;

        [$left, $right, $topY, $bottom] = self::POLL_CHART_PLOT;
        $x = static fn(float $time): float => round($left + (($right - $left) * ($time - $start) / $span), 1);
        $y = static fn(float $share): float => round($bottom - (($bottom - $topY) * $share / $scale), 1);

        $seats = PoliticsEngine::dHondt($latest['shares'], AerieDiet::SEATS);
        $coalition = AerieDiet::governingParties($politics->governingCoalition);
        $support = AerieDiet::governingParties($politics->supportParties);
        $sumSeats = static fn(array $members): int => array_sum(array_map(static fn(string $party): int => $seats[$party] ?? 0, $members));

        $parties = [];
        foreach (AerieDiet::PARTIES as $party) {
            $parties[] = [
                'name' => AerieDiet::PARTY_NAMES[$party],
                'label' => self::PARTY_LABELS[$party],
                'color' => self::PARTY_COLORS[$party],
                'vote' => $politics->dietVoteShares[$party] ?? 0.0,
                'poll' => $latest['shares'][$party] ?? 0.0,
                'change' => ($latest['shares'][$party] ?? 0.0) - ($politics->dietVoteShares[$party] ?? 0.0),
                'seats' => $seats[$party] ?? 0,
                'line' => implode(' ', array_map(static fn(array $point): string => $x($point['t']) . ',' . $y($point['shares'][$party] ?? 0.0), $points)),
                'last' => ['x' => $x($latest['t']), 'y' => $y($latest['shares'][$party] ?? 0.0)],
            ];
        }
        usort($parties, static fn(array $a, array $b): int => $b['poll'] <=> $a['poll']);

        $gridlines = [];
        for ($share = 0.0; $share <= $scale + 1e-9; $share += $step) {
            $gridlines[] = ['y' => $y($share), 'label' => (string) round($share * 100) . '%'];
        }
        $years = [];
        for ($year = ceil($start); $year <= $nextElection + 1e-9; $year += 1.0) {
            $years[] = ['x' => $x($year), 'label' => sprintf('Year %d', (int) $year + 1)];
        }

        return [
            'since' => $politics->lastElectionAt < 0.0 ? 'Year 1' : self::simDate($politics->lastElectionAt),
            'latest' => self::simDate($latest['t']),
            'count' => count($politics->polls),
            'parties' => $parties,
            'gridlines' => $gridlines,
            'years' => $years,
            'today' => $x($latest['t']),
            'plot' => self::POLL_CHART_PLOT,
            'cabinet' => [
                'seats' => $sumSeats($coalition),
                'supportedSeats' => $sumSeats($coalition) + $sumSeats($support),
                'hasSupport' => $support !== [],
            ],
        ];
    }

    /**
     * A party's page: who it is, where it stands, what it would enact governing alone from there today against what is
     * in force, and its record at the polls since Year 1.
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
                'question' => AeriePartyProfiles::PROFILES[$party]['questions'][$axis],
                'others' => array_values(array_map(static fn(array $other): array => [
                    'label' => $other['label'],
                    'color' => $other['color'],
                    'value' => $other[$axis],
                ], array_filter($rows, static fn(array $other): bool => $other['key'] !== $party))),
            ];
        }

        $record = [['date' => 'Year 1', 'seats' => (int) AerieDiet::SEED_SEATS[$party], 'share' => AerieDiet::SEED_VOTE_SHARES[$party],
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
                ['name' => 'Housing schemes refused', 'unit' => 'pct', 'platform' => AssetMarketSubsystem::planningRefusalRate($platform['greenBeltStringency']), 'enacted' => AssetMarketSubsystem::planningRefusalRate($politics->greenBeltStringency)],
                ['name' => 'Carbon price', 'unit' => 'usd_t', 'platform' => $platform['carbonPrice'], 'enacted' => $politics->carbonPrice],
                ['name' => 'Extraction compliance cost', 'unit' => 'pct', 'platform' => self::extractionCostUplift($platform['extractionStringency']), 'enacted' => self::extractionCostUplift($politics->extractionStringency)],
                ['name' => 'Stamp duty on share trades', 'unit' => 'pct', 'platform' => $platform['stampDutyRate'], 'enacted' => $politics->stampDutyRate],
                ['name' => 'Bank levy', 'unit' => 'pct', 'platform' => $platform['bankLevyRate'], 'enacted' => $politics->bankLevyRate],
            ],
            'leaders' => self::leaders($politics, $party),
            'polling' => $politics->polls === [] ? null : (static function (array $poll) use ($politics, $party): array {
                return [
                    'share' => $poll['shares'][$party] ?? 0.0,
                    'change' => ($poll['shares'][$party] ?? 0.0) - ($politics->dietVoteShares[$party] ?? 0.0),
                    'date' => self::simDate($poll['t']),
                ];
            })($politics->polls[array_key_last($politics->polls)]),
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
                'blocKey' => $blocs[$party],
                'bloc' => self::PARTY_LABELS[$blocs[$party]],
                'blocColor' => self::PARTY_COLORS[$blocs[$party]],
                'partyLeader' => self::leader($politics, $party),
            ] + $position;
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
        $standing = PoliticsEngine::standingLevers($politics);
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
                [
                    'name' => 'Housing schemes refused',
                    'axis' => AerieDiet::AXIS_ENVIRONMENT,
                    'platform' => AssetMarketSubsystem::planningRefusalRate($budget['platform']['greenBeltStringency']),
                    'target' => AssetMarketSubsystem::planningRefusalRate($budget['levers']['greenBeltStringency']),
                    'enacted' => AssetMarketSubsystem::planningRefusalRate($standing['greenBeltStringency']),
                    'status' => $status('greenBeltStringency'),
                    'note' => $standing['greenBeltStringency'] > 0.0
                        ? sprintf('Builders answer a rise in prices only %.0f%% as strongly as under the plan in force at Year 1', 100.0 * AssetMarketSubsystem::greenBeltSupplyResponse($standing['greenBeltStringency']))
                        : 'The plan in force at Year 1 still stands',
                ],
                [
                    'name' => 'Carbon price',
                    'axis' => AerieDiet::AXIS_ENVIRONMENT,
                    'unit' => 'usd_t',
                    'platform' => $budget['platform']['carbonPrice'],
                    'target' => $budget['levers']['carbonPrice'],
                    'enacted' => $standing['carbonPrice'],
                    'status' => $status('carbonPrice'),
                    'note' => $standing['carbonPrice'] > 0.0
                        ? sprintf('Adds $%.2f to each MWh of wholesale power', CommodityLogisticsSubsystem::carbonPowerPriceAdder($standing['carbonPrice']))
                        : 'No carbon price is in force; one would add to each MWh of wholesale power',
                ],
                [
                    'name' => 'Extraction compliance cost',
                    'axis' => AerieDiet::AXIS_ENVIRONMENT,
                    'platform' => self::extractionCostUplift($budget['platform']['extractionStringency']),
                    'target' => self::extractionCostUplift($budget['levers']['extractionStringency']),
                    'enacted' => self::extractionCostUplift($standing['extractionStringency']),
                    'status' => $status('extractionStringency'),
                    'note' => $standing['extractionStringency'] > 0.0
                        ? 'Added to the cost of every barrel lifted offshore and every tonne mined'
                        : 'The rules on offshore drilling and mining in force at Year 1 still stand',
                ],
                [
                    'name' => 'Stamp duty on share trades',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $budget['platform']['stampDutyRate'],
                    'target' => $budget['levers']['stampDutyRate'],
                    'enacted' => $standing['stampDutyRate'],
                    'status' => $status('stampDutyRate'),
                    'note' => sprintf('Paid by buyer and seller each, into the Sovereign Reserve Fund; turnover runs %.0f%% %s its level at the rate in force at Year 1',
                        abs(100.0 * (MathUtility::calculateStampDutyVolumeFactor($standing['stampDutyRate']) - 1.0)),
                        MathUtility::calculateStampDutyVolumeFactor($standing['stampDutyRate']) < 1.0 ? 'below' : 'above'),
                ],
                [
                    'name' => 'Bank levy',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $budget['platform']['bankLevyRate'],
                    'target' => $budget['levers']['bankLevyRate'],
                    'enacted' => $standing['bankLevyRate'],
                    'status' => $status('bankLevyRate'),
                    'note' => $macro->boardBankLevy > 0.0
                        ? sprintf('On banks\' short-term funding, half that on long-term funding and uninsured deposits; the banks owe %s a year', (new NumberFormatExtension())->formatLargeNumber($macro->boardBankLevy, '$'))
                        : 'On banks\' short-term funding, half that on long-term funding and uninsured deposits',
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
     * What the rules on extraction add to the cost of each barrel or tonne, as a fraction.
     *
     * @param float $stringency The rules, 0 to 1.
     */
    private static function extractionCostUplift(float $stringency): float
    {
        return MathUtility::getInstance()->calculateProductivityLossCostFactor(FinancialConstants::ENVIRONMENTAL_REGULATION_TFP_LOSS * $stringency) - 1.0;
    }

    /**
     * A party's name as it reads mid-sentence: "the Vanguard", "the Civic Front".
     */
    /**
     * The prime minister: the leader of the cabinet's largest party. Null before the leaders are first drawn.
     *
     * @return array{name: string, age: int, sinceLabel: string, party: string}|null
     */
    private static function primeMinister(PoliticsStateDTO $politics): ?array
    {
        $party = PartyLeaders::primeMinisterParty($politics);
        $leader = $party === null ? null : self::leader($politics, $party);

        return $leader === null || $party === null ? null : $leader + ['party' => self::midSentenceName($party)];
    }

    /**
     * A party's leader: their name, age, and since when they have led it. Null before the leaders are first drawn.
     *
     * @return array{name: string, age: int, sinceLabel: string}|null
     */
    private static function leader(PoliticsStateDTO $politics, string $party): ?array
    {
        if (!isset($politics->leaderNames[$party], $politics->leaderBirths[$party], $politics->leaderSince[$party])) {
            return null;
        }

        return [
            'name' => $politics->leaderNames[$party],
            'age' => (int) floor($politics->totalTime - $politics->leaderBirths[$party]),
            'sinceLabel' => $politics->leaderSince[$party] < 0.0 ? 'Before Year 1' : self::simDate($politics->leaderSince[$party]),
        ];
    }

    /**
     * Everyone who has led a party since the leaders were first drawn, the present leader first.
     *
     * @return list<array{name: string, fromLabel: string, toLabel: string, current: bool}>
     */
    private static function leaders(PoliticsStateDTO $politics, string $party): array
    {
        $label = static fn(float $time): string => $time < 0.0 ? 'Before Year 1' : self::simDate($time);
        $leaders = [];
        if (isset($politics->leaderNames[$party], $politics->leaderSince[$party])) {
            $leaders[] = ['name' => $politics->leaderNames[$party], 'fromLabel' => $label($politics->leaderSince[$party]), 'toLabel' => 'today', 'current' => true];
        }
        foreach (array_reverse($politics->leaderHistory[$party] ?? []) as $former) {
            $leaders[] = ['name' => $former['name'], 'fromLabel' => $label($former['since']), 'toLabel' => $label($former['until']), 'current' => false];
        }

        return $leaders;
    }

    private static function midSentenceName(string $party): string
    {
        return 'the ' . (preg_replace('/^The /', '', AerieDiet::PARTY_NAMES[$party]) ?? AerieDiet::PARTY_NAMES[$party]);
    }

    /**
     * A simulation time as the page names it: the year, counted from Year 1 when the District's records begin, and the quarter.
     * The time is read to the microyear, so a vote the accumulated clock puts a hair short of the term's end is dated on it.
     */
    public static function simDate(float $simTime): string
    {
        $simTime = round($simTime, 6);
        $year = (int) floor($simTime);
        $quarter = (int) floor(($simTime - $year) * 4.0 + 1e-9) + 1;

        return sprintf('Year %d Q%d', $year + 1, min(4, $quarter));
    }

    /**
     * The seats of the chamber drawing, bloc by bloc, each running from the party wanting the largest state to the smallest.
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

        // Each bloc sits together, the one wanting the larger state on the left; within a bloc, the larger state leftmost.
        $blocState = [];
        foreach ($parties as $party) {
            $blocState[$party['blocKey']][] = [$seats[$party['key']], $party['state']];
        }
        $blocState = array_map(static function (array $members): float {
            $weight = array_sum(array_column($members, 0));

            return $weight > 0
                ? array_sum(array_map(static fn(array $member): float => $member[0] * $member[1], $members)) / $weight
                : array_sum(array_column($members, 1)) / count($members);
        }, $blocState);

        $order = $parties;
        usort($order, static fn(array $a, array $b): int => [$blocState[$b['blocKey']], $a['blocKey'], $b['state']]
            <=> [$blocState[$a['blocKey']], $b['blocKey'], $a['state']]);

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
     * Where the parties stand: one line per question, from its small-state, closed, populist or growth end on the left,
     * drawn at each width the page offers. Each party is a dot sized by its seats with its last few positions fading
     * behind it, and its label set above or below where it crowds nothing, a leader line joining any label moved
     * sideways off its dot. The cabinet's parties are ringed solid and its supporters dotted; a coalition also has a
     * mark across the line where it governs from (its parties weighted by seats), drawn behind the dots so it hides none
     * of them.
     *
     * @param list<array<string, mixed>>  $parties    Party rows.
     * @param list<DietElection>          $history    Votes, oldest first.
     * @param array{state: float, openness: float, council: float, environment: float} $government The cabinet's seat-weighted position.
     * @param list<string>                $coalition  The cabinet.
     * @return array<string, mixed>
     */
    private function compass(array $parties, array $history, array $government, array $coalition): array
    {
        [$faintest, $strongest] = self::COMPASS_TRAIL_OPACITY;
        $recent = array_values(array_slice($history, -self::COMPASS_TRAIL_VOTES));

        $dots = [];
        foreach ($parties as $party) {
            $past = [];
            foreach ($recent as $k => $election) {
                if (isset($election->getPositions()[$party['key']])) {
                    $past[] = [
                        'position' => AerieDiet::position($party['key'], $election->getPositions()),
                        'opacity' => round($faintest + (($strongest - $faintest) * ($k + 1) / count($recent)), 3),
                    ];
                }
            }
            $dots[] = $party + ['r' => self::COMPASS_DOT_RADIUS + ($party['seats'] / self::COMPASS_SEATS_PER_RADIUS), 'past' => $past];
        }

        $members = array_values(array_filter($dots, static fn(array $dot): bool => in_array($dot['key'], $coalition, true)));
        $coalitionMark = count($members) > 1;
        $hubReach = ($members === [] ? 0.0 : max(array_column($members, 'r'))) + self::COMPASS_RING_GAP + self::COMPASS_HUB_PAD;
        $outermost = max(array_map(static fn(array $dot): float => $dot['r'] + ($dot['governing'] || $dot['supporting'] ? self::COMPASS_RING_GAP : 0.0), $dots));

        $layouts = [];
        foreach (self::COMPASS_LAYOUTS as $name => [$scale, $margin, $endsBeside]) {
            $rows = [];
            foreach (AerieDiet::AXES as $axis) {
                $rows[] = self::compassRow($axis, $dots, $coalitionMark ? $government[$axis] : null, $scale, $margin, $endsBeside, $hubReach, $outermost);
            }
            $layouts[$name] = [
                'halfWidth' => $scale,
                'endsBeside' => $endsBeside,
                // How far in from the drawing's edge the line ends, so end words set beneath it line up with its ends.
                'endInset' => round(100.0 * $margin / (2.0 * ($scale + $margin)), 2),
                'rows' => $rows,
            ];
        }

        return [
            'fontSize' => self::COMPASS_FONT_SIZE,
            'hubReach' => $hubReach,
            'ringGap' => self::COMPASS_RING_GAP,
            'coalitionMark' => $coalitionMark,
            'layouts' => $layouts,
        ];
    }

    /**
     * One question's line at one width: every party placed and labelled, the mark where a coalition governs from, and
     * the drawing's extent.
     *
     * @param list<array<string, mixed>> $dots     The parties, with their radius and past positions.
     * @param float|null                 $hub      Where the cabinet governs from on this question; null when one party governs alone.
     * @param float                      $hubReach How far the mark reaches either side of the line, in SVG units.
     * @return array<string, mixed>
     */
    private static function compassRow(string $axis, array $dots, ?float $hub, float $scale, float $margin, bool $endsBeside, float $hubReach, float $outermost): array
    {
        $size = self::COMPASS_FONT_SIZE;
        [$low, $high] = self::COMPASS_ROW_ENDS[$axis];
        $signed = static fn(float $value): string => sprintf('%+.2f', $value);

        $placed = [];
        foreach ($dots as $i => $dot) {
            $placed[$i] = $dot + [
                'x' => round($dot[$axis] * $scale, 2),
                'trail' => array_map(static fn(array $past): array => ['x' => round($past['position'][$axis] * $scale, 2), 'opacity' => $past['opacity']], $dot['past']),
            ];
            unset($placed[$i]['past']);
        }

        $boxes = [];
        foreach (self::placeRowLabels($placed, $outermost, $scale, $margin, $endsBeside) as $i => $label) {
            $placed[$i] += $label;
            $boxes[] = self::labelBox($placed[$i]['label'], $label['labelX'], $label['labelY'], 'middle');
        }

        $reach = max($outermost, $hub === null ? 0.0 : $hubReach);
        $top = min(-$reach, ...array_column($boxes, 1)) - self::COMPASS_ROW_PAD;
        $bottom = max($reach, ...array_column($boxes, 3)) + self::COMPASS_ROW_PAD;

        return [
            'axis' => $axis,
            'name' => self::AXIS_NAMES[$axis]['name'],
            'laws' => array_map(static fn(string $lever): string => self::LEVER_SHORT_NAMES[$lever], array_keys(PoliticsEngine::LEVER_AXES, $axis, true)),
            'summary' => sprintf('%s, from %s on the left to %s on the right: %s%s', self::AXIS_NAMES[$axis]['name'], strtolower($low), strtolower($high),
                implode(', ', array_map(static fn(array $dot): string => $dot['name'] . ($dot['governing'] ? ' (in the cabinet)' : ($dot['supporting'] ? ' (supporting it)' : '')) . ' at ' . $signed($dot[$axis]), $dots)),
                $hub === null ? '' : '; the cabinet at ' . $signed($hub)),
            'ends' => [
                ['text' => $low, 'x' => -$scale - 4.0, 'y' => 0.35 * $size, 'anchor' => 'end'],
                ['text' => $high, 'x' => $scale + 4.0, 'y' => 0.35 * $size, 'anchor' => 'start'],
            ],
            'hub' => $hub === null ? null : round($hub * $scale, 2),
            'parties' => array_values($placed),
            'viewBox' => [-$scale - $margin, round($top, 2), 2.0 * ($scale + $margin), round($bottom - $top, 2)],
        ];
    }

    /**
     * Where each party's label goes on a question's line: in one row above the line or one below, the larger parties
     * choosing first and taking the row above unless a label already there is in the way, the side with less in the
     * way when both are crowded; a row too long for its labels sends its smallest parties across where they fit. Each
     * row is spread so no label crowds the next, and set back from the line far enough that the leader joining a dot
     * to a label moved sideways off it rises at least COMPASS_LEADER_SLOPE. No label is centred past the end of its
     * line when the end words sit beside it, so no leader crosses them.
     *
     * @param array<int, array<string, mixed>> $dots       The parties, with their place on the line, radius, label and part in the government.
     * @param float                            $outermost  Furthest any dot or ring reaches from the line, in SVG units.
     * @param float                            $scale      Half the line's length, in SVG units.
     * @param float                            $margin     The drawing's margin either side of the line, in SVG units.
     * @param bool                             $endsBeside Whether the end words sit beside the line's ends.
     * @return array<int, array{labelX: float, labelY: float, leader: list<float>|null}> By the dots' index.
     */
    private static function placeRowLabels(array $dots, float $outermost, float $scale, float $margin, bool $endsBeside): array
    {
        $gap = self::COMPASS_LABEL_GAP;
        $size = self::COMPASS_FONT_SIZE;
        $order = self::bySeats($dots);

        // Each label wants to sit centred over its dot; its own centre stays where its edge is inside the drawing, and
        // short of the line's end when the end words sit beside it.
        $items = [];
        foreach ($order as $i) {
            $width = self::labelWidth($dots[$i]['label']);
            $limit = $endsBeside ? $scale : $scale + $margin - ($width / 2.0);
            $items[$i] = ['centre' => (float) $dots[$i]['x'], 'width' => $width, 'lo' => -$limit, 'hi' => $limit];
        }
        $inTheWay = static function (array $item, array $row) use ($gap): float {
            $crowding = 0.0;
            foreach ($row as $other) {
                $crowding += max(0.0, ($item['width'] + $other['width']) / 2.0 + $gap - abs($item['centre'] - $other['centre']));
            }

            return $crowding;
        };
        // Whether a row's labels fit, in order, between the limits of its first and its last.
        $fits = static function (array $row) use ($gap): bool {
            if ($row === []) {
                return true;
            }
            uasort($row, static fn(array $a, array $b): int => $a['centre'] <=> $b['centre']);
            $first = $row[array_key_first($row)];
            $last = $row[array_key_last($row)];

            return array_sum(array_column($row, 'width')) + ($gap * (count($row) - 1))
                <= ($last['hi'] + ($last['width'] / 2.0)) - ($first['lo'] - ($first['width'] / 2.0));
        };

        // -1 is the row above the line, +1 the row below.
        $rows = [-1 => [], 1 => []];
        foreach ($order as $i) {
            $above = $inTheWay($items[$i], $rows[-1]);
            $rows[$above <= 0.0 || $above <= $inTheWay($items[$i], $rows[1]) ? -1 : 1][$i] = $items[$i];
        }
        foreach ([-1, 1] as $side) {
            foreach (array_reverse($order) as $i) {
                if ($fits($rows[$side])) {
                    break;
                }
                if (isset($rows[$side][$i]) && $fits($rows[-$side] + [$i => $rows[$side][$i]])) {
                    $rows[-$side][$i] = $rows[$side][$i];
                    unset($rows[$side][$i]);
                }
            }
        }

        $labels = [];
        foreach ($rows as $side => $row) {
            uasort($row, static fn(array $a, array $b): int => $a['centre'] <=> $b['centre']);
            $centres = self::packRow($row);
            $lift = self::COMPASS_LABEL_LIFT;
            $moved = [];
            foreach ($centres as $i => $centre) {
                $shift = abs($centre - $row[$i]['centre']);
                if ($shift > $row[$i]['width'] / 4.0) {
                    $moved[$i] = true;
                    $lift = max($lift, self::COMPASS_LEADER_SLOPE * $shift);
                }
            }
            // The labels' edge nearest the line, and their baseline.
            $edge = $side * ($outermost + $gap + $lift);
            $baseline = $side < 0 ? $edge - (0.2 * $size) : $edge + (0.8 * $size);
            foreach ($centres as $i => $centre) {
                $leader = null;
                if (isset($moved[$i])) {
                    $x = $row[$i]['centre'];
                    $endY = $edge - ($side * self::COMPASS_LEADER_GAP);
                    $length = hypot($centre - $x, $endY);
                    $reach = $dots[$i]['r'] + ($dots[$i]['governing'] || $dots[$i]['supporting'] ? self::COMPASS_RING_GAP : 0.0) + self::COMPASS_LEADER_GAP;
                    $leader = [round($x + (($centre - $x) * $reach / $length), 2), round($endY * $reach / $length, 2), round($centre, 2), round($endY, 2)];
                }
                $labels[$i] = ['labelX' => round($centre, 2), 'labelY' => round($baseline, 2), 'leader' => $leader];
            }
        }

        return $labels;
    }

    /**
     * Spreads a row of labels so none comes within the label gap of the next, each as near its wanted centre as the
     * others allow: labels that would crowd each other are merged into a run set where its labels' wanted centres put
     * it on average (least squares), held within every label's limits, and runs that then crowd merge in turn.
     *
     * @param array<int, array{centre: float, width: float, lo: float, hi: float}> $items Labels in order of their wanted centres, each with the range its centre may take.
     * @return array<int, float> Each label's centre.
     */
    private static function packRow(array $items): array
    {
        $gap = self::COMPASS_LABEL_GAP;
        $left = static fn(array $run): float => max($run['lo'], min($run['hi'], $run['sum'] / count($run['keys'])));
        // A run's 'sum' adds each label's wanted left edge less its offset in the run, so its best left edge is their
        // mean; 'lo' and 'hi' bound that edge so every label keeps within its own limits.
        $runs = [];
        foreach ($items as $key => $item) {
            $half = $item['width'] / 2.0;
            $run = ['keys' => [$key], 'width' => $item['width'], 'sum' => $item['centre'] - $half, 'lo' => $item['lo'] - $half, 'hi' => $item['hi'] - $half];
            while ($runs !== [] && $left($runs[array_key_last($runs)]) + $runs[array_key_last($runs)]['width'] + $gap > $left($run)) {
                $last = array_pop($runs);
                $offset = $last['width'] + $gap;
                $run = [
                    'keys' => [...$last['keys'], ...$run['keys']],
                    'width' => $offset + $run['width'],
                    'sum' => $last['sum'] + $run['sum'] - ($offset * count($run['keys'])),
                    'lo' => max($last['lo'], $run['lo'] - $offset),
                    'hi' => min($last['hi'], $run['hi'] - $offset),
                ];
            }
            $runs[] = $run;
        }

        $centres = [];
        foreach ($runs as $run) {
            $at = $left($run);
            foreach ($run['keys'] as $key) {
                $centres[$key] = $at + ($items[$key]['width'] / 2.0);
                $at += $items[$key]['width'] + $gap;
            }
        }

        return $centres;
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
     * The Monetary Authority as the page shows it: the governor the Council named and the candidates it passed over, the
     * committee the governor picked, each with their age, stance, seat dates and last vote; the committee's make-up and
     * the supermajority it holds; and the last rate meeting. Null before the Authority has formed.
     *
     * @return array<string, mixed>|null
     */
    private function authority(PoliticsStateDTO $politics): ?array
    {
        if ($politics->authoritySalt < 0.0) {
            return null;
        }

        $time = $politics->totalTime;
        $votes = $politics->lastMeetingVotes;
        $seated = static fn(float $since): string => $since < 0.0 ? 'Before Year 1' : self::simDate($since);
        $person = static fn(string $name, float $birth, float $stance, float $swing, ?float $vote): array => [
            'name' => $name,
            'age' => (int) floor($time - $birth),
            'stance' => MonetaryAuthority::typeName($stance, $swing),
            'leaning' => self::leaning($stance, $swing),
            'vote' => $vote,
        ];

        $members = [];
        foreach ($politics->memberNames as $member => $name) {
            $since = $politics->memberSince[$member] ?? 0.0;
            $members[] = $person($name, $politics->memberBirths[$member] ?? $time, $politics->memberStances[$member] ?? 0.0, $politics->memberSwingers[$member] ?? 0.0, $votes[$member + 1] ?? null) + [
                'sinceLabel' => $seated($since),
                'termEndsLabel' => self::simDate($since + MonetaryAuthority::MEMBER_TERM_YEARS),
            ];
        }
        $higher = count(array_filter($votes, static fn(float $vote): bool => $vote > 0.0));
        $lower = count(array_filter($votes, static fn(float $vote): bool => $vote < 0.0));

        return [
            'governor' => $person($politics->governorName, $politics->governorBirth, $politics->governorStance, max(0.0, $politics->governorSwinger), $votes[0] ?? null) + [
                'sinceLabel' => $seated($politics->governorTermStart),
                'termEndsLabel' => self::simDate(MonetaryAuthority::governorTermEnd($time)),
                'passedOver' => array_map(static fn(array $candidate): array => $person($candidate['name'], $candidate['birth'], $candidate['stance'], $candidate['swing'] ?? 0.0, null), $politics->governorPassedOver),
            ],
            'members' => $members,
            'committee' => self::stanceCounts(array_merge([$politics->governorStance], $politics->memberStances), array_merge([max(0.0, $politics->governorSwinger)], array_values($politics->memberSwingers))) + [
                'balance' => $politics->committeeBalance,
                'majority' => $politics->committeeMajority > 0.0 ? 'hawkish' : ($politics->committeeMajority < 0.0 ? 'dovish' : null),
            ],
            'pressure' => $politics->pressureSince < 0.0 ? null : [
                'sinceLabel' => self::simDate($politics->pressureSince),
                'givingIn' => PoliticalPressure::concession($politics) > 0.0,
                'cabinet' => array_map(static fn(string $party): string => AerieDiet::PARTY_NAMES[$party], AerieDiet::governingParties($politics->governingCoalition)),
            ],
            'meeting' => $politics->lastMeetingAt < 0.0 ? null : [
                'date' => self::simDate($politics->lastMeetingAt),
                'rate' => $politics->lastMeetingRate,
                'change' => $politics->lastMeetingChange,
                'split' => (count($votes) - $higher - $lower) . '–' . ($higher + $lower),
                'higher' => $higher,
                'lower' => $lower,
            ],
            'rules' => [
                'governorTermYears' => MonetaryAuthority::GOVERNOR_TERM_YEARS,
                'memberTermYears' => MonetaryAuthority::MEMBER_TERM_YEARS,
                'members' => MonetaryAuthority::COMMITTEE_MEMBERS + 1,
                'meetingsPerYear' => MonetaryAuthority::MEETINGS_PER_YEAR,
                'shortlist' => CouncilAppointments::SHORTLIST,
            ],
        ];
    }

    /**
     * The Sovereign Reserve Fund's head: who they are, their stance on the reserves and the equity share they set, whom
     * the Council passed over for them with the share each would have set, and the fund's mix in force. Null before the
     * fund has a head.
     *
     * @return array<string, mixed>|null
     */
    private function fundHead(PoliticsStateDTO $politics, MacroStateDTO $macro): ?array
    {
        if ($politics->fundHeadName === '') {
            return null;
        }

        $time = $politics->totalTime;
        $share = SovereignReserveFund::equityShare($politics->fundHeadStance);

        return [
            'name' => $politics->fundHeadName,
            'age' => (int) floor($time - $politics->fundHeadBirth),
            'sinceLabel' => $politics->fundHeadTermStart < 0.0 ? 'Before Year 1' : self::simDate($politics->fundHeadTermStart),
            'termEndsLabel' => self::simDate(SovereignReserveFund::headTermEnd($time)),
            'opening' => $politics->fundHeadTermStart < 0.0,
            'stance' => SovereignReserveFund::stanceName($share),
            'equityShare' => $share,
            'inForce' => $macro->sovereignFundPolicyEquityShare > 0.0 ? $macro->sovereignFundPolicyEquityShare : null,
            'passedOver' => array_map(static fn(array $candidate): array => [
                'name' => $candidate['name'],
                'age' => (int) floor($politics->fundHeadTermStart - $candidate['birth']),
                'equityShare' => SovereignReserveFund::equityShare($candidate['fund']),
            ], $politics->fundHeadPassedOver),
            'rules' => [
                'termYears' => SovereignReserveFund::HEAD_TERM_YEARS,
                'shortlist' => CouncilAppointments::SHORTLIST,
                'mostCautious' => SovereignReserveFund::mostCautious(),
                'boldest' => SovereignReserveFund::boldest(),
            ],
        ];
    }

    /**
     * The Financial Regulator: its head, their stance on the banks and whom the Council passed over for them; the core
     * capital requirement in force and, while a rise phases in, the one it is rising to; the countercyclical buffer on
     * top; and the range of regimes a head may run. Null before the Regulator has a head.
     *
     * @return array<string, mixed>|null
     */
    private function regulator(PoliticsStateDTO $politics, MacroStateDTO $macro): ?array
    {
        if ($politics->regulatorName === '') {
            return null;
        }

        $time = $politics->totalTime;
        $target = FinancialRegulator::requirement($politics->regulatorStance);

        return [
            'head' => [
                'name' => $politics->regulatorName,
                'age' => (int) floor($time - $politics->regulatorBirth),
                'sinceLabel' => $politics->regulatorTermStart < 0.0 ? 'Before Year 1' : self::simDate($politics->regulatorTermStart),
                'termEndsLabel' => self::simDate(FinancialRegulator::headTermEnd($time)),
                'stance' => FinancialRegulator::stanceName($target),
                'requirement' => $target,
                'passedOver' => array_map(static fn(array $candidate): array => [
                    'name' => $candidate['name'],
                    'age' => (int) floor($politics->regulatorTermStart - $candidate['birth']),
                    'requirement' => FinancialRegulator::requirement($candidate['regulation']),
                ], $politics->regulatorPassedOver),
            ],
            'inForce' => $politics->bankCapitalRequirement,
            'buffer' => $macro->countercyclicalBufferRateEma,
            'phasing' => $target > $politics->bankCapitalRequirement + 1e-9 ? [
                'target' => $target,
                'completeLabel' => self::simDate($politics->requirementPhaseStart + FinancialRegulator::PHASE_IN_YEARS),
            ] : null,
            'rules' => [
                'termYears' => FinancialRegulator::HEAD_TERM_YEARS,
                'shortlist' => CouncilAppointments::SHORTLIST,
                'lightest' => FinancialRegulator::lightest(),
                'strictest' => FinancialRegulator::strictest(),
                'phaseInMonths' => (int) round(FinancialRegulator::PHASE_IN_YEARS * 12),
            ],
        ];
    }

    /**
     * How many of a group are hawks, swing votes and doves.
     *
     * @param array<int, float> $stances
     * @param array<int, float> $swingers Whether each is a swing vote, in the same order.
     * @return array{hawk: int, swing: int, dove: int}
     */
    private static function stanceCounts(array $stances, array $swingers = []): array
    {
        $counts = ['hawk' => 0, 'swing' => 0, 'dove' => 0];
        foreach ($stances as $key => $stance) {
            ++$counts[MonetaryAuthority::typeName($stance, $swingers[$key] ?? 0.0)];
        }

        return $counts;
    }

    /** The camp a swing vote leans to now, 'hawk' or 'dove'; null for a hawk or a dove, or a swing vote with no lean yet. */
    private static function leaning(float $stance, float $swing): ?string
    {
        return $swing > 0.0 && $stance !== 0.0 ? MonetaryAuthority::stanceName($stance) : null;
    }

    /**
     * Who holds a Council seat: the politics state's record once the Authority has formed, else the councillor seated
     * before Year 1.
     *
     * @param array{seat: int, beforeYearOne: bool} $seat
     */
    private static function councillorName(PoliticsStateDTO $politics, array $seat): string
    {
        return $politics->councilNames[$seat['seat'] - 1] ?? ($seat['beforeYearOne'] ? AerieCouncil::OPENING_MEMBERS[$seat['seat'] - 1] : '');
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

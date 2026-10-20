<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\District\DistrictCalendar;
use App\Data\District\Institutions;
use App\Data\Politics\AerieCouncil;
use App\Data\Politics\AerieDiet;
use App\Data\Politics\AeriePartyProfiles;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Repository\ElectionOddsRepository;
use App\Repository\MacroReportHistoryRepository;
use App\Repository\RateDecisionRepository;
use App\Repository\StockRepository;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\Valuation;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SovereignReserveFund;
use App\Service\Politics\Diet\CoalitionFormation;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\ElectionForecast;
use App\Service\Politics\Diet\PartyLeaders;
use App\Service\Politics\Policy\BudgetRound;
use App\Service\Politics\Policy\Platforms;
use App\Service\Politics\PoliticsEngine;
use App\Twig\Extension\NumberFormatExtension;

/**
 * Builds the government's pages: the front page with a headline from each institution, the Diet (the chamber, the talks
 * after a vote, the parties in the policy space, every vote on record), the coming election, the budget and its laws,
 * the Council, the Monetary Authority, and a page for each party.
 *
 * The talks are settled on the day of the vote; the page shows only the attempts whose day has passed, and no cabinet
 * before it takes office.
 *
 * The live Diet is read off the politics snapshot (PoliticsEngine keeps it every tick), and the debt, the rate firms
 * pay and the policy uncertainty off the macro snapshot; the history and the parties' past positions off diet_election.
 * The chamber and the compass are drawn by DietChamberDrawing, the rate, odds and poll charts by GovernmentCharts, and
 * the departments' headlines come from DepartmentPageBuilder.
 */
class GovernmentPageBuilder
{
    // --- Laws ---
    /** Each law's name and unit on the market's forecast and the law charts, in the budget's order. */
    private const LEVER_DISPLAY = [
        'publicSpending' => ['Public spending, share of GDP', 'pct'],
        'corporateTax' => ['Corporate tax rate', 'pct'],
        'dividendTax' => ['Tax on dividends', 'pct'],
        'tariff' => ['Average tariff on imports', 'pct'],
        'laborGrowth' => ['Labour force growth', 'pct'],
        'foreignBuyerTax' => ['Tax on foreign home buyers', 'pct'],
        'greenBeltStringency' => ['Housing schemes refused', 'pct'],
        'carbonPrice' => ['Carbon price', 'usd_t'],
        'extractionStringency' => ['Extraction compliance cost', 'pct'],
        'stampDutyRate' => ['Stamp duty on share trades', 'pct'],
        'bankLevyRate' => ['Bank levy', 'pct'],
        'reserveDrawShare' => ['Share of reserve returns spent', 'pct'],
        'consolidationTaxShare' => ['Debt repair raised by tax', 'pct'],
        'reserveHomeShare' => ['Reserve fund shares held at home', 'pct'],
        'utilityRateGrant' => ['Utility rate requests granted', 'pct'],
        'debtTarget' => ['Debt target', 'pct'],
        'debtPace' => ['Debt cut a year', 'pct'],
    ];
    /**
     * The laws the Council's departments set that the law charts show beside the Diet's, keyed as each recorded quarter
     * carries them.
     */
    private const DEPARTMENT_LAWS = [
        'mergerReviewLeniency' => ['Merger review line', 'hhi'],
        'capitalRequirement' => ['Core capital requirement', 'pct'],
    ];

    // --- Law History Charts ---
    /** Each law's chart plot area in its 300 by 84 drawing: left, right, top and bottom edges. */
    private const LAW_CHART_PLOT = [52.0, 296.0, 6.0, 66.0];
    /**
     * Least span of a law chart's value axis by unit, so a law that barely moved is not stretched across the chart:
     * half a point, 100 HHI points, $5 a tonne.
     */
    private const LAW_CHART_MIN_SPAN = ['pct' => 0.005, 'hhi' => 0.01, 'usd_t' => 5.0];
    /** Most year labels under a law chart's axis. */
    private const LAW_CHART_YEAR_LABELS = 5;

    // --- Election Odds ---
    /**
     * How near a forecast's vote must fall to the coming vote's day to count as a forecast of it, in years: a vote is
     * held within a tick of its day, and the next is a term away.
     */
    private const ODDS_VOTE_TOLERANCE = 0.25;

    // --- Policy Questions ---
    /** The questions as the table of positions heads its columns. */
    private const AXIS_COLUMNS = [
        AerieDiet::AXIS_STATE => 'Size of state',
        AerieDiet::AXIS_OPENNESS => 'Openness',
        AerieDiet::AXIS_COUNCIL => 'Council',
        AerieDiet::AXIS_ENVIRONMENT => 'Environment',
    ];

    public function __construct(
        private readonly DietElectionRepository $elections,
        private readonly ?MacroReportHistoryRepository $reports = null,
        private readonly ?StockRepository $stocks = null,
        private readonly ?RateDecisionRepository $decisions = null,
        private readonly ?ElectionOddsRepository $odds = null,
        private readonly DepartmentPageBuilder $departments = new DepartmentPageBuilder(),
    ) {}

    /**
     * The government's front page: the cabinet and the next vote, and one headline from each institution with a link to
     * its own page.
     *
     * @return array<string, mixed>
     */
    public function buildHub(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        $frame = $this->frame($macro, $politics);
        $context = $frame['context'];

        return array_replace($frame['page'], [
            'talks' => $this->talks($politics, $context['talking']),
            'parties' => $context['parties'],
            'hemicycle' => DietChamberDrawing::hemicycle($context['seats'], $context['parties']),
            'budget' => $this->budget($macro, $politics, $context['coalition'], $context['support'], $context['talking']),
            'polls' => GovernmentCharts::polls($politics, $context['nextElection']),
            'market' => self::market($politics),
            'council' => $frame['page']['council'] + ['lean' => self::councilLean($politics)],
            'authority' => $this->authority($politics, false),
            'regulator' => $this->departments->regulator($politics, $macro),
            'mortgageLtvCap' => $macro->mortgageLtvCap,
            'fundHead' => $this->departments->fundHead($politics, $macro),
            'exchequer' => $this->departments->exchequer($politics, $macro),
            'fairTrading' => $this->departments->fairTrading($politics),
            'securities' => $this->departments->securities($politics, $macro),
            'tradeOffice' => $this->departments->tradeOffice($politics, $macro),
        ]);
    }

    /**
     * The Diet's page: the chamber as it sits, the talks after a vote, the parties in the policy space, and every vote
     * on record.
     *
     * @return array<string, mixed>
     */
    public function buildDiet(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        $frame = $this->frame($macro, $politics);
        $context = $frame['context'];

        return $frame['page'] + [
            'axes' => array_map(static fn(string $axis): array => ['key' => $axis, 'name' => self::AXIS_COLUMNS[$axis]], AerieDiet::AXES),
            'talks' => $this->talks($politics, $context['talking']),
            'parties' => $context['parties'],
            'hemicycle' => DietChamberDrawing::hemicycle($context['seats'], $context['parties']),
            'compass' => DietChamberDrawing::compass($context['parties'], $context['history'], $context['coalitionPosition'], $context['coalition']),
            'history' => array_map(fn(DietElection $election): array => $this->historyRow($election, $macro->totalTime), array_reverse($context['history'])),
        ];
    }

    /**
     * The coming vote's page: the polls since the last one, the market's odds on the government it seats and the laws
     * that government would pass, and how those odds have moved.
     *
     * @return array<string, mixed>
     */
    public function buildElection(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        $frame = $this->frame($macro, $politics);

        return $frame['page'] + [
            'polls' => GovernmentCharts::polls($politics, $frame['context']['nextElection']),
            'market' => self::market($politics),
            'odds' => $this->oddsHistory($politics),
        ];
    }

    /**
     * The budget's page: the laws the cabinet aims for against those in force and what holds them, each law's path
     * since Year 1, and the listed firms each moves most.
     *
     * @return array<string, mixed>
     */
    public function buildBudget(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        $frame = $this->frame($macro, $politics);
        $context = $frame['context'];

        return $frame['page'] + [
            'budget' => $this->budget($macro, $politics, $context['coalition'], $context['support'], $context['talking']),
            'laws' => $this->laws($politics, $context['history']),
            'market' => self::market($politics),
            'exposure' => $this->exposure($macro, $politics),
        ];
    }

    /**
     * The Aerie Council's page: each seat, who holds it and their stances, the Council's and its Appointment Board's
     * leans, the departments it appoints to, and its powers.
     *
     * @return array<string, mixed>
     */
    public function buildCouncil(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        $page = $this->frame($macro, $politics)['page'];
        $heads = self::standingHeads($politics, $macro->totalTime);

        return array_replace($page, ['council' => $page['council'] + [
            'roster' => array_map(static fn(array $seat): array => $seat + [
                'name' => self::councillorName($politics, $seat),
                'sinceLabel' => ($politics->councilSeatedAt[$seat['seat'] - 1] ?? $seat['since']) > $seat['since'] + 1e-9
                    ? DistrictCalendar::quarter($politics->councilSeatedAt[$seat['seat'] - 1])
                    : ($seat['beforeYearOne'] ? 'Before Year 1' : DistrictCalendar::quarter($seat['since'])),
                'termEndsLabel' => DistrictCalendar::quarter($seat['termEnds']),
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
            'board' => $politics->boardMembers === [] ? null : self::stanceCounts(
                array_map(static fn(array $member): float => $member['stance'], $politics->boardMembers),
                array_map(static fn(array $member): float => $member['swing'] ?? 0.0, $politics->boardMembers),
            ) + ['seats' => count($politics->boardMembers), 'median' => MonetaryAuthority::stanceName(CouncilAppointments::boardMedians($politics)[CouncilAppointments::AXIS_MONEY])],
            'lean' => self::councilLean($politics),
            // The Security Bureau and the two guards stand outside the government's menus, so their heads are shown
            // with their mandates.
            'departments' => array_map(static fn(array $department): array => $department + ['head' => $heads[$department['name']] ?? null], AerieCouncil::DEPARTMENTS),
        ]]);
    }

    /**
     * The heads the Council page lists beside their mandates, by department: the Security Bureau's director and the
     * commanders of the Coast Guard and the State Guard. A post not yet filled is left out.
     *
     * @return array<string, array{title: string, name: string, age: int, termEndsLabel: string}>
     */
    private static function standingHeads(PoliticsStateDTO $politics, float $time): array
    {
        $posts = [
            Institutions::SECURITY_BUREAU => ['Director', Office::SecurityDirector],
            Institutions::COAST_GUARD => ['Commandant', Office::CoastGuardCommandant],
            Institutions::STATE_GUARD => ['Commander', Office::StateGuardCommander],
        ];

        $heads = [];
        foreach ($posts as $department => [$title, $office]) {
            $head = $politics->head($office);
            if ($head['name'] !== '') {
                $heads[$department] = ['title' => $title, 'name' => $head['name'], 'age' => (int) floor($time - $head['birth']), 'termEndsLabel' => DistrictCalendar::quarter($office->termEnd($time))];
            }
        }

        return $heads;
    }

    /**
     * The Monetary Authority's page: the governor and the committee, the last decision and the next meeting, and the
     * rate meeting by meeting.
     *
     * @return array<string, mixed>
     */
    public function buildAuthority(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        return [
            'simDate' => DistrictCalendar::quarter($macro->totalTime),
            'authority' => $this->authority($politics, true),
        ];
    }

    /**
     * What every government page shares: the date, the cabinet, the next vote, the next Council vacancy and the rules
     * the copy quotes (the page part); and the Diet as it sits, the parties and the votes on record, which the pages
     * build their own sections from (the context part).
     *
     * @return array{page: array<string, mixed>, context: array{history: list<DietElection>, seats: array<string, int>, coalition: list<string>, support: list<string>, coalitionPosition: array{state: float, openness: float, council: float, environment: float}, talking: bool, parties: non-empty-list<array<string, mixed>>, nextElection: float}}
     */
    private function frame(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        $history = $this->elections->findChronological();
        $seats = array_map('intval', $politics->dietSeats + array_fill_keys(AerieDiet::PARTIES, 0.0));
        $coalition = AerieDiet::governingParties($politics->governingCoalition);
        $support = AerieDiet::governingParties($politics->supportParties);
        $sumSeats = static fn(array $members): int => array_sum(array_map(static fn(string $party): int => $seats[$party], $members));
        $coalitionSeats = $sumSeats($coalition);
        $coalitionPosition = Platforms::coalitionPosition($politics->governingCoalition, $politics->dietSeats, $politics->partyPositions);
        $talking = $politics->coalitionTakesOfficeAt > $macro->totalTime;

        $blocs = $politics->dietBlocs;
        $leaders = array_values(array_unique($blocs));

        $term = Election::TERM_YEARS;
        $nextElection = (floor($macro->totalTime / $term) + 1.0) * $term;
        $member = static fn(string $party): array => [
            'key' => $party,
            'name' => AerieDiet::PARTY_NAMES[$party],
            'color' => DietStyle::PARTY_COLORS[$party],
            'seats' => $seats[$party],
        ];

        $page = [
            'simDate' => DistrictCalendar::quarter($macro->totalTime),
            'government' => [
                'members' => array_map($member, $coalition),
                'support' => array_map($member, $support),
                'seats' => $coalitionSeats,
                'supportedSeats' => $coalitionSeats + $sumSeats($support),
                'minority' => $coalitionSeats < AerieDiet::MAJORITY_SEATS,
                'formed' => $politics->lastGovernmentFormedAt < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($politics->coalitionFormedAt),
                'range' => CoalitionFormation::ideologicalRange($coalition, $politics->partyPositions),
                'caretaker' => $talking,
                'primeMinister' => self::primeMinister($politics),
            ] + $coalitionPosition,
            'election' => [
                'next' => DistrictCalendar::quarter($nextElection),
                'yearsLeft' => $nextElection - $macro->totalTime,
                'campaign' => $nextElection - $macro->totalTime <= Election::CAMPAIGN_WINDOW_YEARS,
                'policyUncertainty' => $macro->policyUncertaintyIndexEma,
            ],
            'rules' => [
                'seats' => AerieDiet::SEATS,
                'majority' => AerieDiet::MAJORITY_SEATS,
                'termYears' => $term,
                'councilSeats' => AerieCouncil::SEATS,
                'councilTermYears' => AerieCouncil::TERM_YEARS,
                'reserveDrawCeiling' => MacroConstants::RESERVE_DRAW_CEILING,
                'budgetRoundMonths' => 12.0 * MacroConstants::BUDGET_ROUND_PERIOD_YEARS,
                'blocLeaders' => array_map(self::midSentenceName(...), $leaders),
                'blocSeats' => array_map(
                    static fn(string $leader): array => [
                        'name' => DietStyle::PARTY_LABELS[$leader],
                        'color' => DietStyle::PARTY_COLORS[$leader],
                        'seats' => $sumSeats(array_keys(array_filter($blocs, static fn(string $bloc): bool => $bloc === $leader))),
                    ],
                    $leaders,
                ),
            ],
            'council' => [
                'nextVacancy' => (static function (array $seat) use ($politics): array {
                    return $seat + ['name' => self::councillorName($politics, $seat), 'termEndsLabel' => DistrictCalendar::quarter($seat['termEnds'])];
                })(AerieCouncil::nextVacancy($macro->totalTime)),
            ],
        ];

        return [
            'page' => $page,
            'context' => [
                'history' => $history,
                'seats' => $seats,
                'coalition' => $coalition,
                'support' => $support,
                'coalitionPosition' => $coalitionPosition,
                'talking' => $talking,
                'parties' => $this->partyRows($politics, $history),
                'nextElection' => $nextElection,
            ],
        ];
    }

    /**
     * The Council's lean on each of its questions: its hawks, swing votes and doves on money, and what its middle
     * councillor would ask of the banks and the reserves. Null before the Council's stances are drawn.
     *
     * @return array<string, mixed>|null
     */
    private static function councilLean(PoliticsStateDTO $politics): ?array
    {
        return $politics->councilStances === [] ? null : self::stanceCounts($politics->councilStances, $politics->councilSwingers) + [
            'median' => MonetaryAuthority::stanceName(CouncilAppointments::median($politics->councilStances)),
            'banks' => $politics->councilRegulationStances === [] ? null : FinancialRegulator::requirement(CouncilAppointments::median($politics->councilRegulationStances)),
            'reserves' => $politics->councilFundStances === [] ? null : SovereignReserveFund::equityShare(CouncilAppointments::median($politics->councilFundStances)),
        ];
    }

    /**
     * The listed firms each law moves most, against the laws in force and, once the market has a forecast, the laws it
     * expects (LawExposureBuilder). Null without a board to read.
     *
     * @return array<string, mixed>|null
     */
    private function exposure(MacroStateDTO $macro, PoliticsStateDTO $politics): ?array
    {
        if ($this->stocks === null) {
            return null;
        }
        $expected = $politics->forecastAt < 0.0 ? [] : $politics->forecastLevers;

        return LawExposureBuilder::build($this->stocks->findAll(), $macro, BudgetRound::standingLevers($politics), $expected);
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
            if ($chance >= GovernmentCharts::LISTED_LEADER_CHANCE) {
                $leaders[] = ['name' => AerieDiet::PARTY_NAMES[$party], 'color' => DietStyle::PARTY_COLORS[$party], 'chance' => $chance];
            }
        }
        usort($leaders, static fn(array $a, array $b): int => $b['chance'] <=> $a['chance']);

        $names = static fn(array $parties): array => array_map(static fn(string $party): string => AerieDiet::PARTY_NAMES[$party], $parties);
        $standing = BudgetRound::standingLevers($politics);
        $levers = [];
        foreach (self::LEVER_DISPLAY as $lever => [$name, $unit]) {
            $enacted = self::leverDisplay($lever, $standing[$lever]);
            $expected = self::leverDisplay($lever, $politics->forecastLevers[$lever] ?? $standing[$lever]);
            // A move shows only when it shows at the precision the page prints: hundredths of a point, whole HHI
            // points, cents.
            $places = self::displayPlaces($unit);
            $levers[] = ['name' => $name, 'unit' => $unit, 'enacted' => $enacted, 'expected' => $expected, 'moves' => round($expected, $places) !== round($enacted, $places)];
        }

        return [
            'talks' => $politics->forecastFor <= $politics->totalTime,
            'vote' => DistrictCalendar::quarter($politics->forecastFor),
            'takesEffect' => DistrictCalendar::quarter(ElectionForecast::takesEffect($politics)),
            'leaders' => $leaders,
            'cabinets' => array_map(static fn(array $option): array => [
                'cabinet' => $names($option['cabinet']),
                'support' => $names($option['support']),
                'chance' => $option['chance'],
            ], array_slice($politics->forecastCabinets, 0, 4)),
            'levers' => $levers,
        ];
    }

    /**
     * The laws in force at the close of each recorded quarter, one step chart a law on a shared time axis, and behind
     * them the cabinet that governed. Each chart runs on to the law in force today.
     *
     * @param list<DietElection> $history The votes on record, oldest first.
     * @return array<string, mixed>|null Null with fewer than two quarters recorded.
     */
    private function laws(PoliticsStateDTO $politics, array $history): ?array
    {
        $rows = $this->reports?->laws() ?? [];
        if (count($rows) < 2) {
            return null;
        }

        $now = $politics->totalTime;
        $rows[] = ['t' => $now, 'levers' => BudgetRound::standingLevers($politics), 'capitalRequirement' => $politics->bankCapitalRequirement, 'mergerReviewLeniency' => $politics->policy()->mergerReviewLeniency];
        $from = $rows[0]['t'];
        [$left, $right, $top, $bottom] = self::LAW_CHART_PLOT;
        $x = static fn(float $time): float => round($left + (($right - $left) * ($time - $from) / max(1e-9, $now - $from)), 1);

        $charts = [];
        foreach (self::LEVER_DISPLAY + self::DEPARTMENT_LAWS as $key => [$name, $unit]) {
            $points = [];
            foreach ($rows as $row) {
                $raw = array_key_exists($key, self::DEPARTMENT_LAWS) ? ($row[$key] ?? null) : ($row['levers'][$key] ?? null);
                if ($raw !== null) {
                    $points[] = [$row['t'], $key === 'capitalRequirement' ? $raw : self::leverDisplay($key, $raw)];
                }
            }
            if (count($points) < 2) {
                continue;
            }

            $values = array_column($points, 1);
            [$low, $high] = [min($values), max($values)];
            $minSpan = self::LAW_CHART_MIN_SPAN[$unit];
            if ($high - $low < $minSpan) {
                $middle = ($high + $low) / 2.0;
                $low = $middle - ($minSpan / 2.0) < 0.0 && min($values) >= 0.0 ? 0.0 : $middle - ($minSpan / 2.0);
                $high = $low + $minSpan;
            }
            $y = static fn(float $value): float => round($bottom - (($bottom - $top) * ($value - $low) / ($high - $low)), 1);

            $places = self::displayPlaces($unit);
            $line = [];
            $changes = 0;
            $previous = null;
            foreach ($points as [$time, $value]) {
                if ($previous !== null) {
                    $line[] = $x($time) . ',' . $y($previous);
                    $changes += round($value, $places) !== round($previous, $places) ? 1 : 0;
                }
                $line[] = $x($time) . ',' . $y($value);
                $previous = $value;
            }

            $charts[] = [
                'name' => $name,
                'unit' => $unit,
                'now' => $values[array_key_last($values)],
                'low' => ['value' => min($values), 'y' => $y(min($values))],
                'high' => ['value' => max($values), 'y' => $y(max($values))],
                'flat' => round(max($values), $places) === round(min($values), $places),
                'line' => implode(' ', $line),
                'changes' => $changes,
            ];
        }

        $cabinets = [];
        foreach (self::cabinetPeriods($politics, $history) as $index => $period) {
            $start = max($from, $period['from']);
            $end = min($now, $period['to']);
            if ($end <= $start) {
                continue;
            }
            $cabinets[] = [
                'x0' => $x($start),
                'x1' => $x($end),
                'color' => DietStyle::PARTY_COLORS[$period['leader']],
                'members' => array_map(static fn(string $party): string => DietStyle::PARTY_LABELS[$party], $period['cabinet']),
                'fromLabel' => $period['from'] < 0.0 && $index === 0 ? 'Before Year 1' : DistrictCalendar::quarter($start),
                'toLabel' => $end >= $now ? 'now' : DistrictCalendar::quarter($end),
            ];
        }

        $years = [];
        $count = (int) floor($now) - (int) ceil($from) + 1;
        $step = max(1, (int) ceil($count / self::LAW_CHART_YEAR_LABELS));
        for ($year = (int) ceil($from); $year <= $now + 1e-9; $year += $step) {
            $years[] = ['x' => $x((float) $year), 'label' => sprintf('Year %d', $year + 1)];
        }

        return [
            'since' => DistrictCalendar::quarter($from),
            'charts' => $charts,
            'cabinets' => $cabinets,
            'years' => $years,
            'plot' => self::LAW_CHART_PLOT,
        ];
    }

    /**
     * The cabinets that have governed, oldest first: the one sitting before the first vote on record, the one each
     * vote's talks seated from the day it took office, and the one that followed each fall. Consecutive terms of the
     * same cabinet are one period. Each is led by its largest party in the Diet it governed.
     *
     * @param list<DietElection> $history
     * @return list<array{from: float, to: float, cabinet: list<string>, leader: string}>
     */
    private static function cabinetPeriods(PoliticsStateDTO $politics, array $history): array
    {
        $now = $politics->totalTime;
        $terms = $history === []
            ? [[-INF, AerieDiet::governingParties($politics->governingCoalition), $politics->dietSeats]]
            : [[-INF, $history[0]->getOutgoingCoalition(), AerieDiet::SEED_SEATS]];
        foreach ($history as $election) {
            if ($election->getTakesOfficeAt() <= $now) {
                $terms[] = [$election->getTakesOfficeAt(), $election->getCoalition(), $election->getSeats()];
            }
            foreach ($election->getFalls() as $fall) {
                $takesOffice = $fall['fellAt'] + ($fall['formationDays'] / FinancialConstants::DAYS_PER_YEAR);
                if ($takesOffice <= $now && $fall['cabinet'] !== []) {
                    $terms[] = [$takesOffice, $fall['cabinet'], $election->getSeats()];
                }
            }
        }

        $periods = [];
        foreach ($terms as [$start, $cabinet, $seats]) {
            $last = array_key_last($periods);
            if ($cabinet === [] || ($last !== null && $periods[$last]['cabinet'] === $cabinet)) {
                continue;
            }
            $leader = $cabinet[0];
            foreach ($cabinet as $party) {
                if (($seats[$party] ?? 0) > ($seats[$leader] ?? 0)) {
                    $leader = $party;
                }
            }
            $periods[] = ['from' => $start, 'to' => INF, 'cabinet' => $cabinet, 'leader' => $leader];
        }
        // Each period runs until the next begins.
        foreach ($periods as $i => $period) {
            $periods[$i]['to'] = $periods[$i + 1]['from'] ?? INF;
        }

        return $periods;
    }

    /**
     * The market's odds on who leads the next government, forecast by forecast, for the vote its latest forecast is
     * for.
     *
     * @return array<string, mixed>|null Null before two forecasts of that vote are on record.
     */
    private function oddsHistory(PoliticsStateDTO $politics): ?array
    {
        if ($this->odds === null || $politics->forecastAt < 0.0) {
            return null;
        }

        return GovernmentCharts::oddsChart($this->odds->findForVote($politics->forecastFor, self::ODDS_VOTE_TOLERANCE), $politics->totalTime, $politics->forecastFor);
    }

    /**
     * Decimal places a law's value shows at, as a fraction: hundredths of a point, whole HHI points, or cents for a
     * dollar figure.
     */
    private static function displayPlaces(string $unit): int
    {
        return $unit === 'usd_t' ? 2 : 4;
    }

    /**
     * A law's value as the page shows it: the corporate rate itself, the merger line as an HHI, the green belt as
     * schemes refused, the extraction rules as their cost.
     */
    private static function leverDisplay(string $lever, float $value): float
    {
        return match ($lever) {
            'corporateTax' => MacroConstants::BASE_CORPORATE_TAX_RATE + $value,
            'mergerReviewLeniency' => MergerAndAcquisitionEngine::reviewScreens($value)['concentrated'],
            'greenBeltStringency' => AssetMarketSubsystem::planningRefusalRate($value),
            'extractionStringency' => self::extractionCostUplift($value),
            default => $value,
        };
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
        $platform = Platforms::partyProgramme($party, $politics->partyPositions);
        $neutral = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $talking = $politics->coalitionTakesOfficeAt > $macro->totalTime;

        $axes = [];
        foreach (AerieDiet::AXES as $axis) {
            $axes[] = DietStyle::AXIS_NAMES[$axis] + [
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
                'date' => DistrictCalendar::quarter($election->getSimTime()),
                'seats' => (int) ($election->getSeats()[$party] ?? 0),
                'share' => (float) ($election->getVoteShares()[$party] ?? 0.0),
                'cabinet' => $formed && in_array($party, $election->getCoalition(), true),
                'support' => $formed && in_array($party, $election->getSupport(), true),
            ];
        }
        $votes = array_slice($record, 1);

        return [
            'simDate' => DistrictCalendar::quarter($macro->totalTime),
            'party' => $row + AeriePartyProfiles::PROFILES[$party] + [
                'caretaker' => $talking,
                'blocLeader' => self::midSentenceName($politics->dietBlocs[$party]),
                'definedBy' => array_map(static fn(string $axis): string => DietStyle::AXIS_NAMES[$axis]['name'], array_keys(AerieDiet::FIXED_POSITIONS[$party])),
            ],
            'axes' => $axes,
            'levers' => [
                ['name' => 'Public spending, share of GDP', 'unit' => 'pct', 'platform' => $platform['publicSpending'], 'enacted' => $politics->publicSpendingShare],
                ['name' => 'Corporate tax rate', 'unit' => 'pct', 'platform' => $neutral + $platform['corporateTax'], 'enacted' => $neutral + $politics->corporateTaxPolicyShift],
                ['name' => 'Tax on dividends', 'unit' => 'pct', 'platform' => $platform['dividendTax'], 'enacted' => $politics->dividendTaxRate],
                ['name' => 'Average tariff on imports', 'unit' => 'pct', 'platform' => $platform['tariff'], 'enacted' => $politics->importTariffRate],
                ['name' => 'Labour force growth', 'unit' => 'pct', 'platform' => $platform['laborGrowth'], 'enacted' => $politics->laborForceGrowthRate],
                ['name' => 'Tax on foreign home buyers', 'unit' => 'pct', 'platform' => $platform['foreignBuyerTax'], 'enacted' => $politics->foreignBuyerTaxRate],
                ['name' => 'Housing schemes refused', 'unit' => 'pct', 'platform' => AssetMarketSubsystem::planningRefusalRate($platform['greenBeltStringency']), 'enacted' => AssetMarketSubsystem::planningRefusalRate($politics->greenBeltStringency)],
                ['name' => 'Carbon price', 'unit' => 'usd_t', 'platform' => $platform['carbonPrice'], 'enacted' => $politics->carbonPrice],
                ['name' => 'Extraction compliance cost', 'unit' => 'pct', 'platform' => self::extractionCostUplift($platform['extractionStringency']), 'enacted' => self::extractionCostUplift($politics->extractionStringency)],
                ['name' => 'Stamp duty on share trades', 'unit' => 'pct', 'platform' => $platform['stampDutyRate'], 'enacted' => $politics->stampDutyRate],
                ['name' => 'Bank levy', 'unit' => 'pct', 'platform' => $platform['bankLevyRate'], 'enacted' => $politics->bankLevyRate],
                ['name' => 'Share of reserve returns spent', 'unit' => 'pct', 'platform' => $platform['reserveDrawShare'], 'enacted' => $politics->reserveDrawShare],
                ['name' => 'Debt repair raised by tax', 'unit' => 'pct', 'platform' => $platform['consolidationTaxShare'], 'enacted' => $politics->consolidationTaxShare],
                ['name' => 'Reserve fund shares held at home', 'unit' => 'pct', 'platform' => $platform['reserveHomeShare'], 'enacted' => $politics->reserveHomeEquityShare],
                ['name' => 'Utility rate requests granted', 'unit' => 'pct', 'platform' => $platform['utilityRateGrant'], 'enacted' => $politics->utilityRateCaseGrantShare],
                ['name' => 'Debt target', 'unit' => 'pct', 'platform' => $platform['debtTarget'], 'enacted' => $politics->debtTarget],
                ['name' => 'Debt cut a year', 'unit' => 'pct', 'platform' => $platform['debtPace'], 'enacted' => $politics->debtPace],
            ],
            'leaders' => self::leaders($politics, $party),
            'polling' => $politics->polls === [] ? null : (static function (array $poll) use ($politics, $party): array {
                return [
                    'share' => $poll['shares'][$party] ?? 0.0,
                    'change' => ($poll['shares'][$party] ?? 0.0) - ($politics->dietVoteShares[$party] ?? 0.0),
                    'date' => DistrictCalendar::quarter($poll['t']),
                ];
            })($politics->polls[array_key_last($politics->polls)]),
            'record' => $record,
            'recordSummary' => [
                'votes' => count($votes),
                'cabinet' => count(array_filter($votes, static fn(array $vote): bool => $vote['cabinet'])),
                'support' => count(array_filter($votes, static fn(array $vote): bool => $vote['support'])),
                'best' => max(array_column($record, 'seats')),
                // The share its lasting support drifts back toward, and where it stood at the founding.
                'coreVote' => $politics->normalVoteShares[$party] ?? AerieDiet::SEED_VOTE_SHARES[$party],
                'coreVoteAtFounding' => AerieDiet::SEED_VOTE_SHARES[$party],
                'scale' => max(AerieDiet::MAJORITY_SEATS / 2, (int) ceil(max(array_column($record, 'seats')) * 1.15)),
            ],
            'parties' => array_values(array_map(static fn(array $other): array => [
                'key' => $other['key'],
                'name' => $other['name'],
                'slug' => $other['slug'],
                'color' => $other['color'],
            ], $rows)),
            'rules' => ['seats' => AerieDiet::SEATS, 'majority' => AerieDiet::MAJORITY_SEATS, 'termYears' => Election::TERM_YEARS],
        ];
    }

    /**
     * Every party as both pages list it: its seats and vote, its part in the government, its bloc, and where it stands.
     *
     * @param list<DietElection> $history Votes, oldest first.
     * @return non-empty-list<array<string, mixed>>
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
                'label' => DietStyle::PARTY_LABELS[$party],
                'color' => DietStyle::PARTY_COLORS[$party],
                'seats' => $seats[$party],
                'share' => $politics->dietVoteShares[$party] ?? 0.0,
                'swing' => $history === [] ? null : ($politics->dietVoteSwings[$party] ?? 0.0),
                'governing' => in_array($party, $coalition, true),
                'supporting' => in_array($party, $support, true),
                'fixedAxes' => array_keys(AerieDiet::FIXED_POSITIONS[$party]),
                'leadsBloc' => $blocs[$party] === $party,
                'blocKey' => $blocs[$party],
                'bloc' => DietStyle::PARTY_LABELS[$blocs[$party]],
                'blocColor' => DietStyle::PARTY_COLORS[$blocs[$party]],
                'partyLeader' => self::leader($politics, $party),
            ] + $position;
        }

        return $parties;
    }

    /**
     * The last talks, after a vote or a fall, as far as they have gone: the attempts whose day has passed, who leads
     * the one under way (but not the cabinet it is trying), and, once a cabinet has taken office, how long it took.
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
                $leading = ['name' => $names[$entry['formateur']], 'color' => DietStyle::PARTY_COLORS[$entry['formateur']], 'attempt' => $attempt + 1];
                break;
            }
            $entries[] = [
                'day' => $entry['day'],
                'attempt' => $attempt + 1,
                'formateur' => $names[$entry['formateur']],
                'color' => DietStyle::PARTY_COLORS[$entry['formateur']],
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
            'startedOn' => DistrictCalendar::quarter($startedAt),
            'day' => $talking ? $elapsed : $last['day'],
            'entries' => $entries,
            'leading' => $leading,
            'attempts' => count($politics->formationLog),
        ];
    }

    /**
     * The levers as the cabinet's platform sets them, as the next budget would enact them, and as the last budget
     * left them, with what stands between: the support parties, the reserve fund's consent to its draw, or talks still
     * under way; and the primary balance the budget plans this year on the debt target and pace in force.
     *
     * @param list<string> $coalition The cabinet.
     * @param list<string> $support   Its support parties.
     * @return array<string, mixed>
     */
    private function budget(MacroStateDTO $macro, PoliticsStateDTO $politics, array $coalition, array $support, bool $talking): array
    {
        $standing = BudgetRound::standingLevers($politics);
        $budget = BudgetRound::budget($coalition, $support, $politics->dietSeats, $politics->partyPositions, $standing, MacroEngine::recessionDeclared($macro));
        $round = MacroConstants::BUDGET_ROUND_PERIOD_YEARS;
        $nextRound = DistrictCalendar::quarter((floor($macro->totalTime / $round) + 1.0) * $round);

        $status = static function (string $lever) use ($budget, $standing, $talking): string {
            return match (true) {
                abs($budget['platform'][$lever] - $standing[$lever]) < 1e-9 => 'enacted',
                $talking => 'caretaker',
                $lever === 'reserveDrawShare' && $budget['fundHeld'] => 'fund',
                $budget['supportHeld'][$lever] && abs($budget['levers'][$lever] - $standing[$lever]) < 1e-9 => 'blocked',
                $budget['supportHeld'][$lever] => 'partial',
                default => 'pending',
            };
        };
        $neutral = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $planned = CreditFiscalSubsystem::requiredPrimaryBalance($macro);

        return [
            'levers' => [
                [
                    'name' => 'Public spending, share of GDP',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $budget['platform']['publicSpending'],
                    'target' => $budget['levers']['publicSpending'],
                    'enacted' => $standing['publicSpending'],
                    'status' => $status('publicSpending'),
                    'spending' => true,
                    'note' => sprintf(
                        'Spending on public services runs %.1f%% of GDP today; a budget\'s change reaches it over two to three years',
                        100.0 * CreditFiscalSubsystem::publicAccounts($macro, 0.0)->purchases,
                    ),
                ],
                [
                    'name' => 'Corporate tax rate',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $neutral + $budget['platform']['corporateTax'],
                    'target' => $neutral + $budget['levers']['corporateTax'],
                    'enacted' => $neutral + $standing['corporateTax'],
                    'status' => $status('corporateTax'),
                    'note' => sprintf('Firms pay %.1f%% today. Other taxes, net of benefits, take %.1f%% of GDP and move with the economy', 100.0 * $macro->corporateTaxRate, 100.0 * $macro->netTaxRate),
                ],
                [
                    'name' => 'Tax on dividends',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $budget['platform']['dividendTax'],
                    'target' => $budget['levers']['dividendTax'],
                    'enacted' => $standing['dividendTax'],
                    'status' => $status('dividendTax'),
                    'note' => self::dividendTaxNote($standing['dividendTax']),
                ],
                [
                    'name' => 'Average tariff on imports',
                    'axis' => AerieDiet::AXIS_OPENNESS,
                    'platform' => $budget['platform']['tariff'],
                    'target' => $budget['levers']['tariff'],
                    'enacted' => $standing['tariff'],
                    'status' => $status('tariff'),
                    'note' => sprintf('Partners answer with %.1f%% on the District\'s exports', 100.0 * MacroConstants::TARIFF_RETALIATION_RATIO * $standing['tariff']),
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
                    'name' => 'Tax on foreign home buyers',
                    'axis' => AerieDiet::AXIS_OPENNESS,
                    'platform' => $budget['platform']['foreignBuyerTax'],
                    'target' => $budget['levers']['foreignBuyerTax'],
                    'enacted' => $standing['foreignBuyerTax'],
                    'status' => $status('foreignBuyerTax'),
                    'note' => $standing['foreignBuyerTax'] > 0.0
                        ? sprintf('Of the price of a home bought from outside the District; it holds home prices %.1f%% below where they would stand without it', 100.0 * (1.0 - AssetMarketSubsystem::foreignBuyerPriceFactor($standing['foreignBuyerTax'])))
                        : 'Homes are open to buyers from anywhere, as at Year 1',
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
                    'note' => sprintf(
                        'Paid by buyer and seller each, into the Sovereign Reserve Fund; turnover runs %.0f%% %s its level at the rate in force at Year 1',
                        abs(100.0 * (MacroTransmission::calculateStampDutyVolumeFactor($standing['stampDutyRate']) - 1.0)),
                        MacroTransmission::calculateStampDutyVolumeFactor($standing['stampDutyRate']) < 1.0 ? 'below' : 'above',
                    ),
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
                [
                    'name' => 'Share of reserve returns spent',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $budget['platform']['reserveDrawShare'],
                    'target' => $budget['levers']['reserveDrawShare'],
                    'enacted' => $standing['reserveDrawShare'],
                    'status' => $status('reserveDrawShare'),
                    'note' => $macro->sovereignFundDrawToGdp > 0.0
                        ? sprintf('Of the Sovereign Reserve Fund\'s expected return; it pays the budget %.1f%% of GDP this year', 100.0 * $macro->sovereignFundDrawToGdp)
                        : 'Of the Sovereign Reserve Fund\'s expected return, paid to the budget each year',
                ],
                [
                    'name' => 'Debt repair raised by tax',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $budget['platform']['consolidationTaxShare'],
                    'target' => $budget['levers']['consolidationTaxShare'],
                    'enacted' => $standing['consolidationTaxShare'],
                    'status' => $status('consolidationTaxShare'),
                    'note' => $planned > 0.0
                        ? sprintf('Of the primary surplus the budget plans, %.1f%% of GDP this year; the rest comes from spending cuts', 100.0 * $planned)
                        : sprintf('Of any surplus the debt path calls for; the rest comes from spending cuts. The budget plans a primary deficit of %.1f%% of GDP this year, and this share of the room goes to spending, the rest to tax cuts', 100.0 * abs($planned)),
                ],
                [
                    'name' => 'Debt target',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $budget['platform']['debtTarget'],
                    'target' => $budget['levers']['debtTarget'],
                    'enacted' => $standing['debtTarget'],
                    'status' => $status('debtTarget'),
                    'note' => sprintf('Of GDP in public debt, which the budget steers toward over the years: a surplus while debt stands above it, room to spend or cut taxes below it. Debt stands at %.1f%% of GDP', 100.0 * $macro->sovereignDebtToGdp),
                ],
                [
                    'name' => 'Debt cut a year',
                    'axis' => AerieDiet::AXIS_STATE,
                    'platform' => $budget['platform']['debtPace'],
                    'target' => $budget['levers']['debtPace'],
                    'enacted' => $standing['debtPace'],
                    'status' => $status('debtPace'),
                    'note' => $standing['debtPace'] > 0.0
                        ? 'Of GDP a year at most that the budget moves debt toward its target, on top of what holds debt steady as interest and growth move it'
                        : 'The budget holds debt where it stands, as interest and growth move it, and makes no move toward the target',
                ],
                [
                    'name' => 'Reserve fund shares held at home',
                    'axis' => AerieDiet::AXIS_OPENNESS,
                    'platform' => $budget['platform']['reserveHomeShare'],
                    'target' => $budget['levers']['reserveHomeShare'],
                    'enacted' => $standing['reserveHomeShare'],
                    'status' => $status('reserveHomeShare'),
                    'note' => $this->reserveHomeShareNote($macro),
                ],
                [
                    'name' => 'Utility rate requests granted',
                    'axis' => AerieDiet::AXIS_COUNCIL,
                    'platform' => $budget['platform']['utilityRateGrant'],
                    'target' => $budget['levers']['utilityRateGrant'],
                    'enacted' => $standing['utilityRateGrant'],
                    'status' => $status('utilityRateGrant'),
                    'note' => 'Of the rise in power and water bills a utility asks the commission for when its costs outrun its tariff; the rest it carries into its next request',
                ],
            ],
            'debt' => $macro->sovereignDebtToGdp,
            'debtTarget' => $macro->debtTarget,
            'debtPace' => $macro->debtPace,
            'plannedBalance' => $planned,
            // What the bond market charges on the District's debt for the risk it is not repaid, in basis points.
            'riskSpreadBp' => (int) round(10000.0 * $macro->sovereignRiskSpread),
            'caretaker' => $talking,
            'lastBudget' => $politics->lastBudgetEnactedAt >= 0.0 ? DistrictCalendar::quarter($politics->lastBudgetEnactedAt) : null,
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
        return FirmEconomics::calculateProductivityLossCostFactor(FinancialConstants::ENVIRONMENTAL_REGULATION_TFP_LOSS * $stringency) - 1.0;
    }

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
            'sinceLabel' => $politics->leaderSince[$party] < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($politics->leaderSince[$party]),
        ];
    }

    /**
     * Everyone who has led a party since the leaders were first drawn, the present leader first.
     *
     * @return list<array{name: string, fromLabel: string, toLabel: string, current: bool}>
     */
    private static function leaders(PoliticsStateDTO $politics, string $party): array
    {
        $label = static fn(float $time): string => $time < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($time);
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
     * The Monetary Authority as the page shows it: the governor the Council named and the candidates it passed over,
     * the committee the governor picked, each with their age, stance, seat dates and last vote; the committee's make-up
     * and the supermajority it holds; and the last rate meeting. Null before the Authority has formed.
     *
     * @return array<string, mixed>|null
     */
    private function authority(PoliticsStateDTO $politics, bool $withChart): ?array
    {
        if ($politics->authoritySalt < 0.0) {
            return null;
        }

        $time = $politics->totalTime;
        $votes = $politics->lastMeetingVotes;
        $seated = static fn(float $since): string => $since < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($since);
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
                'termEndsLabel' => DistrictCalendar::quarter($since + MonetaryAuthority::MEMBER_TERM_YEARS),
            ];
        }
        $governor = $politics->head(Office::Governor);
        $higher = count(array_filter($votes, static fn(float $vote): bool => $vote > 0.0));
        $lower = count(array_filter($votes, static fn(float $vote): bool => $vote < 0.0));

        return [
            'governor' => $person($governor['name'], $governor['birth'], $governor['stance'], max(0.0, $politics->governorSwinger), $votes[0] ?? null) + [
                'sinceLabel' => $seated($governor['termStart']),
                'termEndsLabel' => DistrictCalendar::quarter(Office::Governor->termEnd($time)),
                'passedOver' => array_map(static fn(array $candidate): array => $person($candidate['name'], $candidate['birth'], $candidate['stance'], $candidate['swing'] ?? 0.0, null), $governor['passedOver']),
            ],
            'members' => $members,
            'committee' => self::stanceCounts(array_merge([$governor['stance']], $politics->memberStances), array_merge([max(0.0, $politics->governorSwinger)], array_values($politics->memberSwingers))) + [
                'majority' => $politics->committeeMajority > 0.0 ? 'hawkish' : ($politics->committeeMajority < 0.0 ? 'dovish' : null),
            ],
            'meeting' => $politics->lastMeetingAt < 0.0 ? null : [
                'date' => DistrictCalendar::quarter($politics->lastMeetingAt),
                'rate' => $politics->lastMeetingRate,
                'change' => $politics->lastMeetingChange,
                // Whole basis points, the precision the rate is printed at: a move that changes the printed rate is
                // never a hold.
                'moveBp' => (int) round(10000.0 * $politics->lastMeetingChange),
                'split' => (count($votes) - $higher - $lower) . '–' . ($higher + $lower),
                'higher' => $higher,
                'lower' => $lower,
            ],
            'nextMeeting' => DistrictCalendar::dateline(MonetaryPolicySubsystem::nextMeetingAt($time)),
            'decisions' => !$withChart || $this->decisions === null ? null : GovernmentCharts::rateChart(
                $this->decisions->findSince($time - GovernmentCharts::RATE_CHART_YEARS),
                $time,
                $this->reports?->inflation((int) ceil(FinancialConstants::QUARTERS_PER_YEAR * GovernmentCharts::RATE_CHART_YEARS) + 1) ?? [],
            ),
            'rules' => [
                'governorTermYears' => Office::Governor->termYears(),
                'memberTermYears' => MonetaryAuthority::MEMBER_TERM_YEARS,
                'members' => MonetaryAuthority::COMMITTEE_MEMBERS + 1,
                'meetingsPerYear' => MonetaryAuthority::MEETINGS_PER_YEAR,
                'shortlist' => CouncilAppointments::SHORTLIST,
            ],
        ];
    }

    /**
     * Who pays the tax on dividends, and what the rate in force does against the founding one: how much more or less
     * companies pay out as dividends, the rest going to buybacks, and what it adds to the budget.
     */
    private static function dividendTaxNote(float $rate): string
    {
        $note = 'Paid by shareholders outside retirement accounts on what companies pay them';
        if (abs($rate - Valuation::FOUNDING_DIVIDEND_TAX_RATE) < 1e-9) {
            return $note . ', at the rate set at Year 1';
        }

        return $note . sprintf(
            '; dividends run %+.0f%% against Year 1, buybacks making up the difference, and the budget\'s revenue %+.2f%% of GDP',
            100.0 * (Valuation::dividendTaxPayoutScale($rate) - 1.0),
            100.0 * CreditFiscalSubsystem::dividendTaxRevenueShift($rate),
        );
    }

    /**
     * What the home share buys: the fund's slice of the board, and when the share the law asks for would need more of
     * the float than the fund may own, that the ceiling holds it there. Its holding scales with its weight on the
     * board, so the ownership the law asks for is the ownership now times the target weight over the weight now.
     */
    private function reserveHomeShareNote(MacroStateDTO $macro): string
    {
        $note = 'Of the Sovereign Reserve Fund\'s shares, the rest held abroad';
        $owned = $macro->sovereignFundOwnershipShare;
        if ($owned <= 0.0) {
            return $note;
        }

        $note .= sprintf('; it owns %.1f%% of the shares listed in the District', 100.0 * $owned);
        $asked = $macro->sovereignFundDomesticWeight > 0.0 ? $owned * $macro->sovereignFundTargetWeight / $macro->sovereignFundDomesticWeight : 0.0;
        if ($asked > SovereignFundSubsystem::MAX_OWNERSHIP_SHARE) {
            $note .= sprintf(', and the law would need %.0f%%, more than the %.0f%% it may hold, so a higher share buys no more', 100.0 * $asked, 100.0 * SovereignFundSubsystem::MAX_OWNERSHIP_SHARE);
        }

        return $note;
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

    /**
     * The camp a swing vote leans to now, 'hawk' or 'dove'; null for a hawk or a dove, or a swing vote with no lean
     * yet.
     */
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
     * A vote on record, with each cabinet that fell before the next. A government stays hidden until it takes office:
     * the talks are settled on the day of the vote or the fall, and the page must not give their outcome away.
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
            'color' => DietStyle::PARTY_COLORS[$party],
        ];

        return [
            'date' => DistrictCalendar::quarter($election->getSimTime()),
            'seats' => array_map(static fn(string $party): array => [
                'key' => $party,
                'seats' => (int) ($seats[$party] ?? 0),
                'swing' => (float) ($election->getVoteSwings()[$party] ?? 0.0),
                'color' => DietStyle::PARTY_COLORS[$party],
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
                    'date' => DistrictCalendar::quarter($fall['fellAt']),
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

<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\AerieCouncil;
use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\DistrictPoliticsSubsystem;

/**
 * Builds the government page: the Diet as it now sits, the parties in the policy space, the coalition that governs,
 * the Council, and every vote on record.
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
        $coalitionSeats = array_sum(array_map(static fn(string $party): int => $seats[$party], $coalition));
        $coalitionPosition = DistrictPoliticsSubsystem::coalitionPosition($macro->governingCoalition, $macro->dietSeats, $macro->partySecondaryPositions);

        $parties = [];
        foreach (AerieDiet::PARTIES as $party) {
            $secondary = $macro->partySecondaryPositions[$party] ?? AerieDiet::SEED_SECONDARY_POSITIONS[$party];
            $position = AerieDiet::position($party, $secondary);
            $parties[] = [
                'key' => $party,
                'name' => AerieDiet::PARTY_NAMES[$party],
                'color' => self::PARTY_COLORS[$party],
                'seats' => $seats[$party],
                'share' => $macro->dietVoteShares[$party] ?? 0.0,
                'swing' => $history === [] ? null : ($macro->dietVoteSwings[$party] ?? 0.0),
                'governing' => in_array($party, $coalition, true),
                'primaryAxis' => AerieDiet::PRIMARY_AXIS[$party],
                'state' => $position[AerieDiet::AXIS_STATE],
                'openness' => $position[AerieDiet::AXIS_OPENNESS],
            ];
        }

        $term = MacroEngine::ELECTION_TERM_YEARS;
        $nextElection = (floor($macro->totalTime / $term) + 1.0) * $term;

        return [
            'simDate' => self::simDate($macro->totalTime),
            'government' => [
                'members' => array_map(static fn(string $party): array => [
                    'key' => $party,
                    'name' => AerieDiet::PARTY_NAMES[$party],
                    'color' => self::PARTY_COLORS[$party],
                    'seats' => $seats[$party],
                ], $coalition),
                'seats' => $coalitionSeats,
                'formed' => $history === [] ? 'At the founding' : self::simDate($macro->coalitionFormedAt),
                'range' => DistrictPoliticsSubsystem::ideologicalRange($coalition, $macro->partySecondaryPositions),
                'state' => $coalitionPosition[AerieDiet::AXIS_STATE],
                'openness' => $coalitionPosition[AerieDiet::AXIS_OPENNESS],
                'supermajority' => $coalitionSeats >= AerieDiet::SUPERMAJORITY_SEATS,
            ],
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
            ],
            'parties' => $parties,
            'hemicycle' => $this->hemicycle($seats, $parties, $coalition),
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
            'history' => array_map(fn(DietElection $election): array => $this->historyRow($election), array_reverse($history)),
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
     * @param array<string, int>          $seats     Seats by party.
     * @param list<array<string, mixed>>  $parties   Party rows.
     * @param list<string>                $coalition Governing parties.
     * @return list<array{x: float, y: float, color: string, governing: bool}>
     */
    private function hemicycle(array $seats, array $parties, array $coalition): array
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
                    'governing' => in_array($party['key'], $coalition, true),
                ];
            }
        }

        return $drawn;
    }

    /**
     * The policy space: openness across, size of state up, each party with its trail of past positions.
     *
     * @param list<array<string, mixed>>        $parties   Party rows.
     * @param list<DietElection>                $history   Votes, oldest first.
     * @param array{state: float, openness: float} $government The coalition's seat-weighted position.
     * @param list<string>                      $coalition Governing parties.
     * @return array<string, mixed>
     */
    private function compass(array $parties, array $history, array $government, array $coalition): array
    {
        $scale = self::COMPASS_HALF_WIDTH;
        $point = static fn(float $state, float $openness): array => ['x' => round($openness * $scale, 2), 'y' => round(-$state * $scale, 2)];

        $dots = [];
        foreach ($parties as $party) {
            $trail = [];
            foreach ($history as $election) {
                $secondary = $election->getSecondaryPositions()[$party['key']] ?? null;
                if ($secondary === null) {
                    continue;
                }
                $past = AerieDiet::position($party['key'], (float) $secondary);
                $trail[] = $point($past[AerieDiet::AXIS_STATE], $past[AerieDiet::AXIS_OPENNESS]);
            }
            $dots[] = $party + $point($party['state'], $party['openness']) + ['trail' => $trail];
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
            'government' => $point($government[AerieDiet::AXIS_STATE], $government[AerieDiet::AXIS_OPENNESS]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyRow(DietElection $election): array
    {
        $seats = $election->getSeats();
        $coalition = $election->getCoalition();

        return [
            'date' => self::simDate($election->getSimTime()),
            'seats' => array_map(static fn(string $party): array => [
                'key' => $party,
                'seats' => (int) ($seats[$party] ?? 0),
                'swing' => (float) ($election->getVoteSwings()[$party] ?? 0.0),
                'color' => self::PARTY_COLORS[$party],
            ], AerieDiet::PARTIES),
            'coalition' => array_map(static fn(string $party): array => [
                'name' => AerieDiet::PARTY_NAMES[$party],
                'color' => self::PARTY_COLORS[$party],
            ], $coalition),
            // Both lists are written in party order, so a different government is a different list.
            'changed' => $coalition !== $election->getOutgoingCoalition(),
            'incumbentSwing' => $election->getIncumbentSwing(),
            'volatility' => $election->getVolatility(),
            'growthGap' => $election->getGrowthGap(),
            'inflationGap' => $election->getInflationGap(),
            'crisisLift' => $election->hasCrisisLift(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\AerieDiet as Diet;
use App\Data\AeriePartyProfiles;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Entity\ElectionOdds;
use App\Entity\RateDecision;
use App\Entity\Stock;
use App\Repository\DietElectionRepository;
use App\Repository\ElectionOddsRepository;
use App\Repository\MacroReportHistoryRepository;
use App\Repository\RateDecisionRepository;
use App\Repository\StockRepository;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Politics\ElectionForecast;
use App\Service\Politics\PartyLeaders;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use App\Service\View\GovernmentPageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GovernmentPageBuilderTest extends TestCase
{
    /** @param list<DietElection> $history */
    private function builder(array $history = []): GovernmentPageBuilder
    {
        $elections = $this->createStub(DietElectionRepository::class);
        $elections->method('findChronological')->willReturn($history);

        return new GovernmentPageBuilder($elections);
    }

    public function testTheExposureTablesReadTheBoardAndTheMarketsForecast(): void
    {
        $elections = $this->createStub(DietElectionRepository::class);
        $elections->method('findChronological')->willReturn([]);
        $bank = (new Stock())->setTicker('BANK')->setName('Bank')->setIndustry('Banks - Regional')->setTotalRevenue('1000000000')
            ->setTotalNetIncome('100000000')->setWholesaleDebt('5000000000')->setFloatingDebtRatio('0.5')->setCustomerDeposits('8000000000')->setRevolverDrawn('0');
        $stocks = $this->createStub(StockRepository::class);
        $stocks->method('findAll')->willReturn([$bank]);
        $builder = new GovernmentPageBuilder($elections, null, $stocks);
        $standing = PoliticsEngine::standingLevers(new PoliticsStateDTO(bankLevyRate: 0.001));

        $page = $builder->build(new MacroStateDTO(bankLevyRate: 0.001), new PoliticsStateDTO(bankLevyRate: 0.001));
        $levy = array_column($page['exposure']['laws'], null, 'lever')['bankLevyRate'];
        $this->assertSame(['BANK'], array_column($levy['firms'], 'ticker'));
        $this->assertLessThan(0.0, $levy['firms'][0]['now']);
        $this->assertNull($levy['firms'][0]['change'], 'Without a forecast nothing is expected.');

        $forecast = $builder->build(new MacroStateDTO(bankLevyRate: 0.001), new PoliticsStateDTO(bankLevyRate: 0.001, forecastAt: 1.0, forecastLevers: ['bankLevyRate' => 0.002] + $standing));
        $levy = array_column($forecast['exposure']['laws'], null, 'lever')['bankLevyRate'];
        $this->assertTrue($levy['expectedMoves']);
        $this->assertEqualsWithDelta($levy['firms'][0]['now'], $levy['firms'][0]['change'], 1e-6, 'Doubling the levy costs the bank as much again.');

        $this->assertNull($this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO())['exposure'], 'No board, no tables.');
    }

    public function testTheDietAtYearOneIsDrawnSeatForSeat(): void
    {
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO());

        $this->assertSame(['The Vanguard'], array_column($page['government']['members'], 'name'));
        $this->assertSame(95, $page['government']['seats']);
        $this->assertSame(['Exchange Party', 'The Chartists', 'New Horizon'], array_column($page['government']['support'], 'name'));
        $this->assertSame(165, $page['government']['supportedSeats']);
        $this->assertTrue($page['government']['minority']);
        $this->assertSame(
            [94 + 25 + 8 + 8, 95 + 25 + 20 + 25],
            array_column($page['rules']['blocSeats'], 'seats'),
            'The blocs: the Civic Front with the Harbor, the Common Lot and the Accord; the Vanguard with the Exchange, the Chartists and New Horizon.'
        );
        $tags = array_column($page['parties'], 'bloc', 'key');
        $this->assertSame('Vanguard', $tags[Diet::CHARTISTS]);
        $this->assertSame('Civic', $tags[Diet::IRON_HARBOR]);
        $this->assertNull($page['talks'], 'Before any vote there are no talks to show.');
        $this->assertFalse($page['government']['supermajority']);
        $this->assertSame('Before Year 1', $page['government']['formed']);

        $this->assertCount(Diet::SEATS, $page['hemicycle']);
        $drawn = array_count_values(array_column($page['hemicycle'], 'color'));
        foreach ($page['parties'] as $party) {
            $this->assertSame($party['seats'], $drawn[$party['color']], "{$party['name']} is drawn with the wrong number of seats.");
            $this->assertNull($party['swing'], 'Before any vote there is no change to show.');
        }
        $this->assertSame([], $page['history']);
    }

    /** The chamber runs from the largest state on the left to the smallest on the right. */
    public function testTheChamberRunsFromBigStateToSmall(): void
    {
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO());
        $leftmost = array_reduce($page['hemicycle'], static fn(?array $carry, array $seat): array => $carry === null || $seat['x'] < $carry['x'] ? $seat : $carry);
        $rightmost = array_reduce($page['hemicycle'], static fn(?array $carry, array $seat): array => $carry === null || $seat['x'] > $carry['x'] ? $seat : $carry);

        $this->assertContains($leftmost['color'], [GovernmentPageBuilder::PARTY_COLORS[Diet::CIVIC]]);
        $this->assertContains($rightmost['color'], [GovernmentPageBuilder::PARTY_COLORS[Diet::VANGUARD]]);
    }

    /**
     * Each bloc sits together even when it spans the size-of-state axis: with the Vanguard in the Civic Front's bloc,
     * a chamber sorted on that axis alone would wrap the bloc around the other one.
     */
    public function testEachBlocSitsTogether(): void
    {
        $blocs = Diet::SEED_BLOCS;
        $blocs[Diet::VANGUARD] = Diet::CIVIC;
        $blocs[Diet::EXCHANGE] = Diet::EXCHANGE;
        $blocs[Diet::CHARTISTS] = Diet::EXCHANGE;
        $blocs[Diet::NEW_HORIZON] = Diet::EXCHANGE;
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(dietBlocs: $blocs));

        $blocOf = [];
        foreach ($page['parties'] as $party) {
            $blocOf[$party['color']] = $party['blocKey'];
        }
        $seats = $page['hemicycle'];
        usort($seats, static fn(array $a, array $b): int => atan2($b['y'], $b['x']) <=> atan2($a['y'], $a['x']));
        $runs = 1;
        for ($i = 1, $n = count($seats); $i < $n; ++$i) {
            $runs += $blocOf[$seats[$i]['color']] !== $blocOf[$seats[$i - 1]['color']] ? 1 : 0;
        }

        $this->assertSame(2, $runs, 'Each bloc is one wedge of the chamber.');
        $this->assertSame(GovernmentPageBuilder::PARTY_COLORS[Diet::CIVIC], $seats[0]['color'], 'The bigger-state bloc sits on the left, its biggest-state party leftmost.');
    }

    /** The history runs newest first, marks a change of government, and each vote leaves a point on every party's trail. */
    public function testTheHistoryRunsNewestFirst(): void
    {
        $first = $this->election(4.0, [Diet::VANGUARD, Diet::EXCHANGE], [Diet::VANGUARD, Diet::EXCHANGE]);
        $second = $this->election(8.0, [Diet::CIVIC, Diet::IRON_HARBOR, Diet::EXCHANGE], [Diet::VANGUARD, Diet::EXCHANGE]);

        $page = $this->builder([$first, $second])->build(new MacroStateDTO(totalTime: 9.3), new PoliticsStateDTO(totalTime: 9.3, coalitionFormedAt: 8.0, lastGovernmentFormedAt: 8.0));

        $this->assertSame(['Year 9 Q1', 'Year 5 Q1'], array_column($page['history'], 'date'));
        $this->assertSame([true, false], array_column($page['history'], 'changed'));
        $this->assertSame('Year 9 Q1', $page['government']['formed']);
        $this->assertSame('Year 13 Q1', $page['election']['next']);
        foreach ($page['compass']['layouts'] as $layout) {
            foreach ($layout['rows'] as $row) {
                foreach ($row['parties'] as $party) {
                    $this->assertCount(2, $party['trail']);
                }
            }
        }
    }

    /**
     * Mid-talks the page shows the attempts whose day has passed and who leads the one under way, and nothing of how
     * the talks end: not the next attempt, not the cabinet in the history. The caretaker passes no budget.
     */
    public function testTalksUnderWayShowOnlyTheAttemptsWhoseDayHasPassed(): void
    {
        $log = [
            ['day' => 20.0, 'formateur' => Diet::VANGUARD, 'formed' => false, 'cabinet' => [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS], 'support' => []],
            ['day' => 50.0, 'formateur' => Diet::VANGUARD, 'formed' => true, 'cabinet' => [Diet::VANGUARD], 'support' => [Diet::EXCHANGE, Diet::CHARTISTS]],
        ];
        $vote = $this->election(4.0, [Diet::VANGUARD], [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS])->setSupport([Diet::EXCHANGE, Diet::CHARTISTS])->setFormation($log)->setFormationDays(50.0);

        $page = $this->builder([$vote])->build(new MacroStateDTO(
            totalTime: 4.0 + 25.0 / 365.0,
            sovereignDebtToGdp: 0.5,
        ), new PoliticsStateDTO(
            totalTime: 4.0 + 25.0 / 365.0,
            lastElectionAt: 4.0,
            coalitionTakesOfficeAt: 4.0 + 50.0 / 365.0,
            formationLog: $log,
        ));

        $this->assertTrue($page['talks']['underWay']);
        $this->assertEqualsWithDelta(25.0, $page['talks']['day'], 1e-9);
        $this->assertCount(1, $page['talks']['entries']);
        $this->assertFalse($page['talks']['entries'][0]['formed']);
        $this->assertSame('The Vanguard', $page['talks']['leading']['name']);
        $this->assertSame(2, $page['talks']['leading']['attempt']);
        $this->assertTrue($page['government']['caretaker']);
        $this->assertSame(['The Vanguard'], array_column($page['government']['members'], 'name'), 'The outgoing cabinet stays on.');
        $this->assertSame('caretaker', array_column($page['budget']['levers'], null, 'name')['Corporate tax rate']['status']);
        $this->assertFalse($page['history'][0]['formed']);
        $this->assertSame([], $page['history'][0]['coalition'], 'The history gives the talks away.');
        $this->assertSame([], $page['history'][0]['support']);

        $after = $this->builder([$vote])->build(new MacroStateDTO(totalTime: 4.0 + 60.0 / 365.0), new PoliticsStateDTO(totalTime: 4.0 + 60.0 / 365.0, lastElectionAt: 4.0, formationLog: $log));
        $this->assertFalse($after['talks']['underWay']);
        $this->assertCount(2, $after['talks']['entries']);
        $this->assertTrue($after['history'][0]['formed']);
        $this->assertSame(['The Vanguard'], array_column($after['history'][0]['coalition'], 'name'));
        $this->assertSame(['Exchange Party', 'The Chartists'], array_column($after['history'][0]['support'], 'name'));
    }

    /**
     * After a fall the talks count their days from the fall, and the vote's history row shows the fall at once but the
     * cabinet the talks will seat only once it takes office.
     */
    public function testTalksAfterAFallCountFromTheFallAndKeepTheirOutcome(): void
    {
        $log = [['day' => 30.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC], 'support' => [Diet::IRON_HARBOR, Diet::TIDELINE]]];
        $vote = $this->election(4.0, [Diet::VANGUARD], [Diet::VANGUARD])
            ->addFall(6.5, [Diet::VANGUARD], [Diet::CIVIC], [Diet::IRON_HARBOR, Diet::TIDELINE], $log);
        $during = new PoliticsStateDTO(
            totalTime: 6.5 + 10.0 / 365.0,
            lastElectionAt: 4.0,
            coalitionTakesOfficeAt: 6.5 + 30.0 / 365.0,
            formationLog: $log,
            talksStartedAt: 6.5,
            lastCabinetFellAt: 6.5,
        );

        $page = $this->builder([$vote])->build(new MacroStateDTO(totalTime: 6.5 + 10.0 / 365.0), $during);

        $this->assertTrue($page['talks']['underWay']);
        $this->assertTrue($page['talks']['afterFall']);
        $this->assertEqualsWithDelta(10.0, $page['talks']['day'], 1e-9);
        $this->assertSame('Year 7 Q3', $page['talks']['startedOn']);
        $fall = $page['history'][0]['falls'][0];
        $this->assertSame('Year 7 Q3', $fall['date']);
        $this->assertSame(['The Vanguard'], array_column($fall['fallen'], 'name'));
        $this->assertFalse($fall['formed']);
        $this->assertSame([], $fall['coalition'], 'The history gives the talks away.');
        $this->assertTrue($page['history'][0]['formed'], 'The vote\'s own government is long in office.');

        $after = $this->builder([$vote])->build(new MacroStateDTO(totalTime: 6.5 + 31.0 / 365.0), new PoliticsStateDTO(totalTime: 6.5 + 31.0 / 365.0, lastElectionAt: 4.0, formationLog: $log, talksStartedAt: 6.5, lastCabinetFellAt: 6.5));
        $this->assertFalse($after['talks']['underWay']);
        $this->assertSame(['Civic Front'], array_column($after['history'][0]['falls'][0]['coalition'], 'name'));
        $this->assertSame(['Iron Harbor Coalition', 'The Tideline Accord'], array_column($after['history'][0]['falls'][0]['support'], 'name'));
    }

    /** A minority cabinet's supporters are marked in the chamber and the tables, and count toward its majority. */
    public function testSupportSeatsAreMarkedAndCountTowardTheMajority(): void
    {
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(
            governingCoalition: Diet::membership([Diet::VANGUARD]),
            supportParties: Diet::membership([Diet::EXCHANGE, Diet::CHARTISTS]),
        ));

        $this->assertTrue($page['government']['minority']);
        $this->assertSame(95, $page['government']['seats']);
        $this->assertSame(140, $page['government']['supportedSeats']);
        $this->assertSame(['Exchange Party', 'The Chartists'], array_column($page['government']['support'], 'name'));
        $this->assertCount(45, array_filter($page['hemicycle'], static fn(array $seat): bool => $seat['supporting']));
        $this->assertCount(95, array_filter($page['hemicycle'], static fn(array $seat): bool => $seat['governing']));
        $this->assertSame(['wide', 'narrow'], array_keys($page['compass']['layouts']));
        foreach ($page['compass']['layouts'] as $layout) {
            $this->assertSame(Diet::AXES, array_column($layout['rows'], 'axis'), 'One line per question, in the questions\' order.');
            foreach ($layout['rows'] as $row) {
                $this->assertSame(Diet::PARTIES, array_column($row['parties'], 'key'));
                foreach ($row['parties'] as $party) {
                    $this->assertEqualsWithDelta($party[$row['axis']] * $layout['halfWidth'], $party['x'], 0.005, 'Each line places every party on its question.');
                }
            }
        }
    }

    /** A supporter will not vote to move a lever further from its own policy than it stands, and the page says so. */
    public function testASupporterBlocksTheCutItWillNotVoteFor(): void
    {
        $page = $this->builder()->build(new MacroStateDTO(
            totalTime: 0.2,
            sovereignDebtToGdp: 0.5,
        ), new PoliticsStateDTO(
            totalTime: 0.2,
            governingCoalition: Diet::membership([Diet::VANGUARD]),
            supportParties: Diet::membership([Diet::CIVIC]),
        ));
        $tax = array_column($page['budget']['levers'], null, 'name')['Corporate tax rate'];

        $this->assertLessThan(MacroEngine::TARGET_CORPORATE_TAX_RATE, $tax['platform']);
        $this->assertSame(MacroEngine::TARGET_CORPORATE_TAX_RATE, $tax['target']);
        $this->assertSame('blocked', $tax['status']);
        $this->assertFalse($page['budget']['braking']);
    }

    /** A majority government's cut waits on the Council while debt is over the line; a lever that costs no revenue waits only on the next round. */
    public function testTheBudgetShowsWhatTheCouncilHolds(): void
    {
        $rightBloc = Diet::membership([Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS, Diet::NEW_HORIZON]);
        $braked = $this->builder()->build(new MacroStateDTO(totalTime: 0.2, sovereignDebtToGdp: MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.03), new PoliticsStateDTO(totalTime: 0.2, governingCoalition: $rightBloc, supportParties: Diet::membership([])));
        $levers = array_column($braked['budget']['levers'], null, 'name');

        $this->assertTrue($braked['budget']['braking']);
        $this->assertSame('held', $levers['Corporate tax rate']['status']);
        $this->assertLessThan(MacroEngine::TARGET_CORPORATE_TAX_RATE, $levers['Corporate tax rate']['platform']);
        $this->assertSame(MacroEngine::TARGET_CORPORATE_TAX_RATE, $levers['Corporate tax rate']['enacted']);
        $this->assertSame('enacted', $levers['Average tariff on imports']['status'], 'An open government has no tariff to levy.');
        $this->assertSame('pending', $levers['Labour force growth']['status']);
        $this->assertSame('Year 1 Q3', $braked['budget']['nextRound']);

        $free = $this->builder()->build(new MacroStateDTO(totalTime: 0.2, sovereignDebtToGdp: 0.5), new PoliticsStateDTO(totalTime: 0.2, governingCoalition: $rightBloc, supportParties: Diet::membership([])));
        $this->assertFalse($free['budget']['braking']);
        $this->assertSame('pending', array_column($free['budget']['levers'], null, 'name')['Corporate tax rate']['status']);
    }

    /**
     * With parties crowded together -- six of them within half a unit on the environment, three on top of each other on
     * the Council -- or all eight at their homes, no label lands on another, on an end word or another
     * party's leader line, no leader crosses a label, and nothing runs off its line's drawing.
     *
     * @param array<string, float> $seats
     * @param array<string, array<string, float>> $positions
     */
    #[DataProvider('crowdedDiets')]
    public function testNoLabelCrowdsAnotherWhenThePartiesBunch(array $seats, array $positions): void
    {
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(
            dietSeats: $seats,
            partyPositions: $positions,
            governingCoalition: Diet::membership([Diet::CIVIC, Diet::TIDELINE]),
            supportParties: Diet::membership([Diet::COMMON_LOT, Diet::CHARTISTS]),
        ));
        $leaders = $this->assertCompassIsClean($page['compass']);
        if (isset($positions[Diet::TIDELINE])) {
            $this->assertGreaterThan(0, $leaders, 'The bunched case needs labels moved aside, or it tests nothing about leaders.');
        }
    }

    /**
     * No label lands on another, on an end word or another party's leader line, none covers its own dot,
     * every leader joins its dot to its label without lying flat, and nothing runs off
     * its line's drawing, at every width.
     *
     * @param array<string, mixed> $compass
     * @return int Leaders drawn.
     */
    private function assertCompassIsClean(array $compass): int
    {
        $size = $compass['fontSize'];
        $box = static function (string $text, float $x, float $y, string $anchor) use ($size): array {
            $width = mb_strlen($text) * $size * GovernmentPageBuilder::COMPASS_CHARACTER_WIDTH;
            $left = match ($anchor) { 'start' => $x, 'end' => $x - $width, default => $x - ($width / 2.0) };

            return [$left, $y - (0.8 * $size), $left + $width, $y + (0.2 * $size)];
        };
        $apart = static fn(array $a, array $b): bool => $a[2] <= $b[0] || $b[2] <= $a[0] || $a[3] <= $b[1] || $b[3] <= $a[1];

        $leaders = 0;
        foreach ($compass['layouts'] as $name => $layout) {
            foreach ($layout['rows'] as $row) {
                $where = "{$row['axis']} ({$name})";
                [$left, $top, $width, $height] = $row['viewBox'];
                $boxes = $layout['endsBeside'] ? array_map(static fn(array $end): array => $box($end['text'], $end['x'], $end['y'], $end['anchor']), $row['ends']) : [];
                $lines = [];
                foreach ($row['parties'] as $party) {
                    $this->assertSame(GovernmentPageBuilder::PARTY_LABELS[$party['key']], $party['label']);
                    $label = $box($party['label'], $party['labelX'], $party['labelY'], 'middle');
                    $ring = $party['r'] + ($party['governing'] || $party['supporting'] ? $compass['ringGap'] : 0.0);
                    $this->assertTrue($apart($label, [$party['x'] - $ring, -$ring, $party['x'] + $ring, $ring]), "{$party['label']}'s label covers its dot or ring on {$where}.");
                    $boxes[$party['key']] = $label;
                    if ($party['leader'] !== null) {
                        ++$leaders;
                        [$x1, $y1, $x2, $y2] = $party['leader'];
                        $lines[$party['key']] = [min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2)];
                        $this->assertSame($party['labelX'], $x2, 'A leader ends under its label.');
                        $this->assertEqualsWithDelta($party['labelY'] < 0.0 ? $label[3] : $label[1], $y2, 1.0, 'A leader reaches its label.');
                        $this->assertGreaterThan($party['r'], hypot($x1 - $party['x'], $y1), 'A leader starts outside its dot.');
                        $this->assertGreaterThanOrEqual(0.4 - 1e-6, abs($y2 - $y1) / max(1e-9, abs($x2 - $x1)), 'A leader lies too flat.');
                    }
                }
                $keys = array_keys($boxes);
                foreach ($keys as $n => $i) {
                    $a = $boxes[$i];
                    $this->assertGreaterThanOrEqual($left, $a[0], "A label runs off the left of {$where}.");
                    $this->assertLessThanOrEqual($left + $width, $a[2], "A label runs off the right of {$where}.");
                    $this->assertGreaterThanOrEqual($top, $a[1], "A label runs off the top of {$where}.");
                    $this->assertLessThanOrEqual($top + $height, $a[3], "A label runs off the bottom of {$where}.");
                    foreach (array_slice($keys, $n + 1) as $j) {
                        $this->assertTrue($apart($a, $boxes[$j]), "Two labels on {$where} overlap ({$i} and {$j}).");
                    }
                    foreach ($lines as $owner => $line) {
                        if ($owner !== $i) {
                            $this->assertTrue($apart($a, $line), "{$owner}'s leader on {$where} crosses the label {$i}.");
                        }
                    }
                }
            }
        }

        return $leaders;
    }

    /** Diets drawn at random -- seats, positions, cabinet and supporters -- lay out as cleanly as the hand-picked ones. */
    public function testRandomDietsLayOutCleanly(): void
    {
        mt_srand(20261001);
        $uniform = static fn(): float => (mt_rand() / mt_getrandmax() * 2.0) - 1.0;
        for ($draw = 0; $draw < 500; ++$draw) {
            $weights = array_map(static fn(): float => -log(max(1e-9, mt_rand() / mt_getrandmax())), Diet::PARTIES);
            $seats = array_combine(Diet::PARTIES, array_map(static fn(float $w): float => round(Diet::SEATS * $w / array_sum($weights)), $weights));
            $positions = array_fill_keys(Diet::PARTIES, []);
            foreach (Diet::PARTIES as $party) {
                foreach (Diet::AXES as $axis) {
                    $positions[$party][$axis] = mt_rand(0, 3) === 0 ? $uniform() * 0.15 : $uniform();
                }
            }
            $shuffled = Diet::PARTIES;
            shuffle($shuffled);
            $cabinet = array_slice($shuffled, 0, mt_rand(1, 4));
            $support = array_slice($shuffled, count($cabinet), mt_rand(0, 3));

            $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(
                dietSeats: $seats,
                partyPositions: $positions,
                governingCoalition: Diet::membership($cabinet),
                supportParties: Diet::membership($support),
            ));
            $this->assertCompassIsClean($page['compass']);
        }
    }

    /** @return array<string, array{array<string, float>, array<string, array<string, float>>}> */
    public static function crowdedDiets(): array
    {
        return [
            'six parties bunched' => [
                [Diet::CIVIC => 75.0, Diet::VANGUARD => 70.0, Diet::IRON_HARBOR => 38.0, Diet::EXCHANGE => 40.0, Diet::CHARTISTS => 21.0, Diet::COMMON_LOT => 14.0, Diet::TIDELINE => 24.0, Diet::NEW_HORIZON => 18.0],
                [
                    Diet::CIVIC => [Diet::AXIS_STATE => 0.6, Diet::AXIS_OPENNESS => -0.04, Diet::AXIS_COUNCIL => 0.74, Diet::AXIS_ENVIRONMENT => 0.30],
                    Diet::VANGUARD => [Diet::AXIS_STATE => -0.6, Diet::AXIS_OPENNESS => -0.93, Diet::AXIS_COUNCIL => 0.74, Diet::AXIS_ENVIRONMENT => 0.05],
                    Diet::IRON_HARBOR => [Diet::AXIS_STATE => -0.12, Diet::AXIS_OPENNESS => -0.8, Diet::AXIS_COUNCIL => -0.33, Diet::AXIS_ENVIRONMENT => 0.12],
                    Diet::EXCHANGE => [Diet::AXIS_STATE => -0.12, Diet::AXIS_OPENNESS => 0.8, Diet::AXIS_COUNCIL => 0.74, Diet::AXIS_ENVIRONMENT => 0.20],
                    Diet::CHARTISTS => [Diet::AXIS_STATE => -0.17, Diet::AXIS_OPENNESS => 0.76, Diet::AXIS_COUNCIL => 0.8, Diet::AXIS_ENVIRONMENT => 0.0],
                    Diet::COMMON_LOT => [Diet::AXIS_STATE => -0.17, Diet::AXIS_OPENNESS => 0.46, Diet::AXIS_COUNCIL => -0.8, Diet::AXIS_ENVIRONMENT => 0.25],
                    Diet::TIDELINE => [Diet::AXIS_STATE => -0.1, Diet::AXIS_OPENNESS => 0.0, Diet::AXIS_COUNCIL => 0.0, Diet::AXIS_ENVIRONMENT => 0.8],
                    Diet::NEW_HORIZON => [Diet::AXIS_STATE => -0.12, Diet::AXIS_OPENNESS => 0.7, Diet::AXIS_COUNCIL => 0.0, Diet::AXIS_ENVIRONMENT => 0.15],
                ],
            ],
            'the founding Diet' => [Diet::SEED_SEATS, Diet::HOME_POSITIONS],
            'everyone at the centre' => [Diet::SEED_SEATS, array_fill_keys(Diet::PARTIES, array_fill_keys(Diet::AXES, 0.0))],
        ];
    }

    /** A party at the end of a line has its label kept inside the drawing, in the margin past the line's end. */
    public function testALabelAtTheEdgeStaysInsideTheDrawing(): void
    {
        $positions = Diet::HOME_POSITIONS;
        $positions[Diet::EXCHANGE][Diet::AXIS_STATE] = -1.0;
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(partyPositions: $positions));
        foreach ($page['compass']['layouts'] as $name => $layout) {
            $row = array_column($layout['rows'], null, 'axis')[Diet::AXIS_STATE];
            $exchange = array_column($row['parties'], null, 'key')[Diet::EXCHANGE];
            $halfLabel = mb_strlen($exchange['label']) * $page['compass']['fontSize'] * GovernmentPageBuilder::COMPASS_CHARACTER_WIDTH / 2.0;

            $this->assertGreaterThanOrEqual($row['viewBox'][0], $exchange['labelX'] - $halfLabel);
            if ($name === 'wide') {
                $this->assertSame(-$layout['halfWidth'], $exchange['labelX'], 'With room to spare the label stays centred over its dot.');
            }
        }
    }

    /**
     * Once the Monetary Authority has formed the page shows its governor, the candidates the Council passed over and the
     * committee, each with a name, an age, a stance and seat dates, the committee's make-up, the Council's councillors
     * likewise with how the Council leans, and the last meeting's vote; before, nothing of it.
     */
    public function testThePageShowsTheMonetaryAuthority(): void
    {
        $this->assertNull($this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO())['authority']);

        mt_srand(11);
        $state = new \App\Service\Politics\PoliticsState();
        $math = new \App\Service\Math\MathUtility();
        for ($tick = 0; $tick <= 52; ++$tick) {
            $state->totalTime = $tick / 52.0;
            \App\Service\Politics\CouncilAppointments::advance($state, $math);
            \App\Service\Politics\MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $state->totalTime, policyRate: 0.04), 1.0 / 52.0, $math);
            \App\Service\Politics\FinancialRegulator::advance($state, $math);
            \App\Service\Politics\SovereignReserveFund::advance($state, $math);
        }
        $politics = PoliticsStateDTO::fromState($state);
        $page = $this->builder()->build(new MacroStateDTO(totalTime: $state->totalTime, countercyclicalBufferRate: 0.005), $politics);
        $authority = $page['authority'];

        $this->assertSame(\App\Data\AerieCouncil::OPENING_GOVERNOR, $authority['governor']['name']);
        $this->assertSame('Before Year 1', $authority['governor']['sinceLabel']);
        $this->assertCount(\App\Service\Politics\CouncilAppointments::SHORTLIST - 1, $authority['governor']['passedOver']);
        $this->assertCount(\App\Service\Politics\MonetaryAuthority::COMMITTEE_MEMBERS, $authority['members']);
        $this->assertSame($politics->memberNames, array_column($authority['members'], 'name'));
        $this->assertSame(\App\Service\Politics\MonetaryAuthority::COMMITTEE_MEMBERS + 1, $authority['committee']['hawk'] + $authority['committee']['swing'] + $authority['committee']['dove']);
        $this->assertSame($politics->committeeMajority > 0.0 ? 'hawkish' : ($politics->committeeMajority < 0.0 ? 'dovish' : null), $authority['committee']['majority']);
        $this->assertNotNull($authority['meeting']);
        $this->assertSame(\App\Service\Politics\MonetaryAuthority::COMMITTEE_MEMBERS + 1, array_sum(array_map('intval', explode('–', $authority['meeting']['split']))));
        $this->assertNull($authority['pressure'], 'No cabinet is leaning on the Authority.');
        $state->pressureSince = 0.5;
        $state->pressureGivingIn = 1.0;
        $state->governingCoalition = \App\Data\AerieDiet::membership([\App\Data\AerieDiet::COMMON_LOT, \App\Data\AerieDiet::CIVIC]);
        $pressed = $this->builder()->build(new MacroStateDTO(totalTime: $state->totalTime), PoliticsStateDTO::fromState($state))['authority']['pressure'];
        $this->assertTrue($pressed['givingIn']);
        $this->assertSame(['Civic Front', 'The Common Lot'], $pressed['cabinet']);
        $state->pressureSince = -1.0;
        $state->pressureGivingIn = 0.0;
        $this->assertSame(\App\Service\Politics\MonetaryAuthority::stanceName(\App\Service\Politics\CouncilAppointments::median($politics->councilStances)), $page['council']['lean']['median']);
        foreach ($page['council']['roster'] as $index => $seat) {
            $this->assertSame($politics->councilNames[$index], $seat['name']);
            $this->assertGreaterThanOrEqual((int) floor(\App\Service\Politics\CouncilAppointments::APPOINTMENT_AGE_MIN), $seat['age']);
            $this->assertContains($seat['stance'], ['hawk', 'swing', 'dove']);
            $this->assertContains($seat['banks'], ['light', 'middle', 'strict']);
        }

        $regulator = $page['regulator'];
        $this->assertSame(\App\Data\AerieCouncil::OPENING_REGULATOR, $regulator['head']['name']);
        $this->assertSame('Before Year 1', $regulator['head']['sinceLabel']);
        $this->assertSame('light', $regulator['head']['stance']);
        $this->assertSame([], $regulator['head']['passedOver']);
        $this->assertEqualsWithDelta(\App\Service\Math\FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $regulator['inForce'], 1e-12);
        $this->assertNull($regulator['phasing']);
        $this->assertEqualsWithDelta(0.005, $regulator['buffer'], 1e-12);
        $this->assertEqualsWithDelta(\App\Service\Politics\FinancialRegulator::requirement(\App\Service\Politics\CouncilAppointments::median($politics->councilRegulationStances)), $page['council']['lean']['banks'], 1e-12);
        $this->assertNull($this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO())['regulator']);

        $fundHead = $page['fundHead'];
        $this->assertSame(\App\Data\AerieCouncil::OPENING_FUND_HEAD, $fundHead['name']);
        $this->assertTrue($fundHead['opening']);
        $this->assertSame('bold', $fundHead['stance'], 'The fund opens bolder than the median reserve fund.');
        $this->assertEqualsWithDelta(\App\Service\Politics\SovereignReserveFund::equityShare(\App\Service\Politics\CouncilAppointments::median($politics->councilFundStances)), $page['council']['lean']['reserves'], 1e-12);
        foreach ($page['council']['roster'] as $seat) {
            $this->assertContains($seat['reserves'], ['cautious', 'balanced', 'bold']);
        }
        $this->assertNull($this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO())['fundHead']);
    }

    /**
     * While a new head's rise phases in, the page shows the requirement in force and the one it is rising to, with the
     * quarter it completes; and whom the Council passed over, with the requirement each would have set.
     */
    public function testThePageShowsARisePhasingIn(): void
    {
        $regime = \App\Service\Politics\FinancialRegulator::stance(0.1230);
        $politics = new PoliticsStateDTO(
            totalTime: 7.25,
            authoritySalt: 5.0,
            regulatorName: 'Test Head',
            regulatorBirth: -45.0,
            regulatorTermStart: 7.0,
            regulatorStance: $regime,
            regulatorPassedOver: [['name' => 'Other One', 'birth' => -50.0, 'stance' => 0.0, 'regulation' => -1.0, 'fund' => 0.0]],
            bankCapitalRequirement: 0.1012,
            requirementPhaseFrom: 0.094,
            requirementPhaseStart: 7.0,
        );
        $regulator = $this->builder()->build(new MacroStateDTO(totalTime: 7.25), $politics)['regulator'];

        $this->assertSame('strict', $regulator['head']['stance']);
        $this->assertEqualsWithDelta(0.1230, $regulator['head']['requirement'], 1e-12);
        $this->assertEqualsWithDelta(0.1012, $regulator['inForce'], 1e-12);
        $this->assertEqualsWithDelta(0.1230, $regulator['phasing']['target'], 1e-12);
        $this->assertSame(GovernmentPageBuilder::simDate(8.0), $regulator['phasing']['completeLabel']);
        $this->assertSame('Other One', $regulator['head']['passedOver'][0]['name']);
        $this->assertSame(57, $regulator['head']['passedOver'][0]['age']);
        $this->assertEqualsWithDelta(0.0843, $regulator['head']['passedOver'][0]['requirement'], 1e-12);
    }

    public function testSimulationDatesCountFromTheFounding(): void
    {
        $this->assertSame('Year 1 Q1', GovernmentPageBuilder::simDate(0.0));
        $this->assertSame('Year 4 Q4', GovernmentPageBuilder::simDate(3.99));
        $this->assertSame('Year 5 Q1', GovernmentPageBuilder::simDate(4.0));
        $this->assertSame('Year 41 Q1', GovernmentPageBuilder::simDate(40.0 - 1e-11), 'A vote the accumulated clock holds a hair short of its day.');
    }

    /**
     * On every line a coalition's parties and its supporters are marked each on its own, so no party between them looks
     * part of it, with a mark where the coalition governs from (its parties weighted by seats) reaching past every
     * member's ring; a screen reader hears who is in it and where it stands.
     */
    public function testACoalitionIsMarkedPartyByPartyWithWhereItGovernsFrom(): void
    {
        $cabinet = [Diet::CIVIC, Diet::IRON_HARBOR, Diet::TIDELINE];
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(
            governingCoalition: Diet::membership($cabinet),
            supportParties: Diet::membership([Diet::COMMON_LOT]),
        ));
        $compass = $page['compass'];

        $this->assertTrue($compass['coalitionMark']);
        foreach ($compass['layouts'] as $layout) {
            foreach ($layout['rows'] as $row) {
                $dots = array_column($row['parties'], null, 'key');
                $this->assertEqualsCanonicalizing($cabinet, array_keys(array_filter($dots, static fn(array $dot): bool => $dot['governing'])));
                $this->assertSame([Diet::COMMON_LOT], array_keys(array_filter($dots, static fn(array $dot): bool => $dot['supporting'])));
                $this->assertArrayNotHasKey('cabinet', $row, 'No shape spans the cabinet, which would take in the parties between its members.');
                $this->assertEqualsWithDelta($page['government'][$row['axis']] * $layout['halfWidth'], $row['hub'], 0.01);
                $largest = max(array_map(static fn(string $party): float => $dots[$party]['r'], $cabinet));
                $this->assertGreaterThan($largest + $compass['ringGap'], $compass['hubReach'], 'The mark shows past the ring of any member sitting on it.');
                $this->assertGreaterThanOrEqual($compass['hubReach'], -$row['viewBox'][1], 'The mark fits the drawing.');
                $this->assertStringEndsWith('; the cabinet at ' . sprintf('%+.2f', $page['government'][$row['axis']]), $row['summary'], 'A screen reader hears where the cabinet stands.');
                $this->assertStringContainsString('Civic Front (in the cabinet) at', $row['summary']);
                $this->assertStringContainsString('The Common Lot (supporting it) at', $row['summary']);
            }
        }
    }

    /** A party governing alone is its own cabinet: it is ringed, and there is no mark of where a coalition governs from. */
    public function testAPartyGoverningAloneIsItsOwnCabinet(): void
    {
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO());
        $compass = $page['compass'];

        $this->assertFalse($compass['coalitionMark']);
        foreach ($compass['layouts'] as $layout) {
            foreach ($layout['rows'] as $row) {
                $this->assertSame([Diet::VANGUARD], array_keys(array_filter(array_column($row['parties'], null, 'key'), static fn(array $dot): bool => $dot['governing'])));
                $this->assertNull($row['hub']);
                $this->assertStringNotContainsString('the cabinet at', $row['summary']);
                $this->assertCount(3, array_filter($row['parties'], static fn(array $dot): bool => $dot['supporting']), 'The founding Vanguard cabinet has three supporters.');
            }
        }
    }

    /** Each line names the laws its question sets, and every law is named on exactly one line. */
    public function testEachQuestionNamesTheLawsItSets(): void
    {
        $rows = array_column($this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO())['compass']['layouts']['wide']['rows'], 'laws', 'axis');

        $this->assertSame(['corporate tax', 'stamp duty', 'bank levy', 'reserve draw'], $rows[Diet::AXIS_STATE]);
        $this->assertSame(['tariffs', 'immigration'], $rows[Diet::AXIS_OPENNESS]);
        $this->assertSame(['merger review'], $rows[Diet::AXIS_COUNCIL]);
        $this->assertSame(['green belts', 'carbon price', 'extraction rules'], $rows[Diet::AXIS_ENVIRONMENT]);
        $this->assertCount(count(PoliticsEngine::LEVER_FIELDS), array_merge(...array_values($rows)));
    }

    /**
     * A party's page: its profile, what it would enact governing alone from where it stands against what is in force,
     * where it stands on each question, and its record, with a government that has not yet taken office kept hidden.
     */
    public function testThePartyPageCarriesItsProfilePlatformAndRecord(): void
    {
        $first = $this->election(4.0, [Diet::CIVIC, Diet::IRON_HARBOR], [Diet::VANGUARD]);
        $pending = $this->election(8.0, [Diet::IRON_HARBOR], [Diet::CIVIC, Diet::IRON_HARBOR])->setFormationDays(60.0);
        $page = $this->builder([$first, $pending])->buildParty(
            new MacroStateDTO(totalTime: 8.05),
            new PoliticsStateDTO(totalTime: 8.05, importTariffRate: 0.05),
            Diet::IRON_HARBOR
        );

        $this->assertSame('iron-harbor', $page['party']['slug']);
        $this->assertSame('Iron Harbor Coalition', $page['party']['name']);
        $this->assertNotEmpty($page['party']['about']);
        $this->assertCount(3, $page['party']['agenda']);
        $this->assertSame(['Openness'], $page['party']['definedBy']);
        $this->assertSame('the Civic Front', $page['party']['blocLeader']);

        $tariff = array_column($page['levers'], null, 'name')['Average tariff on imports'];
        $this->assertSame(PoliticsEngine::platform(Diet::position(Diet::IRON_HARBOR, Diet::HOME_POSITIONS))['tariff'], $tariff['platform']);
        $this->assertSame(PoliticsEngine::POLICY_PROTECTIONIST_TARIFF, $tariff['platform'], 'The party at the closed end enacts the protectionist tariff.');
        $this->assertSame(0.05, $tariff['enacted']);

        $axes = array_column($page['axes'], null, 'key');
        $this->assertTrue($axes[Diet::AXIS_OPENNESS]['fixed']);
        $this->assertFalse($axes[Diet::AXIS_STATE]['fixed']);
        $this->assertCount(count(Diet::PARTIES) - 1, $axes[Diet::AXIS_STATE]['others']);
        $this->assertSame(AeriePartyProfiles::PROFILES[Diet::IRON_HARBOR]['questions'][Diet::AXIS_OPENNESS], $axes[Diet::AXIS_OPENNESS]['question'], 'Each line carries what the party makes of the question.');
        $this->assertNotEmpty($page['party']['history']);

        $this->assertSame(['Year 1', 'Year 5 Q1', 'Year 9 Q1'], array_column($page['record'], 'date'));
        $this->assertSame(25, $page['record'][0]['seats']);
        $this->assertTrue($page['record'][1]['cabinet']);
        $this->assertFalse($page['record'][2]['cabinet'], 'The talks\' outcome is hidden until the cabinet takes office.');
        $this->assertSame(['votes' => 2, 'cabinet' => 1], array_intersect_key($page['recordSummary'], ['votes' => 0, 'cabinet' => 0]));
        $this->assertCount(count(Diet::PARTIES), $page['parties']);
    }

    /**
     * The prime minister is the leader of the cabinet's largest party, and stays so through a change of leader; each
     * party's row names its leader, and its page names its leader and everyone who led it before.
     */
    public function testThePagesNameThePrimeMinisterAndThePartyLeaders(): void
    {
        $math = new MathUtility();
        $state = new PoliticsState();
        $state->authoritySalt = 4242.0;
        $state->totalTime = 6.0;
        PartyLeaders::advance($state, 0.0, $math);
        $former = $state->leaderNames[Diet::VANGUARD];
        $state->leaderExitAt[Diet::VANGUARD] = 6.0;
        PartyLeaders::advance($state, 0.0, $math);
        $politics = PoliticsStateDTO::fromState($state);
        $macro = new MacroStateDTO(totalTime: 6.0);

        $page = $this->builder()->build($macro, $politics);
        $this->assertSame($state->leaderNames[Diet::VANGUARD], $page['government']['primeMinister']['name'], 'The founding cabinet is the Vanguard alone.');
        $this->assertSame('the Vanguard', $page['government']['primeMinister']['party']);
        $this->assertSame($state->leaderNames[Diet::CIVIC], array_column($page['parties'], null, 'key')[Diet::CIVIC]['partyLeader']['name']);

        $party = $this->builder()->buildParty($macro, $politics, Diet::VANGUARD);
        $this->assertSame($state->leaderNames[Diet::VANGUARD], $party['party']['partyLeader']['name']);
        $this->assertSame([$state->leaderNames[Diet::VANGUARD], $former], array_column($party['leaders'], 'name'), 'The present leader first, then those before.');
        $this->assertSame(['today', self::simDate(6.0)], array_column($party['leaders'], 'toLabel'));

        $this->assertNull($this->builder()->build($macro, new PoliticsStateDTO())['government']['primeMinister'], 'No one is named before the leaders are drawn.');
    }

    /**
     * The polls panel draws each party's line from the last result across the term to the latest poll, lists the
     * parties by the latest poll with the seats it would give them, and counts the cabinet's; none shows before the
     * term's first poll.
     */
    public function testThePollsRunFromTheLastResultToTheLatestPoll(): void
    {
        $this->assertNull($this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO())['polls']);

        $first = array_merge(Diet::SEED_VOTE_SHARES, [Diet::CIVIC => Diet::SEED_VOTE_SHARES[Diet::CIVIC] - 0.02, Diet::VANGUARD => Diet::SEED_VOTE_SHARES[Diet::VANGUARD] + 0.02]);
        $latest = array_merge(Diet::SEED_VOTE_SHARES, [Diet::CIVIC => Diet::SEED_VOTE_SHARES[Diet::CIVIC] - 0.06, Diet::VANGUARD => Diet::SEED_VOTE_SHARES[Diet::VANGUARD] + 0.06]);
        $politics = new PoliticsStateDTO(totalTime: 6.0, lastElectionAt: 4.0, polls: [['t' => 4.5, 'shares' => $first], ['t' => 6.0, 'shares' => $latest]]);
        $polls = $this->builder()->build(new MacroStateDTO(totalTime: 6.0), $politics)['polls'];

        $this->assertSame('Year 5 Q1', $polls['since']);
        $this->assertSame('Year 7 Q1', $polls['latest']);
        $this->assertSame(['Year 5', 'Year 6', 'Year 7', 'Year 8', 'Year 9'], array_column($polls['years'], 'label'));
        [$left, $right] = $polls['plot'];
        $this->assertEqualsWithDelta(($left + $right) / 2.0, $polls['today'], 0.1, 'Half the term has run.');

        $rows = array_column($polls['parties'], null, 'name');
        $vanguard = $rows[Diet::PARTY_NAMES[Diet::VANGUARD]];
        $this->assertSame(Diet::PARTY_NAMES[Diet::VANGUARD], $polls['parties'][0]['name'], 'Listed by the poll average.');
        $averages = GovernmentPageBuilder::pollAverages($politics);
        $average = $averages[array_key_last($averages)]['shares'];
        $this->assertGreaterThan(Diet::SEED_VOTE_SHARES[Diet::VANGUARD], $average[Diet::VANGUARD], 'The polls pull the average up from the result...');
        $this->assertLessThan($latest[Diet::VANGUARD], $average[Diet::VANGUARD], '...but not all the way to one noisy poll.');
        $this->assertEqualsWithDelta($average[Diet::VANGUARD], $vanguard['average'], 1e-12);
        $this->assertEqualsWithDelta($latest[Diet::VANGUARD], $vanguard['poll'], 1e-12);
        $this->assertEqualsWithDelta($average[Diet::VANGUARD] - Diet::SEED_VOTE_SHARES[Diet::VANGUARD], $vanguard['change'], 1e-12);
        $this->assertGreaterThan(0.0, $vanguard['margin']);
        $this->assertCount(3, explode(' ', $vanguard['line']), 'The result, then the average after each poll.');
        $this->assertCount(6, explode(' ', $vanguard['band']), 'The band\'s upper edge out, its lower edge back.');
        $this->assertCount(2, $vanguard['dots'], 'One dot a poll.');
        $this->assertStringStartsWith($left . ',', $vanguard['line']);
        $this->assertFalse($polls['marketSeats'], 'With no forecast for the vote, the seats are the average\'s own.');
        $seats = PoliticsEngine::dHondt($average, Diet::SEATS);
        $this->assertSame($seats[Diet::VANGUARD], $vanguard['seats']);
        $this->assertSame(Diet::SEATS, array_sum(array_column($polls['parties'], 'seats')));
        $this->assertSame($seats[Diet::VANGUARD], $polls['cabinet']['seats'], 'The founding cabinet is the Vanguard alone.');
        $this->assertSame($seats[Diet::VANGUARD] + $seats[Diet::EXCHANGE] + $seats[Diet::CHARTISTS] + $seats[Diet::NEW_HORIZON], $polls['cabinet']['supportedSeats']);

        $party = $this->builder()->buildParty(new MacroStateDTO(totalTime: 6.0), $politics, Diet::VANGUARD)['polling'];
        $this->assertEqualsWithDelta($latest[Diet::VANGUARD], $party['share'], 1e-12);
        $this->assertEqualsWithDelta(0.06, $party['change'], 1e-12);
        $this->assertSame('Year 7 Q1', $party['date']);
        $this->assertNull($this->builder()->buildParty(new MacroStateDTO(), new PoliticsStateDTO(), Diet::VANGUARD)['polling']);
    }

    /**
     * The market panel lists the parties by their chance of leading the next government, the likeliest governments by
     * name, and each law in force against the one expected, the corporate tax as a rate; none shows before a forecast.
     */
    public function testTheMarketPanelShowsTheOddsAndTheLawsExpected(): void
    {
        $this->assertNull($this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO())['market']);

        $standing = PoliticsEngine::standingLevers(new PoliticsStateDTO());
        $politics = new PoliticsStateDTO(
            totalTime: 6.0,
            forecastAt: 6.0,
            forecastFor: 8.0,
            forecastLeaders: [Diet::CIVIC => 0.62, Diet::VANGUARD => 0.375, Diet::EXCHANGE => 0.005] + array_fill_keys(Diet::PARTIES, 0.0),
            forecastCabinets: [['cabinet' => [Diet::CIVIC, Diet::IRON_HARBOR], 'support' => [Diet::COMMON_LOT], 'chance' => 0.41]],
            forecastLevers: ['corporateTax' => 0.03, 'bankLevyRate' => 0.0015] + $standing,
        );
        $market = $this->builder()->build(new MacroStateDTO(totalTime: 6.0), $politics)['market'];

        $this->assertFalse($market['talks']);
        $this->assertSame('Year 9 Q1', $market['vote']);
        $this->assertSame('Year 9 Q3', $market['takesEffect']);
        $this->assertSame([Diet::PARTY_NAMES[Diet::CIVIC], Diet::PARTY_NAMES[Diet::VANGUARD]], array_column($market['leaders'], 'name'), 'Likeliest first; a chance under a point is left off.');
        $this->assertSame([Diet::PARTY_NAMES[Diet::CIVIC], Diet::PARTY_NAMES[Diet::IRON_HARBOR]], $market['cabinets'][0]['cabinet']);
        $this->assertSame([Diet::PARTY_NAMES[Diet::COMMON_LOT]], $market['cabinets'][0]['support']);

        $levers = array_column($market['levers'], null, 'name');
        $this->assertEqualsWithDelta(MacroEngine::TARGET_CORPORATE_TAX_RATE, $levers['Corporate tax rate']['enacted'], 1e-12);
        $this->assertEqualsWithDelta(MacroEngine::TARGET_CORPORATE_TAX_RATE + 0.03, $levers['Corporate tax rate']['expected'], 1e-12);
        $this->assertTrue($levers['Bank levy']['moves']);
        $this->assertFalse($levers['Average tariff on imports']['moves']);
    }

    /**
     * The Tideline Accord's page: founded on the environment alone, and governing alone it would enact the strictest
     * green belt (the 90th percentile of refusals), the EU ETS carbon price and the strictest extraction rules.
     */
    public function testTheTidelinePageShowsTheEnvironmentLevers(): void
    {
        $page = $this->builder()->buildParty(new MacroStateDTO(), new PoliticsStateDTO(), Diet::TIDELINE);
        $levers = array_column($page['levers'], null, 'name');

        $this->assertSame(['The environment'], $page['party']['definedBy']);
        $this->assertEqualsWithDelta(0.254 + (1.2816 * 0.087), $levers['Housing schemes refused']['platform'], 1e-12);
        $this->assertEqualsWithDelta(0.254 - (1.2816 * 0.087), $levers['Housing schemes refused']['enacted'], 1e-12, 'The founding plan is in force.');
        $this->assertSame('usd_t', $levers['Carbon price']['unit']);
        $this->assertSame(PoliticsEngine::POLICY_GREEN_CARBON_PRICE, $levers['Carbon price']['platform']);
        $this->assertSame(0.0, $levers['Carbon price']['enacted']);
        $this->assertEqualsWithDelta((1.0 / 0.952) - 1.0, $levers['Extraction compliance cost']['platform'], 1e-12);
    }

    /** A Civic cabinet's budget puts a carbon price in front of the Diet, and the budget page names what it adds to power. */
    public function testTheBudgetCarriesTheEnvironmentLevers(): void
    {
        $page = $this->builder()->build(new MacroStateDTO(totalTime: 0.2, sovereignDebtToGdp: 0.5), new PoliticsStateDTO(
            totalTime: 0.2,
            governingCoalition: Diet::membership([Diet::CIVIC]),
            supportParties: Diet::membership([Diet::IRON_HARBOR, Diet::COMMON_LOT, Diet::TIDELINE]),
            carbonPrice: 10.0,
        ));
        $carbon = array_column($page['budget']['levers'], null, 'name')['Carbon price'];

        $this->assertSame(Diet::AXIS_ENVIRONMENT, $carbon['axis']);
        $this->assertGreaterThan(10.0, $carbon['platform']);
        $this->assertSame(10.0, $carbon['enacted']);
        $this->assertStringContainsString('MWh', $carbon['note']);
        $this->assertContains(Diet::AXIS_ENVIRONMENT, array_column($page['axes'], 'key'));
    }

    /**
     * While the market's forecast is for the coming vote, the seats beside the polls are the ones it expects, so they
     * agree with its odds; the cabinet's tally is their sum.
     */
    public function testThePollsShowTheSeatsTheMarketExpects(): void
    {
        $expected = [Diet::CIVIC => 101.4, Diet::VANGUARD => 88.6, Diet::IRON_HARBOR => 24.2, Diet::EXCHANGE => 26.0, Diet::CHARTISTS => 19.3, Diet::COMMON_LOT => 9.1, Diet::TIDELINE => 8.4, Diet::NEW_HORIZON => 23.0];
        $politics = new PoliticsStateDTO(totalTime: 6.0, lastElectionAt: 4.0, polls: [['t' => 6.0, 'shares' => Diet::SEED_VOTE_SHARES]], forecastSeats: $expected, forecastFor: 8.0, forecastAt: 6.0);
        $polls = $this->builder()->build(new MacroStateDTO(totalTime: 6.0), $politics)['polls'];

        $this->assertTrue($polls['marketSeats']);
        $rows = array_column($polls['parties'], null, 'name');
        $this->assertSame(101, $rows[Diet::PARTY_NAMES[Diet::CIVIC]]['seats']);
        $this->assertSame(89, $rows[Diet::PARTY_NAMES[Diet::VANGUARD]]['seats']);
        $this->assertSame(89, $polls['cabinet']['seats'], 'The founding cabinet is the Vanguard alone.');
        $this->assertSame((int) round(88.6 + 26.0 + 19.3 + 23.0), $polls['cabinet']['supportedSeats']);
    }

    /**
     * The average the page draws is the one the market keeps: replaying the filter over the term's polls lands on the
     * average and variance ElectionForecast stored, from the reset to the result on the day of the vote.
     */
    public function testThePollAveragePathEndsOnTheMarketsAverage(): void
    {
        $state = new PoliticsState();
        $state->authoritySalt = 5.0;
        $state->totalTime = 4.0;
        $state->lastElectionAt = 4.0;
        $state->polls = [];
        ElectionForecast::advance($state, 0.6);

        foreach ([1, 2, 4, 5] as $month) {
            $state->totalTime = 4.0 + ($month / 12.0);
            $shares = Diet::SEED_VOTE_SHARES;
            $shares[Diet::CIVIC] += 0.004 * $month;
            $shares[Diet::VANGUARD] -= 0.004 * $month;
            $state->polls[] = ['t' => $state->totalTime, 'shares' => $shares];
            ElectionForecast::advance($state, 0.6);
        }
        $politics = PoliticsStateDTO::fromState($state);

        $averages = GovernmentPageBuilder::pollAverages($politics);
        $last = $averages[array_key_last($averages)];
        $this->assertCount(5, $averages, 'The result, then the average after each poll.');
        foreach (Diet::PARTIES as $party) {
            $this->assertEqualsWithDelta($politics->pollAverage[$party], $last['shares'][$party], 1e-12, "{$party}'s average.");
            $this->assertEqualsWithDelta($politics->pollAverageVariance[$party], $last['variance'][$party], 1e-15, "{$party}'s variance.");
        }
    }


    /** The last decision reads as a hold only when it left the printed rate unchanged, and the next meeting is on the grid. */
    public function testTheRateDecisionAndTheNextMeeting(): void
    {
        $politics = static fn(float $change): PoliticsStateDTO => new PoliticsStateDTO(totalTime: 3.30, authoritySalt: 5.0, lastMeetingAt: 3.25, lastMeetingRate: 0.04, lastMeetingChange: $change);

        $this->assertSame(12, $this->builder()->build(new MacroStateDTO(totalTime: 3.30), $politics(0.0012))['authority']['meeting']['moveBp'], 'A 12bp move is a move.');
        $this->assertSame(-1, $this->builder()->build(new MacroStateDTO(totalTime: 3.30), $politics(-0.00006))['authority']['meeting']['moveBp']);
        $this->assertSame(0, $this->builder()->build(new MacroStateDTO(totalTime: 3.30), $politics(0.00004))['authority']['meeting']['moveBp'], 'Under half a basis point the printed rate does not move.');

        $this->assertEqualsWithDelta(3.375, GovernmentPageBuilder::nextMeeting(3.30), 1e-12);
        $this->assertEqualsWithDelta(3.375, GovernmentPageBuilder::nextMeeting(3.25), 1e-12, 'On a meeting day the next is a meeting on.');
        $this->assertEqualsWithDelta(3.375, GovernmentPageBuilder::nextMeeting(3.25 - 1e-12), 1e-12, 'A clock a hair short of the meeting it just held.');
        $this->assertEqualsWithDelta(3.25, GovernmentPageBuilder::nextMeeting(3.2), 1e-12);
        $this->assertSame(\App\Data\DistrictCalendar::dateline(3.375), $this->builder()->build(new MacroStateDTO(totalTime: 3.30), $politics(0.0))['authority']['nextMeeting']);
    }

    /**
     * The law charts run from the first quarter recorded to today on one time axis, each a step at the quarter its law
     * changed, the merger line read as an HHI as the budget reads it; behind them the cabinets that governed, each in
     * its leading party's colour, the one before the first vote on record from before Year 1.
     */
    public function testTheLawsAreChartedQuarterByQuarterBehindTheCabinets(): void
    {
        $standing = PoliticsEngine::standingLevers(new PoliticsStateDTO());
        $rows = [];
        foreach ([3.0, 3.25, 3.5, 3.75, 4.0, 4.25] as $time) {
            $levers = $standing;
            $levers['bankLevyRate'] = $time >= 4.25 ? 0.002 : 0.001;
            $rows[] = ['t' => $time, 'levers' => $levers, 'capitalRequirement' => 0.0843];
        }
        $rows[0]['levers']['carbonPrice'] = null; // A quarter recorded before the column existed.
        $reports = $this->createStub(MacroReportHistoryRepository::class);
        $reports->method('laws')->willReturn($rows);
        $elections = $this->createStub(DietElectionRepository::class);
        $elections->method('findChronological')->willReturn([$this->election(4.0, [Diet::CIVIC, Diet::IRON_HARBOR], [Diet::VANGUARD])]);
        $politics = new PoliticsStateDTO(totalTime: 4.4, bankLevyRate: 0.002, governingCoalition: Diet::membership([Diet::CIVIC, Diet::IRON_HARBOR]));

        $laws = (new GovernmentPageBuilder($elections, $reports))->build(new MacroStateDTO(totalTime: 4.4), $politics)['laws'];

        $this->assertSame('Year 4 Q1', $laws['since']);
        [$left, $right] = $laws['plot'];
        $charts = array_column($laws['charts'], null, 'name');
        $levy = $charts['Bank levy'];
        $this->assertSame(1, $levy['changes']);
        $this->assertEqualsWithDelta(0.002, $levy['now'], 1e-12);
        $this->assertEqualsWithDelta(0.001, $levy['low']['value'], 1e-12);
        $this->assertStringStartsWith($left . ',', $levy['line']);
        $this->assertStringEndsWith((string) $right . ',' . $levy['high']['y'], $levy['line'], 'The line runs on to today.');
        $this->assertCount(2 * 7 - 1, explode(' ', $levy['line']), 'Each point after the first is a step: across, then up or down.');
        $this->assertTrue($charts['Corporate tax rate']['flat']);
        $this->assertSame(0, $charts['Corporate tax rate']['changes']);
        $this->assertEqualsWithDelta(MergerAndAcquisitionEngine::reviewScreens($standing['mergerReviewLeniency'])['concentrated'], $charts['Merger review line']['now'], 1e-12);
        $this->assertArrayHasKey('Core capital requirement', $charts);

        $this->assertCount(2, $laws['cabinets']);
        [$before, $after] = $laws['cabinets'];
        $this->assertSame(['Vanguard'], $before['members']);
        $this->assertSame('Before Year 1', $before['fromLabel']);
        $this->assertSame(GovernmentPageBuilder::PARTY_COLORS[Diet::VANGUARD], $before['color']);
        $this->assertSame(['Civic', 'Iron Harbor'], $after['members']);
        $this->assertSame(GovernmentPageBuilder::PARTY_COLORS[Diet::CIVIC], $after['color'], 'Led by its largest party.');
        $this->assertSame('now', $after['toLabel']);
        $this->assertEqualsWithDelta($left, $before['x0'], 1e-9);
        $this->assertEqualsWithDelta($before['x1'], $after['x0'], 1e-9);
        $this->assertEqualsWithDelta($right, $after['x1'], 1e-9);

        $this->assertNull($this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO())['laws'], 'Without a record there is nothing to chart.');
    }

    /**
     * The rate chart marks every meeting on record at the rate it left, a dissent above or below it by its side, and a
     * new governor where the chair changed hands; the value axis spans every rate on whole gridline steps.
     */
    public function testTheRateChartMarksEachMeetingItsDissentsAndANewGovernor(): void
    {
        $this->assertNull(GovernmentPageBuilder::rateChart([$this->decision(1.0, 0.03, 0.0, [0.0, 0.0], 'Ada Marsh')], 1.1), 'One meeting is no line.');

        $decisions = [
            $this->decision(1.0, 0.0300, 0.0, [0.0, 0.0, 0.0], 'Ada Marsh'),
            $this->decision(1.125, 0.0325, 0.0025, [0.0, 1.0, 1.0], 'Ada Marsh'),
            $this->decision(1.25, 0.0326, 0.0001, [0.0, 0.0, -1.0], 'Ben Okafor'),
            $this->decision(1.375, 0.0350, 0.0025, [0.0, 0.0, 0.0], 'Ben Okafor'),
        ];
        $chart = GovernmentPageBuilder::rateChart($decisions, 1.4);
        $this->assertNotNull($chart);

        $this->assertCount(4, $chart['meetings']);
        $this->assertSame(2, $chart['moves'], 'Two meetings moved the rate a full step; a basis point is the rule drifting.');
        $this->assertSame(2, $chart['split'], 'Two meetings split the committee.');
        $this->assertSame([25, 1], [$chart['meetings'][1]['moveBp'], $chart['meetings'][2]['moveBp']]);
        $this->assertSame([2, 0], [$chart['meetings'][1]['higher'], $chart['meetings'][1]['lower']]);
        $this->assertSame([0, 1], [$chart['meetings'][2]['higher'], $chart['meetings'][2]['lower']]);
        $this->assertSame('1–2', $chart['meetings'][1]['split']);
        $this->assertSame([['x' => $chart['meetings'][2]['x'], 'name' => 'Ben Okafor', 'date' => self::simDate(1.25)]], $chart['governors']);

        [$left, $right, $top, $bottom] = $chart['plot'];
        $this->assertSame($left, $chart['meetings'][0]['x']);
        $this->assertLessThan($right, $chart['meetings'][3]['x'], 'The axis runs on to today, past the last meeting.');
        $this->assertLessThan($chart['meetings'][0]['y'], $chart['meetings'][3]['y'], 'A higher rate sits higher.');
        $rates = array_column($chart['gridlines'], 'rate');
        $this->assertLessThanOrEqual(0.03 + 1e-12, min($rates));
        $this->assertGreaterThanOrEqual(0.035 - 1e-12, max($rates));
        foreach ($chart['meetings'] as $meeting) {
            $this->assertGreaterThanOrEqual($top, $meeting['y']);
            $this->assertLessThanOrEqual($bottom, $meeting['y']);
        }
    }

    /**
     * The odds chart holds each party's chance from one forecast to the next, as the odds stood between polls, and runs
     * it on to today; a party that never reached the listed chance is left off, and the vote ahead is marked.
     */
    public function testTheOddsChartHoldsEachForecastUntilTheNext(): void
    {
        $leaders = static fn(float $civic): array => [Diet::CIVIC => $civic, Diet::VANGUARD => 1.0 - $civic - 0.004, Diet::EXCHANGE => 0.004] + array_fill_keys(Diet::PARTIES, 0.0);
        $forecasts = [$this->odds(4.1, $leaders(0.5)), $this->odds(4.2, $leaders(0.6)), $this->odds(4.3, $leaders(0.7))];
        $this->assertNull(GovernmentPageBuilder::oddsChart([$forecasts[0]], 4.35, 8.0));

        $chart = GovernmentPageBuilder::oddsChart($forecasts, 4.35, 8.0);
        $this->assertNotNull($chart);
        $this->assertSame([Diet::PARTY_NAMES[Diet::CIVIC], Diet::PARTY_NAMES[Diet::VANGUARD]], array_column($chart['parties'], 'name'), 'Likeliest first; a party under the listed chance throughout is left off.');

        $civic = $chart['parties'][0];
        $this->assertEqualsWithDelta(0.7, $civic['chance'], 1e-12);
        $this->assertEqualsWithDelta(0.5, $civic['first'], 1e-12);
        $points = array_map(static fn(string $point): array => array_map('floatval', explode(',', $point)), explode(' ', $civic['line']));
        $this->assertCount(6, $points, 'Three forecasts, a step up to each after the first, and a run on to today.');
        $this->assertSame($points[1][0], $points[2][0], 'The step is vertical, at the second forecast.');
        $this->assertSame($points[0][1], $points[1][1], 'The odds hold flat until the next forecast.');
        $this->assertSame($points[4][1], $points[5][1]);
        $this->assertSame($chart['today'], $points[5][0]);
        $this->assertNotNull($chart['vote']);
        $this->assertSame(self::simDate(8.0), $chart['vote']['date']);
        $this->assertSame($chart['plot'][1], $chart['vote']['x'], 'The axis runs to the vote.');
    }

    /** The page reads the meetings over the chart's span and the forecasts of the vote the market's latest forecast is for. */
    public function testThePageChartsTheRecordedMeetingsAndForecasts(): void
    {
        $elections = $this->createStub(DietElectionRepository::class);
        $elections->method('findChronological')->willReturn([]);
        $decisions = $this->createMock(RateDecisionRepository::class);
        $decisions->expects($this->once())->method('findSince')->with(5.3 - GovernmentPageBuilder::RATE_CHART_YEARS)
            ->willReturn([$this->decision(5.0, 0.04, 0.0, [0.0], 'Ada Marsh'), $this->decision(5.125, 0.0425, 0.0025, [0.0], 'Ada Marsh')]);
        $odds = $this->createMock(ElectionOddsRepository::class);
        $odds->expects($this->once())->method('findForVote')->with(8.0, $this->greaterThan(0.0))
            ->willReturn([$this->odds(5.0, [Diet::CIVIC => 0.5]), $this->odds(5.2, [Diet::CIVIC => 0.6])]);

        $politics = new PoliticsStateDTO(totalTime: 5.3, authoritySalt: 5.0, forecastAt: 5.2, forecastFor: 8.0, lastMeetingAt: 5.125, lastMeetingRate: 0.0425);
        $page = (new GovernmentPageBuilder($elections, null, null, $decisions, $odds))->build(new MacroStateDTO(totalTime: 5.3), $politics);

        $this->assertCount(2, $page['authority']['decisions']['meetings']);
        $this->assertCount(1, $page['odds']['parties']);
        $this->assertNull($this->builder()->build(new MacroStateDTO(totalTime: 5.3), $politics)['odds'], 'Without a record there is nothing to chart.');
        $this->assertNull($this->builder()->build(new MacroStateDTO(totalTime: 5.3), $politics)['authority']['decisions']);
    }

    /** @param list<float> $votes */
    private function decision(float $at, float $rate, float $change, array $votes, string $governor): RateDecision
    {
        return (new RateDecision())->setSimTime($at)->setRate($rate)->setRateChange($change)->setVotes($votes)->setGovernor($governor);
    }

    /** @param array<string, float> $leaders */
    private function odds(float $at, array $leaders): ElectionOdds
    {
        return (new ElectionOdds())->setSimTime($at)->setVoteAt(8.0)->setLeaders($leaders);
    }

    private function election(float $at, array $coalition, array $outgoing): DietElection
    {
        return (new DietElection())
            ->setSimTime($at)
            ->setSeats(array_map('intval', Diet::SEED_SEATS))
            ->setVoteShares(Diet::SEED_VOTE_SHARES)
            ->setVoteSwings(array_fill_keys(Diet::PARTIES, 0.0))
            ->setPositions(Diet::HOME_POSITIONS)
            ->setCoalition($coalition)
            ->setOutgoingCoalition($outgoing);
    }

    /** A simulation time as the page names it. */
    private static function simDate(float $time): string
    {
        return (new \ReflectionMethod(GovernmentPageBuilder::class, 'simDate'))->invoke(null, $time);
    }
}

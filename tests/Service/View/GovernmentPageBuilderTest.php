<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Macro\MacroEngine;
use App\Service\Politics\PoliticsEngine;
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

    public function testTheFoundingDietIsDrawnSeatForSeat(): void
    {
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO());

        $this->assertSame(['The Vanguard'], array_column($page['government']['members'], 'name'));
        $this->assertSame(85, $page['government']['seats']);
        $this->assertSame(['Exchange Party', 'The Chartists', 'New Horizon'], array_column($page['government']['support'], 'name'));
        $this->assertSame(160, $page['government']['supportedSeats']);
        $this->assertTrue($page['government']['minority']);
        $this->assertSame(
            [75 + 35 + 15 + 15, 85 + 35 + 20 + 20],
            array_column($page['rules']['blocSeats'], 'seats'),
            'The blocs: the Civic Front with the Harbor, the Common Lot and the Accord; the Vanguard with the Exchange, the Chartists and New Horizon.'
        );
        $tags = array_column($page['parties'], 'bloc', 'key');
        $this->assertSame('Vanguard', $tags[Diet::CHARTISTS]);
        $this->assertSame('Civic', $tags[Diet::IRON_HARBOR]);
        $this->assertNull($page['talks'], 'Before any vote there are no talks to show.');
        $this->assertFalse($page['government']['supermajority']);
        $this->assertSame('At the founding', $page['government']['formed']);

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
        foreach ($page['compass']['parties'] as $party) {
            $this->assertCount(2, $party['trail']);
            foreach ($party['strips'] as $strip) {
                $this->assertCount(2, $strip['trail']);
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
        $this->assertSame(85, $page['government']['seats']);
        $this->assertSame(140, $page['government']['supportedSeats']);
        $this->assertSame(['Exchange Party', 'The Chartists'], array_column($page['government']['support'], 'name'));
        $this->assertCount(55, array_filter($page['hemicycle'], static fn(array $seat): bool => $seat['supporting']));
        $this->assertCount(85, array_filter($page['hemicycle'], static fn(array $seat): bool => $seat['governing']));
        $this->assertSame([Diet::AXIS_COUNCIL, Diet::AXIS_ENVIRONMENT], array_column($page['compass']['strips'], 'axis'));
        foreach ($page['compass']['parties'] as $party) {
            foreach ($party['strips'] as $axis => $strip) {
                $this->assertEqualsWithDelta($party[$axis] * 100.0, $strip['x'], 1e-9, 'Each strip places every party on its axis.');
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
     * With parties crowded together -- the Chartists, the Exchange Party and the Common Lot close on the plane, the
     * Civic Front, the Vanguard and the Chartists close on the Council strip -- or all eight at their homes, no label
     * lands on another or on an axis name, and none runs off the drawing.
     *
     * @param array<string, float> $seats
     * @param array<string, array<string, float>> $positions
     */
    #[DataProvider('crowdedDiets')]
    public function testNoLabelCrowdsAnotherWhenThePartiesBunch(array $seats, array $positions): void
    {
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(dietSeats: $seats, partyPositions: $positions));
        $compass = $page['compass'];
        $size = $compass['fontSize'];
        $box = static function (string $text, float $x, float $y, string $anchor) use ($size): array {
            $width = mb_strlen($text) * $size * GovernmentPageBuilder::COMPASS_CHARACTER_WIDTH;
            $left = match ($anchor) { 'start' => $x, 'end' => $x - $width, default => $x - ($width / 2.0) };

            return [$left, $y - (0.8 * $size), $left + $width, $y + (0.2 * $size)];
        };
        $apart = static fn(array $a, array $b): bool => $a[2] <= $b[0] || $b[2] <= $a[0] || $a[3] <= $b[1] || $b[3] <= $a[1];
        [$left, $top, $width, $height] = $compass['viewBox'];

        $plane = array_map(static fn(array $axis): array => $box($axis['text'], $axis['x'], $axis['y'], $axis['anchor']), $compass['axes']);
        $sets = ['plane' => $plane];
        foreach ($compass['strips'] as $strip) {
            $sets[$strip['axis']] = array_map(static fn(array $end): array => $box($end['text'], $end['x'], $strip['y'] + $end['y'], $end['anchor']), $strip['ends']);
        }
        foreach ($compass['parties'] as $party) {
            $this->assertSame(GovernmentPageBuilder::PARTY_LABELS[$party['key']], $party['label']);
            $sets['plane'][] = $box($party['label'], $party['labelX'], $party['labelY'], $party['labelAnchor']);
            foreach ($compass['strips'] as $strip) {
                $place = $party['strips'][$strip['axis']];
                $sets[$strip['axis']][] = $box($party['label'], $place['labelX'], $strip['y'] + $place['labelY'], 'middle');
            }
        }
        foreach ($sets as $name => $boxes) {
            foreach ($boxes as $i => $a) {
                $this->assertGreaterThanOrEqual($left, $a[0], "A {$name} label runs off the left.");
                $this->assertLessThanOrEqual($left + $width, $a[2], "A {$name} label runs off the right.");
                foreach (array_slice($boxes, $i + 1, null, true) as $j => $b) {
                    $this->assertTrue($apart($a, $b), "Two {$name} labels overlap ({$i} and {$j}).");
                }
            }
        }
        foreach ($sets['plane'] as $a) {
            $this->assertGreaterThanOrEqual($top, $a[1]);
            $this->assertLessThanOrEqual($compass['strips'][0]['y'] - 16.0, $a[3], 'A plane label runs into the strip.');
        }
        $stripBoxes = array_merge(...array_values(array_diff_key($sets, ['plane' => true])));
        foreach ($stripBoxes as $i => $a) {
            $this->assertLessThanOrEqual($top + $height, $a[3], 'A strip label runs off the bottom.');
            foreach (array_slice($stripBoxes, $i + 1, null, true) as $b) {
                $this->assertTrue($apart($a, $b), 'Labels on two strips overlap.');
            }
        }
    }

    /** @return array<string, array{array<string, float>, array<string, array<string, float>>}> */
    public static function crowdedDiets(): array
    {
        return [
            'six parties bunched' => [
                [Diet::CIVIC => 83.0, Diet::VANGUARD => 84.0, Diet::IRON_HARBOR => 48.0, Diet::EXCHANGE => 50.0, Diet::CHARTISTS => 21.0, Diet::COMMON_LOT => 14.0],
                [
                    Diet::CIVIC => [Diet::AXIS_STATE => 0.6, Diet::AXIS_OPENNESS => -0.04, Diet::AXIS_COUNCIL => 0.74],
                    Diet::VANGUARD => [Diet::AXIS_STATE => -0.6, Diet::AXIS_OPENNESS => -0.93, Diet::AXIS_COUNCIL => 0.55],
                    Diet::IRON_HARBOR => [Diet::AXIS_STATE => -0.12, Diet::AXIS_OPENNESS => -0.8, Diet::AXIS_COUNCIL => -0.33],
                    Diet::EXCHANGE => [Diet::AXIS_STATE => -0.12, Diet::AXIS_OPENNESS => 0.8, Diet::AXIS_COUNCIL => -0.04],
                    Diet::CHARTISTS => [Diet::AXIS_STATE => -0.17, Diet::AXIS_OPENNESS => 0.76, Diet::AXIS_COUNCIL => 0.8],
                    Diet::COMMON_LOT => [Diet::AXIS_STATE => -0.17, Diet::AXIS_OPENNESS => 0.46, Diet::AXIS_COUNCIL => -0.8],
                ],
            ],
            'the founding Diet' => [Diet::SEED_SEATS, Diet::HOME_POSITIONS],
        ];
    }

    /** A big party at the top of the plane has its label moved off the top edge, beneath its dot, rather than off the drawing. */
    public function testALabelAtTheEdgeStaysInsideTheDrawing(): void
    {
        $positions = Diet::HOME_POSITIONS;
        $positions[Diet::EXCHANGE][Diet::AXIS_STATE] = 1.0;
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(
            dietSeats: [Diet::CIVIC => 60.0, Diet::VANGUARD => 60.0, Diet::IRON_HARBOR => 20.0, Diet::EXCHANGE => 130.0, Diet::CHARTISTS => 20.0, Diet::COMMON_LOT => 10.0],
            partyPositions: $positions,
        ));
        $exchange = array_values(array_filter($page['compass']['parties'], static fn(array $party): bool => $party['key'] === Diet::EXCHANGE))[0];

        $this->assertGreaterThan($exchange['y'], $exchange['labelY'], 'Above the dot the label would run off the top.');
        $this->assertGreaterThanOrEqual($page['compass']['viewBox'][1], $exchange['labelY'] - (0.8 * $page['compass']['fontSize']));
    }

    public function testSimulationDatesCountFromTheFounding(): void
    {
        $this->assertSame('Year 1 Q1', GovernmentPageBuilder::simDate(0.0));
        $this->assertSame('Year 4 Q4', GovernmentPageBuilder::simDate(3.99));
        $this->assertSame('Year 5 Q1', GovernmentPageBuilder::simDate(4.0));
    }

    /**
     * @param list<string> $coalition
     * @param list<string> $outgoing
     */
    /**
     * The cabinet is a shaded area over its parties, a ring where it governs from (its parties weighted by seats), solid
     * lines from there to its parties and dotted ones to its supporters; a party inside the others adds no corner.
     */
    public function testTheCabinetIsShadedOverItsPartiesAndLinkedToItsSupporters(): void
    {
        $positions = Diet::HOME_POSITIONS;
        $positions[Diet::CHARTISTS] = [Diet::AXIS_STATE => 0.0, Diet::AXIS_OPENNESS => 0.0, Diet::AXIS_COUNCIL => 0.8];
        $cabinet = [Diet::CIVIC, Diet::VANGUARD, Diet::IRON_HARBOR, Diet::EXCHANGE, Diet::CHARTISTS];
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO(
            partyPositions: $positions,
            governingCoalition: Diet::membership($cabinet),
            supportParties: Diet::membership([Diet::NEW_HORIZON]),
        ));
        $cabinetShape = $page['compass']['cabinet'];
        $dots = array_column($page['compass']['parties'], null, 'key');
        $at = static fn(string $party): array => [$dots[$party]['x'], $dots[$party]['y']];

        $this->assertEqualsCanonicalizing([$at(Diet::CIVIC), $at(Diet::VANGUARD), $at(Diet::IRON_HARBOR), $at(Diet::EXCHANGE)], $cabinetShape['halo'], 'The Chartists sit inside the others and add no corner.');
        $this->assertTrue($cabinetShape['ring']);
        $this->assertEqualsWithDelta($page['government']['openness'] * 100.0, $cabinetShape['hub']['x'], 0.01);
        $this->assertEqualsWithDelta(-$page['government']['state'] * 100.0, $cabinetShape['hub']['y'], 0.01);
        $this->assertCount(count($cabinet), array_filter($cabinetShape['spokes'], static fn(array $spoke): bool => !$spoke['supporter']));
        $dotted = array_values(array_filter($cabinetShape['spokes'], static fn(array $spoke): bool => $spoke['supporter']));
        $this->assertCount(1, $dotted);
        $this->assertSame($at(Diet::NEW_HORIZON), [$dotted[0]['x2'], $dotted[0]['y2']]);
        $this->assertArrayNotHasKey('councilX', $cabinetShape, 'The cabinet is not drawn on the Council strip.');
    }

    /** A party governing alone is its own cabinet: the shade is round it, no ring, and only its supporters are linked to it. */
    public function testAPartyGoverningAloneIsItsOwnCabinet(): void
    {
        $page = $this->builder()->build(new MacroStateDTO(), new PoliticsStateDTO());
        $cabinetShape = $page['compass']['cabinet'];
        $vanguard = array_column($page['compass']['parties'], null, 'key')[Diet::VANGUARD];

        $this->assertSame([[$vanguard['x'], $vanguard['y']]], $cabinetShape['halo']);
        $this->assertGreaterThan($vanguard['r'], $cabinetShape['pad']);
        $this->assertFalse($cabinetShape['ring']);
        $this->assertCount(3, $cabinetShape['spokes'], 'The founding Vanguard cabinet has three supporters.');
        foreach ($cabinetShape['spokes'] as $spoke) {
            $this->assertTrue($spoke['supporter']);
            $this->assertSame([$vanguard['x'], $vanguard['y']], [$spoke['x1'], $spoke['y1']]);
        }
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

        $this->assertSame(['Founding', 'Year 5 Q1', 'Year 9 Q1'], array_column($page['record'], 'date'));
        $this->assertSame(35, $page['record'][0]['seats']);
        $this->assertTrue($page['record'][1]['cabinet']);
        $this->assertFalse($page['record'][2]['cabinet'], 'The talks\' outcome is hidden until the cabinet takes office.');
        $this->assertSame(['votes' => 2, 'cabinet' => 1], array_intersect_key($page['recordSummary'], ['votes' => 0, 'cabinet' => 0]));
        $this->assertCount(count(Diet::PARTIES), $page['parties']);
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
}

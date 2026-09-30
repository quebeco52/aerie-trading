<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Macro\MacroEngine;
use App\Service\View\GovernmentPageBuilder;
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
        $page = $this->builder()->build(new MacroStateDTO());

        $this->assertSame(['The Vanguard', 'Exchange Party'], array_column($page['government']['members'], 'name'));
        $this->assertSame(130, $page['government']['seats']);
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
        $page = $this->builder()->build(new MacroStateDTO());
        $leftmost = array_reduce($page['hemicycle'], static fn(?array $carry, array $seat): array => $carry === null || $seat['x'] < $carry['x'] ? $seat : $carry);
        $rightmost = array_reduce($page['hemicycle'], static fn(?array $carry, array $seat): array => $carry === null || $seat['x'] > $carry['x'] ? $seat : $carry);

        $this->assertSame(GovernmentPageBuilder::PARTY_COLORS[Diet::CIVIC], $leftmost['color']);
        $this->assertSame(GovernmentPageBuilder::PARTY_COLORS[Diet::VANGUARD], $rightmost['color']);
    }

    /** The history runs newest first, marks a change of government, and each vote leaves a point on every party's trail. */
    public function testTheHistoryRunsNewestFirst(): void
    {
        $first = $this->election(4.0, [Diet::VANGUARD, Diet::EXCHANGE], [Diet::VANGUARD, Diet::EXCHANGE]);
        $second = $this->election(8.0, [Diet::CIVIC, Diet::IRON_HARBOR, Diet::EXCHANGE], [Diet::VANGUARD, Diet::EXCHANGE]);

        $page = $this->builder([$first, $second])->build(new MacroStateDTO(totalTime: 9.3, coalitionFormedAt: 8.0));

        $this->assertSame(['Year 9 Q1', 'Year 5 Q1'], array_column($page['history'], 'date'));
        $this->assertSame([true, false], array_column($page['history'], 'changed'));
        $this->assertSame('Year 9 Q1', $page['government']['formed']);
        $this->assertSame('Year 13 Q1', $page['election']['next']);
        foreach ($page['compass']['parties'] as $party) {
            $this->assertCount(2, $party['trail']);
        }
    }

    /** The founding government's cut waits on the Council while debt is over the line; a lever that costs no revenue waits only on the next round. */
    public function testTheBudgetShowsWhatTheCouncilHolds(): void
    {
        $braked = $this->builder()->build(new MacroStateDTO(totalTime: 0.2, sovereignDebtToGdp: MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.03));
        $levers = array_column($braked['budget']['levers'], null, 'name');

        $this->assertTrue($braked['budget']['braking']);
        $this->assertSame('held', $levers['Corporate tax rate']['status']);
        $this->assertLessThan(MacroEngine::TARGET_CORPORATE_TAX_RATE, $levers['Corporate tax rate']['platform']);
        $this->assertSame(MacroEngine::TARGET_CORPORATE_TAX_RATE, $levers['Corporate tax rate']['enacted']);
        $this->assertSame('enacted', $levers['Average tariff on imports']['status'], 'An open government has no tariff to levy.');
        $this->assertSame('pending', $levers['Labour force growth']['status']);
        $this->assertSame('Year 1 Q3', $braked['budget']['nextRound']);

        $free = $this->builder()->build(new MacroStateDTO(totalTime: 0.2, sovereignDebtToGdp: 0.5));
        $this->assertFalse($free['budget']['braking']);
        $this->assertSame('pending', array_column($free['budget']['levers'], null, 'name')['Corporate tax rate']['status']);
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
    private function election(float $at, array $coalition, array $outgoing): DietElection
    {
        return (new DietElection())
            ->setSimTime($at)
            ->setSeats(array_map('intval', Diet::SEED_SEATS))
            ->setVoteShares(Diet::SEED_VOTE_SHARES)
            ->setVoteSwings(array_fill_keys(Diet::PARTIES, 0.0))
            ->setSecondaryPositions(Diet::SEED_SECONDARY_POSITIONS)
            ->setCoalition($coalition)
            ->setOutgoingCoalition($outgoing);
    }
}

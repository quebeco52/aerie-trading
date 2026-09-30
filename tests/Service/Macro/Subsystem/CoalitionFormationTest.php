<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Subsystem;

use App\Data\AerieDiet as Diet;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CoalitionFormation as Formation;
use App\Service\Macro\Subsystem\DistrictPoliticsSubsystem as Politics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class CoalitionFormationTest extends TestCase
{
    // --- The calculus ---

    /** A cabinet's log-odds are Martin & Stevenson's terms, each where it applies. */
    public function testTheUtilityIsMartinAndStevensonsTermByTerm(): void
    {
        $seats = Diet::SEED_SEATS;
        $positions = Diet::SEED_POSITIONS;
        $cabinet = [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS];
        $range = Formation::ideologicalRange($cabinet, $positions);
        $majority = MacroEngine::FORMATION_MINIMAL_WINNING_UTILITY + (3 * MacroEngine::FORMATION_PARTY_UTILITY)
            + MacroEngine::FORMATION_LARGEST_PARTY_UTILITY + (MacroEngine::FORMATION_RANGE_UTILITY * $range);

        $this->assertTrue(Formation::isMinimalWinning($cabinet, $seats));
        $this->assertEqualsWithDelta($majority, Formation::utility($cabinet, $seats, $positions, [], Diet::VANGUARD), 1e-12);
        $this->assertEqualsWithDelta(
            $majority + MacroEngine::FORMATION_STATUS_QUO_UTILITY,
            Formation::utility($cabinet, $seats, $positions, [Diet::CHARTISTS, Diet::VANGUARD, Diet::EXCHANGE], Diet::VANGUARD),
            1e-12,
            'The outgoing cabinet, in whatever order it is written, has the incumbency term.'
        );
        $this->assertEqualsWithDelta(
            MacroEngine::FORMATION_MINORITY_UTILITY + MacroEngine::FORMATION_PARTY_UTILITY + MacroEngine::FORMATION_LARGEST_PARTY_UTILITY,
            Formation::utility([Diet::VANGUARD], $seats, $positions, [], Diet::VANGUARD),
            1e-12,
            'One party alone spans no range and holds no majority.'
        );
        $surplus = [Diet::CIVIC, Diet::VANGUARD, Diet::EXCHANGE];
        $this->assertFalse(Formation::isMinimalWinning($surplus, $seats));
        $this->assertEqualsWithDelta(
            (3 * MacroEngine::FORMATION_PARTY_UTILITY) + MacroEngine::FORMATION_LARGEST_PARTY_UTILITY + (MacroEngine::FORMATION_RANGE_UTILITY * Formation::ideologicalRange($surplus, $positions)),
            Formation::utility($surplus, $seats, $positions, [], Diet::VANGUARD),
            1e-12,
            'A surplus cabinet is neither minority nor minimal winning.'
        );
    }

    /** The range is the distance between the two furthest members, across all three axes. */
    public function testTheRangeSpansAllThreeAxes(): void
    {
        $positions = Diet::SEED_POSITIONS;

        $this->assertEqualsWithDelta(
            sqrt((0.6 ** 2) + (0.2 ** 2) + (0.5 ** 2)),
            Formation::ideologicalRange([Diet::VANGUARD, Diet::CHARTISTS], $positions),
            1e-12
        );
        $this->assertEqualsWithDelta(1.6, Formation::ideologicalRange([Diet::CHARTISTS, Diet::COMMON_LOT, Diet::VANGUARD], $positions) >= 1.6 ? 1.6 : 0.0, 1e-12);
        $this->assertSame(0.0, Formation::ideologicalRange([Diet::CIVIC], $positions));
    }

    /** An attempt forms a government with the logit chance its best cabinet beats the bar of no deal. */
    public function testTheChanceOfSuccessIsTheLogitOfTheInclusiveValueOverTheBar(): void
    {
        $this->assertEqualsWithDelta(0.5, Formation::successProbability([0.0, log(3.0)], log(4.0)), 1e-12);
        $this->assertEqualsWithDelta(0.8, Formation::successProbability([log(4.0)], 0.0), 1e-12);
        $this->assertGreaterThan(Formation::successProbability([0.0], 0.0), Formation::successProbability([0.0, -1.0], 0.0), 'Another cabinet on the table only adds to the chance.');
    }

    // --- The rounds ---

    public function testTheLeadPassesAsTheRoundsRequire(): void
    {
        $expected = [
            1 => ['a', Diet::ROUND_MAJORITY],
            2 => ['a', Diet::ROUND_SUPPORT],
            3 => ['b', Diet::ROUND_MAJORITY],
            4 => ['b', Diet::ROUND_SUPPORT],
            5 => ['a', Diet::ROUND_RUNOFF],
            6 => ['b', Diet::ROUND_RUNOFF],
            7 => ['a', Diet::ROUND_RUNOFF],
        ];
        foreach ($expected as $attempt => $lead) {
            $this->assertSame($lead, Formation::lead($attempt, 'a', 'b'), "Attempt {$attempt}.");
        }
    }

    public function testTheFirstRoundOffersOnlyMajorityCabinetsTheFormateurLeads(): void
    {
        $options = Formation::options(Diet::VANGUARD, Diet::ROUND_MAJORITY, Diet::SEED_SEATS, Diet::SEED_POSITIONS);

        $this->assertNotSame([], $options);
        foreach ($options as $option) {
            $this->assertContains(Diet::VANGUARD, $option['cabinet']);
            $this->assertGreaterThanOrEqual(Diet::MAJORITY_SEATS, Formation::coalitionSeats($option['cabinet'], Diet::SEED_SEATS));
            $this->assertSame([], $option['support']);
        }
        // Every set holding the Vanguard with a majority: 32 sets hold it, and this many reach 151.
        $expected = 0;
        for ($mask = 0; $mask < 32; ++$mask) {
            $others = array_values(array_diff(Diet::PARTIES, [Diet::VANGUARD]));
            $members = [Diet::VANGUARD];
            foreach ($others as $i => $party) {
                if ($mask & (1 << $i)) {
                    $members[] = $party;
                }
            }
            $expected += Formation::coalitionSeats($members, Diet::SEED_SEATS) >= Diet::MAJORITY_SEATS ? 1 : 0;
        }
        $this->assertCount($expected, $options);
    }

    /** From the second round a minority cabinet is on offer, with the narrowest set of supporters that carries it. */
    public function testMinorityCabinetsComeWithTheNarrowestSupportThatCarriesThem(): void
    {
        $seats = Diet::SEED_SEATS;
        $positions = Diet::SEED_POSITIONS;
        $options = Formation::options(Diet::VANGUARD, Diet::ROUND_SUPPORT, $seats, $positions);
        $minority = array_filter($options, static fn(array $option): bool => Formation::coalitionSeats($option['cabinet'], $seats) < Diet::MAJORITY_SEATS);

        $this->assertNotSame([], $minority);
        $this->assertCount(count(Formation::options(Diet::VANGUARD, Diet::ROUND_MAJORITY, $seats, $positions)) + count($minority), $options, 'The majority cabinets stay on offer.');
        foreach ($minority as $option) {
            $total = Formation::coalitionSeats(array_merge($option['cabinet'], $option['support']), $seats);
            $this->assertGreaterThanOrEqual(Diet::MAJORITY_SEATS, $total);
            $this->assertSame([], array_intersect($option['cabinet'], $option['support']));
            foreach ($option['support'] as $party) {
                $this->assertLessThan(Diet::MAJORITY_SEATS, $total - $seats[$party], "{$party} is not needed.");
            }
        }

        // The Vanguard alone: the Exchange Party and the Chartists are the narrowest pair that carries it (155 seats).
        $alone = array_values(array_filter($options, static fn(array $option): bool => $option['cabinet'] === [Diet::VANGUARD]))[0];
        $this->assertSame([Diet::EXCHANGE, Diet::CHARTISTS], $alone['support']);
        $this->assertSame($alone['support'], Formation::supportFor([Diet::VANGUARD], $seats, $positions));
    }

    /** The founding cabinet is the Vanguard's likeliest majority at the founding Diet, so the constant cannot drift from the calculus. */
    public function testTheFoundingCabinetIsTheVanguardsLikeliestMajority(): void
    {
        $order = Formation::bySize(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES);
        $this->assertSame([Diet::VANGUARD, Diet::CIVIC], array_slice($order, 0, 2));

        $options = Formation::options(Diet::VANGUARD, Diet::ROUND_MAJORITY, Diet::SEED_SEATS, Diet::SEED_POSITIONS);
        $utilities = array_map(static fn(array $option): float => Formation::utility($option['cabinet'], Diet::SEED_SEATS, Diet::SEED_POSITIONS, [], Diet::VANGUARD), $options);

        $this->assertSame(Diet::governingParties(Diet::SEED_COALITION), $options[Formation::likeliest($utilities)]['cabinet']);
        $this->assertSame([], Diet::governingParties(Diet::SEED_SUPPORT));
    }

    // --- The talks ---

    public function testAPartyWithAMajorityGovernsAloneWithNoTalks(): void
    {
        $seats = [Diet::CIVIC => 40.0, Diet::VANGUARD => 170.0, Diet::IRON_HARBOR => 30.0, Diet::EXCHANGE => 30.0, Diet::CHARTISTS => 20.0, Diet::COMMON_LOT => 10.0];

        $talks = Formation::talks($seats, Diet::SEED_VOTE_SHARES, Diet::SEED_POSITIONS, [], MathUtility::ownStream(1));

        $this->assertSame(['cabinet' => [Diet::VANGUARD], 'support' => [], 'days' => 0.0, 'log' => []], $talks);
    }

    /** A majority is 151 seats: a party holding exactly that many governs alone. */
    public function testExactlyAMajorityIsEnoughToGovernAlone(): void
    {
        $seats = [Diet::CIVIC => 60.0, Diet::VANGUARD => (float) Diet::MAJORITY_SEATS, Diet::IRON_HARBOR => 30.0, Diet::EXCHANGE => 29.0, Diet::CHARTISTS => 20.0, Diet::COMMON_LOT => 10.0];

        $talks = Formation::talks($seats, Diet::SEED_VOTE_SHARES, Diet::SEED_POSITIONS, [], MathUtility::ownStream(1));

        $this->assertSame([Diet::VANGUARD], $talks['cabinet']);
        $this->assertSame([], $talks['log']);
    }

    /** A successful attempt draws its cabinet in proportion to its odds, not always the likeliest one. */
    public function testTheCabinetIsDrawnInProportionToItsOdds(): void
    {
        $options = Formation::options(Diet::VANGUARD, Diet::ROUND_MAJORITY, Diet::SEED_SEATS, Diet::SEED_POSITIONS);
        $utilities = array_map(static fn(array $option): float => Formation::utility($option['cabinet'], Diet::SEED_SEATS, Diet::SEED_POSITIONS, [], Diet::VANGUARD), $options);
        $likeliest = $options[Formation::likeliest($utilities)]['cabinet'];
        $expected = exp(max($utilities)) / array_sum(array_map('exp', $utilities));

        $stream = MathUtility::ownStream(3);
        $firstTime = 0;
        $modal = 0;
        for ($trial = 0; $trial < 4000; ++$trial) {
            $talks = Formation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::SEED_POSITIONS, [], $stream);
            if (count($talks['log']) === 1) {
                ++$firstTime;
                $modal += $talks['cabinet'] === $likeliest ? 1 : 0;
            }
        }

        $this->assertEqualsWithDelta($expected, $modal / $firstTime, 0.03);
    }

    /** An attempt that fails logs what its formateur was likeliest to form; the one that succeeds logs the cabinet. */
    public function testEveryAttemptIsLogged(): void
    {
        // Every draw fails an attempt until the round-2 sure thing: uniform draws at 0.9999 fail anything short of p = 1.
        $draws = new class extends MathUtility {
            public int $attempt = 0;
            public function generateExponential(float $rate = 1.0): float { ++$this->attempt; return 1.0; }
            public function generateUniform(): float { return $this->attempt < 3 ? 0.9999999 : 0.0; }
        };

        $talks = Formation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::SEED_POSITIONS, [], $draws);

        $this->assertCount(3, $talks['log']);
        $this->assertSame([Diet::VANGUARD, Diet::VANGUARD, Diet::CIVIC], array_column($talks['log'], 'formateur'));
        $this->assertSame([Diet::ROUND_MAJORITY, Diet::ROUND_SUPPORT, Diet::ROUND_MAJORITY], array_column($talks['log'], 'round'));
        $this->assertSame([false, false, true], array_column($talks['log'], 'formed'));
        $this->assertEqualsWithDelta(3 * MacroEngine::FORMATION_ATTEMPT_DAYS, $talks['days'], 1e-9);
        $this->assertSame([MacroEngine::FORMATION_ATTEMPT_DAYS, 2 * MacroEngine::FORMATION_ATTEMPT_DAYS, 3 * MacroEngine::FORMATION_ATTEMPT_DAYS], array_column($talks['log'], 'day'));
        $this->assertSame(Diet::governingParties(Diet::SEED_COALITION), $talks['log'][0]['cabinet'], 'The first attempt logs the Vanguard\'s likeliest majority.');
        $this->assertContains(Diet::CIVIC, $talks['cabinet']);
        $this->assertSame($talks['log'][2]['cabinet'], $talks['cabinet']);
    }

    /** However the draws fall, the talks end in a government that commands the Diet. */
    public function testTheTalksAlwaysEndInAGovernmentThatCommandsTheDiet(): void
    {
        $stream = MathUtility::ownStream(7);
        for ($trial = 0; $trial < 300; ++$trial) {
            [$seats, $shares, $positions] = $this->randomDiet($stream);

            $talks = Formation::talks($seats, $shares, $positions, [], $stream);

            $this->assertGreaterThanOrEqual(Diet::MAJORITY_SEATS, Formation::coalitionSeats(array_merge($talks['cabinet'], $talks['support']), $seats));
            $this->assertTrue($talks['log'] === [] || $talks['log'][array_key_last($talks['log'])]['formed']);
            $this->assertSame(count($talks['log']) === 0, $talks['days'] === 0.0);
        }
    }

    /** The Council's loyalists and the populists are the axis's two ends: they almost never sit in one cabinet. */
    public function testTheChartistsAndTheCommonLotRarelyGovernTogether(): void
    {
        $stream = MathUtility::ownStream(13);
        $together = 0;
        for ($trial = 0; $trial < 2000; ++$trial) {
            [$seats, $shares, $positions] = $this->driftedFoundingDiet($stream);
            $cabinet = Formation::talks($seats, $shares, $positions, [], $stream)['cabinet'];
            $together += in_array(Diet::CHARTISTS, $cabinet, true) && in_array(Diet::COMMON_LOT, $cabinet, true) ? 1 : 0;
        }

        $this->assertLessThan(0.06, $together / 2000);
    }

    public function testTheTalksReplayUnderTheSameSeed(): void
    {
        $first = Formation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::SEED_POSITIONS, [], MathUtility::ownStream(5));
        $again = Formation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::SEED_POSITIONS, [], MathUtility::ownStream(5));

        $this->assertSame($first, $again);
    }

    public function testTheLargestPartyLeadsTiesToTheLargerVote(): void
    {
        $seats = [Diet::CIVIC => 90.0, Diet::VANGUARD => 90.0] + array_fill_keys(Diet::PARTIES, 30.0);
        $shares = [Diet::CIVIC => 0.29, Diet::VANGUARD => 0.30] + array_fill_keys(Diet::PARTIES, 0.1);

        $this->assertSame([Diet::VANGUARD, Diet::CIVIC], array_slice(Formation::bySize($seats, $shares), 0, 2));
    }

    /**
     * A Diet of random shares and positions anywhere on the axes, each party still fixed on its own.
     *
     * @return array{0: array<string, int>, 1: array<string, float>, 2: array<string, array<string, float>>}
     */
    private function randomDiet(MathUtility $stream): array
    {
        $shares = [];
        $positions = [];
        foreach (Diet::PARTIES as $party) {
            $shares[$party] = 0.02 + $stream->generateUniform();
            foreach (Diet::AXES as $axis) {
                $positions[$party][$axis] = -1.0 + (2.0 * $stream->generateUniform());
            }
            $positions[$party] = Diet::position($party, $positions);
        }
        $total = array_sum($shares);
        $shares = array_map(static fn(float $share): float => $share / $total, $shares);

        return [Politics::dHondt($shares, Diet::SEATS), $shares, $positions];
    }

    /**
     * The founding Diet a few votes on: shares scattered by the short-term swing, positions by a few manifesto steps.
     *
     * @return array{0: array<string, int>, 1: array<string, float>, 2: array<string, array<string, float>>}
     */
    private function driftedFoundingDiet(MathUtility $stream): array
    {
        $shares = [];
        $positions = [];
        foreach (Diet::PARTIES as $party) {
            $shares[$party] = Diet::SEED_VOTE_SHARES[$party] * exp(0.25 * $stream->generateStandardNormal());
            foreach (Diet::AXES as $axis) {
                $positions[$party][$axis] = max(-1.0, min(1.0, Diet::SEED_POSITIONS[$party][$axis] + (0.3 * $stream->generateStandardNormal())));
            }
            $positions[$party] = Diet::position($party, $positions);
        }
        $total = array_sum($shares);
        $shares = array_map(static fn(float $share): float => $share / $total, $shares);

        return [Politics::dHondt($shares, Diet::SEATS), $shares, $positions];
    }
}

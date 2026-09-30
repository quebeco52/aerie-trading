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

    /**
     * A cabinet's log-odds are Martin & Stevenson's terms, each where it applies. At the founding the Council median is
     * the establishment's +0.3, the right bloc is the Vanguard, the Exchange Party, the Chartists and the Free Port
     * Compact, and the left bloc the rest.
     */
    public function testTheUtilityIsMartinAndStevensonsTermByTerm(): void
    {
        $seats = Diet::SEED_SEATS;
        $positions = Diet::HOME_POSITIONS;
        $this->assertEqualsWithDelta(0.3, Formation::councilMedian($seats, $positions), 1e-12);

        $right = [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS, Diet::FREE_PORT];
        $this->assertTrue(Formation::isMinimalWinning($right, $seats));
        // Distances from the Council median: the Exchange Party 0.1, the Chartists 0.5, the Vanguard and the Free Port none.
        $majority = MacroEngine::FORMATION_MINIMAL_WINNING_UTILITY + (4 * MacroEngine::FORMATION_PARTY_UTILITY) + MacroEngine::FORMATION_LARGEST_PARTY_UTILITY
            + (MacroEngine::FORMATION_RANGE_UTILITY * Formation::ideologicalRange($right, $positions))
            + MacroEngine::FORMATION_PACT_UTILITY + (MacroEngine::FORMATION_ANTISYSTEM_UTILITY * 0.6);
        $this->assertEqualsWithDelta($majority, Formation::utility($right, [], $seats, $positions, [], Diet::VANGUARD, Diet::SEED_BLOCS), 1e-12);
        $this->assertEqualsWithDelta(
            $majority + MacroEngine::FORMATION_STATUS_QUO_UTILITY,
            Formation::utility($right, [], $seats, $positions, [Diet::FREE_PORT, Diet::CHARTISTS, Diet::VANGUARD, Diet::EXCHANGE], Diet::VANGUARD, Diet::SEED_BLOCS),
            1e-12,
            'The outgoing cabinet, in whatever order it is written, has the incumbency term.'
        );

        $this->assertEqualsWithDelta(
            MacroEngine::FORMATION_MINORITY_UTILITY + MacroEngine::FORMATION_PARTY_UTILITY + MacroEngine::FORMATION_LARGEST_PARTY_UTILITY,
            Formation::utility([Diet::VANGUARD], [], $seats, $positions, [], Diet::VANGUARD, Diet::SEED_BLOCS),
            1e-12,
            'One party alone spans no range, holds no majority and makes no pact.'
        );
        $this->assertEqualsWithDelta(
            MacroEngine::FORMATION_MINORITY_UTILITY + MacroEngine::FORMATION_PARTY_UTILITY + MacroEngine::FORMATION_LARGEST_PARTY_UTILITY
                + (MacroEngine::FORMATION_RANGE_UTILITY * Formation::ideologicalRange($right, $positions)) + MacroEngine::FORMATION_PACT_UTILITY,
            Formation::utility([Diet::VANGUARD], [Diet::EXCHANGE, Diet::CHARTISTS, Diet::FREE_PORT], $seats, $positions, [], Diet::VANGUARD, Diet::SEED_BLOCS),
            1e-12,
            'Carried by its own bloc, a one-party cabinet is a pact whose range runs over its supporters, who pay no anti-system cost.'
        );

        $grand = [Diet::CIVIC, Diet::VANGUARD];
        $this->assertTrue(Formation::isMinimalWinning($grand, $seats));
        // The Civic Front leans populist, 0.4 below the Council median.
        $this->assertEqualsWithDelta(
            MacroEngine::FORMATION_MINIMAL_WINNING_UTILITY + (2 * MacroEngine::FORMATION_PARTY_UTILITY) + MacroEngine::FORMATION_LARGEST_PARTY_UTILITY
                + (MacroEngine::FORMATION_RANGE_UTILITY * Formation::ideologicalRange($grand, $positions)) + MacroEngine::FORMATION_ANTIPACT_UTILITY
                + (MacroEngine::FORMATION_ANTISYSTEM_UTILITY * 0.4),
            Formation::utility($grand, [], $seats, $positions, [], Diet::VANGUARD, Diet::SEED_BLOCS),
            1e-12,
            'The two bloc leaders together are an anti-pact and no pact.'
        );
    }

    // --- The blocs ---

    /** The founding Diet splits around its two largest parties, the Vanguard and the Civic Front, into the blocs it opens with. */
    public function testTheFoundingDietSplitsAroundItsTwoLargestParties(): void
    {
        $this->assertSame(Diet::SEED_BLOCS, Formation::declareBlocs(Diet::SEED_SEATS, Diet::HOME_POSITIONS, []));
        $this->assertSame(Diet::SEED_BLOCS, Formation::declareBlocs(Diet::SEED_SEATS, Diet::HOME_POSITIONS, Diet::SEED_BLOCS), 'Nothing moved, so no party changes sides.');
    }

    /** A party that drifts past the line between the blocs' centres changes sides. */
    public function testAPartyThatDriftsAcrossChangesSides(): void
    {
        $positions = Diet::HOME_POSITIONS;
        $positions[Diet::CHARTISTS][Diet::AXIS_STATE] = 0.4;
        $positions[Diet::CHARTISTS][Diet::AXIS_OPENNESS] = 0.2;

        $this->assertSame(Diet::CIVIC, Formation::declareBlocs(Diet::SEED_SEATS, $positions, Diet::SEED_BLOCS)[Diet::CHARTISTS], 'The Chartists drift toward big government and a closed economy.');
    }

    /** Each bloc is led by its largest party: Iron Harbor, grown past the Civic Front, leads the left, and the rivalry is its. */
    public function testABlocIsLedByItsLargestParty(): void
    {
        $seats = Diet::SEED_SEATS;
        $seats[Diet::IRON_HARBOR] = 80.0;
        $seats[Diet::CIVIC] = 60.0;

        $blocs = Formation::declareBlocs($seats, Diet::HOME_POSITIONS, Diet::SEED_BLOCS);

        foreach ([Diet::CIVIC, Diet::IRON_HARBOR, Diet::COMMON_LOT, Diet::BASTION_GUILDS] as $party) {
            $this->assertSame(Diet::IRON_HARBOR, $blocs[$party]);
        }
        $this->assertTrue(Formation::rulesOut(Diet::IRON_HARBOR, Diet::VANGUARD, $blocs));
        $this->assertFalse(Formation::rulesOut(Diet::CIVIC, Diet::VANGUARD, $blocs), 'The Civic Front no longer leads a bloc.');
    }

    /**
     * The last vote's blocs carry over: at the founding positions a split on the size of the state and one on openness
     * both hold, and whichever the parties declared last stands.
     */
    public function testTheLastVotesBlocsCarryOver(): void
    {
        $openAgainstClosed = [
            Diet::CIVIC => Diet::CIVIC,
            Diet::VANGUARD => Diet::VANGUARD,
            Diet::IRON_HARBOR => Diet::VANGUARD,
            Diet::EXCHANGE => Diet::CIVIC,
            Diet::CHARTISTS => Diet::CIVIC,
            Diet::COMMON_LOT => Diet::VANGUARD,
            Diet::FREE_PORT => Diet::CIVIC,
            Diet::BASTION_GUILDS => Diet::VANGUARD,
        ];

        $this->assertSame($openAgainstClosed, Formation::declareBlocs(Diet::SEED_SEATS, Diet::HOME_POSITIONS, $openAgainstClosed));
        $this->assertNotSame($openAgainstClosed, Diet::SEED_BLOCS);
    }

    /** However the Diet stands, the declarations settle in two blocs, each party nearer its own bloc's centre and each bloc led by its largest party. */
    public function testEveryPartyEndsNearerItsOwnBlocsCentre(): void
    {
        $stream = MathUtility::ownStream(11);
        $previous = Diet::SEED_BLOCS;
        for ($trial = 0; $trial < 300; ++$trial) {
            [$seats, , $positions] = $this->driftedFoundingDiet($stream);

            $blocs = Formation::declareBlocs($seats, $positions, $previous);

            $leaders = array_values(array_unique($blocs));
            $this->assertCount(2, $leaders);
            $centres = [];
            foreach ($leaders as $leader) {
                $members = array_keys($blocs, $leader, true);
                $this->assertSame($leader, Formation::leader($members, Formation::bySize($seats, [])));
                $weight = Formation::coalitionSeats($members, $seats);
                foreach (Diet::BLOC_AXES as $axis) {
                    $centres[$leader][$axis] = array_sum(array_map(static fn(string $party): float => $seats[$party] * $positions[$party][$axis], $members)) / $weight;
                }
            }
            $distance = static fn(string $party, string $leader): float => sqrt(array_sum(array_map(
                static fn(string $axis): float => ($positions[$party][$axis] - $centres[$leader][$axis]) ** 2,
                Diet::BLOC_AXES
            )));
            foreach (Diet::PARTIES as $party) {
                $other = $leaders[0] === $blocs[$party] ? $leaders[1] : $leaders[0];
                $this->assertLessThanOrEqual($distance($party, $other) + 1e-9, $distance($party, $blocs[$party]), "{$party} stands nearer the other bloc.");
            }
            $previous = $blocs;
        }
    }

    /** Only the two bloc leaders rule each other out. */
    public function testOnlyTheTwoLeadersRuleEachOtherOut(): void
    {
        $this->assertTrue(Formation::rulesOut(Diet::CIVIC, Diet::VANGUARD, Diet::SEED_BLOCS));
        $this->assertTrue(Formation::rulesOut(Diet::VANGUARD, Diet::CIVIC, Diet::SEED_BLOCS));
        $this->assertFalse(Formation::rulesOut(Diet::CIVIC, Diet::CIVIC, Diet::SEED_BLOCS));
        $this->assertFalse(Formation::rulesOut(Diet::CIVIC, Diet::EXCHANGE, Diet::SEED_BLOCS));
        $this->assertFalse(Formation::rulesOut(Diet::CHARTISTS, Diet::COMMON_LOT, Diet::SEED_BLOCS));
    }

    /** The Council median is where the party holding the middle seat stands, counting from the populist end. */
    public function testTheCouncilMedianIsTheMiddleSeat(): void
    {
        $seats = array_fill_keys(Diet::PARTIES, 0.0);
        $seats[Diet::COMMON_LOT] = 160.0;
        $seats[Diet::CHARTISTS] = 140.0;

        $this->assertSame(-0.8, Formation::councilMedian($seats, Diet::HOME_POSITIONS));
        $seats[Diet::COMMON_LOT] = 140.0;
        $seats[Diet::CHARTISTS] = 160.0;
        $this->assertSame(0.8, Formation::councilMedian($seats, Diet::HOME_POSITIONS));
    }

    /** The range is the distance between the two furthest members, across all three axes. */
    public function testTheRangeSpansAllThreeAxes(): void
    {
        $positions = Diet::HOME_POSITIONS;

        $this->assertEqualsWithDelta(
            sqrt((0.6 ** 2) + (0.6 ** 2) + (0.5 ** 2)),
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

    // --- What is on offer ---

    /**
     * Every set of parties is on offer, as Martin & Stevenson estimated over: a majority alone, a minority with support,
     * and never supported by a party that ruled out governing with one of its members.
     */
    public function testEveryCabinetIsOnOffer(): void
    {
        $seats = Diet::SEED_SEATS;
        $positions = Diet::HOME_POSITIONS;
        $options = Formation::options($seats, $positions, Diet::SEED_BLOCS);

        $this->assertCount(count($options), array_unique(array_map(static fn(array $option): string => implode('+', $option['cabinet']), $options)));
        $majorities = 0;
        foreach ($options as $option) {
            $cabinetSeats = Formation::coalitionSeats($option['cabinet'], $seats);
            if ($cabinetSeats >= Diet::MAJORITY_SEATS) {
                ++$majorities;
                $this->assertSame([], $option['support'], 'A majority cabinet needs no support.');
                continue;
            }
            $total = Formation::coalitionSeats(array_merge($option['cabinet'], $option['support']), $seats);
            $this->assertGreaterThanOrEqual(Diet::MAJORITY_SEATS, $total);
            $this->assertSame([], array_intersect($option['cabinet'], $option['support']));
            foreach ($option['support'] as $party) {
                $this->assertLessThan(Diet::MAJORITY_SEATS, $total - $seats[$party], "{$party} is not needed.");
                foreach ($option['cabinet'] as $member) {
                    $this->assertFalse(Formation::rulesOut($party, $member, Diet::SEED_BLOCS), "{$party} props up a cabinet holding its rival.");
                }
            }
        }
        $expected = 0;
        for ($mask = 1; $mask < (1 << count(Diet::PARTIES)); ++$mask) {
            $members = array_values(array_filter(Diet::PARTIES, static fn(string $party): bool => ($mask & (1 << array_search($party, Diet::PARTIES, true))) !== 0));
            $expected += Formation::coalitionSeats($members, $seats) >= Diet::MAJORITY_SEATS ? 1 : 0;
        }
        $this->assertSame($expected, $majorities, 'Every majority cabinet is on offer.');
    }

    /** A minority cabinet's supporters are the narrowest set of outsiders that carries it, its rival left out. */
    public function testMinorityCabinetsComeWithTheNarrowestSupportThatCarriesThem(): void
    {
        // The Vanguard alone (80 seats): its bloc, the Exchange Party, the Chartists and the Free Port Compact, carry it at 155.
        $this->assertSame([Diet::EXCHANGE, Diet::CHARTISTS, Diet::FREE_PORT], Formation::supportFor([Diet::VANGUARD], Diet::SEED_SEATS, Diet::HOME_POSITIONS, Diet::SEED_BLOCS));

        // With the Civic Front the only party big enough, the Vanguard cannot be carried at all.
        $seats = array_fill_keys(Diet::PARTIES, 1.0);
        $seats[Diet::CIVIC] = 150.0;
        $seats[Diet::VANGUARD] = 144.0;
        $this->assertNull(Formation::supportFor([Diet::VANGUARD], $seats, Diet::HOME_POSITIONS, Diet::SEED_BLOCS));
        $this->assertNotContains([Diet::VANGUARD], array_column(Formation::options($seats, Diet::HOME_POSITIONS, Diet::SEED_BLOCS), 'cabinet'));
    }

    /** The talks for a cabinet are led by its largest party, whether or not it is the Diet's largest. */
    public function testACabinetsLargestPartyLeadsItsTalks(): void
    {
        $order = Formation::bySize(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES);

        $this->assertSame([Diet::VANGUARD, Diet::CIVIC], array_slice($order, 0, 2));
        $this->assertSame(Diet::VANGUARD, Formation::leader([Diet::EXCHANGE, Diet::VANGUARD], $order));
        $this->assertSame(Diet::CIVIC, Formation::leader([Diet::IRON_HARBOR, Diet::CIVIC, Diet::COMMON_LOT], $order));
        $this->assertSame(Diet::CHARTISTS, Formation::leader([Diet::COMMON_LOT, Diet::CHARTISTS], $order));
    }

    /** The founding government is the likeliest the founding Diet would seat, so the constant cannot drift from the calculus. */
    public function testTheFoundingGovernmentIsTheFoundingDietsLikeliest(): void
    {
        $options = Formation::options(Diet::SEED_SEATS, Diet::HOME_POSITIONS, Diet::SEED_BLOCS);
        $utilities = array_map(static fn(array $option): float => Formation::utility($option['cabinet'], $option['support'], Diet::SEED_SEATS, Diet::HOME_POSITIONS, [], Diet::VANGUARD, Diet::SEED_BLOCS), $options);
        $likeliest = $options[(int) array_search(max($utilities), $utilities, true)];

        $this->assertSame(Diet::governingParties(Diet::SEED_COALITION), $likeliest['cabinet']);
        $this->assertSame(Diet::governingParties(Diet::SEED_SUPPORT), $likeliest['support']);
    }

    // --- The talks ---

    public function testAPartyWithAMajorityGovernsAloneWithNoTalks(): void
    {
        $seats = [Diet::CIVIC => 40.0, Diet::VANGUARD => 170.0, Diet::IRON_HARBOR => 30.0, Diet::EXCHANGE => 30.0, Diet::CHARTISTS => 20.0, Diet::COMMON_LOT => 10.0];

        $talks = Formation::talks($seats, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, MathUtility::ownStream(1));

        $this->assertSame(['cabinet' => [Diet::VANGUARD], 'support' => [], 'days' => 0.0, 'log' => []], $talks);
    }

    /** A majority is 151 seats: a party holding exactly that many governs alone. */
    public function testExactlyAMajorityIsEnoughToGovernAlone(): void
    {
        $seats = [Diet::CIVIC => 60.0, Diet::VANGUARD => (float) Diet::MAJORITY_SEATS, Diet::IRON_HARBOR => 30.0, Diet::EXCHANGE => 29.0, Diet::CHARTISTS => 20.0, Diet::COMMON_LOT => 10.0];

        $talks = Formation::talks($seats, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, MathUtility::ownStream(1));

        $this->assertSame([Diet::VANGUARD], $talks['cabinet']);
        $this->assertSame([], $talks['log']);
    }

    /** Each attempt tries a cabinet drawn in proportion to its odds, not always the likeliest one. */
    public function testTheCabinetIsDrawnInProportionToItsOdds(): void
    {
        $options = Formation::options(Diet::SEED_SEATS, Diet::HOME_POSITIONS, Diet::SEED_BLOCS);
        $utilities = array_map(static fn(array $option): float => Formation::utility($option['cabinet'], $option['support'], Diet::SEED_SEATS, Diet::HOME_POSITIONS, [], Diet::VANGUARD, Diet::SEED_BLOCS), $options);
        $likeliest = $options[(int) array_search(max($utilities), $utilities, true)]['cabinet'];
        $expected = exp(max($utilities)) / array_sum(array_map('exp', $utilities));

        $stream = MathUtility::ownStream(3);
        $attempts = 0;
        $modal = 0;
        for ($trial = 0; $trial < 1000; ++$trial) {
            foreach (Formation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, $stream)['log'] as $attempt) {
                ++$attempts;
                $modal += $attempt['cabinet'] === $likeliest ? 1 : 0;
            }
        }

        $this->assertEqualsWithDelta($expected, $modal / $attempts, 0.03);
    }

    /** Every attempt is logged with the cabinet it tried and the party that led it; the last formed the government. */
    public function testEveryAttemptIsLogged(): void
    {
        // Uniform draws in turn: which cabinet an attempt tries, then whether it succeeds. 0.0 tries the first cabinet,
        // the Civic Front alone; just under 1.0 tries the last with any odds to speak of -- every party but the Civic
        // Front, since every party together holds both leaders -- and fails anything short of certain.
        $draws = new class extends MathUtility {
            /** @var list<float> */
            public array $uniforms = [0.0, 0.9999999, 0.9999999, 0.9999999, 0.0, 0.0];
            public function generateExponential(float $rate = 1.0): float { return 1.0; }
            public function generateUniform(): float { return (float) array_shift($this->uniforms); }
        };

        $talks = Formation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, $draws);

        $this->assertSame([Diet::CIVIC, Diet::VANGUARD, Diet::CIVIC], array_column($talks['log'], 'formateur'), 'Each attempt is led by the largest party in its cabinet.');
        $this->assertSame([false, false, true], array_column($talks['log'], 'formed'));
        $this->assertSame([[Diet::CIVIC], array_values(array_diff(Diet::PARTIES, [Diet::CIVIC])), [Diet::CIVIC]], array_column($talks['log'], 'cabinet'));
        $this->assertSame([MacroEngine::FORMATION_ATTEMPT_DAYS, 2 * MacroEngine::FORMATION_ATTEMPT_DAYS, 3 * MacroEngine::FORMATION_ATTEMPT_DAYS], array_column($talks['log'], 'day'));
        $this->assertEqualsWithDelta(3 * MacroEngine::FORMATION_ATTEMPT_DAYS, $talks['days'], 1e-9);
        $this->assertSame([Diet::CIVIC], $talks['cabinet'], 'A cabinet without the Diet\'s largest party can form.');
        $this->assertSame(Formation::supportFor([Diet::CIVIC], Diet::SEED_SEATS, Diet::HOME_POSITIONS, Diet::SEED_BLOCS), $talks['support']);
        $this->assertSame($talks['support'], $talks['log'][2]['support']);
    }

    /** However the draws fall, the talks end in a government that commands the Diet. */
    public function testTheTalksAlwaysEndInAGovernmentThatCommandsTheDiet(): void
    {
        $stream = MathUtility::ownStream(7);
        for ($trial = 0; $trial < 300; ++$trial) {
            [$seats, $shares, $positions] = $this->randomDiet($stream);

            $talks = Formation::talks($seats, $shares, $positions, [], Formation::declareBlocs($seats, $positions, []), $stream);

            $this->assertGreaterThanOrEqual(Diet::MAJORITY_SEATS, Formation::coalitionSeats(array_merge($talks['cabinet'], $talks['support']), $seats));
            $this->assertTrue($talks['log'] === [] || $talks['log'][array_key_last($talks['log'])]['formed']);
            foreach ($talks['log'] as $attempt) {
                $this->assertSame(Formation::leader($attempt['cabinet'], Formation::bySize($seats, $shares)), $attempt['formateur']);
            }
            $this->assertSame(count($talks['log']) === 0, $talks['days'] === 0.0);
        }
    }

    /**
     * The firewall: the Council axis's two ends seldom sit in cabinet and far more often carry one from outside. The two
     * bloc leaders, whichever parties lead, seldom serve together (Scandinavia since 1945: 1 cabinet in 68) and never prop
     * each other up.
     */
    public function testTheFirewallKeepsTheCouncilAxissEndsInSupport(): void
    {
        $stream = MathUtility::ownStream(13);
        $trials = 400;
        $cabinet = array_fill_keys([Diet::CHARTISTS, Diet::COMMON_LOT], 0);
        $support = $cabinet;
        $together = 0;
        for ($trial = 0; $trial < $trials; ++$trial) {
            [$seats, $shares, $positions] = $this->driftedFoundingDiet($stream);
            $blocs = Formation::declareBlocs($seats, $positions, Diet::SEED_BLOCS);
            [$leader, $rival] = array_values(array_unique($blocs));
            $talks = Formation::talks($seats, $shares, $positions, [], $blocs, $stream);
            foreach ($cabinet as $party => $unused) {
                $cabinet[$party] += in_array($party, $talks['cabinet'], true) ? 1 : 0;
                $support[$party] += in_array($party, $talks['support'], true) ? 1 : 0;
            }
            $together += in_array($leader, $talks['cabinet'], true) && in_array($rival, $talks['cabinet'], true) ? 1 : 0;
            foreach ([[$leader, $rival], [$rival, $leader]] as [$governing, $supporting]) {
                $this->assertFalse(in_array($governing, $talks['cabinet'], true) && in_array($supporting, $talks['support'], true), 'A bloc leader props up its rival.');
            }
        }
        $this->assertLessThan(0.05, $together / $trials);

        foreach ($cabinet as $party => $count) {
            $this->assertLessThan(0.15, $count / $trials, "{$party} sits in cabinet too often.");
            $this->assertGreaterThan(2 * $count, $support[$party], "{$party} governs rather than supports.");
        }
    }

    public function testTheTalksReplayUnderTheSameSeed(): void
    {
        $first = Formation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, MathUtility::ownStream(5));
        $again = Formation::talks(Diet::SEED_SEATS, Diet::SEED_VOTE_SHARES, Diet::HOME_POSITIONS, [], Diet::SEED_BLOCS, MathUtility::ownStream(5));

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
     * The founding Diet a few votes on: shares scattered by the short-term swing, positions around their homes.
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
                $positions[$party][$axis] = max(-1.0, min(1.0, Diet::HOME_POSITIONS[$party][$axis] + (0.3 * $stream->generateStandardNormal())));
            }
            $positions[$party] = Diet::position($party, $positions);
        }
        $total = array_sum($shares);
        $shares = array_map(static fn(float $share): float => $share / $total, $shares);

        return [Politics::dHondt($shares, Diet::SEATS), $shares, $positions];
    }
}

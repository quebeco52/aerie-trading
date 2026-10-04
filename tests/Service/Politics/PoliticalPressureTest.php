<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieDiet;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticalPressure as Pressure;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class PoliticalPressureTest extends TestCase
{
    private const QUARTER = 0.25;

    /** A state with the Authority formed and a cabinet of the given parties in office since Year 1. */
    private static function governedBy(array $parties, float $salt = 4242.0): PoliticsState
    {
        $state = new PoliticsState();
        $state->authoritySalt = $salt;
        $state->governingCoalition = AerieDiet::membership($parties);
        $state->coalitionFormedAt = 0.0;

        return $state;
    }

    /** Advances a state to each quarter's turn in turn, one tick a quarter, and reports what each quarter held. */
    private static function quarters(PoliticsState $state, int $count, MathUtility $math): array
    {
        $held = [];
        for ($i = 1; $i <= $count; ++$i) {
            $state->totalTime = $i * self::QUARTER;
            Pressure::advance($state, self::QUARTER, $math);
            $held[] = ['pressed' => $state->pressureSince >= 0.0, 'givingIn' => Pressure::concession($state) > 0.0, 'began' => $state->lastPressureAt === $state->totalTime];
        }

        return $held;
    }

    /** The Council axis reads onto V-Party's 0-1 populism as a straight line: the Common Lot at 0.9, the Chartists at 0.1. */
    public function testPopulismReadsTheCouncilAxisOntoZeroToOne(): void
    {
        $this->assertEqualsWithDelta(0.9, Pressure::populism(AerieDiet::position(AerieDiet::COMMON_LOT, AerieDiet::HOME_POSITIONS)), 1e-12);
        $this->assertEqualsWithDelta(0.1, Pressure::populism(AerieDiet::position(AerieDiet::CHARTISTS, AerieDiet::HOME_POSITIONS)), 1e-12);
        $this->assertSame(1.0, Pressure::populism([AerieDiet::AXIS_COUNCIL => -3.0]));
        $this->assertSame(0.0, Pressure::populism([AerieDiet::AXIS_COUNCIL => 3.0]));
        $this->assertSame(0.5, Pressure::populism([]));
    }

    /**
     * The most populist cabinet presses about three and a half times as often as the most technocratic (Gavin & Manger:
     * 3.7 across their sample), and the onset chance is the one that keeps the long-run share at the probit's.
     */
    public function testAPopulistCabinetPressesMoreOftenAndTheOnsetKeepsTheShare(): void
    {
        $math = new MathUtility();
        $populist = Pressure::shareOfQuarters(0.9, $math);
        $technocratic = Pressure::shareOfQuarters(0.1, $math);
        $this->assertGreaterThan(3.0, $populist / $technocratic);
        $this->assertLessThan(4.0, $populist / $technocratic);

        foreach ([0.02, 0.035, 0.08] as $share) {
            $onset = Pressure::onsetChance($share);
            $this->assertEqualsWithDelta($share, $onset / ($onset + 1.0 - Pressure::CONTINUATION), 1e-15);
        }
    }

    /**
     * Over a long run of quarters a cabinet presses in the share of quarters the probit gives its populism, in episodes
     * of about two and a half quarters, and the Authority gives ground to about a fifth of them; nothing moves off a
     * quarter's turn.
     */
    public function testALongRunMatchesTheShareTheEpisodesAndTheGiveInRate(): void
    {
        $math = new MathUtility();
        $state = self::governedBy([AerieDiet::COMMON_LOT]);
        $held = self::quarters($state, 40000, $math);

        $pressed = count(array_filter($held, static fn(array $quarter): bool => $quarter['pressed']));
        $episodes = array_values(array_filter($held, static fn(array $quarter): bool => $quarter['began']));
        $conceded = count(array_filter($episodes, static fn(array $quarter): bool => $quarter['givingIn']));
        $this->assertEqualsWithDelta(Pressure::shareOfQuarters(0.9, $math), $pressed / count($held), 0.008);
        $this->assertEqualsWithDelta(1.0 / (1.0 - Pressure::CONTINUATION), $pressed / count($episodes), 0.3);
        $this->assertEqualsWithDelta(Pressure::GIVE_IN_SHARE, $conceded / count($episodes), 0.06);

        $before = clone $state;
        $state->totalTime += 0.1;
        Pressure::advance($state, 0.1, $math);
        $this->assertEquals($before->pressureSince, $state->pressureSince, 'Off a quarter\'s turn nothing is drawn.');
    }

    /**
     * An episode belongs to the cabinet that began it: when another takes office, it ends at the next quarter's turn. The
     * draws are the game's own, so two runs of the same game press in the same quarters.
     */
    public function testAnEpisodeEndsWithItsCabinetAndRepeatsWithTheGame(): void
    {
        $math = new MathUtility();
        $state = self::governedBy([AerieDiet::COMMON_LOT]);
        $quarter = 0;
        while ($state->pressureSince < 0.0) {
            $state->totalTime = ++$quarter * self::QUARTER;
            Pressure::advance($state, self::QUARTER, $math);
        }
        $this->assertSame($state->coalitionFormedAt, $state->pressureCabinet);

        $state->coalitionFormedAt = $state->totalTime;
        $state->governingCoalition = AerieDiet::membership([AerieDiet::CHARTISTS]);
        $state->totalTime = ++$quarter * self::QUARTER;
        Pressure::advance($state, self::QUARTER, $math);
        $this->assertTrue($state->pressureSince < 0.0 || $state->pressureCabinet === $state->coalitionFormedAt, 'The old cabinet\'s episode is over.');

        $this->assertSame(self::quarters(self::governedBy([AerieDiet::IRON_HARBOR]), 400, $math), self::quarters(self::governedBy([AerieDiet::IRON_HARBOR]), 400, $math));
        $this->assertNotSame(self::quarters(self::governedBy([AerieDiet::IRON_HARBOR]), 400, $math), self::quarters(self::governedBy([AerieDiet::IRON_HARBOR], 777.0), 400, $math));
    }

    /**
     * The economy is handed the concession only while the Authority gives ground, nothing before the Authority has
     * formed; the start of an episode makes the headline, by what the Authority does.
     */
    public function testThePolicyHandsTheConcessionAndTheStartMakesTheHeadline(): void
    {
        $this->assertNull((new PoliticsStateDTO(authoritySalt: -1.0))->policy()->authorityConcession);

        $state = self::governedBy([AerieDiet::COMMON_LOT]);
        $state->totalTime = 3.0;
        $this->assertSame(0.0, PoliticsStateDTO::fromState($state)->policy()->authorityConcession);
        $state->pressureSince = 3.0;
        $state->lastPressureAt = 3.0;
        $this->assertSame(0.0, PoliticsStateDTO::fromState($state)->policy()->authorityConcession, 'Holding firm hands nothing over.');
        $this->assertSame(ShockEvent::AUTHORITY_PRESSED, PoliticsEngine::headline($state));
        $state->pressureGivingIn = 1.0;
        $this->assertSame(1.0, PoliticsStateDTO::fromState($state)->policy()->authorityConcession);
        $this->assertSame(ShockEvent::AUTHORITY_GIVES_GROUND, PoliticsEngine::headline($state));

        $round = PoliticsState::fromArray(json_decode((string) json_encode($state->toArray()), true));
        $this->assertSame([3.0, 1.0, 3.0], [$round->pressureSince, $round->pressureGivingIn, $round->lastPressureAt], 'The episode survives the round trip.');
    }
}

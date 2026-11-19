<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\DividendTier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The exchange's dividend roll: tiers by years of increases, each smoothing harder than the one below. */
#[Group('unit')]
class DividendTierTest extends TestCase
{
    public function testTheTiersFollowTheStreak(): void
    {
        $this->assertSame(DividendTier::None, DividendTier::fromStreak(4));
        $this->assertSame(DividendTier::Challenger, DividendTier::fromStreak(5));
        $this->assertSame(DividendTier::Contender, DividendTier::fromStreak(10));
        $this->assertSame(DividendTier::Champion, DividendTier::fromStreak(25));
        $this->assertSame(DividendTier::Champion, DividendTier::fromStreak(49));
        $this->assertSame(DividendTier::King, DividendTier::fromStreak(50));
    }

    /** A longer record smooths no less than a shorter one, and only champions and kings hold through a loss. */
    public function testALongerRecordSmoothsHarderAndOnlyChampionsHoldThroughALoss(): void
    {
        $previous = 1.0;
        foreach (DividendTier::cases() as $tier) {
            $this->assertLessThanOrEqual($previous, $tier->smoothing(), "{$tier->value} smooths at least as hard as the tier below.");
            $this->assertGreaterThan(0.0, $tier->smoothing());
            $previous = $tier->smoothing();
        }
        $this->assertSame([DividendTier::Champion, DividendTier::King], array_values(array_filter(DividendTier::cases(), static fn(DividendTier $t): bool => $t->holdsThroughLosses())));
        $this->assertFalse(DividendTier::None->isListed());
        $this->assertTrue(DividendTier::Challenger->isListed());
    }

    public function testOnlyAContenderOrAboveProtectsItsRecord(): void
    {
        $this->assertSame(
            [DividendTier::Contender, DividendTier::Champion, DividendTier::King],
            array_values(array_filter(DividendTier::cases(), static fn(DividendTier $t): bool => $t->protectsRecord())),
        );
    }

    /**
     * The opening records follow the long-run distribution their survival rates imply: two thirds of payers have a
     * record, a third are on the roll, about one in thirteen is a champion, and a higher quantile never opens on a
     * longer record.
     */
    public function testOpeningRecordsFollowTheLongRunDistribution(): void
    {
        $steps = 20000;
        $atLeast = [1 => 0, 5 => 0, 10 => 0, 25 => 0, 50 => 0];
        $previous = PHP_INT_MAX;
        for ($i = 0; $i < $steps; $i++) {
            $streak = DividendTier::openingStreak(($i + 0.5) / $steps);
            $this->assertLessThanOrEqual($previous, $streak);
            $this->assertLessThanOrEqual(DividendTier::LONGEST_OPENING_RECORD, $streak);
            $previous = $streak;
            foreach (array_keys($atLeast) as $years) {
                $atLeast[$years] += $streak >= $years ? 1 : 0;
            }
        }

        $this->assertEqualsWithDelta(DividendTier::OPENING_RECORD_SHARE, $atLeast[1] / $steps, 1e-3);
        $this->assertEqualsWithDelta(0.315, $atLeast[5] / $steps, 2e-3);
        $this->assertEqualsWithDelta(0.181, $atLeast[10] / $steps, 2e-3);
        $this->assertEqualsWithDelta(0.076, $atLeast[25] / $steps, 2e-3);
        $this->assertEqualsWithDelta(0.022, $atLeast[50] / $steps, 2e-3);
    }
}

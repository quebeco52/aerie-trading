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
}

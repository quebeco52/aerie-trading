<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\Subsystem\Aggregate\DomesticDemand;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DomesticDemand's pure formulas: the real-rate stance's drag, told against the stance borrowers are used to.
 */
#[Group('unit')]
class DomesticDemandTest extends TestCase
{
    /** @return array<string, array{float}> A usual stance either side of neutral, and at it. */
    public static function usualStances(): array
    {
        return ['restrictive' => [0.006], 'neutral' => [0.0], 'accommodative' => [-0.008]];
    }

    /** A stance held for a decade drags at the full slope, either side of neutral: it is in loan sizes. */
    #[DataProvider('usualStances')]
    public function testTheStanceBorrowersAreUsedToDragsAtTheFullSlope(float $usual): void
    {
        $this->assertEqualsWithDelta(DomesticDemand::KALDOR_MONETARY_DRAG * $usual, DomesticDemand::monetaryDrag($usual, $usual), 1e-15);
    }

    /** A move tighter than the usual stance binds the limits loans were sized to, at the full slope. */
    #[DataProvider('usualStances')]
    public function testTighteningPastTheUsualStanceDragsAtTheFullSlope(float $usual): void
    {
        $tightening = DomesticDemand::monetaryDrag($usual + 0.01, $usual) - DomesticDemand::monetaryDrag($usual, $usual);

        $this->assertEqualsWithDelta(DomesticDemand::KALDOR_MONETARY_DRAG * 0.01, $tightening, 1e-15);
    }

    /**
     * Easing pushes on a string: a move easier than the usual stance lifts demand at the easing share of what the same
     * move tighter cuts, wherever the usual stance sits, so the kink is not at neutral.
     */
    #[DataProvider('usualStances')]
    public function testEasingPastTheUsualStanceLiftsAtTheEasingShare(float $usual): void
    {
        $tightening = DomesticDemand::monetaryDrag($usual + 0.01, $usual) - DomesticDemand::monetaryDrag($usual, $usual);
        $easing = DomesticDemand::monetaryDrag($usual, $usual) - DomesticDemand::monetaryDrag($usual - 0.01, $usual);

        $this->assertEqualsWithDelta(FinancialConstants::RATE_EASING_SLOPE_SHARE * $tightening, $easing, 1e-15);
        $this->assertLessThan($tightening, $easing, 'A cut lifts less than a hike cuts.');
        $this->assertGreaterThan(0.0, $easing, 'A cut still lifts.');
    }

    /** No jump where tightening meets easing. */
    public function testTheDragIsContinuousAtTheUsualStance(): void
    {
        $usual = 0.004;

        $this->assertEqualsWithDelta(DomesticDemand::monetaryDrag($usual + 1e-12, $usual), DomesticDemand::monetaryDrag($usual - 1e-12, $usual), 1e-11);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Deal;

use App\Service\Corporate\Deal\MergerReview;
use PHPUnit\Framework\TestCase;

class MergerReviewTest extends TestCase
{
    /**
     * The 2023 Merger Guidelines presume a deal illegal when it raises the HHI by more than 100 points and
     * either leaves the market above 1,800 or creates a firm with more than 30% of it. The cleared share is
     * the largest deal those screens pass, checked here against the screens as the guidelines state them.
     */
    public function testTheClearedTargetShareIsTheLargestDealTheMergerScreensPass(): void
    {
        $presumedIllegal = static function (float $share, float $herfindahl, float $target): bool {
            $delta = 2.0 * $share * $target;

            return $delta > 0.0100 && (($herfindahl + ($target * $target) + $delta) > 0.1800 || ($share + $target) > 0.30);
        };

        foreach ([0.0, 0.02, 0.05, 0.10, 0.20, 0.28, 0.30, 0.35, 0.50, 0.80] as $share) {
            foreach ([0.0, 0.02, 0.08, 0.15, 0.18, 0.25] as $rivals) {
                $herfindahl = ($share * $share) + $rivals;
                $cleared = MergerReview::maxClearedTargetShare($share, $herfindahl);
                $case = "share {$share}, HHI {$herfindahl}, cleared {$cleared}";

                foreach ([0.25, 0.5, 0.999999] as $fraction) {
                    $this->assertFalse($presumedIllegal($share, $herfindahl, $cleared * $fraction), "every smaller deal clears: {$case}");
                }
                if ($cleared < 1.0) {
                    $this->assertTrue($presumedIllegal($share, $herfindahl, $cleared + 1e-6), "a larger deal is presumed illegal: {$case}");
                }
            }
        }

        // A leader at 30% alone in its market is held to the delta safe harbour: 100 points over 2 x 30%.
        $this->assertEqualsWithDelta(0.0100 / 0.60, MergerReview::maxClearedTargetShare(0.30, 0.09), 1e-12);
        // A 10% firm in an unconcentrated market may buy up to the 30% share ceiling.
        $this->assertEqualsWithDelta(0.20, MergerReview::maxClearedTargetShare(0.10, 0.01), 1e-12);
        // In a highly concentrated market even a small firm only gets the safe harbour.
        $this->assertEqualsWithDelta(0.10, MergerReview::maxClearedTargetShare(0.05, 0.20), 1e-12);
    }

    /**
     * The Diet sets review between the 2023 guidelines and the 2010 ones: 2,500 points, a 200-point delta and no share
     * presumption let the 30% leader that 2023 holds to its safe harbour buy until the market reaches 2,500.
     */
    public function testLenientReviewAppliesTheTwentyTenGuidelines(): void
    {
        $this->assertSame(['delta' => 0.0100, 'concentrated' => 0.1800, 'shareCeiling' => 0.30], MergerReview::reviewScreens(0.0));
        $this->assertEqualsWithDelta(0.0200, MergerReview::reviewScreens(1.0)['delta'], 1e-12);
        $this->assertEqualsWithDelta(0.2500, MergerReview::reviewScreens(1.0)['concentrated'], 1e-12);
        $this->assertEqualsWithDelta(1.0, MergerReview::reviewScreens(1.0)['shareCeiling'], 1e-12);
        $this->assertSame(MergerReview::reviewScreens(1.0), MergerReview::reviewScreens(1.5), 'Nothing on record is more lenient.');

        // √(0.09 + 0.25 − 0.09) − 0.30: the purchase that takes the market to 2,500.
        $this->assertEqualsWithDelta(0.20, MergerReview::maxClearedTargetShare(0.30, 0.09, 1.0), 1e-12);
        $this->assertEqualsWithDelta(0.0100 / 0.60, MergerReview::maxClearedTargetShare(0.30, 0.09, 0.0), 1e-12);
        $this->assertGreaterThan(MergerReview::maxClearedTargetShare(0.30, 0.09, 0.4), MergerReview::maxClearedTargetShare(0.30, 0.09, 0.6));
    }
}

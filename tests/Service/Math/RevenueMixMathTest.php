<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two revenue-mix measures the segment panels read: arithmetic growth attribution and
 * Herfindahl concentration. Both are standard segment-reporting arithmetic, and both are only
 * useful because of an identity — contributions sum to total growth, and the effective segment
 * count is the inverse of the index — so those identities are what these tests hold.
 */
class RevenueMixMathTest extends TestCase
{
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
    }

    public function testGrowthContributionsSumToTotalGrowth(): void
    {
        $previous = ['net_interest_income' => 80.0, 'fee_income' => 20.0];
        $current = ['net_interest_income' => 100.0, 'fee_income' => 30.0];

        $contributions = $this->math->calculateGrowthContributions($current, $previous);

        // 130 against 100 is 30% growth, and the segments must account for all of it.
        $this->assertEqualsWithDelta(0.30, array_sum($contributions), 1e-9);
        $this->assertEqualsWithDelta(0.20, $contributions['net_interest_income'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $contributions['fee_income'], 1e-9);
    }

    public function testASmallSegmentsOwnGrowthRateIsNotItsContribution(): void
    {
        $previous = ['core' => 95.0, 'venture' => 5.0];
        $current = ['core' => 95.0, 'venture' => 7.0];

        $contributions = $this->math->calculateGrowthContributions($current, $previous);

        // Venture grew 40% on its own base but carried 2 points of the firm — the whole reason
        // the panel prints contribution alongside the segment's own rate.
        $this->assertEqualsWithDelta(0.02, $contributions['venture'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $contributions['core'], 1e-9);
    }

    public function testDiscontinuedAndNewSegmentsBothCountTowardsGrowth(): void
    {
        $previous = ['legacy' => 40.0, 'core' => 60.0];
        $current = ['core' => 60.0, 'platform' => 30.0];

        $contributions = $this->math->calculateGrowthContributions($current, $previous);

        $this->assertEqualsWithDelta(-0.40, $contributions['legacy'], 1e-9);
        $this->assertEqualsWithDelta(0.30, $contributions['platform'], 1e-9);
        $this->assertEqualsWithDelta(-0.10, array_sum($contributions), 1e-9);
    }

    public function testGrowthContributionsAreUndefinedWithoutAPriorPeriod(): void
    {
        $this->assertSame([], $this->math->calculateGrowthContributions(['core' => 100.0], []));
        $this->assertSame([], $this->math->calculateGrowthContributions(['core' => 100.0], ['core' => 0.0]));
    }

    public function testHerfindahlIndexRunsFromEvenSplitToSingleSegment(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->math->calculateHerfindahlIndex(['only' => 1.0]), 1e-9);
        $this->assertEqualsWithDelta(0.5, $this->math->calculateHerfindahlIndex(['a' => 0.5, 'b' => 0.5]), 1e-9);
        $this->assertEqualsWithDelta(0.25, $this->math->calculateHerfindahlIndex(array_fill_keys(['a', 'b', 'c', 'd'], 0.25)), 1e-9);
    }

    public function testHerfindahlIndexNormalisesSharesThatDoNotSumToOne(): void
    {
        // Raw revenues carry the same concentration as the shares they imply.
        $this->assertEqualsWithDelta(
            $this->math->calculateHerfindahlIndex(['a' => 0.5, 'b' => 0.5]),
            $this->math->calculateHerfindahlIndex(['a' => 400.0, 'b' => 400.0]),
            1e-9,
        );
    }

    public function testHerfindahlIndexIsZeroForAnEmptyMix(): void
    {
        $this->assertSame(0.0, $this->math->calculateHerfindahlIndex([]));
        $this->assertSame(0.0, $this->math->calculateHerfindahlIndex(['a' => 0.0]));
    }

    public function testEffectiveSegmentCountIsTheInverseOfTheIndex(): void
    {
        $hhi = $this->math->calculateHerfindahlIndex(['a' => 0.5, 'b' => 0.3, 'c' => 0.2]);

        // Three booked segments that behave like about 2.6 of them.
        $this->assertEqualsWithDelta(2.632, $this->math->calculateEffectiveSegmentCount($hhi), 0.001);
        $this->assertSame(0.0, $this->math->calculateEffectiveSegmentCount(0.0));
    }
}

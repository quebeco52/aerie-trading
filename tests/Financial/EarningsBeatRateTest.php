<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Street walks each firm's EPS estimate down by its own measured surprise, so a steady firm and a lumpy one beat
 * about as often. A flat cut, a fixed share of earnings, let a steady staples name beat almost every quarter and a
 * lumpy one barely half of them.
 */
class EarningsBeatRateTest extends TestCase
{
    /** @return array<string, array{float}> surprise sd as a share of quarterly revenue */
    public static function surpriseDispersions(): array
    {
        return [
            'steady (0.5% of revenue)' => [0.005],
            'typical' => [MarketConsensusEngine::TYPICAL_EARNINGS_SURPRISE_SD],
            'lumpy (6% of revenue)' => [0.06],
        ];
    }

    #[DataProvider('surpriseDispersions')]
    public function testEveryFirmBeatsAboutThreeQuartersInFour(float $surpriseSd): void
    {
        $math = MathUtility::ownStream(20261017);
        $squares = 0.0;
        $weight = 0.0;
        $beats = 0;
        $reports = 2_000;
        for ($report = 0; $report < $reports; ++$report) {
            $walkDown = MarketConsensusEngine::epsWalkDown(1.0, MarketConsensusEngine::surpriseVariance($squares, $weight));
            $surprise = $surpriseSd * $math->generateStandardNormal();
            $beats += $surprise > -$walkDown ? 1 : 0;
            [$squares, $weight] = MarketConsensusEngine::recordSurprise($squares, $weight, $surprise, 0.25);
        }

        // Phi(0.674) = 0.75; a record of about a dozen reports measures the sd a little low, so the share sits just under.
        $this->assertEqualsWithDelta(0.74, $beats / $reports, 0.03);
    }

    /** With no record a firm is walked down as a typical one; a long record replaces the prior with its own measure. */
    public function testTheRecordReplacesThePrior(): void
    {
        $this->assertEqualsWithDelta(MarketConsensusEngine::TYPICAL_EARNINGS_SURPRISE_SD ** 2, MarketConsensusEngine::surpriseVariance(0.0, 0.0), 1e-15);

        $squares = 0.0;
        $weight = 0.0;
        for ($report = 0; $report < 80; ++$report) {
            [$squares, $weight] = MarketConsensusEngine::recordSurprise($squares, $weight, 0.01, 0.25);
        }
        $this->assertEqualsWithDelta(0.01 ** 2, MarketConsensusEngine::surpriseVariance($squares, $weight), 1e-8);
        $this->assertEqualsWithDelta(MarketConsensusEngine::ANALYST_EPS_WALKDOWN_SURPRISE_SDS * 0.01 * 500.0, MarketConsensusEngine::epsWalkDown(500.0, 0.01 ** 2), 1e-9);
    }
}

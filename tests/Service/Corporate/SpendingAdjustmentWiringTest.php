<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\MacroStateDTO;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\MathUtility;
use App\Tests\Support\CorporateEngineFactory;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The durable spending a falling gap defers reaches what a durable seller files: the capacity engine adds the model's
 * spending-adjustment shift to the cycle's level before the quarter's load is struck, so revenue and the committed cost
 * base both see it.
 */
final class SpendingAdjustmentWiringTest extends TestCase
{
    use CorporateEngineFactory;

    public function testAFallingGapCutsWhatADurableSellerFiles(): void
    {
        $revenue = function (float $gapAQuarterAgo): float {
            $stock = StockBuilder::matureIndustrial('TOOL')->withIndustry('Tools & Accessories')->build();
            $macro = new MacroStateDTO(
                outputGapEma: -0.02,
                outputGapLag3m: $gapAQuarterAgo,
                inflationEma: 0.02,
                policyRate: 0.04,
                policyRateEma: 0.04,
                yield10yEma: 0.045,
                nominalGdpIndex: 1.0,
            );
            self::earningsEngine(MathUtility::ownStream(7))->calculate($stock, $macro, EarningsEngine::resolveReportingTick('TOOL', 252), 252);

            return (float) $stock->getTotalRevenue();
        };

        // The same level of the gap, at rest or falling two points a quarter: only the rate differs.
        $atRest = $revenue(-0.02);
        $falling = $revenue(0.0);

        $this->assertGreaterThan(0.0, $atRest);
        $this->assertLessThan(0.97 * $atRest, $falling);
    }
}

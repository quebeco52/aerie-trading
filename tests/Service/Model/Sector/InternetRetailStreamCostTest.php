<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InternetRetailBusinessModel;
use PHPUnit\Framework\TestCase;

class InternetRetailStreamCostTest extends TestCase
{
    /**
     * A marketplace surge carries the marketplace's own cost. The 1P cost ratio used to be the plan's residual after
     * REALIZED 3P and ads costs, so a 3P surge lowered it by exactly the surge's cost and arrived costless.
     */
    public function testThirdPartySurgeAddsItsOwnCostRatio(): void
    {
        $model = new InternetRetailBusinessModel();
        $run = function (float $tpZ) use ($model) {
            $math = $this->createStub(MathUtility::class);
            $math->method('generateStandardNormal')->willReturn(0.0);
            // fp, tp, ads, event
            $math->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, $tpZ, 0.0, 0.0);
            $stock = (new Stock())->setTicker('WEAV')->setBeta('1.0');
            $macro = new MacroStateDTO(outputGapEma: 0.0, consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL);

            return $model->computeActualFinancials($stock, 1000.0, 0.30, 50.0, 0.15, $macro, $math);
        };

        $base = $run(0.0);
        $surge = $run(2.0);

        $tpDelta = $surge->streamRevenue['third_party_seller'] - $base->streamRevenue['third_party_seller'];
        $this->assertGreaterThan(0.0, $tpDelta);

        // Marketplace traffic also lifts ads; each stream brings its own cost ratio and 1P's cost is unchanged.
        $adsDelta = $surge->streamRevenue['digital_ads_cloud'] - $base->streamRevenue['digital_ads_cloud'];
        $this->assertEqualsWithDelta($base->streamRevenue['first_party_retail'], $surge->streamRevenue['first_party_retail'], 1e-9);

        $costDelta = ($surge->clampedMargin * $surge->actualRevenue) - ($base->clampedMargin * $base->actualRevenue);
        $expected = ($tpDelta * InternetRetailBusinessModel::THIRD_PARTY_COST_RATIO) + ($adsDelta * InternetRetailBusinessModel::DIGITAL_ADS_COST_RATIO);
        $this->assertEqualsWithDelta($expected, $costDelta, 1e-6);
    }
}

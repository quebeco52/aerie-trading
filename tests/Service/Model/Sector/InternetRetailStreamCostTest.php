<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Model\Sector\InternetRetailBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
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
            // fp, tp, ads, event
            $math = new ScriptedDraws(generatePersistentZ: [0.0, $tpZ, 0.0, 0.0]);
            $stock = (new Stock())->setTicker('WEAV')->setBeta('1.0');
            $macro = new MacroStateDTO(outputGapEma: 0.0, consumerSentimentIndexEma: MacroConstants::SENTIMENT_TREND_LEVEL);

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

    /**
     * A marketplace with no first-party line has no residual line to absorb the engine's cost ratio, so its own lines
     * carry it: the cost ratio moves one for one with the engine's, where its planned ratios used to hold it still.
     */
    public function testAMarketplaceWithNoFirstPartyLineCarriesTheEngineMargin(): void
    {
        $model = new InternetRetailBusinessModel();
        $ratio = function (float $engineRatio) use ($model): float {
            $math = new ScriptedDraws(generatePersistentZ: 0.0);
            $macro = new MacroStateDTO(outputGapEma: 0.0, consumerSentimentIndexEma: MacroConstants::SENTIMENT_TREND_LEVEL, exchangeRateIndexEma: 100.0);

            return $model->computeActualFinancials((new Stock())->setTicker('JDAW'), 1000.0, $engineRatio, 0.0, 0.0, $macro, $math)->clampedMargin;
        };

        $this->assertEqualsWithDelta(0.30, $ratio(0.30), 1e-6);
        $this->assertEqualsWithDelta(0.40, $ratio(0.40), 1e-6);
    }
}

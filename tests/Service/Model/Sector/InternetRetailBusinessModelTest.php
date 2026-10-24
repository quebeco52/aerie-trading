<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InternetRetailBusinessModel;
use App\Service\News\NarrativeEngine;
use App\Tests\Support\MacroStateBuilder;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

class InternetRetailBusinessModelTest extends TestCase
{
    private InternetRetailBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new InternetRetailBusinessModel();
    }

    private function createMacroStateDTO(): MacroStateDTO
    {
        return MacroStateBuilder::flatCurveDto();
    }

    public function testWarehouseLaborStrikeShockTriggersLaborStrikeEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('AMZN_MOCK');
        $stock->setBeta('1.0');

        // fpZ = 0.0, tpZ = 0.0, adsZ = 0.0, eventZ = -2.30 (< WAREHOUSE_STRIKE_Z_SCORE -2.20)
        $mathUtilityMock = new ScriptedDraws(generatePersistentZ: [0.0, 0.0, 0.0, -2.30]);

        $macroState = $this->createMacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertSame(ShockEvent::LABOR_STRIKE, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testAntitrustFineShockTriggersRegulatoryFineEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('AMZN_MOCK');
        $stock->setBeta('1.0');

        // fpZ = 0.0, tpZ = 0.0, adsZ = 0.0, eventZ = -2.70 (< ANTITRUST_FINE_Z_SCORE -2.60)
        $mathUtilityMock = new ScriptedDraws(generatePersistentZ: [0.0, 0.0, 0.0, -2.70]);

        $macroState = $this->createMacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertSame(ShockEvent::REGULATORY_FINE, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
        $this->assertLessThan(1000.0, $result->actualRevenue);
    }

    public function testViralHolidaySurgeShockTriggersViralGrowthEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('AMZN_MOCK');
        $stock->setBeta('1.0');

        // fpZ = 0.0, tpZ = 0.0, adsZ = 0.0, eventZ = 2.50 (> VIRAL_HOLIDAY_SURGE_Z 2.40)
        $mathUtilityMock = new ScriptedDraws(generatePersistentZ: [0.0, 0.0, 0.0, 2.50]);

        $macroState = $this->createMacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertSame(ShockEvent::VIRAL_GROWTH, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
        $this->assertGreaterThan(1000.0, $result->actualRevenue);
    }

    public function testNarrativeEngineGeneratesLoreForLaborStrike(): void
    {
        $narrativeEngine = new NarrativeEngine(MathUtility::ownStream(20261024));

        $lore = $narrativeEngine->generateLore(ShockEvent::LABOR_STRIKE);
        $this->assertNotEmpty($lore);
        $this->assertNotSame('Experienced an unexpected market event.', $lore);
    }

    /**
     * Household confidence moves the discretionary basket before the output gap does, so a sentiment slump
     * must cut first-party retail on its own while the third-party tollbooth, which clips a fee on whatever
     * sellers move, is untouched by it.
     */
    public function testConsumerSentimentSlumpCutsFirstPartyRetailButNotTheThirdPartyTollbooth(): void
    {
        $math = new ScriptedDraws(generatePersistentZ: 0.0);

        $run = function (float $sentiment) use ($math): array {
            $stock = (new Stock())->setTicker('WEAV_SENT')->setBeta('1.0');

            return $this->model->computeActualFinancials($stock, 1000.0, 0.60, 50.0, 0.0, new MacroStateDTO(outputGapEma: 0.0, consumerSentimentIndexEma: $sentiment, consumerSentimentMood: $sentiment - MacroConstants::SENTIMENT_TREND_LEVEL), $math)->streamRevenue;
        };

        // With output at trend the slump is twenty points of mood: confidence the cycle does not explain.
        $calm = $run(MacroConstants::SENTIMENT_TREND_LEVEL);
        $slump = $run(MacroConstants::SENTIMENT_TREND_LEVEL - 20.0);

        $expectedDrop = 0.20 * InternetRetailBusinessModel::CONSUMER_SENTIMENT_SCALAR * InternetRetailBusinessModel::OPERATING_CYCLICALITY;
        $this->assertEqualsWithDelta($calm['first_party_retail'] * (1.0 - $expectedDrop), $slump['first_party_retail'], 1e-6);
        $this->assertEqualsWithDelta($calm['third_party_seller'], $slump['third_party_seller'], 1e-9, 'Sentiment reaches the shopper, not the marketplace fee.');
    }

    public function testTheCycleReachesTheCostBaseThroughTheStreamVolumes(): void
    {
        $stock = (new Stock())->setTicker('LOON_ACTIVITY')->setBeta('1.0');
        // Confidence falls by its gap loading with the gap, so the residual over the gap is zero.
        $sentiment = MacroConstants::SENTIMENT_TREND_LEVEL + (-0.03 * \App\Tests\Support\SentimentOnTheCycle::POINTS_PER_GAP);
        $recession = new MacroStateDTO(outputGapEma: -0.03, exchangeRateIndexEma: 100.0, consumerSentimentIndexEma: $sentiment);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // 1.30 x (0.45 x 2 x -0.03 + 0.40 x 0.5 x -0.03) = -0.0429; ads carry no macro term.
        $expected = InternetRetailBusinessModel::OPERATING_CYCLICALITY * (
            (InternetRetailBusinessModel::FIRST_PARTY_WEIGHT * 2.0 * -0.03)
            + (InternetRetailBusinessModel::THIRD_PARTY_WEIGHT * 0.5 * -0.03)
        );
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }

    /**
     * Online's drift is the District's own adoption, 4% of retail to 6.5% over a decade, net of retail's own drift:
     * about half the mainland's.
     */
    public function testTheSecularExcessIsTheDistrictsOnlineAdoption(): void
    {
        $excess = $this->model->getSecularGrowthRate(new Stock()) - MacroConstants::TREND_REAL_GROWTH;

        $this->assertEqualsWithDelta(log(0.065 / 0.040) / 10.0 + log(0.0593 / 0.0676) / 22.0, $excess, 1e-12);
        $this->assertEqualsWithDelta(0.0426, $excess, 0.0005);
    }
}

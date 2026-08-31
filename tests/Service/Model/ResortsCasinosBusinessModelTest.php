<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ResortsCasinosBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ResortsCasinosBusinessModelTest extends TestCase
{
    private function createMacroState(
        float $inflation = 0.02,
        float $outputGap = 0.0,
        float $consumerSentimentIndexEma = 100.0,
        float $energyPriceIndexEma = 100.0,
        float $macroCreditSpread = 0.015,
        float $yield10y = 0.04
    ): MacroStateDTO {
        return new MacroStateDTO(
            outputGap: $outputGap,
            outputGapEma: $outputGap,
            unemploymentRate: 0.04,
            unemploymentRateEma: 0.04,
            energyPriceIndex: $energyPriceIndexEma,
            energyPriceIndexEma: $energyPriceIndexEma,
            energyPriceShock: 0.0,
            consumerSentimentIndex: $consumerSentimentIndexEma,
            consumerSentimentIndexEma: $consumerSentimentIndexEma,
            inflation: $inflation,
            inflationEma: $inflation,
            policyRate: 0.04,
            policyRateEma: 0.04,
            targetRate: 0.04,
            yield2y: 0.04,
            yield2yEma: 0.04,
            yield5y: 0.04,
            yield5yEma: 0.04,
            yield10y: $yield10y,
            yield10yEma: $yield10y,
            yield30y: 0.04,
            yield30yEma: 0.04,
            marketVolatility: 0.15,
            marketVolatilityEma: 0.15,
            marketZ: 0.0,
            corporateTaxRate: 0.21,
            equityRiskPremium: 0.05,
            macroCreditSpread: $macroCreditSpread,
            macroCreditSpreadEma: $macroCreditSpread,
            qeActive: false,
            qeIntensity: 0.0,
            inversionDuration: 0.0,
            nsLevel: 0.04,
            nsSlope: 0.0,
            nsSlopeEma: 0.0,
            nsCurvature: 0.0,
            potentialGdpIndex: 1.0,
            nominalGdpIndex: 1.0,
        );
    }

    private function createMathUtilityMock(array $persistentZCalls = []): MathUtility
    {
        $mock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        if (!empty($persistentZCalls)) {
            $mock->method('generatePersistentZ')
                ->willReturnOnConsecutiveCalls(...array_values($persistentZCalls));
        }

        return $mock;
    }

    public function testConsumerSentimentAndWealthEffectDemandShift(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $stock = new Stock();
        $stock->setTicker('CASINO');
        $stock->setBeta('1.4');

        // Sentiment boom (120 vs 100 baseline -> +0.20 shift)
        $macroBoom = $this->createMacroState(consumerSentimentIndexEma: 120.0);
        $physicsBoom = $model->getMacroPhysics($stock, $macroBoom);

        // Expected shift: outputGap (0) + 0.20 * 1.4 * 0.25 = 0.07
        $this->assertEqualsWithDelta(0.07, $physicsBoom['macro_demand_shift'], 0.001);

        // Sentiment slump (80 vs 100 baseline -> -0.20 shift)
        $macroSlump = $this->createMacroState(consumerSentimentIndexEma: 80.0);
        $physicsSlump = $model->getMacroPhysics($stock, $macroSlump);

        // Expected shift: -0.07
        $this->assertEqualsWithDelta(-0.07, $physicsSlump['macro_demand_shift'], 0.001);
    }

    public function testSymmetricWhaleHoldLuckSurgeAndCrash(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $stock = new Stock();
        $stock->setTicker('CASINO');

        // 1. Whale loss surge (house holds big, gamingZ = 2.5 > 2.40)
        // gamingZ = 2.5, nonGamingZ = 0.0, eventZ = 0.0
        $mathSurge = $this->createMathUtilityMock([2.5, 0.0, 0.0]);
        $macroState = $this->createMacroState();

        $resSurge = $model->computeActualFinancials($stock, 10_000.0, 0.58, 2000.0, 0.15, $macroState, $mathSurge);
        // Gaming revenue should receive 1.18x whale multiplier
        // Expected base gaming: 10_000 * 0.55 * (1 + 2.5 * 0.15 * 0.30) * 1.18 = 5500 * 1.1125 * 1.18 = 7220.125
        $this->assertEqualsWithDelta(7220.125, $resSurge->streamRevenue['gaming'], 1.0);
        $this->assertNull($resSurge->eventType);

        // 2. Whale win crash (house loses to high rollers, gamingZ = -2.5 < -2.40)
        // gamingZ = -2.5, nonGamingZ = 0.0, eventZ = 0.0
        $mathCrash = $this->createMathUtilityMock([-2.5, 0.0, 0.0]);

        $resCrash = $model->computeActualFinancials($stock, 10_000.0, 0.58, 2000.0, 0.15, $macroState, $mathCrash);
        // Gaming revenue should receive 0.82x whale multiplier
        // Expected base gaming: 10_000 * 0.55 * (1 - 2.5 * 0.15 * 0.30) * 0.82 = 5500 * 0.8875 * 0.82 = 4002.625
        $this->assertEqualsWithDelta(4002.625, $resCrash->streamRevenue['gaming'], 1.0);
        $this->assertNull($resCrash->eventType);
    }

    public function testGamingRegulatoryCrackdownAndHaircut(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $stock = new Stock();
        $stock->setTicker('CASINO');

        // eventZ = -2.5 (< -2.20 GAMING_REGULATION_CRACKDOWN_Z)
        // gamingZ = 0.0, nonGamingZ = 0.0, eventZ = -2.5
        $mathMock = $this->createMathUtilityMock([0.0, 0.0, -2.5]);
        $macroState = $this->createMacroState();

        $result = $model->computeActualFinancials($stock, 10_000.0, 0.58, 2000.0, 0.15, $macroState, $mathMock);

        $this->assertSame(ShockEvent::REGULATORY_FINE, $result->eventType);
        // Gaming revenue should receive 0.75 haircut (down 25% from 5,500 to 4,125)
        $this->assertEqualsWithDelta(4125.0, $result->streamRevenue['gaming'], 1.0);
    }

    public function testPromotionalCompsIsolatesToNonGamingLedger(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $stock = new Stock();
        $stock->setTicker('CASINO');
        $stock->setBeta('1.0');

        // gamingZ = 0.0, nonGamingZ = 0.0, eventZ = 0.0
        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0]);

        // Sentiment slump of 20 points (sentimentIndex = 80.0 vs 100.0 baseline) -> -0.20 shift
        // promotionalDrag = 0.20 * 1.0 * 0.15 = 0.03
        $macroState = $this->createMacroState(consumerSentimentIndexEma: 80.0);

        // Gaming weight = 0.55, Non-gaming weight = 0.45.
        // baseGamingMargin = 0.58 / (0.55 + 2.0 * 0.45) = 0.58 / 1.45 = 0.40.
        // gamingVariableMargin = 0.40 (unaffected by promotional comps)
        // nonGamingVariableMargin = 0.80 + 0.03 = 0.83 (diluted by comp rooms & F&B)
        $result = $model->computeActualFinancials($stock, 10_000.0, 0.58, 2000.0, 0.15, $macroState, $mathMock);

        // Total variable costs: (5500 * 0.40) + (4500 * 0.83) = 2200 + 3735 = 5935
        // Raw margin = 5935 / 10000 = 0.5935
        $this->assertEqualsWithDelta(0.5935, $result->clampedMargin, 0.001);
    }

    public function testEnergyUtilityDragScalesByOperationalFootprint(): void
    {
        $model = new ResortsCasinosBusinessModel();

        // 1. Pure-Play Casino Resort (100% operational footprint)
        $stockPure = new Stock();
        $stockPure->setTicker('CASINO');
        $stockPure->setBeta('1.0');

        $mathMock1 = $this->createMathUtilityMock([0.0, 0.0, 0.0]);

        // Energy index spikes by 20 points (120 vs 100 baseline -> 0.20 shift)
        // energyDrag = 0.20 * 0.20 = 0.04
        $macroEnergy = $this->createMacroState(energyPriceIndexEma: 120.0);

        $resPure = $model->computeActualFinancials($stockPure, 10_000.0, 0.58, 2000.0, 0.15, $macroEnergy, $mathMock1);
        // Absorbs full 0.04 energy drag: 0.58 + 0.04 = 0.62
        $this->assertEqualsWithDelta(0.62, $resPure->clampedMargin, 0.001);

        // 2. Landlord Empire (Silver Gull Resorts 'GULL': 20% gaming, 10% non-gaming, 70% CRE -> footprint = 0.30)
        $stockGull = new Stock();
        $stockGull->setTicker('GULL');
        $stockGull->setBeta('1.0');

        // gamingZ = 0.0, nonGamingZ = 0.0, eventZ = 0.0, creZ = 0.0
        $mathMock2 = $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]);

        // GULL has PricingPowerIndex = 1.00 (from StockModelTuning), so inflation penalty is 0
        // Denominator for baseGamingMargin: 0.20 + (2.0 * 0.10) + 0.70 = 1.10
        // If realizedVariableMargin = 0.33: baseGamingMargin = 0.30
        // gamingMargin = 0.30, nonGamingMargin = 0.60, creMargin = 0.30
        // actualVariableCosts = (2000 * 0.30) + (1000 * 0.60) + (7000 * 0.30) = 600 + 600 + 2100 = 3300 (0.33 margin)
        // Effective energy drag = 0.04 * 0.30 (footprint) = 0.012
        // Clamped margin = 0.33 + 0.012 = 0.342
        $resGull = $model->computeActualFinancials($stockGull, 10_000.0, 0.33, 2000.0, 0.15, $macroEnergy, $mathMock2);
        $this->assertEqualsWithDelta(0.342, $resGull->clampedMargin, 0.001);
    }

    public function testCommercialRealEstateHybridLandlordPhysics(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GULL');
        $stock->setBeta('1.0');

        // gamingZ = 0.0, nonGamingZ = 0.0, eventZ = 0.0, creZ = -2.0 (< -1.50 CRE_VACANCY_DISTRESS_Z)
        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0, -2.0]);

        // Recession: outputGap = -0.04 -> tenant concession drag = 0.04 * 0.10 = 0.004
        // Vacancy shock: creVacancyShock = 2.0 * 0.05 = 0.10
        // Excess inflation: 0.04 (2% excess) -> rentEscalator = 0.02 * 0.50 = 0.01
        $macroState = $this->createMacroState(inflation: 0.04, outputGap: -0.04);

        $result = $model->computeActualFinancials($stock, 10_000.0, 0.33, 2000.0, 0.15, $macroState, $mathMock);

        // CRE margin: base (0.30) + vacancyShock (0.10) + concessionDrag (0.004) = 0.404
        // CRE revenue receives rentEscalator (+0.01) and demandShock (-0.04 * 0.50 = -0.02) and creZ shock (-2.0 * 0.15 * 0.30 * 0.20 = -0.018)
        $this->assertGreaterThan(0.33, $result->clampedMargin);
        $this->assertNotNull($result->streamRevenue['cre']);
    }

    public function testResortAgingAndModernizationCapEx(): void
    {
        $model = new ResortsCasinosBusinessModel();

        // R = 0.5 -> Resort aging, room fatigue
        $stock = new Stock();
        $stock->setOperatingMargin('0.25');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.25, $decayed);
        $this->assertGreaterThanOrEqual(ResortsCasinosBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 -> Modernization, new flagship attractions
        $stock->setOperatingMargin('0.25');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.25, $expanded);
        $this->assertLessThanOrEqual(ResortsCasinosBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testDynamicRevenueMixDriftsAcrossStreams(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GULL');

        // Previous quarter had heavy gaming share surge
        $stock->setEarningsMomentumZ([
            'weight:gaming'     => 0.20,
            'weight:non_gaming' => 0.10,
            'weight:cre'        => 0.70,
            'share:gaming'      => 0.40,
            'share:non_gaming'  => 0.10,
            'share:cre'         => 0.50,
        ]);

        $mathUtility = new MathUtility();
        $macroState = $this->createMacroState();

        $result = $model->computeActualFinancials($stock, 10_000.0, 0.33, 2000.0, 0.15, $macroState, $mathUtility);

        // Gaming active weight should drift up from 0.20
        $activeGaming = $result->streamZ['weight:gaming'];
        $this->assertGreaterThan(0.20, $activeGaming);

        // Total active weights sum to 1.0
        $totalWeight = $result->streamZ['weight:gaming'] + $result->streamZ['weight:non_gaming'] + $result->streamZ['weight:cre'];
        $this->assertEqualsWithDelta(1.0, $totalWeight, 0.0001);
    }
}

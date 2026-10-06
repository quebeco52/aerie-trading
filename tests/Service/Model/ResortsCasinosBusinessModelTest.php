<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ResortsCasinosBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Math\FinancialConstants;
use App\Service\Corporate\EarningsEngine;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Draw order in calculateSectorPhysics(): gaming, non_gaming, hold, event, then cre for a landlord.
 */
#[AllowMockObjectsWithoutExpectations]
class ResortsCasinosBusinessModelTest extends TestCase
{
    private function createMacroState(
        float $inflation = 0.02,
        float $outputGap = 0.0,
        float $consumerSentimentIndexEma = MacroEngine::SENTIMENT_TREND_LEVEL,
        float $energyPriceIndexEma = 100.0,
        float $macroCreditSpread = 0.015,
        float $yield10y = 0.04,
        float $energyCostPushLag = 0.0,
        float $realWageGap = 0.0,
        float $commercialPropertyIndexEma = 100.0,
        float $retailDefaultRateEma = MacroEngine::RETAIL_DEFAULT_BASELINE
    ): MacroStateDTO {
        return new MacroStateDTO(
            outputGap: $outputGap,
            outputGapEma: $outputGap,
            unemploymentRate: 0.04,
            unemploymentRateEma: 0.04,
            energyPriceIndex: $energyPriceIndexEma,
            energyPriceIndexEma: $energyPriceIndexEma,
            energyPriceShock: 0.0,
            energyCostPushLag: $energyCostPushLag,
            realWageGap: $realWageGap,
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
            commercialPropertyIndex: $commercialPropertyIndexEma,
            commercialPropertyIndexEma: $commercialPropertyIndexEma,
            retailDefaultRateEma: $retailDefaultRateEma,
        );
    }

    /** @param list<float> $persistentZCalls */
    private function createMathUtilityMock(array $persistentZCalls = []): MathUtility
    {
        $mock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ', 'checkProbability'])
            ->getMock();

        if (!empty($persistentZCalls)) {
            $mock->method('generatePersistentZ')
                ->willReturnOnConsecutiveCalls(...array_values($persistentZCalls));
        }
        // Regime exits never fire, so a crackdown's persistence is deterministic.
        $mock->method('checkProbability')->willReturn(false);

        return $mock;
    }

    private function stock(string $ticker, array $momentum = []): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setBeta('1.0');
        if ($momentum !== []) {
            $stock->setEarningsMomentumZ($momentum);
        }

        return $stock;
    }

    /** The firm's cycle shift before confidence and the foreign bloc: lagged gap x (0.5 + pricing power) x cyclicality + FX. */
    private function cycleShift(ResortsCasinosBusinessModel $model, Stock $stock, MacroStateDTO $macro): float
    {
        return (float) (new \ReflectionMethod(StandardCorporateBusinessModel::class, 'resolveCycleDemandShift'))->invoke($model, $stock, $macro);
    }

    /** GULL's share of revenue that moves with visitor volume: gaming, non-gaming and percentage rent. */
    private function gullVisitorShare(): float
    {
        return 0.20 + 0.10 + (0.70 * ResortsCasinosBusinessModel::OVERAGE_RENT_SHARE);
    }

    public function testConsumerSentimentResidualShiftsVisitorDemand(): void
    {
        $model = new ResortsCasinosBusinessModel();

        // Confidence twenty points above trend with no output gap: all of it is residual.
        $boom = $model->getMacroPhysics($this->stock('CASINO'), $this->createMacroState(consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL + 20.0));
        $this->assertEqualsWithDelta(0.20 * ResortsCasinosBusinessModel::OPERATING_CYCLICALITY * ResortsCasinosBusinessModel::SENTIMENT_SENSITIVITY_SCALAR, $boom['macro_demand_shift'], 0.001);

        $slump = $model->getMacroPhysics($this->stock('CASINO'), $this->createMacroState(consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL - 20.0));
        $this->assertEqualsWithDelta(-0.20 * ResortsCasinosBusinessModel::OPERATING_CYCLICALITY * ResortsCasinosBusinessModel::SENTIMENT_SENSITIVITY_SCALAR, $slump['macro_demand_shift'], 0.001);
    }

    public function testLandlordRootShiftIsTheVisitorShareOfTheCycle(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $gap = -0.03;
        // Confidence exactly where the gap puts it adds nothing beyond the gap (Lemmon & Portniaguina 2006).
        $macro = new MacroStateDTO(
            outputGapEma: $gap,
            consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL + (MacroEngine::SENTIMENT_GAP_LOADING * $gap),
            exchangeRateIndexEma: 100.0,
        );

        $cycle = $this->cycleShift($model, $this->stock('GULL'), $macro);
        $this->assertLessThan(0.0, $cycle);

        // Base rent contracted at trend: only a third of a 70% landlord's revenue moves with visitors this quarter.
        $root = $model->getMacroPhysics($this->stock('GULL', ['state:in_place_rent' => 0.0]), $macro)['macro_demand_shift'];
        $this->assertEqualsWithDelta($cycle * $this->gullVisitorShare(), $root, 1e-9);

        // A roll already at market moves one for one: the whole firm then follows visitors.
        $atMarket = $model->getMacroPhysics($this->stock('GULL', ['state:in_place_rent' => $cycle]), $macro)['macro_demand_shift'];
        $this->assertEqualsWithDelta($cycle, $atMarket, 1e-9);
    }

    public function testEachStreamCarriesItsOwnVisitorExposureAndRentRollsSlowly(): void
    {
        $model = new ResortsCasinosBusinessModel();
        // Confidence on the gap's fit, so the cycle shift is the whole visitor shift.
        $macro = $this->createMacroState(outputGap: -0.04, consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL + (MacroEngine::SENTIMENT_GAP_LOADING * -0.04));
        $visitor = $this->cycleShift($model, $this->stock('GULL'), $macro);
        $root = $visitor * $this->gullVisitorShare();

        // In-place rents opened at trend last quarter; all draws zero. Expected revenue holds the root shift, as the engine builds it.
        $result = $model->computeActualFinancials($this->stock('GULL', ['state:in_place_rent' => 0.0]), 10_000.0 * (1.0 + $root), 0.33, 2000.0, 0.15, $macro, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0, 0.0]));
        $w = $result->streamZ;

        // The gaming floor takes the whole visitor shift.
        $this->assertEqualsWithDelta(1.0 + $visitor, $result->streamRevenue['gaming'] / (10_000.0 * $w['weight:gaming']), 1e-9);

        // The rent roll reprices only its expiring slice toward market; percentage rent follows tenant sales.
        $o = ResortsCasinosBusinessModel::OVERAGE_RENT_SHARE;
        $rolled = $visitor * EarningsEngine::QUARTERLY_TIME_STEP / ResortsCasinosBusinessModel::CRE_LEASE_WALT_YEARS;
        $expectedCre = 1.0 + ((1.0 - $o) * $rolled) + ($o * $visitor);
        $this->assertEqualsWithDelta($expectedCre, $result->streamRevenue['cre'] / (10_000.0 * $w['weight:cre']), 1e-9);
        $this->assertEqualsWithDelta($rolled, $result->streamZ[ResortsCasinosBusinessModel::STATE_IN_PLACE_RENT], 1e-12);
        $this->assertEqualsWithDelta($rolled, $result->kpis['in_place_rent_index'], 1e-12);
    }

    public function testRentIgnoresPropertyPricesAndInflation(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $momentum = ['state:in_place_rent' => 0.0];

        $neutral = $model->computeActualFinancials($this->stock('GULL', $momentum), 10_000.0, 0.33, 2000.0, 0.15, $this->createMacroState(), $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0, 0.0]));
        // Prices up 50% (cap-rate compression) and inflation 4pp over target: rent income is set by tenant sales and
        // the contracted roll, and expected revenue already carries the pricing pass-through.
        $hot = $model->computeActualFinancials($this->stock('GULL', $momentum), 10_000.0, 0.33, 2000.0, 0.15, $this->createMacroState(inflation: 0.06, commercialPropertyIndexEma: 150.0), $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0, 0.0]));

        $this->assertEqualsWithDelta($neutral->streamRevenue['cre'], $hot->streamRevenue['cre'], 1e-6);
    }

    public function testTenantDefaultsCostRent(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $momentum = ['state:in_place_rent' => 0.0];

        $calm = $model->computeActualFinancials($this->stock('GULL', $momentum), 10_000.0, 0.33, 2000.0, 0.15, $this->createMacroState(), $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0, 0.0]));
        // Retail defaults at twice their baseline: one unit of relative excess, already seen in expected revenue.
        $macroWave = $this->createMacroState(retailDefaultRateEma: 2.0 * MacroEngine::RETAIL_DEFAULT_BASELINE);
        $root = $model->getMacroPhysics($this->stock('GULL', $momentum), $macroWave)['macro_demand_shift'];
        $wave = $model->computeActualFinancials($this->stock('GULL', $momentum), 10_000.0 * (1.0 + $root), 0.33, 2000.0, 0.15, $macroWave, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0, 0.0]));

        $lost = 10_000.0 * $calm->streamZ['weight:cre'] * ResortsCasinosBusinessModel::RETAIL_TENANT_DEFAULT_RENT_LOSS;
        $this->assertEqualsWithDelta(-0.70 * ResortsCasinosBusinessModel::RETAIL_TENANT_DEFAULT_RENT_LOSS, $root, 1e-9);
        $this->assertEqualsWithDelta($lost, $calm->streamRevenue['cre'] - $wave->streamRevenue['cre'], 1e-6);
        // Lost rent saves no operating cost.
        $this->assertEqualsWithDelta($calm->clampedMargin * $calm->actualRevenue, $wave->clampedMargin * $wave->actualRevenue, 1e-6);
    }

    public function testHoldLuckMovesGamingWinWithoutCost(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $macro = $this->createMacroState();

        $even = $model->computeActualFinancials($this->stock('CASINO'), 10_000.0, 0.58, 2000.0, 0.15, $macro, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]));
        $hot = $model->computeActualFinancials($this->stock('CASINO'), 10_000.0, 0.58, 2000.0, 0.15, $macro, $this->createMathUtilityMock([0.0, 0.0, 2.0, 0.0]));

        $luck = 2.0 * ResortsCasinosBusinessModel::HIGH_LIMIT_SHARE_OF_GAMING * ResortsCasinosBusinessModel::HIGH_LIMIT_HOLD_VOLATILITY;
        $this->assertEqualsWithDelta($even->streamRevenue['gaming'] * (1.0 + $luck), $hot->streamRevenue['gaming'], 1e-6);
        $this->assertEqualsWithDelta($even->streamRevenue['non_gaming'], $hot->streamRevenue['non_gaming'], 1e-6);
        // The house's extra win drops straight through: variable costs are unchanged in dollars.
        $this->assertEqualsWithDelta($even->clampedMargin * $even->actualRevenue, $hot->clampedMargin * $hot->actualRevenue, 1e-6);
        $this->assertNull($hot->eventType);
    }

    public function testCrackdownPersistsAcrossQuarters(): void
    {
        $model = new ResortsCasinosBusinessModel();
        $macro = $this->createMacroState();
        $stock = $this->stock('CASINO');

        // eventZ -2.5: Phi(-2.5) = 0.6% under the quarterly arrival probability 1 - exp(-0.056 / 4) = 1.4%.
        $onset = $model->computeActualFinancials($stock, 10_000.0, 0.58, 2000.0, 0.15, $macro, $this->createMathUtilityMock([0.0, 0.0, 0.0, -2.5]));
        $this->assertSame(ShockEvent::GAMING_CRACKDOWN, $onset->eventType);
        $this->assertEqualsWithDelta(5500.0 * (1.0 - ResortsCasinosBusinessModel::GAMING_REGULATION_HAIRCUT), $onset->streamRevenue['gaming'], 1e-6);

        // Next quarter, no fresh event: the regime still holds gaming down, is not announced again, and expected
        // revenue already carries it.
        $stock->setEarningsMomentumZ($onset->streamZ);
        $root = $model->getMacroPhysics($stock, $macro)['macro_demand_shift'];
        $this->assertEqualsWithDelta(-ResortsCasinosBusinessModel::GAMING_REVENUE_WEIGHT * ResortsCasinosBusinessModel::GAMING_REGULATION_HAIRCUT, $root, 1e-9);
        $later = $model->computeActualFinancials($stock, 10_000.0 * (1.0 + $root), 0.58, 2000.0, 0.15, $macro, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]));
        $this->assertNull($later->eventType);
        $this->assertEqualsWithDelta(1.0 - ResortsCasinosBusinessModel::GAMING_REGULATION_HAIRCUT, $later->streamRevenue['gaming'] / (10_000.0 * $later->streamZ['weight:gaming']), 1e-9);
        $this->assertEqualsWithDelta(1.0, $later->streamRevenue['non_gaming'] / (10_000.0 * $later->streamZ['weight:non_gaming']), 1e-9);

        // A draw that is not in the tail starts nothing.
        $calm = $model->computeActualFinancials($this->stock('CASINO'), 10_000.0, 0.58, 2000.0, 0.15, $macro, $this->createMathUtilityMock([0.0, 0.0, 0.0, -2.0]));
        $this->assertNull($calm->eventType);
        $this->assertEqualsWithDelta(5500.0, $calm->streamRevenue['gaming'], 1e-6);
    }

    public function testPromotionalCompsIsolatesToNonGamingLedger(): void
    {
        $model = new ResortsCasinosBusinessModel();

        $promotionalDrag = 0.20 * ResortsCasinosBusinessModel::OPERATING_CYCLICALITY * ResortsCasinosBusinessModel::PROMOTIONAL_COMP_DRAG_SCALAR;
        $macroState = $this->createMacroState(consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL - 20.0);

        // baseGamingMargin = 0.58 / (0.55 + 2.0 * 0.45) = 0.40; hotel and F&B run at 0.80 plus the comps.
        $result = $model->computeActualFinancials($this->stock('CASINO'), 10_000.0, 0.58, 2000.0, 0.15, $macroState, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]));

        $expectedMargin = ((5500.0 * 0.40) + (4500.0 * (0.80 + $promotionalDrag))) / 10_000.0;
        $this->assertEqualsWithDelta($expectedMargin, $result->clampedMargin, 0.001);
    }

    public function testInputCostDragScalesByOperationalFootprint(): void
    {
        $model = new ResortsCasinosBusinessModel();

        // The real wage runs 10% over its productivity path. Payroll is the resort's material tracked input (BEA puts its
        // fuel and food content under the materiality floor). The basket buys at spot, so the full move lands in the cost
        // base this quarter; room and menu pricing recovers pricingPower x MAX_INPUT_COST_PASS_THROUGH of it with the
        // pass-through lag.
        $wageDeviation = 0.10 * ResortsCasinosBusinessModel::INPUT_COST_EXPOSURES['labor'];
        $recoveryWeight = 1.0 - exp(-EarningsEngine::QUARTERLY_TIME_STEP / FinancialConstants::DEFAULT_INPUT_PASS_THROUGH_LAG_YEARS);
        $macroBase = $this->createMacroState();
        $macroWage = $this->createMacroState(realWageGap: 0.10);

        // 1. Pure-play casino resort: whole footprint, the sector's pricing power.
        $resPureBase = $model->computeActualFinancials($this->stock('CASINO'), 10_000.0, 0.58, 2000.0, 0.15, $macroBase, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]));
        $resPure = $model->computeActualFinancials($this->stock('CASINO'), 10_000.0, 0.58, 2000.0, 0.15, $macroWage, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]));

        $pureRecovered = ResortsCasinosBusinessModel::PRICING_POWER_INDEX * ResortsCasinosBusinessModel::MAX_INPUT_COST_PASS_THROUGH * $recoveryWeight;
        $expectedPureDrag = 0.58 * $wageDeviation * (1.0 - $pureRecovered);
        $this->assertEqualsWithDelta($expectedPureDrag, $resPure->clampedMargin - $resPureBase->clampedMargin, 0.05 * $expectedPureDrag);

        // 2. Landlord (GULL: 20% gaming, 10% non-gaming, 70% CRE -> footprint 0.30, pricing power 1.00). Only the
        // physical resort carries the operating bill; NNN tenants staff and run their own.
        $momentum = ['state:in_place_rent' => 0.0];
        $resGullBase = $model->computeActualFinancials($this->stock('GULL', $momentum), 10_000.0, 0.33, 2000.0, 0.15, $macroBase, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0, 0.0]));
        $resGull = $model->computeActualFinancials($this->stock('GULL', $momentum), 10_000.0, 0.33, 2000.0, 0.15, $macroWage, $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0, 0.0]));

        $gullRecovered = 1.00 * ResortsCasinosBusinessModel::MAX_INPUT_COST_PASS_THROUGH * $recoveryWeight;
        $expectedGullDrag = 0.33 * $wageDeviation * (1.0 - $gullRecovered) * 0.30;
        $this->assertEqualsWithDelta($expectedGullDrag, $resGull->clampedMargin - $resGullBase->clampedMargin, 0.05 * $expectedGullDrag);
        $this->assertLessThan($expectedPureDrag, $expectedGullDrag);
    }

    public function testSeasonalityAveragesToOne(): void
    {
        $factors = (new ResortsCasinosBusinessModel())->getSeasonalityFactors();

        $this->assertCount(4, $factors);
        $this->assertEqualsWithDelta(1.0, array_sum($factors) / 4.0, 0.005);
        // Coastal resort: the summer quarter peaks, the winter quarter troughs.
        $this->assertSame(2, array_search(max($factors), $factors, true));
        $this->assertSame(0, array_search(min($factors), $factors, true));
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

        // Previous quarter had heavy gaming share surge
        $stock = $this->stock('GULL', [
            'weight:gaming'     => 0.20,
            'weight:non_gaming' => 0.10,
            'weight:cre'        => 0.70,
            'share:gaming'      => 0.40,
            'share:non_gaming'  => 0.10,
            'share:cre'         => 0.50,
        ]);

        $result = $model->computeActualFinancials($stock, 10_000.0, 0.33, 2000.0, 0.15, $this->createMacroState(), new MathUtility());

        // Gaming active weight should drift up from 0.20
        $this->assertGreaterThan(0.20, $result->streamZ['weight:gaming']);

        // Total active weights sum to 1.0
        $totalWeight = $result->streamZ['weight:gaming'] + $result->streamZ['weight:non_gaming'] + $result->streamZ['weight:cre'];
        $this->assertEqualsWithDelta(1.0, $totalWeight, 0.0001);
    }
}

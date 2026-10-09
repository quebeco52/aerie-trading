<?php

namespace App\Tests\Service\Math;

use App\Service\Macro\MacroEngine;
use App\Service\Math\MacroTransmission;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\MacroTransmission.
 */
class MacroTransmissionTest extends TestCase
{
    public function testCalculateGscpiComposite(): void
    {
        // Baseline shipping conditions
        $neutralGscpi = MacroTransmission::calculateGscpiComposite(
            freightRateIndex: 100.0,
            inventoryStockGap: 0.0,
            industrialMetalsIndex: 100.0
        );
        $this->assertEqualsWithDelta(0.0, $neutralGscpi, 0.0001, 'Baseline freight and logistics must yield 0 GSCPI.');

        // Global supply chain bottleneck crisis (freight +150, inventory stock shortage -0.05, metals +50)
        $bottleneckGscpi = MacroTransmission::calculateGscpiComposite(
            freightRateIndex: 250.0,
            inventoryStockGap: -0.05,
            industrialMetalsIndex: 150.0
        );
        $this->assertGreaterThan(2.0, $bottleneckGscpi, 'Severe supply chain congestion must yield high positive GSCPI index.');

        // Slack logistics capacity (freight 70, inventory glut +0.05, metals 80)
        $slackGscpi = MacroTransmission::calculateGscpiComposite(
            freightRateIndex: 70.0,
            inventoryStockGap: 0.05,
            industrialMetalsIndex: 80.0
        );
        $this->assertLessThan(0.0, $slackGscpi, 'Excess shipping capacity and loose inventories must yield negative GSCPI index.');
    }

    public function testCalculateCapitalMarketsDealIndexStep(): void
    {
        // Mean reversion toward baseline (dW = 0)
        $revertingIndex = MacroTransmission::calculateCapitalMarketsDealIndexStep(
            currentDealIndex: 80.0,
            equityRiskPremium: 0.045,
            hyCreditSpread: 0.048,
            marketVolatility: 0.15,
            dt: 0.25,
            dW: 0.0
        );
        $this->assertGreaterThan(80.0, $revertingIndex, 'Index below baseline must mean-revert upwards.');

        // Golden era: Low ERP (3.0%), tight credit spreads (2.5%), low VIX (10%)
        $goldenEra = MacroTransmission::calculateCapitalMarketsDealIndexStep(
            currentDealIndex: 100.0,
            equityRiskPremium: 0.030,
            hyCreditSpread: 0.025,
            marketVolatility: 0.10,
            dt: 0.25,
            dW: 0.0
        );
        $this->assertGreaterThan(100.0, $goldenEra, 'High equity multiples, tight spreads, and low VIX must stimulate M&A deal activity.');

        // Credit freeze & market panic: Elevated ERP (7.0%), wide credit spread (9.0%), VIX spike (40%)
        $panicIndex = MacroTransmission::calculateCapitalMarketsDealIndexStep(
            currentDealIndex: 100.0,
            equityRiskPremium: 0.070,
            hyCreditSpread: 0.090,
            marketVolatility: 0.40,
            dt: 0.25,
            dW: 0.0
        );
        $this->assertLessThan(100.0, $panicIndex, 'Market panic and credit freeze must collapse deal activity index.');
        $this->assertGreaterThanOrEqual(20.0, $panicIndex);

        // The full cycle is bounded at ~2x either way: iterate each regime to its target with no noise.
        $trough = 100.0;
        $peak = 100.0;
        for ($i = 0; $i < 40; $i++) {
            $trough = MacroTransmission::calculateCapitalMarketsDealIndexStep($trough, 0.070, 0.150, 0.60, 0.25, 0.0);
            $peak = MacroTransmission::calculateCapitalMarketsDealIndexStep($peak, 0.025, 0.020, 0.09, 0.25, 0.0);
        }
        $floor = MacroEngine::DEAL_ACTIVITY_BASELINE * exp(-MacroEngine::DEAL_ACTIVITY_LOG_RANGE);
        $ceiling = MacroEngine::DEAL_ACTIVITY_BASELINE * exp(MacroEngine::DEAL_ACTIVITY_LOG_RANGE);
        $this->assertEqualsWithDelta($floor, $trough, 0.5, 'A 2008-type freeze bottoms at the capped log range, not at the hard floor.');
        $this->assertGreaterThan(140.0, $peak, 'A 2007/2021-type boom runs about 1.5x baseline.');
        $this->assertLessThan($ceiling, $peak, 'and does not need the cap to stay within a recorded cycle.');

        // Policy uncertainty (Bonaime, Gulen & Ion 2018): boards defer deals while the regime is in question.
        $settled = MacroTransmission::calculateCapitalMarketsDealIndexStep(100.0, 0.045, 0.043, 0.15, 0.25, 0.0, policyUncertaintyIndex: MacroEngine::EPU_BASELINE);
        $contested = MacroTransmission::calculateCapitalMarketsDealIndexStep(100.0, 0.045, 0.043, 0.15, 0.25, 0.0, policyUncertaintyIndex: 2.0 * MacroEngine::EPU_BASELINE);
        $this->assertLessThan($settled, $contested, 'A doubling of policy uncertainty lowers the deal-flow target with valuations and credit unchanged.');
        $this->assertLessThan(2.8, $peak / $trough, 'Peak-to-trough deal volume stays near the 2.2x of the widest recorded cycle.');
    }

    public function testCalculateDiffusionIndex(): void
    {
        // 1. Neutral baseline (no deviations)
        $neutral = MacroTransmission::calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [
                ['deviation' => 0.0, 'sensitivity' => 120.0],
                ['deviation' => 0.0, 'sensitivity' => 80.0],
            ]
        );
        $this->assertEqualsWithDelta(50.0, $neutral, 0.001);

        // 2. Expansionary drivers (positive deviations)
        $expansion = MacroTransmission::calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [
                ['deviation' => 0.03, 'sensitivity' => 150.0], // +4.5
                ['deviation' => 0.02, 'sensitivity' => 50.0],  // +1.0
            ]
        );
        $this->assertEqualsWithDelta(55.5, $expansion, 0.001);
        $this->assertGreaterThan(50.0, $expansion);

        // 3. Contractionary drivers (negative deviations)
        $contraction = MacroTransmission::calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [
                ['deviation' => -0.04, 'sensitivity' => 150.0], // -6.0
                ['deviation' => -0.02, 'sensitivity' => 50.0],  // -1.0
            ]
        );
        $this->assertEqualsWithDelta(43.0, $contraction, 0.001);
        $this->assertLessThan(50.0, $contraction);

        // 4. Clamping bounds
        $clampedHigh = MacroTransmission::calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [['deviation' => 1.0, 'sensitivity' => 100.0]],
            min: 30.0,
            max: 70.0
        );
        $this->assertEqualsWithDelta(70.0, $clampedHigh, 0.001);

        $clampedLow = MacroTransmission::calculateDiffusionIndex(
            baseline: 50.0,
            drivers: [['deviation' => -1.0, 'sensitivity' => 100.0]],
            min: 30.0,
            max: 70.0
        );
        $this->assertEqualsWithDelta(30.0, $clampedLow, 0.001);
    }

    public function testCalculateStageOfProcessingPpi(): void
    {
        $weights = [
            'metals' => 0.15,
            'energy' => 0.20,
            'agri' => 0.15,
            'gscpi' => 0.005,
            'ulc' => 0.35,
            'demand' => 0.15,
        ];

        // Neutral wholesale pricing
        $neutralPpi = MacroTransmission::calculateStageOfProcessingPpi(
            metalsInflation: 0.02,
            energyInflation: 0.02,
            agriInflation: 0.02,
            gscpiZ: 0.0,
            unitLaborCost: 0.02,
            outputGap: 0.0,
            weights: $weights
        );
        // (0.15*0.02) + (0.20*0.02) + (0.15*0.02) + 0 + (0.35*0.02) + 0 = 0.003 + 0.004 + 0.003 + 0.007 = 0.017
        $this->assertEqualsWithDelta(0.017, $neutralPpi, 0.0001);

        // Commodity & supply chain shock
        $shockPpi = MacroTransmission::calculateStageOfProcessingPpi(
            metalsInflation: 0.20, // +20%
            energyInflation: 0.40, // +40%
            agriInflation: 0.10,   // +10%
            gscpiZ: 2.5,           // Supply bottleneck
            unitLaborCost: 0.05,   // Elevated ULC
            outputGap: 0.02,
            weights: $weights
        );
        $this->assertGreaterThan($neutralPpi, $shockPpi);
        $this->assertGreaterThan(0.10, $shockPpi);

        // Clamping to bounds
        $clampedMax = MacroTransmission::calculateStageOfProcessingPpi(
            metalsInflation: 2.0,
            energyInflation: 2.0,
            agriInflation: 2.0,
            gscpiZ: 10.0,
            unitLaborCost: 1.0,
            outputGap: 1.0,
            weights: $weights,
            min: -0.06,
            max: 0.25
        );
        $this->assertEqualsWithDelta(0.25, $clampedMax, 0.0001);
    }

    public function testCalculateTobinsQHousingStarts(): void
    {
        $params = [
            'baseline' => 100.0,
            'qSens' => 45.0,
            'costSens' => 550.0,
            'sloosSens' => 30.0,
            'kappa' => 1.20,
            'sigma' => 0.03,
            'min' => 40.0,
            'max' => 180.0,
        ];

        // Boom scenario: high house prices (Q > 1), low mortgage user cost
        $boomStarts = MacroTransmission::calculateTobinsQHousingStarts(
            currentStarts: 100.0,
            residentialPriceRatio: 1.20,
            replacementCostRatio: 1.00,
            userCost: 0.035,
            neutralUserCost: 0.050,
            sloosTightening: 0.0,
            dt: 0.25,
            dW: 0.0,
            params: $params
        );
        $this->assertGreaterThan(100.0, $boomStarts, 'High Tobin Q and cheap mortgage financing must stimulate housing starts.');

        // Housing slump: high user cost, bank tightening, falling price/cost ratio
        $slumpStarts = MacroTransmission::calculateTobinsQHousingStarts(
            currentStarts: 100.0,
            residentialPriceRatio: 0.85,
            replacementCostRatio: 1.10,
            userCost: 0.075,
            neutralUserCost: 0.050,
            sloosTightening: 0.35,
            dt: 0.25,
            dW: 0.0,
            params: $params
        );
        $this->assertLessThan(100.0, $slumpStarts, 'High mortgage rates and tight bank lending must depress housing starts.');
        $this->assertGreaterThanOrEqual(40.0, $slumpStarts);

        // Credit gap availability: positive credit expansion stimulates housing starts at the sensitivity the caller sets
        $params['creditGapSens'] = \App\Service\Macro\Subsystem\AssetMarketSubsystem::HOUSING_STARTS_CREDIT_GAP_SENSITIVITY;
        $creditBoomStarts = MacroTransmission::calculateTobinsQHousingStarts(
            currentStarts: 100.0,
            residentialPriceRatio: 1.00,
            replacementCostRatio: 1.00,
            userCost: 0.050,
            neutralUserCost: 0.050,
            sloosTightening: 0.0,
            dt: 0.25,
            dW: 0.0,
            params: $params,
            creditGap: 0.05
        );
        $neutralStarts = MacroTransmission::calculateTobinsQHousingStarts(
            currentStarts: 100.0,
            residentialPriceRatio: 1.00,
            replacementCostRatio: 1.00,
            userCost: 0.050,
            neutralUserCost: 0.050,
            sloosTightening: 0.0,
            dt: 0.25,
            dW: 0.0,
            params: $params,
            creditGap: 0.0
        );
        $this->assertGreaterThan($neutralStarts, $creditBoomStarts, 'Positive credit-to-GDP gap must stimulate housing starts.');
    }

    public function testCalculateBroadMoneyGrowth(): void
    {
        $params = [
            'qeSens' => 0.08,
            'sloosSens' => 0.06,
            'gapSens' => 0.30,
            'kappa' => 1.00,
            'sigma' => 0.004,
            'min' => -0.04,
            'max' => 0.25,
        ];

        // Expansionary QE and loose credit
        $qeGrowth = MacroTransmission::calculateBroadMoneyGrowth(
            currentM2Growth: 0.055,
            baseGrowth: 0.055,
            balanceSheetIntensity: 0.50, // Active QE
            sloosTightening: -0.10,      // Easing standards
            outputGap: 0.02,
            dt: 0.25,
            dW: 0.0,
            params: $params
        );
        $this->assertGreaterThan(0.055, $qeGrowth, 'Central bank balance sheet expansion must accelerate M2 broad money growth.');

        // Quantitative tightening & banking credit crunch
        $qtGrowth = MacroTransmission::calculateBroadMoneyGrowth(
            currentM2Growth: 0.055,
            baseGrowth: 0.055,
            balanceSheetIntensity: -0.50, // Active QT runoff
            sloosTightening: 0.40,        // 40% net banks tightening
            outputGap: -0.03,
            dt: 0.25,
            dW: 0.0,
            params: $params
        );
        $this->assertLessThan(0.055, $qtGrowth, 'QT runoff and commercial bank lending standards tightening must decelerate M2 growth.');
        $this->assertGreaterThanOrEqual(-0.04, $qtGrowth);
    }

    /** Lending standards enter M2's target signed (Lown & Morgan 2006): easing lends deposits into being as tightening withholds them, symmetrically. */
    public function testBroadMoneyReadsLendingStandardsBothWays(): void
    {
        $params = ['qeSens' => 0.0, 'sloosSens' => 0.06, 'gapSens' => 0.0, 'kappa' => 1.0, 'sigma' => 0.0, 'min' => -0.04, 'max' => 0.25];
        $growth = fn (float $sloos): float => MacroTransmission::calculateBroadMoneyGrowth(0.04, 0.04, 0.0, $sloos, 0.0, 0.25, 0.0, $params);

        $this->assertEqualsWithDelta(0.04 - $growth(0.20), $growth(-0.20) - 0.04, 1e-12, 'Easing lifts M2 as much as tightening of the same size cuts it.');
        $this->assertGreaterThan(0.04, $growth(-0.20));
    }

    public function testMacroTransmissionHelpers(): void
    {
        // 1. calculatePmiDemandShift
        // Neutral 50.0 -> 0.0 shift
        $neutralPmi = MacroTransmission::calculatePmiDemandShift(50.0);
        $this->assertEqualsWithDelta(0.0, $neutralPmi, 0.0001);

        // Expansion 55.0 -> (55-50)/50 * 0.50 = +0.05
        $expansionPmi = MacroTransmission::calculatePmiDemandShift(55.0, 50.0, 0.50);
        $this->assertEqualsWithDelta(0.05, $expansionPmi, 0.0001);

        // Contraction 45.0 -> (45-50)/50 * 0.50 = -0.05
        $contractionPmi = MacroTransmission::calculatePmiDemandShift(45.0, 50.0, 0.50);
        $this->assertEqualsWithDelta(-0.05, $contractionPmi, 0.0001);

        // Clamping bounds [-0.30, 0.30]
        $extremePmi = MacroTransmission::calculatePmiDemandShift(90.0, 50.0, 1.0);
        $this->assertLessThanOrEqual(0.30, $extremePmi);

        // 2. calculatePpiCostDrag
        // PPI <= target yields 0.0 drag
        $this->assertEqualsWithDelta(0.0, MacroTransmission::calculatePpiCostDrag(0.02, 0.02), 0.0001);
        $this->assertEqualsWithDelta(0.0, MacroTransmission::calculatePpiCostDrag(0.01, 0.02), 0.0001);

        // High PPI (0.06 vs 0.02 target), pricing power 0.50, sensitivity 0.50 -> (0.06-0.02) * (1 - 0.5) * 0.5 = 0.01
        $ppiDrag = MacroTransmission::calculatePpiCostDrag(0.06, 0.02, 0.50, 0.50);
        $this->assertEqualsWithDelta(0.01, $ppiDrag, 0.0001);

        // Perfect pricing power (1.0) eliminates PPI cost drag
        $this->assertEqualsWithDelta(0.0, MacroTransmission::calculatePpiCostDrag(0.08, 0.02, 1.0), 0.0001);

        // 3. calculateHousingStartsShift
        // Neutral 100.0 -> 0.0 shift
        $this->assertEqualsWithDelta(0.0, MacroTransmission::calculateHousingStartsShift(100.0), 0.0001);
        // Boom 120.0 -> (120-100)/100 * 0.30 = +0.06
        $this->assertEqualsWithDelta(0.06, MacroTransmission::calculateHousingStartsShift(120.0, 100.0, 0.30), 0.0001);

        // 4. calculateTradeBalanceShift
        // Baseline -0.028 -> 0.0
        $this->assertEqualsWithDelta(0.0, MacroTransmission::calculateTradeBalanceShift(-0.028, -0.028), 0.0001);
        // Improvement to -0.018 (+0.01) * 2.0 = +0.02
        $this->assertEqualsWithDelta(0.02, MacroTransmission::calculateTradeBalanceShift(-0.018, -0.028, 2.0), 0.0001);

        // 5. calculateBroadMoneyLiquidityShift
        // Neutral 0.055 -> 0.0
        $this->assertEqualsWithDelta(0.0, MacroTransmission::calculateBroadMoneyLiquidityShift(0.055, 0.055), 0.0001);
        // Expansion 0.085 (+0.03) * 0.50 = +0.015
        $this->assertEqualsWithDelta(0.015, MacroTransmission::calculateBroadMoneyLiquidityShift(0.085, 0.055, 0.50), 0.0001);

        // 6. calculateCapacityUtilizationShift
        // Fed G.17 and MacroEngine::CU_BASELINE both express utilization as a FRACTION, so the helper is fed
        // 0.785 rather than 78.5. Neutral 0.785 -> 0.0
        $this->assertEqualsWithDelta(0.0, MacroTransmission::calculateCapacityUtilizationShift(0.785, 0.785), 0.0001);
        // Shift to 0.805 (+2.0 points of utilization) -> 0.020 * 0.40 = 0.008 (+0.8%)
        $this->assertEqualsWithDelta(0.008, MacroTransmission::calculateCapacityUtilizationShift(0.805, 0.785, 0.40), 0.0001);
        // The live engine clamps utilization to [0.60, 0.92], so the reachable gap spans roughly -0.185 to
        // +0.135. A boom at the physical ceiling has to clear the sector shock gates, which are set in the
        // same units (SemiconductorBusinessModel::BOOM_CAPACITY_UTILIZATION_THRESHOLD = 0.030, i.e. 3 points).
        $this->assertGreaterThan(0.030, MacroTransmission::calculateCapacityUtilizationShift(0.92, 0.785, 1.0));
        $this->assertLessThan(-0.030, MacroTransmission::calculateCapacityUtilizationShift(0.60, 0.785, 1.0));
    }

    /**
     * The convex Phillips curve is piecewise: asymptotic in expansion, linearly rigid in contraction.
     */
    public function testConvexPhillipsCurvePiecewiseFormAndContinuityAtZero(): void
    {
        $this->assertSame(0.0, MacroTransmission::calculateConvexPhillipsCurve(0.0), 'A closed output gap exerts no demand-pull pressure.');

        // Expansion branch: kappa * (y / (yMax - y)).
        $this->assertEqualsWithDelta(
            0.020 * (0.04 / (0.08 - 0.04)),
            MacroTransmission::calculateConvexPhillipsCurve(0.04),
            0.0000001,
            'The expansion branch must follow the asymptotic form.'
        );

        // Contraction branch: (kappa / yMax) * rigidity * y — linear, so halving the gap halves the pressure.
        $this->assertEqualsWithDelta(
            (0.020 / 0.08) * 0.35 * -0.04,
            MacroTransmission::calculateConvexPhillipsCurve(-0.04),
            0.0000001,
            'The contraction branch must follow the rigid linear form.'
        );
        $this->assertEqualsWithDelta(
            2.0 * MacroTransmission::calculateConvexPhillipsCurve(-0.02),
            MacroTransmission::calculateConvexPhillipsCurve(-0.04),
            0.0000001,
            'Downward rigidity is linear: no curvature below the closed gap.'
        );

        // The ceiling denominator floors at 0.005, so the gap may exceed capacity without dividing by zero.
        $this->assertTrue(is_finite(MacroTransmission::calculateConvexPhillipsCurve(0.08)), 'Reaching capacity must not divide by zero.');
        $this->assertTrue(is_finite(MacroTransmission::calculateConvexPhillipsCurve(0.20)), 'Overheating past capacity must stay finite.');

        // A stiffer rigidity factor deepens disinflation; it has no effect above the closed gap.
        $this->assertLessThan(
            MacroTransmission::calculateConvexPhillipsCurve(-0.04, 0.08, 0.020, 0.35),
            MacroTransmission::calculateConvexPhillipsCurve(-0.04, 0.08, 0.020, 0.70),
            'A weaker rigidity factor must let prices fall further.'
        );
        $this->assertSame(
            MacroTransmission::calculateConvexPhillipsCurve(0.04, 0.08, 0.020, 0.35),
            MacroTransmission::calculateConvexPhillipsCurve(0.04, 0.08, 0.020, 0.70),
            'Downward rigidity must not touch the expansion branch.'
        );
    }

    public function testCalculateForeignDemandShiftIsTheMirrorOfTheTradeShift(): void
    {
        $this->assertEqualsWithDelta(0.06, MacroTransmission::calculateForeignDemandShift(0.03, 2.0), 1e-12);
        $this->assertEqualsWithDelta(-0.06, MacroTransmission::calculateForeignDemandShift(-0.03, 2.0), 1e-12);
        $this->assertSame(0.20, MacroTransmission::calculateForeignDemandShift(0.50, 2.0), 'Bounded like the trade shift.');
        $this->assertSame(0.0, MacroTransmission::calculateForeignDemandShift(0.0), 'A bloc at trend asks for nothing extra.');
    }

    /** A sector's demand drift is the annual log change in its GDP share; a share that does not move adds nothing. */
    public function testGdpShareDriftIsTheAnnualLogChangeInTheShare(): void
    {
        self::assertEqualsWithDelta(log(2.0) / 10.0, MacroTransmission::gdpShareDrift(0.01, 0.02, 10.0), 1e-12);
        self::assertSame(0.0, MacroTransmission::gdpShareDrift(0.03, 0.03, 22.0));
        self::assertLessThan(0.0, MacroTransmission::gdpShareDrift(0.03, 0.02, 22.0));
        self::assertSame(0.0, MacroTransmission::gdpShareDrift(0.0, 0.02, 22.0));
    }
}

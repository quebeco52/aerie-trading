<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use App\Tests\Support\MacroStateBuilder;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Domain-specific Financial and Macroeconomic Invariant & Property Tests.
 *
 * Enforces architectural rule from .agents/AGENTS.md:
 * "No Invented Math: Do not write custom mathematical formulas, approximations, or 'game-like' logic.
 *  Only use real world financial models/formulas and centralize them in MathUtility."
 */
class FinancialInvariantTest extends TestCase
{
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
    }

    // --- Benigno-Eggertsson (2023) Convex Phillips Curve Invariants ---

    public function testConvexPhillipsCurveStrictMonotonicityAcrossEntireDomain(): void
    {
        $kappa = MacroEngine::PHILLIPS_CONVEX_KAPPA;
        $yMax = MacroEngine::PHILLIPS_MAX_CAPACITY;
        $rigidity = MacroEngine::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR;

        $previousPressure = -100.0;

        // Sweep output gap from deep contraction (-10%) to severe expansion (+7.5%)
        for ($gap = -0.10; $gap <= 0.075; $gap += 0.005) {
            $pressure = $this->math->calculateConvexPhillipsCurve($gap, $yMax, $kappa, $rigidity);

            $this->assertFalse(is_nan($pressure), "Phillips curve produced NaN at gap={$gap}");
            $this->assertFalse(is_infinite($pressure), "Phillips curve produced INF at gap={$gap}");
            $this->assertGreaterThanOrEqual(
                $previousPressure,
                $pressure,
                "Phillips curve monotonicity violated at gap={$gap}: {$pressure} < {$previousPressure}"
            );

            $previousPressure = $pressure;
        }
    }

    public function testConvexPhillipsCurveAcceleratingCurvatureInExpansion(): void
    {
        $kappa = MacroEngine::PHILLIPS_CONVEX_KAPPA;
        $yMax = MacroEngine::PHILLIPS_MAX_CAPACITY;
        $rigidity = MacroEngine::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR;

        // Compare incremental slopes: (f(y2)-f(y1)) vs (f(y3)-f(y2))
        $p1 = $this->math->calculateConvexPhillipsCurve(0.01, $yMax, $kappa, $rigidity);
        $p2 = $this->math->calculateConvexPhillipsCurve(0.03, $yMax, $kappa, $rigidity);
        $p3 = $this->math->calculateConvexPhillipsCurve(0.05, $yMax, $kappa, $rigidity);
        $p4 = $this->math->calculateConvexPhillipsCurve(0.07, $yMax, $kappa, $rigidity);

        $slope1 = ($p2 - $p1) / 0.02;
        $slope2 = ($p3 - $p2) / 0.02;
        $slope3 = ($p4 - $p3) / 0.02;

        $this->assertGreaterThan($slope1, $slope2, 'Phillips curve slope must accelerate between 1-3% and 3-5% gap');
        $this->assertGreaterThan($slope2, $slope3, 'Phillips curve slope must accelerate non-linearly near capacity');
    }

    public function testDownwardRigidityPreventsDeflationaryCollapse(): void
    {
        $kappa = MacroEngine::PHILLIPS_CONVEX_KAPPA;
        $yMax = MacroEngine::PHILLIPS_MAX_CAPACITY;
        $rigidity = MacroEngine::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR;

        $deepRecessionPressure = $this->math->calculateConvexPhillipsCurve(-0.08, $yMax, $kappa, $rigidity);
        $depressionPressure = $this->math->calculateConvexPhillipsCurve(-0.15, $yMax, $kappa, $rigidity);

        // Even in a -15% depression, downward wage rigidity prevents deflation pressure from collapsing unbounded
        $this->assertGreaterThan(-0.05, $deepRecessionPressure, 'Downward rigidity must bound deflationary pressure');
        $this->assertGreaterThan(-0.08, $depressionPressure, 'Downward rigidity must prevent hyper-deflation');
    }

    // --- Jarrow-Lando-Turnbull (1997) Dual-Tranche Credit Spread Invariants ---

    public function testJltCreditSpreadRatingCliffAndMonotonicity(): void
    {
        $subsystem = new CreditFiscalSubsystem($this->math);

        // Test across 4 distinct regimes: Normal, Expansion, Recession, Crisis
        $regimes = [
            'Expansion' => MacroStateBuilder::create()->asExpansion()->build(),
            'Normal' => MacroStateBuilder::create()->build(),
            'Recession' => MacroStateBuilder::create()->asRecession()->build(),
            'CreditCrisis' => MacroStateBuilder::create()->asCreditCrisis()->build(),
        ];

        foreach ($regimes as $name => $state) {
            $subsystem->calculateMacroCreditSpread($state);

            $igSpread = $state->macroCreditSpread;
            $hySpread = $state->highYieldCreditSpread;

            // Invariant 1: Spreads are strictly non-negative
            $this->assertGreaterThan(0.0, $igSpread, "IG spread must be positive in {$name}");
            $this->assertGreaterThan(0.0, $hySpread, "HY spread must be positive in {$name}");

            // Invariant 2: High Yield spread must always exceed Investment Grade spread
            $this->assertGreaterThan(
                $igSpread,
                $hySpread,
                "HY spread must exceed IG spread in {$name}: HY={$hySpread}, IG={$igSpread}"
            );

            // Invariant 3: High Yield / IG spread ratio must be at least 2.0x (credit cliff)
            $ratio = $hySpread / $igSpread;
            $this->assertGreaterThanOrEqual(
                2.0,
                $ratio,
                "Fallen Angel cliff multiplier must maintain >= 2.0x spread ratio in {$name}"
            );
        }
    }

    public function testCreditSpreadOrderingAcrossMacroCycles(): void
    {
        $subsystem = new CreditFiscalSubsystem($this->math);

        $expansionState = MacroStateBuilder::create()->asExpansion()->build();
        $recessionState = MacroStateBuilder::create()->asRecession()->build();
        $crisisState = MacroStateBuilder::create()->asCreditCrisis()->build();

        $subsystem->calculateMacroCreditSpread($expansionState);
        $subsystem->calculateMacroCreditSpread($recessionState);
        $subsystem->calculateMacroCreditSpread($crisisState);

        // Recession spreads must exceed expansion spreads
        $this->assertGreaterThan(
            $expansionState->macroCreditSpread,
            $recessionState->macroCreditSpread,
            'Recession IG spread must exceed Expansion IG spread'
        );
        $this->assertGreaterThan(
            $expansionState->highYieldCreditSpread,
            $recessionState->highYieldCreditSpread,
            'Recession HY spread must exceed Expansion HY spread'
        );

        // Crisis spreads must blow out beyond recession spreads
        $this->assertGreaterThan(
            $recessionState->highYieldCreditSpread,
            $crisisState->highYieldCreditSpread,
            'Crisis HY spread must exceed Recession HY spread'
        );
    }

    // --- Flexible Average Inflation Targeting (FAIT - Powell 2020) Invariants ---

    public function testFaitPolicyRateZeroLowerBoundAndConvergence(): void
    {
        $monetarySubsystem = new MonetaryPolicySubsystem($this->math);

        // Severe deflationary shock: inflation = -2%, output gap = -8%
        $state = MacroStateBuilder::create()
            ->withInflation(-0.02)
            ->withOutputGap(-0.08)
            ->withPolicyRate(0.005)
            ->build();

        $state->cumulativeInflationGap = -0.05;

        // Run monetary policy update
        $targetRate = $monetarySubsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $newRate = $monetarySubsystem->updatePolicyRate($state, $targetRate, 0.25);

        // Invariant: Policy rate must never fall below zero lower bound
        $this->assertGreaterThanOrEqual(
            MacroEngine::EFFECTIVE_LOWER_BOUND,
            $newRate,
            'Policy rate must respect the Effective Lower Bound'
        );
        $this->assertFalse(is_nan($newRate), 'Policy rate must not be NaN');
    }

    public function testFaitCumulativeHistoryAnchor(): void
    {
        $monetarySubsystem = new MonetaryPolicySubsystem($this->math);

        // Two identical current inflation states (3.0%), but one with prior undershoot history
        $stateNoHistory = MacroStateBuilder::create()
            ->withInflation(0.03)
            ->withOutputGap(0.01)
            ->withPolicyRate(0.035)
            ->build();
        $stateNoHistory->cumulativeInflationGap = 0.0;

        $stateWithUndershoot = MacroStateBuilder::create()
            ->withInflation(0.03)
            ->withOutputGap(0.01)
            ->withPolicyRate(0.035)
            ->build();
        $stateWithUndershoot->cumulativeInflationGap = -0.04; // Past cumulative undershoot

        $targetNoHistory = $monetarySubsystem->calculateTargetRate($stateNoHistory, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $targetWithUndershoot = $monetarySubsystem->calculateTargetRate($stateWithUndershoot, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Under FAIT, past undershoot allows central bank to be more patient (lower target rate)
        $this->assertGreaterThan(
            $targetWithUndershoot,
            $targetNoHistory,
            'FAIT past undershoot must result in more accommodative policy target rate'
        );
    }

    // --- Metzler (1941) Inventory Cycle Invariants ---

    public function testMetzlerInventoryOverhangDissipationAndOutputGapImpact(): void
    {
        $deterministicMath = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $aggregateSubsystem = new MacroAggregateSubsystem($deterministicMath);

        $stateClean = MacroStateBuilder::create()
            ->withOutputGap(0.0)
            ->withInventoryGap(0.0)
            ->build();

        $stateOverhang = MacroStateBuilder::create()
            ->withOutputGap(0.0)
            ->withInventoryGap(0.05) // Significant 5% inventory overhang
            ->build();

        $gapClean = $aggregateSubsystem->calculateOutputGap($stateClean, 0.035, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
        $gapOverhang = $aggregateSubsystem->calculateOutputGap($stateOverhang, 0.035, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);

        // Invariant: Inventory overhang acts as an explicit drag on production ($gapOverhang < $gapClean)
        $this->assertLessThan(
            $gapClean,
            $gapOverhang,
            "Inventory overhang must drag output gap lower during liquidation ({$gapOverhang} < {$gapClean})"
        );
    }

    // --- Working (1949) Commodity Convenience Yield Invariants ---

    public function testWorkingCommodityConvenienceYieldRealAndBounded(): void
    {
        // Test convenience yield across physical inventory levels:
        // From 140 (contango glut) down to 52 (critical backwardation buffer scarcity)
        $inventoryLevels = [140.0, 120.0, 100.0, 90.0, 80.0, 70.0, 60.0, 52.0];
        $previousYield = -1.0;

        foreach ($inventoryLevels as $level) {
            $convenienceYield = $this->math->calculateConvenienceYield($level, 50.0, 0.10, 1.8);

            $this->assertFalse(is_nan($convenienceYield), "Convenience yield was NaN at level={$level}");
            $this->assertGreaterThanOrEqual(0.0, $convenienceYield, "Convenience yield must be non-negative at level={$level}");

            if ($level >= 100.0) {
                $this->assertEquals(0.0, $convenienceYield, "Contango regime must produce 0% convenience yield at level={$level}");
            } else {
                // Inward backwardation: decreasing inventory must strictly increase convenience yield
                $this->assertGreaterThan(
                    $previousYield,
                    $convenienceYield,
                    "Convenience yield must strictly increase as inventory depletes ({$convenienceYield} > {$previousYield} at level={$level})"
                );
            }

            $previousYield = $convenienceYield;
        }
    }

    // --- Corporate Balance Sheet Invariants ---

    public function testCorporateBalanceSheetIdentityPreserved(): void
    {
        $stock = StockBuilder::create('CORP', 'Enterprise Corp')
            ->withCash(10_000_000.0)
            ->withTotalDebt(20_000_000.0)
            ->withTotalEquity(80_000_000.0)
            ->build();

        $cash = (float) $stock->getCorporateTreasury();
        $debt = (float) $stock->getTotalDebt();
        $equity = (float) $stock->getTotalEquity();

        // Assets = Liabilities + Equity identity check
        // Total invested assets = Total Debt + Total Equity
        $totalCapital = $debt + $equity;
        $this->assertEquals(100_000_000.0, $totalCapital);
        $this->assertGreaterThan(0.0, $cash);
        $this->assertGreaterThan(0.0, $equity);
    }

    // --- Nelson-Siegel-Svensson Yield Curve Term Structure Invariants ---

    public function testNelsonSiegelYieldCurveInversionAndSteepeningAcrossRegimes(): void
    {
        $monetary = new MonetaryPolicySubsystem($this->math);

        // Regime 1: Early Expansion / Easing (Steep Normal Curve: 2Y < 10Y < 30Y)
        $easingState = MacroStateBuilder::create()
            ->withPolicyRate(0.015)
            ->withOutputGap(0.01)
            ->withInflation(0.02)
            ->build();
        $easingState->targetRate = 0.020;
        $easingCurve = $monetary->calculateYieldCurveAndQE($easingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        $this->assertGreaterThan(0.0, $easingCurve['yield_2y']);
        $this->assertGreaterThan($easingCurve['yield_2y'], $easingCurve['yield_10y'], 'Normal expansion must exhibit an upward-sloping yield curve (10Y > 2Y)');
        $this->assertGreaterThan($easingCurve['yield_10y'], $easingCurve['yield_30y'], 'Normal expansion must have positive term slope at the long end (30Y > 10Y)');

        // Regime 2: Late-Cycle Inflation Tightening / Inverted Curve (2Y > 10Y)
        $hikingState = MacroStateBuilder::create()
            ->withPolicyRate(0.055)
            ->withOutputGap(0.03)
            ->withInflation(0.060)
            ->build();
        $hikingState->targetRate = 0.065;
        $hikingCurve = $monetary->calculateYieldCurveAndQE($hikingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // When short rates are driven to 5.5% while long-term expectations are anchored, the curve inverts
        $this->assertGreaterThan(0.0, $hikingCurve['yield_10y']);
        $this->assertGreaterThan(0.0, $hikingCurve['yield_2y']);
        $this->assertGreaterThan(
            $hikingCurve['yield_10y'],
            $hikingCurve['yield_2y'],
            'Late-cycle restrictive policy tightening must invert the 2s10s yield curve (2Y > 10Y)'
        );
    }

    public function testEvansRulePreservesContinuousShadowRateWhileHoldingPolicyRate(): void
    {
        $monetary = new MonetaryPolicySubsystem($this->math);

        $zlbState = MacroStateBuilder::create()
            ->withPolicyRate(0.00) // At ZLB
            ->withOutputGap(-0.01)
            ->withInflation(0.019) // Below Evans ceiling 2.5%
            ->build();
        $zlbState->unemploymentRateEma = 0.065; // Elevated above 5.0%

        $shadowRate = $monetary->calculateTargetRate($zlbState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $newPolicyRate = $monetary->updatePolicyRate($zlbState, $shadowRate, 0.25);

        // Invariant 1: Wu-Xia shadow rate must remain continuous and positive (not flatlined to 0.00)
        $this->assertGreaterThan(0.0, $shadowRate, 'Wu-Xia shadow rate must calculate continuously without artificial zero clamping.');
        // Invariant 2: Policy rate under Evans Rule forward guidance must hold at ZLB (0.00%)
        $this->assertEquals(0.00, $newPolicyRate, 'Evans Rule must lock actual policy rate at lower bound during elevated unemployment.');
    }

    public function testOutputGapBoundedWithinRealisticHistoricalBounds(): void
    {
        $deterministicMath = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $aggregateSubsystem = new MacroAggregateSubsystem($deterministicMath);

        $state = MacroStateBuilder::create()
            ->withOutputGap(0.015)
            ->withInflation(0.025)
            ->withPolicyRate(0.035)
            ->build();
        $state->energyPriceIndex = 180.0; // Significant +80% energy price shock
        $state->energyPriceShock = 80.0;

        $minGap = 1.0;
        $maxGap = -1.0;

        for ($quarter = 0; $quarter < 40; $quarter++) {
            $newGap = $aggregateSubsystem->calculateOutputGap($state, 0.040, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
            $state->outputGap = $newGap;
            $state->outputGapEma += 0.25 * ($newGap - $state->outputGapEma);

            $minGap = min($minGap, $newGap);
            $maxGap = max($maxGap, $newGap);
        }

        // Invariant: Output gap must remain within realistic historical business cycle bounds [-4.0%, +3.5%]
        $this->assertGreaterThan(-0.040, $minGap, 'Output gap must not collapse into double-digit depression even under energy shocks.');
        $this->assertLessThan(0.035, $maxGap, 'Output gap must remain bounded by cubic capacity in expansions.');
    }

    // --- Diamond-Mortensen-Pissarides (DMP) Beveridge Curve Invariants ---

    public function testBeveridgeCurveHyperbolicSlackAndWagePhillipsTransmission(): void
    {
        $labor = new LaborMarketSubsystem();

        $tightState = new MacroState();
        $tightState->unemploymentRate = 0.035; // Tight labor market (3.5%)
        $tightState->wageGrowth = 0.035;

        $slackState = new MacroState();
        $slackState->unemploymentRate = 0.075; // Slack labor market (7.5%)
        $slackState->wageGrowth = 0.035;

        $labor->calculateLaborMarketAndWages($tightState, 0.015, 0.25);
        $labor->calculateLaborMarketAndWages($slackState, 0.015, 0.25);

        // Invariant 1: Beveridge curve inverse slope (dv/du < 0)
        $this->assertGreaterThan(
            $slackState->jobVacanciesRate,
            $tightState->jobVacanciesRate,
            'Job vacancies must be strictly higher when unemployment is low along the Beveridge curve'
        );

        // Invariant 2: Labor market tightness theta = V / U must be significantly higher in tight market
        $this->assertGreaterThan(
            2.0 * $slackState->laborTightness,
            $tightState->laborTightness,
            'Labor tightness must expand significantly during low unemployment'
        );

        // Invariant 3: Wage Phillips curve transmission
        $this->assertGreaterThan(
            $slackState->wageGrowth,
            $tightState->wageGrowth,
            'Tight labor markets must exert higher nominal wage growth pressure'
        );
    }

    public function testFlightToSafetyCompressesSovereignTermPremiumDuringFinancialCrisis(): void
    {
        $monetary = new MonetaryPolicySubsystem($this->math);

        // Calm market state (vol = 15%)
        $calmState = MacroStateBuilder::create()
            ->withPolicyRate(0.025)
            ->withOutputGap(0.01)
            ->withInflation(0.020)
            ->build();
        $calmState->marketVolatilityEma = 0.15;

        // Financial crisis state with identical macro fundamentals but high equity panic (vol = 45%)
        $crisisState = clone $calmState;
        $crisisState->marketVolatilityEma = 0.45;

        $calmCurve = $monetary->calculateYieldCurveAndQE($calmState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $crisisCurve = $monetary->calculateYieldCurveAndQE($crisisState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Invariant: Safe-haven demand during equity panic must compress sovereign term premium (Campbell et al. 2020)
        $this->assertLessThan(
            $calmCurve['term_premium_10y'],
            $crisisCurve['term_premium_10y'],
            'Financial market volatility spike must compress sovereign term premium via safe-haven flight-to-safety'
        );
    }

    public function testAcmRiskNeutralDecompositionPureMonetaryPathAndElbFloor(): void
    {
        $monetary = new MonetaryPolicySubsystem($this->math);

        // Severe deflation / depression state
        $stressState = MacroStateBuilder::create()
            ->withPolicyRate(MacroEngine::EFFECTIVE_LOWER_BOUND)
            ->withOutputGap(-0.06)
            ->withInflation(-0.02)
            ->build();
        $stressState->targetRate = -0.05;

        $curve = $monetary->calculateYieldCurveAndQE($stressState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Invariant 1: ACM identity holds exactly (10Y Yield = Risk-Neutral 10Y + 10Y Term Premium)
        $this->assertEqualsWithDelta(
            $curve['yield_10y'],
            $curve['risk_neutral_10y'] + $curve['term_premium_10y'],
            0.0001,
            'ACM decomposition must preserve y_10y = RN_10y + TP_10y identity'
        );

        // Invariant 2: All nominal tenors must strictly respect the Effective Lower Bound floor
        $this->assertGreaterThanOrEqual(MacroEngine::EFFECTIVE_LOWER_BOUND, $curve['yield_2y'], '2Y yield must respect ELB');
        $this->assertGreaterThanOrEqual(MacroEngine::EFFECTIVE_LOWER_BOUND, $curve['yield_5y'], '5Y yield must respect ELB');
        $this->assertGreaterThanOrEqual(MacroEngine::EFFECTIVE_LOWER_BOUND, $curve['yield_10y'], '10Y yield must respect ELB');
        $this->assertGreaterThanOrEqual(MacroEngine::EFFECTIVE_LOWER_BOUND, $curve['yield_30y'], '30Y yield must respect ELB');
    }

    public function testSupplyShocksAreSymmetricProvidingTailwindsWhenBelowBaseline(): void
    {
        $deterministicMath = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
        };
        $aggregate = new MacroAggregateSubsystem($deterministicMath);

        $stateNeutral = MacroStateBuilder::create()
            ->withOutputGap(0.0)
            ->withPolicyRate(0.035)
            ->withInflation(0.02)
            ->build();
        $stateNeutral->energyPriceIndexEma = MacroEngine::ENERGY_BASELINE;
        $stateNeutral->freightRateIndexEma = MacroEngine::FREIGHT_BASELINE;

        $stateCheapEnergy = clone $stateNeutral;
        $stateCheapEnergy->energyPriceIndexEma = 75.0; // 25% energy cost relief / dividend

        $stateExpensiveEnergy = clone $stateNeutral;
        $stateExpensiveEnergy->energyPriceIndexEma = 125.0; // 25% energy cost spike

        $gapNeutral = $aggregate->calculateOutputGap($stateNeutral, 0.040, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
        $gapDividend = $aggregate->calculateOutputGap($stateCheapEnergy, 0.040, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
        $gapDrag = $aggregate->calculateOutputGap($stateExpensiveEnergy, 0.040, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);

        // Invariant: Cheap energy must act as a positive supply dividend ($gapDividend > $gapNeutral)
        $this->assertGreaterThan(
            $gapNeutral,
            $gapDividend,
            'Below-baseline energy prices must act as an aggregate supply tailwind'
        );

        // Invariant: Expensive energy must act as a supply drag ($gapDrag < $gapNeutral)
        $this->assertLessThan(
            $gapNeutral,
            $gapDrag,
            'Above-baseline energy prices must act as an aggregate supply drag'
        );
    }

    public function testMonetaryEasingOverpowersCreditFrictionDuringRecession(): void
    {
        $deterministicMath = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
        };
        $aggregate = new MacroAggregateSubsystem($deterministicMath);

        // Recession state with blown-out credit spreads (450 bps IG, 60 bps interbank)
        $stateTightMoney = MacroStateBuilder::create()
            ->withOutputGap(-0.025)
            ->withInflation(0.015)
            ->withPolicyRate(0.045) // Central bank hasn't cut yet
            ->build();
        $stateTightMoney->macroCreditSpreadEma = 0.045;
        $stateTightMoney->interbankLiquiditySpreadEma = 0.006;
        $stateTightMoney->energyPriceIndexEma = MacroEngine::ENERGY_BASELINE;
        $stateTightMoney->freightRateIndexEma = MacroEngine::FREIGHT_BASELINE;

        // Easing state: central bank cuts policy rate from 4.5% to 1.5%
        $stateEasedMoney = clone $stateTightMoney;
        $stateEasedMoney->policyRate = 0.015;

        $gapTight = $aggregate->calculateOutputGap($stateTightMoney, 0.045, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
        $gapEased = $aggregate->calculateOutputGap($stateEasedMoney, 0.025, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);

        // Invariant: Monetary policy easing must produce a significant positive aggregate demand boost
        $this->assertGreaterThan(
            $gapTight,
            $gapEased,
            'Central bank rate cuts during a downturn must stimulate output gap drift despite credit spreads'
        );

        // Easing boost must be at least +50 bps annualized (+0.00125 per quarter)
        $this->assertGreaterThanOrEqual(
            0.00125,
            $gapEased - $gapTight,
            '300 bps rate cut must deliver at least 50 bps annualized output gap recovery boost'
        );
    }

    public function testFinancialConditionsIndexEmpiricalBalanceAcrossRegimes(): void
    {
        $assetSubsystem = new AssetMarketSubsystem($this->math);

        // Expansion / Normal regime: moderate spreads, normal slope, low vol
        $stateNormal = new MacroState();
        $stateNormal->macroCreditSpreadEma = 0.010; // 100 bps, an expansion-tight IG spread against the 130 bps through-the-cycle baseline
        $stateNormal->equityRiskPremium = 0.042;
        $stateNormal->marketVolatilityEma = 0.14;
        $stateNormal->nsSlopeEma = 0.012; // Normal upward slope (+120 bps)
        $stateNormal->exchangeRateIndexEma = 100.0;

        $assetSubsystem->calculateFinancialConditionsIndex($stateNormal, 0.25);

        // Invariant 1: Normal expansion conditions must be accommodative (negative Z-score, green)
        $this->assertLessThan(
            0.0,
            $stateNormal->financialConditionsIndex,
            'Normal expansion conditions must exhibit negative (accommodative) FCI'
        );

        // Recession / Crisis regime: wide spreads, flat/inverted slope, high vol
        $stateCrisis = new MacroState();
        $stateCrisis->macroCreditSpreadEma = 0.045; // 450 bps
        $stateCrisis->equityRiskPremium = 0.080;
        $stateCrisis->marketVolatilityEma = 0.32;
        $stateCrisis->nsSlopeEma = -0.010; // Inverted curve
        $stateCrisis->exchangeRateIndexEma = 110.0;

        $assetSubsystem->calculateFinancialConditionsIndex($stateCrisis, 0.25);

        // Invariant 2: Crisis conditions must be restrictive (positive Z-score, red)
        $this->assertGreaterThan(
            0.50,
            $stateCrisis->financialConditionsIndex,
            'Financial crisis must produce strongly positive (restrictive) FCI'
        );
    }

    public function testTaylorRulePeakPolicyRateBoundedDuringExpansion(): void
    {
        $monetary = new MonetaryPolicySubsystem($this->math);

        // Economic expansion with hot output gap (2.6%) and inflation (2.8%)
        $expansionState = MacroStateBuilder::create()
            ->withOutputGap(0.026)
            ->withInflation(0.028)
            ->withPolicyRate(0.045)
            ->build();
        $expansionState->outputGapEma = 0.026;
        $expansionState->inflationEma = 0.028;
        $expansionState->tipsBreakeven = 0.027;

        $target = $monetary->calculateTargetRate($expansionState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Invariant: Taylor rule anchored by structural r* (1.5%) must cap peak target at or below 6.0% (previously 6.66%)
        $this->assertLessThanOrEqual(
            0.060,
            $target,
            sprintf('Taylor target rate must not overshoot to punitive >6.0%% during standard expansion (got %0.2f%%)', $target * 100)
        );
        $this->assertGreaterThanOrEqual(
            0.045,
            $target,
            'Taylor target rate must remain restrictive above 4.5% during hot expansion'
        );
    }

    public function testSovereignYieldCurveReflectsExpectedInflationInLevelFactor(): void
    {
        $monetary = new MonetaryPolicySubsystem($this->math);

        // Low inflation expectations state (TIPS breakeven = 2.0%)
        $stateLowExp = MacroStateBuilder::create()
            ->withOutputGap(0.015)
            ->withInflation(0.020)
            ->withPolicyRate(0.035)
            ->build();
        $stateLowExp->tipsBreakeven = 0.020;

        // Elevated inflation expectations state (TIPS breakeven = 2.8%)
        $stateHighExp = clone $stateLowExp;
        $stateHighExp->tipsBreakeven = 0.028;

        $curveLow = $monetary->calculateYieldCurveAndQE($stateLowExp, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $curveHigh = $monetary->calculateYieldCurveAndQE($stateHighExp, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Invariant: Higher forward-looking inflation expectations must lift Nelson-Siegel level beta0
        $this->assertGreaterThan(
            $curveLow['level'],
            $curveHigh['level'],
            'Elevated TIPS breakeven must lift long-term yield level beta0'
        );

        // 10Y sovereign yield must reflect higher inflation regime
        $this->assertGreaterThan(
            $curveLow['yield_10y'],
            $curveHigh['yield_10y'],
            '10Y sovereign yield must adjust upward when forward inflation expectations rise'
        );
    }

    // --- Empirical Business Cycle & Yield Curve Transmission Invariants ---

    public function test2s10sYieldCurveSpreadSteepensSignificantlyDuringMonetaryEasingAndInvertsAtTighteningPeak(): void
    {
        $monetary = new MonetaryPolicySubsystem($this->math);

        // 1. Neutral macroeconomic state (policy rate = 3.5%, inflation = 2.0%, output gap = 0%)
        $neutralState = MacroStateBuilder::create()
            ->withOutputGap(0.0)
            ->withInflation(MacroEngine::TARGET_INFLATION)
            ->withPolicyRate(MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION)
            ->build();
        $neutralState->tipsBreakeven = MacroEngine::TARGET_INFLATION;

        $curveNeutral = $monetary->calculateYieldCurveAndQE($neutralState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $spreadNeutral = $curveNeutral['yield_10y'] - $curveNeutral['yield_2y'];

        // Invariant 1: In neutral equilibrium, 2s10s spread must be positive and healthy (>= +35 bps)
        $this->assertGreaterThanOrEqual(
            0.0035,
            $spreadNeutral,
            sprintf('Neutral 2s10s spread must be at least +35 bps (got %0.2f bps)', $spreadNeutral * 10000)
        );

        // 2. Monetary easing / recession state (policy rate = 1.0%, target rate = 0.5%, output gap = -2.5%)
        $easingState = MacroStateBuilder::create()
            ->withOutputGap(-0.025)
            ->withInflation(0.015)
            ->withPolicyRate(0.010)
            ->build();
        $easingState->targetRate = 0.005;
        $easingState->tipsBreakeven = 0.018;

        $curveEasing = $monetary->calculateYieldCurveAndQE($easingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $spreadEasing = $curveEasing['yield_10y'] - $curveEasing['yield_2y'];

        // Invariant 2: In monetary easing cycles, the yield curve must bull-steepen significantly (>= +100 bps)
        $this->assertGreaterThanOrEqual(
            0.0100,
            $spreadEasing,
            sprintf('2s10s yield curve spread must bull-steepen to at least +100 bps during monetary easing (got %0.2f bps)', $spreadEasing * 10000)
        );

        // 3. Peak tightening state (policy rate = 5.25%, target rate = 5.25%, inflation = 3.5%, output gap = +2.5%)
        $peakState = MacroStateBuilder::create()
            ->withOutputGap(0.025)
            ->withInflation(0.035)
            ->withPolicyRate(0.0525)
            ->build();
        $peakState->targetRate = 0.0525;
        $peakState->tipsBreakeven = 0.026;

        $curvePeak = $monetary->calculateYieldCurveAndQE($peakState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $spreadPeak = $curvePeak['yield_10y'] - $curvePeak['yield_2y'];

        // Invariant 3: At the peak of monetary policy tightening, 2s10s spread must invert (< 0.0)
        $this->assertLessThan(
            0.0,
            $spreadPeak,
            sprintf('2s10s yield curve spread must invert at peak monetary tightening (got %0.2f bps)', $spreadPeak * 10000)
        );
    }

    public function testRecessionOutputGapReachesRealisticTroughDepth(): void
    {
        $deterministicMath = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $aggregate = new MacroAggregateSubsystem($deterministicMath);

        // Contractionary onset: restrictive monetary policy (5.0%), inventory overhang (3%), starting negative gap (-0.5%)
        $state = MacroStateBuilder::create()
            ->withOutputGap(-0.005)
            ->withInflation(0.020)
            ->withPolicyRate(0.050)
            ->withInventoryGap(0.030)
            ->build();
        $state->outputGapEma = -0.005;

        $minGap = 0.0;
        for ($quarter = 0; $quarter < 12; $quarter++) {
            $newGap = $aggregate->calculateOutputGap($state, 0.045, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
            $state->outputGap = $newGap;
            $state->outputGapEma += 0.25 * ($newGap - $state->outputGapEma);
            $minGap = min($minGap, $newGap);
        }

        // Invariant 1: Contraction must develop realistic empirical depth past the previous -0.8% floor (<= -1.8%)
        $this->assertLessThanOrEqual(
            -0.018,
            $minGap,
            sprintf('Recession output gap must reach genuine trough depth <= -1.8%% (got %0.2f%%)', $minGap * 100)
        );

        // Invariant 2: Contraction must not collapse into double-digit depression (> -4.0%)
        $this->assertGreaterThan(
            -0.040,
            $minGap,
            sprintf('Recession output gap must remain bounded above depression threshold -4.0%% (got %0.2f%%)', $minGap * 100)
        );
    }
}


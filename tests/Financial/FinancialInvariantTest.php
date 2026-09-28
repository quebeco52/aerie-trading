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
        $kappa = MacroAggregateSubsystem::PHILLIPS_CONVEX_KAPPA;
        $yMax = MacroAggregateSubsystem::PHILLIPS_MAX_CAPACITY;
        $rigidity = MacroAggregateSubsystem::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR;

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
        $kappa = MacroAggregateSubsystem::PHILLIPS_CONVEX_KAPPA;
        $yMax = MacroAggregateSubsystem::PHILLIPS_MAX_CAPACITY;
        $rigidity = MacroAggregateSubsystem::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR;

        // Compare incremental slopes on the approach to the ceiling: (f(y2)-f(y1)) vs (f(y3)-f(y2)). The probes
        // are placed relative to the ceiling because beyond it the formula's floor makes the curve linear.
        $step = 0.2 * $yMax;
        $p1 = $this->math->calculateConvexPhillipsCurve(0.2 * $yMax, $yMax, $kappa, $rigidity);
        $p2 = $this->math->calculateConvexPhillipsCurve(0.4 * $yMax, $yMax, $kappa, $rigidity);
        $p3 = $this->math->calculateConvexPhillipsCurve(0.6 * $yMax, $yMax, $kappa, $rigidity);
        $p4 = $this->math->calculateConvexPhillipsCurve(0.8 * $yMax, $yMax, $kappa, $rigidity);

        $slope1 = ($p2 - $p1) / $step;
        $slope2 = ($p3 - $p2) / $step;
        $slope3 = ($p4 - $p3) / $step;

        $this->assertGreaterThan($slope1, $slope2, 'Phillips curve slope must accelerate as the gap approaches capacity');
        $this->assertGreaterThan($slope2, $slope3, 'Phillips curve slope must accelerate non-linearly near capacity');
    }

    public function testDownwardRigidityPreventsDeflationaryCollapse(): void
    {
        $kappa = MacroAggregateSubsystem::PHILLIPS_CONVEX_KAPPA;
        $yMax = MacroAggregateSubsystem::PHILLIPS_MAX_CAPACITY;
        $rigidity = MacroAggregateSubsystem::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR;

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

            // Invariant 3: High Yield / IG spread ratio must be at least 2.0x
            $ratio = $hySpread / $igSpread;
            $this->assertGreaterThanOrEqual(
                2.0,
                $ratio,
                "HY must keep at least 2.0x the IG spread in {$name}"
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

    // --- Flexible Inflation Targeting (FOMC 2025 framework) Invariants ---

    public function testPolicyRateRespectsTheLowerBoundInADeflationarySlump(): void
    {
        $monetarySubsystem = new MonetaryPolicySubsystem($this->math);

        $state = MacroStateBuilder::create()
            ->withInflation(-0.02)
            ->withOutputGap(-0.08)
            ->withPolicyRate(0.005)
            ->build();

        $targetRate = $monetarySubsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $newRate = $monetarySubsystem->updatePolicyRate($state, $targetRate, 0.25);

        $this->assertGreaterThanOrEqual(MacroEngine::EFFECTIVE_LOWER_BOUND, $newRate, 'Policy rate must respect the Effective Lower Bound');
        $this->assertFalse(is_nan($newRate), 'Policy rate must not be NaN');
    }

    /** No make-up: the target reads today's inflation and gap, not the path that led there. */
    public function testTheTargetCarriesNoMemoryOfPastInflation(): void
    {
        $monetarySubsystem = new MonetaryPolicySubsystem($this->math);

        $withHistory = MacroStateBuilder::create()->withInflation(0.005)->withOutputGap(-0.02)->withPolicyRate(0.01)->build();
        for ($quarter = 0; $quarter < 8; $quarter++) {
            $monetarySubsystem->calculateTargetRate($withHistory, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        }
        $fresh = MacroStateBuilder::create()->withInflation(0.03)->withOutputGap(0.01)->withPolicyRate(0.035)->build();
        foreach (['inflation', 'inflationEma', 'outputGap', 'outputGapEma', 'policyRate', 'policyRateEma'] as $field) {
            $withHistory->{$field} = $fresh->{$field};
        }

        $this->assertSame(
            $monetarySubsystem->calculateTargetRate($fresh, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25),
            $monetarySubsystem->calculateTargetRate($withHistory, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25),
            'Two years of undershoot buy no extra accommodation once inflation is back.'
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

        $gapClean = $aggregateSubsystem->calculateOutputGap($stateClean, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapOverhang = $aggregateSubsystem->calculateOutputGap($stateOverhang, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

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
        // No diffusion and no jump arrivals: the uniform gate sits above every per-tick jump probability.
        $deterministicMath = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            public function generateUniform(): float
            {
                return 0.5;
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
            $newGap = $aggregateSubsystem->calculateOutputGap($state, 0.040, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
            $state->outputGap = $newGap;
            $state->outputGapEma += 0.25 * ($newGap - $state->outputGapEma);

            $minGap = min($minGap, $newGap);
            $maxGap = max($maxGap, $newGap);
        }

        // The shock here is held at +80% for ten years with the policy rate frozen and no mean reversion,
        // which is a scenario the engine never produces: energy is an OU process and the stabilisers answer it.
        // At the Blanchard-Gali (2007) supply elasticity that standing shock is ~1pp a year of drag with
        // nothing pushing back, so the bound here is the arithmetic of the scenario, not a cycle bound. The
        // cycle bound is the one below, and BusinessCycleRealismTest measures the distribution the engine
        // actually reaches (energy contributes a mean +0.01pp/yr, sd 0.22, over 24 seeds x 60y).
        $this->assertGreaterThan(-0.150, $minGap, 'Even a decade-long energy shock with no policy response must stay out of a depression spiral.');
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

        $gapNeutral = $aggregate->calculateOutputGap($stateNeutral, 0.040, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapDividend = $aggregate->calculateOutputGap($stateCheapEnergy, 0.040, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapDrag = $aggregate->calculateOutputGap($stateExpensiveEnergy, 0.040, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

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

    /**
     * Tenreyro & Thwaites (2016), Barnichon & Matthes (2018): easing still lifts demand in a slump with blown-out
     * spreads, but it pushes on a string -- a 300bp cut lifts the gap by less than a 300bp hike from the same state
     * cuts it.
     */
    public function testMonetaryEasingStillStimulatesInARecessionButLessThanTighteningRestrains(): void
    {
        $deterministicMath = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }

            // The disaster gate is the only randomness left; a jump in one leg would swamp the difference.
            public function checkProbability(float $probability): bool { return false; }
        };

        $gaps = [];
        foreach (['tight' => [0.045, 0.045], 'eased' => [0.015, 0.025], 'tighter' => [0.075, 0.065]] as $name => [$policyRate, $yield5y]) {
            $aggregate = new MacroAggregateSubsystem($deterministicMath);
            // Recession state with blown-out credit spreads (450 bps IG, 60 bps interbank)
            $state = MacroStateBuilder::create()
                ->withOutputGap(-0.025)
                ->withInflation(0.015)
                ->withPolicyRate($policyRate)
                ->build();
            $state->macroCreditSpreadEma = 0.045;
            $state->interbankLiquiditySpreadEma = 0.006;
            $state->energyPriceIndexEma = MacroEngine::ENERGY_BASELINE;
            $state->freightRateIndexEma = MacroEngine::FREIGHT_BASELINE;
            $gaps[$name] = $aggregate->calculateOutputGap($state, $yield5y, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        }

        $this->assertGreaterThan($gaps['tight'], $gaps['eased'], 'Rate cuts during a downturn must still lift the output gap despite credit spreads.');
        $this->assertLessThan($gaps['tight'] - $gaps['tighter'], $gaps['eased'] - $gaps['tight'], 'A 300bp cut must lift demand by less than a 300bp hike cuts it.');
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

        // Invariant: the target sits between Taylor's (1993) prescription for this state and the long-run US rule
        // Clarida, Gali & Gertler's form estimates on 1987-2008 (inflation 1.87, gap 1.84; var/harness/policy_fit.py).
        $inflation = 0.028;
        $taylor1993 = MacroEngine::BASE_NATURAL_RATE + $inflation + (0.5 * ($inflation - MacroEngine::TARGET_INFLATION)) + (0.5 * 0.026);
        $estimatedUsRule = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + (1.87 * ($inflation - MacroEngine::TARGET_INFLATION)) + (1.84 * 0.026);
        $this->assertLessThanOrEqual(
            $estimatedUsRule,
            $target,
            sprintf('Taylor target must not lean harder than the estimated US rule (%0.2f%%, got %0.2f%%)', $estimatedUsRule * 100, $target * 100)
        );
        $this->assertGreaterThanOrEqual(
            $taylor1993,
            $target,
            sprintf('Taylor target must be at least Taylor 1993 in a hot expansion (%0.2f%%, got %0.2f%%)', $taylor1993 * 100, $target * 100)
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

    /**
     * Restrictive policy held against a contraction keeps it open, and a tighter stance keeps it deeper. The depth
     * itself is the fitted IS elasticity (drag over the gap's own reversion, about half Rudebusch-Svensson's -1 per
     * point of real rate), so the bounds here are the properties that hold at any calibration: no recovery above
     * potential while policy stays tight, monotone in the stance, and short of a depression.
     */
    public function testRestrictivePolicyHeldKeepsTheContractionOpenInProportionToTheStance(): void
    {
        $deterministicMath = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            // This scenario is restrictive policy held against a contraction, not a disaster arriving on
            // top of one. The jump gate is the only randomness left in the subsystem, so hold it shut.
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };

        $paths = [];
        foreach (['tight' => [0.050, 0.045], 'tighter' => [0.060, 0.055]] as $name => [$policyRate, $yield5y]) {
            $aggregate = new MacroAggregateSubsystem($deterministicMath);
            // Contractionary onset: restrictive policy, inventory overhang (3%), starting negative gap (-0.5%)
            $state = MacroStateBuilder::create()
                ->withOutputGap(-0.005)
                ->withInflation(0.020)
                ->withPolicyRate($policyRate)
                ->withInventoryGap(0.030)
                ->build();
            $state->outputGapEma = -0.005;
            // Credit at its average: the compensated premium and crisis drag are neutral, so only policy acts.
            $state->excessBondPremium = $aggregate->stationaryAdversePremium();
            $state->creditCrisisDrag = $aggregate->stationaryCrisisDrag();

            for ($quarter = 0; $quarter < 12; $quarter++) {
                $newGap = $aggregate->calculateOutputGap($state, $yield5y, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
                $state->outputGap = $newGap;
                $state->outputGapEma += 0.25 * ($newGap - $state->outputGapEma);
                $paths[$name][] = $newGap;
            }
        }

        // Invariant 1: while policy stays restrictive the economy does not recover above potential.
        $this->assertLessThan(0.0, max($paths['tight']), 'A contraction held under restrictive policy must stay open for three years.');

        // Invariant 2: a tighter stance holds the gap deeper in every quarter.
        foreach ($paths['tighter'] as $quarter => $gap) {
            $this->assertLessThan($paths['tight'][$quarter], $gap, "A tighter stance must hold the gap deeper (quarter {$quarter}).");
        }

        // Invariant 3: even the tighter stance, held three years, stays short of a depression and clear of the -12% clamp.
        $this->assertGreaterThan(-0.080, min($paths['tighter']), 'Held policy alone must not dig a depression.');
    }
}


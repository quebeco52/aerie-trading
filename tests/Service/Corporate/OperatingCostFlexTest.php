<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Sectors;
use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Capacity cost is committed, not chosen quarter by quarter.
 *
 * Anderson, Banker & Janakiraman (2003) measure what operating costs actually do when activity moves: they
 * follow it up at one elasticity and down at a lower one, because a firm cannot unstaff a plant the quarter
 * demand falls and does not re-staff one the quarter it returns. Before this channel existed the fixed cost
 * base was pinned to structural capacity with no restructuring path anywhere in src/, which is what
 * produced the -20% to -60% trough margins the failure audit found.
 *
 * The adjustment laws are exercised directly because they are a deterministic function of three context
 * fields; the trajectory test at the end runs the real pipeline to prove the channel is actually reached.
 */
#[AllowMockObjectsWithoutExpectations]
final class OperatingCostFlexTest extends TestCase
{
    private const AUTO_MANUFACTURER = 'auto_manufacturer';

    private function buildEngine(?MathUtility $mathUtility = null): EarningsEngine
    {
        $mathUtility ??= new MathUtility();
        $corporateMetrics = new CorporateMetrics();
        $debtEngine = new DebtEngine($mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $treasuryEngine = new TreasuryEngine($corporateMetrics, $debtEngine, $capExEngine, $mathUtility);

        $capitalAllocationEngine = new CapitalAllocationEngine(
            $this->createStub(CorporateLedgerService::class),
            $corporateMetrics,
            $debtEngine,
            $mathUtility,
            $treasuryEngine
        );

        return new EarningsEngine(
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MarketEventPublisher::class),
            $capitalAllocationEngine,
            $debtEngine,
            $capExEngine,
            $mathUtility,
            $corporateMetrics,
            $this->createStub(NarrativeEngine::class),
            new MarketConsensusEngine(),
            null
        );
    }

    private function buildMatureIndustrial(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('Auto Manufacturers');
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('50.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('50000000000');
        $stock->setWholesaleDebt('20000000000');
        $stock->setCorporateTreasury('15000000000');
        $stock->setRetainedEarnings('10000000000');
        $stock->setTotalRevenue('80000000000');
        $stock->setPreviousRevenue('80000000000');
        $stock->setOperatingMargin('0.12');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('1.00');
        $stock->setLastDividend('0.00');
        $stock->setFixedCostRatio(0.40);
        $stock->setCapexRatio('0.20');

        return $stock;
    }

    /**
     * A context carrying only what the adjustment reads: the base the firm starts the quarter with, the
     * activity it has to carry, and the seasonal swing it staffs through.
     */
    private function buildContext(float $priorScale, float $utilization, float $seasonalFactor = 1.0): EarningsSimulationContext
    {
        $stock = $this->buildMatureIndustrial('FLEX');
        $stock->setCommittedCostScale((string) $priorScale);

        $ctx = new EarningsSimulationContext(
            $stock,
            new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04),
            Sectors::getBusinessModelStrategy(self::AUTO_MANUFACTURER),
            self::AUTO_MANUFACTURER
        );
        $ctx->capacityUtilization = $utilization * $seasonalFactor;
        $ctx->seasonalFactor = $seasonalFactor;

        return $ctx;
    }

    private function resolveScale(EarningsSimulationContext $ctx): float
    {
        return (new ReflectionMethod(EarningsEngine::class, 'resolveCommittedCostScale'))
            ->invoke($this->buildEngine(), $ctx);
    }

    private function applyCharge(EarningsSimulationContext $ctx): void
    {
        (new ReflectionMethod(EarningsEngine::class, 'applyRestructuringCharge'))
            ->invoke($this->buildEngine(), $ctx);
    }

    /**
     * An investment bank's deal flow moves inside its sector physics, not through capacity utilization, which
     * it holds at one. Before the model reported that activity, an advisory drought never reached the base:
     * the bank carried the headcount of a boom through a two-year slump and lost money on it every quarter.
     */
    public function testActivityAModelMovesInsideItsOwnPhysicsReachesTheCommittedBase(): void
    {
        $scaleAfterAQuarter = function (float $outputGap, float $dealActivityIndex): float {
            $stock = $this->buildMatureIndustrial('IBX');
            $stock->setIndustry('Investment Banking');
            $stock->setCommittedCostScale('1.0');
            $ctx = new EarningsSimulationContext(
                $stock,
                MacroStateDTO::fromArray([
                    'corporate_tax_rate' => 0.21,
                    'output_gap_ema' => $outputGap,
                    'deal_activity_index_ema' => $dealActivityIndex,
                    'macro_credit_spread_ema' => \App\Service\Model\Sector\InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
                    'market_volatility_ema' => \App\Service\Model\Sector\InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
                    'policy_rate' => 0.04,
                    'policy_rate_ema' => 0.04,
                    'yield_5y_ema' => 0.04 + \App\Service\Model\Sector\InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE,
                ]),
                Sectors::getBusinessModelStrategy('investment_bank'),
                'investment_bank'
            );
            $ctx->capacityUtilization = 1.0;
            $ctx->seasonalFactor = 1.0;

            return $this->resolveScale($ctx);
        };

        $this->assertEqualsWithDelta(1.0, $scaleAfterAQuarter(0.0, \App\Service\Macro\MacroEngine::DEAL_ACTIVITY_BASELINE), 1e-6, 'Normal deal flow keeps the whole base.');
        $this->assertLessThan(0.97, $scaleAfterAQuarter(-0.045, 0.70 * \App\Service\Macro\MacroEngine::DEAL_ACTIVITY_BASELINE), 'A deal drought starts trimming the base the quarter it arrives.');
    }

    /**
     * The ABJ asymmetry itself: the same 10% swing in activity cuts the base by less than it rebuilds it.
     * Cost stickiness IS this gap — remove it and the channel collapses back into a variable cost.
     */
    public function testTheBaseFallsMoreSlowlyThanItRebuilds(): void
    {
        $cut = 1.0 - $this->resolveScale($this->buildContext(1.0, 0.90));
        $rebuild = $this->resolveScale($this->buildContext(0.90, 1.00)) - 0.90;

        $this->assertGreaterThan(0.0, $cut, 'a 10% volume shortfall must trim the committed base');
        $this->assertGreaterThan(0.0, $rebuild, 'volume returning to trend must rebuild it');
        $this->assertGreaterThan(
            $cut,
            $rebuild,
            'costs are sticky DOWNWARD: the rebuild elasticity must exceed the contraction one'
        );

        // Pinned to the coefficients rather than only their ordering, so a silent recalibration is visible.
        $contraction = FinancialConstants::STICKY_COST_BETA_EXPANSION + FinancialConstants::STICKY_COST_BETA_CONTRACTION_PENALTY;
        $this->assertEqualsWithDelta(1.0 - exp($contraction * log(0.90)), $cut, 1e-9);
        $this->assertEqualsWithDelta(
            (0.90 * exp(FinancialConstants::STICKY_COST_BETA_EXPANSION * log(1.0 / 0.90))) - 0.90,
            $rebuild,
            1e-9
        );
    }

    /**
     * A slump that persists restructures; one bad quarter only trims. The partial adjustment is what makes
     * the difference, and it is the reason a firm does not resize on the first soft quarter.
     */
    public function testAPersistentSlumpRestructuresFurtherThanASingleBadQuarter(): void
    {
        $scale = 1.0;
        $firstQuarter = null;

        for ($quarter = 1; $quarter <= 5; $quarter++) {
            $scale = $this->resolveScale($this->buildContext($scale, 0.85));
            $firstQuarter ??= $scale;
        }

        $this->assertLessThan($firstQuarter, $scale, 'each quarter of the slump cuts further into the base');
        $this->assertGreaterThan(0.85, $scale, 'and never overshoots the activity it is adjusting toward');
        $this->assertEqualsWithDelta(0.85, $scale, 0.02, 'five quarters is most of the way there');
    }

    /**
     * Running above capacity is already paid for as the convex overtime premium on the variable margin.
     * Letting the committed base climb past its structural size would charge a boom for that twice.
     */
    public function testABoomNeverLiftsTheBaseAboveItsStructuralSize(): void
    {
        $this->assertSame(1.0, $this->resolveScale($this->buildContext(1.0, 1.40)));
        $this->assertSame(1.0, $this->resolveScale($this->buildContext(0.98, 1.40)));
    }

    /** Property, insurance, minimum maintenance and core staffing survive any restructuring short of liquidation. */
    public function testRestructuringStopsAtTheIrreducibleFloor(): void
    {
        $scale = 1.0;
        for ($quarter = 1; $quarter <= 40; $quarter++) {
            $scale = $this->resolveScale($this->buildContext($scale, 0.10));
        }

        $this->assertSame(FinancialConstants::MIN_COMMITTED_COST_SCALE, $scale);
    }

    /**
     * A retailer staffs for its own December and does not restructure every January. The adjustment is
     * struck on deseasonalized activity precisely so the seasonal trough is not read as a slump.
     */
    public function testASeasonalTroughIsNotMistakenForASlump(): void
    {
        $trough = $this->buildContext(1.0, 1.0, 0.70);

        $this->assertSame(1.0, $this->resolveScale($trough));
        $this->assertSame(0.0, $trough->committedCostCut);
    }

    /**
     * ASC 420-10: one-time termination benefits are recognised in the quarter the plan is committed, on the
     * labour share of the capacity cut — exiting a lease or mothballing a line does not pay severance.
     */
    public function testCuttingTheBaseBooksSeveranceOnTheLabourShareOfWhatWasCut(): void
    {
        $ctx = $this->buildContext(1.0, 0.90);
        $this->resolveScale($ctx);

        $structuralFixedCosts = 4_000_000_000.0;
        $ctx->fixedCosts = $structuralFixedCosts * $ctx->committedCostScale;
        $ctx->ebitda = 10_000_000_000.0;
        $ctx->ebit = 8_000_000_000.0;

        $this->applyCharge($ctx);

        $expected = $ctx->committedCostCut
            * $structuralFixedCosts
            * $ctx->strategy->getLaborCostShare()
            * FinancialConstants::RESTRUCTURING_SEVERANCE_QUARTERS;

        $this->assertGreaterThan(0.0, $expected);
        $this->assertEqualsWithDelta($expected, $ctx->restructuringCharge, 1e-6);
        // Severance sits in operating expense, so it reduces EBITDA and EBIT alike.
        $this->assertEqualsWithDelta(10_000_000_000.0 - $expected, $ctx->ebitda, 1e-6);
        $this->assertEqualsWithDelta(8_000_000_000.0 - $expected, $ctx->ebit, 1e-6);
    }

    /** Holding the base, or rebuilding it, commits no termination plan and therefore books no charge. */
    public function testHoldingOrRebuildingTheBaseBooksNoCharge(): void
    {
        foreach ([[1.0, 1.0], [0.90, 1.0], [1.0, 1.40]] as [$prior, $utilization]) {
            $ctx = $this->buildContext($prior, $utilization);
            $this->resolveScale($ctx);
            $ctx->fixedCosts = 4_000_000_000.0 * $ctx->committedCostScale;
            $ctx->ebit = 8_000_000_000.0;

            $this->applyCharge($ctx);

            $this->assertSame(0.0, $ctx->restructuringCharge);
            $this->assertSame(8_000_000_000.0, $ctx->ebit);
        }
    }

    /**
     * End to end on the real pipeline, two matched runs off one seed: a firm held in a deep slump resizes
     * its committed base, and the same firm at trend does not. This is what the reflection tests above are
     * a magnifying glass on — without it they would pass on a channel nothing reaches.
     */
    public function testADeepSlumpResizesTheCostBaseOnTheRealPipeline(): void
    {
        $run = function (MacroStateDTO $macroState): array {
            mt_srand(11);
            $engine = $this->buildEngine();
            $stock = $this->buildMatureIndustrial('FLEX');
            $reportingTick = EarningsEngine::resolveReportingTick('FLEX', 252);
            $scales = [];

            for ($quarter = 1; $quarter <= 16; $quarter++) {
                $engine->calculate($stock, $macroState, (($quarter - 1) * 63) + $reportingTick, 252);
                $scales[] = (float) $stock->getCommittedCostScale();
            }

            return $scales;
        };

        $neutral = $run(new MacroStateDTO(
            outputGapEma: 0.0,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            nominalGdpIndex: 1.0,
            marketVolatilityEma: 0.15,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.0010
        ));

        $slump = $run(new MacroStateDTO(
            outputGapEma: -0.06,
            inflationEma: -0.01,
            policyRate: 0.01,
            policyRateEma: 0.01,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            nominalGdpIndex: 0.94,
            marketVolatilityEma: 0.35,
            macroCreditSpreadEma: 0.05,
            interbankLiquiditySpreadEma: 0.01
        ));

        $mean = static fn (array $series): float => array_sum($series) / count($series);

        $this->assertLessThan(
            $mean($neutral) - 0.03,
            $mean($slump),
            'a six-point output gap held for four years has to show up as a smaller committed cost base'
        );
        $this->assertGreaterThan(
            FinancialConstants::MIN_COMMITTED_COST_SCALE,
            min($slump),
            'an ordinary recession is not a liquidation: the irreducible base survives it'
        );
        $this->assertLessThanOrEqual(1.0, max($neutral), 'the structural base is still the ceiling at trend');
    }

    /**
     * Stickiness reads activity. Last quarter's actual revenue also carries its market price and one-off shocks:
     * a refiner whose revenue rose with crude, or any firm that beat its plan, has not grown its operations, so
     * neither may be read as a contraction this quarter. A genuine fall in planned activity still is.
     */
    public function testStickyCostsReadLastQuartersPlannedActivityNotItsPrice(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $engine = $this->buildEngine($quiet);
        $process = new ReflectionMethod(EarningsEngine::class, 'processVariableMargins');

        $realizedRatio = function (?float $priorExpectedRevenue, float $priorActualRevenue) use ($engine, $process): float {
            $ctx = $this->buildContext(1.0, 1.0);
            $ctx->baselineVariableMargin = 0.60;
            $ctx->baselineVol = 0.15;
            $ctx->expectedRevenue = 20_000_000_000.0;
            $ctx->previousQuarterlyRevenue = $priorActualRevenue;
            if ($priorExpectedRevenue !== null) {
                $ctx->stock->setEarningsMomentumZ([FinancialConstants::STATE_LAST_EXPECTED_REVENUE => $priorExpectedRevenue]);
            }
            $process->invoke($engine, $ctx);

            return $ctx->realizedVariableMargin;
        };

        $steady = $realizedRatio(20_000_000_000.0, 20_000_000_000.0);
        $priceRally = $realizedRatio(20_000_000_000.0, 24_000_000_000.0);
        $activityFell = $realizedRatio(24_000_000_000.0, 24_000_000_000.0);

        $this->assertEqualsWithDelta($steady, $priceRally, 1e-12, 'a quarter that out-earned its plan is not a contraction');
        $this->assertGreaterThan($steady * 1.05, $activityFell, 'a fall in planned activity still leaves the costs behind');

        // A firm reporting for the first time has no plan on record and falls back to what it last reported.
        $this->assertEqualsWithDelta($activityFell, $realizedRatio(null, 24_000_000_000.0), 1e-12);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\MarketEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The invariant that governs every phase of the market layer: a new source of price movement must draw
 * variance down from the diffusion rather than stack on top of it.
 *
 * The diffusion is a reduced-form stand-in for the order flow nobody was simulating. Once real flow moves
 * the price, the same variance is being modelled twice and every traded name simply gets more volatile —
 * the exact failure MarketEngine's systemic-jump comment records, where connecting the district jump made
 * every name in it roughly four points more volatile until the budget was applied.
 *
 * These tests run the real price process forward over tens of thousands of ticks and measure what comes
 * out. They are the reason the SVJJ parameters still mean what they were calibrated to mean.
 */
class OrderFlowVarianceBudgetTest extends TestCase
{
    private const TICKS = 40000;

    /**
     * Ticks discarded before measurement begins.
     *
     * The budget is drawn from an exponentially weighted estimate of what flow has been supplying, and that
     * estimate starts at zero. Measuring through the warm-up would score the budget on a window where it
     * did not yet have the number it needs, which says nothing about whether it works.
     */
    private const WARMUP_TICKS = 4000;

    private const TICKS_PER_YEAR = 14400;
    private const SEED = 20260910;
    private const BASELINE_VOLATILITY = 0.28;

    /** @var array<string, float> Memoized: each run is deterministic and the sweep is the slowest fixture here. */
    private static array $realized = [];

    private function stock(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('FLOW')
            ->setName('flow test')
            ->setSharesOutstanding('100000000')
            ->setPublicFloatPercentage('0.90')
            ->setVolatility((string) self::BASELINE_VOLATILITY)
            ->setCurrentVolatility((string) self::BASELINE_VOLATILITY)
            ->setPrice('100')
            ->setBeta('1.00')
            ->setJumpIntensity('2.00')
            ->setJumpVol('0.10');

        $stock->setTurnoverRatio(LiquidityEngine::structuralTurnoverRatio(self::BASELINE_VOLATILITY));
        $stock->setImpactVarianceEma(0.0);

        return $stock;
    }

    /**
     * Runs the price process forward and returns the annualized volatility it actually realized.
     *
     * The direction draw happens on every tick whether or not flow is enabled, so all three scenarios walk
     * exactly the same random path. Without that, a run with flow and a run without consume the generator
     * differently and the comparison measures the seed rather than the budget.
     *
     * @param float $flowFraction Fraction of average daily volume traded each tick, signed at random.
     * @param bool  $budget       Whether the diffusion gives back what the flow supplies.
     */
    private function realizedVolatility(float $flowFraction, bool $budget): float
    {
        $key = sprintf('%.6f|%d', $flowFraction, (int) $budget);
        if (isset(self::$realized[$key])) {
            return self::$realized[$key];
        }

        mt_srand(self::SEED);

        $math = new MathUtility();
        $engine = new MarketEngine($math);
        $liquidity = new LiquidityEngine($math);

        $stock = $this->stock();
        $macro = new MacroStateDTO(policyRate: 0.04, equityRiskPremium: 0.045);

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $phi = exp(-$dt / FinancialConstants::IMPACT_VARIANCE_EMA_YEARS);

        $price = 100.0;
        $volatility = self::BASELINE_VOLATILITY;
        $impactVarianceEma = 0.0;
        $returns = [];

        for ($tick = 0; $tick < self::TICKS; $tick++) {
            $stock->setPrice((string) $price);
            $stock->setCurrentVolatility((string) $volatility);

            $result = $engine->calculateNextPrice(new MarketPricingContext(
                currentPrice: $price,
                currentVolatility: $volatility,
                longTermVolatility: self::BASELINE_VOLATILITY,
                earningsPerShare: 5.0,
                dt: $dt,
                lambda: 2.0,
                jumpVol: 0.10,
                beta: 1.0,
                marketZ: 0.0,
                sectorZ: 0.0,
                marketJumpMultiplier: 1.0,
                marketVol: 0.15,
                macroState: $macro,
                bookValuePerShare: 40.0,
                currentRoic: 0.12,
                roicTtm: 0.12,
                liveWacc: 0.08,
                baselineIndustryPE: 15.0,
                revenuePerShare: 50.0,
                businessModel: 'none',
                liveCostOfEquity: 0.10,
                orderFlowVariance: $budget ? $impactVarianceEma : 0.0
            ));

            $nextPrice = $result['price'];
            $volatility = $result['next_volatility'];

            $sign = mt_rand(0, 1) === 1 ? 1.0 : -1.0;
            $impact = 0.0;

            if ($flowFraction > 0.0) {
                $quantity = $liquidity->averageDailyVolume($stock) * $flowFraction * $sign;
                $impact = $liquidity->permanentImpact($stock, $quantity);
                $nextPrice = max(0.01, $nextPrice * exp($impact));
            }

            $impactVarianceEma = ($impactVarianceEma * $phi) + ((($impact * $impact) / $dt) * (1.0 - $phi));

            if ($tick >= self::WARMUP_TICKS) {
                $returns[] = log($nextPrice / $price);
            }

            $price = $nextPrice;
        }

        $count = count($returns);
        $mean = array_sum($returns) / $count;

        $sumSquares = 0.0;
        foreach ($returns as $return) {
            $sumSquares += ($return - $mean) ** 2;
        }

        return self::$realized[$key] = sqrt(($sumSquares / ($count - 1)) / $dt);
    }

    /** Ticks per simulated trading day, for expressing flow as a share of daily volume. */
    private function perTickFraction(float $shareOfDailyVolume): float
    {
        return $shareOfDailyVolume / (self::TICKS_PER_YEAR / FinancialConstants::TRADING_DAYS_PER_YEAR);
    }

    /**
     * The headline claim: at any plausible level of participation, adding real order flow to the market
     * does not change how volatile it is.
     */
    public function testRealisticOrderFlowLeavesRealizedVolatilityUnchanged(): void
    {
        $baseline = $this->realizedVolatility(0.0, true);

        // Up to roughly a third of daily volume the budget is near-exact: measured drift is under four
        // basis points of annualized volatility. The tolerance is set an order of magnitude wider than
        // that, and still an order of magnitude tighter than the ~3.9 point inflation an absent budget
        // produces at the top of this range.
        foreach ([0.01, 0.05, 0.10, 0.30] as $shareOfDailyVolume) {
            $withFlow = $this->realizedVolatility($this->perTickFraction($shareOfDailyVolume), true);

            $this->assertEqualsWithDelta(
                $baseline,
                $withFlow,
                0.0075,
                sprintf(
                    'Players trading %.0f%% of daily volume moved realized volatility from %.2f%% to %.2f%%.',
                    $shareOfDailyVolume * 100,
                    $baseline * 100,
                    $withFlow * 100
                )
            );
        }
    }

    /**
     * And the counterfactual: without the budget, that same flow inflates volatility.
     *
     * Asserted so the first test cannot pass for the wrong reason. If impact were too small to matter, both
     * tests would look fine and the budget would be untested decoration.
     */
    public function testWithoutTheBudgetTheSameFlowInflatesVolatility(): void
    {
        $baseline = $this->realizedVolatility(0.0, true);

        foreach ([0.30] as $shareOfDailyVolume) {
            $perTick = $this->perTickFraction($shareOfDailyVolume);

            $budgeted = $this->realizedVolatility($perTick, true);
            $unbudgeted = $this->realizedVolatility($perTick, false);

            $this->assertGreaterThan(
                $baseline,
                $unbudgeted,
                'Unbudgeted order flow must visibly add variance, or this test proves nothing.'
            );

            $this->assertLessThan(
                $unbudgeted,
                $budgeted,
                'The budget must reclaim variance the flow supplied.'
            );
        }
    }

    /**
     * Past the cap the diffusion stops giving ground, and volatility rises.
     *
     * That is the intended behaviour rather than a limitation. A market where the players alone churn more
     * than its entire daily volume genuinely IS more volatile than one where they do not, and the cap is
     * what stops heavy flow from suppressing the diffusion — and with it the fundamental anchoring — to
     * nothing. The threshold is asserted so the trade-off stays visible if the cap is ever retuned.
     *
     * The churn needed to get there is several times daily volume because permanent impact is linear:
     * randomly signed slices add variance in proportion to the sum of their SQUARES, so fine-grained
     * two-way churn nets out almost entirely and only the net flow leaves a mark. Under the square-root
     * law this test once used 1.2x daily volume, which was the concavity charging every slice in full.
     */
    public function testTheCapBindsOnlyUnderImplausiblyHeavyChurn(): void
    {
        $baseline = $this->realizedVolatility(0.0, true);
        $churned = $this->realizedVolatility($this->perTickFraction(6.00), true);

        $this->assertGreaterThan(
            $baseline + 0.02,
            $churned,
            'Beyond the cap, volatility should rise rather than be silently suppressed.'
        );
    }
}

<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\Market\MarketEngine;
use App\Service\Math\MathUtility;
use App\DTO\MarketPricingContext;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class MarketEngineTest extends TestCase
{
    private MathUtility&MockObject $mathUtilityMock;
    private MarketEngine $engine;

    protected function setUp(): void
    {
        // Use a partial mock so the actual math methods run, but we can control randomness
        $this->mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal', 'checkProbability'])
            ->getMock();

        $this->engine = new MarketEngine($this->mathUtilityMock);
    }

    public function testCalculateNextPriceWithoutJump(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $ctx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 0.0 // lambda = 0 means NO jump
        );

        $result = $this->engine->calculateNextPrice($ctx);

        $this->assertIsArray($result);
        $this->assertNull($result['shock'], 'Shock should be null when no jump occurs.');

        // The figure returned is the name's TOTAL volatility, and every source is accounted for: the
        // systematic loading (beta * marketVol)^2 = 0.0225, the market-wide jump's 0.004375 — its 25%
        // ceiling against a long-run idiosyncratic target of 0.04 - 0.0225 = 0.0175 — and the 0.013125 of
        // idiosyncratic diffusion left over. Those sum back to exactly the 0.04 configured, so the answer
        // sits just under the 20% input, short only by where the variance step itself landed this draw.
        $this->assertEqualsWithDelta(0.1949, $result['next_volatility'], 0.001);

        // Whatever the variance process is doing, a name can never be quieter than its own market loading.
        $this->assertGreaterThan(0.15, $result['next_volatility'], 'Total volatility must cover beta * marketVol.');

        $this->assertIsFloat($result['price']);
        $this->assertGreaterThan(0, $result['price']);
    }

    public function testCalculateNextPriceWithGuaranteedJump(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);

        $ctx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 1000.0, // massive lambda guarantees checkProbability triggers
            jumpVol: 0.05
        );

        $result = $this->engine->calculateNextPrice($ctx);

        $this->assertNotNull($result['shock'], 'Shock should occur due to high lambda.');

        $this->assertIsFloat($result['price']);
        $this->assertGreaterThan(0, $result['price']);
        $this->assertIsFloat($result['next_volatility']);
        $this->assertGreaterThan(0, $result['next_volatility']);
    }

    public function testReversionToFairValue(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $ctx = new MarketPricingContext(
            currentPrice: 50.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 0.0,            reversionSpeed: 0.5,
            bookValuePerShare: 50.0,
            currentRoic: 0.10,
            roicTtm: 0.10,
            revenuePerShare: 50.0
        );

        $result = $this->engine->calculateNextPrice($ctx);

        $this->assertGreaterThan(50.0, $result['price'], 'Undervalued price should drift upwards towards fair value.');
    }

    public function testEvaluateFundamentalStateLowMarginHighRevenueNotInflated(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $ctx = new MarketPricingContext(
            currentPrice: 40.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 0.50, // low margin
            dt: 1.0,
            lambda: 0.0,            reversionSpeed: 0.25,
            bookValuePerShare: 25.0,
            currentRoic: 0.08,
            roicTtm: 0.08,
            liveWacc: 0.15,
            revenuePerShare: 32.0,  // high revenue
            businessModel: 'standard'
        );

        $result = $this->engine->calculateNextPrice($ctx);

        // Perceived fair value should not blow up to $60+ due to raw P/S floor
        $this->assertLessThan(35.0, $result['perceived_fair_value'], 'Low-margin firm should not receive bubble fair value.');
    }

    public function testEstarReversionAndFundingLiquidityDampening(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        // Calculate with 0 macro stress
        $normalCtx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 0.0,
            reversionSpeed: 0.5,
            macroState: new MacroStateDTO(outputGap: 0.0, inflation: 0.02)
        );
        $normalResult = $this->engine->calculateNextPrice($normalCtx);

        // Calculate with high macro stress (severe recession and inflation spike)
        $stressedCtx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 0.0,
            reversionSpeed: 0.5,
            macroState: new MacroStateDTO(outputGap: -0.10, inflation: 0.08)
        );
        $stressedResult = $this->engine->calculateNextPrice($stressedCtx);

        $this->assertLessThan(
            $normalResult['dynamic_reversion'],
            $stressedResult['dynamic_reversion'],
            'Brunnermeier-Pedersen funding liquidity dampener must reduce reversion speed during high systemic stress.'
        );
    }

    /** A firm with a dividend policy, priced with no drift or shocks so only the dividend inputs vary. */
    private function incomeContext(float $quarterlyDividend, float $targetPayout, float $speed, string $businessModel = 'none'): MarketPricingContext
    {
        return new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 8.0,
            dt: 1.0,
            lambda: 0.0,
            bookValuePerShare: 60.0,
            dividendPerShare: $quarterlyDividend,
            currentRoic: 0.12,
            roicTtm: 0.12,
            businessModel: $businessModel,
            liveCostOfEquity: 0.08,
            targetPayoutRatio: $targetPayout,
            dividendAdjustmentSpeed: $speed
        );
    }

    /**
     * The income value is the dividend the firm's policy will pay, not the last cheque. A gap to target that
     * closes at once is worth nothing either way; one that closes slowly costs the income missed meanwhile, so a
     * lagging dividend is worth less, but a dividend cut to nothing still leaves the policy's value, not a zero.
     */
    public function testIncomeValuePricesTheDividendPolicyNotTheLastCheque(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);
        $income = fn (float $dividend, float $payout, float $speed): float => $this->engine->calculateNextPrice($this->incomeContext($dividend, $payout, $speed))['analyst_targets']['income_analyst'];

        $atOnce = $income(1.0, 0.5, 1.0);
        $this->assertGreaterThan(1.0, $atOnce);
        $this->assertEqualsWithDelta($atOnce, $income(0.0, 0.5, 1.0), 1e-9);
        $this->assertEqualsWithDelta($atOnce, $income(3.0, 0.5, 1.0), 1e-9);

        $cut = $income(0.0, 0.5, 0.10);
        $this->assertGreaterThan(0.01, $cut, 'A cut dividend still has a policy to return to.');
        $this->assertLessThan($income(1.0, 0.5, 0.10), $cut);
        $this->assertLessThan($atOnce, $cut, 'The income missed while it recovers is a real loss.');

        $this->assertSame(0.01, $income(1.0, 0.0, 0.10), 'No dividend policy, no income value.');
    }

    /** An operating company is worth what it earns and owns; how much of it is paid out is no input (Miller & Modigliani 1961). */
    public function testAnOperatingCompanysFairValueDoesNotTurnOnItsDividend(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);
        $fairValue = fn (float $dividend, float $payout): float => $this->engine->calculateNextPrice($this->incomeContext($dividend, $payout, 0.05, 'tech'))['perceived_fair_value'];

        $paying = $fairValue(1.0, 0.5);
        $this->assertEqualsWithDelta($paying, $fairValue(0.0, 0.5), 1e-9);
        $this->assertEqualsWithDelta($paying, $fairValue(0.0, 0.0), 1e-9);
        $this->assertEqualsWithDelta($paying, $fairValue(3.0, 0.9), 1e-9);
    }

    public function testTechSectorIgnoresBookValueInValuation(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $techCtx = new MarketPricingContext(
            currentPrice: 150.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 10.0,
            dt: 1.0,
            lambda: 0.0,
            bookValuePerShare: 2.0, // Negligible book value
            currentRoic: 0.25,
            roicTtm: 0.25,
            liveWacc: 0.09,
            revenuePerShare: 50.0,
            businessModel: 'tech'
        );
        $techResult = $this->engine->calculateNextPrice($techCtx);

        // Tech fair value should not be dragged down by the tiny $2.00 book value
        $this->assertGreaterThan(75.0, $techResult['perceived_fair_value'], 'Tech fair value should reflect high earnings power rather than book value.');
    }

    public function testMarketShocksAreStrictlyBoundedToThirtyPercent(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);

        for ($i = 0; $i < 500; $i++) {
            $ctx = new MarketPricingContext(
                currentPrice: 100.0,
                currentVolatility: 0.15,
                longTermVolatility: 0.15,
                earningsPerShare: 5.0,
                dt: 1.0 / 252.0,
                lambda: 2.0,
                jumpVol: 0.10
            );

            $result = $this->engine->calculateNextPrice($ctx);
            $shock = $result['shock'];

            $this->assertNotNull($shock);
            $this->assertLessThanOrEqual(30.01, $shock, 'Positive market shock must be bounded to <= 30% ceiling.');
            $this->assertGreaterThanOrEqual(-30.01, $shock, 'Negative market shock must be bounded to >= -30% floor.');
            $this->assertNotEquals(0.0, $shock, 'Market shock should be non-zero.');
        }
    }

    public function testSafeReinsuranceMarketJumpStaysWithinBoundedLimits(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);

        $ctx = new MarketPricingContext(
            currentPrice: 1500.0,
            currentVolatility: 0.10,
            longTermVolatility: 0.10,
            earningsPerShare: 200.0,
            dt: 1.0 / 252.0,
            lambda: 0.15,
            jumpVol: 0.06,
            beta: 0.20
        );

        for ($i = 0; $i < 200; $i++) {
            $result = $this->engine->calculateNextPrice($ctx);
            $shock = $result['shock'];

            $this->assertNotNull($shock);
            $this->assertLessThanOrEqual(30.01, $shock, 'SAFE market shock must never exceed 30%.');
            $this->assertGreaterThanOrEqual(-30.01, $shock, 'SAFE market shock must never breach -30%.');
            $this->assertNotEquals(0.0, $shock, 'SAFE market shock should be non-zero.');
        }
    }

    /**
     * A financial's P/B leg is struck on tangible book. Wherever the justified multiple is interior this is
     * the same value (the same earnings over the smaller base, NI / r either way); at the 0.40x floor it is 40%
     * of TANGIBLE book, so goodwill no longer props up a distressed bank's valuation.
     */
    public function testAFinancialsBookLegIsStruckOnTangibleBook(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $valueAnalyst = fn (float $roe, ?float $tangibleBook, string $model = 'commercial_bank'): float => $this->engine->calculateNextPrice(new MarketPricingContext(
            currentPrice: 50.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 100.0 * $roe,
            dt: 1.0,
            lambda: 0.0,
            bookValuePerShare: 100.0,
            currentRoic: $roe,
            roicTtm: $roe,
            liveCostOfEquity: 0.10,
            liveWacc: 0.10,
            businessModel: $model,
            tangibleBookValuePerShare: $tangibleBook
        ))['analyst_targets']['value_analyst'];

        // Interior: 15% on $100 of book is 37.5% on $40 of tangible book, and both are worth $150.
        $this->assertEqualsWithDelta($valueAnalyst(0.15, null), $valueAnalyst(0.15, 40.0), 1e-9);

        // At the floor: 1% on book is far below the hurdle, and 40% of $40 is what is left of the floor.
        $this->assertEqualsWithDelta(0.40 * 40.0 * 0.80, $valueAnalyst(0.01, 40.0), 1e-9);
        $this->assertEqualsWithDelta(0.40 * 100.0 * 0.80, $valueAnalyst(0.01, null), 1e-9, 'without a tangible book the leg reads book, as before');

        // An operating company is valued on its invested capital's return; the tangible book is not read.
        $this->assertEqualsWithDelta($valueAnalyst(0.01, null, 'none'), $valueAnalyst(0.01, 40.0, 'none'), 1e-9);
    }
}

<?php

namespace App\Tests\Service\Market\Pricing;

use PHPUnit\Framework\TestCase;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\PolicyCapitalization;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
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
    /**
     * A forecast revised this tick moves the price at once by as much as it moves the fair value, rather than leaving
     * the price to drift there; a bank, which also expects the levy, is marked down by more than a firm that owes none.
     */
    /**
     * A policy gap is capitalized on the same clamped perpetual growth the multiple is struck on. In an inflationary
     * boom the raw outlook can reach the hurdle; spread over that, a levy gap once wiped a broker's value out.
     */
    public function testAPolicyGapIsCapitalizedOnTheClampedPerpetualGrowth(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);
        $levy = PolicyCapitalization::LONG_RUN_BANK_LEVY_RATE;
        $value = fn (float $expectedLevy): float => $this->engine->calculateNextPrice(new MarketPricingContext(
            currentPrice: 100.0, currentVolatility: 0.2, longTermVolatility: 0.2, dt: 1.0 / 252.0, lambda: 0.0, beta: 2.0,
            macroState: new MacroStateDTO(
                totalTime: 6.0, inflation: 0.12, outputGap: 0.03, bankLevyRate: $levy, bankLevyEmbodied: $levy,
                expectedLevers: ['bankLevyRate' => $expectedLevy], expectedPolicyFrom: 8.5,
                previousExpectedLevers: ['bankLevyRate' => $expectedLevy], previousExpectedPolicyFrom: 8.5,
            ),
            bookValuePerShare: 60.0, currentRoic: 0.12, roicTtm: 0.12, businessModel: 'commercial_bank', liveCostOfEquity: 0.10,
            policyBasesPerShare: ['bankLevyRate' => 400.0],
        ))['perceived_fair_value'];

        // The outlook here (12% inflation plus a boom at beta 2) is above the hurdle; the multiple holds it at +6%.
        $this->assertEqualsWithDelta(0.06, MathUtility::perpetualGrowthRate(0.10, 0.20), 1e-12);
        $this->assertEqualsWithDelta(max(FinancialConstants::MIN_COST_OF_EQUITY, 0.05) - FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD, MathUtility::perpetualGrowthRate(0.05, 0.20), 1e-12);

        $ratio = $value($levy + 0.002) / $value($levy);
        $this->assertLessThan(1.0, $ratio, 'A higher expected levy still costs the bank.');
        $this->assertGreaterThan(0.5, $ratio, 'Spread over the clamped growth, it does not wipe the bank out.');
    }

    public function testARevisedForecastMovesThePriceWithTheTarget(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);
        $inForce = PolicyCapitalization::LONG_RUN_CORPORATE_TAX_SHIFT;
        $levy = PolicyCapitalization::LONG_RUN_BANK_LEVY_RATE;
        $macro = static fn(float $expectedTax, float $expectedLevy, float $previousTax, float $previousLevy, float $expectedRules = 0.0): MacroStateDTO => new MacroStateDTO(
            totalTime: 6.0,
            corporateTaxPolicyShift: $inForce,
            bankLevyRate: $levy,
            corporateTaxShiftRealized: $inForce,
            corporateTaxShiftEmbodied: $inForce,
            bankLevyEmbodied: $levy,
            expectedLevers: ['corporateTax' => $expectedTax, 'bankLevyRate' => $expectedLevy, 'extractionStringency' => $expectedRules],
            expectedPolicyFrom: 8.5,
            previousExpectedLevers: ['corporateTax' => $previousTax, 'bankLevyRate' => $previousLevy, 'extractionStringency' => $expectedRules],
            previousExpectedPolicyFrom: 8.5,
        );
        $priced = fn (MacroStateDTO $state, string $model, array $bases): array => $this->engine->calculateNextPrice(new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            dt: 1.0 / 252.0,
            lambda: 0.0,
            macroState: $state,
            bookValuePerShare: 60.0,
            currentRoic: 0.12,
            roicTtm: 0.12,
            businessModel: $model,
            liveCostOfEquity: 0.10,
            policyBasesPerShare: $bases,
        ));

        $steady = $priced($macro($inForce, $levy, $inForce, $levy), 'tech', []);
        $revised = $priced($macro($inForce + 0.04, $levy, $inForce, $levy), 'tech', []);
        $repricing = $revised['perceived_fair_value'] / $steady['perceived_fair_value'];
        $this->assertLessThan(0.99, $repricing, 'A four-point rise from the next government\'s first budget costs the firm its share of the tax for as long as the rise is expected to last.');
        $this->assertGreaterThan(1.0 - (0.04 / 0.79), $repricing, 'Less than a rise for good would.');
        $this->assertEqualsWithDelta($repricing, $revised['price'] / $steady['price'], 1e-3);

        $settled = $priced($macro($inForce + 0.04, $levy, $inForce + 0.04, $levy), 'tech', []);
        $this->assertEqualsWithDelta($revised['perceived_fair_value'], $settled['perceived_fair_value'], 1e-9);
        $this->assertLessThan(0.1 * abs($revised['price'] - $steady['price']), abs($settled['price'] - $steady['price']), 'Once the revision is in the price, the next tick only drifts toward the target, as every tick does.');

        $bank = $priced($macro($inForce, $levy + 0.002, $inForce, $levy + 0.002), 'commercial_bank', ['bankLevyRate' => 400.0]);
        $bankSteady = $priced($macro($inForce, $levy, $inForce, $levy), 'commercial_bank', ['bankLevyRate' => 400.0]);
        $this->assertLessThan($bankSteady['perceived_fair_value'], $bank['perceived_fair_value']);
        $firm = $priced($macro($inForce, $levy + 0.002, $inForce, $levy + 0.002), 'tech', []);
        $this->assertEqualsWithDelta($steady['perceived_fair_value'], $firm['perceived_fair_value'], 1e-9, 'A firm owing no levy does not price one.');

        // A miner prices the strictest rules expected from the next government by the cost they would add to its units.
        $mine = ['extractionStringency' => 60.0];
        $strict = $priced($macro($inForce, $levy, $inForce, $levy, 1.0), 'mining', $mine);
        $founding = $priced($macro($inForce, $levy, $inForce, $levy, 0.0), 'mining', $mine);
        $this->assertLessThan($founding['perceived_fair_value'], $strict['perceived_fair_value']);
        $this->assertEqualsWithDelta(
            $priced($macro($inForce, $levy, $inForce, $levy, 0.0), 'mining', [])['perceived_fair_value'],
            $priced($macro($inForce, $levy, $inForce, $levy, 1.0), 'mining', [])['perceived_fair_value'],
            1e-9,
            'A miner with no reported cost base prices no rules.'
        );
    }

    private function incomeContext(float $quarterlyDividend, float $targetPayout, float $speed, string $businessModel = 'none'): MarketPricingContext
    {
        return new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
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
     * The re-strike a report tick hands its agents is the price step's own fair value, struck without a draw, so
     * it cannot shift any scripted random stream.
     */
    public function testAFairValueStrikeMatchesThePriceStepAndDrawsNothing(): void
    {
        $this->mathUtilityMock->expects($this->never())->method('generateStandardNormal');
        $this->mathUtilityMock->expects($this->never())->method('checkProbability');
        $context = $this->incomeContext(1.0, 0.5, 0.2, 'tech');

        $strike = $this->engine->strikeFairValue($context);

        $stepping = new MarketEngine(new MathUtility());
        $step = $stepping->calculateNextPrice($context);
        $this->assertEqualsWithDelta($step['perceived_fair_value'], $strike['perceived_fair_value'], 1e-9);
        $this->assertEqualsWithDelta($step['analyst_targets'], $strike['analyst_targets'], 1e-9);
    }

    /**
     * The sovereign fund's measured impact variance comes out of the market factor's diffusion, (beta sigma_m)^2
     * less it, so the systematic loading the tape realizes stays beta sigma_m. The +/-Z spread isolates the factor
     * term: drift, Ito and reversion pull cancel in it.
     */
    public function testTheFundsImpactVarianceComesOutOfTheMarketFactorDiffusion(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);
        $spread = function (float $fundVariance): float {
            $at = fn (float $z): float => log($this->engine->calculateNextPrice(new MarketPricingContext(
                currentPrice: 100.0, currentVolatility: 0.25, longTermVolatility: 0.25,
                dt: 1.0 / 252.0, lambda: 0.0, beta: 1.2, marketZ: $z, marketVol: 0.15, bookValuePerShare: 50.0,
                fundImpactVariance: $fundVariance
            ))['price']);

            return $at(1.0) - $at(-1.0);
        };

        $systematic = (1.2 * 0.15) ** 2;
        $this->assertEqualsWithDelta(sqrt(1.0 - (0.01 / $systematic)), $spread(0.01) / $spread(0.0), 1e-9);
        $this->assertEqualsWithDelta(0.0, $spread($systematic * 2.0), 1e-12, 'Floored at zero, never negative.');
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

    /**
     * An operating company is worth what it earns and owns (Miller & Modigliani 1961). The cheque it last paid is
     * no input at all, and its payout policy reaches value only through investment: while what it retains funds
     * the outlook's growth the payout changes nothing, and a payout that leaves too little to fund it lowers the
     * growth, and the multiple, it is priced on.
     */
    public function testAnOperatingCompanysDividendReachesValueOnlyThroughTheGrowthItsRetentionFunds(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);
        $fairValue = fn (float $dividend, float $payout): float => $this->engine->calculateNextPrice($this->incomeContext($dividend, $payout, 0.05, 'tech'))['perceived_fair_value'];

        $paying = $fairValue(1.0, 0.5);
        $this->assertEqualsWithDelta($paying, $fairValue(0.0, 0.5), 1e-9, 'The cheque itself is no input.');
        $this->assertEqualsWithDelta($paying, $fairValue(3.0, 0.5), 1e-9);
        $this->assertEqualsWithDelta($paying, $fairValue(1.0, 0.0), 1e-9, 'Half of a 12% return funds the outlook, so paying it out costs nothing.');
        $this->assertLessThan($paying, $fairValue(1.0, 0.95), 'Keeping 5% of a 12% return funds 0.6% growth, short of the outlook.');
    }

    public function testTechSectorIgnoresBookValueInValuation(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $techCtx = new MarketPricingContext(
            currentPrice: 150.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            dt: 1.0,
            lambda: 0.0,
            bookValuePerShare: 2.0, // Negligible book value
            currentRoic: 0.25,
            roicTtm: 0.25,
            liveWacc: 0.09,
            liveCostOfEquity: 0.09, // All equity: the WACC is the cost of equity.
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

    /**
     * Fair value prices equity, so it is discounted at the cost of equity and the WACC does not enter it:
     * two firms differing only in their WACC price identically, and a dearer equity is worth less.
     */
    public function testFairValueIsDiscountedAtTheCostOfEquityNotTheWacc(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $priced = fn (float $wacc, float $costOfEquity): array => $this->engine->calculateNextPrice(new MarketPricingContext(
            currentPrice: 80.0,
            currentVolatility: 0.25,
            longTermVolatility: 0.25,
            dt: 1.0 / 252.0,
            lambda: 0.0,
            bookValuePerShare: 30.0,
            currentRoic: 0.12,
            roicTtm: 0.12,
            liveWacc: $wacc,
            revenuePerShare: 60.0,
            liveCostOfEquity: $costOfEquity,
            investedCapitalPerShare: 50.0,
            costOfDebt: 0.05,
        ));

        $base = $priced(0.08, 0.10);
        $this->assertEqualsWithDelta($base['perceived_fair_value'], $priced(0.06, 0.10)['perceived_fair_value'], 1e-9, 'The WACC moved an equity value.');
        $this->assertEqualsWithDelta($base['price'], $priced(0.06, 0.10)['price'], 1e-9);
        $this->assertLessThan($base['perceived_fair_value'], $priced(0.08, 0.12)['perceived_fair_value'], 'A dearer equity must be worth less.');
    }

    /**
     * A good year is not capitalized as permanent: a trailing return above the firm's long-run level is valued on its
     * persistent-equivalent share of the gap (Ohlson 1995; Fama & French 2000), and a firm with no long-run record yet
     * is valued on its trailing return.
     */
    public function testAReturnAboveItsLongRunLevelIsValuedAsItFades(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $fairValue = fn (float $trailing, ?float $longRun): float => $this->engine->calculateNextPrice(new MarketPricingContext(
            currentPrice: 80.0,
            currentVolatility: 0.25,
            longTermVolatility: 0.25,
            dt: 1.0 / 252.0,
            lambda: 0.0,
            bookValuePerShare: 30.0,
            currentRoic: $trailing,
            roicTtm: $trailing,
            liveWacc: 0.08,
            revenuePerShare: 60.0,
            liveCostOfEquity: 0.10,
            investedCapitalPerShare: 50.0,
            costOfDebt: 0.05,
            longRunReturn: $longRun,
        ))['perceived_fair_value'];

        $this->assertEqualsWithDelta($fairValue(0.20, null), $fairValue(0.20, 0.20), 1e-9);
        $this->assertLessThan($fairValue(0.20, null), $fairValue(0.20, 0.12), 'A good year was capitalized as permanent.');
        $this->assertEqualsWithDelta(
            $fairValue(MathUtility::persistentEquivalentReturn(0.20, 0.12, 0.10), null),
            $fairValue(0.20, 0.12),
            1e-9
        );
    }
}

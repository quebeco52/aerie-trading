<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\SovereignCurveDTO;
use App\Entity\OptionContract;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\MarketEngine;
use App\Service\Market\OptionPricingEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The option desk, checked on the properties that make it a desk rather than a formula: the surface it
 * quotes has to be the one the price process will actually realize, the smile has to carry the direction of
 * the underlying's own jump, and the two sides of the quote have to be a spread in volatility.
 */
class OptionPricingEngineTest extends TestCase
{
    private MathUtility $math;
    private OptionPricingEngine $engine;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
        $this->engine = new OptionPricingEngine($this->math, new BondPricingEngine($this->math));
    }

    private function curve(): SovereignCurveDTO
    {
        return new SovereignCurveDTO(
            level: 0.0425,
            slope: -0.0175,
            curvature1: 0.0,
            curvature2: 0.0,
            baseTermPremium: 0.0125,
            longEndPremium: 0.0125,
            balanceSheetIntensity: 0.0,
        );
    }

    // --- Surface Level ---

    public function testTheSurfaceIsStruckOnVarianceExpectedOverTheContractsLifeNotTodays(): void
    {
        // A name whose spot volatility is far above its long-run level.
        $shocked = $this->engine->surface(0.80, 0.25, 1.0, 0.0, 0.0, 1.0);
        $calm = $this->engine->surface(0.25, 0.25, 1.0, 0.0, 0.0, 1.0);

        // The shock is priced, but mean reversion has pulled most of it out over a year: the one-year
        // contract quotes far below the spot volatility it was struck from.
        $this->assertGreaterThan($calm['atm_volatility'], $shocked['atm_volatility']);
        $this->assertLessThan(0.80, $shocked['atm_volatility']);
    }

    public function testAShockIsPricedHarderIntoANearExpiryThanAFarOne(): void
    {
        $near = $this->engine->surface(0.80, 0.25, 1.0, 0.0, 0.0, 1.0 / 12.0);
        $far = $this->engine->surface(0.80, 0.25, 1.0, 0.0, 0.0, 1.0);

        // The front month lives entirely inside the shock; the back month mostly outside it. That is the
        // term structure inverting, which is what a real surface does in a panic.
        $this->assertGreaterThan($far['atm_volatility'], $near['atm_volatility']);
    }

    public function testTheDeskQuotesAboveTheVarianceItExpectsToRealize(): void
    {
        // With no jump and the spot at its long-run level, the expected volatility IS the configured one,
        // so what is left over the top of it is the variance risk premium and nothing else.
        $surface = $this->engine->surface(0.30, 0.30, 1.0, 0.0, 0.0, 0.5);

        $this->assertEqualsWithDelta(0.30 * FinancialConstants::OPTION_VARIANCE_RISK_PREMIUM, $surface['atm_volatility'], 1e-9);
    }

    public function testTheSurfaceCarriesNoShapeWhenTheNameDoesNotJump(): void
    {
        $surface = $this->engine->surface(0.30, 0.30, 1.0, 0.0, 0.10, 0.5);

        $this->assertSame(0.0, $surface['skewness']);
        $this->assertSame(0.0, $surface['excess_kurtosis']);
    }

    // --- Surface Shape ---

    public function testAJumpyNameQuotesNegativeSkewAndFatTails(): void
    {
        $surface = $this->engine->surface(0.30, 0.30, 1.0, 2.0, 0.12, 0.25);

        // The price process's own jump is 40/60 with a wider down branch, so the distribution it produces is
        // left-skewed and fat-tailed, and the surface has to say so.
        $this->assertLessThan(0.0, $surface['skewness']);
        $this->assertGreaterThan(0.0, $surface['excess_kurtosis']);
    }

    public function testShapeIsTakenFromTheCalibratedJumpNotTheConfiguredOne(): void
    {
        // A name configured with a jump far larger than its variance budget allows. The engine scales the
        // jump down before the tape ever sees it, and the desk has to quote the scaled one.
        $longRunVol = 0.20;
        $lambda = 4.0;
        $configuredJumpVol = 0.90;

        $calibrated = MarketEngine::calibratedJumpParameters($longRunVol, 1.0, $lambda, $configuredJumpVol);
        $this->assertLessThan($configuredJumpVol, $calibrated['scale'], 'the budget should have bound here');

        $surface = $this->engine->surface($longRunVol, $longRunVol, 1.0, $lambda, $configuredJumpVol, 0.25);

        $expected = $this->math->calculateJumpDiffusionShape(
            max(0.0, (($longRunVol * FinancialConstants::OPTION_VARIANCE_RISK_PREMIUM) ** 2)
                - ($lambda * $this->math->calculateKouJumpMoment(2, MarketEngine::jumpProbabilityUp(), $calibrated['eta_up'], $calibrated['eta_down']))),
            $lambda,
            MarketEngine::jumpProbabilityUp(),
            $calibrated['eta_up'],
            $calibrated['eta_down'],
            0.25
        );

        $this->assertEqualsWithDelta($expected['skewness'], $surface['skewness'], 1e-12);
        $this->assertEqualsWithDelta($expected['excess_kurtosis'], $surface['excess_kurtosis'], 1e-12);
    }

    public function testTheJumpDrawsItsVarianceFromTheDiffusionRatherThanStackingOnIt(): void
    {
        // Same name, priced with and without a jump. The jump changes the SHAPE of the surface and must not
        // change its level: a jump charged on top would quote a jumpy name above its own realized tape, which
        // is the same double count the price engine's variance budget exists to prevent.
        $jumpless = $this->engine->surface(0.30, 0.30, 1.0, 0.0, 0.0, 0.5);
        $jumpy = $this->engine->surface(0.30, 0.30, 1.0, 3.0, 0.10, 0.5);

        $this->assertEqualsWithDelta($jumpless['atm_volatility'], $jumpy['atm_volatility'], 1e-12);
        $this->assertNotEqualsWithDelta($jumpless['skewness'], $jumpy['skewness'], 1e-6);

        // And the two components the shape is built from add back to exactly that variance.
        $calibrated = MarketEngine::calibratedJumpParameters(0.30, 1.0, 3.0, 0.10);
        $shape = $this->math->calculateJumpDiffusionShape(
            max(0.0, ($jumpy['atm_volatility'] ** 2)
                - (3.0 * $this->math->calculateKouJumpMoment(2, MarketEngine::jumpProbabilityUp(), $calibrated['eta_up'], $calibrated['eta_down']))),
            3.0,
            MarketEngine::jumpProbabilityUp(),
            $calibrated['eta_up'],
            $calibrated['eta_down'],
            0.5
        );

        $this->assertEqualsWithDelta(($jumpy['atm_volatility'] ** 2) * 0.5, $shape['variance'], 1e-12);
    }

    // --- Quotes ---

    public function testTheSmileMarksDownsideStrikesAboveUpsideOnes(): void
    {
        $surface = $this->engine->surface(0.30, 0.30, 1.0, 2.0, 0.12, 0.25);

        $downside = $this->engine->quote(100.0, 85.0, false, $surface, 0.04, 0.0, 0.25);
        $upside = $this->engine->quote(100.0, 115.0, true, $surface, 0.04, 0.0, 0.25);

        // The equity smirk: the put nobody wants to be short is marked at a higher volatility than the call.
        $this->assertGreaterThan($upside->impliedVolatility, $downside->impliedVolatility);
    }

    public function testAQuoteIsAlwaysWorthAtLeastAPennyAndAtLeastItsIntrinsicValue(): void
    {
        $surface = $this->engine->surface(0.25, 0.25, 1.0, 1.0, 0.08, 0.25);

        $miles = $this->engine->quote(100.0, 400.0, true, $surface, 0.04, 0.0, 0.01);
        $this->assertGreaterThanOrEqual(FinancialConstants::OPTION_MIN_PREMIUM, $miles->mark);

        $deep = $this->engine->quote(100.0, 50.0, true, $surface, 0.04, 0.0, 0.25);
        $this->assertGreaterThan(100.0 - 50.0 - 0.001, $deep->mark);
    }

    public function testCallDeltaIsPositiveAndPutDeltaNegative(): void
    {
        $surface = $this->engine->surface(0.25, 0.25, 1.0, 1.0, 0.08, 0.5);

        $this->assertGreaterThan(0.0, $this->engine->quote(100.0, 100.0, true, $surface, 0.04, 0.0, 0.5)->delta);
        $this->assertLessThan(0.0, $this->engine->quote(100.0, 100.0, false, $surface, 0.04, 0.0, 0.5)->delta);
    }

    public function testGammaIsHighestAtTheMoney(): void
    {
        $surface = $this->engine->surface(0.25, 0.25, 1.0, 0.0, 0.0, 0.25);

        $atm = $this->engine->quote(100.0, 100.0, true, $surface, 0.04, 0.0, 0.25)->gamma;
        $wing = $this->engine->quote(100.0, 130.0, true, $surface, 0.04, 0.0, 0.25)->gamma;

        $this->assertGreaterThan($wing, $atm);
    }

    // --- The Spread Is A Spread In Volatility ---

    public function testTheQuotedSpreadIsVegaTimesTheDesksVolatilitySpread(): void
    {
        $surface = $this->engine->surface(0.30, 0.30, 1.0, 0.0, 0.0, 0.5);
        $quote = $this->engine->quote(100.0, 100.0, true, $surface, 0.04, 0.0, 0.5);

        $expectedHalfSpread = $quote->vega * FinancialConstants::OPTION_HALF_SPREAD_VOLATILITY;

        $this->assertEqualsWithDelta($expectedHalfSpread, ($quote->ask - $quote->bid) / 2.0, 1e-9);
        $this->assertEqualsWithDelta($quote->mark, ($quote->ask + $quote->bid) / 2.0, 1e-9);
    }

    public function testADeepInTheMoneyContractStillCostsSomethingToCross(): void
    {
        $surface = $this->engine->surface(0.25, 0.25, 1.0, 0.0, 0.0, 0.08);

        // Almost no vega left here, so the volatility spread alone would quote it for free.
        $quote = $this->engine->quote(100.0, 40.0, true, $surface, 0.04, 0.0, 0.08);

        $this->assertGreaterThan(0.0, $quote->ask - $quote->bid);
        $this->assertEqualsWithDelta(
            $quote->mark * FinancialConstants::OPTION_MIN_HALF_SPREAD_FRACTION,
            ($quote->ask - $quote->bid) / 2.0,
            1e-9
        );
    }

    public function testAFarOutOfTheMoneyContractNeverQuotesANegativeBid(): void
    {
        $surface = $this->engine->surface(0.60, 0.60, 1.0, 2.0, 0.15, 1.0);
        $quote = $this->engine->quote(100.0, 250.0, true, $surface, 0.04, 0.0, 1.0);

        $this->assertGreaterThanOrEqual(0.0, $quote->bid);
        $this->assertLessThanOrEqual(
            $quote->mark * FinancialConstants::OPTION_MAX_HALF_SPREAD_FRACTION,
            ($quote->ask - $quote->bid) / 2.0
        );
    }

    public function testAQuoteIsBoughtAtTheAskAndSoldAtTheBid(): void
    {
        $surface = $this->engine->surface(0.30, 0.30, 1.0, 0.0, 0.0, 0.5);
        $quote = $this->engine->quote(100.0, 100.0, true, $surface, 0.04, 0.0, 0.5);

        $this->assertSame($quote->ask, $quote->executionPrice(true));
        $this->assertSame($quote->bid, $quote->executionPrice(false));
    }

    // --- Dividends And The Curve ---

    public function testTheDividendYieldIsTheAnnualisedRunRateOfTheQuarterlyPayment(): void
    {
        $stock = StockBuilder::create()->withPrice(80.0)->withLastDividend(0.60)->build();

        $this->assertEqualsWithDelta((0.60 * 4.0) / 80.0, $this->engine->dividendYield($stock), 1e-12);
    }

    public function testANonPayerHasNoDividendYield(): void
    {
        $stock = StockBuilder::create()->withPrice(80.0)->build();

        $this->assertSame(0.0, $this->engine->dividendYield($stock));
    }

    public function testTheContractDiscountsAtTheCurveNotThePolicyRate(): void
    {
        $curve = $this->curve();

        $short = $this->engine->riskFreeRate($curve, 1.0 / 12.0);
        $long = $this->engine->riskFreeRate($curve, 2.0);

        // An upward sloping curve has to reach the desk as a genuinely different rate by tenor.
        $this->assertNotEqualsWithDelta($short, $long, 1e-6);
    }

    // --- Contract Pricing ---

    public function testAContractIsPricedFromItsUnderlyingsOwnParameters(): void
    {
        $stock = StockBuilder::create('VANE')
            ->withPrice(100.0)
            ->withVolatility(0.35)
            ->withCurrentVolatility(0.35)
            ->withJumpIntensity(2.0)
            ->withJumpVol(0.12)
            ->withBeta(1.2)
            ->build();

        $contract = (new OptionContract())
            ->setTicker('VANE-13C100')
            ->setStock($stock)
            ->setOptionType(OptionContract::TYPE_CALL)
            ->setStrike('100')
            ->setExpirySerial(13)
            ->setExpiresAtTime(13.0 / 12.0);

        $quote = $this->engine->quoteContract($contract, $this->curve(), 1.0);

        $this->assertGreaterThan(0.0, $quote->mark);
        $this->assertEqualsWithDelta((13.0 / 12.0) - 1.0, $quote->timeToExpiry, 1e-12);
        $this->assertGreaterThan(0.0, $quote->delta);
        $this->assertLessThan(1.0, $quote->delta);
    }

    public function testAnExpiredContractIsMarkedAtIntrinsicValue(): void
    {
        $stock = StockBuilder::create('VANE')->withPrice(120.0)->withVolatility(0.30)->build();

        $contract = (new OptionContract())
            ->setTicker('VANE-12C100')
            ->setStock($stock)
            ->setOptionType(OptionContract::TYPE_CALL)
            ->setStrike('100')
            ->setExpirySerial(12)
            ->setExpiresAtTime(1.0);

        $quote = $this->engine->quoteContract($contract, $this->curve(), 1.0);

        $this->assertEqualsWithDelta(20.0, $quote->mark, 1e-9);
        $this->assertSame(1.0, $quote->delta);
        $this->assertSame(0.0, $quote->gamma);
    }

    public function testAChainStrikesOneSurfacePerExpiryAndPricesEveryStrikeOffIt(): void
    {
        $stock = StockBuilder::create('VANE')
            ->withPrice(100.0)
            ->withVolatility(0.30)
            ->withCurrentVolatility(0.45)
            ->withJumpIntensity(2.0)
            ->withJumpVol(0.10)
            ->build();

        $contracts = [];
        foreach ([90.0, 100.0, 110.0] as $strike) {
            foreach ([OptionContract::TYPE_CALL, OptionContract::TYPE_PUT] as $type) {
                $contracts[] = (new OptionContract())
                    ->setTicker('VANE-13' . ($type === OptionContract::TYPE_CALL ? 'C' : 'P') . $strike)
                    ->setStock($stock)
                    ->setOptionType($type)
                    ->setStrike((string) $strike)
                    ->setExpirySerial(13)
                    ->setExpiresAtTime(13.0 / 12.0);
            }
        }

        $quotes = $this->engine->quoteChain($contracts, $this->curve(), 1.0);

        $this->assertCount(6, $quotes);

        // Every quote in the chain agrees with the single-contract path, which is the property that lets the
        // sweep reuse a surface and the trade path reprice one contract.
        foreach ($contracts as $contract) {
            $single = $this->engine->quoteContract($contract, $this->curve(), 1.0);
            $this->assertEqualsWithDelta($single->mark, $quotes[$contract->getTicker()]->mark, 1e-12, $contract->getTicker());
        }
    }

    public function testPutCallParityHoldsAcrossTheChainAtTheSameStrike(): void
    {
        // Parity is a no-arbitrage identity, not a model result, so it has to survive the smile: both sides
        // of a strike are quoted at the SAME volatility, which is exactly what the smile guarantees.
        $surface = $this->engine->surface(0.30, 0.30, 1.0, 2.0, 0.12, 0.5);

        foreach ([80.0, 100.0, 120.0] as $strike) {
            $call = $this->engine->quote(100.0, $strike, true, $surface, 0.04, 0.015, 0.5);
            $put = $this->engine->quote(100.0, $strike, false, $surface, 0.04, 0.015, 0.5);

            $this->assertEqualsWithDelta($call->impliedVolatility, $put->impliedVolatility, 1e-12, "strike {$strike}");

            $expected = (100.0 * exp(-0.015 * 0.5)) - ($strike * exp(-0.04 * 0.5));
            $this->assertEqualsWithDelta($expected, $call->mark - $put->mark, 1e-6, "strike {$strike}");
        }
    }
}

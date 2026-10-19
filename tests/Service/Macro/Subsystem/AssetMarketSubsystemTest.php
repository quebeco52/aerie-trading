<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Subsystem;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\CreditRisk;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use PHPUnit\Framework\TestCase;

class AssetMarketSubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private AssetMarketSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            public function generateUniform(): float
            {
                return 1.0; // no jump strikes
            }
        };
        $this->subsystem = new AssetMarketSubsystem($this->mathUtility);
    }

    public function testCampbellCochraneEquityRiskPremiumRisesInRecession(): void
    {
        $state = new MacroState();
        $state->outputGapEma = -0.05;

        $this->subsystem->calculateEquityRiskPremium($state);
        $this->assertGreaterThan(MacroConstants::BASE_EQUITY_RISK_PREMIUM, $state->equityRiskPremium);
    }

    public function testTheForeignBlocPricesItsOwnCycleAndReRatesItsEquities(): void
    {
        $state = new MacroState();
        $this->subsystem->calculateEquityRiskPremium($state);
        $this->assertEqualsWithDelta(MacroConstants::BASE_EQUITY_RISK_PREMIUM, $state->foreignEquityRiskPremium, 1e-15, 'At trend, the base premium.');
        $this->assertEqualsWithDelta(0.0, $state->foreignEquityValuation, 1e-15);

        // A foreign recession, with the district itself at trend: only the foreign premium and valuation move.
        $state->foreignOutputGapEma = -0.03;
        $this->subsystem->calculateEquityRiskPremium($state);

        $premium = MacroConstants::BASE_EQUITY_RISK_PREMIUM * exp(AssetMarketSubsystem::HABIT_RISK_AVERSION_COEFF * 0.03);
        $annualPersistence = TimeSeries::calculateAr2Autocorrelation(AssetMarketSubsystem::MAINLAND_GAP_AR1, AssetMarketSubsystem::MAINLAND_GAP_AR2, 4);
        $duration = 1.0 / (1.0 - (AssetMarketSubsystem::CAMPBELL_SHILLER_RHO * $annualPersistence));
        $this->assertEqualsWithDelta($premium, $state->foreignEquityRiskPremium, 1e-15);
        $this->assertEqualsWithDelta(MacroConstants::BASE_EQUITY_RISK_PREMIUM, $state->equityRiskPremium, 1e-15);
        $this->assertEqualsWithDelta(-$duration * ($premium - MacroConstants::BASE_EQUITY_RISK_PREMIUM), $state->foreignEquityValuation, 1e-15);
        $this->assertEqualsWithDelta($state->foreignEquityValuation, $state->foreignEquityValuationChange, 1e-15, 'The change from trend is handed on.');
        $this->assertLessThan(-0.03, $state->foreignEquityValuation, 'A 3% foreign slump re-rates foreign equities by a few percent.');

        // The same state again: no further re-rating to hand on.
        $this->subsystem->calculateEquityRiskPremium($state);
        $this->assertEqualsWithDelta(0.0, $state->foreignEquityValuationChange, 1e-15);
    }

    public function testRealEstateIndicesMeanRevertWithinBounds(): void
    {
        $state = new MacroState();
        $state->yield10y = 0.04;
        $state->yield10yEma = 0.045;
        $state->macroCreditSpread = 0.02;
        $state->unemploymentRateEma = 0.04;
        $state->inflationEma = 0.02;
        $state->commercialPropertyIndex = 100.0;
        $state->residentialPropertyIndex = 100.0;

        $this->subsystem->calculateCommercialPropertyIndex($state, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($state, MacroConstants::TARGET_INFLATION, 0.25);

        $this->assertGreaterThan(0.0, $state->commercialPropertyIndex);
        $this->assertGreaterThan(0.0, $state->residentialPropertyIndex);
    }

    /**
     * Rents keep pace with expected inflation, so a ten-year yield that rises one for one with it leaves the Gordon
     * value where it was; the same rise in the real yield is a higher cap rate and a lower value.
     */
    public function testCommercialPropertyRentIndexationProtectsValuationsDuringInflation(): void
    {
        $anchored = $this->creFundamentalTick(0.055, 0.02);

        $this->assertEqualsWithDelta($anchored, $this->creFundamentalTick(0.065, 0.03), 1e-9, 'Inflation priced into the yield and the rents nets out.');
        $this->assertLessThan($anchored, $this->creFundamentalTick(0.065, 0.02), 'A higher real yield must lower values.');
    }

    /**
     * Construction and the REITs read the index against CRE_BASELINE, so the fundamental must sit there when every
     * input the cap rate reads is at the value the engine is built on. A neutral cap rate measured off an older engine
     * (6.4%) held the index near 110 and handed both a standing tenth of property values that no cycle had produced.
     */
    public function testCommercialPropertyHoldsItsBaselineAtTheEnginesNeutral(): void
    {
        $state = new MacroState();
        $state->yield10yEma = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION + MacroConstants::NS_BASE_TERM_PREMIUM;
        $state->tipsBreakevenEma = MacroConstants::TARGET_INFLATION;
        $state->macroCreditSpreadEma = MacroConstants::BASE_CREDIT_SPREAD;
        $state->unemploymentRateEma = $state->nairu;
        $state->outputGapEma = 0.0;
        $state->commercialPropertyIndex = MacroConstants::CRE_BASELINE;

        // A quarter's pull toward a fundamental 7% off moves the index ~0.4 points; the log-mean correction alone,
        // ~0.015.
        $this->subsystem->calculateCommercialPropertyIndex($state, 0.25);

        $this->assertEqualsWithDelta(MacroConstants::CRE_BASELINE, $state->commercialPropertyIndex, 0.05);
    }

    private function creFundamentalTick(float $yield10y, float $expectedInflation): float
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('generateStandardNormal')->willReturn(0.0);

        $state = new MacroState();
        $state->yield10yEma = $yield10y;
        $state->tipsBreakevenEma = $expectedInflation;
        $state->inflationEma = $expectedInflation;
        $state->macroCreditSpreadEma = 0.02;
        $state->unemploymentRateEma = $state->nairu;
        $state->outputGapEma = 0.01;
        $state->commercialPropertyIndex = 100.0;
        (new AssetMarketSubsystem($math))->calculateCommercialPropertyIndex($state, 0.25);

        return $state->commercialPropertyIndex;
    }

    public function testCalculateCapitalMarketsDealIndex(): void
    {
        $state = new MacroState();
        $state->dealActivityIndex = 100.0;
        $state->equityRiskPremium = 0.035;
        $state->highYieldCreditSpread = 0.030;
        $state->marketVolatility = 0.12;

        $this->subsystem->calculateCapitalMarketsDealIndex($state, 0.25);
        $this->assertGreaterThan(50.0, $state->dealActivityIndex);
        $this->assertLessThan(250.0, $state->dealActivityIndex);
    }

    /**
     * The index settles where its stress legs put it, not at the hundred it is built from; the trend measures that
     * level over a deal cycle, and a firm reading deal flow at its trend reads no deviation at all.
     */
    public function testDealFlowIsReadAgainstItsOwnDecadeLongLevel(): void
    {
        $state = new MacroState();
        $state->dealActivityIndexTrend = 95.0;
        $dt = 0.25;

        $this->subsystem->calculateCapitalMarketsDealIndex($state, $dt);

        $pull = 1.0 - exp(-$dt / AssetMarketSubsystem::DEAL_ACTIVITY_TREND_YEARS);
        $this->assertEqualsWithDelta(95.0 + ($pull * ($state->dealActivityIndex - 95.0)), $state->dealActivityIndexTrend, 1e-9);

        $atTrend = new MacroStateDTO(dealActivityIndexEma: 95.0, dealActivityIndexTrend: 95.0);
        $this->assertSame(0.0, $atTrend->dealActivityDeviation());

        $busy = new MacroStateDTO(dealActivityIndexEma: 104.5, dealActivityIndexTrend: 95.0);
        $this->assertEqualsWithDelta(0.10, $busy->dealActivityDeviation(), 1e-12);
    }

    public function testCalculateTradeBalance(): void
    {
        $dt = 0.25;

        $stateAppreciated = new MacroState();
        $stateAppreciated->exchangeRateIndex = 115.0;
        $stateAppreciated->outputGap = 0.03;
        $stateAppreciated->tradeBalanceToGdp = MacroConstants::TRADE_BALANCE_BASELINE;

        $this->subsystem->calculateTradeBalance($stateAppreciated, $dt);
        $this->assertLessThan(MacroConstants::TRADE_BALANCE_BASELINE, $stateAppreciated->tradeBalanceToGdp, 'Currency appreciation and domestic demand absorption must worsen trade deficit');
    }

    public function testCalculateHousingStarts(): void
    {
        $dt = 0.25;

        $stateBoom = new MacroState();
        $stateBoom->residentialPropertyIndex = 130.0;
        $stateBoom->industrialMetalsIndex = 100.0;
        $stateBoom->realWageGap = 0.0;
        $stateBoom->yield10yEma = 0.035;
        $stateBoom->macroCreditSpread = 0.015;
        $stateBoom->inflationEma = 0.025;
        $stateBoom->sloosTighteningIndexEma = 0.0;
        $stateBoom->housingStartsIndex = 100.0;

        $this->subsystem->calculateHousingStarts($stateBoom, MacroConstants::TARGET_INFLATION, $dt);
        $this->assertGreaterThan(100.0, $stateBoom->housingStartsIndex, 'High Tobin Q and affordable mortgage finance must stimulate housing starts');
    }

    /**
     * Tobin's q reads each leg against the trend it has settled at: the residential index rests near 94, not on the 100
     * it is built from, and the metals index wherever its supercycle stands. At rest, starts sit at their baseline.
     */
    public function testHousingStartsRestAtTheirBaselineWhenPricesAndCostsSitOnTheirTrends(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $state = $this->neutralBuildingState();
        $state->riskNeutral10yEma = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;
        $state->residentialPropertyIndex = 94.0;
        $state->residentialWealthTrend = 94.0;
        $state->industrialMetalsIndex = 130.0;
        $state->industrialMetalsIndexTrend = 130.0;

        (new AssetMarketSubsystem($math))->calculateHousingStarts($state, MacroConstants::TARGET_INFLATION, 0.25);

        $this->assertEqualsWithDelta(MacroConstants::HOUSING_STARTS_BASELINE, $state->housingStartsIndex, 1e-9, 'At rest q is one and starts hold their baseline.');
    }

    /**
     * The trade balance closes on its target by exact exponential smoothing: two half steps land where one full step
     * does.
     */
    public function testTheTradeBalanceAdjustsByExactSmoothing(): void
    {
        $step = function (MacroState $state, float $dt): void {
            $state->exchangeRateIndex = 115.0;
            $state->outputGap = 0.03;
            $this->subsystem->calculateTradeBalance($state, $dt);
        };
        $once = new MacroState();
        $once->tradeBalanceToGdp = MacroConstants::TRADE_BALANCE_BASELINE;
        $twice = clone $once;

        $step($once, 0.5);
        $step($twice, 0.25);
        $step($twice, 0.25);

        $this->assertEqualsWithDelta($once->tradeBalanceToGdp, $twice->tradeBalanceToGdp, 1e-12);
        $this->assertNotEqualsWithDelta(MacroConstants::TRADE_BALANCE_BASELINE, $once->tradeBalanceToGdp, 1e-6, 'and it does move.');
    }

    /** Houses at their neutral price, built with materials and labour at their neutral real cost. */
    private function neutralBuildingState(): MacroState
    {
        $state = $this->neutralHousingState();
        $state->industrialMetalsIndex = MacroConstants::METALS_BASELINE;
        $state->realWageGap = 0.0;
        $state->sloosTighteningIndexEma = 0.0;
        $state->creditToGdpGapEma = 0.0;
        $state->housingStartsIndex = MacroConstants::HOUSING_STARTS_BASELINE;

        return $state;
    }

    /**
     * Replacement cost is priced in levels on both halves. With home prices, metals and the real wage all at their
     * baselines Tobin's q is exactly one, so however fast nominal pay is growing it adds no building-cost premium;
     * a real wage that stays above its productivity path does, for as long as it stays there.
     */
    public function testBuildingLabourIsARealWageLevelNotAWageGrowthRate(): void
    {
        $calm = $this->neutralBuildingState();
        $calm->wageGrowth = 0.035;
        $hotNominalPay = $this->neutralBuildingState();
        $hotNominalPay->wageGrowth = 0.07;
        $dearLabour = $this->neutralBuildingState();
        $dearLabour->realWageGap = 0.04;

        foreach ([$calm, $hotNominalPay, $dearLabour] as $state) {
            $this->subsystem->calculateHousingStarts($state, MacroConstants::TARGET_INFLATION, 0.25);
        }

        $this->assertEqualsWithDelta($calm->housingStartsIndex, $hotNominalPay->housingStartsIndex, 1e-9, 'Wage growth alone is not a building cost.');
        $this->assertLessThan($calm->housingStartsIndex, $dearLabour->housingStartsIndex, 'Dearer real labour raises replacement cost and lowers q.');

        // Labour is the non-materials share of the cost base: a 4% real wage gap moves replacement cost by that share
        // of e^0.04 - 1.
        $materials = AssetMarketSubsystem::REPLACEMENT_COST_MATERIALS_SHARE;
        $qShift = 1.0 / ($materials + (1.0 - $materials) * exp(0.04)) - 1.0;
        $expected = AssetMarketSubsystem::HOUSING_STARTS_KAPPA * AssetMarketSubsystem::HOUSING_STARTS_Q_SENSITIVITY * $qShift * 0.25;
        $this->assertEqualsWithDelta($expected, $dearLabour->housingStartsIndex - $calm->housingStartsIndex, 1e-9);
    }

    /**
     * Homes are bought on floating loans and priced on the rate expected over the years they are held: the expected
     * path (the ten-year without its term premium) sets the user cost; this quarter's policy rate and the term premium
     * do not.
     */
    public function testResidentialFundamentalPricesOffTheExpectedRatePath(): void
    {
        $base = new MacroState();
        $base->policyRateEma = 0.035;
        $base->riskNeutral10yEma = 0.035;
        $base->yield10yEma = 0.045;
        $base->unemploymentRateEma = $base->nairu;
        $base->inflationEma = MacroConstants::TARGET_INFLATION;
        $base->outputGapEma = 0.0;
        $base->residentialPropertyIndex = 100.0;

        $hikedToday = clone $base;
        $hikedToday->policyRateEma = 0.055;
        $hikedToday->yield10yEma = 0.060;

        $dearerPath = clone $base;
        $dearerPath->riskNeutral10yEma = 0.050;

        $this->subsystem->calculateResidentialPropertyIndex($base, MacroConstants::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($hikedToday, MacroConstants::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($dearerPath, MacroConstants::TARGET_INFLATION, 0.25);

        $this->assertEqualsWithDelta($base->residentialPropertyIndex, $hikedToday->residentialPropertyIndex, 1e-9, 'A rate expected to come back down does not reprice a home.');
        $this->assertLessThan($base->residentialPropertyIndex, $dearerPath->residentialPropertyIndex, 'Dearer money expected for years raises the user cost and lowers fundamental home prices.');
    }

    public function testNeutralUserCostMatchesTheNeutralRatePathPlusMortgageSpread(): void
    {
        $neutralPolicyRate = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;
        $expected = $neutralPolicyRate + MacroConstants::RESIDENTIAL_MORTGAGE_SPREAD + AssetMarketSubsystem::RESIDENTIAL_DEPRECIATION_TAX_RATE - MacroConstants::TARGET_INFLATION;

        $this->assertEqualsWithDelta($expected, AssetMarketSubsystem::RESIDENTIAL_NEUTRAL_USER_COST, 1e-12);
    }

    /**
     * Settles the exchange rate against a fixed policy rate, varying only the channel under test.
     */
    private function settledFx(array $overrides = []): float
    {
        $state = new MacroState();
        foreach ($overrides as $field => $value) {
            $state->$field = $value;
        }

        for ($i = 0; $i < 200; $i++) {
            $this->subsystem->calculateExchangeRate($state, 0.25);
        }

        return $state->exchangeRateIndex;
    }

    /**
     * Chen & Rogoff (2003) with the importer's sign. The district runs a structural trade deficit and its
     * output gap is dragged by dearer energy, so a rising import basket is a terms-of-trade LOSS and has to
     * weaken the currency. Getting this backwards would cushion every energy shock instead of compounding it.
     */
    public function testADearerImportBasketWeakensTheCurrency(): void
    {
        $neutral = $this->settledFx();

        $this->assertLessThan($neutral, $this->settledFx(['energyPriceIndexEma' => 150.0]), 'Dearer energy must weaken an importer.');
        $this->assertLessThan($neutral, $this->settledFx(['industrialMetalsIndexEma' => 150.0]), 'Dearer metals must weaken an importer.');

        // Cheaper commodities are the same trade in reverse, so the channel must run both ways.
        $this->assertGreaterThan($neutral, $this->settledFx(['energyPriceIndexEma' => 70.0]), 'A commodity dividend must strengthen it.');

        // Energy carries the heavier PPI weight, so it must move the currency more than metals do.
        $energyMove = $neutral - $this->settledFx(['energyPriceIndexEma' => 150.0]);
        $metalsMove = $neutral - $this->settledFx(['industrialMetalsIndexEma' => 150.0]);
        $this->assertGreaterThan($metalsMove, $energyMove, 'Energy is the larger share of the import basket.');
    }

    /**
     * Frankel (1979): the currency follows the real rate differential. A rate that only keeps pace with expected
     * inflation leaves it where it was; the same rise against anchored expectations strengthens it.
     */
    public function testTheCurrencyFollowsTheRealRateDifferential(): void
    {
        $base = $this->fundamental(0.04, 0.02);

        $this->assertEqualsWithDelta($base, $this->fundamental(0.05, 0.03), 1e-9, 'A rate rise matching expected inflation is not a stronger currency.');
        $this->assertEqualsWithDelta($base * exp(AssetMarketSubsystem::UIP_SENSITIVITY * 0.01), $this->fundamental(0.05, 0.02), 1e-9);

        $mainlandHot = AssetMarketSubsystem::exchangeRateFundamentalAt(0.04, 0.02, MacroConstants::MAINLAND_NEUTRAL_RATE, AssetMarketSubsystem::mainlandBreakeven(0.04), 100.0, 100.0, 0.0, 0.0);
        $this->assertGreaterThan($base, $mainlandHot, 'Higher mainland inflation at the same mainland rate is a lower mainland real rate.');
    }

    private function fundamental(float $policyRate, float $expectedInflation): float
    {
        return AssetMarketSubsystem::exchangeRateFundamentalAt($policyRate, $expectedInflation, MacroConstants::MAINLAND_NEUTRAL_RATE, AssetMarketSubsystem::mainlandBreakeven(MacroConstants::TARGET_INFLATION), 100.0, 100.0, 0.0, 0.0);
    }

    /**
     * Ranaldo & Soderlind (2010): the flight that compresses this sovereign's term premium buys its currency
     * first. One-sided at the same threshold, so ordinary volatility must not move the rate at all.
     */
    public function testPanicBidsTheCurrencyOnlyOnceFlightToSafetyStarts(): void
    {
        $neutral = $this->settledFx();
        $threshold = MacroConstants::FLIGHT_TO_SAFETY_VOL_THRESHOLD;

        $this->assertEqualsWithDelta($neutral, $this->settledFx(['marketVolatilityEma' => $threshold]), 1e-9, 'At the threshold the bid is exactly zero.');
        $this->assertEqualsWithDelta($neutral, $this->settledFx(['marketVolatilityEma' => $threshold - 0.05]), 1e-9, 'Below it, calm does not sell the currency.');
        $this->assertGreaterThan($neutral, $this->settledFx(['marketVolatilityEma' => 0.50]), 'A panic bids it.');
        $this->assertGreaterThan(
            $this->settledFx(['marketVolatilityEma' => 0.40]),
            $this->settledFx(['marketVolatilityEma' => 0.60]),
            'A worse panic bids it harder.',
        );
    }

    /**
     * The two channels oppose each other in the crisis that raises both, and neither is allowed to swamp the
     * rate: an energy shock big enough to cause the panic still leaves the currency weaker on net.
     */
    public function testAnEnergyDrivenPanicLeavesTheCurrencyWeakerOnNet(): void
    {
        $neutral = $this->settledFx();
        $stagflationaryPanic = $this->settledFx(['energyPriceIndexEma' => 180.0, 'marketVolatilityEma' => 0.30]);

        $this->assertLessThan($neutral, $stagflationaryPanic, 'The terms-of-trade loss outweighs a mild safe-haven bid.');
        $this->assertGreaterThan(60.0, $stagflationaryPanic, 'The rate stays inside its floor.');
        $this->assertLessThan(160.0, $stagflationaryPanic, 'The rate stays inside its ceiling.');
    }

    /**
     * The user cost subtracts EXPECTED inflation: a headline spike with expectations anchored leaves home prices alone.
     */
    public function testAHeadlineSpikeWithAnchoredExpectationsDoesNotMoveHomePrices(): void
    {
        $calm = $this->neutralHousingState();
        $spiked = $this->neutralHousingState();
        $spiked->inflation = 0.06;
        $spiked->inflationEma = 0.05;

        $this->subsystem->calculateResidentialPropertyIndex($calm, MacroConstants::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($spiked, MacroConstants::TARGET_INFLATION, 0.25);

        $this->assertEqualsWithDelta($calm->residentialPropertyIndex, $spiked->residentialPropertyIndex, 1e-9);
    }

    /** Higher expected inflation lowers the real cost of owning and lifts fundamental home prices. */
    public function testHigherExpectedInflationLowersTheUserCostAndLiftsHomePrices(): void
    {
        $anchored = $this->neutralHousingState();
        $unanchored = $this->neutralHousingState();

        $this->subsystem->calculateResidentialPropertyIndex($anchored, MacroConstants::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($unanchored, 0.03, 0.25);

        $this->assertGreaterThan($anchored->residentialPropertyIndex, $unanchored->residentialPropertyIndex);
    }

    public function testImmigrationBidsUpTheHouseFundamentalOneForOne(): void
    {
        $base = $this->neutralHousingState();
        $grown = $this->neutralHousingState();
        $grown->immigrationPopulationShift = 0.05;

        $this->subsystem->calculateResidentialPropertyIndex($base, MacroConstants::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($grown, MacroConstants::TARGET_INFLATION, 0.25);

        // The index closes its reversion's share of the step in the fundamental, which is the population's log times
        // Saiz's elasticity.
        $expected = (1.0 - exp(-AssetMarketSubsystem::RESIDENTIAL_MEAN_REVERSION * 0.25)) * AssetMarketSubsystem::IMMIGRATION_HOUSING_ELASTICITY * 0.05;
        $this->assertEqualsWithDelta($expected, log($grown->residentialPropertyIndex / $base->residentialPropertyIndex), 1e-12);
    }

    /**
     * A tax on foreign home buyers takes the part of values their demand holds up: British Columbia's 15% leaves the
     * fundamental 6% lower, foreign buyers' share falling from 9.5% to 1.7% of purchases; the effect saturates once
     * they have left, and the index closes its reversion's share of the step as it does for immigration.
     */
    public function testAForeignBuyerTaxTakesThePartOfValuesTheirDemandHoldsUp(): void
    {
        $this->assertSame(1.0, AssetMarketSubsystem::foreignBuyerPriceFactor(0.0));
        $this->assertEqualsWithDelta(0.94, AssetMarketSubsystem::foreignBuyerPriceFactor(0.15), 5e-4);
        $this->assertEqualsWithDelta(0.017 / 0.095, exp(-AssetMarketSubsystem::FOREIGN_BUYER_TAX_DEMAND_SEMI_ELASTICITY * 0.15), 5e-4);
        $this->assertEqualsWithDelta(exp(-AssetMarketSubsystem::FOREIGN_BUYER_HOUSING_VALUE_SHARE), AssetMarketSubsystem::foreignBuyerPriceFactor(0.60), 1e-3, 'At Singapore\'s 60% almost every foreign buyer has gone.');
        $this->assertGreaterThan(AssetMarketSubsystem::foreignBuyerPriceFactor(0.60) - AssetMarketSubsystem::foreignBuyerPriceFactor(0.30), AssetMarketSubsystem::foreignBuyerPriceFactor(0.0) - AssetMarketSubsystem::foreignBuyerPriceFactor(0.15), 'The first points of tax do the most.');

        $base = $this->neutralHousingState();
        $taxed = $this->neutralHousingState();
        $taxed->foreignBuyerTaxRate = 0.15;
        $this->subsystem->calculateResidentialPropertyIndex($base, MacroConstants::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($taxed, MacroConstants::TARGET_INFLATION, 0.25);
        $expected = (1.0 - exp(-AssetMarketSubsystem::RESIDENTIAL_MEAN_REVERSION * 0.25)) * log(AssetMarketSubsystem::foreignBuyerPriceFactor(0.15));
        $this->assertEqualsWithDelta($expected, log($taxed->residentialPropertyIndex / $base->residentialPropertyIndex), 1e-12);
    }

    /**
     * The green belt runs from the 10th percentile of English planning authorities' refusal rates (the founding plan)
     * to the 90th, and raises the price elasticity by Hilber & Vermeulen's boom or bust coefficient per standard
     * deviation of refusal rate between them.
     */
    public function testTheGreenBeltSpansTheTenthToTheNinetiethPercentileOfEnglishPlanning(): void
    {
        $this->assertEqualsWithDelta(0.254 - (1.2816 * 0.087), AssetMarketSubsystem::planningRefusalRate(0.0), 1e-12);
        $this->assertEqualsWithDelta(0.254 + (1.2816 * 0.087), AssetMarketSubsystem::planningRefusalRate(1.0), 1e-12);
        $this->assertEqualsWithDelta(0.254, AssetMarketSubsystem::planningRefusalRate(0.5), 1e-12);

        $this->assertSame(0.0, AssetMarketSubsystem::greenBeltElasticityLift(0.0, 0.05), 'The founding plan is the calibrated economy.');
        $this->assertEqualsWithDelta(2.0 * 1.2816 * 0.267, AssetMarketSubsystem::greenBeltElasticityLift(1.0, 0.05), 1e-12);
        $this->assertEqualsWithDelta(2.0 * 1.2816 * 0.152, AssetMarketSubsystem::greenBeltElasticityLift(1.0, -0.05), 1e-12, 'Supply binds less in a slump.');
        $this->assertEqualsWithDelta(1.0 / (1.0 + (2.0 * 1.2816 * 0.267)), AssetMarketSubsystem::greenBeltSupplyResponse(1.0), 1e-12);
    }

    /**
     * Under a green belt the same boom lifts the fundamental further, and the same slump takes it lower, but by less
     * than the boom adds.
     */
    public function testAGreenBeltDeepensBoomsMoreThanSlumps(): void
    {
        $move = function (float $gap, float $stringency): float {
            $state = $this->neutralHousingState();
            $state->outputGapEma = $gap;
            $state->greenBeltStringency = $stringency;
            $this->subsystem->calculateResidentialPropertyIndex($state, MacroConstants::TARGET_INFLATION, 0.25);

            return log($state->residentialPropertyIndex / 100.0);
        };

        $boomLift = $move(0.03, 1.0) - $move(0.03, 0.0);
        $slumpDrop = $move(-0.03, 0.0) - $move(-0.03, 1.0);
        $this->assertGreaterThan(0.0, $boomLift);
        $this->assertGreaterThan(0.0, $slumpDrop);
        $this->assertGreaterThan($slumpDrop, $boomLift);
        $this->assertSame($move(0.0, 0.0), $move(0.0, 1.0), 'At trend a green belt moves nothing: the index has no trend income to amplify.');
    }

    /** Immigration bids up a green-belted District harder: Saiz's one-for-one plus the green belt's lift. */
    public function testAGreenBeltRaisesThePriceOfImmigration(): void
    {
        $open = $this->neutralHousingState();
        $open->immigrationPopulationShift = 0.05;
        $belted = $this->neutralHousingState();
        $belted->immigrationPopulationShift = 0.05;
        $belted->greenBeltStringency = 1.0;

        $this->subsystem->calculateResidentialPropertyIndex($open, MacroConstants::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($belted, MacroConstants::TARGET_INFLATION, 0.25);

        $expected = (1.0 - exp(-AssetMarketSubsystem::RESIDENTIAL_MEAN_REVERSION * 0.25)) * AssetMarketSubsystem::greenBeltElasticityLift(1.0, 0.05) * 0.05;
        $this->assertEqualsWithDelta($expected, log($belted->residentialPropertyIndex / $open->residentialPropertyIndex), 1e-12);
    }

    /** Builders answer a price boom less under a green belt, in proportion to the price's stronger answer to demand. */
    public function testAGreenBeltSlowsBuildingInABoom(): void
    {
        $starts = function (float $price, float $stringency): float {
            $state = $this->neutralBuildingState();
            $state->residentialPropertyIndex = $price;
            $state->greenBeltStringency = $stringency;
            $this->subsystem->calculateHousingStarts($state, MacroConstants::TARGET_INFLATION, 0.25);

            return $state->housingStartsIndex;
        };

        $openResponse = $starts(130.0, 0.0) - $starts(100.0, 0.0);
        $beltedResponse = $starts(130.0, 1.0) - $starts(100.0, 1.0);
        $this->assertGreaterThan(0.0, $beltedResponse);
        $this->assertEqualsWithDelta(AssetMarketSubsystem::greenBeltSupplyResponse(1.0), $beltedResponse / $openResponse, 1e-9);
    }

    private function neutralHousingState(): MacroState
    {
        $state = new MacroState();
        $state->yield10yEma = 0.040;
        $state->unemploymentRateEma = $state->nairu;
        $state->inflationEma = MacroConstants::TARGET_INFLATION;
        $state->outputGapEma = 0.0;
        $state->residentialPropertyIndex = 100.0;

        return $state;
    }

    /**
     * Pastor & Veronesi (2013): political uncertainty is priced as volatility. It enters the anchor, so it is budgeted
     * like every other driver.
     */
    public function testPolicyUncertaintyLiftsTheVolatilityAnchor(): void
    {
        // Strip the process to its anchor: no jumps, and the variance step lands exactly on the long-run target.
        $math = new class extends MathUtility {
            public function calculateSVJJJumps(float $lambda, float $pUp, float $etaUp, float $etaDown, float $muV, float $dt): array
            {
                return ['price_multiplier' => 1.0, 'var_jump' => 0.0, 'shock_pct' => 0.0];
            }
            public function calculateQEVarianceStep(float $currentVar, float $theta, float $kappa, float $sigma, float $dt, ?float $varianceShock = null): float
            {
                return $theta;
            }
        };
        $subsystem = new AssetMarketSubsystem($math);

        $neutral = new MacroState();
        $doubled = new MacroState();
        $doubled->policyUncertaintyIndexEma = 2.0 * MacroConstants::EPU_BASELINE;

        $volNeutral = $subsystem->calculateMarketVolatility($neutral, 0.01);
        $volDoubled = $subsystem->calculateMarketVolatility($doubled, 0.01);

        $this->assertGreaterThan($volNeutral, $volDoubled);
        // The anchor variance carries a fixed jump drag, so compare the anchors themselves: add the drag back.
        $drag = AssetMarketSubsystem::jumpVarianceDrag();
        $anchorNeutral = sqrt(($volNeutral ** 2) + $drag);
        $anchorDoubled = sqrt(($volDoubled ** 2) + $drag);
        $expectedRatio = exp(log(2.0) * AssetMarketSubsystem::MACRO_VOL_EPU_SENSITIVITY);
        $this->assertEqualsWithDelta($expectedRatio, $anchorDoubled / $anchorNeutral, 0.01, 'A doubling of the index lifts the anchor by the exponential of the sensitivity.');
    }

    /**
     * Volatility loads on the excess bond premium, never on the credit spread. The spread reads volatility, and
     * with volatility reading the spread back the two held each other at their caps for four years of a slump.
     */
    public function testTheVolatilityAnchorReadsThePremiumNotTheSpread(): void
    {
        $math = new class extends MathUtility {
            public function calculateSVJJJumps(float $lambda, float $pUp, float $etaUp, float $etaDown, float $muV, float $dt): array
            {
                return ['price_multiplier' => 1.0, 'var_jump' => 0.0, 'shock_pct' => 0.0];
            }
            public function calculateQEVarianceStep(float $currentVar, float $theta, float $kappa, float $sigma, float $dt, ?float $varianceShock = null): float
            {
                return $theta;
            }
        };
        $subsystem = new AssetMarketSubsystem($math);
        $drag = AssetMarketSubsystem::jumpVarianceDrag();
        $anchor = fn(float $vol): float => sqrt(($vol ** 2) + $drag);

        $neutral = new MacroState();
        $wideSpread = new MacroState();
        $wideSpread->macroCreditSpread = CreditRisk::MAX_CREDIT_SPREAD;
        $premium = new MacroState();
        $premium->excessBondPremium = 0.010;

        $volNeutral = $subsystem->calculateMarketVolatility($neutral, 0.01);

        $this->assertSame($volNeutral, $subsystem->calculateMarketVolatility($wideSpread, 0.01), 'A spread at its cap, with no premium behind it, leaves volatility alone.');
        $this->assertEqualsWithDelta(
            exp(0.010 * AssetMarketSubsystem::MACRO_VOL_PREMIUM_SENSITIVITY),
            $anchor($subsystem->calculateMarketVolatility($premium, 0.01)) / $anchor($volNeutral),
            0.01,
            'A 100 bps premium lifts the anchor by the exponential of its loading (~1.37x).',
        );
    }

    /**
     * Alesina & Perotti (1995): fiscal risk is default and inflation risk, and sells the currency rather than earning
     * carry.
     */
    public function testFiscalRiskSellsTheCurrency(): void
    {
        $neutral = $this->settledFx();
        $stressed = $this->settledFx(['sovereignRiskSpreadEma' => 0.02]);

        $this->assertLessThan($neutral, $stressed);
        $this->assertEqualsWithDelta(exp(-0.02 * AssetMarketSubsystem::FX_FISCAL_RISK_SENSITIVITY), $stressed / $neutral, 0.002, 'Two hundred basis points of sovereign premium cost about four percent of the currency.');
    }

    /**
     * Allied defence spending is its customers' budgets: a build-up fades on the fitted SIPRI half-life
     * (1.4 years) toward a trend set below the baseline by the mobilisations' level shift and the Jensen term.
     */
    public function testAnAlliedBuildUpFadesOnItsFittedHalfLife(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };
        $subsystem = new AssetMarketSubsystem($quiet);
        $state = new MacroState();
        $state->alliedDefenseSpendingIndex = 150.0;

        $kappa = AssetMarketSubsystem::ALLIED_DEFENSE_MEAN_REVERSION;
        // The quiet path's log level: the baseline less the mobilisations' log drift, which leaves the jumping
        // process's LOG averaging ln(baseline), the centre its log reader (alliedDefenseGap) measures from.
        $logTrend = log(MacroConstants::ALLIED_DEFENSE_BASELINE)
            - (AssetMarketSubsystem::ALLIED_MOBILISATION_PROBABILITY * AssetMarketSubsystem::ALLIED_MOBILISATION_MEAN / $kappa);

        $dt = 1.0 / 360.0;
        $halfLife = log(2.0) / $kappa;
        for ($i = 0, $n = (int) round($halfLife / $dt); $i < $n; $i++) {
            $subsystem->calculateAlliedDefenseSpending($state, $dt);
        }
        $elapsed = round($halfLife / $dt) * $dt;
        $this->assertEqualsWithDelta((log(150.0) - $logTrend) * exp(-$kappa * $elapsed), log($state->alliedDefenseSpendingIndex) - $logTrend, 1e-9);
        $this->assertLessThan(MacroConstants::ALLIED_DEFENSE_BASELINE, exp($logTrend), 'Quiet years sit under the trend the mobilisations average up to.');
    }

    /** A mobilisation lifts allied spending by the fitted jump at once, before any reversion to speak of. */
    public function testAnAlliedMobilisationArrivesWhole(): void
    {
        $mobilised = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return true;
            }
        };
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };
        $war = new MacroState();
        $peace = new MacroState();
        (new AssetMarketSubsystem($mobilised))->calculateAlliedDefenseSpending($war, 1.0 / 360.0);
        (new AssetMarketSubsystem($quiet))->calculateAlliedDefenseSpending($peace, 1.0 / 360.0);

        $this->assertEqualsWithDelta(exp(AssetMarketSubsystem::ALLIED_MOBILISATION_MEAN), $war->alliedDefenseSpendingIndex / $peace->alliedDefenseSpendingIndex, 1e-12);
        $this->assertGreaterThan(1.7, $war->alliedDefenseSpendingIndex / $peace->alliedDefenseSpendingIndex, 'A Korea-scale mobilisation: allied budgets up by three quarters.');
    }

    public function testAStormSeasonDentsTheHousingStock(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $subsystem = new AssetMarketSubsystem($quiet);

        $averageHousing = new MacroState();
        $stormyHousing = new MacroState();
        $stormyHousing->catastropheLossIndexEma = 3.0;
        for ($i = 0; $i < 200; $i++) {
            $subsystem->calculateResidentialPropertyIndex($averageHousing, MacroConstants::TARGET_INFLATION, 0.25);
            $subsystem->calculateResidentialPropertyIndex($stormyHousing, MacroConstants::TARGET_INFLATION, 0.25);
        }
        $expectedRatio = 1.0 - (AssetMarketSubsystem::CATASTROPHE_PROPERTY_DAMAGE_SHARE * 2.0);
        $this->assertEqualsWithDelta($expectedRatio, $stormyHousing->residentialPropertyIndex / $averageHousing->residentialPropertyIndex, 0.005, 'Two units of excess burden destroy two percent of the stock.');
    }

    // --- The Mainland ---

    /** Advances the clock one quarter at a time, the way the engine's clock reaches each quarter's turn. */
    private function runMainlandQuarters(AssetMarketSubsystem $subsystem, MacroState $state, int $quarters, ?callable $beforeEach = null): void
    {
        for ($q = 0; $q < $quarters; $q++) {
            if ($beforeEach !== null) {
                $beforeEach($state);
            }
            $state->totalTime += 0.25;
            $subsystem->calculateForeignEconomy($state, 0.25);
            $state->foreignOutputGapEma = $state->foreignOutputGap; // the engine's EMA step, stood in for here
        }
    }

    /**
     * Where a mainland without shocks settles above its mean: a quarter no crash strikes keeps the crash's expected
     * loss, which the AR(2) carries at its long-run multiplier (~0.22pp).
     */
    private static function quietMainlandLift(): float
    {
        $crashCover = AssetMarketSubsystem::MAINLAND_CRASH_INTENSITY / AssetMarketSubsystem::MAINLAND_QUARTERS_PER_YEAR * AssetMarketSubsystem::MAINLAND_CRASH_MEAN;

        return $crashCover / (1.0 - AssetMarketSubsystem::MAINLAND_GAP_AR1 - AssetMarketSubsystem::MAINLAND_GAP_AR2);
    }

    /** The mainland gap is the fitted US cycle: the AR(2)'s stationary spread and its persistence a year out. */
    public function testTheMainlandGapHasTheFittedUsSpreadAndPersistence(): void
    {
        mt_srand(20260928);
        $subsystem = new AssetMarketSubsystem(new MathUtility()); // real draws: the fixture's stub is silent
        $state = new MacroState();
        $state->outputGapEma = 0.0;
        $samples = [];
        for ($q = 0; $q < 40000; $q++) {
            $this->runMainlandQuarters($subsystem, $state, 1);
            if ($q >= 40) {
                $samples[] = $state->foreignOutputGap;
            }
        }
        $count = count($samples);
        $mean = array_sum($samples) / $count;
        $deviations = array_map(static fn(float $x): float => $x - $mean, $samples);
        $variance = array_sum(array_map(static fn(float $d): float => $d * $d, $deviations));
        $covariance = array_sum(array_map(
            static fn(float $d, float $yearBack): float => $d * $yearBack,
            array_slice($deviations, 4),
            array_slice($deviations, 0, $count - 4),
        ));

        $a1 = AssetMarketSubsystem::MAINLAND_GAP_AR1;
        $a2 = AssetMarketSubsystem::MAINLAND_GAP_AR2;
        // Yule-Walker: gamma_0 = sigma^2 / (1 - a1 rho_1 - a2 rho_2).
        $expectedSd = AssetMarketSubsystem::mainlandGapInnovationSd()
            / sqrt(1.0 - ($a1 * TimeSeries::calculateAr2Autocorrelation($a1, $a2, 1)) - ($a2 * TimeSeries::calculateAr2Autocorrelation($a1, $a2, 2)));
        $this->assertEqualsWithDelta(0.0162, $expectedSd, 0.001, 'The fitted process keeps the spread of the CBO gap it was fitted on, 1.62% (1985-2019).');
        $this->assertEqualsWithDelta(0.0, $mean, 0.002, 'With the district at trend the mainland cycle averages nothing.');
        $this->assertEqualsWithDelta($expectedSd, sqrt($variance / $count), 0.001, 'Its spread is the fitted AR(2)\'s, ~1.65%.');
        $this->assertEqualsWithDelta(TimeSeries::calculateAr2Autocorrelation($a1, $a2, 4), $covariance / $variance, 0.04, 'and a year on it still carries ~0.72 of itself (the CBO gap 0.66).');
    }

    /**
     * The quarter's gap innovation is handed back over its sd, the region's common demand shock; between turns there is
     * none.
     */
    public function testTheMainlandHandsBackItsGapSurpriseAtTheTurn(): void
    {
        $draws = new class extends MathUtility {
            public int $calls = 0;
            public function generateStandardNormal(): float
            {
                // The gap's draw leads each quarter; core inflation, the rate, a crash's size and the rest of the world
                // follow.
                return $this->calls++ % 5 === 0 ? 1.5 : 0.0;
            }

            public function generateUniform(): float
            {
                return 1.0; // no crash strikes
            }
        };
        $subsystem = new AssetMarketSubsystem($draws);
        $state = new MacroState();

        $state->totalTime = 0.10;
        $this->assertSame(0.0, $subsystem->calculateForeignEconomy($state, 0.01), 'Inside a quarter there is no surprise.');

        $state->totalTime = 0.25;
        $crashCover = AssetMarketSubsystem::MAINLAND_CRASH_INTENSITY / AssetMarketSubsystem::MAINLAND_QUARTERS_PER_YEAR * AssetMarketSubsystem::MAINLAND_CRASH_MEAN;
        $shock = (AssetMarketSubsystem::MAINLAND_GAP_SIGMA * 1.5) + $crashCover;
        $surprise = $shock / AssetMarketSubsystem::mainlandGapInnovationSd();
        $this->assertEqualsWithDelta($surprise, $subsystem->calculateForeignEconomy($state, 0.01), 1e-12, 'The turn hands back the gap\'s innovation over its sd,');
        $this->assertEqualsWithDelta($shock, $state->foreignOutputGap, 1e-12, 'the innovation the mainland gap took: its draw, and the crash cover a quiet quarter earns.');

        $state->totalTime = 1.25;
        $this->assertEqualsWithDelta(4.0 * $surprise, $subsystem->calculateForeignEconomy($state, 1.0), 1e-12, 'A year-long step sums its four quarters\' surprises.');
    }

    /**
     * A crash strikes the mainland whole in its quarter, about 2008Q4's 2.3pp, and the district takes it as a surprise
     * of over four sd; the Fed starts cutting at once.
     */
    public function testAMainlandCrashLandsWholeAndReachesTheDistrictAsItsSurprise(): void
    {
        $draws = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            public function generateUniform(): float
            {
                return 0.0; // a crash strikes
            }
        };
        $subsystem = new AssetMarketSubsystem($draws);
        $state = new MacroState();
        $state->totalTime = 0.25;
        $surprise = $subsystem->calculateForeignEconomy($state, 0.01);

        $median = AssetMarketSubsystem::MAINLAND_CRASH_MEAN * exp(-(AssetMarketSubsystem::MAINLAND_CRASH_LOG_SD ** 2) / 2.0);
        $crashCover = AssetMarketSubsystem::MAINLAND_CRASH_INTENSITY / AssetMarketSubsystem::MAINLAND_QUARTERS_PER_YEAR * AssetMarketSubsystem::MAINLAND_CRASH_MEAN;
        $this->assertEqualsWithDelta($crashCover - $median, $state->foreignOutputGap, 1e-12, 'The median crash takes 2.2pp off the gap in one quarter.');
        $this->assertEqualsWithDelta($state->foreignOutputGap / AssetMarketSubsystem::mainlandGapInnovationSd(), $surprise, 1e-12, 'The district takes the same innovation over its sd,');
        $this->assertLessThan(-4.0, $surprise, 'a quarter no Gaussian of the fitted sd produces in a thousand years.');
        $this->assertLessThan(MacroConstants::MAINLAND_NEUTRAL_RATE, $state->foreignPolicyRate, 'The Fed cuts in the same quarter.');
    }

    /** Crashes and the Gaussian part together are the regression's whole residual, 0.490pp. */
    public function testTheMainlandsCrashesKeepTheFittedInnovationSd(): void
    {
        $this->assertEqualsWithDelta(0.00490, AssetMarketSubsystem::mainlandGapInnovationSd(), 0.00002);
        $this->assertGreaterThan(5.0, AssetMarketSubsystem::MAINLAND_CRASH_MEAN / AssetMarketSubsystem::MAINLAND_GAP_SIGMA, 'A crash is a quarter the Gaussian part does not produce.');
    }

    /** The mainland's statistics and its rate decision arrive at the quarter's turn, not between. */
    public function testTheMainlandMovesOnlyAtTheTurnOfAQuarter(): void
    {
        $draws = new class extends MathUtility {
            public int $calls = 0;
            public function generateStandardNormal(): float
            {
                $this->calls++;
                return 0.0;
            }

            public function generateUniform(): float
            {
                return 1.0; // no crash strikes
            }
        };
        $subsystem = new AssetMarketSubsystem($draws);
        $state = new MacroState();
        $state->outputGapEma = 0.04;

        $state->totalTime = 0.10;
        $subsystem->calculateForeignEconomy($state, 0.01);
        $this->assertSame(0, $draws->calls, 'Inside a quarter nothing is released.');
        $this->assertSame(0.0, $state->foreignOutputGap);

        $state->totalTime = 0.25;
        $subsystem->calculateForeignEconomy($state, 0.01);
        $this->assertSame(5, $draws->calls, 'The turn releases the gap, core inflation, the rate decision, a crash\'s size and the rest of the world once.');
        $this->assertGreaterThan(0.0, $state->foreignOutputGap, 'and the district\'s boom shows in the mainland gap.');

        $state->totalTime = 1.25;
        $subsystem->calculateForeignEconomy($state, 1.0);
        $this->assertSame(25, $draws->calls, 'A year-long step releases four quarters.');
    }

    public function testTheDistrictsBoomSpillsIntoTheMainlandOnlyInPart(): void
    {
        $state = new MacroState();
        $state->outputGapEma = 0.04;
        $this->runMainlandQuarters($this->subsystem, $state, 400);

        $this->assertEqualsWithDelta((0.04 * AssetMarketSubsystem::FOREIGN_IMPORT_SPILLOVER) + self::quietMainlandLift(), $state->foreignOutputGap, 1e-6, 'The mainland feels the district\'s imports, not its whole cycle.');
        $this->assertGreaterThan(MacroConstants::MAINLAND_NEUTRAL_RATE, $state->foreignPolicyRate, 'and the Fed leans against the demand it does feel.');
        $this->assertEqualsWithDelta(AssetMarketSubsystem::WORLD_MAINLAND_LOADING * $state->foreignOutputGap, $state->worldActivityGap, 1e-9, 'With no shocks of its own the rest of the world carries the mainland cycle at its loading.');
        $restOfWorld = 1.0 - AssetMarketSubsystem::DOMESTIC_DEMAND_WEIGHT - AssetMarketSubsystem::MAINLAND_DEMAND_WEIGHT;
        $expectedGlobal = (AssetMarketSubsystem::DOMESTIC_DEMAND_WEIGHT * $state->outputGap) + (AssetMarketSubsystem::MAINLAND_DEMAND_WEIGHT * $state->foreignOutputGap) + ($restOfWorld * $state->worldActivityGap);
        $this->assertEqualsWithDelta($expectedGlobal, $state->globalDemandGap, 1e-9, 'World demand weighs the three economies at their GDP shares.');
        $this->assertEqualsWithDelta(0.63, $restOfWorld, 0.01, 'Most of the world is neither the District nor the mainland.');
    }

    /** The fitted AR(2)'s stationary sd, as the constant the world loading divides by states it. */
    public function testTheMainlandStationarySdIsWhatItsAr2Implies(): void
    {
        $a1 = AssetMarketSubsystem::MAINLAND_GAP_AR1;
        $a2 = AssetMarketSubsystem::MAINLAND_GAP_AR2;
        $implied = AssetMarketSubsystem::mainlandGapInnovationSd() * sqrt((1.0 - $a2) / ((1.0 + $a2) * (((1.0 - $a2) ** 2) - ($a1 ** 2))));
        $this->assertEqualsWithDelta($implied, AssetMarketSubsystem::MAINLAND_GAP_STATIONARY_SD, 0.00002);

        $ownSd = AssetMarketSubsystem::WORLD_GAP_SD * sqrt(1.0 - (AssetMarketSubsystem::WORLD_MAINLAND_CORRELATION ** 2));
        $this->assertEqualsWithDelta($ownSd * sqrt(1.0 - (AssetMarketSubsystem::WORLD_GAP_AR1 ** 2)), AssetMarketSubsystem::WORLD_GAP_SIGMA, 0.00002);
    }

    /**
     * The rest of the world is a one-factor model on the mainland's cycle: over a long run its spread, its persistence
     * and its co-movement with the mainland are the G6 industrial-production gap's (sd 1.8%, AR(1) ~0.85, correlation
     * 0.51).
     */
    public function testTheRestOfTheWorldHasTheFittedSpreadAndCoMovement(): void
    {
        mt_srand(20261014);
        $subsystem = new AssetMarketSubsystem(new MathUtility());
        $state = new MacroState();
        $state->outputGapEma = 0.0;
        $world = [];
        $mainland = [];
        for ($q = 0; $q < 20000; $q++) {
            $this->runMainlandQuarters($subsystem, $state, 1);
            if ($q >= 200) {
                $world[] = $state->worldActivityGap;
                $mainland[] = $state->foreignOutputGap;
            }
        }
        $mean = static fn(array $x): float => array_sum($x) / count($x);
        $cov = static function (array $x, array $y) use ($mean): float {
            $mx = $mean($x);
            $my = $mean($y);
            $s = 0.0;
            foreach ($x as $i => $v) {
                $s += ($v - $mx) * ($y[$i] - $my);
            }

            return $s / count($x);
        };
        $sd = sqrt($cov($world, $world));

        $this->assertEqualsWithDelta(AssetMarketSubsystem::WORLD_GAP_SD, $sd, 0.0015);
        $this->assertEqualsWithDelta(AssetMarketSubsystem::WORLD_MAINLAND_CORRELATION, $cov($world, $mainland) / ($sd * sqrt($cov($mainland, $mainland))), 0.05);
        $this->assertEqualsWithDelta(0.87, $cov(array_slice($world, 1), array_slice($world, 0, -1)) / ($sd ** 2), 0.03, 'Its own AR(1) at 0.85 plus the more persistent mainland part.');
    }

    /**
     * The Taylor principle: held a point higher, four-quarter core inflation takes the funds rate up by the fitted 1.66
     * points.
     */
    public function testTheFedAnswersInflationMoreThanOneForOne(): void
    {
        $settle = function (float $inflation): array {
            $state = new MacroState();
            $state->outputGapEma = 0.0;
            $this->runMainlandQuarters($this->subsystem, $state, 200, static function (MacroState $s) use ($inflation): void {
                $s->foreignCoreInflation = $s->foreignCoreInflationLag1 = $s->foreignCoreInflationLag2 = $s->foreignCoreInflationLag3 = $inflation;
            });
            $yearOnYear = ($state->foreignCoreInflation + $state->foreignCoreInflationLag1 + $state->foreignCoreInflationLag2 + $state->foreignCoreInflationLag3) / 4.0;

            return [$yearOnYear, $state->foreignPolicyRate];
        };
        [$atTarget, $neutral] = $settle(MacroConstants::TARGET_INFLATION);
        [$above, $tight] = $settle(MacroConstants::TARGET_INFLATION + 0.01);

        // A quiet mainland sits a little above its mean (quietMainlandLift()), and its inflation by the slope on that.
        $quietAnswer = (AssetMarketSubsystem::FED_RULE_GAP_RESPONSE * self::quietMainlandLift())
            + (AssetMarketSubsystem::FED_RULE_INFLATION_RESPONSE * ($atTarget - MacroConstants::TARGET_INFLATION));
        $this->assertEqualsWithDelta(MacroConstants::MAINLAND_NEUTRAL_RATE + $quietAnswer, $neutral, 1e-9, 'At target the Fed rests at neutral, but for its answer to the quiet mainland.');
        $response = ($tight - $neutral) / ($above - $atTarget);
        $this->assertEqualsWithDelta(AssetMarketSubsystem::FED_RULE_INFLATION_RESPONSE, $response, 1e-6);
        $this->assertGreaterThan(1.0, $response, 'Real rates rise when inflation does.');
    }

    public function testTheFedStopsAtTheLowerBound(): void
    {
        $state = new MacroState();
        $state->outputGapEma = 0.0;
        $this->runMainlandQuarters($this->subsystem, $state, 60, static function (MacroState $s): void {
            $s->foreignOutputGap = $s->foreignOutputGapLag = -0.06;
        });

        $this->assertSame(MacroConstants::EFFECTIVE_LOWER_BOUND, $state->foreignPolicyRate, 'A deep mainland slump takes the funds rate to the floor and no further.');
    }

    /**
     * Dornbusch (1976): the currency is an asset price. A rate move reprices it at once by the parity loading, and only
     * its departure from that fundamental decays, at the purchasing-power reversion speed.
     */
    public function testTheCurrencyJumpsWithItsFundamentalAndOnlyItsDeviationDecays(): void
    {
        $state = new MacroState();
        $state->policyRate = $state->policyRateShadow = MacroConstants::MAINLAND_NEUTRAL_RATE;
        $state->foreignPolicyRate = MacroConstants::MAINLAND_NEUTRAL_RATE;
        $state->exchangeRateDeviation = 1.0;
        $this->subsystem->calculateExchangeRate($state, 0.001);
        $before = $state->exchangeRateIndex;

        $state->policyRateShadow += 0.01;
        $this->subsystem->calculateExchangeRate($state, 0.001);
        $this->assertEqualsWithDelta(exp(AssetMarketSubsystem::UIP_SENSITIVITY * 0.01), $state->exchangeRateIndex / $before, 1e-4, 'A point of rate differential is 3% on the currency within the tick.');
        $this->assertEqualsWithDelta(AssetMarketSubsystem::exchangeRateFundamental($state) * $state->exchangeRateDeviation, $state->exchangeRateIndex, 1e-9);

        // A 10% overvaluation against the fundamental decays like the log-OU it is.
        $state->exchangeRateDeviation = 1.10;
        $this->subsystem->calculateExchangeRate($state, 1.0);
        $decay = exp(-AssetMarketSubsystem::EXCHANGE_RATE_MEAN_REVERSION);
        // The log mean that leaves the level averaging one: -sigma^2 / 4 kappa.
        $itoMean = -(AssetMarketSubsystem::exchangeRateDeviationVolatility() ** 2) / (4.0 * AssetMarketSubsystem::EXCHANGE_RATE_MEAN_REVERSION);
        $this->assertEqualsWithDelta((log(1.10) * $decay) + ($itoMean * (1.0 - $decay)), log($state->exchangeRateDeviation), 1e-12);
    }

    /**
     * Parity reads the short rate the market expects: a meeting that rounds it to the step everyone saw coming moves
     * neither the currency nor the slope.
     */
    public function testAnExpectedDecisionMovesNeitherTheCurrencyNorTheSlope(): void
    {
        $before = new MacroState();
        $before->policyRate = 0.03;
        $before->policyRateShadow = 0.0324;
        $decided = clone $before;
        $decided->policyRate = 0.0325;

        $this->assertSame(AssetMarketSubsystem::exchangeRateFundamental($before), AssetMarketSubsystem::exchangeRateFundamental($decided));

        $monetary = new MonetaryPolicySubsystem(new MathUtility());
        foreach ([$before, $decided] as $state) {
            $monetary->applyYieldCurve($state, $monetary->calculateYieldCurve($state, MacroConstants::TARGET_INFLATION, MacroConstants::BASE_NATURAL_RATE), 0.01);
        }
        $this->assertSame($before->nsSlope, $decided->nsSlope);
        $this->assertSame($before->structuralSlope, $decided->structuralSlope);
    }

    /**
     * BIS effective rates: a small open economy's currency moves about 2.7% a quarter. The fundamental carries its own
     * share of that, so the deviation's quarterly variance is only the rest, and a unit draw moves it by exactly the
     * log-OU's conditional sd at that sigma.
     */
    public function testTheCurrencyDeviationSuppliesOnlyTheVarianceTheFundamentalLeaves(): void
    {
        $kappa = AssetMarketSubsystem::EXCHANGE_RATE_MEAN_REVERSION;
        $quarter = AssetMarketSubsystem::EXCHANGE_RATE_VOLATILITY_HORIZON_YEARS;
        $sigma = AssetMarketSubsystem::exchangeRateDeviationVolatility();
        $deviationQuarterVariance = ($sigma ** 2 / $kappa) * (1.0 - exp(-$kappa * $quarter));
        $this->assertEqualsWithDelta(
            MacroConstants::EXCHANGE_RATE_QUARTERLY_VOLATILITY ** 2,
            $deviationQuarterVariance + (AssetMarketSubsystem::EXCHANGE_RATE_FUNDAMENTAL_QUARTERLY_SD ** 2),
            1e-12,
            'Deviation and fundamental together carry the measured quarterly variance, no more.',
        );
        $this->assertLessThan(MacroConstants::EXCHANGE_RATE_QUARTERLY_VOLATILITY, sqrt($deviationQuarterVariance));

        // The step draws on that sigma: a unit draw from parity moves the log deviation by its conditional sd.
        $unitDraw = new AssetMarketSubsystem(new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 1.0;
            }
        });
        $flat = new AssetMarketSubsystem(new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        });
        $up = new MacroState();
        $up->exchangeRateDeviation = 1.0;
        $still = new MacroState();
        $still->exchangeRateDeviation = 1.0;
        $unitDraw->calculateExchangeRate($up, $quarter);
        $flat->calculateExchangeRate($still, $quarter);
        $conditionalSd = sqrt(($sigma ** 2 / (2.0 * $kappa)) * (1.0 - exp(-2.0 * $kappa * $quarter)));
        $this->assertEqualsWithDelta($conditionalSd, log($up->exchangeRateDeviation / $still->exchangeRateDeviation), 1e-12);
    }

    /** UIP: the district's currency is priced on the differential against the Fed's rate, not a constant. */
    public function testTheCurrencyReadsTheForeignPolicyRate(): void
    {
        $neutral = $this->settledFx();
        $foreignHikes = $this->settledFx(['foreignPolicyRate' => MacroConstants::MAINLAND_NEUTRAL_RATE + 0.02]);
        $districtHikes = $this->settledFx(['policyRate' => MacroConstants::MAINLAND_NEUTRAL_RATE + 0.02, 'policyRateShadow' => MacroConstants::MAINLAND_NEUTRAL_RATE + 0.02]);

        $this->assertLessThan($neutral, $foreignHikes, 'A foreign hiking cycle sells the district\'s currency.');
        $this->assertGreaterThan($neutral, $districtHikes, 'A district hiking cycle with the bloc on hold buys it.');
    }

    public function testAForeignBoomImprovesTheTradeBalance(): void
    {
        $home = new MacroState();
        $home->outputGap = 0.0;
        // The mainland boom's exports, already part of the gap they lift, with domestic demand unmoved.
        $exports = MacroAggregateSubsystem::netExportGapAt(0.03, 0.0, 0.0);
        $abroad = new MacroState();
        $abroad->netExportGap = $exports;
        $abroad->outputGap = $exports;
        for ($i = 0; $i < 400; $i++) {
            $this->subsystem->calculateTradeBalance($home, 0.01);
            $this->subsystem->calculateTradeBalance($abroad, 0.01);
        }

        $this->assertEqualsWithDelta($exports, $abroad->tradeBalanceToGdp - $home->tradeBalanceToGdp, 0.0005, 'Their demand is our exports, and nothing else moves the balance.');

        // Domestic demand draws in imports at 1.4 times itself on the imports for home use; those passed through into
        // exports do not move with it.
        $booming = new MacroState();
        $booming->outputGap = 0.03;
        for ($i = 0; $i < 400; $i++) {
            $this->subsystem->calculateTradeBalance($booming, 0.01);
        }
        $this->assertLessThan(MacroAggregateSubsystem::DISTRICT_IMPORT_SHARE, MacroAggregateSubsystem::DISTRICT_DOMESTIC_IMPORT_SHARE);
        $this->assertEqualsWithDelta(
            -MacroAggregateSubsystem::DISTRICT_DOMESTIC_IMPORT_SHARE * MacroAggregateSubsystem::IMPORT_DEMAND_ELASTICITY * 0.03,
            $booming->tradeBalanceToGdp - $home->tradeBalanceToGdp,
            0.0005,
        );
    }

    public function testLendingStandardsMoveTheHousePriceFundamental(): void
    {
        $calm = new MacroState();
        $tight = new MacroState();
        $tight->sloosTighteningIndexEma = 0.80;
        $loose = new MacroState();
        $loose->sloosTighteningIndexEma = -0.20;

        for ($i = 0; $i < 40; $i++) {
            $this->subsystem->calculateResidentialPropertyIndex($calm, MacroConstants::TARGET_INFLATION, 0.25);
            $this->subsystem->calculateResidentialPropertyIndex($tight, MacroConstants::TARGET_INFLATION, 0.25);
            $this->subsystem->calculateResidentialPropertyIndex($loose, MacroConstants::TARGET_INFLATION, 0.25);
        }

        $this->assertLessThan(0.85 * $calm->residentialPropertyIndex, $tight->residentialPropertyIndex, 'The price a buyer can pay is the price a lender will finance: the 80% tightening of a crisis takes over 15% off in a decade.');
        $this->assertGreaterThan($calm->residentialPropertyIndex, $loose->residentialPropertyIndex, 'Loosening standards lift what buyers can bid.');
    }

    /** Capozza, Hendershott, Mack & Mayer (2002): the market carries its past year's real growth into this year's. */
    public function testHousePricesCarryLastYearsGrowth(): void
    {
        $flat = new MacroState();
        $rising = new MacroState();
        $rising->residentialPriceMomentum = 0.05;

        $dt = 0.25;
        $this->subsystem->calculateResidentialPropertyIndex($flat, MacroConstants::TARGET_INFLATION, $dt);
        $this->subsystem->calculateResidentialPropertyIndex($rising, MacroConstants::TARGET_INFLATION, $dt);

        $this->assertEqualsWithDelta(
            exp(AssetMarketSubsystem::RESIDENTIAL_PRICE_MOMENTUM * 0.05 * $dt),
            $rising->residentialPropertyIndex / $flat->residentialPropertyIndex,
            1e-12,
            'Five percent a year of recent growth carries on at the fitted share.',
        );

        $weight = 1.0 - exp(-$dt / AssetMarketSubsystem::RESIDENTIAL_MOMENTUM_HORIZON_YEARS);
        $expected = 0.05 + ($weight * ((log($rising->residentialPropertyIndex / 100.0) / $dt) - 0.05));
        $this->assertEqualsWithDelta($expected, $rising->residentialPriceMomentum, 1e-12, 'The momentum is the year\'s average of the index\'s own log growth.');
    }

    /**
     * Serial correlation on top of reversion to fundamental makes a housing boom overshoot the level that justifies it,
     * then correct.
     */
    public function testAHousingBoomOvershootsItsFundamental(): void
    {
        $state = new MacroState();
        for ($quarter = 0; $quarter < 240; $quarter++) {
            $this->subsystem->calculateResidentialPropertyIndex($state, MacroConstants::TARGET_INFLATION, 0.25);
        }
        $start = $state->residentialPropertyIndex;

        // Loose lending lifts what buyers can bid: a permanent rise in the fundamental.
        $state->sloosTighteningIndexEma = -0.40;
        $peak = $start;
        for ($quarter = 0; $quarter < 240; $quarter++) {
            $this->subsystem->calculateResidentialPropertyIndex($state, MacroConstants::TARGET_INFLATION, 0.25);
            $peak = max($peak, $state->residentialPropertyIndex);
        }
        $settled = $state->residentialPropertyIndex;

        $this->assertGreaterThan($start, $settled);
        $this->assertGreaterThan($start + (1.10 * ($settled - $start)), $peak, 'The boom runs past the new fundamental by more than a tenth of the move.');
    }

    /**
     * Favara & Imbs (2015): the quantity of credit outstanding is housing demand in its own right, which closes the
     * collateral loop against CreditFiscalSubsystem's Mian & Sufi home-equity term.
     */
    public function testCreditSupplyIsHousingDemandInItsOwnRight(): void
    {
        $boom = new MacroState();
        $boom->creditToGdpGapEma = 0.10;
        $bust = new MacroState();
        $bust->creditToGdpGapEma = -0.10;
        $neutral = new MacroState();

        // The standards index is flat in all three, so only the credit QUANTITY leg can separate them.
        foreach ([$boom, $bust, $neutral] as $state) {
            $state->sloosTighteningIndexEma = 0.0;
            self::assertSame(0.0, $state->sloosTighteningIndexEma);
        }

        for ($i = 0; $i < 40; $i++) {
            $this->subsystem->calculateResidentialPropertyIndex($boom, MacroConstants::TARGET_INFLATION, 0.25);
            $this->subsystem->calculateResidentialPropertyIndex($bust, MacroConstants::TARGET_INFLATION, 0.25);
            $this->subsystem->calculateResidentialPropertyIndex($neutral, MacroConstants::TARGET_INFLATION, 0.25);
        }

        $this->assertGreaterThan(
            $neutral->residentialPropertyIndex,
            $boom->residentialPropertyIndex,
            'A credit stock ten points over trend is bidding for houses, whatever the standards index says.',
        );
        $this->assertLessThan(
            $neutral->residentialPropertyIndex,
            $bust->residentialPropertyIndex,
            'A deleveraging economy withdraws that bid, which is what makes the bust leg of the loop work.',
        );

        // The size of the effect, not merely its sign: ten points of gap at 0.25 is 2.5% on the fundamental.
        $lift = ($boom->residentialPropertyIndex / $neutral->residentialPropertyIndex) - 1.0;
        $this->assertEqualsWithDelta(
            AssetMarketSubsystem::RESIDENTIAL_CREDIT_SUPPLY_ELASTICITY * 0.10,
            $lift,
            0.010,
            'The house price response must carry the Favara-Imbs elasticity, not just its direction.',
        );
    }

    /**
     * A state with every FCI component at its neutral: credit, premium, slope and volatility at their means,
     * lending standards unchanged, the currency on its trend.
     */
    private static function neutralFinancialConditions(float $exchangeRateLevel): MacroState
    {
        $state = new MacroState();
        $state->macroCreditSpreadEma = AssetMarketSubsystem::FCI_CREDIT_MEAN;
        $state->equityRiskPremium = AssetMarketSubsystem::FCI_ERP_MEAN;
        $state->nsSlopeEma = AssetMarketSubsystem::FCI_SLOPE_MEAN;
        $state->marketVolatilityEma = AssetMarketSubsystem::FCI_VOL_MEAN;
        $state->sloosTighteningIndexEma = AssetMarketSubsystem::FCI_SLOOS_MEAN;
        $state->exchangeRateIndexEma = $exchangeRateLevel;
        $state->exchangeRateTrend = $exchangeRateLevel;

        return $state;
    }

    public function testTheFinancialConditionsIndexReadsTheCurrencyAgainstItsTrend(): void
    {
        // A currency settled at 108 is its own neutral: the index was built on 100, but the rate differential holds it
        // above that for decades, and scoring the level read a strong-but-settled currency as standing tightness.
        $state = self::neutralFinancialConditions(108.0);
        $this->subsystem->calculateFinancialConditionsIndex($state, 0.0);
        $this->assertEqualsWithDelta(0.0, $state->financialConditionsIndex, 1e-12, 'A currency on its trend adds no tightness, wherever the trend sits.');

        // One standard deviation (in logs) above that trend is one unit of the FX component.
        $state->exchangeRateIndexEma = 108.0 * exp(AssetMarketSubsystem::FCI_FX_STD);
        $this->subsystem->calculateFinancialConditionsIndex($state, 0.0);
        $this->assertEqualsWithDelta(AssetMarketSubsystem::FCI_EXCHANGE_RATE_WEIGHT, $state->financialConditionsIndex, 1e-12);
    }

    public function testTheFinancialConditionsIndexIsTheSamePeriodComposite(): void
    {
        // A credit event: the index reads it on the tick it arrives, whatever it read before (the NFCI is weekly
        // market prices), so a spread doubling cannot sit behind a stale reading for quarters.
        $state = self::neutralFinancialConditions(100.0);
        $state->financialConditionsIndex = -0.5;
        $state->macroCreditSpreadEma = AssetMarketSubsystem::FCI_CREDIT_MEAN + (2.0 * AssetMarketSubsystem::FCI_CREDIT_STD);

        $this->subsystem->calculateFinancialConditionsIndex($state, 0.0);
        $this->assertEqualsWithDelta(2.0 * AssetMarketSubsystem::FCI_CREDIT_SPREAD_WEIGHT, $state->financialConditionsIndex, 1e-12);

        $this->subsystem->calculateFinancialConditionsIndex($state, 0.0);
        $this->assertEqualsWithDelta(2.0 * AssetMarketSubsystem::FCI_CREDIT_SPREAD_WEIGHT, $state->financialConditionsIndex, 1e-12, 'The same inputs give the same reading: no path dependence.');
    }

    /**
     * Conditions that last become the norm: the composite is published net of its decade-long average, as the NFCI is
     * centred on its sample's, and a credit event on top of the new norm still reads tight.
     */
    public function testFinancialConditionsAreReadAgainstTheirOwnDecade(): void
    {
        $state = new MacroState();
        $this->subsystem->calculateFinancialConditionsIndex($state, 0.0);
        $opening = $state->financialConditionsIndex;

        // Spreads half a standard deviation wide for good: a quarter-weighted 0.5 on the composite.
        $state->macroCreditSpreadEma = MacroConstants::BASE_CREDIT_SPREAD + (0.5 * AssetMarketSubsystem::FCI_CREDIT_STD);
        $this->subsystem->calculateFinancialConditionsIndex($state, 0.0);
        $this->assertEqualsWithDelta($opening + (0.5 * AssetMarketSubsystem::FCI_CREDIT_SPREAD_WEIGHT), $state->financialConditionsIndex, 1e-9);

        for ($i = 0; $i < 4 * 40; $i++) {
            $this->subsystem->calculateFinancialConditionsIndex($state, 0.25);
        }
        $this->assertEqualsWithDelta(0.0, $state->financialConditionsIndex, 0.005, 'Four decades on, the wider spread is the norm.');

        $state->macroCreditSpreadEma += 3.0 * AssetMarketSubsystem::FCI_CREDIT_STD;
        $this->subsystem->calculateFinancialConditionsIndex($state, 0.0);
        $this->assertGreaterThan(0.7, $state->financialConditionsIndex, 'A three-sigma credit event still reads tight.');
    }

    /**
     * The currency prices the mainland's expected decision before its quarter turns: with no surprise, the turn moves
     * the fundamental no more than a tick does. It used to read the decision itself, so every quarter stepped it.
     */
    public function testTheMainlandsExpectedStepIsInTheCurrencyBeforeTheTurn(): void
    {
        $state = new MacroState();
        // A hot mainland whose rule asks for well over the rate it has.
        $state->foreignCoreInflation = 0.045;
        $state->foreignCoreInflationLag1 = 0.045;
        $state->foreignCoreInflationLag2 = 0.045;
        $state->foreignCoreInflationLag3 = 0.045;
        $state->foreignOutputGap = 0.02;
        $state->foreignOutputGapLag = 0.02;
        $state->foreignPolicyRate = 0.02;

        $state->totalTime = 1.0 - 0.0005;
        $before = AssetMarketSubsystem::exchangeRateFundamental($state);
        $state->totalTime = 1.0 + 0.0005;
        $this->subsystem->calculateForeignEconomy($state, 0.001);

        $this->assertGreaterThan(0.0225, $state->foreignPolicyRate, 'The rule raised the mainland rate at the turn.');
        $this->assertEqualsWithDelta($before, AssetMarketSubsystem::exchangeRateFundamental($state), $before * 0.0005);
    }
}

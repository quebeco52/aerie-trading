<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Math\MathUtility;
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
        };
        $this->subsystem = new AssetMarketSubsystem($this->mathUtility);
    }

    public function testCampbellCochraneEquityRiskPremiumRisesInRecession(): void
    {
        $state = new MacroState();
        $state->outputGapEma = -0.05;

        $this->subsystem->calculateEquityRiskPremium($state);
        $this->assertGreaterThan(MacroEngine::BASE_EQUITY_RISK_PREMIUM, $state->equityRiskPremium);
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
        $this->subsystem->calculateResidentialPropertyIndex($state, MacroEngine::TARGET_INFLATION, 0.25);

        $this->assertGreaterThan(0.0, $state->commercialPropertyIndex);
        $this->assertGreaterThan(0.0, $state->residentialPropertyIndex);
    }

    public function testCommercialPropertyRentIndexationProtectsValuationsDuringInflation(): void
    {
        $stateNoInflation = new MacroState();
        $stateNoInflation->yield10yEma = 0.055;
        $stateNoInflation->macroCreditSpreadEma = 0.02;
        $stateNoInflation->unemploymentRateEma = 0.04;
        $stateNoInflation->inflationEma = 0.02;
        $stateNoInflation->outputGapEma = 0.01;
        $stateNoInflation->commercialPropertyIndex = 100.0;

        $stateWithInflation = new MacroState();
        $stateWithInflation->yield10yEma = 0.055;
        $stateWithInflation->macroCreditSpreadEma = 0.02;
        $stateWithInflation->unemploymentRateEma = 0.04;
        $stateWithInflation->inflationEma = 0.04; // Higher inflation boosts contract rents
        $stateWithInflation->outputGapEma = 0.01;
        $stateWithInflation->commercialPropertyIndex = 100.0;

        $this->subsystem->calculateCommercialPropertyIndex($stateNoInflation, 0.25);
        $this->subsystem->calculateCommercialPropertyIndex($stateWithInflation, 0.25);

        // Rent growth indexation must support commercial property values when inflation is elevated
        $this->assertGreaterThan($stateNoInflation->commercialPropertyIndex, $stateWithInflation->commercialPropertyIndex);
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

    public function testCalculateTradeBalance(): void
    {
        $dt = 0.25;

        $stateAppreciated = new MacroState();
        $stateAppreciated->exchangeRateIndex = 115.0;
        $stateAppreciated->outputGap = 0.03;
        $stateAppreciated->tradeBalanceToGdp = MacroEngine::TRADE_BALANCE_BASELINE;

        $this->subsystem->calculateTradeBalance($stateAppreciated, $dt);
        $this->assertLessThan(MacroEngine::TRADE_BALANCE_BASELINE, $stateAppreciated->tradeBalanceToGdp, 'Currency appreciation and domestic demand absorption must worsen trade deficit');
    }

    public function testCalculateHousingStarts(): void
    {
        $dt = 0.25;

        $stateBoom = new MacroState();
        $stateBoom->residentialPropertyIndex = 130.0;
        $stateBoom->industrialMetalsIndex = 100.0;
        $stateBoom->wageGrowth = 0.03;
        $stateBoom->yield10yEma = 0.035;
        $stateBoom->macroCreditSpread = 0.015;
        $stateBoom->inflationEma = 0.025;
        $stateBoom->sloosTighteningIndexEma = 0.0;
        $stateBoom->housingStartsIndex = 100.0;

        $this->subsystem->calculateHousingStarts($stateBoom, MacroEngine::TARGET_INFLATION, $dt);
        $this->assertGreaterThan(100.0, $stateBoom->housingStartsIndex, 'High Tobin Q and affordable mortgage finance must stimulate housing starts');
    }


    public function testResidentialFundamentalPricesOffTheTenYearNotTheThirtyYear(): void
    {
        $base = new MacroState();
        $base->yield10yEma = 0.040;
        $base->yield30yEma = 0.045;
        $base->unemploymentRateEma = $base->nairu;
        $base->inflationEma = MacroEngine::TARGET_INFLATION;
        $base->outputGapEma = 0.0;
        $base->residentialPropertyIndex = 100.0;

        $steeperLongEnd = clone $base;
        $steeperLongEnd->yield30yEma = 0.065;

        $dearerTenYear = clone $base;
        $dearerTenYear->yield10yEma = 0.060;

        $this->subsystem->calculateResidentialPropertyIndex($base, MacroEngine::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($steeperLongEnd, MacroEngine::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($dearerTenYear, MacroEngine::TARGET_INFLATION, 0.25);

        $this->assertEqualsWithDelta($base->residentialPropertyIndex, $steeperLongEnd->residentialPropertyIndex, 1e-9, 'The 30Y yield must not enter the mortgage rate.');
        $this->assertLessThan($base->residentialPropertyIndex, $dearerTenYear->residentialPropertyIndex, 'A dearer 10Y raises the mortgage user cost and lowers fundamental home prices.');
    }

    public function testNeutralUserCostMatchesNeutralTenYearPlusMortgageSpread(): void
    {
        $neutralTenYear = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + MacroEngine::NS_BASE_TERM_PREMIUM;
        $expected = $neutralTenYear + AssetMarketSubsystem::RESIDENTIAL_MORTGAGE_SPREAD + AssetMarketSubsystem::RESIDENTIAL_DEPRECIATION_TAX_RATE - MacroEngine::TARGET_INFLATION;

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
     * Ranaldo & Soderlind (2010): the flight that compresses this sovereign's term premium buys its currency
     * first. One-sided at the same threshold, so ordinary volatility must not move the rate at all.
     */
    public function testPanicBidsTheCurrencyOnlyOnceFlightToSafetyStarts(): void
    {
        $neutral = $this->settledFx();
        $threshold = MacroEngine::FLIGHT_TO_SAFETY_VOL_THRESHOLD;

        $this->assertEqualsWithDelta($neutral, $this->settledFx(['marketVolatilityEma' => $threshold]), 1e-9, 'At the threshold the bid is exactly zero.');
        $this->assertEqualsWithDelta($neutral, $this->settledFx(['marketVolatilityEma' => $threshold - 0.05]), 1e-9, 'Below it, calm does not sell the currency.');
        $this->assertGreaterThan($neutral, $this->settledFx(['marketVolatilityEma' => 0.50]), 'A panic bids it.');
        $this->assertGreaterThan(
            $this->settledFx(['marketVolatilityEma' => 0.40]),
            $this->settledFx(['marketVolatilityEma' => 0.60]),
            'A worse panic bids it harder.'
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

    /** The user cost subtracts EXPECTED inflation: a headline spike with expectations anchored leaves home prices alone. */
    public function testAHeadlineSpikeWithAnchoredExpectationsDoesNotMoveHomePrices(): void
    {
        $calm = $this->neutralHousingState();
        $spiked = $this->neutralHousingState();
        $spiked->inflation = 0.06;
        $spiked->inflationEma = 0.05;

        $this->subsystem->calculateResidentialPropertyIndex($calm, MacroEngine::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($spiked, MacroEngine::TARGET_INFLATION, 0.25);

        $this->assertEqualsWithDelta($calm->residentialPropertyIndex, $spiked->residentialPropertyIndex, 1e-9);
    }

    /** Higher expected inflation lowers the real cost of owning and lifts fundamental home prices. */
    public function testHigherExpectedInflationLowersTheUserCostAndLiftsHomePrices(): void
    {
        $anchored = $this->neutralHousingState();
        $unanchored = $this->neutralHousingState();

        $this->subsystem->calculateResidentialPropertyIndex($anchored, MacroEngine::TARGET_INFLATION, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($unanchored, 0.03, 0.25);

        $this->assertGreaterThan($anchored->residentialPropertyIndex, $unanchored->residentialPropertyIndex);
    }

    private function neutralHousingState(): MacroState
    {
        $state = new MacroState();
        $state->yield10yEma = 0.040;
        $state->unemploymentRateEma = $state->nairu;
        $state->inflationEma = MacroEngine::TARGET_INFLATION;
        $state->outputGapEma = 0.0;
        $state->residentialPropertyIndex = 100.0;

        return $state;
    }
}

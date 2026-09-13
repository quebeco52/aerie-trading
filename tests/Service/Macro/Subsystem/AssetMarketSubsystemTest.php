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
        $this->subsystem->calculateResidentialPropertyIndex($state, 0.25);

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

        $this->subsystem->calculateHousingStarts($stateBoom, $dt);
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

        $this->subsystem->calculateResidentialPropertyIndex($base, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($steeperLongEnd, 0.25);
        $this->subsystem->calculateResidentialPropertyIndex($dearerTenYear, 0.25);

        $this->assertEqualsWithDelta($base->residentialPropertyIndex, $steeperLongEnd->residentialPropertyIndex, 1e-9, 'The 30Y yield must not enter the mortgage rate.');
        $this->assertLessThan($base->residentialPropertyIndex, $dearerTenYear->residentialPropertyIndex, 'A dearer 10Y raises the mortgage user cost and lowers fundamental home prices.');
    }

    public function testNeutralUserCostMatchesNeutralTenYearPlusMortgageSpread(): void
    {
        $neutralTenYear = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + MacroEngine::NS_BASE_TERM_PREMIUM;
        $expected = $neutralTenYear + MacroEngine::RESIDENTIAL_MORTGAGE_SPREAD + MacroEngine::RESIDENTIAL_DEPRECIATION_TAX_RATE - MacroEngine::TARGET_INFLATION;

        $this->assertEqualsWithDelta($expected, MacroEngine::RESIDENTIAL_NEUTRAL_USER_COST, 1e-12);
    }
}

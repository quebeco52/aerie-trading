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
        $this->mathUtility = new MathUtility();
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
        $state->yield30yEma = 0.045;
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
}

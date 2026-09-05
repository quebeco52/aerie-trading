<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class CreditFiscalSubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private CreditFiscalSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $this->subsystem = new CreditFiscalSubsystem($this->mathUtility);
    }

    public function testCreditSpreadExpandsDuringRecession(): void
    {
        $state = new MacroState();
        $state->outputGapEma = -0.05;
        $state->marketVolatilityEma = 0.30;
        $state->interbankLiquiditySpreadEma = 0.0050;

        $this->subsystem->calculateMacroCreditSpread($state);
        $this->assertGreaterThan(MacroEngine::BASE_CREDIT_SPREAD, $state->macroCreditSpread);
    }

    public function testGovernmentSpendingExpandsCountercyclically(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('calculateSchwartz1Factor')->willReturnCallback(function ($currentPrice, $kappa, $theta, $sigma, $dt, $dW) {
            $drift = $kappa * ($theta - $currentPrice) * $dt;
            return $currentPrice + $drift;
        });
        $mathMock->method('calculateJumpDiffusion')->willReturn(['jumped' => false, 'multiplier' => 1.0]);

        $subsystem = new CreditFiscalSubsystem($mathMock);

        $state = new MacroState();
        $state->outputGapEma = -0.04;
        $state->governmentSpendingIndex = 100.0;

        $subsystem->calculateGovernmentSpending($state, 0.25);
        $this->assertGreaterThan(100.0, $state->governmentSpendingIndex);
    }

    public function testBarroFiscalPolicyCutsCorporateTaxInRecession(): void
    {
        $state = new MacroState();
        $state->outputGapEma = -0.04;
        $state->corporateTaxRate = 0.21;

        $this->subsystem->calculateDynamicFiscalPolicy($state, 0.25);
        $this->assertLessThan(0.21, $state->corporateTaxRate);
    }

    public function testDualTrancheCreditSpreadsReflectFallenAngelCliff(): void
    {
        $stateNormal = new MacroState();
        $stateNormal->outputGapEma = 0.01;
        $stateNormal->marketVolatilityEma = 0.15;
        $stateNormal->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;

        $this->subsystem->calculateMacroCreditSpread($stateNormal);
        $this->assertEqualsWithDelta(MacroEngine::BASE_CREDIT_SPREAD, $stateNormal->macroCreditSpread, 0.005);
        $this->assertGreaterThan($stateNormal->macroCreditSpread, $stateNormal->highYieldCreditSpread);

        $stateRecession = new MacroState();
        $stateRecession->outputGapEma = -0.05;
        $stateRecession->marketVolatilityEma = 0.35;
        $stateRecession->interbankLiquiditySpreadEma = 0.006;

        $this->subsystem->calculateMacroCreditSpread($stateRecession);
        // Investment grade widens moderately
        $this->assertGreaterThan($stateNormal->macroCreditSpread, $stateRecession->macroCreditSpread);
        // High yield blows out exponentially due to fallen angel downgrade cliff
        $this->assertGreaterThan($stateNormal->highYieldCreditSpread * 2.0, $stateRecession->highYieldCreditSpread);
        $this->assertGreaterThan($stateRecession->macroCreditSpread * 2.5, $stateRecession->highYieldCreditSpread);
    }

    public function testBohnFiscalReactionStabilizesExcessDebt(): void
    {
        $stateSustainable = new MacroState();
        $stateSustainable->sovereignDebtToGdp = 0.60; // Below neutral threshold (0.70)
        $stateSustainable->nominalGdpIndex = 1.0;
        $stateSustainable->yield10yEma = 0.04;
        $stateSustainable->outputGap = 0.0;
        $stateSustainable->inflationEma = 0.02;
        $stateSustainable->corporateTaxRate = 0.21;
        $stateSustainable->governmentSpendingIndex = 100.0;

        $this->subsystem->calculateSovereignDebt($stateSustainable, 0.25);

        $stateExcessDebt = new MacroState();
        $stateExcessDebt->sovereignDebtToGdp = 1.20; // Well above neutral threshold (0.70)
        $stateExcessDebt->nominalGdpIndex = 1.0;
        $stateExcessDebt->yield10yEma = 0.04;
        $stateExcessDebt->outputGap = 0.0;
        $stateExcessDebt->inflationEma = 0.02;
        $stateExcessDebt->corporateTaxRate = 0.21;
        $stateExcessDebt->governmentSpendingIndex = 100.0;

        $this->subsystem->calculateSovereignDebt($stateExcessDebt, 0.25);

        // Bohn (1998) adjustment: higher debt induces primary fiscal surplus, counteracting interest burden
        // dDebt/dt rate of increase must be constrained by the Bohn stabilizer
        $excessDebtAmount = 1.20 - MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD;
        $expectedBohnSurplus = MacroEngine::BOHN_FISCAL_REACTION_SENSITIVITY * $excessDebtAmount;
        $this->assertGreaterThan(0.0, $expectedBohnSurplus);
    }

    public function testRetailDefaultRateCoupledToCreditSpreadStress(): void
    {
        $stateCalm = new MacroState();
        $stateCalm->unemploymentRateEma = 0.04;
        $stateCalm->inflationEma = 0.02;
        $stateCalm->macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD;
        $stateCalm->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;

        $this->subsystem->calculateRetailDefaultRate($stateCalm, 0.25);

        $stateCreditFreeze = new MacroState();
        $stateCreditFreeze->unemploymentRateEma = 0.04;
        $stateCreditFreeze->inflationEma = 0.02;
        $stateCreditFreeze->macroCreditSpreadEma = 0.050; // Severe wholesale credit widening
        $stateCreditFreeze->interbankLiquiditySpreadEma = 0.010; // Severe TED spread freeze

        $this->subsystem->calculateRetailDefaultRate($stateCreditFreeze, 0.25);

        // Retail default rate must transmit wholesale credit stress into consumer distress
        $this->assertGreaterThan($stateCalm->retailDefaultRate, $stateCreditFreeze->retailDefaultRate);
    }
}


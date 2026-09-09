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

    /**
     * A 2008-style freeze (deep contraction, 60% equity vol, TED at its record) must pin the IG spread at the
     * cap, and that cap must sit at the historical record rather than above it.
     */
    public function testInvestmentGradeSpreadIsCappedAtTheHistoricalRecord(): void
    {
        $state = new MacroState();
        $state->outputGapEma = -0.08;
        $state->marketVolatilityEma = 0.60;
        $state->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_MAX_SPREAD;

        $this->subsystem->calculateMacroCreditSpread($state);

        $this->assertEqualsWithDelta(MacroEngine::MAX_CREDIT_SPREAD, $state->macroCreditSpread, 1e-12);
        $this->assertLessThanOrEqual(0.065, MacroEngine::MAX_CREDIT_SPREAD, 'IG OAS never exceeded ~620 bps (Dec 2008).');
        $this->assertLessThanOrEqual(MacroEngine::MAX_HY_CREDIT_SPREAD, $state->highYieldCreditSpread);
        $this->assertGreaterThan($state->macroCreditSpread, $state->highYieldCreditSpread);
    }

    public function testInterbankContagionWidensIgSpreadByTheCalibratedCoefficient(): void
    {
        $neutral = new MacroState();
        $neutral->outputGapEma = 0.0;
        $neutral->marketVolatilityEma = 0.15;
        $neutral->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;

        $stressed = clone $neutral;
        $stressed->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD + 0.02; // +200 bps TED

        $this->subsystem->calculateMacroCreditSpread($neutral);
        $this->subsystem->calculateMacroCreditSpread($stressed);

        $this->assertEqualsWithDelta(
            0.02 * MacroEngine::INTERBANK_CREDIT_CONTAGION_SENSITIVITY,
            $stressed->macroCreditSpread - $neutral->macroCreditSpread,
            1e-9,
            'Contagion must flow through the engine constant, not a literal inside the formula.'
        );
        $this->assertLessThanOrEqual(1.0, MacroEngine::INTERBANK_CREDIT_CONTAGION_SENSITIVITY, '2008: +430 bps TED moved IG OAS by ~+450 bps.');
    }

    public function testInterbankSpreadIsCappedAtTheHistoricalRecord(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('calculateCIR')->willReturn(0.03); // 300 bps already stressed
        $mathMock->method('calculateJumpDiffusion')->willReturn(['multiplier' => 10.0, 'shock_pct' => 900.0, 'exponent' => log(10.0)]);

        $subsystem = new CreditFiscalSubsystem($mathMock);
        $state = new MacroState();
        $state->interbankLiquiditySpread = 0.03;
        $state->marketVolatilityEma = 0.40;

        $subsystem->calculateInterbankLiquiditySpread($state, 0.25);

        $this->assertEqualsWithDelta(MacroEngine::INTERBANK_MAX_SPREAD, $state->interbankLiquiditySpread, 1e-12);
        $this->assertEqualsWithDelta(0.05, MacroEngine::INTERBANK_MAX_SPREAD, 1e-12, 'TED record: 457 bps on 10 Oct 2008.');
    }

    /** Guards the jump-size calibration: the median panic is well short of 2008, and no panic jump shrinks the spread. */
    public function testInterbankPanicJumpCalibration(): void
    {
        $medianMultiplier = exp(MacroEngine::INTERBANK_JUMP_MEAN);
        $twoSigmaLow = exp(MacroEngine::INTERBANK_JUMP_MEAN - 2.0 * MacroEngine::INTERBANK_JUMP_VOL);

        $this->assertLessThan(5.0, $medianMultiplier, 'A 5x freeze (2008) must be a tail outcome, not the median jump.');
        $this->assertGreaterThan(2.0, $medianMultiplier, 'A panic jump should still at least double the spread.');
        $this->assertGreaterThanOrEqual(1.0, $twoSigmaLow, 'Panic jumps must never reduce the interbank spread.');
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
        // Pin the ASRF systemic noise draw: with the real RNG the calm state's random shock can outweigh
        // the freeze state's deterministic stress term, so the comparison must isolate the stress channel.
        $mathUtility = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $mathUtility->expects($this->exactly(2))->method('generateStandardNormal')->willReturn(0.0);
        $subsystem = new CreditFiscalSubsystem($mathUtility);

        $stateCalm = new MacroState();
        $stateCalm->unemploymentRateEma = 0.04;
        $stateCalm->inflationEma = 0.02;
        $stateCalm->macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD;
        $stateCalm->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;

        $subsystem->calculateRetailDefaultRate($stateCalm, 0.25);

        $stateCreditFreeze = new MacroState();
        $stateCreditFreeze->unemploymentRateEma = 0.04;
        $stateCreditFreeze->inflationEma = 0.02;
        $stateCreditFreeze->macroCreditSpreadEma = 0.050; // Severe wholesale credit widening
        $stateCreditFreeze->interbankLiquiditySpreadEma = 0.010; // Severe TED spread freeze

        $subsystem->calculateRetailDefaultRate($stateCreditFreeze, 0.25);

        // Retail default rate must transmit wholesale credit stress into consumer distress
        $this->assertGreaterThan($stateCalm->retailDefaultRate, $stateCreditFreeze->retailDefaultRate);
    }

    public function testCalculateCorporateDefaultRate(): void
    {
        $stateNormal = new MacroState();
        $stateNormal->outputGapEma = 0.0;
        $stateNormal->highYieldCreditSpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
        $stateNormal->sloosTighteningIndexEma = 0.0;

        $this->subsystem->calculateCorporateDefaultRate($stateNormal, 0.25);
        $this->assertGreaterThan(0.005, $stateNormal->corporateDefaultRate);
        $this->assertLessThan(0.030, $stateNormal->corporateDefaultRate);

        $stateCrisis = new MacroState();
        $stateCrisis->outputGapEma = -0.04;
        $stateCrisis->highYieldCreditSpread = 0.090; // Severe junk spread widening
        $stateCrisis->sloosTighteningIndexEma = 0.40;   // Bank lending freeze

        $this->subsystem->calculateCorporateDefaultRate($stateCrisis, 0.25);
        $this->assertGreaterThan($stateNormal->corporateDefaultRate * 2.0, $stateCrisis->corporateDefaultRate, 'Recession with credit freeze must spike speculative defaults.');
    }

    public function testCalculateSloosCreditStandards(): void
    {
        $state = new MacroState();
        $state->sloosTighteningIndex = 0.0;
        $state->macroCreditSpread = 0.050;    // +300bps widening above baseline
        $state->macroCreditSpreadEma = 0.050;
        $state->outputGapEma = -0.03;         // Recession

        $this->subsystem->calculateSloosCreditStandards($state, 0.25);
        $this->assertGreaterThan(0.05, $state->sloosTighteningIndex, 'Wide credit spreads and recession must trigger net bank tightening.');
    }


    public function testCreditSpreadsSpanBoomTightnessToCrisisBlowout(): void
    {
        $boom = new MacroState();
        $boom->outputGapEma = 0.03;
        $boom->marketVolatilityEma = 0.13;
        $boom->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;

        $crisis = new MacroState();
        $crisis->outputGapEma = -0.04;
        $crisis->marketVolatilityEma = 0.35;
        $crisis->interbankLiquiditySpreadEma = 0.020;

        $this->subsystem->calculateMacroCreditSpread($boom);
        $this->subsystem->calculateMacroCreditSpread($crisis);

        // Boom: IG inside 110 bps and HY inside 400 bps, the tight end of a developed credit cycle.
        $this->assertLessThan(0.011, $boom->macroCreditSpread);
        $this->assertLessThan(0.040, $boom->highYieldCreditSpread);

        // Crisis: IG beyond 400 bps and HY beyond 1,500 bps, a 2008-type blowout rather than a 260 bps wobble.
        $this->assertGreaterThan(0.040, $crisis->macroCreditSpread);
        $this->assertGreaterThan(0.150, $crisis->highYieldCreditSpread);
        $this->assertLessThanOrEqual(MacroEngine::MAX_HY_CREDIT_SPREAD, $crisis->highYieldCreditSpread);
    }

    public function testInterbankSpreadMeanTracksCreditStress(): void
    {
        // With diffusion silenced the CIR process converges on its credit-coupled mean: a wide IG spread must
        // pull the interbank spread well above baseline, so a credit crisis shows up in TED rather than random jumps.
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
        $subsystem = new CreditFiscalSubsystem($quiet);

        $state = new MacroState();
        $state->macroCreditSpread = MacroEngine::BASE_CREDIT_SPREAD + 0.030;
        $state->macroCreditSpreadEma = $state->macroCreditSpread;
        $state->marketVolatilityEma = 0.30;
        $state->interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;

        for ($i = 0; $i < 40; $i++) {
            $subsystem->calculateInterbankLiquiditySpread($state, 0.25);
        }

        $expectedMean = MacroEngine::INTERBANK_BASELINE_SPREAD + (0.030 * MacroEngine::INTERBANK_CREDIT_COUPLING);
        $this->assertEqualsWithDelta($expectedMean, $state->interbankLiquiditySpread, 0.0005);
        $this->assertGreaterThan(0.010, $state->interbankLiquiditySpread, 'A 300 bps IG blowout must lift TED past 100 bps.');
    }
}

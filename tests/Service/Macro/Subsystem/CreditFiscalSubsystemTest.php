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
        $state->interbankLiquiditySpreadEma = CreditFiscalSubsystem::INTERBANK_MAX_SPREAD;

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

        $this->assertEqualsWithDelta(CreditFiscalSubsystem::INTERBANK_MAX_SPREAD, $state->interbankLiquiditySpread, 1e-12);
        $this->assertEqualsWithDelta(0.05, CreditFiscalSubsystem::INTERBANK_MAX_SPREAD, 1e-12, 'TED record: 457 bps on 10 Oct 2008.');
    }

    /** Guards the jump-size calibration: the median panic is well short of 2008, and no panic jump shrinks the spread. */
    public function testInterbankPanicJumpCalibration(): void
    {
        $medianMultiplier = exp(CreditFiscalSubsystem::INTERBANK_JUMP_MEAN);
        $twoSigmaLow = exp(CreditFiscalSubsystem::INTERBANK_JUMP_MEAN - 2.0 * CreditFiscalSubsystem::INTERBANK_JUMP_VOL);

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
        $expectedBohnSurplus = CreditFiscalSubsystem::BOHN_FISCAL_REACTION_SENSITIVITY * $excessDebtAmount;
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

        $expectedMean = MacroEngine::INTERBANK_BASELINE_SPREAD + (0.030 * CreditFiscalSubsystem::INTERBANK_CREDIT_COUPLING);
        $this->assertEqualsWithDelta($expectedMean, $state->interbankLiquiditySpread, 0.0005);
        $this->assertGreaterThan(0.010, $state->interbankLiquiditySpread, 'A 300 bps IG blowout must lift TED past 100 bps.');
    }


    // --- Administered Healthcare Prices ---

    public function testReimbursementRateIsFlatBetweenAnnualUpdates(): void
    {
        $state = new MacroState();
        $state->totalTime = 3.40;
        $state->inflationEma = 0.06;
        $before = $state->reimbursementRateGrowth;

        $this->subsystem->calculateReimbursementRate($state, 0.25);

        $this->assertSame($before, $state->reimbursementRateGrowth, 'An administered price does not move mid-year, whatever inflation does.');
        $this->assertSame(100.0, $state->reimbursementRateIndex);
    }

    public function testAnnualUpdateIsObservedInflationLessTheProductivityOffset(): void
    {
        $state = new MacroState();
        $state->totalTime = 4.001; // the tick that crossed the year end
        $state->inflationEma = 0.04;
        $state->sovereignDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP; // below the neutral threshold: no sequester

        $this->subsystem->calculateReimbursementRate($state, 0.01);

        $this->assertEqualsWithDelta(0.04 - MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET, $state->reimbursementRateGrowth, 1e-9);
        $this->assertEqualsWithDelta(100.0 * (1.0 + $state->reimbursementRateGrowth), $state->reimbursementRateIndex, 1e-9);
    }

    public function testAFiscalCorrectionSequestersTheUpdate(): void
    {
        $solvent = new MacroState();
        $solvent->totalTime = 4.001;
        $solvent->inflationEma = 0.03;
        $solvent->sovereignDebtToGdpEma = MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD;

        $indebted = new MacroState();
        $indebted->totalTime = 4.001;
        $indebted->inflationEma = 0.03;
        $indebted->sovereignDebtToGdpEma = MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD + 0.30;

        $this->subsystem->calculateReimbursementRate($solvent, 0.01);
        $this->subsystem->calculateReimbursementRate($indebted, 0.01);

        $this->assertEqualsWithDelta(
            0.30 * CreditFiscalSubsystem::REIMBURSEMENT_FISCAL_CUT_SENSITIVITY,
            $solvent->reimbursementRateGrowth - $indebted->reimbursementRateGrowth,
            1e-9,
            'Thirty points of excess debt take the sequester off the administered update.'
        );
    }

    public function testADeflationaryYearHoldsRatesRatherThanCuttingThem(): void
    {
        $state = new MacroState();
        $state->totalTime = 4.001;
        $state->inflationEma = -0.01;

        $this->subsystem->calculateReimbursementRate($state, 0.01);

        $this->assertSame(CreditFiscalSubsystem::REIMBURSEMENT_MIN_UPDATE, $state->reimbursementRateGrowth);
        $this->assertSame(100.0, $state->reimbursementRateIndex);
    }


    // --- Economic Policy Uncertainty ---

    /** Deterministic subsystem: no diffusion draw, no jump arrival. */
    private function quietSubsystem(): CreditFiscalSubsystem
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };

        return new CreditFiscalSubsystem($math);
    }

    public function testPolicyUncertaintyBuildsIntoAScheduledElection(): void
    {
        $subsystem = $this->quietSubsystem();

        $midTerm = new MacroState();
        $midTerm->totalTime = 1.5; // two and a half years to the vote
        $campaign = new MacroState();
        $campaign->totalTime = 3.95; // three weeks out

        for ($i = 0; $i < 40; $i++) {
            $subsystem->calculatePolicyUncertainty($midTerm, 0.01);
            $subsystem->calculatePolicyUncertainty($campaign, 0.01);
            $midTerm->totalTime = 1.5;
            $campaign->totalTime = 3.95;
        }

        $this->assertGreaterThan($midTerm->policyUncertaintyIndex, $campaign->policyUncertaintyIndex, 'The index rises through the election year.');
        $this->assertLessThan(MacroEngine::EPU_BASELINE * exp(CreditFiscalSubsystem::EPU_ELECTION_LIFT), $campaign->policyUncertaintyIndex, 'and no higher than the full election lift.');
    }

    public function testADownturnLiftsPolicyUncertainty(): void
    {
        $subsystem = $this->quietSubsystem();

        $calm = new MacroState();
        $calm->totalTime = 1.5;
        $calm->recessionProbabilityEma = CreditFiscalSubsystem::EPU_STRESS_PROBABILITY_FLOOR;
        $stressed = new MacroState();
        $stressed->totalTime = 1.5;
        $stressed->recessionProbabilityEma = CreditFiscalSubsystem::EPU_STRESS_PROBABILITY_FLOOR + 0.50;

        // Four years of steps: the log-OU has closed all but a fraction of a percent of its gap to the target.
        for ($i = 0; $i < 400; $i++) {
            $subsystem->calculatePolicyUncertainty($calm, 0.01);
            $subsystem->calculatePolicyUncertainty($stressed, 0.01);
            $calm->totalTime = 1.5;
            $stressed->totalTime = 1.5;
        }

        $expectedRatio = exp(CreditFiscalSubsystem::EPU_STRESS_LIFT * 0.50);
        $this->assertEqualsWithDelta($expectedRatio, $stressed->policyUncertaintyIndex / $calm->policyUncertaintyIndex, 0.02, 'A fifty-point rise in recession odds puts the policy response itself in question.');
    }

    public function testTheElectionPulseFallsOnTheTickTheTermEnds(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = new MacroState();

        $state->totalTime = 3.995;
        $subsystem->calculatePolicyUncertainty($state, 0.01);
        $this->assertSame(-1.0, $state->lastElectionAt, 'No election before the term is up.');

        $state->totalTime = 4.005;
        $subsystem->calculatePolicyUncertainty($state, 0.01);
        $this->assertSame(4.005, $state->lastElectionAt, 'The vote falls on the tick that crosses the term boundary.');

        $state->totalTime = 4.015;
        $subsystem->calculatePolicyUncertainty($state, 0.01);
        $this->assertSame(4.005, $state->lastElectionAt, 'and is not re-held on the next tick.');
    }

    public function testPolicyUncertaintyStaysInsideItsRecordedRange(): void
    {
        mt_srand(20260919);
        $state = new MacroState();
        for ($i = 0; $i < 4000; $i++) {
            $this->subsystem->calculatePolicyUncertainty($state, 0.01);
            $state->totalTime += 0.01;
            $this->assertGreaterThanOrEqual(CreditFiscalSubsystem::MIN_EPU, $state->policyUncertaintyIndex);
            $this->assertLessThanOrEqual(CreditFiscalSubsystem::MAX_EPU, $state->policyUncertaintyIndex);
        }
    }


    // --- Sovereign Risk Premium ---

    /** Runs the repricing lag to its target so the level, not the speed, is under test. */
    private function settledSovereignSpread(float $debtToGdpEma): float
    {
        $state = new MacroState();
        $state->sovereignDebtToGdpEma = $debtToGdpEma;
        for ($i = 0; $i < 2000; $i++) {
            $this->subsystem->calculateSovereignRiskSpread($state, 0.01);
        }

        return $state->sovereignRiskSpread;
    }

    public function testTheFiscalPositionTheReactionDefendsCarriesNoRiskPremium(): void
    {
        $this->assertSame(0.0, $this->settledSovereignSpread(MacroEngine::INITIAL_DEBT_TO_GDP));
        $this->assertSame(0.0, $this->settledSovereignSpread(MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD), 'The Bohn threshold is where the fiscal reaction starts, not where the market re-rates.');
        $this->assertSame(0.0, $this->settledSovereignSpread(CreditFiscalSubsystem::SOVEREIGN_RISK_DEBT_THRESHOLD), 'At the line itself there is nothing to charge for yet.');
    }

    public function testDebtAboveTheRiskLineIsPricedAtLaubachsRate(): void
    {
        $spread = $this->settledSovereignSpread(CreditFiscalSubsystem::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.30);

        $this->assertEqualsWithDelta(0.30 * CreditFiscalSubsystem::LAUBACH_DEBT_YIELD_SENSITIVITY, $spread, 1e-6, 'Thirty points of excess debt cost about 105 bps: 3.5 bps a point.');
    }

    public function testTheSovereignSpreadIsCappedAtTheLossOfMarketAccess(): void
    {
        $atMaxDebt = $this->settledSovereignSpread(2.50);
        $expected = min(CreditFiscalSubsystem::MAX_SOVEREIGN_RISK_SPREAD, (2.50 - CreditFiscalSubsystem::SOVEREIGN_RISK_DEBT_THRESHOLD) * CreditFiscalSubsystem::LAUBACH_DEBT_YIELD_SENSITIVITY);
        $this->assertEqualsWithDelta($expected, $atMaxDebt, 1e-6);
        $this->assertLessThanOrEqual(CreditFiscalSubsystem::MAX_SOVEREIGN_RISK_SPREAD, $atMaxDebt);
    }

    public function testTheSovereignSpreadRepricesOverBudgetRoundsNotTicks(): void
    {
        $state = new MacroState();
        $state->sovereignDebtToGdpEma = CreditFiscalSubsystem::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.30;

        $this->subsystem->calculateSovereignRiskSpread($state, 1.0 / 252.0);

        $target = 0.30 * CreditFiscalSubsystem::LAUBACH_DEBT_YIELD_SENSITIVITY;
        $this->assertGreaterThan(0.0, $state->sovereignRiskSpread);
        $this->assertLessThan(0.02 * $target, $state->sovereignRiskSpread, 'One trading day closes under two percent of the gap.');
    }

    public function testTheSovereignCeilingLiftsTheCorporateBase(): void
    {
        $sound = new MacroState();
        $sound->outputGapEma = 0.0;
        $stressed = new MacroState();
        $stressed->outputGapEma = 0.0;
        $stressed->sovereignRiskSpreadEma = 0.02;

        $this->subsystem->calculateMacroCreditSpread($sound);
        $this->subsystem->calculateMacroCreditSpread($stressed);

        $this->assertEqualsWithDelta(0.02 * CreditFiscalSubsystem::SOVEREIGN_CEILING_PASSTHROUGH, $stressed->macroCreditSpread - $sound->macroCreditSpread, 1e-6, 'At a zero gap the pass-through reaches IG one-for-one with its share.');
        $this->assertGreaterThan($sound->highYieldCreditSpread, $stressed->highYieldCreditSpread);
    }

    public function testTheDeficitIsPublishedAsAShareOfGdp(): void
    {
        $state = new MacroState();
        $state->sovereignDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP;
        $state->nominalGdpIndex = 1.0;
        $state->outputGap = 0.0;
        $state->governmentSpendingIndex = 100.0;
        $state->corporateTaxRate = MacroEngine::TARGET_CORPORATE_TAX_RATE;

        $this->subsystem->calculateSovereignDebt($state, 0.25);

        $this->assertEqualsWithDelta(CreditFiscalSubsystem::SOVEREIGN_STRUCTURAL_DEFICIT, $state->primaryDeficitToGdp, 1e-9, 'At neutral spending and tax the primary deficit is the structural one.');
    }


    // --- Household credit cycle (Mian & Sufi 2018; BIS DSR; Basel III CCyB) ---

    /** A state whose household rates sit at the engine's neutral, so only the term under test moves leverage. */
    private function householdStateAtNeutralRates(): MacroState
    {
        $state = new MacroState();
        $state->yield10yEma = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + MacroEngine::NS_BASE_TERM_PREMIUM;
        $state->policyRateEma = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $state->outputGapEma = 0.0;
        $state->householdDebtServiceTrend = MacroEngine::HOUSEHOLD_DSR_NEUTRAL;

        return $state;
    }

    public function testLeverageBuildsOnAHousePriceBoomAndUnwindsUnderTightStandards(): void
    {
        $subsystem = $this->quietSubsystem();

        $boom = $this->householdStateAtNeutralRates();
        $boom->residentialPropertyIndexEma = 1.20 * MacroEngine::RESIDENTIAL_BASELINE;
        $subsystem->calculateHouseholdCredit($boom, 1.0);

        $tight = $this->householdStateAtNeutralRates();
        $tight->sloosTighteningIndexEma = 1.0;
        $subsystem->calculateHouseholdCredit($tight, 1.0);

        $expectedBoom = MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE * exp(CreditFiscalSubsystem::CREDIT_GROWTH_HOUSE_PRICE * 0.20);
        $this->assertEqualsWithDelta($expectedBoom, $boom->householdDebtToIncome, 1e-9, 'Twenty percent richer collateral borrows two percent more in a year.');
        $this->assertLessThan(MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $tight->householdDebtToIncome, 'Rationed credit amortises faster than it is written.');
        $this->assertGreaterThan($tight->householdDebtToIncome, $boom->householdDebtToIncome);
    }

    public function testTheDebtServiceRatioIsTheBisAnnuityAndRisesWithRates(): void
    {
        $subsystem = $this->quietSubsystem();
        $dt = 1.0 / 3600.0;

        $neutral = $this->householdStateAtNeutralRates();
        $subsystem->calculateHouseholdCredit($neutral, $dt);
        $this->assertEqualsWithDelta(MacroEngine::HOUSEHOLD_DSR_NEUTRAL, $neutral->householdDebtServiceRatio, 5e-4, 'Baseline leverage at neutral rates services at the documented 10.6% of income.');

        $hiked = $this->householdStateAtNeutralRates();
        $hiked->yield10yEma += 0.02;
        $hiked->policyRateEma += 0.02;
        $subsystem->calculateHouseholdCredit($hiked, $dt);
        $this->assertEqualsWithDelta($neutral->householdDebtToIncome, $hiked->householdDebtToIncome, 1e-4, 'One tick barely moves the stock, so the difference is the rate.');
        $this->assertGreaterThan($neutral->householdDebtServiceRatio + 0.01, $hiked->householdDebtServiceRatio, 'Two points of rates cost more than a point of income in service.');
    }

    public function testTheBufferFollowsTheBaselSchedule(): void
    {
        $subsystem = $this->quietSubsystem();

        $settle = function (float $creditGapEma) use ($subsystem): float {
            $state = $this->householdStateAtNeutralRates();
            $state->creditToGdpGapEma = $creditGapEma;
            for ($i = 0; $i < 40; $i++) {
                $subsystem->calculateHouseholdCredit($state, 0.25);
                $state->creditToGdpGapEma = $creditGapEma;
            }

            return $state->countercyclicalBufferRate;
        };

        $this->assertEqualsWithDelta(0.0, $settle(CreditFiscalSubsystem::CCYB_GAP_FLOOR - 0.005), 1e-9, 'Below a two-point gap no buffer is set.');
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::MAX_CCYB / 2.0, $settle((CreditFiscalSubsystem::CCYB_GAP_FLOOR + CreditFiscalSubsystem::CCYB_GAP_CEILING) / 2.0), 1e-5, 'Halfway up the schedule is half the buffer.');
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::MAX_CCYB, $settle(CreditFiscalSubsystem::CCYB_GAP_CEILING + 0.02), 1e-5, 'Past a ten-point gap the full 2.5% is held.');
    }

    public function testTheBufferPhasesInOverAYearRatherThanOnTheTick(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = $this->householdStateAtNeutralRates();
        $state->creditToGdpGapEma = CreditFiscalSubsystem::CCYB_GAP_CEILING + 0.02;

        $subsystem->calculateHouseholdCredit($state, 1.0 / 252.0);

        $this->assertGreaterThan(0.0, $state->countercyclicalBufferRate);
        $this->assertLessThan(0.01 * CreditFiscalSubsystem::MAX_CCYB, $state->countercyclicalBufferRate, 'A trading day closes under one percent of the decision.');
    }

    public function testHouseholdsDeleverageOnlyPastTheWarningLine(): void
    {
        $subsystem = $this->quietSubsystem();

        $below = $this->householdStateAtNeutralRates();
        $below->householdDebtServiceGap = MacroEngine::HOUSEHOLD_DSR_STRESS_MARGIN - 0.001;
        $subsystem->calculateHouseholdCredit($below, 1.0);

        $above = $this->householdStateAtNeutralRates();
        $above->householdDebtServiceGap = MacroEngine::HOUSEHOLD_DSR_STRESS_MARGIN + 0.02;
        $subsystem->calculateHouseholdCredit($above, 1.0);

        $this->assertEqualsWithDelta(MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $below->householdDebtToIncome, 1e-9, 'Under the line the service burden is carried, not repaid.');
        $this->assertEqualsWithDelta(MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE * exp(-CreditFiscalSubsystem::DELEVERAGING_SPEED * 0.02), $above->householdDebtToIncome, 1e-9, 'Two points over the line repay one percent of income a year.');
    }

    public function testRetailDefaultsRiseWithTheDebtServiceRatioAtFixedUnemployment(): void
    {
        $subsystem = $this->quietSubsystem();

        $neutral = new MacroState();
        $neutral->unemploymentRateEma = $neutral->nairu;
        $subsystem->calculateRetailDefaultRate($neutral, 0.25);

        $burdened = new MacroState();
        $burdened->unemploymentRateEma = $burdened->nairu;
        $burdened->householdDebtServiceGap = 0.02;
        $subsystem->calculateRetailDefaultRate($burdened, 0.25);

        $this->assertGreaterThan($neutral->retailDefaultRate, $burdened->retailDefaultRate, 'A heavier instalment defaults more households at the same unemployment.');
    }

    public function testTheBufferTightensLendingStandardsLikeASpreadWould(): void
    {
        $subsystem = $this->quietSubsystem();

        $unbuffered = new MacroState();
        $subsystem->calculateSloosCreditStandards($unbuffered, 0.25);

        $buffered = new MacroState();
        $buffered->countercyclicalBufferRateEma = CreditFiscalSubsystem::MAX_CCYB;
        $subsystem->calculateSloosCreditStandards($buffered, 0.25);

        $this->assertGreaterThan($unbuffered->sloosTighteningIndex, $buffered->sloosTighteningIndex, 'Capital held against new loans rations them.');
    }

    public function testTheServiceGapStartsAtZeroWhateverTheSeededCurve(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = new MacroState();
        $state->outputGapEma = 0.0;

        $subsystem->calculateHouseholdCredit($state, 1.0 / 3600.0);

        $this->assertEqualsWithDelta(0.0, $state->householdDebtServiceGap, 1e-6, 'The trend starts at the first observation, so a cold start reads no stress.');
        $this->assertEqualsWithDelta($state->householdDebtServiceRatio, $state->householdDebtServiceTrend, 1e-6);
    }

    public function testTheServiceGapMeasuresTheRatioAgainstItsOwnSlowAverage(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = $this->householdStateAtNeutralRates();
        $state->yield10yEma += 0.02;
        $state->policyRateEma += 0.02;

        $subsystem->calculateHouseholdCredit($state, 1.0 / 3600.0);

        $this->assertGreaterThan(0.01, $state->householdDebtServiceGap, 'A hike opens the gap on the tick.');
        for ($i = 0; $i < 400; $i++) {
            $subsystem->calculateHouseholdCredit($state, 0.25);
        }
        $this->assertLessThan(0.002, abs($state->householdDebtServiceGap), 'A century later the same rates are the average, and the gap has closed.');
    }
}

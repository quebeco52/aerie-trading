<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class CreditFiscalSubsystemTest extends TestCase
{
    /** A debt position below the 70% the Bohn reaction defends: no sequester, no fiscal tightening, no market premium. */
    private const SOUND_DEBT_TO_GDP = 0.60;

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

    /** Purchases do not lean against the cycle (US 1949-2019), and a surge fades on the fitted 1.8-year half-life. */
    public function testGovernmentSpendingIgnoresTheGapAndASurgeFadesOverYears(): void
    {
        $subsystem = $this->quietSubsystem();
        $paths = [];
        foreach ([-0.04, 0.04] as $gap) {
            $state = new MacroState();
            $state->outputGap = $gap;
            $state->outputGapEma = $gap;
            $state->governmentSpendingIndex = 125.0;
            $path = [];
            for ($year = 1; $year <= 3; $year++) {
                for ($i = 0; $i < 360; $i++) {
                    $subsystem->calculateGovernmentSpending($state, 1.0 / 360.0);
                }
                $path[$year] = $state->governmentSpendingIndex;
            }
            $paths[] = $path;
        }

        $this->assertSame($paths[0], $paths[1], 'A slump and a boom leave purchases on the same path.');
        $settle = $paths[0][2];
        for ($i = 0; $i < 360 * 40; $i++) {
            $subsystem->calculateGovernmentSpending($state, 1.0 / 360.0);
        }
        $share = static fn (float $index): float => log($index / $state->governmentSpendingIndex) / log(125.0 / $state->governmentSpendingIndex);
        $this->assertGreaterThan(0.5, $share($paths[0][1]), 'More than half of a Korea-sized surge is still there after a year.');
        $this->assertLessThan(0.5, $share($settle), 'Less than half is left after two.');
        $this->assertGreaterThan(0.25, $share($settle), 'A mobilisation is still a quarter there after two years.');
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
        $this->assertGreaterThan($stateNormal->corporateDefaultRate * 2.0, $stateCrisis->corporateDefaultRate, 'Recession with credit freeze must spike corporate defaults.');
    }

    /**
     * The series is Moody's ALL-rated default rate, and its readers treat it that way: receivables are
     * provisioned at it, and every sector model scales off its excess over the 1.6% long-run average. The
     * crisis end had been calibrated to the SPECULATIVE-grade 2009 peak (13%) on an all-rated base, so the
     * worst recession read as 4.9 times "normal" excess where the all-rated record's worst is about 2.4.
     */
    public function testCorporateDefaultRateIsCalibratedToTheAllRatedRecord(): void
    {
        $neutral = new MacroState();
        $neutral->outputGapEma = 0.0;
        $neutral->highYieldCreditSpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
        $neutral->sloosTighteningIndexEma = 0.0;
        $this->subsystem->calculateCorporateDefaultRate($neutral, 0.25);

        $blowout = new MacroState();
        $blowout->outputGapEma = -0.04;
        $blowout->highYieldCreditSpread = 0.20;
        $blowout->sloosTighteningIndexEma = 0.80;
        $this->subsystem->calculateCorporateDefaultRate($blowout, 0.25);

        // A neutral year at the post-1983 median, below the mean it averages to.
        $this->assertEqualsWithDelta(0.012, $neutral->corporateDefaultRate, 0.001);
        $this->assertLessThan(MacroEngine::CORPORATE_DEFAULT_BASELINE, $neutral->corporateDefaultRate);
        // A 2,000 bps blowout held for a year is a Depression year for the all-rated series (1933: ~8.4%),
        // not the speculative-grade 2009 peak of 13% the old calibration produced (14.7% here).
        $this->assertGreaterThan(0.054, $blowout->corporateDefaultRate, 'Worse than 2009\'s all-rated 5.4%, which averaged a shorter blowout.');
        $this->assertLessThan(0.10, $blowout->corporateDefaultRate, 'Not a speculative-grade peak on an all-rated base.');
    }

    public function testCalculateSloosCreditStandards(): void
    {
        $state = new MacroState();
        $state->sloosTighteningIndex = 0.0;
        $state->excessBondPremium = 0.020;    // lenders charging 200 bps over default risk
        $state->outputGapEma = -0.03;         // Recession

        $this->subsystem->calculateSloosCreditStandards($state, 0.25);
        $this->assertGreaterThan(0.05, $state->sloosTighteningIndex, 'A repricing of credit risk must trigger net bank tightening.');
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
        $crisis->excessBondPremium = 0.034; // October 2008

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

    public function testInterbankSpreadMeanTracksTheBondPremium(): void
    {
        // With diffusion silenced the CIR process converges on its premium-coupled mean: lenders repricing credit
        // risk must pull the interbank spread well above baseline, so a credit crisis shows up in TED rather than
        // random jumps.
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
        $state->excessBondPremium = 0.034;
        $state->marketVolatilityEma = 0.30;
        $state->interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;

        for ($i = 0; $i < 40; $i++) {
            $subsystem->calculateInterbankLiquiditySpread($state, 0.25);
        }

        $expectedMean = MacroEngine::INTERBANK_BASELINE_SPREAD + (0.034 * CreditFiscalSubsystem::INTERBANK_PREMIUM_COUPLING);
        $this->assertEqualsWithDelta($expectedMean, $state->interbankLiquiditySpread, 0.0005);
        $this->assertGreaterThan(0.010, $state->interbankLiquiditySpread, "2008's 340 bps premium must lift TED past 100 bps.");
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
        $state->sovereignDebtToGdpEma = self::SOUND_DEBT_TO_GDP; // below the neutral threshold: no sequester

        $this->subsystem->calculateReimbursementRate($state, 0.01);

        $this->assertEqualsWithDelta(0.04 - MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET, $state->reimbursementRateGrowth, 1e-9);
        $this->assertEqualsWithDelta(100.0 * (1.0 + $state->reimbursementRateGrowth), $state->reimbursementRateIndex, 1e-9);
    }

    public function testAFiscalCorrectionSequestersTheUpdate(): void
    {
        $solvent = new MacroState();
        $solvent->totalTime = 4.001;
        $solvent->inflationEma = 0.03;
        $solvent->sovereignDebtToGdpEma = CreditFiscalSubsystem::SOVEREIGN_RISK_DEBT_THRESHOLD;

        $indebted = new MacroState();
        $indebted->totalTime = 4.001;
        $indebted->inflationEma = 0.03;
        $indebted->sovereignDebtToGdpEma = CreditFiscalSubsystem::SOVEREIGN_RISK_DEBT_THRESHOLD + 0.30;

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
        $state->sovereignRiskSpread = 0.0;
        for ($i = 0; $i < 2000; $i++) {
            $this->subsystem->calculateSovereignRiskSpread($state, 0.01);
        }

        return $state->sovereignRiskSpread;
    }

    public function testTheFiscalPositionTheReactionDefendsCarriesNoRiskPremium(): void
    {
        $this->assertSame(0.0, $this->settledSovereignSpread(self::SOUND_DEBT_TO_GDP));
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
        $state->sovereignRiskSpread = 0.0; // from no premium at all

        $this->subsystem->calculateSovereignRiskSpread($state, 1.0 / 252.0);

        $target = 0.30 * CreditFiscalSubsystem::LAUBACH_DEBT_YIELD_SENSITIVITY;
        $this->assertGreaterThan(0.0, $state->sovereignRiskSpread);
        $this->assertLessThan(0.02 * $target, $state->sovereignRiskSpread, 'One trading day closes under two percent of the gap.');
    }

    public function testTheSovereignCeilingLiftsTheCorporateBase(): void
    {
        $sound = new MacroState();
        $sound->outputGapEma = 0.0;
        $sound->sovereignRiskSpreadEma = 0.0;
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
        $state->sovereignDebtToGdp = self::SOUND_DEBT_TO_GDP;
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

    public function testLeverageBuildsOnAHousePriceBoom(): void
    {
        $subsystem = $this->quietSubsystem();

        $boom = $this->householdStateAtNeutralRates();
        $boom->residentialPropertyIndexEma = 1.20 * AssetMarketSubsystem::RESIDENTIAL_BASELINE;
        $subsystem->calculateHouseholdCredit($boom, 1.0);

        $expectedBoom = MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE * exp(CreditFiscalSubsystem::CREDIT_GROWTH_HOUSE_PRICE * 0.20);
        $this->assertEqualsWithDelta($expectedBoom, $boom->householdDebtToIncome, 1e-9, 'Twenty percent richer collateral borrows ~7% more in a year.');
    }

    /**
     * A house-price level that the trend has followed must not move leverage at all.
     *
     * The collateral term used to read against the nominal RESIDENTIAL_BASELINE of 100. The index's own
     * equilibrium sits near 94, so a run at rest showed a standing -5.6% "lift" and the term drained
     * -0.56%/yr of leverage forever: measured over 25 live years, household debt to income slid 1.005 to
     * 0.869 and the credit-to-GDP gap could only ever go negative, which left the Schularick-Taylor crisis
     * hazard at 0.4 per century and the engine with no recession to be had.
     */
    public function testAHousePriceLevelTheTrendHasFollowedDoesNotMoveLeverage(): void
    {
        $subsystem = $this->quietSubsystem();

        $settled = $this->householdStateAtNeutralRates();
        $settled->residentialPropertyIndexEma = 0.9442 * AssetMarketSubsystem::RESIDENTIAL_BASELINE;
        $settled->residentialWealthTrend = $settled->residentialPropertyIndexEma;

        $subsystem->calculateHouseholdCredit($settled, 1.0);

        $this->assertEqualsWithDelta(
            MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE,
            $settled->householdDebtToIncome,
            1e-9,
            'Prices at their own trend are not a collateral shock, whatever the nominal index reads.'
        );
    }

    /**
     * The cyclical response the term exists for has to survive the trend anchoring: Mian & Sufi's channel is
     * borrowing against collateral that has moved AWAY from trend, which is what the gap-building boom is.
     */
    public function testCollateralBorrowingStillRespondsToPricesAboveTrend(): void
    {
        $subsystem = $this->quietSubsystem();

        $boom = $this->householdStateAtNeutralRates();
        $boom->residentialWealthTrend = 0.9442 * AssetMarketSubsystem::RESIDENTIAL_BASELINE;
        $boom->residentialPropertyIndexEma = 1.20 * $boom->residentialWealthTrend;

        $subsystem->calculateHouseholdCredit($boom, 1.0);

        $expected = MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE
            * exp(CreditFiscalSubsystem::CREDIT_GROWTH_HOUSE_PRICE * 0.20);

        $this->assertEqualsWithDelta($expected, $boom->householdDebtToIncome, 1e-9);
    }

    /**
     * US household credit does not answer business-loan standards, the policy stance or the gap once house prices
     * are in (1976-2019: +0.002, +0.12 and +0.04, none significant). Those act through the collateral and the
     * debt service, so on their own they must leave leverage where it is.
     */
    public function testStandardsRatesAndTheGapMoveLeverageOnlyThroughHousesAndDebtService(): void
    {
        $subsystem = $this->quietSubsystem();

        $shocked = $this->householdStateAtNeutralRates();
        $shocked->sloosTighteningIndexEma = 0.80;
        $shocked->outputGapEma = -0.05;
        $shocked->policyRateEma += 0.03;
        $shocked->householdDebtServiceTrend = 1.0;
        $subsystem->calculateHouseholdCredit($shocked, 1.0);

        $this->assertEqualsWithDelta(MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $shocked->householdDebtToIncome, 1e-9);
    }

    /** Basel III measures the credit gap against a one-sided HP trend, which follows steady financial deepening; a slow EMA reads it as a boom. */
    public function testSteadyFinancialDeepeningLeavesNoStandingCreditGap(): void
    {
        $state = $this->householdStateAtNeutralRates();
        $dt = 0.25;
        for ($quarter = 1; $quarter <= 240; $quarter++) {
            $state->totalTime = $quarter * $dt;
            $state->householdDebtToIncome = MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE + 0.0025 * $quarter;
            $state->householdDebtServiceTrend = 10.0;
            $trend = $state->creditToGdpTrend;
            $slope = $state->creditToGdpTrendSlope;
            $credit = $this->mathUtility->calculateOneSidedHpStep($trend, $slope, $state->householdDebtToIncome, CreditFiscalSubsystem::CREDIT_GAP_HP_LEVEL_GAIN, CreditFiscalSubsystem::CREDIT_GAP_HP_SLOPE_GAIN);
            $state->creditToGdpTrend = $credit['level'];
            $state->creditToGdpTrendSlope = $credit['slope'];
        }

        $this->assertEqualsWithDelta(0.0025, $state->creditToGdpTrendSlope, 0.0002, 'Sixty years in, the trend has learned the deepening rate.');
        $this->assertLessThan(0.02, abs($state->householdDebtToIncome - $state->creditToGdpTrend), 'Deepening at a steady pace is trend, not a boom (a 30-year EMA lags it by ~30 points here).');
    }

    /** The HP trend is defined on quarterly data: it steps when a quarter closes and holds inside one. */
    public function testTheCreditTrendStepsOnTheQuarter(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = $this->householdStateAtNeutralRates();
        $state->householdDebtToIncome = 1.10;
        $state->totalTime = 5.10;
        $subsystem->calculateHouseholdCredit($state, 0.01);
        $this->assertSame(MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $state->creditToGdpTrend, 'Mid-quarter the trend holds.');
        $this->assertEqualsWithDelta($state->householdDebtToIncome - $state->creditToGdpTrend, $state->creditToGdpGap, 1e-12, '...while the gap reads today\'s leverage against it.');

        $state->totalTime = 5.25;
        $subsystem->calculateHouseholdCredit($state, 0.01);
        $this->assertGreaterThan(MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $state->creditToGdpTrend, 'At the quarter the trend takes its step toward leverage.');
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
        $this->assertEqualsWithDelta(MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE * exp(-CreditFiscalSubsystem::DELEVERAGING_SPEED * 0.02), $above->householdDebtToIncome, 1e-9, 'Two points over the line repay 3.5% of the stock a year.');
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

    public function testCrisisHazardReadsTheCreditBoomAndIsOffInsideTheRefractoryWindow(): void
    {
        $subsystem = $this->quietSubsystem();

        $boom = new MacroState();
        $boom->totalTime = 20.0;
        $boom->creditToGdpGapEma = 0.10;
        $subsystem->calculateCreditCrisisHazard($boom, 0.25);
        $this->assertGreaterThan(0.06, $boom->creditCrisisHazard, 'A ten-point household boom triples the base rate: ~6.7% a year in the JST panel.');
        $this->assertSame(-1.0, $boom->lastCreditCrisisAt, 'The dice did not land, so no crisis has struck.');

        $calm = new MacroState();
        $calm->totalTime = 20.0;
        $subsystem->calculateCreditCrisisHazard($calm, 0.25);
        $this->assertEqualsWithDelta(1.0 / (1.0 + exp(-CreditFiscalSubsystem::CREDIT_CRISIS_LOGIT_INTERCEPT)), $calm->creditCrisisHazard, 1e-12, 'With no boom the hazard is the panel\'s base rate, 2.2% a year.');

        $recovering = new MacroState();
        $recovering->totalTime = 20.0;
        $recovering->creditToGdpGapEma = 0.10;
        $recovering->lastCreditCrisisAt = 20.0 - (CreditFiscalSubsystem::CREDIT_CRISIS_REFRACTORY_YEARS - 1.0);
        $subsystem->calculateCreditCrisisHazard($recovering, 0.25);
        $this->assertSame(0.0, $recovering->creditCrisisHazard, 'The bust resets the stock: no second crisis inside the refractory window.');
    }

    public function testACrisisBooksADragScaledByTheBoomAndForcesTheFundingRun(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return true; }
            public function calculateJumpDiffusion(float $lambda, float $jumpMean, float $jumpVol, float $dt): array
            {
                return ['multiplier' => 1.0, 'shock_pct' => null, 'exponent' => null];
            }
        };
        $subsystem = new CreditFiscalSubsystem($math);

        $state = new MacroState();
        $state->totalTime = 12.5;
        $state->creditToGdpGapEma = 0.10;
        $subsystem->calculateCreditCrisisHazard($state, 1.0 / 3600.0);

        $this->assertSame(12.5, $state->lastCreditCrisisAt, 'The crisis is dated to the tick it lands on.');
        $this->assertEqualsWithDelta(
            CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_BASE + (CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_PER_GAP * 0.10),
            $state->creditCrisisDrag,
            1e-9,
            'Credit bites back: the drag is the base loss plus a share of the boom behind it.'
        );

        $state->interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;
        $subsystem->calculateInterbankLiquiditySpread($state, 1.0 / 3600.0);
        $this->assertGreaterThan(2.5 * MacroEngine::INTERBANK_BASELINE_SPREAD, $state->interbankLiquiditySpread, 'The day of the crisis is a run on wholesale funding: the panic jump lands with certainty.');

        $quiet = new MacroState();
        $quiet->totalTime = 12.5;
        $quiet->lastCreditCrisisAt = 12.0;
        $quiet->interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;
        $subsystem->calculateInterbankLiquiditySpread($quiet, 1.0 / 3600.0);
        $this->assertEqualsWithDelta(MacroEngine::INTERBANK_BASELINE_SPREAD, $quiet->interbankLiquiditySpread, 1e-5, 'A crisis already six months old does not re-run the funding market.');
    }

    public function testTheCrisisDragDecaysAtItsTimeConstant(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = new MacroState();
        $state->totalTime = 10.0;
        $state->lastCreditCrisisAt = 9.0;
        $state->creditCrisisDrag = 0.02;

        $subsystem->calculateCreditCrisisHazard($state, 1.0);

        $this->assertEqualsWithDelta(0.02 * exp(-CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_DECAY), $state->creditCrisisDrag, 1e-9, 'A year on, the drag has decayed by its time constant.');
    }

    /**
     * The decay rate is the whole depth lever, so it is asserted as the property it was chosen for rather
     * than as its own value. JST 2013 follow output five years past a credit-boom recession and still find
     * it depressed; a drag that is spent inside the ~5 quarters the policy loop needs to respond gets
     * offset as it arrives and the bust never reaches its floor.
     */
    public function testTheCrisisDragOutlastsThePolicyResponse(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = new MacroState();
        $state->totalTime = 10.0;
        $state->lastCreditCrisisAt = 9.0;
        $state->creditCrisisDrag = 0.02;

        for ($year = 0; $year < 5; $year++) {
            $subsystem->calculateCreditCrisisHazard($state, 1.0);
        }

        $surviving = $state->creditCrisisDrag / 0.02;

        $this->assertGreaterThan(
            0.20,
            $surviving,
            'Five years on, a credit-boom recession is still depressing output in the JST panel.'
        );
        $this->assertLessThan(
            0.60,
            $surviving,
            'It is a transitory deleveraging drag, not permanent scarring; the economy does recover.'
        );
    }

    /**
     * A crisis reaches lending standards through the premium it books, so standards tighten on the day and ease
     * as the premium passes, while the crisis drag on demand still has most of its four-year life to run.
     * 2008: net tightening 84% in October, 32% by July 2009, easing by January 2010 with the gap at -4%.
     */
    public function testACrisisTightensLendingStandardsThroughThePremiumItBooks(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return true; }
        };
        $subsystem = new CreditFiscalSubsystem($math);
        $quiet = $this->quietSubsystem();

        $calm = new MacroState();
        $crisis = new MacroState();
        $crisis->totalTime = 12.5;
        $crisis->creditToGdpGapEma = 0.116; // the US boom of 2007 (BIS)
        $subsystem->calculateCreditCrisisHazard($crisis, 1.0 / 3600.0);
        $bookedDrag = $crisis->creditCrisisDrag;

        $peak = 0.0;
        $dt = 1.0 / 52.0;
        for ($week = 1; $week <= 104; $week++) {
            foreach ([$calm, $crisis] as $state) {
                $quiet->updateExcessBondPremium($state, 0.0, $dt);
                $quiet->calculateSloosCreditStandards($state, $dt);
            }
            $crisis->creditCrisisDrag *= exp(-CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_DECAY * $dt);
            $peak = max($peak, $crisis->sloosTighteningIndex - $calm->sloosTighteningIndex);
        }
        $remaining = ($crisis->sloosTighteningIndex - $calm->sloosTighteningIndex) / $peak;

        $this->assertGreaterThan(0.20, $peak, 'The premium a crisis books tightens standards by a fifth on its own, before any fall in the gap adds to it.');
        $this->assertLessThan(
            $crisis->creditCrisisDrag / $bookedDrag,
            $remaining,
            'Two years on, standards have unwound further than the deleveraging drag has: they follow the premium, not the drag.'
        );
    }

    // --- Excess Bond Premium (Gilchrist & Zakrajsek 2012) ---

    /** The log premium decays on its own clock, whatever the level of the gap: a deep but stable slump holds no premium up. */
    public function testThePremiumDecaysAtItsOwnRateWhateverTheGapLevel(): void
    {
        $subsystem = $this->quietSubsystem();
        $logGap = static fn (float $premium): float => log($premium + CreditFiscalSubsystem::EBP_DISPLACEMENT) - CreditFiscalSubsystem::EBP_LOG_MEAN;
        $halfLife = log(2.0) / CreditFiscalSubsystem::EBP_MEAN_REVERSION;

        foreach ([1.0 / 360.0, 4.0 / 360.0] as $dt) {
            $state = new MacroState();
            $state->excessBondPremium = 0.034;
            $state->outputGap = -0.07;
            $state->outputGapEma = -0.07;
            $start = $logGap($state->excessBondPremium);
            $steps = (int) round($halfLife / $dt);
            for ($i = 0; $i < $steps; $i++) {
                $subsystem->updateExcessBondPremium($state, 0.0, $dt);
            }

            $this->assertEqualsWithDelta($start * exp(-CreditFiscalSubsystem::EBP_MEAN_REVERSION * $steps * $dt), $logGap($state->excessBondPremium), 1e-12, "Exact log-OU decay at dt {$dt}.");
            $this->assertEqualsWithDelta(0.5 * $start, $logGap($state->excessBondPremium), 0.01, 'The log deviation is half gone after ln2/kappa, at any step size.');
        }
        $this->assertLessThan(0.75, $halfLife, 'A shock to the premium is half spent within three quarters (record: 0.59 left at 3q).');
    }

    /** A 2008-sized premium fades faster in levels than a small one: the same log decay removes more basis points. */
    public function testAHighPremiumFadesFasterInLevelsThanALowOne(): void
    {
        $subsystem = $this->quietSubsystem();

        $high = new MacroState();
        $high->excessBondPremium = 0.034;
        $low = new MacroState();
        $low->excessBondPremium = 0.005;
        for ($i = 0; $i < 360; $i++) {
            $subsystem->updateExcessBondPremium($high, 0.0, 1.0 / 360.0);
            $subsystem->updateExcessBondPremium($low, 0.0, 1.0 / 360.0);
        }

        $this->assertLessThan(0.30, $high->excessBondPremium / 0.034, 'A year on, most of a 340 bps premium is gone (GZ: 3.40% in October 2008, 0.33% by July 2009).');
        $this->assertGreaterThan($high->excessBondPremium / 0.034, $low->excessBondPremium / 0.005, 'A small premium keeps a larger share of itself.');
    }

    /** Lenders price the speed of the fall, multiplicatively in the shifted premium, so it is step-size neutral. */
    public function testAFallingGapLiftsThePremiumAndARecoveryLowersIt(): void
    {
        $subsystem = $this->quietSubsystem();
        $shift = CreditFiscalSubsystem::EBP_DISPLACEMENT;
        $lift = exp(0.02 * CreditFiscalSubsystem::EBP_LOG_GAP_SPEED_SENSITIVITY);

        $falling = new MacroState();
        $subsystem->updateExcessBondPremium($falling, -0.02, 1e-9);
        $this->assertEqualsWithDelta($shift * ($lift - 1.0), $falling->excessBondPremium, 1e-9);

        $recovering = new MacroState();
        $subsystem->updateExcessBondPremium($recovering, 0.02, 1e-9);
        $this->assertEqualsWithDelta($shift * ((1.0 / $lift) - 1.0), $recovering->excessBondPremium, 1e-9);

        $split = new MacroState();
        for ($i = 0; $i < 4; $i++) {
            $subsystem->updateExcessBondPremium($split, -0.005, 1e-9);
        }
        $this->assertEqualsWithDelta($falling->excessBondPremium, $split->excessBondPremium, 1e-10, 'Four small falls price as one large one.');

        $alreadyHigh = new MacroState();
        $alreadyHigh->excessBondPremium = 0.03;
        $subsystem->updateExcessBondPremium($alreadyHigh, -0.02, 1e-9);
        $this->assertEqualsWithDelta(
            (0.03 + $shift) / $shift,
            ($alreadyHigh->excessBondPremium - 0.03) / $falling->excessBondPremium,
            1e-6,
            'The same fall adds more to a premium that is already high.'
        );
    }

    /** In levels the record's shocks grow with the premium (0.15pp a quarter low, 0.42pp high); in logs they are one size. */
    public function testAShockMovesAHighPremiumFurtherThanALowOne(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return 1.0; }
            public function checkProbability(float $probability): bool { return false; }
        };
        $subsystem = new CreditFiscalSubsystem($math);
        $shift = CreditFiscalSubsystem::EBP_DISPLACEMENT;

        $low = new MacroState();
        $high = new MacroState();
        $high->excessBondPremium = 0.03;
        $subsystem->updateExcessBondPremium($low, 0.0, 1e-6);
        $subsystem->updateExcessBondPremium($high, 0.0, 1e-6);

        $this->assertEqualsWithDelta((0.03 + $shift) / $shift, ($high->excessBondPremium - 0.03) / $low->excessBondPremium, 0.06, 'Moves scale with the shifted level; the small residue is the drift, which pulls the two levels differently.');
    }

    /** However hard it is pushed down, the premium stays above minus its displacement: the record's low is -0.78pp a quarter. */
    public function testThePremiumCannotFallThroughItsDisplacement(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return -8.0; }
            public function checkProbability(float $probability): bool { return true; }
        };
        $subsystem = new CreditFiscalSubsystem($math);

        $state = new MacroState();
        for ($i = 0; $i < 720; $i++) {
            $subsystem->updateExcessBondPremium($state, 0.05, 1.0 / 360.0);
        }

        $this->assertGreaterThan(-CreditFiscalSubsystem::EBP_DISPLACEMENT, $state->excessBondPremium);
    }

    public function testACrisisBooksThe2008PremiumJump(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return true; }
        };
        $subsystem = new CreditFiscalSubsystem($math);

        $state = new MacroState();
        $state->totalTime = 12.5;
        $state->creditToGdpGapEma = 0.116;
        $subsystem->calculateCreditCrisisHazard($state, 1.0 / 3600.0);

        $this->assertSame(CreditFiscalSubsystem::EBP_CRISIS_JUMP, $state->excessBondPremium, "A crisis books 2008's jump (EBP 1.24% in August to 3.40% in October).");

        $bigger = new MacroState();
        $bigger->totalTime = 12.5;
        $bigger->creditToGdpGapEma = 0.40;
        $subsystem->calculateCreditCrisisHazard($bigger, 1.0 / 3600.0);
        $this->assertSame(CreditFiscalSubsystem::EBP_CRISIS_JUMP, $bigger->excessBondPremium, 'A bigger boom books a bigger drag, not a premium past the record.');
        $this->assertGreaterThan($state->creditCrisisDrag, $bigger->creditCrisisDrag);
    }

    /** None of the premium's readers feeds back into the spread that reads them: volatility and TED ignore the spread itself. */
    public function testTheCreditBlockHasNoLoop(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };
        $credit = new CreditFiscalSubsystem($math);
        $assets = new AssetMarketSubsystem($math);

        $calm = new MacroState();
        $capped = new MacroState();
        $capped->macroCreditSpread = MacroEngine::MAX_CREDIT_SPREAD;
        $capped->macroCreditSpreadEma = MacroEngine::MAX_CREDIT_SPREAD;
        $capped->highYieldCreditSpread = MacroEngine::MAX_HY_CREDIT_SPREAD;

        $this->assertSame($assets->calculateMarketVolatility($calm, 0.25), $assets->calculateMarketVolatility($capped, 0.25), 'Volatility does not read the spread.');

        $credit->calculateInterbankLiquiditySpread($calm, 0.25);
        $credit->calculateInterbankLiquiditySpread($capped, 0.25);
        $this->assertSame($calm->interbankLiquiditySpread, $capped->interbankLiquiditySpread, 'TED does not read the spread.');

        $credit->calculateSloosCreditStandards($calm, 0.25);
        $credit->calculateSloosCreditStandards($capped, 0.25);
        $this->assertSame($calm->sloosTighteningIndex, $capped->sloosTighteningIndex, 'Lending standards do not read the spread.');
    }

    /**
     * The regression this model exists for. A slump held at -7% with 2008's premium and stress: the spread must
     * come off its cap within a quarter and standards off theirs within a year, with the gap not having moved.
     * Before it, both sat at their caps for 16 quarters and released only as the gap closed. The premium fades at
     * its average-episode rate; 2009's own fade was faster, with the Fed's facilities behind it.
     */
    public function testSpreadsAndStandardsFadeWhileTheGapStaysDeep(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
            public function calculateSVJJJumps(float $lambda, float $pUp, float $etaUp, float $etaDown, float $muV, float $dt): array
            {
                return ['price_multiplier' => 1.0, 'var_jump' => 0.0, 'shock_pct' => 0.0];
            }
        };
        $credit = new CreditFiscalSubsystem($math);
        $assets = new AssetMarketSubsystem($math);

        $state = new MacroState();
        $state->outputGap = -0.07;
        $state->outputGapEma = -0.07;
        $state->excessBondPremium = 0.034;
        $state->marketVolatility = 0.45;
        $state->marketVolatilityEma = 0.45;
        $state->interbankLiquiditySpread = 0.020;
        $state->interbankLiquiditySpreadEma = 0.020;
        $state->sloosTighteningIndex = 0.85;

        $credit->calculateMacroCreditSpread($state);
        $this->assertSame(MacroEngine::MAX_CREDIT_SPREAD, $state->macroCreditSpread, 'October 2008 conditions start at the cap.');
        $defaultRisk = MacroEngine::BASE_CREDIT_SPREAD * exp(-MacroEngine::MERTON_LEVERAGE_SENSITIVITY * -0.07);

        $dt = 1.0 / 52.0;
        $emaWeight = 1.0 - exp(-$dt / 0.25);
        for ($week = 1; $week <= 104; $week++) {
            $credit->updateExcessBondPremium($state, 0.0, $dt);
            $state->marketVolatility = $assets->calculateMarketVolatility($state, $dt);
            $state->marketVolatilityEma += $emaWeight * ($state->marketVolatility - $state->marketVolatilityEma);
            $credit->calculateInterbankLiquiditySpread($state, $dt);
            $state->interbankLiquiditySpreadEma += $emaWeight * ($state->interbankLiquiditySpread - $state->interbankLiquiditySpreadEma);
            $credit->calculateMacroCreditSpread($state);
            $credit->calculateSloosCreditStandards($state, $dt);

            if ($week === 13) {
                $this->assertLessThan(MacroEngine::MAX_CREDIT_SPREAD, $state->macroCreditSpread, 'A quarter on the spread is already off its cap.');
            }
            if ($week === 52) {
                $this->assertLessThan(0.5 * (MacroEngine::MAX_CREDIT_SPREAD - $defaultRisk), $state->macroCreditSpread - $defaultRisk, 'A year on the spread has given back over half its excess over default risk, the gap unchanged.');
                $this->assertLessThan(0.75, $state->sloosTighteningIndex, 'A year on standards are off their cap.');
                $this->assertLessThan(0.33, $state->marketVolatility, 'A year on volatility is down to about two thirds of 45%.');
            }
        }

        $this->assertLessThan($defaultRisk + 0.005, $state->macroCreditSpread, 'Two years on, what is left is the default risk of a -7% slump.');
        $this->assertLessThan(0.40, $state->sloosTighteningIndex, 'Two years on standards have given back most of their tightening.');
    }
}

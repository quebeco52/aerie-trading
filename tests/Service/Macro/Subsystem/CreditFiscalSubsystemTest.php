<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Subsystem;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\Aggregate\DomesticDemand;
use App\Service\Macro\Subsystem\Aggregate\OpenEconomy;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\CreditRisk;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;
use PHPUnit\Framework\TestCase;

class CreditFiscalSubsystemTest extends TestCase
{
    /** A sound debt position, far from where markets charge a premium. */
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
        $this->assertGreaterThan(MacroConstants::BASE_CREDIT_SPREAD, $state->macroCreditSpread);
    }

    /**
     * Purchases do not lean against the cycle (US civilian 1985-2019), and a surge fades on the fitted 2.3-year
     * half-life.
     */
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
            for ($year = 1; $year <= 4; $year++) {
                for ($i = 0; $i < 360; $i++) {
                    $subsystem->calculateGovernmentSpending($state, 1.0 / 360.0);
                }
                $path[$year] = $state->governmentSpendingIndex;
            }
            $paths[] = $path;
        }

        $this->assertSame($paths[0], $paths[1], 'A slump and a boom leave purchases on the same path.');
        for ($i = 0; $i < 360 * 40; $i++) {
            $subsystem->calculateGovernmentSpending($state, 1.0 / 360.0);
        }
        $share = static fn(float $index): float => log($index / $state->governmentSpendingIndex) / log(125.0 / $state->governmentSpendingIndex);
        $this->assertGreaterThan(0.5, $share($paths[0][2]), 'More than half of a spending surge is still there after two years.');
        $this->assertLessThan(0.5, $share($paths[0][3]), 'Less than half is left after three.');
        $this->assertGreaterThan(0.25, $share($paths[0][4]), 'A quarter is still there after four years.');
    }

    /**
     * Purchases revert to the share of GDP the Diet's budget sets, so a budget's change phases in at the fitted speed
     * rather than at once.
     */
    public function testPurchasesRevertToTheShareTheBudgetSets(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = new MacroState();
        // No debt to service or repair, so the budget plans no balance and purchases follow the appropriation alone.
        $state->sovereignDebtToGdp = 0.0;
        $state->debtPace = 0.0;
        $state->publicSpendingShare = MacroConstants::PUBLIC_PURCHASES_TO_GDP * 1.10;
        $target = MacroConstants::GOVT_SPENDING_BASELINE * 1.10;

        for ($i = 0; $i < 360; $i++) {
            $subsystem->calculateGovernmentSpending($state, 1.0 / 360.0);
        }
        $afterOneYear = $state->governmentSpendingIndex;
        for ($i = 0; $i < 360 * 30; $i++) {
            $subsystem->calculateGovernmentSpending($state, 1.0 / 360.0);
        }

        $this->assertGreaterThan(MacroConstants::GOVT_SPENDING_BASELINE, $afterOneYear);
        $this->assertLessThan(MacroConstants::GOVT_SPENDING_BASELINE + (($target - MacroConstants::GOVT_SPENDING_BASELINE) / 2.0), $afterOneYear, 'Less than half the rise has come through after a year.');
        $this->assertEqualsWithDelta($target, $state->governmentSpendingIndex, 0.05, 'Purchases settle at the share the budget set.');
    }

    /**
     * Demand reads purchases against their decade trend: a level held long enough to join it is no impulse, a fresh
     * rise is.
     */
    public function testALevelHeldForADecadeIsNoFiscalImpulse(): void
    {
        $state = new MacroState();
        $state->governmentSpendingIndexEma = 110.0;
        $state->governmentSpendingIndexTrend = 110.0;
        $this->assertSame(0.0, CreditFiscalSubsystem::discretionarySpendingShift($state));

        $state->governmentSpendingIndexEma = 121.0;
        $this->assertEqualsWithDelta(0.10, CreditFiscalSubsystem::discretionarySpendingShift($state), 1e-12);
    }

    /**
     * At the founding maturity the fitted ladder holds; a shorter book reprices more of itself at the bill rate each
     * year and its coupons faster, both as 1 / maturity.
     */
    public function testTheDebtLadderScalesWithItsAverageMaturity(): void
    {
        $founding = CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY;
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::SOVEREIGN_DEBT_BILL_SHARE, CreditFiscalSubsystem::billShare($founding), 1e-12);
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::SOVEREIGN_DEBT_BILL_SHARE * $founding / 4.0, CreditFiscalSubsystem::billShare(4.0), 1e-12);

        $state = new MacroState();
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::SOVEREIGN_COUPON_REPRICING_YEARS, CreditFiscalSubsystem::couponRepricingYears($state), 1e-12);
        $state->sovereignDebtAverageMaturity = 2.0 * $founding;
        $this->assertEqualsWithDelta(2.0 * CreditFiscalSubsystem::SOVEREIGN_COUPON_REPRICING_YEARS, CreditFiscalSubsystem::couponRepricingYears($state), 1e-12);

        $state->policyRate = 0.05;
        $state->sovereignCouponRate = 0.03;
        $long = CreditFiscalSubsystem::sovereignEffectiveRate($state);
        $state->sovereignDebtAverageMaturity = 4.0;
        $this->assertGreaterThan($long, CreditFiscalSubsystem::sovereignEffectiveRate($state), 'With bills above coupons, a shorter book costs more.');
    }

    /**
     * The book reaches a new maturity only as it rolls over: each month the share of it falling due, one over its
     * average maturity, is reissued at the target, so a four-year book takes years to lengthen and never overshoots.
     */
    public function testTheBookMovesTowardItsTargetAsItRollsOver(): void
    {
        $state = new MacroState();
        $state->sovereignDebtAverageMaturity = 4.0;
        $state->sovereignDebtMaturityTarget = 6.0;
        $dt = 1.0 / 12.0;

        $this->subsystem->calculateSovereignDebt($state, $dt);
        $this->assertEqualsWithDelta(4.0 + ((1.0 - exp(-$dt / 4.0)) * 2.0), $state->sovereignDebtAverageMaturity, 1e-12, 'A month rolls over a month\'s share of the book.');

        $previous = $state->sovereignDebtAverageMaturity;
        for ($month = 1; $month < 96; ++$month) {
            $this->subsystem->calculateSovereignDebt($state, $dt);
            $this->assertGreaterThan($previous, $state->sovereignDebtAverageMaturity, 'The book lengthens every month it rolls.');
            $previous = $state->sovereignDebtAverageMaturity;
        }
        $this->assertLessThan(6.0, $state->sovereignDebtAverageMaturity, 'And never past its target.');
        $this->assertGreaterThan(5.0, $state->sovereignDebtAverageMaturity, 'Eight years roll most of a four-year book.');
    }

    /**
     * The term premium a book's maturity adds is nil at the founding book and grows with the duration the market holds
     * beyond it, debt times the extra years.
     */
    public function testMaturitySupplyPricesTheDurationBeyondTheFoundingBook(): void
    {
        $this->assertSame(0.0, CreditFiscalSubsystem::maturitySupplyTermPremium(0.9, CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY));

        $extra = CreditFiscalSubsystem::maturitySupplyTermPremium(0.9, CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY + 1.0);
        $this->assertEqualsWithDelta(0.9 * CreditFiscalSubsystem::DEBT_MATURITY_TERM_PREMIUM, $extra, 1e-15);
        $atOpeningDebt = CreditFiscalSubsystem::maturitySupplyTermPremium(MacroConstants::INITIAL_DEBT_TO_GDP, CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY + 1.0);
        $this->assertEqualsWithDelta(0.004 - $atOpeningDebt, CreditFiscalSubsystem::durationExtraction(0.004, MacroConstants::INITIAL_DEBT_TO_GDP, CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY + 1.0), 1e-15, 'A longer book puts back duration QE took out.');

        // The bond desk's curve carries the same duration as the macro's.
        $dto = new MacroStateDTO(balanceSheetIntensity: 0.004, sovereignDebtToGdpEma: 0.9, sovereignDebtAverageMaturity: CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY + 1.0);
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::durationExtraction(0.004, 0.9, CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY + 1.0), $dto->sovereignCurve()->balanceSheetIntensity, 1e-15);
    }

    /**
     * Greenwood & Vayanos (2014): the premium moves with maturity-weighted debt, so the size of the book prices as its
     * maturity does. The two terms together are the whole book against the opening one, D·M − D0·M0, nil at the opening
     * book, and more debt at the founding maturity raises the long end: 60% to 90% adds 0.29% x 4.96 x 0.30 ≈ 43bp.
     */
    public function testTheSizeOfTheBookPricesAsItsMaturityDoes(): void
    {
        $m0 = CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY;
        $d0 = MacroConstants::INITIAL_DEBT_TO_GDP;
        $this->assertSame(0.0, CreditFiscalSubsystem::debtSupplyTermPremium($d0) + CreditFiscalSubsystem::maturitySupplyTermPremium($d0, $m0));

        foreach ([[0.35, 4.0], [0.90, $m0], [0.75, 6.5]] as [$debt, $maturity]) {
            $whole = CreditFiscalSubsystem::DEBT_MATURITY_TERM_PREMIUM * (($debt * $maturity) - ($d0 * $m0));
            $this->assertEqualsWithDelta($whole, CreditFiscalSubsystem::debtSupplyTermPremium($debt) + CreditFiscalSubsystem::maturitySupplyTermPremium($debt, $maturity), 1e-15);
        }
        $this->assertGreaterThan(0.0, CreditFiscalSubsystem::debtSupplyTermPremium(0.90), 'More debt is more duration in private hands.');
        $this->assertLessThan(0.0, CreditFiscalSubsystem::debtSupplyTermPremium(0.35));
        $this->assertEqualsWithDelta(-CreditFiscalSubsystem::debtSupplyTermPremium(0.90), CreditFiscalSubsystem::durationExtraction(0.0, 0.90, $m0), 1e-15, 'It reaches the curve through the duration private hands hold.');
    }

    /** A recession cuts the budget's net taxes; the rate firms pay moves only with the law. */
    public function testBarroFiscalPolicyCutsNetTaxesInRecessionNotTheCorporateRate(): void
    {
        $state = new MacroState();
        $state->outputGapEma = -0.04;
        $state->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $state->netTaxRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE;

        $this->subsystem->calculateDynamicFiscalPolicy($state, 0.25);
        $this->assertLessThan(CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE, $state->netTaxRate);
        $this->assertSame(MacroConstants::BASE_CORPORATE_TAX_RATE, $state->corporateTaxRate, 'The cycle is not legislated.');
    }

    /**
     * The stabilisers' swing is in net taxes but not in their discretionary part, which is all the demand impulse
     * reads.
     */
    public function testTheStabilisersSwingIsNotInTheDiscretionaryStance(): void
    {
        $calm = new MacroState();
        $calm->outputGapEma = 0.0;
        $slump = clone $calm;
        $slump->outputGapEma = -0.04;

        $this->subsystem->calculateDynamicFiscalPolicy($calm, 0.25);
        $this->subsystem->calculateDynamicFiscalPolicy($slump, 0.25);

        $this->assertLessThan($calm->netTaxRate, $slump->netTaxRate);
        $this->assertSame($calm->netTaxStanceRate, $slump->netTaxStanceRate, 'Nobody decided the slump\'s tax cut.');
        $this->assertSame($calm->netTaxStanceRateTrend, $slump->netTaxStanceRateTrend);
    }

    /** At founding the two rates and the Authority's remittance raise the budget's whole take between them. */
    public function testNetTaxesAndTheCorporateRateFoundTheWholeTake(): void
    {
        $this->assertEqualsWithDelta(MacroConstants::PUBLIC_PURCHASES_TO_GDP, CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE + (MacroConstants::BASE_CORPORATE_TAX_RATE * CreditFiscalSubsystem::CORPORATE_PROFITS_TO_GDP) + CreditFiscalSubsystem::FOUNDING_CENTRAL_BANK_REMITTANCE, 1e-15);
    }

    public function testHighYieldWidensAtItsBaseMultipleOfInvestmentGrade(): void
    {
        $stateNormal = new MacroState();
        $stateNormal->outputGapEma = 0.01;
        $stateNormal->marketVolatilityEma = 0.15;
        $stateNormal->interbankLiquiditySpreadEma = MacroConstants::INTERBANK_BASELINE_SPREAD;
        // The multiple is the corporate-risk part's; the sovereign's share is additive (Durbin & Ng 2005), so hold it
        // at zero.
        $stateNormal->sovereignRiskSpreadEma = 0.0;

        $this->subsystem->calculateMacroCreditSpread($stateNormal);
        $this->assertEqualsWithDelta(MacroConstants::BASE_CREDIT_SPREAD, $stateNormal->macroCreditSpread, 0.005);
        $this->assertGreaterThan($stateNormal->macroCreditSpread, $stateNormal->highYieldCreditSpread);

        $stateRecession = new MacroState();
        $stateRecession->outputGapEma = -0.05;
        $stateRecession->marketVolatilityEma = 0.35;
        $stateRecession->interbankLiquiditySpreadEma = 0.006;
        $stateRecession->sovereignRiskSpreadEma = 0.0;

        $this->subsystem->calculateMacroCreditSpread($stateRecession);
        // Investment grade widens moderately
        $this->assertGreaterThan($stateNormal->macroCreditSpread, $stateRecession->macroCreditSpread);
        // High yield keeps its base multiple of investment grade, so it widens as IG does
        $this->assertGreaterThan($stateNormal->highYieldCreditSpread * 2.0, $stateRecession->highYieldCreditSpread);
        $this->assertEqualsWithDelta(MacroConstants::HY_BASE_SPREAD_MULTIPLIER, $stateRecession->highYieldCreditSpread / $stateRecession->macroCreditSpread, 1e-12);
    }

    /**
     * A freeze (deep contraction, 60% equity vol, the interbank spread at its cap) pins the IG spread at its cap, and
     * high yield at its multiple of it.
     */
    public function testAFreezePinsTheInvestmentGradeSpreadAtItsCap(): void
    {
        $state = new MacroState();
        $state->outputGapEma = -0.08;
        $state->marketVolatilityEma = 0.60;
        $state->interbankLiquiditySpreadEma = CreditFiscalSubsystem::INTERBANK_MAX_SPREAD;
        $state->sovereignRiskSpreadEma = 0.0;

        $this->subsystem->calculateMacroCreditSpread($state);

        $this->assertEqualsWithDelta(CreditRisk::MAX_CREDIT_SPREAD, $state->macroCreditSpread, 1e-12);
        $this->assertEqualsWithDelta(CreditRisk::MAX_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER, $state->highYieldCreditSpread, 1e-12, 'HY peaks at its multiple of the IG cap.');
    }

    public function testInterbankContagionWidensIgSpreadByTheCalibratedCoefficient(): void
    {
        $neutral = new MacroState();
        $neutral->outputGapEma = 0.0;
        $neutral->marketVolatilityEma = 0.15;
        $neutral->interbankLiquiditySpreadEma = MacroConstants::INTERBANK_BASELINE_SPREAD;

        $stressed = clone $neutral;
        $stressed->interbankLiquiditySpreadEma = MacroConstants::INTERBANK_BASELINE_SPREAD + 0.02; // +200 bps TED

        $this->subsystem->calculateMacroCreditSpread($neutral);
        $this->subsystem->calculateMacroCreditSpread($stressed);

        $this->assertEqualsWithDelta(
            0.02 * CreditRisk::INTERBANK_CREDIT_CONTAGION_SENSITIVITY,
            $stressed->macroCreditSpread - $neutral->macroCreditSpread,
            1e-9,
            'Contagion must flow through the engine constant, not a literal inside the formula.',
        );
        $this->assertLessThanOrEqual(1.0, CreditRisk::INTERBANK_CREDIT_CONTAGION_SENSITIVITY, 'Interbank stress passes into corporate spreads at most one for one.');
    }

    public function testInterbankSpreadIsCappedAtItsMaximum(): void
    {
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::INTERBANK_MAX_SPREAD, CreditFiscalSubsystem::interbankSpreadAt(0.20, 0.0), 1e-12);
    }

    /**
     * The interbank spread is a function of the premium and the crisis clock, nothing else: the base with no premium
     * and no crisis, the premium's coupled share above it, and a run that lands whole on a crisis day and decays.
     */
    public function testInterbankSpreadIsThePremiumShareAndADecayingRun(): void
    {
        $this->assertSame(MacroConstants::INTERBANK_BASELINE_SPREAD, CreditFiscalSubsystem::interbankSpreadAt(0.0, INF));
        $this->assertSame(MacroConstants::INTERBANK_BASELINE_SPREAD, CreditFiscalSubsystem::interbankSpreadAt(-0.005, INF), 'A favourable premium does not ease wholesale funding below its base.');
        $this->assertEqualsWithDelta(MacroConstants::INTERBANK_BASELINE_SPREAD + (0.034 * CreditFiscalSubsystem::INTERBANK_PREMIUM_COUPLING), CreditFiscalSubsystem::interbankSpreadAt(0.034, INF), 1e-15);
        $this->assertGreaterThan(0.010, CreditFiscalSubsystem::interbankSpreadAt(0.034, INF), 'A crisis-sized premium must lift the interbank spread past a point.');

        $this->assertEqualsWithDelta(MacroConstants::INTERBANK_BASELINE_SPREAD + CreditFiscalSubsystem::INTERBANK_CRISIS_RUN, CreditFiscalSubsystem::interbankSpreadAt(0.0, 0.0), 1e-15, 'The run lands whole on the crisis day.');
        $this->assertEqualsWithDelta(
            CreditFiscalSubsystem::INTERBANK_CRISIS_RUN * exp(-1.0),
            CreditFiscalSubsystem::interbankSpreadAt(0.0, CreditFiscalSubsystem::INTERBANK_RUN_DECAY_YEARS) - MacroConstants::INTERBANK_BASELINE_SPREAD,
            1e-15,
            'and decays on its time constant.',
        );
        $this->assertGreaterThan(MacroConstants::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD, CreditFiscalSubsystem::interbankSpreadAt(0.0, 0.0), 'A crisis day reads as a freeze.');
    }

    /** The subsystem step reads the state's premium and crisis date through the same function. */
    public function testTheInterbankStepReadsThePremiumAndTheCrisisClock(): void
    {
        $state = new MacroState();
        $state->totalTime = 20.0;
        $state->lastCreditCrisisAt = 19.5;
        $state->excessBondPremium = 0.02;
        $this->quietSubsystem()->calculateInterbankLiquiditySpread($state);

        $this->assertSame(CreditFiscalSubsystem::interbankSpreadAt(0.02, 0.5), $state->interbankLiquiditySpread);

        $never = new MacroState();
        $never->excessBondPremium = 0.02;
        $this->quietSubsystem()->calculateInterbankLiquiditySpread($never);
        $this->assertSame(CreditFiscalSubsystem::interbankSpreadAt(0.02, INF), $never->interbankLiquiditySpread, 'No crisis yet, no run.');
    }

    /**
     * The budget closes as a flow identity: the debt moves by spending less revenue plus interest less growth erosion,
     * with no surplus booked beside the flows. Spending is purchases plus what the fund pays for; revenue is taxes at
     * the rate in force plus the fund's draw.
     */
    public function testTheDeficitIsSpendingLessRevenueWithNoLedgerBesideIt(): void
    {
        $dt = 0.25;
        foreach ([false, true] as $funded) {
            $state = $this->neutralBudget(1.20, 1.3);
            $state->outputGap = -0.02;
            $state->netTaxRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE + 0.025;
            $state->governmentSpendingIndex = 104.0;
            $state->yield10yEma = 0.05;
            $state->yield10y = 0.05;
            $state->policyRate = 0.05;
            $state->sovereignCouponRate = 0.05;
            $state->nominalGdpGrowth = 0.03;
            if ($funded) {
                $state->sovereignFundDollarsPerGdp = 2.0e13;
                $state->sovereignFundDrawToGdp = 0.02;
            }
            $debt = $state->sovereignDebtToGdp;

            $this->subsystem->calculateSovereignDebt($state, $dt);

            $output = 1.3;
            $fundPaid = $funded ? 0.02 * $output : 0.0;
            $spending = (104.0 / MacroConstants::GOVT_SPENDING_BASELINE) * MacroConstants::PUBLIC_PURCHASES_TO_GDP * ($output / 0.98) + $fundPaid;
            // The Authority's paper earns the 5% the whole debt pays, in place of the founding rate the net take leaves
            // room for.
            $remittance = MonetaryPolicySubsystem::CURRENCY_TO_GDP * 0.05;
            $revenue = ((MacroConstants::PUBLIC_PURCHASES_TO_GDP - CreditFiscalSubsystem::FOUNDING_CENTRAL_BANK_REMITTANCE + $remittance + 0.025) * $output) + $fundPaid;
            $deficitToGdp = ($spending - $revenue) / $output;

            $this->assertEqualsWithDelta($deficitToGdp, $state->primaryDeficitToGdp, 1e-12, 'The published deficit is spending less revenue.');
            $this->assertEqualsWithDelta($debt + (($deficitToGdp + ((0.05 - 0.03) * $debt)) * $dt), $state->sovereignDebtToGdp, 1e-12, 'Debt moves by the deficit and r - g, nothing beside them.');
        }
    }

    /**
     * The Diet splits a consolidation between tax and purchases; the surplus is the same either way, and a planned
     * deficit loosens both the other way round.
     */
    public function testTheDietSplitsAConsolidationBetweenTaxAndPurchases(): void
    {
        $share = CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE;
        $this->assertEqualsWithDelta((1.0 - $share) * 0.02, CreditFiscalSubsystem::consolidationSpendingCut(0.02, $share), 1e-15);
        $this->assertEqualsWithDelta($share * -0.01, CreditFiscalSubsystem::consolidationSpendingCut(-0.01, $share), 1e-15, 'A planned deficit gives the tax share back as purchases.');

        $state = new MacroState();
        $state->consolidationTaxShare = $share;
        $state->publicSpendingShare = MacroConstants::PUBLIC_PURCHASES_TO_GDP;
        $state->sovereignDebtToGdp = 0.80;
        $state->debtTarget = 0.60;
        $state->debtPace = 0.01;
        $required = CreditFiscalSubsystem::requiredPrimaryBalance($state);
        $cut = CreditFiscalSubsystem::consolidationSpendingCut($required, $share);

        $this->assertGreaterThan(0.0, $required);
        $this->assertEqualsWithDelta($share * $required, CreditFiscalSubsystem::fiscalStance($state), 1e-12, 'Tax raises its share of the planned surplus.');
        $this->assertEqualsWithDelta(MacroConstants::PUBLIC_PURCHASES_TO_GDP - $cut, CreditFiscalSubsystem::purchasesTarget($state->publicSpendingShare, $required, $share), 1e-12);

        // Purchases settle on the appropriation less the cut.
        $subsystem = $this->quietSubsystem();
        for ($i = 0; $i < 360 * 30; $i++) {
            $subsystem->calculateGovernmentSpending($state, 1.0 / 360.0);
        }
        $this->assertEqualsWithDelta(MacroConstants::GOVT_SPENDING_BASELINE * ($state->publicSpendingShare - $cut) / MacroConstants::PUBLIC_PURCHASES_TO_GDP, $state->governmentSpendingIndex, 0.05);
    }

    /**
     * The planned balance holds debt where it stands at r - g, plus the year's step toward the cabinet's target: the
     * whole remaining gap when it is smaller than the pace, the pace when it is larger, and a deficit the same way
     * below target. With no pace the budget only holds debt where it is.
     */
    public function testThePlannedBalanceStepsDebtTowardTheTargetAtThePace(): void
    {
        $state = new MacroState();
        $state->debtTarget = 0.60;
        $state->debtPace = 0.005;
        $holds = static function (MacroState $at): float {
            $g = MacroConstants::TARGET_INFLATION + MacroConstants::TFP_DRIFT + $at->laborForceGrowthRate;

            return (CreditFiscalSubsystem::sovereignEffectiveRate($at) - $g) * $at->sovereignDebtToGdp;
        };

        $state->sovereignDebtToGdp = 0.90;
        $this->assertEqualsWithDelta($holds($state) + 0.005, CreditFiscalSubsystem::requiredPrimaryBalance($state), 1e-15, 'Far above target: the pace.');
        $state->sovereignDebtToGdp = 0.602;
        $this->assertEqualsWithDelta($holds($state) + 0.002, CreditFiscalSubsystem::requiredPrimaryBalance($state), 1e-12, 'Near target: the rest of the gap.');
        $state->sovereignDebtToGdp = 0.30;
        $this->assertEqualsWithDelta($holds($state) - 0.005, CreditFiscalSubsystem::requiredPrimaryBalance($state), 1e-15, 'Far below target: a deficit at the pace.');
        $state->debtPace = 0.0;
        $this->assertEqualsWithDelta($holds($state), CreditFiscalSubsystem::requiredPrimaryBalance($state), 1e-15, 'No pace: debt held where it stands.');
    }

    /** Run as the economy runs it, a cabinet's plan takes debt from 90% to its 60% target and holds it there. */
    public function testDebtFollowsTheCabinetsPathToItsTarget(): void
    {
        $state = $this->neutralBudget(0.90);
        $state->debtTarget = 0.60;
        $state->debtPace = 0.01;
        $state->netTaxRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE + CreditFiscalSubsystem::fiscalStance($state);
        $state->netTaxStanceRate = $state->netTaxRate;
        $dt = 1.0 / 48.0;
        $subsystem = $this->quietSubsystem();
        $tenYears = 0.0;
        for ($i = 1; $i <= 48 * 60; ++$i) {
            $subsystem->calculateGovernmentSpending($state, $dt);
            $subsystem->calculateDynamicFiscalPolicy($state, $dt);
            $subsystem->calculateSovereignDebt($state, $dt);
            if ($i === 48 * 10) {
                $tenYears = $state->sovereignDebtToGdp;
            }
        }

        $this->assertEqualsWithDelta(0.80, $tenYears, 0.03, 'A point a year: about ten points off in ten years.');
        $this->assertEqualsWithDelta(0.60, $state->sovereignDebtToGdp, 0.02, 'And held at the target.');
    }

    /**
     * The budget targets its balance (Norway's fiscal rule): what a policy levy raises is given back through the other
     * taxes, so a tariff leaves the deficit where it was rather than paying down debt nobody chose to repay. Demand's
     * stance does not move, as the take as a whole does not.
     */
    public function testAPolicyLevyIsGivenBackThroughTheOtherTaxes(): void
    {
        $neutral = $this->neutralBudget(CreditFiscalSubsystem::FOUNDING_DEBT_TARGET);
        $neutral->sovereignFundDollarsPerGdp = 2.0e13;
        $neutral->sovereignFundDrawToGdp = 0.02;
        $neutral->outputGapEma = 0.0;
        $neutral->publicSpendingShare = MacroConstants::PUBLIC_PURCHASES_TO_GDP;
        $tariffed = clone $neutral;
        $tariffed->importTariffRate = 0.10;
        $tariffed->carbonPrice = 40.0;

        for ($i = 0; $i < 4000; ++$i) {
            $this->subsystem->calculateDynamicFiscalPolicy($neutral, 0.01);
            $this->subsystem->calculateDynamicFiscalPolicy($tariffed, 0.01);
        }
        $levies = CreditFiscalSubsystem::publicAccounts($tariffed, 0.01)->policyLevies();
        $this->assertGreaterThan(0.005, $levies);
        $this->assertEqualsWithDelta($neutral->netTaxRate - $levies, $tariffed->netTaxRate, 1e-6, 'The other taxes fall by what the levies raise.');
        $this->assertSame($neutral->netTaxStanceRate, $tariffed->netTaxStanceRate, 'A revenue-neutral swap is no fiscal impulse.');

        $this->subsystem->calculateSovereignDebt($neutral, 0.25);
        $this->subsystem->calculateSovereignDebt($tariffed, 0.25);
        $this->assertEqualsWithDelta($neutral->primaryDeficitToGdp, $tariffed->primaryDeficitToGdp, 1e-6, 'The levies run no surplus of their own.');
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
        $stateCalm->macroCreditSpreadEma = MacroConstants::BASE_CREDIT_SPREAD;
        $stateCalm->interbankLiquiditySpreadEma = MacroConstants::INTERBANK_BASELINE_SPREAD;

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
        $stateNormal->highYieldCreditSpread = MacroConstants::BASE_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER;
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
        $neutral->highYieldCreditSpread = MacroConstants::BASE_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER;
        $neutral->sloosTighteningIndexEma = 0.0;
        $this->subsystem->calculateCorporateDefaultRate($neutral, 0.25);

        $blowout = new MacroState();
        $blowout->outputGapEma = -0.04;
        $blowout->highYieldCreditSpread = 0.20;
        $blowout->sloosTighteningIndexEma = 0.80;
        $this->subsystem->calculateCorporateDefaultRate($blowout, 0.25);

        // A neutral year at the post-1983 median, below the mean it averages to.
        $this->assertEqualsWithDelta(0.012, $neutral->corporateDefaultRate, 0.001);
        $this->assertLessThan(MacroConstants::CORPORATE_DEFAULT_BASELINE, $neutral->corporateDefaultRate);
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
        $boom->interbankLiquiditySpreadEma = MacroConstants::INTERBANK_BASELINE_SPREAD;
        $boom->sovereignRiskSpreadEma = 0.0; // a sound fiscal position, so the cycle alone sets the spread

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
        $this->assertLessThanOrEqual(CreditRisk::MAX_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER, $crisis->highYieldCreditSpread);
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
        $state->sovereignDebtToGdpEma = self::SOUND_DEBT_TO_GDP;

        $this->subsystem->calculateReimbursementRate($state, 0.01);

        $this->assertEqualsWithDelta(0.04 - MacroConstants::REIMBURSEMENT_PRODUCTIVITY_OFFSET, $state->reimbursementRateGrowth, 1e-9);
        $this->assertEqualsWithDelta(100.0 * (1.0 + $state->reimbursementRateGrowth), $state->reimbursementRateIndex, 1e-9);
    }

    /**
     * A standing consolidation lowers health prices once and holds them there; when the budget restores the money, they
     * come back.
     */
    public function testAConsolidationCutsHealthPricesOnceAndItsEndRestoresThem(): void
    {
        $state = new MacroState();
        $state->inflationEma = 0.04;
        $state->sovereignDebtToGdp = 0.70;
        $state->debtTarget = 0.50;
        $state->debtPace = 0.005;
        $state->consolidationTaxShare = 0.0;
        $cut = CreditFiscalSubsystem::consolidationSpendingCut(CreditFiscalSubsystem::requiredPrimaryBalance($state), 0.0) / MacroConstants::PUBLIC_PURCHASES_TO_GDP;
        $this->assertGreaterThan(0.01, $cut);

        $yearEnd = function () use ($state): void {
            $state->totalTime = floor($state->totalTime) + 1.001;
            $this->subsystem->calculateReimbursementRate($state, 0.01);
        };
        $indexed = 1.0 + 0.04 - MacroConstants::REIMBURSEMENT_PRODUCTIVITY_OFFSET;

        $yearEnd();
        $this->assertEqualsWithDelta(($indexed * (1.0 - $cut)) - 1.0, $state->reimbursementRateGrowth, 1e-12, 'The first year carries the cut.');
        $yearEnd();
        $yearEnd();
        $this->assertEqualsWithDelta($indexed - 1.0, $state->reimbursementRateGrowth, 1e-12, 'A cut held is not cut again.');
        $this->assertEqualsWithDelta(100.0 * ($indexed ** 3) * (1.0 - $cut), $state->reimbursementRateIndex, 1e-9);

        // The Diet turns to tax for the rest of the repair: the purchases cut ends, and the prices are restored.
        $state->consolidationTaxShare = 1.0;
        $yearEnd();
        $this->assertEqualsWithDelta(100.0 * ($indexed ** 4), $state->reimbursementRateIndex, 1e-9);
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
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };

        return new CreditFiscalSubsystem($math);
    }

    /**
     * The election pulse the government hands over lifts the index by the election lift a unit: the eve of a vote, or
     * talks under way, against a mid-term pulse at its mean.
     */
    public function testPolicyUncertaintyMovesWithTheElectionPulse(): void
    {
        $subsystem = $this->quietSubsystem();

        $atMean = new MacroState();
        $atMean->totalTime = 1.5;
        $peak = new MacroState();
        $peak->totalTime = 1.5;
        $peak->electionPulse = 1.0;

        // Four years of steps: the log-OU has closed all but a fraction of a percent of its gap to the target.
        for ($i = 0; $i < 400; $i++) {
            $subsystem->calculatePolicyUncertainty($atMean, 0.01);
            $subsystem->calculatePolicyUncertainty($peak, 0.01);
        }

        $this->assertEqualsWithDelta(CreditFiscalSubsystem::EPU_ELECTION_LIFT, log($peak->policyUncertaintyIndex / $atMean->policyUncertaintyIndex), 0.02);
        $this->assertLessThan(MacroConstants::EPU_BASELINE * exp(CreditFiscalSubsystem::EPU_ELECTION_LIFT), $peak->policyUncertaintyIndex, 'and no higher than the full election lift.');
    }

    /**
     * Slack moves the index both ways, by the BBD fit's 0.105 log points per point of unemployment above NAIRU: a
     * downturn puts the policy response in question, a tight labour market quiets it.
     */
    public function testPolicyUncertaintyMovesWithUnemploymentAboveNairu(): void
    {
        $subsystem = $this->quietSubsystem();

        $states = [];
        foreach (['tight' => -0.01, 'atNairu' => 0.0, 'slack' => 0.02] as $name => $gap) {
            $state = new MacroState();
            $state->totalTime = 1.5;
            $state->nairu = 0.045;
            $state->unemploymentRate = 0.045 + $gap;
            $states[$name] = $state;
        }

        // Four years of steps: the log-OU has closed all but a fraction of a percent of its gap to the target.
        for ($i = 0; $i < 400; $i++) {
            foreach ($states as $state) {
                $subsystem->calculatePolicyUncertainty($state, 0.01);
                $state->totalTime = 1.5;
            }
        }

        $atNairu = $states['atNairu']->policyUncertaintyIndex;
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::EPU_SLACK_LIFT * 0.02, log($states['slack']->policyUncertaintyIndex / $atNairu), 0.005, 'Two points of slack lift the index ~21 log points.');
        $this->assertEqualsWithDelta(-CreditFiscalSubsystem::EPU_SLACK_LIFT * 0.01, log($states['tight']->policyUncertaintyIndex / $atNairu), 0.005, 'A point below NAIRU lowers it, so the lift averages out over the cycle.');
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

    /**
     * Runs the repricing lag to its target so the level, not the speed, is under test; a sovereign with no fund, net =
     * gross.
     */
    private function settledSovereignSpread(float $debtToGdpEma): float
    {
        $state = new MacroState();
        $state->sovereignDebtToGdpEma = $debtToGdpEma;
        $state->sovereignNetFinancialLiabilitiesToGdpEma = $debtToGdpEma;
        $state->sovereignRiskSpread = 0.0;
        for ($i = 0; $i < 2000; $i++) {
            $this->subsystem->calculateSovereignRiskSpread($state, 0.01);
        }

        return $state->sovereignRiskSpread;
    }

    /**
     * The default premium is near nothing at the debt the District opened with and rises steeply toward the fiscal
     * limit, never past the loss in a default.
     */
    public function testTheDefaultPremiumIsNearNothingAtTheOpeningDebtAndRisesSteeply(): void
    {
        $this->assertLessThan(0.0002, $this->settledSovereignSpread(MacroConstants::INITIAL_DEBT_TO_GDP), 'Under two basis points at the opening debt.');
        $this->assertEqualsWithDelta(0.0012, $this->settledSovereignSpread(0.90), 0.0003, 'About 12bp at 90% of GDP.');
        $this->assertGreaterThan(0.015, $this->settledSovereignSpread(1.35), 'About 2% at 135%.');
        $previous = 0.0;
        foreach ([0.4, 0.8, 1.2, 1.6, 2.0, 3.0, 6.0] as $liabilities) {
            $premium = CreditRisk::sovereignDefaultPremium($liabilities, CreditFiscalSubsystem::FISCAL_LIMIT_NET_LIABILITIES, CreditFiscalSubsystem::FISCAL_LIMIT_SD, CreditFiscalSubsystem::SOVEREIGN_LOSS_GIVEN_DEFAULT);
            $this->assertGreaterThan($previous, $premium);
            $this->assertLessThanOrEqual(CreditFiscalSubsystem::SOVEREIGN_LOSS_GIVEN_DEFAULT, $premium);
            $previous = $premium;
        }
    }

    public function testTheSovereignSpreadRepricesOverBudgetRoundsNotTicks(): void
    {
        $state = new MacroState();
        $state->sovereignNetFinancialLiabilitiesToGdpEma = 1.35;
        $state->sovereignRiskSpread = 0.0; // from no premium at all

        $this->subsystem->calculateSovereignRiskSpread($state, 1.0 / 252.0);

        $target = CreditRisk::sovereignDefaultPremium(1.35, CreditFiscalSubsystem::FISCAL_LIMIT_NET_LIABILITIES, CreditFiscalSubsystem::FISCAL_LIMIT_SD, CreditFiscalSubsystem::SOVEREIGN_LOSS_GIVEN_DEFAULT);
        $this->assertGreaterThan(0.0, $state->sovereignRiskSpread);
        $this->assertLessThan(0.02 * $target, $state->sovereignRiskSpread, 'One trading day closes under two percent of the gap.');
    }

    /**
     * Two sovereigns with gross debt at 135% of GDP; one holds half of GDP in its reserve fund. The market prices what
     * is owed net of what is held (IMF Fiscal Monitor 2018), so the funded one pays a fraction of the other's premium.
     */
    public function testTheMarketPricesDebtNetOfWhatTheStateHolds(): void
    {
        $unfunded = $this->settledSovereignSpread(1.35);
        $funded = $this->settledSovereignSpread(1.35 - 0.50);

        $this->assertEqualsWithDelta(CreditRisk::sovereignDefaultPremium(1.35, CreditFiscalSubsystem::FISCAL_LIMIT_NET_LIABILITIES, CreditFiscalSubsystem::FISCAL_LIMIT_SD, CreditFiscalSubsystem::SOVEREIGN_LOSS_GIVEN_DEFAULT), $unfunded, 1e-6);
        $this->assertLessThan(0.1 * $unfunded, $funded, 'Net of its holdings the funded sovereign pays a fraction of it.');
    }

    public function testNetDebtIsGrossLessTheFundsBondsAndGrossWithoutAFund(): void
    {
        $withoutFund = new MacroState();
        $withoutFund->sovereignDebtToGdp = 1.20;
        $withoutFund->nominalGdpIndex = 1.0;
        $withoutFund->outputGap = 0.0;
        $withoutFund->governmentSpendingIndex = 100.0;
        $withoutFund->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $withFund = clone $withoutFund;
        $withFund->sovereignFundBondsToGdp = 0.50;

        $this->subsystem->calculateSovereignDebt($withoutFund, 0.25);
        $this->subsystem->calculateSovereignDebt($withFund, 0.25);

        $this->assertSame($withoutFund->sovereignDebtToGdp, $withoutFund->sovereignNetDebtToGdp, 'No fund, nothing to net.');
        $this->assertEqualsWithDelta($withFund->sovereignDebtToGdp - 0.50, $withFund->sovereignNetDebtToGdp, 1e-12);

        // The coupons are paid on gross debt, so the stock the budget accumulates stays gross.
        $this->assertSame($withoutFund->sovereignDebtToGdp, $withFund->sovereignDebtToGdp, 'Holding bonds does not change what the budget owes.');
    }

    /**
     * Net financial liabilities (OECD) net everything the state holds: the fund's equities as well as its bonds. A fund
     * all in equities leaves net debt at gross, and the budget still answers to the gross debt it borrowed.
     */
    public function testNetFinancialLiabilitiesNetTheFundWhole(): void
    {
        $state = new MacroState();
        $state->sovereignDebtToGdp = 1.20;
        $state->nominalGdpIndex = 1.0;
        $state->outputGap = 0.0;
        $state->governmentSpendingIndex = 100.0;
        $state->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $state->sovereignFundDollarsPerGdp = 2.0e13;
        $state->sovereignFundToGdp = 0.60;
        $state->sovereignFundBondsToGdp = 0.0;

        $this->subsystem->calculateSovereignDebt($state, 0.25);

        $this->assertSame($state->sovereignDebtToGdp, $state->sovereignNetDebtToGdp, 'Equities are not debt instruments: GFSM net debt stays gross.');
        $this->assertEqualsWithDelta($state->sovereignDebtToGdp - 0.60, $state->sovereignNetFinancialLiabilitiesToGdp, 1e-12);
        $this->assertGreaterThan(0.0, CreditFiscalSubsystem::fiscalStance($state), 'The budget answers to the gross debt it borrowed, whatever the fund holds.');
    }

    /**
     * Durbin & Ng (2005): a corporate yield carries the sovereign spread at the pass-through share. The District curve
     * carries all of it at the ten-year point, so the spread over that curve carries the share less one, and the
     * corporate yield (curve plus spread) loads the sovereign spread once.
     */
    public function testACorporateYieldCarriesTheSovereignSpreadAtThePassThroughShare(): void
    {
        $sound = new MacroState();
        $sound->outputGapEma = 0.0;
        $sound->sovereignRiskSpreadEma = 0.0;
        $stressed = clone $sound;
        $stressed->sovereignRiskSpreadEma = 0.008;

        $this->subsystem->calculateMacroCreditSpread($sound);
        $this->subsystem->calculateMacroCreditSpread($stressed);

        $curve = new MonetaryPolicySubsystem(new MathUtility());
        $soundCurve = $curve->calculateYieldCurve($sound, MacroConstants::TARGET_INFLATION, MacroConstants::BASE_NATURAL_RATE);
        $stressedCurve = $curve->calculateYieldCurve($stressed, MacroConstants::TARGET_INFLATION, MacroConstants::BASE_NATURAL_RATE);

        $this->assertEqualsWithDelta(0.008, $stressedCurve['yield_10y'] - $soundCurve['yield_10y'], 1e-9, 'The sovereign curve carries its own spread in full.');
        $this->assertEqualsWithDelta((CreditFiscalSubsystem::SOVEREIGN_CEILING_PASSTHROUGH - 1.0) * 0.008, $stressed->macroCreditSpread - $sound->macroCreditSpread, 1e-12);
        $this->assertEqualsWithDelta(
            CreditFiscalSubsystem::SOVEREIGN_CEILING_PASSTHROUGH * 0.008,
            ($stressedCurve['yield_10y'] + $stressed->macroCreditSpread) - ($soundCurve['yield_10y'] + $sound->macroCreditSpread),
            1e-9,
            'The corporate ten-year yield loads the sovereign spread once, at the pass-through share.',
        );
        $this->assertEqualsWithDelta($stressed->macroCreditSpread - $sound->macroCreditSpread, $stressed->highYieldCreditSpread - $sound->highYieldCreditSpread, 1e-12, 'The sovereign share is the same for every rating.');
    }

    public function testTheDeficitIsPublishedAsAShareOfGdp(): void
    {
        $state = new MacroState();
        $state->sovereignDebtToGdp = 0.90;
        $state->nominalGdpIndex = 1.0;
        $state->outputGap = 0.0;
        $state->governmentSpendingIndex = 100.0;
        $state->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $stance = CreditFiscalSubsystem::fiscalStance($state);
        $state->netTaxRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE + $stance;

        $this->subsystem->calculateSovereignDebt($state, 0.25);

        $this->assertEqualsWithDelta(-$stance, $state->primaryDeficitToGdp, 1e-9, 'The planned surplus raised by tax is the published surplus at neutral purchases.');
    }

    /**
     * Norway's fiscal rule: with a fund the structural deficit is the draw, spent. At neutral spending and tax the
     * budget balances whatever the fund pays, so a fund that outgrows the economy cannot turn into a standing surplus.
     */
    public function testWithAFundTheBudgetSpendsTheDraw(): void
    {
        $small = $this->neutralBudget(self::SOUND_DEBT_TO_GDP, 1.3);
        $small->sovereignFundDollarsPerGdp = 2.0e13;
        $small->sovereignFundDrawToGdp = 0.015;
        $large = clone $small;
        $large->sovereignFundDrawToGdp = 0.06;

        $this->subsystem->calculateSovereignDebt($small, 0.25);
        $this->subsystem->calculateSovereignDebt($large, 0.25);

        $this->assertEqualsWithDelta(0.0, $small->primaryDeficitToGdp, 1e-12, 'The draw pays the structural deficit it finances.');
        $this->assertEqualsWithDelta(0.0, $large->primaryDeficitToGdp, 1e-12, 'Four times the draw is four times the spending, not a surplus.');
        $this->assertSame($small->sovereignDebtToGdp, $large->sovereignDebtToGdp);
    }

    /**
     * Below the floor a surplus has no debt left to retire, so it buys the fund's paper: debt retired plus money paid
     * in equals the surplus, and none of it is lost to the clamp.
     */
    public function testASurplusBelowTheDebtFloorIsPaidIntoTheFund(): void
    {
        $dt = 0.5;
        $dollarsPerGdp = 2.0e13;
        $surplus = 0.04;
        // Above the floor: the whole surplus retires debt.
        $reference = $this->surplusBudget(0.50, $surplus);
        $reference->sovereignFundDollarsPerGdp = $dollarsPerGdp;
        $atFloor = $this->surplusBudget(MacroConstants::SOVEREIGN_DEBT_FLOOR + 0.005, $surplus);
        $atFloor->sovereignFundDollarsPerGdp = $dollarsPerGdp;

        $this->subsystem->calculateSovereignDebt($reference, $dt);
        $this->subsystem->calculateSovereignDebt($atFloor, $dt);

        $retired = 0.50 - $reference->sovereignDebtToGdp;
        $this->assertEqualsWithDelta($surplus * $dt, $retired, 1e-12, 'At r = g the debt falls by the surplus.');
        $this->assertSame(0.0, $reference->sovereignFundBudgetInflow, 'Above the floor nothing is paid in.');
        $this->assertSame(MacroConstants::SOVEREIGN_DEBT_FLOOR, $atFloor->sovereignDebtToGdp);
        $this->assertEqualsWithDelta(
            ($retired - 0.005) * $dollarsPerGdp * $atFloor->nominalGdpIndex,
            $atFloor->sovereignFundBudgetInflow,
            1e-3,
            'What the floor stops from retiring debt is paid into the fund.',
        );
    }

    /**
     * With no fund to sweep it into, a surplus past the floor stays with the Exchequer: it keeps retiring debt into net
     * assets.
     */
    public function testWithNoFundTheSurplusKeepsRetiringDebt(): void
    {
        $state = $this->surplusBudget(MacroConstants::SOVEREIGN_DEBT_FLOOR + 0.005, 0.04);

        $this->subsystem->calculateSovereignDebt($state, 0.5);

        $this->assertLessThan(MacroConstants::SOVEREIGN_DEBT_FLOOR, $state->sovereignDebtToGdp);
        $this->assertSame(0.0, $state->sovereignFundBudgetInflow, 'No fund, nothing to buy.');
    }

    // --- Fund-financed stabilisation (IMF Norway 2025, Table 5) ---

    // --- The Diet's levers in the budget ---

    public function testTheTaxRateSettlesOnTheNeutralRatePlusTheLegislatedShift(): void
    {
        $state = new MacroState();
        $state->outputGapEma = 0.0;
        $state->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $state->corporateTaxPolicyShift = -0.025;
        // The corporate rate answers the law alone: whatever the debt, it settles on the neutral rate plus the shift.
        $state->sovereignDebtToGdp = 0.90;

        for ($i = 0; $i < 2000; ++$i) {
            $this->subsystem->calculateDynamicFiscalPolicy($state, 0.01);
        }

        $this->assertEqualsWithDelta(MacroConstants::BASE_CORPORATE_TAX_RATE - 0.025, $state->corporateTaxRate, 1e-6);
    }

    public function testALegislatedShiftIsLeviedOnCorporateProfitsNotOnGdp(): void
    {
        $neutral = $this->neutralBudget(0.60);
        $raised = $this->neutralBudget(0.60);
        $raised->corporateTaxPolicyShift = 0.03;
        $raised->corporateTaxShiftRealized = 0.03;
        $raised->corporateTaxRate += 0.03;
        // Enacted but not yet in the rate firms pay: nothing is levied yet.
        $enacted = $this->neutralBudget(0.60);
        $enacted->corporateTaxPolicyShift = 0.03;

        $this->subsystem->calculateSovereignDebt($neutral, 0.25);
        $this->subsystem->calculateSovereignDebt($raised, 0.25);
        $this->subsystem->calculateSovereignDebt($enacted, 0.25);

        $this->assertEqualsWithDelta(-0.03 * CreditFiscalSubsystem::CORPORATE_PROFITS_TO_GDP, $raised->primaryDeficitToGdp - $neutral->primaryDeficitToGdp, 1e-12);
        $this->assertEqualsWithDelta($neutral->primaryDeficitToGdp, $enacted->primaryDeficitToGdp, 1e-12, 'Revenue is the rate in force, not the law ahead of it.');
    }

    public function testATariffIsLeviedOnImportedGoodsAtTheirReducedVolume(): void
    {
        $neutral = $this->neutralBudget(0.60);
        $tariffed = $this->neutralBudget(0.60);
        $tariffed->importTariffRate = 0.10;

        $this->subsystem->calculateSovereignDebt($neutral, 0.25);
        $this->subsystem->calculateSovereignDebt($tariffed, 0.25);

        $revenue = 0.10 * OpenEconomy::DISTRICT_IMPORT_SHARE * OpenEconomy::GOODS_SHARE_OF_IMPORTS * (1.10 ** -OpenEconomy::IMPORT_PRICE_ELASTICITY);
        $this->assertEqualsWithDelta(-$revenue, $tariffed->primaryDeficitToGdp - $neutral->primaryDeficitToGdp, 1e-12);
    }

    public function testDebtErodesAtNominalGdpGrowth(): void
    {
        $slow = $this->neutralBudget(1.0);
        $fast = $this->neutralBudget(1.0);
        $fast->nominalGdpGrowth = $slow->nominalGdpGrowth + 0.01;

        $this->subsystem->calculateSovereignDebt($slow, 0.25);
        $this->subsystem->calculateSovereignDebt($fast, 0.25);

        $this->assertEqualsWithDelta(-0.01 * 1.0 * 0.25, $fast->sovereignDebtToGdp - $slow->sovereignDebtToGdp, 1e-12);
    }

    /**
     * Taxes are levied on the GDP produced, so at a given rate their share of GDP does not move with the gap; the slump
     * shows in the budget through purchases set on trend, whose share of a smaller GDP rises.
     */
    public function testTheGapReachesTheBudgetThroughPurchasesNotATaxBaseCountedTwice(): void
    {
        $neutral = $this->neutralBudget(0.60);
        $neutral->governmentSpendingIndex = 0.0;
        $slump = $this->neutralBudget(0.60, 0.97);
        $slump->outputGap = -0.03;
        $slump->governmentSpendingIndex = 0.0;

        $this->subsystem->calculateSovereignDebt($neutral, 0.25);
        $this->subsystem->calculateSovereignDebt($slump, 0.25);

        $this->assertEqualsWithDelta($neutral->primaryDeficitToGdp, $slump->primaryDeficitToGdp, 1e-12, 'Revenue over GDP at a given rate is the rate.');

        $spending = $this->neutralBudget(0.60);
        $spendingSlump = $this->neutralBudget(0.60, 0.97);
        $spendingSlump->outputGap = -0.03;

        $this->subsystem->calculateSovereignDebt($spending, 0.25);
        $this->subsystem->calculateSovereignDebt($spendingSlump, 0.25);

        $this->assertEqualsWithDelta(
            MacroConstants::PUBLIC_PURCHASES_TO_GDP * ((1.0 / 0.97) - 1.0),
            $spendingSlump->primaryDeficitToGdp - $spending->primaryDeficitToGdp,
            1e-12,
            'Purchases on trend are a larger share of a GDP 3% under it.',
        );
    }

    /**
     * The ratio is struck on actual GDP, so the growth eroding it is that GDP's growth: a slump that recovers to where
     * it began hands back every point it took, where reading the gap's level as a growth rate kept the slump's toll.
     */
    public function testASlumpThatRecoversLeavesNoGrowthErosionBehind(): void
    {
        $aggregate = MacroAggregateSubsystem::headless(new MathUtility());
        $state = new MacroState();
        $state->laborForceGrowthRate = 0.0;
        $state->domesticInflation = 0.0;
        $state->outputGap = 0.0;
        $state->potentialGdpIndex = 1.0;
        $state->gdpDeflator = 1.0;
        $state->nominalGdpIndex = 1.0;

        $dt = 0.25;
        $erosion = 0.0;
        foreach ([0.0, -0.02, -0.03, -0.03, -0.02, -0.01, 0.0] as $gap) {
            $state->outputGap = $gap;
            $aggregate->calculatePotentialAndNominalGdp($state, $dt, 0.0);
            $erosion += $state->nominalGdpGrowth * $dt;
        }

        $this->assertEqualsWithDelta(0.0, $erosion, 1e-12);
        $this->assertLessThan(-0.019, $this->firstQuarterGrowth(-0.02, $dt), 'The slump itself still shrinks the denominator.');
    }

    private function firstQuarterGrowth(float $gap, float $dt): float
    {
        $state = new MacroState();
        $state->laborForceGrowthRate = 0.0;
        $state->domesticInflation = 0.0;
        $state->potentialGdpIndex = 1.0;
        $state->gdpDeflator = 1.0;
        $state->nominalGdpIndex = 1.0;
        $state->outputGap = $gap;
        (MacroAggregateSubsystem::headless(new MathUtility()))->calculatePotentialAndNominalGdp($state, $dt, 0.0);

        return $state->nominalGdpGrowth * $dt;
    }

    /** A budget at neutral spending and tax with output at trend. */
    private function neutralBudget(float $debtToGdp, float $nominalGdpIndex = 1.0): MacroState
    {
        $state = new MacroState();
        $state->sovereignDebtToGdp = $debtToGdp;
        $state->nominalGdpIndex = $nominalGdpIndex;
        $state->outputGap = 0.0;
        $state->governmentSpendingIndex = 100.0;
        $state->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $state->netTaxRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE;

        return $state;
    }

    /**
     * A budget running a primary surplus of $surplus of GDP (taxes that much above neutral, with no fund draw), with
     * the long yield at nominal trend growth so r - g is zero and the debt moves by the surplus alone.
     */
    private function surplusBudget(float $debtToGdp, float $surplus): MacroState
    {
        $state = $this->neutralBudget($debtToGdp);
        $state->netTaxRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE + $surplus;
        $state->inflationEma = MacroConstants::TARGET_INFLATION;
        $trendGrowth = MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + MacroConstants::TFP_DRIFT + MacroConstants::TARGET_INFLATION;
        $state->yield10yEma = $trendGrowth;
        $state->yield10y = $trendGrowth;
        $state->policyRate = $trendGrowth;
        $state->sovereignCouponRate = $trendGrowth;
        // The Authority's remittance at these rates, not the founding ones, is part of the take: net it out of the
        // surplus.
        $state->netTaxRate += CreditFiscalSubsystem::FOUNDING_CENTRAL_BANK_REMITTANCE - MonetaryPolicySubsystem::authorityNetIncomeToGdp($state);

        return $state;
    }

    // --- Sovereign Debt Maturity Ladder ---

    /**
     * With rates held, the coupon stock rolls onto the 10-year and the debt pays the bill and coupon rates in their
     * shares.
     */
    public function testTheDebtPaysItsBillAndCouponRatesInTheirShares(): void
    {
        $state = $this->neutralBudget(0.80);
        $state->policyRate = 0.01;
        $state->yield10y = 0.05;
        $state->sovereignCouponRate = 0.02;
        for ($i = 0; $i < 400; ++$i) {
            $this->subsystem->calculateSovereignDebt($state, 0.1);
        }

        $this->assertEqualsWithDelta(0.05, $state->sovereignCouponRate, 1e-6);
        $this->assertEqualsWithDelta(
            (CreditFiscalSubsystem::SOVEREIGN_DEBT_BILL_SHARE * 0.01) + ((1.0 - CreditFiscalSubsystem::SOVEREIGN_DEBT_BILL_SHARE) * 0.05),
            CreditFiscalSubsystem::sovereignEffectiveRate($state),
            1e-6,
        );
    }

    /** A jump in the 10-year reaches the coupon stock over its repricing constant, at any tick length. */
    public function testTheCouponStockRepricesOnItsFittedTimeConstant(): void
    {
        $tau = CreditFiscalSubsystem::SOVEREIGN_COUPON_REPRICING_YEARS;
        foreach ([0.25, 1.0 / 360.0] as $dt) {
            $state = $this->neutralBudget(0.80);
            $state->policyRate = 0.03;
            $state->sovereignCouponRate = 0.03;
            $state->yield10y = 0.05;
            for ($i = 0, $n = (int) round($tau / $dt); $i < $n; ++$i) {
                $this->subsystem->calculateSovereignDebt($state, $dt);
            }

            $this->assertEqualsWithDelta(0.03 + (0.02 * (1.0 - exp(-1.0))), $state->sovereignCouponRate, 1e-9, "One time constant in at dt {$dt}.");
        }
    }

    /** Debt issued at low rates keeps paying them: a spike in the 10-year costs the budget only as the debt rolls. */
    public function testTheInterestBillIsTheRateTheDebtWasIssuedAtNotTodaysTenYear(): void
    {
        $locked = $this->neutralBudget(1.0);
        $locked->policyRate = 0.02;
        $locked->sovereignCouponRate = 0.02;
        $locked->yield10y = 0.06;
        $locked->yield10yEma = 0.06;
        $repriced = clone $locked;
        $repriced->sovereignCouponRate = 0.06;
        $repriced->policyRate = 0.06;

        $this->subsystem->calculateSovereignDebt($locked, 0.01);
        $this->subsystem->calculateSovereignDebt($repriced, 0.01);

        // One hundredth of a year: interest 0.0002 of GDP on locked-in debt against 0.0006 had it all repriced, less
        // what the Authority's paper backing the currency earns of it and pays back.
        $this->assertEqualsWithDelta(0.04 * (1.0 - MonetaryPolicySubsystem::CURRENCY_TO_GDP) * 0.01, $repriced->sovereignDebtToGdp - $locked->sovereignDebtToGdp, 2e-5);
    }

    // --- Household credit cycle (Mian & Sufi 2018; BIS DSR; Basel III CCyB) ---

    /** A state whose household rates sit at the engine's neutral, so only the term under test moves leverage. */
    private function householdStateAtNeutralRates(): MacroState
    {
        $state = new MacroState();
        $state->yield10yEma = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION + MacroConstants::NS_BASE_TERM_PREMIUM;
        $state->policyRateEma = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;
        $state->outputGapEma = 0.0;
        // Leverage at rest: on the baseline households revert to, with its trend there too.
        $state->householdDebtToIncome = MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE;
        $state->householdDebtToIncomeEma = MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE;
        $state->creditToGdpTrend = MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE;
        $state->householdDebtServiceTrend = MacroConstants::HOUSEHOLD_DSR_NEUTRAL;

        return $state;
    }

    /**
     * What lenders charge off leaves the household debt stock: a default rate above its long-run average takes the
     * excess charge-off flow out of leverage, and at the average nothing beyond what the fitted drift already carries.
     */
    public function testChargedOffDebtLeavesTheHouseholdStock(): void
    {
        $subsystem = $this->quietSubsystem();
        $calm = $this->householdStateAtNeutralRates();
        $calm->retailDefaultRate = MacroConstants::RETAIL_DEFAULT_LONG_RUN_MEAN;
        $crisis = $this->householdStateAtNeutralRates();
        $crisis->retailDefaultRate = 0.06;

        $subsystem->calculateHouseholdCredit($calm, 1.0);
        $subsystem->calculateHouseholdCredit($crisis, 1.0);

        $excessChargeOff = $subsystem->householdChargeOffRate(0.06) - $subsystem->householdChargeOffRate(MacroConstants::RETAIL_DEFAULT_LONG_RUN_MEAN);
        $this->assertEqualsWithDelta(MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $calm->householdDebtToIncome, 1e-12, 'At the long-run default rate the stock holds.');
        $this->assertEqualsWithDelta(exp(-$excessChargeOff), $crisis->householdDebtToIncome / $calm->householdDebtToIncome, 1e-12, 'The excess charge-off flow leaves the stock.');
        $this->assertGreaterThan(0.01, $excessChargeOff, 'A 2009-scale default rate charges off well over a point of the stock a year.');
    }

    /**
     * The household book charges off at its segments' FRED long-run rates, in the stock's mortgage and consumer shares.
     */
    public function testHouseholdChargeOffsSitAtTheirLongRunRatesInCalmYears(): void
    {
        $longRun = (CreditFiscalSubsystem::HOUSEHOLD_MORTGAGE_DEBT_SHARE * CreditFiscalSubsystem::HOUSEHOLD_MORTGAGE_CHARGE_OFF_RATE)
            + ((1.0 - CreditFiscalSubsystem::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * CreditFiscalSubsystem::HOUSEHOLD_CONSUMER_CHARGE_OFF_RATE);

        $this->assertEqualsWithDelta($longRun, $this->subsystem->householdChargeOffRate(CreditFiscalSubsystem::RETAIL_DEFAULT_BASELINE), 0.1 * $longRun);
        $this->assertGreaterThan($this->subsystem->householdChargeOffRate(CreditFiscalSubsystem::RETAIL_DEFAULT_BASELINE), $this->subsystem->householdChargeOffRate(0.04));
    }

    /**
     * The IG spread's measured trend closes on a sustained level over its decade's time constant, whatever the tick.
     */
    public function testTheCreditSpreadTrendIsADecadesAverage(): void
    {
        $path = function (int $ticksPerYear): float {
            $state = new MacroState();
            $state->macroCreditSpreadTrend = MacroConstants::BASE_CREDIT_SPREAD;
            $state->macroCreditSpread = MacroConstants::BASE_CREDIT_SPREAD + 0.01;
            for ($tick = 0; $tick < 10 * $ticksPerYear; ++$tick) {
                $this->subsystem->updateCreditSpreadTrend($state, 1.0 / $ticksPerYear);
            }

            return $state->macroCreditSpreadTrend;
        };

        $this->assertEqualsWithDelta(MacroConstants::BASE_CREDIT_SPREAD + (0.01 * (1.0 - exp(-10.0 / CreditFiscalSubsystem::CREDIT_SPREAD_TREND_YEARS))), $path(12), 1e-12);
        $this->assertEqualsWithDelta($path(12), $path(252), 1e-12);
    }

    public function testLeverageBuildsOnAHousePriceBoom(): void
    {
        $subsystem = $this->quietSubsystem();

        $boom = $this->householdStateAtNeutralRates();
        $boom->residentialPropertyIndexEma = 1.20 * AssetMarketSubsystem::RESIDENTIAL_BASELINE;
        $subsystem->calculateHouseholdCredit($boom, 1.0);

        $expectedBoom = MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE * exp(CreditFiscalSubsystem::CREDIT_GROWTH_HOUSE_PRICE * 0.20);
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
            MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE,
            $settled->householdDebtToIncome,
            1e-9,
            'Prices at their own trend are not a collateral shock, whatever the nominal index reads.',
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

        $expected = MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE
            * exp(CreditFiscalSubsystem::CREDIT_GROWTH_HOUSE_PRICE * 0.20);

        $this->assertEqualsWithDelta($expected, $boom->householdDebtToIncome, 1e-9);
    }

    /**
     * Drehmann, Juselius & Korinek (2018): new borrowing is the flow into the stock, read as the year's average of the
     * change in leverage.
     */
    public function testNewBorrowingIsTheYearsAverageChangeInLeverage(): void
    {
        $subsystem = $this->quietSubsystem();

        $boom = $this->householdStateAtNeutralRates();
        $boom->residentialPropertyIndexEma = 1.20 * $boom->residentialWealthTrend;
        $before = $boom->householdDebtToIncome;
        $dt = 0.25;
        $subsystem->calculateHouseholdCredit($boom, $dt);

        $weight = 1.0 - exp(-$dt / CreditFiscalSubsystem::HOUSEHOLD_NEW_BORROWING_HORIZON_YEARS);
        $this->assertGreaterThan($before, $boom->householdDebtToIncome);
        $this->assertEqualsWithDelta($weight * ($boom->householdDebtToIncome - $before) / $dt, $boom->householdNewBorrowing, 1e-12);
    }

    /**
     * A high stock that holds still borrows nothing new: once leverage stops rising the flow fades over the year it
     * averages.
     */
    public function testNewBorrowingFadesOnceLeverageHoldsStill(): void
    {
        $subsystem = $this->quietSubsystem();

        $state = $this->householdStateAtNeutralRates();
        $state->householdNewBorrowing = 0.05;
        for ($quarter = 0; $quarter < 4; $quarter++) {
            $subsystem->calculateHouseholdCredit($state, 0.25);
        }

        $this->assertEqualsWithDelta(MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $state->householdDebtToIncome, 1e-12, 'At baseline leverage with no lift the stock holds.');
        $this->assertEqualsWithDelta(0.05 * exp(-1.0 / CreditFiscalSubsystem::HOUSEHOLD_NEW_BORROWING_HORIZON_YEARS), $state->householdNewBorrowing, 1e-12);
    }

    /** The flow is the average of a change, so it must read the same at any tick rate. */
    public function testNewBorrowingIsTimestepNeutral(): void
    {
        $subsystem = $this->quietSubsystem();
        $flows = [];
        foreach ([4, 360] as $ticksPerYear) {
            $state = $this->householdStateAtNeutralRates();
            $state->residentialPropertyIndexEma = 1.20 * $state->residentialWealthTrend;
            for ($tick = 0; $tick < 2 * $ticksPerYear; $tick++) {
                $subsystem->calculateHouseholdCredit($state, 1.0 / $ticksPerYear);
            }
            $flows[$ticksPerYear] = $state->householdNewBorrowing;
        }

        $this->assertGreaterThan(0.03, $flows[360], 'A twenty percent collateral lift borrows several points of income a year.');
        $this->assertEqualsWithDelta($flows[360], $flows[4], 0.03 * $flows[360], 'Quarterly and daily ticks read the same flow.');
    }

    /**
     * Households borrow against their homes up to the loan-to-value cap, which is their lending standard; the banks'
     * standards index reads business lending, and counting it here would ration the same borrowing twice. Standards,
     * the policy stance and the gap act through the collateral and the debt service, so on their own they must leave
     * leverage where it is.
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

        $this->assertEqualsWithDelta(MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $shocked->householdDebtToIncome, 1e-9);
    }

    /**
     * Basel III measures the credit gap against a one-sided HP trend, which follows steady financial deepening; a slow
     * EMA reads it as a boom.
     */
    public function testSteadyFinancialDeepeningLeavesNoStandingCreditGap(): void
    {
        $state = $this->householdStateAtNeutralRates();
        $dt = 0.25;
        for ($quarter = 1; $quarter <= 240; $quarter++) {
            $state->totalTime = $quarter * $dt;
            $state->householdDebtToIncome = MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE + 0.0025 * $quarter;
            $state->householdDebtServiceTrend = 10.0;
            $trend = $state->creditToGdpTrend;
            $slope = $state->creditToGdpTrendSlope;
            $credit = TimeSeries::calculateOneSidedHpStep($trend, $slope, $state->householdDebtToIncome, CreditFiscalSubsystem::CREDIT_GAP_HP_LEVEL_GAIN, CreditFiscalSubsystem::CREDIT_GAP_HP_SLOPE_GAIN);
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
        $this->assertSame(MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $state->creditToGdpTrend, 'Mid-quarter the trend holds.');
        $this->assertEqualsWithDelta($state->householdDebtToIncome - $state->creditToGdpTrend, $state->creditToGdpGap, 1e-12, '...while the gap reads today\'s leverage against it.');

        $state->totalTime = 5.25;
        $subsystem->calculateHouseholdCredit($state, 0.01);
        $this->assertGreaterThan(MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $state->creditToGdpTrend, 'At the quarter the trend takes its step toward leverage.');
    }

    public function testTheDebtServiceRatioIsTheBisAnnuityAndRisesWithRates(): void
    {
        $subsystem = $this->quietSubsystem();
        $dt = 1.0 / 3600.0;

        $neutral = $this->householdStateAtNeutralRates();
        $subsystem->calculateHouseholdCredit($neutral, $dt);
        $this->assertEqualsWithDelta(MacroConstants::HOUSEHOLD_DSR_NEUTRAL, $neutral->householdDebtServiceRatio, 5e-4, 'Baseline leverage at neutral rates services at the documented 10.2% of income.');

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

    /**
     * A regulator with a positive neutral rate holds it while credit is calm, the Basel schedule above it, and releases
     * all of it in a crisis.
     */
    public function testANeutralBufferIsHeldUntilTheScheduleRisesAboveIt(): void
    {
        $subsystem = $this->quietSubsystem();
        $settle = function (float $creditGapEma, float $neutral, float $crisisAt = -1.0) use ($subsystem): float {
            $state = $this->householdStateAtNeutralRates();
            $state->neutralCountercyclicalBuffer = $neutral;
            $state->creditToGdpGapEma = $creditGapEma;
            $state->lastCreditCrisisAt = $crisisAt;
            for ($i = 0; $i < 40; $i++) {
                $subsystem->calculateHouseholdCredit($state, 0.25);
                $state->creditToGdpGapEma = $creditGapEma;
                $state->lastCreditCrisisAt = $crisisAt < 0.0 ? -1.0 : $state->totalTime;
            }

            return $state->countercyclicalBufferRate;
        };

        $this->assertEqualsWithDelta(0.02, $settle(0.0, 0.02), 1e-5, 'Calm credit, the neutral 2%.');
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::MAX_CCYB, $settle(CreditFiscalSubsystem::CCYB_GAP_CEILING + 0.02, 0.02), 1e-5, 'A boom takes it to the full 2.5%.');
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::MAX_CCYB / 2.0, $settle((CreditFiscalSubsystem::CCYB_GAP_FLOOR + CreditFiscalSubsystem::CCYB_GAP_CEILING) / 2.0, 0.01), 1e-5, 'The schedule above a 1% neutral rate.');
        $this->assertSame(0.0, $settle(0.0, 0.02, 0.0), 'Released in full while a crisis runs.');
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

    public function testABufferCutTakesEffectAtOnce(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = $this->householdStateAtNeutralRates();
        $state->countercyclicalBufferRate = CreditFiscalSubsystem::MAX_CCYB;
        $state->creditToGdpGapEma = (CreditFiscalSubsystem::CCYB_GAP_FLOOR + CreditFiscalSubsystem::CCYB_GAP_CEILING) / 2.0;

        $subsystem->calculateHouseholdCredit($state, 1.0 / 252.0);

        $this->assertEqualsWithDelta(CreditFiscalSubsystem::MAX_CCYB / 2.0, $state->countercyclicalBufferRate, 1e-12, 'A rise phases in; a cut is in force the day it is made.');
    }

    public function testACrisisReleasesTheWholeBufferOnTheDay(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return true;
            }
            public function calculateJumpDiffusion(float $lambda, float $jumpMean, float $jumpVol, float $dt): array
            {
                return ['multiplier' => 1.0, 'shock_pct' => null, 'exponent' => null];
            }
        };
        $state = new MacroState();
        $state->totalTime = 12.5;
        $state->creditToGdpGapEma = CreditFiscalSubsystem::CCYB_GAP_CEILING + 0.02;
        $state->countercyclicalBufferRate = CreditFiscalSubsystem::MAX_CCYB;

        (new CreditFiscalSubsystem($math))->calculateCreditCrisisHazard($state, 1.0 / 3600.0);

        $this->assertSame(0.0, $state->countercyclicalBufferRate, 'Banks may lend out of the buffer from the tick the crisis lands.');
    }

    public function testTheReleasedBufferIsHeldAtZeroForAYearThenRebuilds(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = $this->householdStateAtNeutralRates();
        $state->totalTime = 20.0;
        $state->lastCreditCrisisAt = 20.0 - (CreditFiscalSubsystem::CCYB_RELEASE_HOLD_YEARS - 0.01);
        $state->creditToGdpGapEma = CreditFiscalSubsystem::CCYB_GAP_CEILING + 0.02;

        $subsystem->calculateHouseholdCredit($state, 1.0 / 252.0);
        $this->assertSame(0.0, $state->countercyclicalBufferRate, 'Inside the hold the boom still standing does not rebuild the buffer.');

        $state->totalTime = $state->lastCreditCrisisAt + CreditFiscalSubsystem::CCYB_RELEASE_HOLD_YEARS + 0.01;
        $state->creditToGdpGapEma = CreditFiscalSubsystem::CCYB_GAP_CEILING + 0.02;
        $subsystem->calculateHouseholdCredit($state, 1.0 / 252.0);
        $this->assertGreaterThan(0.0, $state->countercyclicalBufferRate, 'Past the hold the buffer builds again, phased in.');
        $this->assertLessThan(0.01 * CreditFiscalSubsystem::MAX_CCYB, $state->countercyclicalBufferRate);
    }

    /**
     * A declared recession releases the buffer, a neutral one included, and it phases back in once the recession is
     * over.
     */
    public function testADeclaredRecessionReleasesTheBufferWithoutACrisis(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = $this->householdStateAtNeutralRates();
        $state->totalTime = 20.0;
        $state->creditToGdpGapEma = -0.10;
        $state->neutralCountercyclicalBuffer = 0.01;
        $state->countercyclicalBufferRate = 0.01;
        $state->recessionDeclared = true;

        $subsystem->calculateHouseholdCredit($state, 1.0 / 252.0);
        $this->assertSame(0.0, $state->countercyclicalBufferRate, 'A slump with no crisis still releases the buffer.');

        $state->recessionDeclared = false;
        $subsystem->calculateHouseholdCredit($state, 1.0 / 252.0);
        $this->assertGreaterThan(0.0, $state->countercyclicalBufferRate, 'Once it is over the neutral rate phases back in.');
        $this->assertLessThan(0.01 * 0.01, $state->countercyclicalBufferRate);
    }

    public function testHouseholdsDeleverageOnlyPastTheWarningLine(): void
    {
        $subsystem = $this->quietSubsystem();

        $below = $this->householdStateAtNeutralRates();
        $below->householdDebtServiceGap = MacroConstants::HOUSEHOLD_DSR_STRESS_MARGIN - 0.001;
        $subsystem->calculateHouseholdCredit($below, 1.0);

        $above = $this->householdStateAtNeutralRates();
        $above->householdDebtServiceGap = MacroConstants::HOUSEHOLD_DSR_STRESS_MARGIN + 0.02;
        $subsystem->calculateHouseholdCredit($above, 1.0);

        $this->assertEqualsWithDelta(MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $below->householdDebtToIncome, 1e-9, 'Under the line the service burden is carried, not repaid.');
        $this->assertEqualsWithDelta(MacroConstants::HOUSEHOLD_DEBT_TO_INCOME_BASELINE * exp(-CreditFiscalSubsystem::DELEVERAGING_SPEED * 0.02), $above->householdDebtToIncome, 1e-9, 'Two points over the line repay 3.5% of the stock a year.');
    }

    public function testRetailDefaultsRiseAsHousePricesFallBelowTrendAtFixedUnemployment(): void
    {
        $subsystem = $this->quietSubsystem();

        $neutral = new MacroState();
        $neutral->unemploymentRateEma = $neutral->nairu;
        $neutral->residentialPropertyIndexEma = 100.0;
        $neutral->residentialWealthTrend = 100.0;
        $neutral->macroCreditSpreadEma = $neutral->macroCreditSpreadTrend;
        $neutral->interbankLiquiditySpreadEma = $neutral->interbankLiquiditySpreadTrend;
        $subsystem->calculateRetailDefaultRate($neutral, 0.25);

        $underwater = new MacroState();
        $underwater->unemploymentRateEma = $underwater->nairu;
        $underwater->residentialPropertyIndexEma = 80.0;
        $underwater->residentialWealthTrend = 100.0;
        $underwater->macroCreditSpreadEma = $underwater->macroCreditSpreadTrend;
        $underwater->interbankLiquiditySpreadEma = $underwater->interbankLiquiditySpreadTrend;
        $subsystem->calculateRetailDefaultRate($underwater, 0.25);

        $this->assertEqualsWithDelta(CreditRisk::calculateVasicekExpectedLoss(CreditFiscalSubsystem::RETAIL_CREDIT_INTERCEPT, CreditFiscalSubsystem::RETAIL_DEFAULT_BASELINE, CreditRisk::RETAIL_ASRF_RHO, 1.0), $neutral->retailDefaultRate, 1e-12, 'At NAIRU, trend spreads and trend prices the systematic factor is the intercept.');
        $expected = CreditRisk::calculateVasicekExpectedLoss(CreditFiscalSubsystem::RETAIL_CREDIT_INTERCEPT + log(0.8) * CreditFiscalSubsystem::RETAIL_HOUSE_PRICE_SENSITIVITY, CreditFiscalSubsystem::RETAIL_DEFAULT_BASELINE, CreditRisk::RETAIL_ASRF_RHO, 1.0);
        $this->assertEqualsWithDelta($expected, $underwater->retailDefaultRate, 1e-9, 'Homes 20% below trend default more households at the same unemployment.');
        $this->assertGreaterThan($neutral->retailDefaultRate, $underwater->retailDefaultRate);
    }

    /**
     * Households read the borrowing and interbank spreads against their measured trends, both ways: spreads that sit
     * high for a decade default no more households once they are the usual level, a freeze over the trend defaults
     * more, and funding cheaper than usual fewer.
     */
    public function testRetailDefaultsReadTheSpreadsAgainstTheirTrends(): void
    {
        $subsystem = $this->quietSubsystem();
        $at = function (float $igSpread, float $interbankSpread) use ($subsystem): float {
            $state = new MacroState();
            $state->unemploymentRateEma = $state->nairu;
            $state->residentialPropertyIndexEma = 100.0;
            $state->residentialWealthTrend = 100.0;
            $state->macroCreditSpreadTrend = 0.016;
            $state->interbankLiquiditySpreadTrend = 0.0025;
            $state->macroCreditSpreadEma = $igSpread;
            $state->interbankLiquiditySpreadEma = $interbankSpread;
            $subsystem->calculateRetailDefaultRate($state, 0.25);

            return $state->retailDefaultRate;
        };
        $pd = static fn(float $z): float => CreditRisk::calculateVasicekExpectedLoss($z, CreditFiscalSubsystem::RETAIL_DEFAULT_BASELINE, CreditRisk::RETAIL_ASRF_RHO, 1.0);
        $sensitivity = CreditFiscalSubsystem::RETAIL_CREDIT_SPREAD_SENSITIVITY;

        $this->assertEqualsWithDelta($pd(CreditFiscalSubsystem::RETAIL_CREDIT_INTERCEPT), $at(0.016, 0.0025), 1e-12, 'Spreads at their trends, above their bases, are neutral.');
        $this->assertEqualsWithDelta($pd(CreditFiscalSubsystem::RETAIL_CREDIT_INTERCEPT - (0.0075 * $sensitivity)), $at(0.016, 0.010), 1e-12, 'A wholesale freeze defaults more households.');
        $this->assertEqualsWithDelta($pd(CreditFiscalSubsystem::RETAIL_CREDIT_INTERCEPT + ((0.003 + 0.001) * $sensitivity)), $at(0.013, MacroConstants::INTERBANK_BASELINE_SPREAD), 1e-12, 'Spreads under their trends default fewer.');
        $this->assertLessThan($at(0.016, 0.0025), $at(0.013, MacroConstants::INTERBANK_BASELINE_SPREAD));
    }

    /**
     * The debt-service gap took the wrong sign against US delinquency, so it reaches households through the crisis
     * hazard and deleveraging, not the default rate.
     */
    public function testTheDebtServiceGapDoesNotMoveTheRetailDefaultRate(): void
    {
        $subsystem = $this->quietSubsystem();

        $neutral = new MacroState();
        $neutral->unemploymentRateEma = $neutral->nairu;
        $subsystem->calculateRetailDefaultRate($neutral, 0.25);

        $burdened = new MacroState();
        $burdened->unemploymentRateEma = $burdened->nairu;
        $burdened->householdDebtServiceGap = 0.02;
        $subsystem->calculateRetailDefaultRate($burdened, 0.25);

        $this->assertSame($neutral->retailDefaultRate, $burdened->retailDefaultRate);
    }

    /** The unexplained household credit factor is state: a shock today is still in the default rate next quarter. */
    public function testTheHouseholdCreditFactorPersistsAcrossTicks(): void
    {
        $math = new class extends MathUtility {
            public float $draw = -1.0;
            public function generateStandardNormal(): float
            {
                return $this->draw;
            }
        };
        $subsystem = new CreditFiscalSubsystem($math);

        $state = new MacroState();
        $state->unemploymentRateEma = $state->nairu;
        $subsystem->calculateRetailDefaultRate($state, 0.25);
        $shocked = $state->retailCreditFactor;
        $math->draw = 0.0;
        $subsystem->calculateRetailDefaultRate($state, 0.25);

        $this->assertLessThan(0.0, $shocked);
        $this->assertEqualsWithDelta($shocked * exp(-CreditFiscalSubsystem::RETAIL_CREDIT_FACTOR_KAPPA * 0.25), $state->retailCreditFactor, 1e-12, 'With no new shock the factor decays at its own rate.');
        $atRest = CreditRisk::calculateVasicekExpectedLoss(CreditFiscalSubsystem::RETAIL_CREDIT_INTERCEPT, CreditFiscalSubsystem::RETAIL_DEFAULT_BASELINE, CreditRisk::RETAIL_ASRF_RHO, 1.0);
        $this->assertGreaterThan($atRest, $state->retailDefaultRate, 'A bad factor still defaults more households a quarter later.');
    }

    /**
     * A buffer the banks have yet to build rations credit through their standards as a raised requirement does; once
     * the capital is held it rations nothing.
     */
    public function testABufferTightensLendingStandardsOnlyUntilItIsBuilt(): void
    {
        $subsystem = $this->quietSubsystem();

        $unbuffered = new MacroState();
        $subsystem->calculateSloosCreditStandards($unbuffered, 0.25);

        $building = new MacroState();
        $building->countercyclicalBufferRate = CreditFiscalSubsystem::MAX_CCYB;
        $subsystem->calculateSloosCreditStandards($building, 0.25);
        $this->assertGreaterThan($unbuffered->sloosTighteningIndex, $building->sloosTighteningIndex, 'A buffer still to be built rations new loans.');

        $held = new MacroState();
        $held->countercyclicalBufferRate = CreditFiscalSubsystem::MAX_CCYB;
        $held->bankCapitalBuilt = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT + CreditFiscalSubsystem::MAX_CCYB;
        $subsystem->calculateSloosCreditStandards($held, 0.25);
        $this->assertEqualsWithDelta($unbuffered->sloosTighteningIndex, $held->sloosTighteningIndex, 1e-12, 'Capital already held rations nothing.');
    }

    /**
     * Banks build to a raised requirement and buffer over the years Bridges et al. measured, and while they fall short
     * their lending standards tighten, a point of requirement as a point of buffer; a cut leaves standards as they
     * were.
     */
    public function testARaisedRequirementSqueezesLendingWhileBanksBuildToIt(): void
    {
        $subsystem = $this->quietSubsystem();
        $state = $this->householdStateAtNeutralRates();
        $state->bankCapitalRequirement = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT + 0.01;
        $subsystem->calculateHouseholdCredit($state, 1.0);
        $this->assertEqualsWithDelta(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT + (0.01 * (1.0 - exp(-1.0 / CreditFiscalSubsystem::REQUIREMENT_BUILD_YEARS))), $state->bankCapitalBuilt, 1e-12);
        for ($year = 1; $year < 3; ++$year) {
            $subsystem->calculateHouseholdCredit($state, 1.0);
        }
        $this->assertEqualsWithDelta(0.0086, $state->bankCapitalBuilt - FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, 0.0005, 'Three years on, 0.86 of the point is built (Bridges et al.: 0.95).');

        $buffered = $this->householdStateAtNeutralRates();
        $buffered->countercyclicalBufferRate = 0.01;
        $buffered->creditToGdpGapEma = (CreditFiscalSubsystem::CCYB_GAP_FLOOR + (0.4 * (CreditFiscalSubsystem::CCYB_GAP_CEILING - CreditFiscalSubsystem::CCYB_GAP_FLOOR)));
        $subsystem->calculateHouseholdCredit($buffered, 1.0);
        $this->assertEqualsWithDelta(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT + (0.01 * (1.0 - exp(-1.0 / CreditFiscalSubsystem::REQUIREMENT_BUILD_YEARS))), $buffered->bankCapitalBuilt, 1e-9, 'The buffer is built toward as the requirement is.');

        $short = new MacroState();
        $short->bankCapitalRequirement = 0.104;
        $short->bankCapitalBuilt = 0.094;
        $subsystem->calculateSloosCreditStandards($short, 0.25);
        $unbuilt = new MacroState();
        $unbuilt->countercyclicalBufferRate = 0.010;
        $subsystem->calculateSloosCreditStandards($unbuilt, 0.25);
        $this->assertEqualsWithDelta($unbuilt->sloosTighteningIndex, $short->sloosTighteningIndex, 1e-12, 'A point of requirement not yet built reads as a point of buffer not yet built.');

        $calm = new MacroState();
        $subsystem->calculateSloosCreditStandards($calm, 0.25);
        $premium = new MacroState();
        $premium->excessBondPremium = DomesticDemand::CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT * 0.01;
        $subsystem->calculateSloosCreditStandards($premium, 0.25);
        $this->assertEqualsWithDelta($premium->sloosTighteningIndex, $short->sloosTighteningIndex, 1e-12, 'A point short tightens standards as the premium the demand drag reads it as does.');
        $target = CreditFiscalSubsystem::SLOOS_PREMIUM_SENSITIVITY * DomesticDemand::CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT * 0.01;
        $this->assertEqualsWithDelta(0.076, $target, 0.001, 'A point short pulls standards toward ~8 points of net tightening.');
        $this->assertEqualsWithDelta($target * CreditFiscalSubsystem::SLOOS_KAPPA * 0.25, $short->sloosTighteningIndex - $calm->sloosTighteningIndex, 1e-9);

        $cut = new MacroState();
        $cut->bankCapitalRequirement = 0.085;
        $cut->bankCapitalBuilt = 0.094;
        $subsystem->calculateSloosCreditStandards($cut, 0.25);
        $this->assertEqualsWithDelta($calm->sloosTighteningIndex, $cut->sloosTighteningIndex, 1e-12, 'A cut releases capital but does not loosen standards.');
    }

    /**
     * As the capital banks must hold rises they cut household lending at once, by Bridges et al.'s year-one amounts for
     * secured and unsecured lending in the stock's proportions: a rise phased in over a year cuts as much as one made
     * at once, a buffer rise cuts as a requirement rise does, and a cut lends nothing back.
     */
    public function testARiseInRequiredCapitalCutsHouseholdLending(): void
    {
        $subsystem = $this->quietSubsystem();
        $cut = (CreditFiscalSubsystem::HOUSEHOLD_MORTGAGE_DEBT_SHARE * CreditFiscalSubsystem::SECURED_LENDING_CUT_PER_CAPITAL_RISE)
            + ((1.0 - CreditFiscalSubsystem::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * CreditFiscalSubsystem::UNSECURED_LENDING_CUT_PER_CAPITAL_RISE);
        $this->assertEqualsWithDelta(0.862, $cut, 1e-12, 'About 0.86% of household debt a point.');
        $day = 1.0 / 252.0;
        $year = function (callable $requirementOn) use ($subsystem, $day): MacroState {
            $state = $this->householdStateAtNeutralRates();
            for ($i = 1; $i <= 252; ++$i) {
                $state->bankCapitalRequirement = $requirementOn($i);
                $subsystem->calculateHouseholdCredit($state, $day);
            }

            return $state;
        };
        $opening = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT;

        $calm = $year(static fn(int $i): float => $opening);
        $atOnce = $year(static fn(int $i): float => $opening + 0.01);
        $phased = $year(static fn(int $i): float => $opening + (0.01 * $i / 252.0));
        $lowered = $year(static fn(int $i): float => $opening - 0.01);

        $this->assertEqualsWithDelta(-$cut * 0.01 * exp(-CreditFiscalSubsystem::CREDIT_MEAN_REVERSION), log($atOnce->householdDebtToIncome) - log($calm->householdDebtToIncome), 2e-5, 'A point cuts ~0.86% of the stock, less what a year of reversion makes up.');
        $this->assertLessThan($calm->householdDebtToIncome, $atOnce->householdDebtToIncome);
        $this->assertEqualsWithDelta(log($atOnce->householdDebtToIncome), log($phased->householdDebtToIncome), 5e-4, 'Phased in or at once, the year ends at the same cut.');
        $this->assertEqualsWithDelta($calm->householdDebtToIncome, $lowered->householdDebtToIncome, 1e-12, 'A cut lends nothing back.');

        $raised = $this->householdStateAtNeutralRates();
        $raised->bankCapitalRequirement = $opening + 0.01;
        $subsystem->calculateHouseholdCredit($raised, $day);
        $buffered = $this->householdStateAtNeutralRates();
        $buffered->countercyclicalBufferRate = 0.01;
        $subsystem->calculateHouseholdCredit($buffered, $day);
        $this->assertEqualsWithDelta($raised->householdDebtToIncome, $buffered->householdDebtToIncome, 1e-12, 'A point of buffer cuts as a point of requirement does.');
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
        $this->assertEqualsWithDelta(
            1.0 / (1.0 + exp(-(CreditFiscalSubsystem::CREDIT_CRISIS_LOGIT_INTERCEPT + CreditFiscalSubsystem::DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT))),
            $calm->creditCrisisHazard,
            1e-12,
            'With no boom the hazard is the District\'s base rate: the panel\'s, shifted for its wholesale funding.',
        );

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
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return true;
            }
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
            CreditFiscalSubsystem::thinCapitalDragScale(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT)
                * (CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_BASE + (CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_PER_GAP * 0.10)),
            $state->creditCrisisDrag,
            1e-9,
            'Credit bites back: the drag is the base loss plus a share of the boom behind it, deepened by thin capital.',
        );
        $this->assertEqualsWithDelta(
            -CreditFiscalSubsystem::CRISIS_POTENTIAL_SCAR * CreditFiscalSubsystem::thinCapitalDragScale(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT),
            $state->crisisScarPending,
            1e-12,
            'The crisis books a scar for potential to take, deeper on thin capital.',
        );

        $state->excessBondPremium = 0.0;
        $subsystem->calculateInterbankLiquiditySpread($state);
        $this->assertGreaterThan(2.5 * MacroConstants::INTERBANK_BASELINE_SPREAD, $state->interbankLiquiditySpread, 'The day of the crisis is a run on wholesale funding.');
    }

    /**
     * The households' share of a crisis's deleveraging is debt repaid: the stock falls by that share of the drag a
     * year, and the two parts of the drag add back to the whole.
     */
    public function testACrisisRepaysHouseholdDebt(): void
    {
        $dt = 1.0 / 3600.0;
        $calm = new MacroState();
        $crisis = new MacroState();
        $crisis->creditCrisisDrag = 0.06;
        $this->quietSubsystem()->calculateHouseholdCredit($calm, $dt);
        $this->quietSubsystem()->calculateHouseholdCredit($crisis, $dt);

        $this->assertEqualsWithDelta(
            -CreditFiscalSubsystem::crisisHouseholdRepayment(0.06) * $dt,
            $crisis->householdDebtToIncome - $calm->householdDebtToIncome,
            1e-9,
        );
        $this->assertEqualsWithDelta(0.25 * 0.06, CreditFiscalSubsystem::crisisHouseholdRepayment(0.06), 1e-15);
        $this->assertEqualsWithDelta(0.06, CreditFiscalSubsystem::crisisDirectDrag(0.06) + (CreditFiscalSubsystem::crisisHouseholdRepayment(0.06) * DomesticDemand::KALDOR_HOUSEHOLD_NEW_BORROWING), 1e-15, 'Nothing lost, nothing counted twice.');
    }

    public function testTheDistrictsWholesaleFundingLiftsTheCrisisHazardAboveThePanel(): void
    {
        $calm = new MacroState();
        $calm->totalTime = 20.0;
        $this->quietSubsystem()->calculateCreditCrisisHazard($calm, 0.25);

        $panel = 1.0 / (1.0 + exp(-CreditFiscalSubsystem::CREDIT_CRISIS_LOGIT_INTERCEPT));
        $this->assertEqualsWithDelta(0.022, $panel, 0.001, 'The JST post-war panel runs a 2.2% hazard at trend.');
        $this->assertEqualsWithDelta(0.036, $calm->creditCrisisHazard, 0.001, 'Loans at 108% of deposits against the US 87% lift it to 3.6% a year (JRST 2021).');
    }

    public function testThinCapitalDeepensEveryCrisisDrag(): void
    {
        $math = new class extends MathUtility {
            public function checkProbability(float $probability): bool
            {
                return true;
            }
        };
        $state = new MacroState();
        $state->totalTime = 12.5;
        (new CreditFiscalSubsystem($math))->calculateCreditCrisisHazard($state, 1.0 / 3600.0);

        $this->assertEqualsWithDelta(
            1.0946 * CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_BASE,
            $state->creditCrisisDrag,
            1e-4 * CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_BASE,
            'With equity 4.9% of assets against the US 7.6%, a crisis with no boom behind it still lands 9.5% harder (JRST 2021, Table 8).',
        );
    }

    /**
     * The capital the banks hold sets how thin the District's is: each point of CET1 over the opening requirement is
     * 0.639 points of equity over total assets, and each point of those spares 0.86 / 24.55 of a financial recession's
     * loss. A requirement spares nothing until it is built, and a countercyclical buffer built in the boom softens the
     * bust it is released into.
     */
    public function testAStricterRequirementSoftensTheCrisisDrag(): void
    {
        $opening = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT;
        $this->assertEqualsWithDelta(1.0 + ((0.86 / 24.55) * 2.7), CreditFiscalSubsystem::thinCapitalDragScale($opening), 1e-12);
        $this->assertEqualsWithDelta(
            CreditFiscalSubsystem::thinCapitalDragScale($opening) - ((0.86 / 24.55) * 100.0 * 0.01 * 0.639),
            CreditFiscalSubsystem::thinCapitalDragScale($opening + 0.01),
            1e-12,
        );
        $this->assertLessThan(CreditFiscalSubsystem::thinCapitalDragScale($opening), CreditFiscalSubsystem::thinCapitalDragScale(\App\Service\Politics\Department\FinancialRegulator::STRICTEST_REQUIREMENT), 'The strictest head softens the drag the most.');
        $this->assertGreaterThan(CreditFiscalSubsystem::thinCapitalDragScale($opening), CreditFiscalSubsystem::thinCapitalDragScale(0.0843));

        $math = new class extends MathUtility {
            public function checkProbability(float $probability): bool
            {
                return true;
            }
        };
        $crisis = static function (float $requirement, float $built) use ($math): float {
            $state = new MacroState();
            $state->totalTime = 12.5;
            $state->bankCapitalRequirement = $requirement;
            $state->bankCapitalBuilt = $built;
            (new CreditFiscalSubsystem($math))->calculateCreditCrisisHazard($state, 1.0 / 3600.0);

            return $state->creditCrisisDrag;
        };
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::thinCapitalDragScale(0.1315) * CreditFiscalSubsystem::CREDIT_CRISIS_DRAG_BASE, $crisis(0.1315, 0.1315), 1e-12);
        $this->assertSame($crisis($opening, $opening), $crisis(0.1315, $opening), 'A requirement not yet built spares nothing.');
        $this->assertLessThan($crisis($opening, $opening), $crisis($opening, $opening + CreditFiscalSubsystem::MAX_CCYB), 'A built buffer softens the bust.');
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
            'Five years on, a credit-boom recession is still depressing output in the JST panel.',
        );
        $this->assertLessThan(
            0.60,
            $surviving,
            'It is a transitory deleveraging drag, not permanent scarring; the economy does recover.',
        );
    }

    /**
     * A crisis reaches lending standards through the premium it books, so standards tighten on the day and ease
     * as the premium passes, while the crisis drag on demand still has most of its eight-year life to run.
     * 2008: net tightening 84% in October, 32% by July 2009, easing by January 2010 with the gap at -4%.
     */
    public function testACrisisTightensLendingStandardsThroughThePremiumItBooks(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return true;
            }
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
            'Two years on, standards have unwound further than the deleveraging drag has: they follow the premium, not the drag.',
        );
    }

    // --- Excess Bond Premium (Gilchrist & Zakrajsek 2012) ---

    /**
     * The log premium decays on its own clock, whatever the level of the gap: a deep but stable slump holds no premium
     * up.
     */
    public function testThePremiumDecaysAtItsOwnRateWhateverTheGapLevel(): void
    {
        $subsystem = $this->quietSubsystem();
        $logGap = static fn(float $premium): float => log($premium + CreditFiscalSubsystem::EBP_DISPLACEMENT) - CreditFiscalSubsystem::EBP_LOG_MEAN;
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

    /**
     * Lenders price the world's credit: a mainland slump lifts the premium, at the mainland's share of what the same
     * fall at home does.
     */
    public function testAMainlandSlumpRaisesThePremium(): void
    {
        $subsystem = $this->quietSubsystem();
        $step = static function (float $gapChange, float $mainlandGapChange) use ($subsystem): float {
            $state = new MacroState();
            $state->excessBondPremium = 0.005;
            $subsystem->updateExcessBondPremium($state, $gapChange, 1.0 / 360.0, $mainlandGapChange);

            return log($state->excessBondPremium + CreditFiscalSubsystem::EBP_DISPLACEMENT);
        };

        $calm = $step(0.0, 0.0);
        $this->assertGreaterThan($calm, $step(0.0, -0.01), 'A mainland slump raises the premium.');
        $this->assertEqualsWithDelta(
            CreditFiscalSubsystem::EBP_LOG_GAP_SPEED_SENSITIVITY * CreditFiscalSubsystem::EBP_MAINLAND_GAP_SHARE * 0.01,
            $step(0.0, -0.01) - $calm,
            1e-12,
        );
        $this->assertEqualsWithDelta($step(-0.01, 0.0) - $calm, ($step(-0.01, -0.01) - $calm) * (1.0 - CreditFiscalSubsystem::EBP_MAINLAND_GAP_SHARE), 1e-12, 'Both falling at once is the whole sensitivity.');
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
        $subsystem->updateExcessBondPremium($falling, -0.02, 1e-9, -0.02);
        $this->assertEqualsWithDelta($shift * ($lift - 1.0), $falling->excessBondPremium, 1e-9);

        $recovering = new MacroState();
        $subsystem->updateExcessBondPremium($recovering, 0.02, 1e-9, 0.02);
        $this->assertEqualsWithDelta($shift * ((1.0 / $lift) - 1.0), $recovering->excessBondPremium, 1e-9);

        $split = new MacroState();
        for ($i = 0; $i < 4; $i++) {
            $subsystem->updateExcessBondPremium($split, -0.005, 1e-9, -0.005);
        }
        $this->assertEqualsWithDelta($falling->excessBondPremium, $split->excessBondPremium, 1e-10, 'Four small falls price as one large one.');

        $alreadyHigh = new MacroState();
        $alreadyHigh->excessBondPremium = 0.03;
        $subsystem->updateExcessBondPremium($alreadyHigh, -0.02, 1e-9, -0.02);
        $this->assertEqualsWithDelta(
            (0.03 + $shift) / $shift,
            ($alreadyHigh->excessBondPremium - 0.03) / $falling->excessBondPremium,
            1e-6,
            'The same fall adds more to a premium that is already high.',
        );
    }

    /**
     * In levels the record's shocks grow with the premium (0.15pp a quarter low, 0.42pp high); in logs they are one
     * size.
     */
    public function testAShockMovesAHighPremiumFurtherThanALowOne(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 1.0;
            }
            public function checkProbability(float $probability): bool
            {
                return false;
            }
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

    /**
     * However hard it is pushed down, the premium stays above minus its displacement: the record's low is -0.78pp a
     * quarter.
     */
    public function testThePremiumCannotFallThroughItsDisplacement(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return -8.0;
            }
            public function checkProbability(float $probability): bool
            {
                return true;
            }
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
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return true;
            }
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

    /**
     * None of the premium's readers feeds back into the spread that reads them: volatility and TED ignore the spread
     * itself.
     */
    public function testTheCreditBlockHasNoLoop(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };
        $credit = new CreditFiscalSubsystem($math);
        $assets = new AssetMarketSubsystem($math);

        $calm = new MacroState();
        $capped = new MacroState();
        $capped->macroCreditSpread = CreditRisk::MAX_CREDIT_SPREAD;
        $capped->macroCreditSpreadEma = CreditRisk::MAX_CREDIT_SPREAD;
        $capped->highYieldCreditSpread = CreditRisk::MAX_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER;

        $this->assertSame($assets->calculateMarketVolatility($calm, 0.25), $assets->calculateMarketVolatility($capped, 0.25), 'Volatility does not read the spread.');

        $credit->calculateInterbankLiquiditySpread($calm);
        $credit->calculateInterbankLiquiditySpread($capped);
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
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
            public function checkProbability(float $probability): bool
            {
                return false;
            }
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
        $this->assertGreaterThan(0.055, $state->macroCreditSpread, 'October 2008 conditions start near the IG record.');
        $defaultRisk = MacroConstants::BASE_CREDIT_SPREAD * exp(-CreditRisk::MERTON_LEVERAGE_SENSITIVITY * -0.07);

        $dt = 1.0 / 52.0;
        $emaWeight = 1.0 - exp(-$dt / 0.25);
        for ($week = 1; $week <= 104; $week++) {
            $credit->updateExcessBondPremium($state, 0.0, $dt);
            $state->marketVolatility = $assets->calculateMarketVolatility($state, $dt);
            $state->marketVolatilityEma += $emaWeight * ($state->marketVolatility - $state->marketVolatilityEma);
            $credit->calculateInterbankLiquiditySpread($state);
            $state->interbankLiquiditySpreadEma += $emaWeight * ($state->interbankLiquiditySpread - $state->interbankLiquiditySpreadEma);
            $credit->calculateMacroCreditSpread($state);
            $credit->calculateSloosCreditStandards($state, $dt);

            if ($week === 13) {
                $this->assertLessThan(0.055, $state->macroCreditSpread, 'A quarter on the spread is already off its peak.');
            }
            if ($week === 52) {
                $this->assertLessThan(0.5 * (CreditRisk::MAX_CREDIT_SPREAD - $defaultRisk), $state->macroCreditSpread - $defaultRisk, 'A year on the spread has given back over half its excess over default risk, the gap unchanged.');
                $this->assertLessThan(0.75, $state->sloosTighteningIndex, 'A year on standards are off their cap.');
                $this->assertLessThan(0.33, $state->marketVolatility, 'A year on volatility is down to about two thirds of 45%.');
            }
        }

        $this->assertLessThan($defaultRisk + 0.005, $state->macroCreditSpread, 'Two years on, what is left is the default risk of a -7% slump.');
        $this->assertLessThan(0.40, $state->sloosTighteningIndex, 'Two years on standards have given back most of their tightening.');
    }

    /** A carbon price is levied on the power sector's emissions: today's tonnes per dollar of GDP, times the price. */
    public function testACarbonPriceIsRevenueOnThePowerSectorsEmissions(): void
    {
        $free = new MacroState();
        $free->sovereignDebtToGdp = self::SOUND_DEBT_TO_GDP;
        $free->nominalGdpIndex = 1.0;
        $free->outputGap = 0.0;
        $free->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $free->governmentSpendingIndex = 100.0;
        $priced = clone $free;
        $priced->carbonPrice = 70.37;

        $this->subsystem->calculateSovereignDebt($free, 0.25);
        $this->subsystem->calculateSovereignDebt($priced, 0.25);

        // 1,425 Mt over $27.36T: about 0.37% of GDP at the EU ETS price.
        $this->assertEqualsWithDelta(70.37 * 1425.0e6 / 27.36e12, $free->primaryDeficitToGdp - $priced->primaryDeficitToGdp, 1e-12);
    }

    /**
     * The banks' levy bill is budget revenue, read into GDP units through the reserve fund's dollars per unit of GDP.
     */
    public function testTheBankLevyIsRevenueOnceTheFundSetsTheScale(): void
    {
        $free = new MacroState();
        $free->sovereignDebtToGdp = self::SOUND_DEBT_TO_GDP;
        $free->nominalGdpIndex = 1.0;
        $free->outputGap = 0.0;
        $free->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE;
        $free->governmentSpendingIndex = 100.0;
        $free->sovereignFundDollarsPerGdp = 12.0e12;
        $levied = clone $free;
        $levied->boardBankLevy = 12.0e9;

        $this->subsystem->calculateSovereignDebt($free, 0.25);
        $this->subsystem->calculateSovereignDebt($levied, 0.25);

        $this->assertEqualsWithDelta(0.001, $free->primaryDeficitToGdp - $levied->primaryDeficitToGdp, 1e-12, '$12B on a $12T economy is a tenth of a point.');

        $unfunded = new MacroState();
        $unfunded->boardBankLevy = 12.0e9;
        $this->subsystem->calculateSovereignDebt($unfunded, 0.25);
        $this->assertTrue(is_finite($unfunded->primaryDeficitToGdp), 'With no fund there is no scale, and nothing is booked.');
    }

    /**
     * The tax rate takes in a legislated shift at its own speed, and the trailing year's earnings carry it half a year
     * later on average; a levy, the rules on extraction, the stamp duty and the carbon price reach them the same way.
     */
    public function testTheEarningsCarryTheLawsAfterTheRateDoes(): void
    {
        $state = new MacroState();
        $state->corporateTaxPolicyShift = 0.04;
        $state->sovereignDebtToGdp = 0.90;
        $state->bankLevyRate = 0.002;
        $state->extractionStringency = 1.0;
        $state->stampDutyRate = 0.002;
        $state->carbonPrice = 40.0;
        $fiscal = new CreditFiscalSubsystem(new MathUtility());
        $rate = $state->corporateTaxRate;
        $dt = 1.0 / 252.0;
        for ($tick = 0; $tick < 252; ++$tick) {
            $fiscal->calculateDynamicFiscalPolicy($state, $dt);
        }

        $this->assertEqualsWithDelta($state->corporateTaxRate - $rate, $state->corporateTaxShiftRealized, 1e-9, 'With the cycle at rest, the rate has moved by the shift as far as it has taken it in.');
        $this->assertEqualsWithDelta(0.04 * (1.0 - exp(-MacroConstants::FISCAL_ADJUSTMENT_SPEED)), $state->corporateTaxShiftRealized, 2e-4);
        $this->assertLessThan($state->corporateTaxShiftRealized, $state->corporateTaxShiftEmbodied);
        $this->assertGreaterThan(0.0, $state->corporateTaxShiftEmbodied);
        $carried = 1.0 - exp(-1.0 / CreditFiscalSubsystem::TRAILING_EARNINGS_MEAN_LAG_YEARS);
        $this->assertEqualsWithDelta(0.002 * $carried, $state->bankLevyEmbodied, 2e-5);
        $this->assertEqualsWithDelta(1.0 + ((FirmEconomics::calculateExtractionCostFactor(1.0) - 1.0) * $carried), $state->extractionCostFactorEmbodied, 1e-2 * (FirmEconomics::calculateExtractionCostFactor(1.0) - 1.0));
        $this->assertEqualsWithDelta(1.0 + ((MacroTransmission::calculateStampDutyVolumeFactor(0.002) - 1.0) * $carried), $state->stampDutyVolumeFactorEmbodied, 1e-2 * (1.0 - MacroTransmission::calculateStampDutyVolumeFactor(0.002)));
        $this->assertEqualsWithDelta(CommodityLogisticsSubsystem::carbonPowerPriceUplift(40.0) * $carried, $state->carbonPowerUpliftEmbodied, 1e-2 * CommodityLogisticsSubsystem::carbonPowerPriceUplift(40.0));
    }

    /**
     * The tax on dividends adds revenue only as far as it departs from the founding rate, levied on taxable holders'
     * dividends, which shrink as it rises; the budget books it.
     */
    public function testTheDividendTaxRaisesOnTaxableHoldersDividends(): void
    {
        $this->assertSame(0.0, CreditFiscalSubsystem::dividendTaxRevenueShift(Valuation::FOUNDING_DIVIDEND_TAX_RATE));
        $base = Valuation::TAXABLE_EQUITY_SHARE * CreditFiscalSubsystem::NET_DIVIDENDS_TO_GDP;
        $high = CreditFiscalSubsystem::dividendTaxRevenueShift(0.396);
        $this->assertGreaterThan(0.0, $high);
        $this->assertLessThan((0.396 - 0.15) * $base, $high, 'Dividends fall as the rate rises, so it raises less than the static sum.');
        $this->assertEqualsWithDelta(-0.15 * $base, CreditFiscalSubsystem::dividendTaxRevenueShift(0.0), 1e-15, 'Exempting dividends gives up the founding take.');

        $founding = $this->neutralBudget(1.20);
        $taxed = $this->neutralBudget(1.20);
        $taxed->dividendTaxRate = 0.396;
        $this->subsystem->calculateSovereignDebt($founding, 0.25);
        $this->subsystem->calculateSovereignDebt($taxed, 0.25);
        $this->assertEqualsWithDelta(-$high, $taxed->primaryDeficitToGdp - $founding->primaryDeficitToGdp, 1e-9, 'The budget books it.');
    }

    /**
     * The tax on foreign buyers raises what British Columbia's 20% raised on its GDP, about C$37.5 million on C$295
     * billion; its take peaks where the rate is the inverse of the demand's semi-elasticity and all but vanishes at the
     * strictest rate; the budget books it.
     */
    public function testTheForeignBuyerTaxRaisesWhatBritishColumbiasRaised(): void
    {
        $this->assertSame(0.0, AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp(0.0));
        $this->assertEqualsWithDelta(37.5e6 / 295.0e9, AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp(0.20), 2e-6);
        $peak = 1.0 / AssetMarketSubsystem::FOREIGN_BUYER_TAX_DEMAND_SEMI_ELASTICITY;
        $this->assertGreaterThan(AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp($peak * 0.8), AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp($peak));
        $this->assertGreaterThan(AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp($peak * 1.2), AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp($peak));
        $this->assertLessThan(1e-5, AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp(0.60), 'The strictest rate keeps the buyers out and raises next to nothing.');

        $none = $this->neutralBudget(1.20);
        $taxed = $this->neutralBudget(1.20);
        $taxed->foreignBuyerTaxRate = 0.20;
        $this->subsystem->calculateSovereignDebt($none, 0.25);
        $this->subsystem->calculateSovereignDebt($taxed, 0.25);
        $this->assertEqualsWithDelta(-AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp(0.20), $taxed->primaryDeficitToGdp - $none->primaryDeficitToGdp, 1e-12, 'The budget books it.');
    }
    // --- Bank rescues ---

    // --- The Exchequer's accounts ---

    /**
     * Stock-flow consistency of the government's books: over a step the debt moves by exactly the accounts' primary
     * deficit, interest less growth and the one-off adjustments, the published deficit is the accounts' own, and net
     * debt and net financial worth are struck on the same stocks.
     */
    public function testTheDebtMovesByExactlyWhatTheAccountsBook(): void
    {
        $state = $this->neutralBudget(1.10, 1.3);
        $state->outputGap = -0.03;
        $state->netTaxRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE - 0.01;
        $state->corporateTaxRate = MacroConstants::BASE_CORPORATE_TAX_RATE + 0.005;
        $state->governmentSpendingIndex = 103.0;
        $state->importTariffRate = 0.04;
        $state->carbonPrice = 20.0;
        $state->dividendTaxRate = 0.05;
        $state->foreignBuyerTaxRate = 0.15;
        $state->sovereignFundDollarsPerGdp = 2.0e13;
        $state->sovereignFundDrawToGdp = 0.012;
        $state->sovereignFundBondsToGdp = 0.15;
        $state->sovereignFundToGdp = 0.45;
        $state->boardBankLevy = 3.0e10;
        $state->nominalGdpGrowth = 0.035;
        $state->policyRate = 0.03;
        $state->yield10y = 0.04;
        // At its target and at the 10-year, the book neither re-matures nor reprices this step.
        $state->sovereignCouponRate = 0.04;
        $state->sovereignDebtAverageMaturity = $state->sovereignDebtMaturityTarget;
        $state->totalTime = 30.0;
        $state->lastCreditCrisisAt = 30.0;
        $dt = 0.25;
        $accounts = CreditFiscalSubsystem::publicAccounts($state, $dt);
        $debt = $state->sovereignDebtToGdp;

        $this->subsystem->calculateSovereignDebt($state, $dt);

        $this->assertEqualsWithDelta($debt + $accounts->debtChange($dt), $state->sovereignDebtToGdp, 1e-12, 'The debt moves by what the accounts book, nothing beside them.');
        $this->assertEqualsWithDelta($accounts->primaryDeficit(), $state->primaryDeficitToGdp, 1e-15);
        $this->assertEqualsWithDelta($state->sovereignDebtToGdp - 0.15, $state->sovereignNetDebtToGdp, 1e-12);
        $this->assertEqualsWithDelta($accounts->fundContribution, $accounts->fundFinancedSpending, 1e-15, 'What the fund pays in, the budget spends.');
        $this->assertEqualsWithDelta($state->sovereignDebtToGdp - 0.45, $state->sovereignNetFinancialLiabilitiesToGdp, 1e-12, 'A crisis on the day books no rescue: debt moves by the accounts alone.');
        $this->assertEqualsWithDelta(1.10 - 0.15, $accounts->netDebt(), 1e-12);
    }

    /** At the founding the budget's primary balance is the structural position alone: taxes pay for purchases. */
    public function testTheFoundingAccountsBalance(): void
    {
        $accounts = CreditFiscalSubsystem::publicAccounts($this->neutralBudget(0.60), 0.25);

        $this->assertEqualsWithDelta(MacroConstants::PUBLIC_PURCHASES_TO_GDP, $accounts->purchases, 1e-12);
        $this->assertEqualsWithDelta(0.0, $accounts->primaryDeficit(), 1e-12);
    }

    /** The published state reads the same accounts as the live one. */
    public function testThePublishedStateReadsTheSameAccounts(): void
    {
        $dto = new MacroStateDTO(sovereignDebtToGdp: 0.8, netTaxRate: 0.17, governmentSpendingIndex: 101.0, outputGap: 0.01);
        $accounts = CreditFiscalSubsystem::publicAccounts($dto, 0.25);

        $this->assertEqualsWithDelta(0.17 + (MacroConstants::BASE_CORPORATE_TAX_RATE * CreditFiscalSubsystem::CORPORATE_PROFITS_TO_GDP), $accounts->netTaxes + $accounts->corporateTax, 1e-12);
        $this->assertEqualsWithDelta(1.01 * MacroConstants::PUBLIC_PURCHASES_TO_GDP / 1.01, $accounts->purchases, 1e-12);
    }

    // --- The Authority's Remittance ---

    /**
     * The Authority earns the debt's rate on the paper backing the currency, and on a bought book the coupon over the
     * rate it pays on the reserves that bought it: a carry while the coupon is above the policy rate, a loss once it
     * is below.
     */
    public function testTheAuthoritysIncomeIsTheCurrencysBackingPlusTheBooksCarry(): void
    {
        $state = new MacroState();
        $state->sovereignDebtAverageMaturity = CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY;
        $state->policyRate = 0.047;
        $state->sovereignCouponRate = 0.047;
        $state->balanceSheetIntensity = 0.0;
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::CURRENCY_TO_GDP * 0.047, MonetaryPolicySubsystem::authorityNetIncomeToGdp($state), 1e-12, 'With no purchases it earns the debt\'s rate on the currency.');

        $state->policyRate = 0.0025;
        $state->sovereignCouponRate = 0.023;
        $state->authorityBookCouponRate = 0.023;
        $state->sovereignDebtAverageMaturity = 1.0e9; // the whole debt at its coupon
        $state->balanceSheetIntensity = 0.0;
        $currencyOnly = MonetaryPolicySubsystem::authorityNetIncomeToGdp($state);
        $state->balanceSheetIntensity = MonetaryPolicySubsystem::QE_MAX_SUPPRESSION;
        $this->assertGreaterThan($currencyOnly, MonetaryPolicySubsystem::authorityNetIncomeToGdp($state), 'A book bought at a coupon over the policy rate earns a carry.');

        // Rates raised past the coupon on a full book: a loss, as in 2022-23.
        $state->policyRate = 0.05;
        $this->assertLessThan(0.0, MonetaryPolicySubsystem::authorityNetIncomeToGdp($state));
    }

    /**
     * A loss is carried, not charged to the budget; the profits that follow pay it down before any reach the Exchequer.
     */
    public function testALossIsCarriedAndPaidDownBeforeTheAuthorityRemits(): void
    {
        $this->assertSame(0.003, CreditFiscalSubsystem::centralBankRemittance(0.003, 0.0, 0.25));
        $this->assertSame(0.0, CreditFiscalSubsystem::centralBankRemittance(-0.004, 0.0, 0.25), 'A loss is not charged to the budget.');
        $this->assertSame(0.0, CreditFiscalSubsystem::centralBankRemittance(0.003, 0.01, 0.25), 'A quarter\'s profit short of the carried loss pays it down.');
        $this->assertEqualsWithDelta(0.003 - (0.0005 / 0.25), CreditFiscalSubsystem::centralBankRemittance(0.003, 0.0005, 0.25), 1e-15, 'The rest is paid over once the loss is made up.');
        $this->assertSame(0.0, CreditFiscalSubsystem::centralBankRemittance(0.003, 0.0005, 0.0), 'A page reads nothing paid while a loss is carried.');

        // Through the budget: a year of losses builds the deferred asset, the debt not carrying them.
        $state = $this->neutralBudget(0.60);
        // A book that neither re-matures nor reprices over the year.
        $state->sovereignDebtAverageMaturity = 1.0e9;
        $state->sovereignDebtMaturityTarget = 1.0e9;
        $state->sovereignCouponRate = 0.02;
        $state->authorityBookCouponRate = 0.02;
        $state->yield10y = 0.02;
        $state->policyRate = 0.05;
        $state->balanceSheetIntensity = MonetaryPolicySubsystem::QE_MAX_SUPPRESSION;
        $state->nominalGdpGrowth = 0.0;
        $loss = -MonetaryPolicySubsystem::authorityNetIncomeToGdp($state);
        $this->assertGreaterThan(0.0, $loss);
        for ($i = 0; $i < 4; ++$i) {
            $accounts = CreditFiscalSubsystem::publicAccounts($state, 0.25);
            $this->assertSame(0.0, $accounts->centralBankRemittance);
            $this->subsystem->calculateSovereignDebt($state, 0.25);
        }
        $this->assertEqualsWithDelta($loss, $state->centralBankDeferredAssetToGdp, 1e-12);
    }

    /** The founding take nets the Authority's founding remittance, so the founding budget still balances. */
    public function testTheFoundingRemittanceIsInTheFoundingBalance(): void
    {
        $accounts = CreditFiscalSubsystem::publicAccounts(new MacroStateDTO(), 0.25);

        $this->assertEqualsWithDelta(CreditFiscalSubsystem::FOUNDING_CENTRAL_BANK_REMITTANCE, $accounts->centralBankRemittance, 1e-12);
        $this->assertEqualsWithDelta(0.0034, CreditFiscalSubsystem::FOUNDING_CENTRAL_BANK_REMITTANCE, 0.0001);
    }

    // --- No Clamps on the Budget ---

    /** Gross debt is not capped: past any level the debt still moves by exactly what the accounts book. */
    public function testDebtHasNoCeiling(): void
    {
        $state = $this->neutralBudget(3.0);
        $state->netTaxRate -= 0.05;
        $accounts = CreditFiscalSubsystem::publicAccounts($state, 0.25);

        $this->subsystem->calculateSovereignDebt($state, 0.25);

        $this->assertEqualsWithDelta(3.0 + $accounts->debtChange(0.25), $state->sovereignDebtToGdp, 1e-12);
        $this->assertGreaterThan(3.0, $state->sovereignDebtToGdp);
    }

    /** No more can be cut than is appropriated: past that the tax rise carries the whole surplus the debt calls for. */
    public function testCutsStopAtTheAppropriationAndTaxCarriesTheRest(): void
    {
        $state = new MacroState();
        $state->sovereignFundDollarsPerGdp = 2.0e13;
        $state->consolidationTaxShare = 0.0;
        $state->publicSpendingShare = MacroConstants::PUBLIC_PURCHASES_TO_GDP;
        $state->sovereignDebtToGdp = 4.0;
        $state->debtPace = 0.40;

        $surplus = CreditFiscalSubsystem::requiredPrimaryBalance($state);
        $this->assertGreaterThan($state->publicSpendingShare, CreditFiscalSubsystem::consolidationSpendingCut($surplus, 0.0));
        $this->assertSame(0.0, CreditFiscalSubsystem::purchasesTarget($state->publicSpendingShare, $surplus, 0.0));
        $this->assertEqualsWithDelta($surplus - $state->publicSpendingShare, CreditFiscalSubsystem::fiscalStance($state), 1e-12);
    }

    /**
     * Each revenue line is struck on its stated base (publicAccounts()): the bank levy on the board's banks, read as a
     * share of GDP, and every other line on the economy, which the board's books do not reach.
     */
    public function testEachRevenueLineIsStruckOnItsStatedBase(): void
    {
        $state = $this->neutralBudget(0.60);
        $state->sovereignFundDollarsPerGdp = 2.0e13;
        $state->boardBankLevy = 3.0e10;
        $state->importTariffRate = 0.05;
        $state->carbonPrice = 40.0;
        $state->foreignBuyerTaxRate = 0.15;
        $state->dividendTaxRate = 0.20;
        $base = CreditFiscalSubsystem::publicAccounts($state, 0.25);
        $lines = static fn(\App\DTO\PublicAccountsDTO $a): array => [$a->netTaxes, $a->corporateTax, $a->tariffs, $a->carbonPrice, $a->dividendTax, $a->foreignBuyerTax, $a->centralBankRemittance];

        $biggerBoard = clone $state;
        $biggerBoard->boardBankLevy *= 2.0;
        $board = CreditFiscalSubsystem::publicAccounts($biggerBoard, 0.25);
        $this->assertEqualsWithDelta(2.0 * $base->bankLevy, $board->bankLevy, 1e-15, 'The levy is the board\'s banks\' bill.');
        $this->assertSame($lines($base), $lines($board), 'No other line reads the board.');

        $biggerEconomy = clone $biggerBoard;
        $biggerEconomy->nominalGdpIndex *= 2.0;
        $economy = CreditFiscalSubsystem::publicAccounts($biggerEconomy, 0.25);
        $this->assertEqualsWithDelta($base->bankLevy, $economy->bankLevy, 1e-15, 'Read as a share of the GDP it is set against.');
        $this->assertSame($lines($base), $lines($economy), 'The economy-wide lines are shares of GDP already.');
    }

    /**
     * The tax rates and the measures the trailing year carries are lags, so two half steps toward a fixed law leave
     * what one full step does; a step that adds speed x gap x dt does not.
     */
    public function testTheFiscalLagsAreTheSameAtAnyStep(): void
    {
        $law = new MacroState();
        $law->corporateTaxPolicyShift = 0.02;
        $law->bankLevyRate = 0.002;
        $halves = clone $law;

        $this->subsystem->calculateDynamicFiscalPolicy($law, 0.5);
        $this->subsystem->calculateDynamicFiscalPolicy($halves, 0.25);
        $this->subsystem->calculateDynamicFiscalPolicy($halves, 0.25);

        $this->assertEqualsWithDelta($law->corporateTaxRate, $halves->corporateTaxRate, 1e-15);
        $this->assertEqualsWithDelta($law->corporateTaxShiftRealized, $halves->corporateTaxShiftRealized, 1e-15);
        $this->assertEqualsWithDelta($law->bankLevyEmbodied, $halves->bankLevyEmbodied, 1e-15);
        $this->assertGreaterThan(0.0, $law->bankLevyEmbodied);
    }

}

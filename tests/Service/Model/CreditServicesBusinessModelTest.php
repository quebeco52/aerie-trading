<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CreditServicesBusinessModel;
use PHPUnit\Framework\TestCase;

class CreditServicesBusinessModelTest extends TestCase
{
    private CreditServicesBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new CreditServicesBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    /**
     * The ROE target is earned AFTER the through-the-cycle charge-offs the allowance roll-forward books
     * against EBIT every quarter, so the operating target has to fund them. It did not: revenue was
     * reverse-engineered from ROE with no provision term, so a card issuer reported its target ROE minus
     * its loss rate forever, missed consensus every quarter, and its trailing ROE then fed back into a
     * lower target. The pre-tax return target must rise by exactly the loss rate.
     */
    public function testTargetOperatingProfitFundsTheThroughTheCycleChargeOffs(): void
    {
        $stock = new Stock();
        $stock->setTicker('TALN');
        $stock->setIndustry('Credit Services');
        $stock->setTotalEquity('120000000000');
        $stock->setWholesaleDebt('48000000000');
        $stock->setCustomerDeposits('850000000000');
        $stock->setCorporateTreasury('90000000000');
        $stock->setBaselineRoe('0.25');
        $stock->setOperatingMargin('0.45');
        $stock->setCreditSpread('0.017');
        $stock->setFloatingDebtRatio('0.60');

        $macro = new MacroStateDTO(
            policyRate: 0.04, policyRateEma: 0.04, yield5yEma: 0.045, corporateTaxRate: 0.21, equityRiskPremium: 0.045
        );

        $lossFree = new class extends CreditServicesBusinessModel {
            public function getThroughTheCycleCreditLossRate(?Stock $stock = null): float
            {
                return 0.0;
            }
        };

        $withLosses = $this->model->getTargetMetrics($stock, $macro, $this->mathUtility);
        $without = $lossFree->getTargetMetrics($stock, $macro, $this->mathUtility);

        $this->assertSame($without['invested_capital'], $withLosses['invested_capital']);
        $this->assertEqualsWithDelta(
            CreditServicesBusinessModel::CARD_CHARGE_OFF_RATE * (1.0 - $macro->corporateTaxRate),
            $withLosses['baseline_roic'] - $without['baseline_roic'],
            1e-9,
            'the after-tax return target must rise by exactly the through-the-cycle loss rate the ledger charges'
        );
    }

    public function testDualStreamLendingAndSwipeInterchange(): void
    {
        $stock = new Stock();
        $stock->setTicker('COF');
        $stock->setBeta('1.1');

        $macro = new MacroStateDTO(
            inflationEma: 0.02,
            consumerSentimentIndexEma: 100.0,
            retailDefaultRateEma: 0.025,
            unemploymentRateEma: 0.045
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.55,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.05,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('lending', $result->streamRevenue);
        $this->assertArrayHasKey('swipe', $result->streamRevenue);
        $this->assertGreaterThan(0.0, $result->streamRevenue['lending']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['swipe']);
    }

    public function testUnemploymentSpikeIncreasesChargeOffProvisions(): void
    {
        $stock = new Stock();
        $stock->setTicker('COF');
        $stock->setBeta('1.0');

        $lowUnemploymentMacro = new MacroStateDTO(
            inflationEma: 0.02,
            consumerSentimentIndexEma: 100.0,
            retailDefaultRateEma: 0.025,
            unemploymentRateEma: 0.040
        );

        $highUnemploymentMacro = new MacroStateDTO(
            inflationEma: 0.02,
            consumerSentimentIndexEma: 100.0,
            retailDefaultRateEma: 0.025,
            unemploymentRateEma: 0.080
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.55,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $lowUnemploymentMacro,
            mathUtility: $mathMock
        );

        $surgeResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.55,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $highUnemploymentMacro,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan(
            $baseResult->clampedMargin,
            $surgeResult->clampedMargin,
            'Elevated unemployment must increase credit card charge-offs and expand the variable cost margin.'
        );
        $this->assertLessThan($baseResult->ebit, $surgeResult->ebit);
    }

    /**
     * A forward reserve is a balance, not a flow (ASC 326): elevated recession risk raises the lifetime
     * loss TARGET the allowance converges to and the ledger books the build once. It must not reach the
     * running margin, where it used to be charged every quarter the outlook stayed elevated, which kept a
     * card issuer loss-making for years through a mild slowdown.
     */
    public function testRecessionRiskRaisesTheReserveTargetNotTheRunningMargin(): void
    {
        $stock = new Stock();
        $stock->setTicker('DFS');
        $stock->setBeta('1.1');

        $lowRecessionMacro = new MacroStateDTO(recessionProbabilityEma: 0.05, macroCreditSpreadEma: 0.020);
        $highRecessionMacro = new MacroStateDTO(recessionProbabilityEma: 0.60, macroCreditSpreadEma: 0.020);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $lowResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $lowRecessionMacro, $mathMock);
        $highResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $highRecessionMacro, $mathMock);

        $this->assertEqualsWithDelta($lowResult->clampedMargin, $highResult->clampedMargin, 1e-12, 'the forecast reserve is not a running cost');
        $this->assertGreaterThan(
            $this->model->getForwardCreditLossMultiplier($stock, $lowRecessionMacro),
            $this->model->getForwardCreditLossMultiplier($stock, $highRecessionMacro),
            'Elevated forward recession risk must raise the lifetime loss estimate on revolving loan portfolios.'
        );
    }

    /** The per-ticker CeclSpreadSensitivity scales how far an issuer's reserve travels with credit spreads. */
    public function testSubprimeOriginatorReserveTravelsFurtherWithSpreadsThanAPrimeNetwork(): void
    {
        $subprime = new Stock();
        $subprime->setTicker('STRK'); // CeclSpreadSensitivity 2.20
        $prime = new Stock();
        $prime->setTicker('TALN'); // CeclSpreadSensitivity 1.40

        $widened = new MacroStateDTO(macroCreditSpreadEma: CreditServicesBusinessModel::CECL_BASELINE_CREDIT_SPREAD + 0.02);

        $subprimeBuild = $this->model->getForwardCreditLossMultiplier($subprime, $widened) - 1.0;
        $primeBuild = $this->model->getForwardCreditLossMultiplier($prime, $widened) - 1.0;

        $this->assertGreaterThan(0.0, $primeBuild);
        $this->assertEqualsWithDelta(2.20 / 1.40, $subprimeBuild / $primeBuild, 1e-9);
    }

    public function testSloosCreditTighteningDampsRevolvingLendingVolume(): void
    {
        $stock = new Stock();
        $stock->setTicker('COF');
        $stock->setBeta('1.0');

        $looseMacro = new MacroStateDTO(sloosTighteningIndexEma: -0.10);
        $tightMacro = new MacroStateDTO(sloosTighteningIndexEma: 0.50);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $looseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $looseMacro, $mathMock);
        $tightResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $tightMacro, $mathMock);

        $this->assertGreaterThan(
            $tightResult->streamRevenue['lending'],
            $looseResult->streamRevenue['lending'],
            'Commercial bank credit tightening gates revolving credit originations and contracts lending asset growth.'
        );
    }

    public function testInterbankLiquidityFreezeSqueezesCreditServicesNim(): void
    {
        $stock = new Stock();
        $stock->setTicker('COF');
        $stock->setBeta('1.0');

        $calmMacro = new MacroStateDTO(
            yield10yEma: 0.05,
            yield2yEma: 0.04,
            interbankLiquiditySpreadEma: 0.0010
        );

        $freezeMacro = new MacroStateDTO(
            yield10yEma: 0.05,
            yield2yEma: 0.04,
            interbankLiquiditySpreadEma: 0.0150 // 150 bps blowout
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $calmResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $calmMacro, $mathMock);
        $freezeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $freezeMacro, $mathMock);

        $this->assertGreaterThan(
            $calmResult->clampedMargin,
            $freezeResult->clampedMargin,
            'Interbank liquidity freeze must compress lending spreads and inflate variable funding costs.'
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CreditServicesBusinessModel;
use App\Service\Math\FinancialConstants;
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
     * lower target. Revenue per unit of book must rise by exactly the loss rate: the cost base is struck on the
     * book and does not grow with it.
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
        $grossYield = static fn (array $metrics): float => $metrics['baseline_roic'] / (0.45 * (1.0 - $macro->corporateTaxRate));
        $this->assertEqualsWithDelta(
            $this->model->getThroughTheCycleCreditLossRate($stock),
            $grossYield($withLosses) - $grossYield($without),
            1e-9,
            'revenue per unit of book must rise by exactly the through-the-cycle loss rate the ledger charges'
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

    /**
     * Card losses follow the household default cycle and are charged off, not overlaid on the margin: a default surge
     * raises this quarter's charge-offs (which the allowance roll-forward replaces through EBIT) and leaves the
     * operating margin alone, as the bank's book does.
     */
    public function testHouseholdDefaultSurgeIsChargedOffNotOverlaidOnTheMargin(): void
    {
        $stock = new Stock();
        $stock->setTicker('COF');
        $stock->setBeta('1.0');

        $lossRate = function (float $retailDefaultRate) use ($stock): array {
            $result = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, new MacroStateDTO(retailDefaultRateEma: $retailDefaultRate), $this->mathUtility);

            return [$result, $result->netChargeOffs * 4.0 / $this->model->resolveEarningAssets($stock)];
        };

        [$calm, $calmRate] = $lossRate(0.025);
        [$surge, $surgeRate] = $lossRate(0.060);

        $this->assertGreaterThan(1.8 * $calmRate, $surgeRate, 'card charge-offs ran 2.25x their mean at the 2010 peak (FRED CORCCACBS)');
        $this->assertEqualsWithDelta($calm->clampedMargin, $surge->clampedMargin, 1e-12, 'credit losses do not touch the operating margin');
        $this->assertSame(\App\Service\Event\ShockEvent::MASSIVE_CREDIT_PROVISION, $surge->eventType);
    }

    /**
     * A card book is unsecured consumer credit, so it loses the industry's card charge-off rate through the cycle,
     * scaled by underwriting: the prime network three quarters of it, the subprime instalment lender 1.6x, OneMain's
     * 6.02% of 2019 over the industry's rate that year. The rate is on receivables, so the earning-asset book loses it
     * on its loan share; the securities sleeve is marked, not charged off.
     */
    public function testTheCardBookLosesTheIndustryRateScaledByUnderwriting(): void
    {
        $prime = (new Stock())->setTicker('TALN');
        $subprime = (new Stock())->setTicker('STRK');
        $cardRate = CreditServicesBusinessModel::CONSUMER_CHARGE_OFF_RATE * (1.0 - FinancialConstants::SECURITIES_SHARE_OF_EARNING_ASSETS);

        $this->assertEqualsWithDelta($cardRate, $this->model->getThroughTheCycleCreditLossRate(), 1e-12);
        $this->assertEqualsWithDelta(0.75 * $cardRate, $this->model->getThroughTheCycleCreditLossRate($prime), 1e-12);
        $this->assertEqualsWithDelta(1.6 * $cardRate, $this->model->getThroughTheCycleCreditLossRate($subprime), 1e-12);
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

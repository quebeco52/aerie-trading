<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ShadowBankBusinessModel;
use PHPUnit\Framework\TestCase;

class ShadowBankBusinessModelTest extends TestCase
{
    private ShadowBankBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ShadowBankBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testIndependentOriginationAndDirectLendingStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('RITM');
        $stock->setBeta('1.3');
        $stock->setTotalEquity('1000000000');
        $stock->setWholesaleDebt('8000000000');

        $macro = new MacroStateDTO(
            outputGapEma: 0.01,
            policyRateEma: 0.05,
            yield30yEma: 0.065,
            macroCreditSpreadEma: 0.02
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 400_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 80_000_000.0,
            baselineVol: 0.12,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('origination_fees', $result->streamRevenue);
        $this->assertArrayHasKey('direct_lending', $result->streamRevenue);

        $this->assertArrayHasKey('origination_fees', $result->streamZ);
        $this->assertArrayHasKey('direct_lending', $result->streamZ);
        $this->assertArrayHasKey('credit', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['origination_fees'] + $result->streamRevenue['direct_lending'],
            1.0
        );
    }

    public function testWholesaleLeverageLimitMatchesOperatingCapacity(): void
    {
        $this->assertSame(8.0, $this->model->getWholesaleLeverageLimit());
    }

    public function testCalculateInterestIncomeYieldsFromMortgageAndDirectLendingPortfolios(): void
    {
        $stock = new Stock();
        $stock->setTicker('POOL');
        $stock->setWholesaleDebt('400000000000.0'); // $400B debt
        $stock->setCorporateTreasury('40000000000.0'); // $40B cash

        $macro = new MacroStateDTO(
            policyRateEma: 0.04, // 4%
            yield30yEma: 0.055   // 5.5%
        );

        $income = $this->model->calculateInterestIncome($stock, $macro, $this->mathUtility);

        // Mortgage: 400B * 60% = 240B. Yield: 0.055 + 0.0225 = 0.0775 -> 240B * 0.0775 = 18.6B
        // Direct Lending: 400B * 40% = 160B. Yield: 0.04 + 0.0450 = 0.0850 -> 160B * 0.0850 = 13.6B
        // Cash: excess cash = 40B - operatingBuffer (0.05 * 0 = 0) = 40B. Cash yield = max(0, 0.04 - 0.0025) = 0.0375 -> 40B * 0.0375 = 1.5B
        // Total = 18.6B + 13.6B + 1.5B = 33.7B ($33,700,000,000.0)
        $this->assertEqualsWithDelta(33_700_000_000.0, $income, 1_000_000.0);
    }

    public function testRetailDefaultAndCommercialPropertyDistressIncreasesProvisionDrag(): void
    {
        $stock = new Stock();
        $stock->setTicker('RITM');
        $stock->setBeta('1.0');

        $baseMacro = new MacroStateDTO(
            retailDefaultRateEma: 0.025,
            commercialPropertyIndexEma: 100.0
        );

        $distressMacro = new MacroStateDTO(
            retailDefaultRateEma: 0.050, // Elevated defaults
            commercialPropertyIndexEma: 80.0 // CRE valuation collapse
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $distressResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $distressMacro, $mathMock);

        // Distress increases default drag and provisions, increasing the variable cost ratio (clampedMargin)
        $this->assertGreaterThan($baseResult->clampedMargin, $distressResult->clampedMargin);
    }

    public function testResidentialPropertyIndexDrivesMortgageOriginationAndProvisions(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHOR');
        $stock->setBeta('1.0');

        $depressedMacro = new MacroStateDTO(residentialPropertyIndexEma: 75.0);
        $boomMacro = new MacroStateDTO(residentialPropertyIndexEma: 130.0);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $depressedResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $depressedMacro, $mathMock);
        $boomResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $boomMacro, $mathMock);

        // High residential property values stimulate mortgage origination
        $this->assertGreaterThan(
            $depressedResult->streamRevenue['origination_fees'],
            $boomResult->streamRevenue['origination_fees'],
            'Residential property index growth should expand mortgage origination fee volume.'
        );

        $this->assertGreaterThan(
            $boomResult->clampedMargin,
            $depressedResult->clampedMargin,
            'Depressed residential property values must increase credit provision costs on mortgage portfolios.'
        );
    }

    public function testInterbankLiquiditySpreadCompressesShadowBankMortgageNIM(): void
    {
        $stock = new Stock();
        $stock->setTicker('RITM');
        $stock->setBeta('1.0');

        $calmMacro = new MacroStateDTO(
            policyRateEma: 0.04,
            yield30yEma: 0.06,
            interbankLiquiditySpreadEma: 0.0010
        );

        $tedSpikeMacro = new MacroStateDTO(
            policyRateEma: 0.04,
            yield30yEma: 0.06,
            interbankLiquiditySpreadEma: 0.0200
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $calmResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $calmMacro, $mathMock);
        $spikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $tedSpikeMacro, $mathMock);

        $this->assertGreaterThan(
            $calmResult->clampedMargin,
            $spikeResult->clampedMargin,
            'TED spread spike raises shadow bank repo borrowing costs, squeezes mortgage NIM, and expands variable cost ratio.'
        );
        $this->assertLessThan($calmResult->ebit, $spikeResult->ebit);
    }

    public function testSloosCreditTighteningExpandsDirectLendingOrigination(): void
    {
        $stock = new Stock();
        $stock->setTicker('BXSL');
        $stock->setBeta('1.0');

        $neutralMacro = new MacroStateDTO(sloosTighteningIndexEma: 0.0, policyRateEma: 0.05);
        $tighteningMacro = new MacroStateDTO(sloosTighteningIndexEma: 0.40, policyRateEma: 0.05);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $neutralMacro, $mathMock);
        $tighteningResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $tighteningMacro, $mathMock);

        $this->assertGreaterThan(
            $neutralResult->streamRevenue['direct_lending'],
            $tighteningResult->streamRevenue['direct_lending'],
            'Commercial bank credit tightening (SLOOS) drives corporate borrowers to private credit, expanding direct lending volume.'
        );
    }

    public function testCorporateDefaultAndRecessionRiskIncreaseLossProvisions(): void
    {
        $stock = new Stock();
        $stock->setTicker('BXSL');
        $stock->setBeta('1.0');

        $benignMacro = new MacroStateDTO(
            corporateDefaultRateEma: 0.018,
            recessionProbabilityEma: 0.05
        );

        $stressMacro = new MacroStateDTO(
            corporateDefaultRateEma: 0.080,
            recessionProbabilityEma: 0.65
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $benignResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $benignMacro, $mathMock);
        $stressResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $stressMacro, $mathMock);

        $this->assertGreaterThan(
            $benignResult->clampedMargin,
            $stressResult->clampedMargin,
            'Surging corporate default rates and forward recession risk must increase shadow bank loss provisions and CECL reserves.'
        );
    }

    public function testBaselineCreditSpreadDoesNotImposeUnprovokedCeclDrag(): void
    {
        $stock = new Stock();
        $stock->setTicker('BXSL');
        $stock->setBeta('1.0');

        $neutralMacro = new MacroStateDTO(
            macroCreditSpreadEma: 0.020, // Baseline 200 bps
            recessionProbabilityEma: 0.15, // Baseline 15%
            yield30yEma: 0.055,
            policyRateEma: 0.040,
            interbankLiquiditySpreadEma: 0.0010
        );

        $widenedMacro = new MacroStateDTO(
            macroCreditSpreadEma: 0.040, // 400 bps blowout (+200 bps)
            recessionProbabilityEma: 0.15,
            yield30yEma: 0.055,
            policyRateEma: 0.040,
            interbankLiquiditySpreadEma: 0.0010
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $neutralMacro, $mathMock);
        $widenedResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $widenedMacro, $mathMock);

        // The forward reserve is a balance, not a flow: a widened spread raises the lifetime loss target the
        // allowance converges to (booked once by the ledger) and leaves the running margin alone.
        $this->assertEqualsWithDelta($neutralResult->clampedMargin, $widenedResult->clampedMargin, 1e-12, 'the forecast reserve is not a running cost');
        $this->assertGreaterThan(
            $this->model->getForwardCreditLossMultiplier($stock, $neutralMacro),
            $this->model->getForwardCreditLossMultiplier($stock, $widenedMacro),
            'Widened credit spread must raise the forward lifetime loss estimate above baseline.'
        );
        $this->assertEqualsWithDelta(
            0.020 * ShadowBankBusinessModel::CECL_RESERVE_SPREAD_SENSITIVITY,
            $this->model->getForwardCreditLossMultiplier($stock, $widenedMacro) - $this->model->getForwardCreditLossMultiplier($stock, $neutralMacro),
            1e-9
        );
    }
}

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
        $thresholds = $this->model->getModelThresholds();
        $this->assertSame(8.0, $thresholds['wholesale_leverage_limit']);
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

        $mathMock = $this->createMock(MathUtility::class);
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

        $mathMock = $this->createMock(MathUtility::class);
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

        $mathMock = $this->createMock(MathUtility::class);
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
}

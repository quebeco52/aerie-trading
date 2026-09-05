<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\AssetManagementBusinessModel;
use App\Service\Model\Sector\PrivateEquityBusinessModel;
use PHPUnit\Framework\TestCase;

class AssetManagementBusinessModelTest extends TestCase
{
    public function testGetTargetMetricsDoesNotDoubleLeverageOperatingYield(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('300.0');
        $stock->setCorporateTreasury('0.0');
        $stock->setBaselineRoe('0.20');
        $stock->setRoeTtm('0.0');
        $stock->setOperatingMargin('0.30');
        $stock->setCreditSpread('0.01');
        $stock->setFloatingDebtRatio('0.5');
        $stock->setIndustry('Asset Management');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.04,
            'corporate_tax_rate' => 0.25,
        ]);

        $metrics = $model->getTargetMetrics($stock, $macroState, $mathUtilityMock);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('invested_capital', $metrics);
        $this->assertArrayHasKey('baseline_roic', $metrics);

        // Invested capital should equal earning assets (Equity + WholesaleDebt - Treasury = 400.0)
        $this->assertEqualsWithDelta(400.0, $metrics['invested_capital'], 0.01);

        // Baseline ROIC should reflect structural operating return without double-leverage inflation
        // With 20% target ROE and 25% tax, target net income on 100 equity is 20, EBT is 26.67.
        // Interest expense on 300 debt at ~5% is 15, so optimal EBIT is ~41.67 across 400 earning assets (~10.42% yield).
        $this->assertLessThan(0.30, $metrics['baseline_roic']);
        $this->assertGreaterThan(0.01, $metrics['baseline_roic']);
    }

    public function testPrivateEquityBusinessModelInheritsCorrectTargetMetrics(): void
    {
        $model = new PrivateEquityBusinessModel();
        $stock = new Stock();
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('200.0');
        $stock->setCorporateTreasury('10.0');
        $stock->setBaselineRoe('0.21');
        $stock->setRoeTtm('0.0');
        $stock->setOperatingMargin('0.35');
        $stock->setCreditSpread('0.015');
        $stock->setFloatingDebtRatio('0.4');
        $stock->setIndustry('Private Equity');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'corporate_tax_rate' => 0.25,
        ]);

        $metrics = $model->getTargetMetrics($stock, $macroState, $mathUtilityMock);

        $this->assertArrayHasKey('invested_capital', $metrics);
        $this->assertArrayHasKey('baseline_roic', $metrics);
        $this->assertEqualsWithDelta(290.0, $metrics['invested_capital'], 0.01);
    }

    public function testModelTraitsAndCoverageProfile(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');

        $this->assertEquals(0.12, $model->getReversionSpeed());
        $this->assertEquals(0.012, $model->getMoatSpread());
        $this->assertEquals(0.035, $model->getSecularGrowthRate($stock));

        $surpriseWeights = $model->getSurpriseBlendWeights();
        $this->assertEquals(0.75, $surpriseWeights['eps_weight']);
        $this->assertEquals(0.25, $surpriseWeights['revenue_weight']);

        $coverage = $model->getCoverageProfile($stock);
        $this->assertEquals(AssetManagementBusinessModel::BASE_COVERAGE_VISIBILITY, $coverage->baseVisibility);
        $this->assertEquals(AssetManagementBusinessModel::BASE_COVERAGE_ERROR, $coverage->errorStdDev);
        $this->assertEquals(AssetManagementBusinessModel::BASE_COVERAGE_MIN_VISIBILITY, $coverage->minVisibility);
    }

    public function testMacroPhysicsNullifiesGenericDemandShift(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.2');

        $macro = new \App\DTO\MacroStateDTO(outputGapEma: 0.05);
        $physics = $model->getMacroPhysics($stock, $macro);

        $this->assertEquals(0.0, $physics['macro_demand_shift'], 'Asset management must nullify generic demand shift to avoid double-counting AUM beta.');
    }

    public function testPerformanceFeeSurgeAndCompensationPoolFlex(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.0');

        $macro = new \App\DTO\MacroStateDTO(outputGapEma: 0.0);

        // Alpha Z = 2.50 (above 1.50 threshold), BaseFee Z = 0.0
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, 2.50);

        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathMock
        );

        // Alpha fee bonus = (2.50 - 1.50) * 0.08 = +0.08
        // Perf revenue = 20M * (1 + 0.08) = 21.6M
        // Base revenue = 80M
        // Total revenue = 101.6M
        $this->assertGreaterThan(100_000_000.0, $result->actualRevenue);
        $this->assertGreaterThan(20_000_000.0, $result->streamRevenue['alpha']);

        // Compensation pool flex: $1.6M excess * 0.40 = $640k bonus pool expense
        // Raw variable margin increases from baseline to fund bonus pool
        $this->assertGreaterThan(0.30, $result->clampedMargin, 'Incentive fee crystallization must expand variable compensation bonus pools.');
    }

    public function testInstitutionalRedemptionOutflowAttrition(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.0');

        $macro = new \App\DTO\MacroStateDTO(outputGapEma: 0.0);

        // Alpha Z = -2.50 (severe redemptions below -1.50), BaseFee Z = 0.0
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, -2.50);

        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0, // Zero baseline vol to isolate deterministic attrition
            macroState: $macro,
            mathUtility: $mathMock
        );

        // Redemption excess = |-2.50 - (-1.50)| = 1.0
        // Base attrition = 1.0 * 0.04 = 0.04
        // Base revenue = 80M * (1 - 0.04) = 76.8M
        $this->assertEqualsWithDelta(76_800_000.0, $result->streamRevenue['base_fee'], 1.0);
        $this->assertLessThan(80_000_000.0, $result->streamRevenue['base_fee'], 'Institutional redemptions must erode base AUM management fee revenue.');
    }

    public function testSupportsUnderleveragedDebtExpansion(): void
    {
        $assetManagerModel = new AssetManagementBusinessModel();
        $this->assertFalse($assetManagerModel->supportsUnderleveragedDebtExpansion());

        $peModel = new PrivateEquityBusinessModel();
        $this->assertTrue($peModel->supportsUnderleveragedDebtExpansion());
    }

    public function testPrivateEquityIsUnderLeveragedThreshold(): void
    {
        $peModel = new PrivateEquityBusinessModel();

        // PE default equity limit is 2.5 (from getWholesaleLeverageLimit) or 1.0 default
        // 85% of 1.0 (default) is 0.85
        $this->assertTrue($peModel->isUnderLeveraged(0.80, 1.0, 5.0, 1.05, 0.12, 0.05));
        $this->assertFalse($peModel->isUnderLeveraged(0.90, 1.0, 5.0, 1.05, 0.12, 0.05));
    }
}

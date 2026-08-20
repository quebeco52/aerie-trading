<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\ReitBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReitBusinessModelTest extends TestCase
{
    private function createMacroState(
        float $inflation = 0.02,
        float $outputGap = 0.0,
        float $yield10y = 0.04,
        float $equityRiskPremium = 0.05,
        float $policyRate = 0.04
    ): MacroStateDTO {
        return new MacroStateDTO(
            outputGap: $outputGap,
            outputGapEma: $outputGap,
            unemploymentRate: 0.04,
            unemploymentRateEma: 0.04,
            energyPriceIndex: 100.0,
            energyPriceIndexEma: 100.0,
            energyPriceShock: 0.0,
            consumerSentimentIndex: 100.0,
            consumerSentimentIndexEma: 100.0,
            inflation: $inflation,
            inflationEma: $inflation,
            policyRate: $policyRate,
            policyRateEma: $policyRate,
            targetRate: $policyRate,
            yield2y: 0.04,
            yield2yEma: 0.04,
            yield5y: 0.04,
            yield5yEma: 0.04,
            yield10y: $yield10y,
            yield10yEma: $yield10y,
            yield30y: 0.04,
            yield30yEma: 0.04,
            marketVolatility: 0.15,
            marketVolatilityEma: 0.15,
            marketZ: 0.0,
            corporateTaxRate: 0.21,
            equityRiskPremium: $equityRiskPremium,
            macroCreditSpread: 0.015,
            macroCreditSpreadEma: 0.015,
            qeActive: false,
            qeIntensity: 0.0,
            inversionDuration: 0.0,
            nsLevel: 0.04,
            nsSlope: 0.0,
            nsSlopeEma: 0.0,
            nsCurvature: 0.0,
            potentialGdpIndex: 1.0,
            nominalGdpIndex: 1.0,
        );
    }

    private function createMathUtilityMock(array $persistentZCalls = []): MathUtility
    {
        $mock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        if (!empty($persistentZCalls)) {
            $mock->method('generatePersistentZ')
                ->willReturnOnConsecutiveCalls(...$persistentZCalls);
        }

        return $mock;
    }

    public function testEffectiveTaxRateIsPassThroughZero(): void
    {
        $model = new ReitBusinessModel();
        $this->assertEquals(0.00, $model->getEffectiveTaxRate(0.21));
        $this->assertEquals(0.00, $model->getEffectiveTaxRate(0.35));
    }

    public function testFundsFromOperationsInterestCoverage(): void
    {
        $model = new ReitBusinessModel();

        // Standard FFO ICR: (EBIT + Depreciation + Interest Income) / Interest Expense
        // ebit = 100, dep = 50, interest income = 10, interest expense = 80 => (100 + 50 + 10) / 80 = 2.0
        $icr = $model->getInterestCoverage(ebit: 100.0, interestExpense: 80.0, depreciation: 50.0, interestIncome: 10.0);
        $this->assertEqualsWithDelta(2.0, $icr, 0.0001);

        // Zero interest expense with positive FFO -> Infinite positive fallback
        $posIcr = $model->getInterestCoverage(ebit: 100.0, interestExpense: 0.0, depreciation: 50.0);
        $this->assertEquals(ReitBusinessModel::INFINITE_ICR_POS_FALLBACK, $posIcr);

        // Zero interest expense with negative FFO -> Infinite negative fallback
        $negIcr = $model->getInterestCoverage(ebit: -200.0, interestExpense: 0.0, depreciation: 50.0);
        $this->assertEquals(ReitBusinessModel::INFINITE_ICR_NEG_FALLBACK, $negIcr);
    }

    public function testCapRateTetheringToMacroRates(): void
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setTicker('REIT_PROP');
        $stock->setTotalEquity('500000000');
        $stock->setWholesaleDebt('500000000');
        $stock->setCorporateTreasury('0');
        $stock->setBaselineRoic('0.07');

        // Macro: 10y yield = 4.5%, ERP = 5.5% => Target Cap Rate = 10.0%
        $macro = $this->createMacroState(yield10y: 0.045, equityRiskPremium: 0.055);
        $math = new MathUtility();

        $metrics = $model->getTargetMetrics($stock, $macro, $math);

        $this->assertArrayHasKey('baseline_roic', $metrics);
        $this->assertArrayHasKey('invested_capital', $metrics);
        $this->assertEquals(1000000000.0, $metrics['invested_capital']);

        // Blended Cap Rate = (0.07 * 0.975) + (0.10 * 0.025) = 0.06825 + 0.0025 = 0.07075
        // Baseline ROIC is updated in stock
        $this->assertEqualsWithDelta(0.07075, (float) $stock->getBaselineRoic(), 0.0001);
    }

    public function testRevenueAndRentEscalatorPhysics(): void
    {
        $model = new ReitBusinessModel();

        // 1. Default stock mix (85% lease, 15% hospitality)
        $defaultStock = new Stock();
        $defaultStock->setTicker('DEFAULT_REIT');

        // High inflation: 5% vs 2% target => 3% excess inflation
        // Rent escalator = 0.03 * 0.80 = 0.024 (+2.4% lease revenue boost)
        $macroState = $this->createMacroState(inflation: 0.05);

        // Z-scores: lease = 1.0, hospitality = 0.5, tenantDefault = 0.0
        $mathMock = $this->createMathUtilityMock([1.0, 0.5, 0.0]);

        $defaultResult = $model->computeActualFinancials(
            $defaultStock,
            expectedRevenue: 1000000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 100000.0,
            baselineVol: 0.20,
            macroState: $macroState,
            mathUtility: $mathMock
        );

        // Defaults: 85% lease, 15% hospitality
        // leaseShock = 1.0 * (0.20 * 0.05) = 0.01
        // rentEscalator = 0.024
        // leaseRevenue = 1,000,000 * 0.85 * (1 + 0.01 + 0.024) = 850,000 * 1.034 = 878,900
        // hospitalityShock = 0.5 * (0.20 * 1.50) = 0.15
        // hospitalityRevenue = 1,000,000 * 0.15 * (1 + 0.15) = 150,000 * 1.15 = 172,500
        // totalRevenue = 878,900 + 172,500 = 1,051,400
        $this->assertEqualsWithDelta(1051400.0, $defaultResult->actualRevenue, 1.0);

        // Observable shock Z: ((leaseShock + rentEscalator) * leaseWeight) + (hospitalityShock * hospitalityWeight)
        // = (0.034 * 0.85) + (0.15 * 0.15) = 0.0289 + 0.0225 = 0.0514
        $this->assertEqualsWithDelta(0.0514, $defaultResult->observableShockZ, 0.0001);

        // 2. PLZA archetype tuning (80% lease, 20% hospitality)
        $plzaStock = new Stock();
        $plzaStock->setTicker('PLZA');

        $mathMockPlza = $this->createMathUtilityMock([1.0, 0.5, 0.0]);
        $plzaResult = $model->computeActualFinancials(
            $plzaStock,
            expectedRevenue: 1000000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 100000.0,
            baselineVol: 0.20,
            macroState: $macroState,
            mathUtility: $mathMockPlza
        );

        // leaseRevenue = 800,000 * 1.034 = 827,200
        // hospitalityRevenue = 200,000 * 1.15 = 230,000
        // totalRevenue = 1,057,200
        $this->assertEqualsWithDelta(1057200.0, $plzaResult->actualRevenue, 1.0);
        // observableShockZ = (0.034 * 0.80) + (0.15 * 0.20) = 0.0272 + 0.0300 = 0.0572
        $this->assertEqualsWithDelta(0.0572, $plzaResult->observableShockZ, 0.0001);
    }

    public function testSecuritizationArchetypeShockInclusion(): void
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHOR'); // Lakeshore Living: 75% lease, 10% hospitality, 15% securitization

        $macroState = $this->createMacroState(inflation: 0.02); // 0% excess inflation

        // Z-scores: lease = 0.0, hospitality = 0.0, securitization = 2.0, tenantDefault = 0.0
        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 2.0, 0.0]);

        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 100000.0,
            baselineVol: 0.10,
            macroState: $macroState,
            mathUtility: $mathMock
        );

        // Securitization shock = 2.0 * 0.10 * 2.00 = 0.40
        // Securitization revenue = 1,000,000 * 0.15 * 1.40 = 210,000
        $this->assertEqualsWithDelta(210000.0, $result->streamRevenue['securitization_income'], 1.0);

        // Observable shock Z = 0.40 * 0.15 = 0.060
        $this->assertEqualsWithDelta(0.060, $result->observableShockZ, 0.0001);
    }

    public function testLongevityArchetypeShockInclusion(): void
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setTicker('ELDE'); // Elderbird: 80% lease, 5% hospitality, 15% longevity

        $macroState = $this->createMacroState(inflation: 0.02);

        // Z-scores: lease = 0.0, hospitality = 0.0, longevity = -2.0, tenantDefault = 0.0
        $mathMock = $this->createMathUtilityMock([0.0, 0.0, -2.0, 0.0]);

        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 100000.0,
            baselineVol: 0.10,
            macroState: $macroState,
            mathUtility: $mathMock
        );

        // Longevity shock = -2.0 * 0.10 * 0.25 = -0.05
        // Longevity revenue = 1,000,000 * 0.15 * 0.95 = 142,500
        $this->assertEqualsWithDelta(142500.0, $result->streamRevenue['longevity_bond_yield'], 1.0);

        // Observable shock Z = -0.05 * 0.15 = -0.0075
        $this->assertEqualsWithDelta(-0.0075, $result->observableShockZ, 0.0001);
    }

    public function testTenantDefaultAndVacancyMarginPenalties(): void
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setTicker('REIT_VACANCY');

        $macroState = $this->createMacroState(yield10y: 0.04); // Default 10y yield -> no refinancing drag

        // 1. Severe tenant bankruptcies (tenantDefaultZ = -2.50)
        $bankruptcyMock = $this->createMathUtilityMock([0.0, 0.0, -2.50]);
        $bankruptcyResult = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 100000.0,
            baselineVol: 0.10,
            macroState: $macroState,
            mathUtility: $bankruptcyMock
        );

        // Penalty = 2.50 * 0.08 = +0.20 margin penalty (cost increase)
        // Variable margin = 0.30 + 0.20 = 0.50
        $this->assertEqualsWithDelta(0.50, $bankruptcyResult->clampedMargin, 0.001);
        $this->assertEquals(ShockEvent::REIT_TENANT_BANKRUPTCIES, $bankruptcyResult->eventType);
        $this->assertTrue($bankruptcyResult->isPublicEvent);

        // 2. Moderate vacancies (tenantDefaultZ = -1.60)
        $vacancyMock = $this->createMathUtilityMock([0.0, 0.0, -1.60]);
        $vacancyResult = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 100000.0,
            baselineVol: 0.10,
            macroState: $macroState,
            mathUtility: $vacancyMock
        );

        $this->assertEquals(ShockEvent::REIT_ELEVATED_VACANCIES, $vacancyResult->eventType);

        // 3. Benign leasing boom (tenantDefaultZ = +2.00)
        $leasingBonusMock = $this->createMathUtilityMock([0.0, 0.0, 2.00]);
        $leasingBonusResult = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 100000.0,
            baselineVol: 0.10,
            macroState: $macroState,
            mathUtility: $leasingBonusMock
        );

        // Bonus = - (2.00 - 1.00) * 0.015 = -0.015 margin cost reduction
        // Clamped margin = 0.30 - 0.015 = 0.285
        $this->assertEqualsWithDelta(0.285, $leasingBonusResult->clampedMargin, 0.001);
    }

    public function testRefinancingWallDrag(): void
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setTicker('REIT_REFI');

        // 10y Yield = 6.0% (200bps above 4.0% fallback)
        // Refinancing drag = (0.06 - 0.04) * 0.25 = +0.005 margin drag
        $macroState = $this->createMacroState(yield10y: 0.06);

        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0]);
        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 100000.0,
            baselineVol: 0.10,
            macroState: $macroState,
            mathUtility: $mathMock
        );

        $this->assertEqualsWithDelta(0.305, $result->clampedMargin, 0.001);
    }

    public function testAssetDepreciationDecayAndModernization(): void
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setOperatingMargin('0.25');

        // Under-reinvestment (reinvestmentRatio = 0.50, dt = 0.25 => 1 quarter)
        // decayRate = 0.015 * (1.0 - 0.50) * 1.0 = 0.0075
        // updatedMargin = 0.25 - (0.25 * 0.0075) = 0.248125
        $model->applyAssetDepreciationDecay($stock, reinvestmentRatio: 0.50, dt: 0.25);
        $this->assertEqualsWithDelta(0.248125, (float) $stock->getOperatingMargin(), 0.0001);

        // Over-reinvestment / modernization (reinvestmentRatio = 2.0, dt = 0.25)
        $stock->setOperatingMargin('0.25');
        // modGain = 0.008 * ln(2.0) * 1.0 = 0.008 * 0.693147 = 0.005545
        // updatedMargin = 0.25 + ((0.45 - 0.25) * 0.005545) = 0.25 + (0.20 * 0.005545) = 0.251109
        $model->applyAssetDepreciationDecay($stock, reinvestmentRatio: 2.0, dt: 0.25);
        $this->assertEqualsWithDelta(0.251109, (float) $stock->getOperatingMargin(), 0.0001);
    }

    public function testSustainableDividendBaseAndFairValue(): void
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setSharesOutstanding('10000000'); // 10M shares

        // Sustainable Dividend Base: EPS + quarterly depreciation per share
        // Invested capital = $800M, depRate = 5% => annual dep = $40M => quarterly dep = $10M
        // Quarterly dep per share = $10M / 10M shares = $1.00
        // Quarterly EPS = $0.50 => Sustainable base = $0.50 + $1.00 = $1.50
        $sustainableBase = $model->getSustainableDividendBase(
            $stock,
            quarterlyEps: 0.50,
            investedCapital: 800000000.0,
            depRate: 0.05
        );
        $this->assertEqualsWithDelta(1.50, $sustainableBase, 0.0001);

        // Fair value with DDM support:
        // P/B fair value = 50.0 (40% weight) -> 20.0
        // Earnings value = 60.0 (30% weight) -> 18.0
        // Dividend support = 70.0 (30% weight) -> 21.0
        // Total fair value = 20.0 + 18.0 + 21.0 = 59.0
        $fairValue = $model->calculateFairValue(
            earningsValue: 60.0,
            pbFairValue: 50.0,
            normalizedEps: 2.0,
            dividendSupportValue: 70.0
        );
        $this->assertEqualsWithDelta(59.0, $fairValue, 0.0001);
    }
}

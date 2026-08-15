<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Data\CeoArchetypes;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CapitalAllocationEngineTest extends TestCase
{
    private CorporateLedgerService|MockObject $corporateLedgerServiceMock;
    private CorporateMetrics|MockObject $corporateMetricsMock;
    private DebtEngine|MockObject $debtEngineMock;
    private MathUtility|MockObject $mathUtilityMock;
    private TreasuryEngine|MockObject $treasuryEngineMock;
    private CapitalAllocationEngine $engine;

    protected function setUp(): void
    {
        $this->corporateLedgerServiceMock = $this->createMock(CorporateLedgerService::class);
        $this->corporateMetricsMock = $this->createMock(CorporateMetrics::class);
        $this->corporateMetricsMock->method('getIndustryDepreciationRate')->willReturn(0.05);
        $this->corporateMetricsMock->method('calculateMarketSaturationPenalty')->willReturn(0.0);

        $this->debtEngineMock = $this->createMock(DebtEngine::class);

        $debtMetricsMock = new DebtMetricsDTO(
            interestExpense: 500000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 2000000.0,
            revenue: 10000000.0,
            depreciation: 500000.0,
            ebitda: 2500000.0
        );

        $debtHealthMock = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 5.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.0,
            rawMetrics: $debtMetricsMock,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($debtHealthMock);

        $this->mathUtilityMock = $this->createMock(MathUtility::class);
        $this->treasuryEngineMock = $this->createMock(TreasuryEngine::class);

        $this->engine = new CapitalAllocationEngine(
            $this->corporateLedgerServiceMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );
    }

    public function testReitFfoDividendCapacity(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST_REIT');
        $stock->setIndustry('REIT - Diversified');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.80');
        $stock->setDividendSpeed('1.00');
        $stock->setLastDividend('0.00');
        $stock->setRetainedEarnings('50000000.00');
        $stock->setCeoArchetype(CeoArchetypes::OPPORTUNIST);
        $stock->setDepreciationRate('0.05');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        // Actual annual EPS is 4.00 ($1.00 quarterly). With Invested Capital 100M and Dep 5%, quarterly dep is $1.25M ($1.25/share).
        // FFO per share = 1.00 + 1.25 = 2.25. Payout target 80% = 1.80 per share.
        $result = $this->engine->allocateCapital($stock, 4.00, 2.00, 100.00, 1000000.0, $macroState);

        $this->assertGreaterThan(1.00, $result['dividend_paid'], 'REIT dividend should reflect FFO rather than raw GAAP EPS');
    }

    public function testDividendDistributionCallsLedgerService(): void
    {
        $this->corporateLedgerServiceMock->expects($this->once())
            ->method('processDividendPayment')
            ->with($this->isInstanceOf(Stock::class), $this->greaterThan(0.0));

        $stock = new Stock();
        $stock->setTicker('TEST_DIV');
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.50');
        $stock->setDividendSpeed('1.00');
        $stock->setLastDividend('1.00');
        $stock->setCeoArchetype(CeoArchetypes::OPPORTUNIST);
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        $this->engine->allocateCapital($stock, 4.00, 2.00, 100.00, 1000000.0, $macroState);
    }
}

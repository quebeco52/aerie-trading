<?php

declare(strict_types=1);

namespace App\Tests\Service;

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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CapitalAllocationEngineTest extends TestCase
{
    private CorporateLedgerService&Stub $corporateLedgerServiceMock;
    private CorporateMetrics&Stub $corporateMetricsMock;
    private DebtEngine&Stub $debtEngineMock;
    private MathUtility&Stub $mathUtilityMock;
    private TreasuryEngine&Stub $treasuryEngineMock;
    private CapitalAllocationEngine $engine;

    protected function setUp(): void
    {
        $this->corporateLedgerServiceMock = $this->createStub(CorporateLedgerService::class);
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);
        $this->corporateMetricsMock->method('getIndustryDepreciationRate')->willReturn(0.05);
        $this->corporateMetricsMock->method('calculateMarketSaturationPenalty')->willReturn(0.0);

        $this->corporateMetricsMock->method('calculateSaturationSeverity')->willReturnCallback(
            fn(float $p, float $r) => $p <= 0.0 ? 0.0 : min(1.0, $p / max(0.01, $r + $p))
        );
        $this->corporateMetricsMock->method('calculateLifeCyclePayoutRatio')->willReturnCallback(
            fn(float $b, float $s) => min(0.85, max($b, $b + ((0.85 - $b) * $s)))
        );
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(5000000.0);

        $this->debtEngineMock = $this->createStub(DebtEngine::class);

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

        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->mathUtilityMock->method('calculateIntrinsicFairValuePE')->willReturn(15.0);

        $this->treasuryEngineMock = $this->createStub(TreasuryEngine::class);

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
        $stock->setDepreciationRate('0.05');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        // In EarningsEngine, actual annual EPS for REITs is computed as FFO per share (Net Income + Depreciation).
        // For 1M shares with $1.00 quarterly net income and $1.25 quarterly depreciation, FFO per share is $2.25 quarterly ($9.00 annual).
        // With an 80% target payout ratio on FFO, target quarterly dividend is $1.80 per share.
        $annualFfoEps = 9.00;
        $result = $this->engine->allocateCapital($stock, $annualFfoEps, 2.00, 100.00, 1000000.0, $macroState);

        $this->assertEqualsWithDelta(1.80, $result['dividend_paid'], 0.0001, 'REIT dividend should reflect 80% target payout on FFO');
    }

    public function testDividendDistributionCallsLedgerService(): void
    {
        $mockLedger = $this->createMock(CorporateLedgerService::class);
        $mockLedger->expects($this->once())
            ->method('processDividendPayment')
            ->with(
                $this->isInstanceOf(Stock::class),
                $this->greaterThan(0.0),
                $this->isInstanceOf(\DateTimeInterface::class)
            );

        $engine = new CapitalAllocationEngine(
            $mockLedger,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );

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
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        $engine->allocateCapital($stock, 4.00, 2.00, 100.00, 1000000.0, $macroState);
    }

    public function testLifeCycleDividendExpansionUnderSaturation(): void
    {
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);
        $this->corporateMetricsMock->method('getIndustryDepreciationRate')->willReturn(0.05);
        $this->corporateMetricsMock->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetricsMock->method('calculateSaturationSeverity')->willReturn(1.0);
        $this->corporateMetricsMock->method('calculateLifeCyclePayoutRatio')->willReturn(0.85);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(5000000.0);

        $engine = new CapitalAllocationEngine(
            $this->corporateLedgerServiceMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );

        $stock = new Stock();
        $stock->setTicker('TEST_SAT');
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.30'); // baseline target is 30%
        $stock->setDividendSpeed('1.00');
        $stock->setLastDividend('0.00');
        $stock->setRetainedEarnings('50000000.00');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setRoicTtm('0.05');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        // Quarterly EPS = $2.00 ($8.00 annual). Under 100% saturation, effective payout is 85% ($1.70 per share)
        $result = $engine->allocateCapital($stock, 8.00, 2.00, 100.00, 1000000.0, $macroState);

        $this->assertEqualsWithDelta(1.70, $result['dividend_paid'], 0.0001, 'Saturated company should expand dividend payout to 85% under Life-Cycle physics');
    }

    public function testSaturationBuybackUnlock(): void
    {
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);
        $this->corporateMetricsMock->method('getIndustryDepreciationRate')->willReturn(0.05);
        $this->corporateMetricsMock->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetricsMock->method('calculateSaturationSeverity')->willReturn(1.0);
        $this->corporateMetricsMock->method('calculateLifeCyclePayoutRatio')->willReturn(0.85);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(5000000.0);

        $engine = new CapitalAllocationEngine(
            $this->corporateLedgerServiceMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );

        $stock = new Stock();
        $stock->setTicker('TEST_BUYBACK_SAT');
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000'); // $50M treasury
        $stock->setTargetPayoutRatio('0.00'); // no dividend to focus on buyback
        $stock->setDividendSpeed('1.00');
        $stock->setLastDividend('0.00');
        $stock->setRetainedEarnings('50000000.00');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('0.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setRoicTtm('0.05');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        // Excess cash is ~$45M ($50M treasury - ~$5M target operating cash).
        // Under 100% saturation, BUYBACK_SPEND_SATURATED_RATIO (50%) is unlocked: ~$22.5M spent on buybacks (~225k shares repurchased).
        $result = $engine->allocateCapital($stock, 8.00, 2.00, 100.00, 1000000.0, $macroState);

        $this->assertLessThan(1000000.0, $result['new_shares'], 'Saturated firm should repurchase significant shares');
        $this->assertGreaterThan(100000, 1000000.0 - $result['new_shares'], 'Should buy back over 100k shares using unlocked saturation buyback ratio');
    }

    public function testDividendAristocratCatchupUnderSaturation(): void
    {
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);
        $this->corporateMetricsMock->method('getIndustryDepreciationRate')->willReturn(0.05);
        $this->corporateMetricsMock->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetricsMock->method('calculateSaturationSeverity')->willReturn(1.0);
        $this->corporateMetricsMock->method('calculateLifeCyclePayoutRatio')->willReturn(0.85);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(5000000.0);

        $engine = new CapitalAllocationEngine(
            $this->corporateLedgerServiceMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );

        $stock = new Stock();
        $stock->setTicker('TEST_ARISTOCRAT');
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.02'); // Aristocrat <= 0.03
        $stock->setLastDividend('1.00');
        $stock->setRetainedEarnings('50000000.00');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setRoicTtm('0.08');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        // Quarterly EPS = $2.00 ($8.00 annual). Target is $1.70 per share.
        // Catch-up ratio is 1.70 / 1.00 = 1.70 > 1.30.
        // Speed accelerates from 0.02 to min(0.15, 0.02 + (1.70 - 1.30) * 0.10) = 0.06.
        // New dividend = 1.00 + 0.06 * (1.70 - 1.00) = 1.042.
        $result = $engine->allocateCapital($stock, 8.00, 2.00, 100.00, 1000000.0, $macroState);

        $this->assertEqualsWithDelta(1.042, $result['dividend_paid'], 0.001, 'Aristocrat should accelerate dividend growth safely without jumping directly to $1.70');
    }

    public function testCommercialBankCapitalConservationBufferHaltsDividends(): void
    {
        $engine = new CapitalAllocationEngine(
            $this->corporateLedgerServiceMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );

        $bank = new Stock();
        $bank->setTicker('BUFFER_BANK');
        $bank->setIndustry('Banks - Regional');
        $bank->setSharesOutstanding('100000000');
        $bank->setPrice('50.00');
        $bank->setTotalEquity('5000000000'); // $5B
        $bank->setCustomerDeposits('80000000000'); // $80B
        $bank->setWholesaleDebt('10000000000'); // $10B
        $bank->setCorporateTreasury('5000000000'); // $5B
        $bank->setTargetPayoutRatio('0.40');
        $bank->setDividendSpeed('0.50');
        $bank->setLastDividend('0.50');
        $bank->setRetainedEarnings('3000000000.00');
        $bank->setTotalRevenue('5000000000.00');
        $bank->setOperatingMargin('0.30');
        $bank->setRoeTtm('0.10');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        // CET1 = 5B / 90B = ~5.56% (< 6.5% CCB threshold)
        // This triggers regulatory dividend halt (dividend_paid = 0.0)
        $result = $engine->allocateCapital($bank, 4.00, 1.00, 50.00, 100000000.0, $macroState);

        $this->assertEquals(0.0, $result['dividend_paid'], 'Bank in CCB buffer zone (CET1 < 6.5%) must have its dividend halted to $0.00');
    }

    public function testBuybackPercentageCalculatesFromOriginalSharesOutstanding(): void
    {
        $engine = new CapitalAllocationEngine(
            $this->corporateLedgerServiceMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );

        $stock = new Stock();
        $stock->setTicker('BUYBACK_CORP');
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('10.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.00');
        $stock->setDividendSpeed('0.00');
        $stock->setLastDividend('0.00');
        $stock->setTotalRevenue('50000000.00');
        $stock->setOperatingMargin('0.30');
        $stock->setRoicTtm('0.15');
        $stock->setWholesaleDebt('0.00');
        $stock->setCustomerDeposits('0.00');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        $result = $engine->allocateCapital($stock, 4.00, 2.00, 10.00, 1000000.0, $macroState);

        $this->assertLessThan(1000000.0, $result['new_shares'], 'Shares should be repurchased');
        $this->assertNotEmpty($result['events']);
        // Check that shock matches (sharesRepurchased / originalShares) * 100 * 0.5
        $sharesRepurchased = 1000000.0 - $result['new_shares'];
        $expectedPctRetired = ($sharesRepurchased / 1000000.0) * 100.0;
        $expectedShock = $expectedPctRetired * 0.5;

        $buybackEvent = null;
        foreach ($result['events'] as $event) {
            if (str_contains($event['description'], 'Bought back')) {
                $buybackEvent = $event;
                break;
            }
        }

        $this->assertNotNull($buybackEvent);
        $this->assertEqualsWithDelta($expectedShock, $buybackEvent['shock'], 0.0001);
    }
}

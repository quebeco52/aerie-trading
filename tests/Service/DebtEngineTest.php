<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\CreditRatingAgency;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DebtEngineTest extends TestCase
{
    private MathUtility&Stub $mathUtilityMock;
    private CorporateMetrics&Stub $corporateMetricsMock;
    private CreditRatingAgency $creditRatingAgency;
    private MarketEventPublisher&MockObject $marketEventPublisherMock;
    private DebtEngine $engine;

    protected function setUp(): void
    {
        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);
        $this->creditRatingAgency = new CreditRatingAgency();
        $this->marketEventPublisherMock = $this->createMock(MarketEventPublisher::class);

        $this->engine = new DebtEngine(
            $this->mathUtilityMock,
            $this->corporateMetricsMock,
            $this->creditRatingAgency,
            $this->marketEventPublisherMock
        );
    }

    public function testDebtTurnoverTriggersCreditRatingDowngradeEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEBT');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('100000000');
        $stock->setWholesaleDebt('50000000');
        $stock->setVolatility('0.25');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.21,
            yield5yEma: 0.04
        );

        // Distance to Default falls to 1.2 -> clamped to BB (downgrade from BBB)
        $this->mathUtilityMock->method('calculateDistanceToDefault')->willReturn(1.2);
        $this->mathUtilityMock->method('calculateMertonCreditSpread')->willReturn(0.04);

        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_DOWNGRADE'),
                $this->stringContains('downgraded from BBB to BB'),
                $this->equalTo(-3.0)
            );

        $this->engine->calculateInterestExpense($stock, $macroState, true);

        $this->assertSame('BB', $stock->getCreditRating());
    }

    public function testDebtTurnoverTriggersCreditRatingUpgradeEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('SAFE');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('200000000');
        $stock->setWholesaleDebt('10000000');
        $stock->setVolatility('0.15');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('200.00');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.21,
            yield5yEma: 0.04
        );

        // Distance to Default rises to 3.6 -> clamped to A (upgrade from BBB)
        $this->mathUtilityMock->method('calculateDistanceToDefault')->willReturn(3.6);
        $this->mathUtilityMock->method('calculateMertonCreditSpread')->willReturn(0.005);

        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_UPGRADE'),
                $this->stringContains('upgraded from BBB to A'),
                $this->equalTo(2.0)
            );

        $this->engine->calculateInterestExpense($stock, $macroState, true);

        $this->assertSame('A', $stock->getCreditRating());
    }

    public function testZeroDebtHealthyCompanyUpgradesOnAdvanceMaturity(): void
    {
        $stock = new Stock();
        $stock->setTicker('ZERODEBT');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('200000000');
        $stock->setWholesaleDebt('0');
        $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('50000000');
        $stock->setRetainedEarnings('50000000');
        $stock->setOperatingMargin('0.25');
        $stock->setTotalRevenue('100000000');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('200.00');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.21,
            yield5yEma: 0.04
        );

        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_UPGRADE'),
                $this->stringContains('upgraded from BBB to A'),
                $this->equalTo(2.0)
            );

        $this->engine->calculateInterestExpense($stock, $macroState, true);

        $this->assertSame('A', $stock->getCreditRating());
    }

    public function testZeroDebtDistressedCompanyDowngradesOnAdvanceMaturity(): void
    {
        $stock = new Stock();
        $stock->setTicker('BURNING');
        $stock->setCreditRating('AAA');
        $stock->setTotalEquity('-10000000');
        $stock->setWholesaleDebt('0');
        $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('1000000');
        $stock->setRetainedEarnings('-50000000');
        $stock->setOperatingMargin('-0.50');
        $stock->setTotalRevenue('5000000');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('0.10');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.21,
            yield5yEma: 0.04
        );

        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_DOWNGRADE'),
                $this->stringContains('downgraded from AAA to D'),
                $this->equalTo(-3.0)
            );

        $this->engine->calculateInterestExpense($stock, $macroState, true);

        $this->assertSame('D', $stock->getCreditRating());
    }

    public function testAnalyzeDebtHealthHealthySolventCompany(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, $this->creditRatingAgency, $this->marketEventPublisherMock);

        $stock = new Stock();
        $stock->setTicker('HEALTHY');
        $stock->setIndustry('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('1000000'); // $100M Market Cap
        $stock->setTotalEquity('80000000');
        $stock->setWholesaleDebt('20000000');
        $stock->setCorporateTreasury('30000000');
        $stock->setTotalRevenue('50000000');
        $stock->setOperatingMargin('0.25'); // $12.5M EBIT
        $stock->setBeta('1.0');
        $stock->setHistoricalFixedRate('0.04');
        $stock->setCreditSpread('0.01');

        $macro = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.20,
            yield5yEma: 0.04,
            equityRiskPremium: 0.05
        );

        $health = $engine->analyzeDebtHealth($stock, $macro);

        $this->assertFalse($health->isLiquidityCrisis);
        $this->assertFalse($health->isLiquidityWarning);
        $this->assertTrue($health->canIssueDebt);
        $this->assertFalse($health->wantsToPaydownDebt);
        $this->assertGreaterThan(5.0, $health->interestCoverage);
        $this->assertGreaterThan(0.0, $health->wacc);
        $this->assertGreaterThan(0.0, $health->costOfEquity);
        $this->assertGreaterThan(0.0, $health->leveredBeta);
    }

    public function testAnalyzeDebtHealthSevereNegativeCarryTriggersPaydown(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, $this->creditRatingAgency, $this->marketEventPublisherMock);

        $stock = new Stock();
        $stock->setTicker('NEGCARRY');
        $stock->setIndustry('Technology');
        $stock->setPrice('50.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('50000000');
        $stock->setWholesaleDebt('30000000');
        $stock->setCorporateTreasury('10000000');
        $stock->setTotalRevenue('40000000');
        $stock->setOperatingMargin('0.20');
        $stock->setBeta('1.0');
        $stock->setHistoricalFixedRate('0.14'); // Expensive 14% legacy debt
        $stock->setCreditSpread('0.05');

        $macro = new MacroStateDTO(
            policyRateEma: 0.01, // Low cash yield (1%)
            corporateTaxRate: 0.20,
            yield5yEma: 0.02
        );

        $health = $engine->analyzeDebtHealth($stock, $macro);

        $this->assertTrue($health->isNegativeCarry, 'Effective cost of debt should exceed yield on cash.');
        $this->assertTrue($health->isSevereNegativeCarry, 'Severe negative carry should be flagged when spread exceeds hurdle.');
        $this->assertTrue($health->wantsToPaydownDebt, 'Company should want to pay down expensive debt.');
    }

    public function testAnalyzeDebtHealthLiquidityCrisisAndWarning(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, $this->creditRatingAgency, $this->marketEventPublisherMock);

        // 1. Negative Operating Margin -> Crisis ($ICR < 0$)
        $stockCrisis = new Stock();
        $stockCrisis->setTicker('CRISIS');
        $stockCrisis->setIndustry('Technology');
        $stockCrisis->setPrice('10.00');
        $stockCrisis->setSharesOutstanding('1000000');
        $stockCrisis->setTotalEquity('20000000');
        $stockCrisis->setWholesaleDebt('10000000');
        $stockCrisis->setCorporateTreasury('500000'); // Low cash buffer
        $stockCrisis->setTotalRevenue('20000000');
        $stockCrisis->setOperatingMargin('-0.10'); // Negative EBIT
        $stockCrisis->setBeta('1.5');
        $stockCrisis->setHistoricalFixedRate('0.06');

        $macro = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04);
        $healthCrisis = $engine->analyzeDebtHealth($stockCrisis, $macro);

        $this->assertTrue($healthCrisis->isLiquidityCrisis);
        $this->assertFalse($healthCrisis->canIssueDebt);

        // 2. Barely positive Margin -> Warning ($0 \le ICR < minIcr$)
        $stockWarning = new Stock();
        $stockWarning->setTicker('WARN');
        $stockWarning->setIndustry('Technology');
        $stockWarning->setPrice('20.00');
        $stockWarning->setSharesOutstanding('1000000');
        $stockWarning->setTotalEquity('20000000');
        $stockWarning->setWholesaleDebt('10000000');
        $stockWarning->setCorporateTreasury('500000');
        $stockWarning->setTotalRevenue('20000000');
        $stockWarning->setOperatingMargin('0.02'); // Tiny EBIT ($400k) vs interest ~$600k -> ICR < 1.0 < minIcr (2.5)
        $stockWarning->setBeta('1.0');
        $stockWarning->setHistoricalFixedRate('0.06');

        $healthWarning = $engine->analyzeDebtHealth($stockWarning, $macro);

        $this->assertFalse($healthWarning->isLiquidityCrisis);
        $this->assertTrue($healthWarning->isLiquidityWarning);
    }

    public function testCalculateAltmanZScoreNonManufacturingZones(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, $this->creditRatingAgency, $this->marketEventPublisherMock);

        // Safe Firm (High working capital, high retained earnings, positive EBIT, high market cap)
        $stockSafe = new Stock();
        $stockSafe->setTicker('SAFE');
        $stockSafe->setIndustry('Technology');
        $stockSafe->setTotalEquity('100000000');
        $stockSafe->setWholesaleDebt('10000000');
        $stockSafe->setCorporateTreasury('50000000');
        $stockSafe->setRetainedEarnings('60000000');
        $stockSafe->setSharesOutstanding('1000000');

        $resultSafe = $engine->calculateAltmanZScore($stockSafe, 20000000.0, 80000000.0, 150.0);
        $this->assertSame('Safe', $resultSafe['zone']);
        $this->assertFalse($resultSafe['is_bankrupt']);
        $this->assertGreaterThan(2.60, $resultSafe['z_score']);

        // Bankrupt Firm (Negative equity, negative retained earnings, severe operating losses)
        $stockBankrupt = new Stock();
        $stockBankrupt->setTicker('DEAD');
        $stockBankrupt->setIndustry('Technology');
        $stockBankrupt->setTotalEquity('-20000000');
        $stockBankrupt->setWholesaleDebt('50000000');
        $stockBankrupt->setCorporateTreasury('100000');
        $stockBankrupt->setRetainedEarnings('-80000000');
        $stockBankrupt->setSharesOutstanding('1000000');

        $resultBankrupt = $engine->calculateAltmanZScore($stockBankrupt, -15000000.0, 10000000.0, 0.05);
        $this->assertSame('Distress', $resultBankrupt['zone']);
        $this->assertTrue($resultBankrupt['is_bankrupt']);
        $this->assertLessThan(0.00, $resultBankrupt['z_score']);
    }

    public function testCalculateAltmanZScoreBankingAlternativeModel(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, $this->creditRatingAgency, $this->marketEventPublisherMock);

        // Bank with healthy capital ratio (Equity / Assets = 100M / 1000M = 10% -> score 10.0 > 8.0 Safe threshold)
        $bankSafe = new Stock();
        $bankSafe->setTicker('BNK_SAFE');
        $bankSafe->setIndustry('Banks - Diversified');
        $bankSafe->setTotalEquity('100000000');
        $bankSafe->setCustomerDeposits('900000000');
        $bankSafe->setWholesaleDebt('0');
        $bankSafe->setCorporateTreasury('100000000');
        $bankSafe->setSharesOutstanding('10000000');

        $resultBankSafe = $engine->calculateAltmanZScore($bankSafe, 30000000.0, 100000000.0, 20.0);
        $this->assertSame('Safe', $resultBankSafe['zone']);
        $this->assertFalse($resultBankSafe['is_bankrupt']);
        $this->assertEqualsWithDelta(10.0, $resultBankSafe['z_score'], 0.1);

        // Insolvent Bank below statutory minimum (Equity = $10M / Assets = $1000M = 1% -> score 1.0 < 4.0 Bankrupt threshold)
        $bankInsolvent = new Stock();
        $bankInsolvent->setTicker('BNK_DEAD');
        $bankInsolvent->setIndustry('Banks - Diversified');
        $bankInsolvent->setTotalEquity('10000000');
        $bankInsolvent->setCustomerDeposits('990000000');
        $bankInsolvent->setWholesaleDebt('0');
        $bankInsolvent->setCorporateTreasury('10000000');
        $bankInsolvent->setSharesOutstanding('10000000');

        $resultBankInsolvent = $engine->calculateAltmanZScore($bankInsolvent, -20000000.0, 50000000.0, 1.0);
        $this->assertSame('Distress', $resultBankInsolvent['zone']);
        $this->assertTrue($resultBankInsolvent['is_bankrupt']);
    }

    public function testCalculateAltmanZScoreInsuranceAlternativeModel(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, $this->creditRatingAgency, $this->marketEventPublisherMock);

        // Insurer with depleted surplus after catastrophe ($373M equity / $70.373B assets = 0.53% capital ratio)
        // Sitting on massive float reserves and liquid cash: in Distress, but NOT bankrupt
        $reinsurerDistressed = new Stock();
        $reinsurerDistressed->setTicker('SAFE');
        $reinsurerDistressed->setIndustry('Insurance - Reinsurance');
        $reinsurerDistressed->setTotalEquity('373000000');
        $reinsurerDistressed->setCustomerDeposits('59000000000');
        $reinsurerDistressed->setWholesaleDebt('11000000000');
        $reinsurerDistressed->setCorporateTreasury('46000000000');
        $reinsurerDistressed->setSharesOutstanding('1000000000');

        $resultDistressed = $engine->calculateAltmanZScore($reinsurerDistressed, -26000000000.0, 63000000000.0, 10.0);
        $this->assertSame('Distress', $resultDistressed['zone']);
        $this->assertFalse($resultDistressed['is_bankrupt'], 'Insurer with positive equity and cash must not be marked bankrupt.');
        $this->assertGreaterThan(0.0, $resultDistressed['z_score']);
        $this->assertLessThan(2.0, $resultDistressed['z_score']);

        // Insurer with negative equity (Balance Sheet Insolvency)
        $reinsurerInsolvent = new Stock();
        $reinsurerInsolvent->setTicker('SAFE_DEAD');
        $reinsurerInsolvent->setIndustry('Insurance - Reinsurance');
        $reinsurerInsolvent->setTotalEquity('-10000000');
        $reinsurerInsolvent->setCustomerDeposits('1000000000');
        $reinsurerInsolvent->setWholesaleDebt('100000000');
        $reinsurerInsolvent->setCorporateTreasury('5000000');
        $reinsurerInsolvent->setSharesOutstanding('10000000');

        $resultInsolvent = $engine->calculateAltmanZScore($reinsurerInsolvent, -50000000.0, 100000000.0, 0.01);
        $this->assertSame('Distress', $resultInsolvent['zone']);
        $this->assertTrue($resultInsolvent['is_bankrupt'], 'Insurer with negative equity is insolvent and bankrupt.');
    }

    public function testIssueDebtUpdatesWholesaleBalanceAndWeightedHistoricalRate(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, $this->creditRatingAgency, $this->marketEventPublisherMock);

        $stock = new Stock();
        $stock->setWholesaleDebt('100000000.00'); // $100M existing at 4%
        $stock->setHistoricalFixedRate('0.04');

        // Issue $100M at 6% -> New total $200M at weighted (100*0.04 + 100*0.06)/200 = 5.0%
        $engine->issueDebt($stock, 100000000.00, 0.06);

        $this->assertEquals('200000000', $stock->getWholesaleDebt());
        $this->assertEqualsWithDelta(0.05, (float) $stock->getHistoricalFixedRate(), 0.0001);

        // Zero issuance should not mutate anything
        $engine->issueDebt($stock, 0.0, 0.10);
        $this->assertEquals('200000000', $stock->getWholesaleDebt());
        $this->assertEqualsWithDelta(0.05, (float) $stock->getHistoricalFixedRate(), 0.0001);
    }

    public function testCalculateInterestExpenseAcceleratesRefinancingWhenRatesDrop(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, null, null);

        $stock = new Stock();
        $stock->setTicker('REFIN');
        $stock->setIndustry('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('100000000');
        $stock->setWholesaleDebt('50000000');
        $stock->setHistoricalFixedRate('0.08'); // High legacy rate (8%)
        $stock->setCreditSpread('0.01');
        $stock->setTotalRevenue('50000000');
        $stock->setOperatingMargin('0.20');
        $stock->setVolatility('0.15');

        // Macro: 5y Yield = 2.0%, Credit Spread ~ 1.0% -> Market Fixed Rate ~ 3.0% (8% - 3% = 500bps drop > 150bps hurdle)
        $macro = new MacroStateDTO(
            policyRateEma: 0.02,
            corporateTaxRate: 0.20,
            yield5yEma: 0.02,
            macroCreditSpreadEma: 0.015
        );

        // With advanceMaturity = true, turnover should accelerate to 15% (0.15)
        $metrics = $engine->calculateInterestExpense($stock, $macro, true);

        // Expected blended fixed rate: (0.08 * 0.85) + (marketRate * 0.15)
        $marketRate = $metrics->currentMarketRate;
        $expectedBlended = (0.08 * 0.85) + ($marketRate * 0.15);
        $this->assertEqualsWithDelta($expectedBlended, $metrics->historicalFixedRate, 0.001);
    }

    public function testBernankeGertlerGilchristFinancialAccelerator(): void
    {
        $realMath = new MathUtility();
        $realMetrics = new CorporateMetrics();
        $engine = new DebtEngine($realMath, $realMetrics, null, null);

        $stock = new Stock();
        $stock->setTicker('LEVER');
        $stock->setIndustry('Industrials');
        $stock->setPrice('50.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('50000000');
        $stock->setWholesaleDebt('100000000'); // 2.0x Debt/Equity (leveraged)
        $stock->setCreditSpread('0.02');
        $stock->setTotalRevenue('100000000');
        $stock->setOperatingMargin('0.15');
        $stock->setVolatility('0.20');

        // 1. Normal economy (output gap = 0)
        $neutralMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield5yEma: 0.03,
            macroCreditSpreadEma: 0.015
        );
        $neutralResult = $engine->calculateInterestExpense($stock, $neutralMacro, false);

        // 2. Severe Recession (output gap = -4%)
        $recessionMacro = new MacroStateDTO(
            outputGapEma: -0.04,
            yield5yEma: 0.03,
            macroCreditSpreadEma: 0.015
        );
        $recessionResult = $engine->calculateInterestExpense($stock, $recessionMacro, false);

        // BGG External Finance Premium expands during recession for leveraged balance sheets
        $this->assertGreaterThan(
            $neutralResult->dynamicSpread,
            $recessionResult->dynamicSpread,
            'Credit spreads on leveraged corporate debt must widen non-linearly during economic contractions via the BGG Financial Accelerator.'
        );
    }
}

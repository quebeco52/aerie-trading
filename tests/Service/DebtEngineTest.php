<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
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

    public function testMaturityWallRollsAtBusinessModelRolloverRate(): void
    {
        // Historical coupon 2%, market now 6%: how far the blended rate moves in one quarter is the
        // business model's rollover rate. A regulated utility (12-year bonds) must reprice far slower
        // than a standard corporate (5-year bonds), which is the only legitimate bond-proxy channel.
        $macroState = new MacroStateDTO(
            policyRateEma: 0.05,
            corporateTaxRate: 0.21,
            yield5yEma: 0.06
        );

        $this->mathUtilityMock->method('calculateDistanceToDefault')->willReturn(6.0);
        $this->mathUtilityMock->method('calculateMertonCreditSpread')->willReturn(0.0);

        $buildStock = function (string $industry): Stock {
            $stock = new Stock();
            $stock->setTicker('ROLL');
            $stock->setIndustry($industry);
            $stock->setTotalEquity('1000000000');
            $stock->setWholesaleDebt('500000000');
            $stock->setTotalRevenue('800000000');
            $stock->setOperatingMargin('0.20');
            $stock->setHistoricalFixedRate('0.02');
            $stock->setFloatingDebtRatio('0.0');
            $stock->setCreditSpread('0.0');
            $stock->setVolatility('0.15');
            $stock->setSharesOutstanding('10000000');
            $stock->setPrice('100.00');
            return $stock;
        };

        $utility = $buildStock('Utilities - Regulated Electric');
        $corporate = $buildStock('General');

        $utilityMetrics = $this->engine->calculateInterestExpense($utility, $macroState, true);
        $corporateMetrics = $this->engine->calculateInterestExpense($corporate, $macroState, true);

        $marketRate = $utilityMetrics->currentMarketRate;
        $utilityRollover = \App\Data\Sectors::getBusinessModelStrategy('utility')->getDebtMaturityRolloverRate();
        $corporateRollover = \App\Data\Sectors::getBusinessModelStrategy('none')->getDebtMaturityRolloverRate();

        $this->assertLessThan($corporateRollover, $utilityRollover);
        $this->assertEqualsWithDelta((0.02 * (1.0 - $utilityRollover)) + ($marketRate * $utilityRollover), $utilityMetrics->historicalFixedRate, 1e-9);
        $this->assertEqualsWithDelta((0.02 * (1.0 - $corporateRollover)) + ($marketRate * $corporateRollover), $corporateMetrics->historicalFixedRate, 1e-9);
        $this->assertLessThan($corporateMetrics->historicalFixedRate, $utilityMetrics->historicalFixedRate);
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

    /**
     * With the primary market open, a maturity is refinanced: the principal survives and only its coupon
     * reprices. This is the ordinary case and must stay the ordinary case, or investment-grade issuers
     * would be forced to liquidate assets every quarter.
     */
    public function testOpenPrimaryMarketRefinancesMaturingPrincipal(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = $this->buildMaturityIssuer('BBB', 0.02);
        $health = $this->buildHealthForMaturity(interestCoverage: 6.0, dynamicSpread: 0.02);

        $roll = $engine->rollMaturities($stock, $health, 50_000_000_000.0);

        $this->assertTrue($roll->refinanced);
        $this->assertGreaterThan(0.0, $roll->maturingPrincipal);
        $this->assertEquals(0.0, $roll->principalRepaid);
        $this->assertEquals(0.0, $roll->unfundedShortfall);
        $this->assertEqualsWithDelta(10_000_000_000.0, (float) $stock->getWholesaleDebt(), 1.0, 'refinanced principal must not be repaid');
    }

    /**
     * When spreads blow out to crisis levels the primary market shuts and the principal must be repaid in
     * cash. Before this existed no firm could ever be refused a refinancing, so the most common real-world
     * failure — a maturity landing in a quarter when nobody will lend — could not happen at all.
     */
    public function testClosedPrimaryMarketForcesCashRepaymentOfPrincipal(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = $this->buildMaturityIssuer('B', 0.02);
        $health = $this->buildHealthForMaturity(interestCoverage: 6.0, dynamicSpread: 0.18);

        $roll = $engine->rollMaturities($stock, $health, 50_000_000_000.0);

        $this->assertFalse($roll->refinanced);
        $this->assertGreaterThan(0.0, $roll->principalRepaid);
        $this->assertEquals(0.0, $roll->unfundedShortfall, 'a cash-rich firm repays in full');
        $this->assertEqualsWithDelta(
            10_000_000_000.0 - $roll->maturingPrincipal,
            (float) $stock->getWholesaleDebt(),
            1.0,
            'repaid principal must leave the balance sheet'
        );
    }

    /**
     * A firm that can neither refinance nor pay carries the gap forward as an unfunded shortfall, which is
     * what the treasury then has to cover with emergency financing or default on.
     */
    public function testUnfundableMaturityIsReportedAsAShortfall(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = $this->buildMaturityIssuer('CCC', 0.02);
        $health = $this->buildHealthForMaturity(interestCoverage: 0.4, dynamicSpread: 0.02);

        $roll = $engine->rollMaturities($stock, $health, 100_000_000.0);

        $this->assertFalse($roll->refinanced, 'a firm that cannot cover its interest cannot roll its principal');
        $this->assertEqualsWithDelta(100_000_000.0, $roll->principalRepaid, 1.0);
        $this->assertGreaterThan(0.0, $roll->unfundedShortfall);
        $this->assertEqualsWithDelta(
            $roll->maturingPrincipal - $roll->principalRepaid,
            $roll->unfundedShortfall,
            1.0
        );
    }

    /**
     * Market access is looser than the test for taking on NEW leverage: an investment-grade issuer with
     * ample coverage rolls its debt straight through a recession, which is what actually happens.
     */
    public function testInvestmentGradeIssuerRefinancesThroughAWidenedButFunctioningMarket(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = $this->buildMaturityIssuer('A', 0.02);
        $health = $this->buildHealthForMaturity(interestCoverage: 3.0, dynamicSpread: 0.06);

        $this->assertTrue($engine->rollMaturities($stock, $health, 0.0)->refinanced);
    }

    /**
     * A debt-free firm has no maturity ladder at all.
     */
    public function testDebtFreeFirmHasNothingToRoll(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = $this->buildMaturityIssuer('AAA', 0.01);
        $stock->setWholesaleDebt('0.00');

        $roll = $engine->rollMaturities($stock, $this->buildHealthForMaturity(6.0, 0.01), 0.0);

        $this->assertEquals(0.0, $roll->maturingPrincipal);
        $this->assertTrue($roll->refinanced);
    }

    private function buildMaturityIssuer(string $rating, float $creditSpread): Stock
    {
        $stock = new Stock();
        $stock->setTicker('MATR');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setWholesaleDebt('10000000000.00');
        $stock->setTotalEquity('20000000000.00');
        $stock->setCorporateTreasury('5000000000.00');
        $stock->setCreditRating($rating);
        $stock->setCreditSpread((string) $creditSpread);

        return $stock;
    }

    private function buildHealthForMaturity(float $interestCoverage, float $dynamicSpread): DebtHealthDTO
    {
        $metrics = new DebtMetricsDTO(
            interestExpense: 500_000_000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: $dynamicSpread,
            currentMarketRate: 0.05 + $dynamicSpread,
            wholesaleRate: 0.05,
            ebit: 3_000_000_000.0,
            revenue: 20_000_000_000.0,
            depreciation: 500_000_000.0,
            ebitda: 3_500_000_000.0
        );

        return new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.03,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: $interestCoverage,
            wantsToPaydownDebt: false,
            canIssueDebt: $interestCoverage > 3.5,
            debtTolerance: 2.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $metrics,
            isLiquidityCrisis: $interestCoverage < 0.0,
            isLiquidityWarning: $interestCoverage < 2.0,
            isUnderLeveraged: false
        );
    }

    /**
     * Once a firm carries a real trade ledger, Altman's working capital term reads it: payables are a
     * current claim that the old cash-minus-a-fifth-of-debt proxy could not see at all.
     */
    public function testAltmanWorkingCapitalReadsTheRealTradeLedgerWhenOneExists(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $build = function (string $payables): Stock {
            $stock = new Stock();
            $stock->setTicker('ALTM');
            $stock->setIndustry('Auto Manufacturers');
            $stock->setTotalEquity('20000000000.00');
            $stock->setWholesaleDebt('10000000000.00');
            $stock->setCorporateTreasury('3000000000.00');
            $stock->setRetainedEarnings('5000000000.00');
            $stock->setSharesOutstanding('1000000000');
            $stock->setTotalRevenue('40000000000.00');
            $stock->setGrossPpe('30000000000.00');
            $stock->setAccumulatedDepreciation('12000000000.00');
            $stock->setReceivables('5000000000.00');
            $stock->setInventory('4000000000.00');
            $stock->setPayables($payables);

            return $stock;
        };

        $lean = $engine->calculateAltmanZScore($build('1000000000.00'), 3_000_000_000.0, 40_000_000_000.0, 30.0);
        $stretched = $engine->calculateAltmanZScore($build('6000000000.00'), 3_000_000_000.0, 40_000_000_000.0, 30.0);

        $this->assertLessThan($lean['z_score'], $stretched['z_score'], 'more payables must mean less working capital and a lower score');
    }

    /**
     * Before the ledger exists the score falls back to the proxies it always used, so a freshly seeded
     * or pre-migration firm is not scored on balances it does not yet carry.
     */
    public function testAltmanFallsBackToProxiesWithoutATradeLedger(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = new Stock();
        $stock->setTicker('NOLG');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setTotalEquity('20000000000.00');
        $stock->setWholesaleDebt('10000000000.00');
        $stock->setCorporateTreasury('3000000000.00');
        $stock->setRetainedEarnings('5000000000.00');
        $stock->setSharesOutstanding('1000000000');
        $stock->setTotalRevenue('40000000000.00');

        $this->assertFalse($stock->hasWorkingCapitalLedger());
        $result = $engine->calculateAltmanZScore($stock, 3_000_000_000.0, 40_000_000_000.0, 30.0);
        $this->assertIsFloat($result['z_score']);
        $this->assertContains($result['zone'], ['Safe', 'Grey', 'Distress']);
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
    public function testCapitalizedLeasesLowerAltmanZForLeaseHeavySectors(): void
    {
        // Identical books: the restaurant franchisor carries a store lease book worth 60% of revenue that
        // IFRS 16 / ASC 842 puts on the balance sheet, so it must screen as more levered than a plain
        // corporate with the same reported debt.
        $build = function (string $industry): Stock {
            $stock = new Stock();
            $stock->setTicker('LEASE');
            $stock->setIndustry($industry);
            $stock->setTotalEquity('1000000000');
            $stock->setWholesaleDebt('300000000');
            $stock->setCorporateTreasury('150000000');
            $stock->setRetainedEarnings('400000000');
            $stock->setSharesOutstanding('50000000');
            return $stock;
        };

        // Real metrics: the lease capitalization must actually run, not the test class's stub.
        $engine = new DebtEngine($this->mathUtilityMock, new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);
        $restaurant = $engine->calculateAltmanZScore($build('Restaurants'), 120_000_000.0, 2_000_000_000.0, 40.0);
        $plain = $engine->calculateAltmanZScore($build('General'), 120_000_000.0, 2_000_000_000.0, 40.0);

        $this->assertGreaterThan(
            \App\Data\Sectors::getBusinessModelStrategy('none')->getLeaseIntensity(),
            \App\Data\Sectors::getBusinessModelStrategy('restaurant')->getLeaseIntensity()
        );
        $this->assertLessThan($plain['z_score'], $restaurant['z_score']);
    }

    /** Cost-of-capital tests need the real CAPM and Hamada arithmetic, not the class stub. */
    private function costOfCapitalEngine(): DebtEngine
    {
        return new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency);
    }

    private function leveredFirm(string $ticker, string $beta, string $creditSpread): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('General');
        $stock->setBeta($beta);
        $stock->setCreditSpread($creditSpread);
        $stock->setHistoricalFixedRate('0.04');
        $stock->setFloatingDebtRatio('0.30');
        $stock->setVolatility('0.20');
        $stock->setTotalEquity('1000000000');
        $stock->setWholesaleDebt('500000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setSharesOutstanding('100000000');
        $stock->setPrice('10.00');
        $stock->setTotalRevenue('800000000');
        $stock->setOperatingMargin('0.15');

        return $stock;
    }

    private function neutralMacro(): MacroStateDTO
    {
        return new MacroStateDTO(
            inflationEma: 0.02,
            policyRateEma: 0.04,
            yield5yEma: 0.045,
            macroCreditSpreadEma: 0.02,
            corporateTaxRate: 0.21,
            equityRiskPremium: 0.045
        );
    }

    /**
     * Hamada multiplies, so leverage scales the magnitude of systematic exposure and can never flip its
     * sign. A levered hedge is a bigger hedge. The engine previously levered max(0.5, |beta|), which handed
     * every inverse-beta name a positive exposure and made it look like a leveraged market bet.
     */
    public function testLeverageAmplifiesAnInverseBetaWithoutFlippingIt(): void
    {
        $health = $this->costOfCapitalEngine()->analyzeDebtHealth($this->leveredFirm('HEDG', '-0.60', '0.02'), $this->neutralMacro());

        $this->assertLessThan(0.0, $health->leveredBeta, 'A hedge must stay a hedge after re-levering.');
        $this->assertGreaterThan(0.60, abs($health->leveredBeta), 'Leverage must amplify the magnitude of the exposure.');
    }

    /**
     * Absolute priority: equity is the junior claim on the same cash flows, so it cannot require a lower
     * return than the debt ranking above it. CAPM alone hands a negative-beta name a rate below the
     * risk-free rate, which is sound portfolio theory and an unusable hurdle rate.
     */
    public function testCostOfEquityIsNeverBelowTheFirmsOwnBorrowingRate(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();

        foreach ([['HEDG', '-0.60'], ['FLAT', '0.05'], ['MID', '0.80'], ['HIGH', '2.00']] as [$ticker, $beta]) {
            $stock = $this->leveredFirm($ticker, $beta, '0.02');
            $health = $engine->analyzeDebtHealth($stock, $macro);
            $marketRate = $engine->calculateInterestExpense($stock, $macro)->currentMarketRate;

            $this->assertGreaterThanOrEqual(
                $marketRate,
                $health->costOfEquity,
                sprintf('%s prices its equity below its own debt.', $ticker)
            );
            $this->assertGreaterThan(0.0, $health->costOfEquity);
        }
    }

    /**
     * The floor carries the firm's OWN credit risk, so two hedges are not priced alike: a clearinghouse on a
     * 40bp spread funds far more cheaply than a distressed-debt shop on 320bp, even though CAPM would put
     * both below the risk-free rate. The old flat beta floor gave them an identical cost of equity.
     */
    public function testTheEquityFloorCarriesTheFirmsOwnCreditRisk(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();

        $fortress = $engine->analyzeDebtHealth($this->leveredFirm('SAFE', '-0.10', '0.004'), $macro);
        $distressed = $engine->analyzeDebtHealth($this->leveredFirm('JUNK', '-0.10', '0.032'), $macro);

        $this->assertGreaterThan(
            $fortress->costOfEquity,
            $distressed->costOfEquity,
            'Two hedges with the same beta must still separate by their own credit risk.'
        );
    }

    /**
     * Below the old 0.5 floor every firm was discounted identically, which is why a water utility and an
     * industrial REIT carried the same multiple. Betas below it must now produce distinct costs of equity.
     */
    public function testLowBetaFirmsNoLongerShareOneCostOfEquity(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();

        $veryDefensive = $engine->analyzeDebtHealth($this->leveredFirm('UTIL', '0.10', '0.006'), $macro);
        $defensive = $engine->analyzeDebtHealth($this->leveredFirm('REIT', '0.45', '0.006'), $macro);

        $this->assertGreaterThan(
            $veryDefensive->costOfEquity,
            $defensive->costOfEquity,
            'A 0.45 beta must cost more equity capital than a 0.10 beta.'
        );
    }

    /**
     * Builds a firm whose only interesting property is its leverage, so the covenant is the variable under test.
     */
    private function leverageFixture(string $industry, string $debt, string $treasury): Stock
    {
        $stock = new Stock();
        $stock->setTicker('LEV');
        $stock->setIndustry($industry);
        $stock->setPrice('50.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('80000000');
        $stock->setWholesaleDebt($debt);
        $stock->setCorporateTreasury($treasury);
        $stock->setTotalRevenue('100000000');
        $stock->setOperatingMargin('0.30');
        $stock->setBeta('0.5');
        // Cheap legacy debt on purpose: it is what lets interest coverage stay comfortable while the
        // firm's leverage against actual cash generation runs away.
        $stock->setHistoricalFixedRate('0.02');
        $stock->setCreditSpread('0.01');

        return $stock;
    }

    private function leverageMacro(): MacroStateDTO
    {
        return new MacroStateDTO(
            policyRateEma: 0.03,
            corporateTaxRate: 0.20,
            yield5yEma: 0.03,
            equityRiskPremium: 0.05
        );
    }

    /**
     * The whole reason the covenant has to exist: interest coverage is rate-sensitive and this is not, so
     * cheap debt keeps the ICR gate open while cash-flow leverage runs past the sector limit.
     */
    public function testLeverageCovenantBindsWhereInterestCoverageDoesNot(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);

        $health = $engine->analyzeDebtHealth($this->leverageFixture('Software - Application', '200000000', '10000000'), $this->leverageMacro());

        $this->assertTrue($health->canIssueDebt, 'Interest coverage must still be satisfied, or this test proves nothing about the covenant.');
        $this->assertGreaterThan($health->ebitdaCovenantLimit, $health->netDebtToEbitda, 'Fixture must actually breach the sector covenant.');
        $this->assertFalse($health->hasLeverageHeadroom, 'A firm past its Net Debt / EBITDA covenant has no discretionary leverage headroom.');
    }

    public function testCompliantFirmKeepsItsLeverageHeadroom(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);

        $health = $engine->analyzeDebtHealth($this->leverageFixture('Utilities - Regulated Electric', '120000000', '10000000'), $this->leverageMacro());

        $this->assertLessThan($health->ebitdaCovenantLimit, $health->netDebtToEbitda);
        $this->assertTrue($health->hasLeverageHeadroom);
    }

    /**
     * Financials carry a 999.0 sentinel because their binding constraint is regulatory capital. A bank funds
     * itself at a leverage no industrial covenant would tolerate, and that is the business, not a breach.
     */
    public function testFinancialsAreExemptFromTheCashFlowLeverageCovenant(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);

        $health = $engine->analyzeDebtHealth($this->leverageFixture('Banks - Regional', '300000000', '10000000'), $this->leverageMacro());

        $this->assertGreaterThan(6.0, $health->netDebtToEbitda, 'The bank should look extremely levered on this ratio.');
        $this->assertTrue($health->hasLeverageHeadroom, 'The sentinel limit must exempt the sector outright.');
    }

    /**
     * The covenant is struck on NET debt, so a cash pile is a genuine defence rather than window dressing.
     */
    public function testCovenantIsStruckOnNetDebtSoTreasuryBuysHeadroom(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);
        $macro = $this->leverageMacro();

        $geared = $engine->analyzeDebtHealth($this->leverageFixture('Software - Application', '200000000', '10000000'), $macro);
        $funded = $engine->analyzeDebtHealth($this->leverageFixture('Software - Application', '200000000', '170000000'), $macro);

        $this->assertLessThan($geared->netDebtToEbitda, $funded->netDebtToEbitda, 'Holding cash against the same gross debt must lower measured leverage.');
        $this->assertTrue($funded->hasLeverageHeadroom, 'Once net debt is inside the limit the covenant is satisfied again.');
    }
}

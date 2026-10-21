<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\ManagementProfile;
use App\Data\Company\ManagementStyle;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Bond\CreditRatingAgency;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Math\CreditRisk;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
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
            $this->marketEventPublisherMock,
        );
    }

    public function testDebtTurnoverTriggersCreditRatingDowngradeEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEBT');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('100000000');
        $stock->setWholesaleDebt('50000000');
        $stock->setVolatility('0.50');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.21,
            yield5yEma: 0.04,
        );

        // Distance to default about 1.2 sits in the B bracket; one notch at a time takes BBB to BB.
        $distance = $this->engine->resolveDistanceToDefault($stock, $macroState);
        $this->assertGreaterThan(CreditRatingAgency::THRESHOLD_B, $distance);
        $this->assertLessThan(CreditRatingAgency::THRESHOLD_BB, $distance);
        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_DOWNGRADE'),
                $this->stringContains('downgraded from BBB to BB'),
                $this->equalTo(-3.0),
            );

        $this->engine->reviewRatingAndPriceDebt($stock, $macroState);

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
            yield5yEma: 0.04,
        );

        // Distance to default clears the AAA line with room to spare; one notch at a time takes BBB to A.
        $this->assertGreaterThan(
            CreditRatingAgency::THRESHOLD_AAA + CreditRatingAgency::HYSTERESIS_BUFFER,
            $this->engine->resolveDistanceToDefault($stock, $macroState),
        );
        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_UPGRADE'),
                $this->stringContains('upgraded from BBB to A'),
                $this->equalTo(2.0),
            );

        $this->engine->reviewRatingAndPriceDebt($stock, $macroState);

        $this->assertSame('A', $stock->getCreditRating());
    }

    /**
     * The rating reads leverage, not just the equity market: an operating firm at 3-4x net debt to EBITDA anchors at
     * BB however far its distance to default runs. A regulated utility is anchored on the excellent row instead.
     */
    public function testLeverageCapsTheRatingAndAUtilityIsAnchoredOnTheExcellentRow(): void
    {
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.04);
        $rate = function (?string $industry, string $from) use ($macroState): array {
            $stock = new Stock();
            $stock->setTicker('LEV');
            if ($industry !== null) {
                $stock->setIndustry($industry);
            }
            $stock->setCreditRating($from);
            $stock->setTotalEquity('200000000');
            $stock->setWholesaleDebt('80000000');
            // Retained earnings put the Altman Z'' in the safe zone, so its grey-zone cap does not decide the test.
            $stock->setRetainedEarnings('100000000');
            $stock->setTotalRevenue('100000000');
            $stock->setOperatingMargin('0.10');
            $stock->setVolatility('0.15');
            $stock->setSharesOutstanding('1000000');
            $stock->setPrice('200.00');
            $this->assertGreaterThan(CreditRatingAgency::THRESHOLD_AAA, $this->engine->resolveDistanceToDefault($stock, $macroState));

            $metrics = $this->engine->reviewRatingAndPriceDebt($stock, $macroState);
            $this->assertSame('Safe', $this->engine->calculateAltmanZScore($stock, $metrics->ebit, 100_000_000.0)['zone']);

            return [$stock->getCreditRating(), $metrics->ebitda];
        };

        [$rating, $ebitda] = $rate(null, 'A');
        $this->assertGreaterThanOrEqual(3.0, 80_000_000.0 / $ebitda);
        $this->assertLessThan(4.0, 80_000_000.0 / $ebitda);
        $this->assertSame('BBB', $rating, 'one notch toward the BB anchor');

        // The utility's own EBITDA, on the excellent row: from AAA it moves one notch toward that anchor.
        [$rating, $ebitda] = $rate('Utilities - Regulated Electric', 'AAA');
        $anchor = CreditRatingAgency::financialRiskAnchor(80_000_000.0, $ebitda, true);
        $this->assertNotSame($anchor, CreditRatingAgency::financialRiskAnchor(80_000_000.0, $ebitda, false));
        $this->assertSame('AA', $rating);
    }

    /**
     * An operating firm at 3-4x net debt to EBITDA with an Altman Z'' in the safe zone and a distance to default above
     * AAA.
     */
    private function leveredOperatingFirm(string $rating): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TTC');
        $stock->setCreditRating($rating);
        $stock->setTotalEquity('200000000');
        $stock->setWholesaleDebt('80000000');
        $stock->setRetainedEarnings('100000000');
        $stock->setTotalRevenue('100000000');
        $stock->setOperatingMargin('0.10');
        $stock->setVolatility('0.15');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('200.00');
        $stock->setQuarterlyOperatingHistory(array_fill(0, 4, ['revenue' => 25_000_000.0, 'ebit' => 2_500_000.0]));

        return $stock;
    }

    /**
     * Each rating review records the net debt and EBITDA it read on the quarter the report added, after the earlier
     * reviews', newest first and as many as the time-weighting reads; reviewing a quarter twice rewrites its own entry.
     */
    public function testTheRatingReviewRecordsItsLeverageOnTheOperatingHistory(): void
    {
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.04);
        $stock = $this->leveredOperatingFirm('BB');

        $metrics = $this->engine->reviewRatingAndPriceDebt($stock, $macroState);
        $rated = ($stock->getQuarterlyOperatingHistory() ?? [])[3][DebtEngine::RATED_LEVERAGE_KEY] ?? [];
        $this->assertCount(1, $rated);
        $this->assertSame($metrics->ebitda, $rated[0]['ebitda']);
        $this->assertGreaterThan(0.0, $rated[0]['net_debt']);

        $this->engine->reviewRatingAndPriceDebt($stock, $macroState);
        $this->assertCount(1, ($stock->getQuarterlyOperatingHistory() ?? [])[3][DebtEngine::RATED_LEVERAGE_KEY] ?? [], 'the same quarter reviewed again');

        for ($quarter = 1; $quarter <= 11; $quarter++) {
            // The next report, as EarningsEngine files it: one quarter in, the window trimmed to the trailing four.
            $history = $stock->getQuarterlyOperatingHistory() ?? [];
            $history[] = ['revenue' => 25_000_000.0, 'ebit' => 2_500_000.0];
            $stock->setQuarterlyOperatingHistory(array_values(array_slice($history, -4)));
            $stock->setWholesaleDebt((string) (80_000_000 + $quarter * 1_000_000));
            $this->engine->reviewRatingAndPriceDebt($stock, $macroState);

            $rated = ($stock->getQuarterlyOperatingHistory() ?? [])[3][DebtEngine::RATED_LEVERAGE_KEY] ?? [];
            $this->assertCount(min($quarter + 1, CreditRatingAgency::timeWeightedLeverageQuarters()), $rated, "quarter {$quarter}");
            $this->assertSame(max(array_column($rated, 'net_debt')), $rated[0]['net_debt'], 'newest first: the debt has grown every quarter');
        }
    }

    /**
     * The leverage anchor reads S&P's time-weighted years, not this quarter alone: a firm at 3-4x today (BB on its own)
     * whose two earlier years ran at 1x keeps a modest weighted ratio and its BBB.
     */
    public function testTheLeverageAnchorReadsTheTimeWeightedYears(): void
    {
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.04);

        $today = $this->leveredOperatingFirm('BBB');
        $metrics = $this->engine->reviewRatingAndPriceDebt($today, $macroState);
        $this->assertGreaterThanOrEqual(3.0, 80_000_000.0 / $metrics->ebitda);
        $this->assertLessThan(4.0, 80_000_000.0 / $metrics->ebitda);
        $this->assertSame('BB', $today->getCreditRating(), 'on this quarter alone the anchor is BB');

        $through = $this->leveredOperatingFirm('BBB');
        $history = $through->getQuarterlyOperatingHistory() ?? [];
        self::assertArrayHasKey(2, $history, 'the fixture files a third trailing quarter');
        $history[2][DebtEngine::RATED_LEVERAGE_KEY] = array_fill(0, 8, ['net_debt' => 80_000_000.0, 'ebitda' => 80_000_000.0]);
        $through->setQuarterlyOperatingHistory($history);
        $this->engine->reviewRatingAndPriceDebt($through, $macroState);
        $this->assertSame('BBB', $through->getCreditRating(), 'the two earlier years at 1x keep the weighted ratio modest');
    }

    /**
     * S&P's forecast years read the base case, the structural margin on trailing revenue: a firm whose trailing margin
     * has collapsed to 2% is anchored on the weighted basis, not on this year alone.
     */
    public function testTheForecastYearsReadTheStructuralMargin(): void
    {
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.04);
        $stock = $this->leveredOperatingFirm('BB');

        $metrics = $this->engine->reviewRatingAndPriceDebt($stock, $macroState, 100_000_000.0, 0.02);
        $baseCaseEbitda = $metrics->ebitda + 100_000_000.0 * (0.10 - 0.02);
        $weighted = (CreditRatingAgency::CORE_RATIO_FORECAST_WEIGHT * $baseCaseEbitda + 0.25 * $metrics->ebitda) / (CreditRatingAgency::CORE_RATIO_FORECAST_WEIGHT + 0.25);
        $pointInTime = CreditRatingAgency::financialRiskAnchor(80_000_000.0, $metrics->ebitda, false);
        $throughTheCycle = CreditRatingAgency::financialRiskAnchor(80_000_000.0, $weighted, false);

        $this->assertSame('B', $pointInTime, 'this year alone is highly leveraged');
        $this->assertSame('BB', $throughTheCycle);
        $this->assertSame('BB', $stock->getCreditRating(), 'held at the weighted anchor; this year alone would take it to B');
        $rated = ($stock->getQuarterlyOperatingHistory() ?? [])[3][DebtEngine::RATED_LEVERAGE_KEY] ?? [];
        $this->assertSame($metrics->ebitda, $rated[0]['ebitda'], 'the record keeps the year as it was, not the base case');
    }

    /**
     * A regulated utility is read on S&P's medial volatility table: 4.0-4.5x is significant there and keeps its A,
     * where the standard table's aggressive band would anchor it at BBB.
     */
    public function testAUtilityIsReadOnTheMedialVolatilityTable(): void
    {
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.04);
        $stock = new Stock();
        $stock->setTicker('UTIL');
        $stock->setIndustry('Utilities - Regulated Electric');
        $stock->setCreditRating('A');
        $stock->setTotalEquity('200000000');
        $stock->setWholesaleDebt('110000000');
        $stock->setRetainedEarnings('100000000');
        $stock->setTotalRevenue('100000000');
        $stock->setOperatingMargin('0.10');
        $stock->setVolatility('0.15');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('200.00');

        $metrics = $this->engine->reviewRatingAndPriceDebt($stock, $macroState);
        $leverage = 110_000_000.0 / $metrics->ebitda;
        $this->assertGreaterThanOrEqual(4.0, $leverage);
        $this->assertLessThan(4.5, $leverage);
        $this->assertSame('BBB', CreditRatingAgency::financialRiskAnchor(110_000_000.0, $metrics->ebitda, true), 'the standard table');
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
            yield5yEma: 0.04,
        );

        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_UPGRADE'),
                $this->stringContains('upgraded from BBB to A'),
                $this->equalTo(2.0),
            );

        $this->engine->reviewRatingAndPriceDebt($stock, $macroState);

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
            yield5yEma: 0.04,
        );

        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_DOWNGRADE'),
                $this->stringContains('downgraded from AAA to CCC'),
                $this->equalTo(-3.0),
            );

        $this->engine->reviewRatingAndPriceDebt($stock, $macroState);

        $this->assertSame('CCC', $stock->getCreditRating());
    }

    public function testMaturityWallRollsAtBusinessModelRolloverRate(): void
    {
        // Historical coupon 2%, market now 7%: how far a refinanced roll moves the coupon is the business model's
        // rollover rate. A regulated utility (12-year bonds) must reprice far slower than a standard corporate
        // (5-year bonds), which is the only legitimate bond-proxy channel. The quarter itself is charged at the 2%.
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $macroState = new MacroStateDTO(policyRateEma: 0.05, corporateTaxRate: 0.21, yield5yEma: 0.06);
        $health = $this->buildHealthForMaturity(interestCoverage: 6.0, dynamicSpread: 0.02);

        $coupons = [];
        foreach (['Utilities - Regulated Electric' => 'utility', 'General' => 'none'] as $industry => $model) {
            $stock = $this->buildMaturityIssuer('BBB', 0.0);
            $stock->setIndustry($industry);
            $stock->setHistoricalFixedRate('0.02');

            $this->assertEqualsWithDelta(0.02, $engine->reviewRatingAndPriceDebt($stock, $macroState)->historicalFixedRate, 1e-12, 'The quarter is charged at the coupon the book carries.');
            $this->assertTrue($engine->rollMaturities($stock, $health, 50_000_000_000.0)->refinanced);

            $rollover = \App\Data\Company\Sectors::getBusinessModelStrategy($model)->getDebtMaturityRolloverRate();
            $this->assertEqualsWithDelta((0.02 * (1.0 - $rollover)) + (0.07 * $rollover), (float) $stock->getHistoricalFixedRate(), 1e-9);
            $coupons[$model] = (float) $stock->getHistoricalFixedRate();
        }
        $this->assertLessThan($coupons['none'], $coupons['utility']);
    }

    /**
     * Falling rates buy a speculative-grade issuer an early refinancing (its bonds are callable at a fixed premium
     * after the non-call period) and buy an investment-grade one nothing: a make-whole call prices the bond at the
     * Treasury curve plus a small spread (Powers & Tsyplakov 2008), so its coupons roll down only as the notes mature.
     */
    public function testOnlySpeculativeGradeIssuersRefinanceEarlyWhenRatesFall(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        // The market prices both at 6% against a book coupon of 8%.
        $health = $this->buildHealthForMaturity(interestCoverage: 6.0, dynamicSpread: 0.01);
        $rollover = \App\Data\Company\Sectors::strategyFor('Auto Manufacturers')->getDebtMaturityRolloverRate();

        foreach ([['A', $rollover], ['BB', 0.15]] as [$rating, $turnover]) {
            $stock = $this->buildMaturityIssuer($rating, 0.0);
            $stock->setHistoricalFixedRate('0.08');

            $this->assertTrue($engine->rollMaturities($stock, $health, 50_000_000_000.0)->refinanced, "control: a {$rating} issuer rolls");
            $this->assertEqualsWithDelta((0.08 * (1.0 - $turnover)) + (0.06 * $turnover), (float) $stock->getHistoricalFixedRate(), 1e-9, "{$rating} refinances at its own pace");
        }
    }

    /**
     * Only what is refinanced reprices. A maturity the market refuses is repaid in cash or left unpaid, and the notes
     * still outstanding keep the coupon they were issued at; repricing them to the crisis yield charged a shut-out firm
     * a refinancing it never got, and the lower coverage kept it shut out.
     */
    public function testARefusedRollLeavesTheCouponUnchanged(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        // The quarter as the engines run it: the report charges interest, then the treasury meets the maturity.
        $crisis = new MacroStateDTO(policyRateEma: 0.05, corporateTaxRate: 0.21, yield5yEma: 0.10);

        $shut = $this->buildMaturityIssuer('B', 0.02);
        $shut->setHistoricalFixedRate('0.06');
        $shut->setHistoricalFixedRate((string) $engine->reviewRatingAndPriceDebt($shut, $crisis)->historicalFixedRate);
        $this->assertFalse($engine->rollMaturities($shut, $this->buildHealthForMaturity(interestCoverage: 6.0, dynamicSpread: 0.18), 50_000_000_000.0)->refinanced, 'control: the market is shut');
        $this->assertSame(0.06, (float) $shut->getHistoricalFixedRate());

        $uncovered = $this->buildMaturityIssuer('CCC', 0.02);
        $uncovered->setHistoricalFixedRate('0.06');
        $uncovered->setHistoricalFixedRate((string) $engine->reviewRatingAndPriceDebt($uncovered, $crisis)->historicalFixedRate);
        $this->assertFalse($engine->rollMaturities($uncovered, $this->buildHealthForMaturity(interestCoverage: 0.4, dynamicSpread: 0.02), 100_000_000.0)->refinanced, 'control: no lender rolls it');
        $this->assertSame(0.06, (float) $uncovered->getHistoricalFixedRate());
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
            equityRiskPremium: 0.05,
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
            yield5yEma: 0.02,
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

    /**
     * Z'' (Altman 1995) reads BOOK equity over total liabilities in X4; the 1968 market-value term overstated the
     * score by a median four points on the live board and left the grey and distress zones out of reach. The share
     * price is not an input any more, and a dollar of book equity moves the score by its 1.05 weight.
     */
    public function testAltmanZDoublePrimeReadsBookEquityOverTotalLiabilities(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);
        $build = static function (string $equity): Stock {
            $stock = new Stock();
            $stock->setTicker('ZBOOK');
            $stock->setIndustry('Technology');
            $stock->setTotalEquity(\App\Service\Math\Decimal::of($equity));
            $stock->setWholesaleDebt('50000000');
            $stock->setCorporateTreasury('0');
            $stock->setRetainedEarnings('0');
            $stock->setSharesOutstanding('1000000');
            $stock->setPrice('150.00');

            return $stock;
        };

        $base = $build('50000000');
        $score = $engine->calculateAltmanZScore($base, 0.0, 0.0)['z_score'];
        $base->setPrice('1.00');
        $this->assertEqualsWithDelta($score, $engine->calculateAltmanZScore($base, 0.0, 0.0)['z_score'], 1e-12, 'the share price is not in Z\'\'');

        // Assets 100 = liabilities 50 + equity 50; no working capital, retained earnings or EBIT, so Z'' = 1.05 x 50/50
        // less the proxy's current liabilities (20% of debt) over assets in X1.
        $this->assertEqualsWithDelta((1.05 * (50.0 / 50.0)) - (6.56 * (10.0 / 100.0)), $score, 1e-9);
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

        $resultSafe = $engine->calculateAltmanZScore($stockSafe, 20000000.0, 80000000.0);
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

        $resultBankrupt = $engine->calculateAltmanZScore($stockBankrupt, -15000000.0, 10000000.0);
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

        $resultBankSafe = $engine->calculateAltmanZScore($bankSafe, 30000000.0, 100000000.0);
        $this->assertSame('Safe', $resultBankSafe['zone']);
        $this->assertFalse($resultBankSafe['is_bankrupt']);
        $this->assertEqualsWithDelta(10.0, $resultBankSafe['z_score'], 0.1);

        // Insolvent Bank below statutory minimum (Equity = $10M / Assets = $1000M = 1% -> score 1.0 < 4.0 Bankrupt
        // threshold)
        $bankInsolvent = new Stock();
        $bankInsolvent->setTicker('BNK_DEAD');
        $bankInsolvent->setIndustry('Banks - Diversified');
        $bankInsolvent->setTotalEquity('10000000');
        $bankInsolvent->setCustomerDeposits('990000000');
        $bankInsolvent->setWholesaleDebt('0');
        $bankInsolvent->setCorporateTreasury('10000000');
        $bankInsolvent->setSharesOutstanding('10000000');

        $resultBankInsolvent = $engine->calculateAltmanZScore($bankInsolvent, -20000000.0, 50000000.0);
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

        $resultDistressed = $engine->calculateAltmanZScore($reinsurerDistressed, -26000000000.0, 63000000000.0);
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

        $resultInsolvent = $engine->calculateAltmanZScore($reinsurerInsolvent, -50000000.0, 100000000.0);
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
            'repaid principal must leave the balance sheet',
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
            1.0,
        );
    }

    /**
     * A bank is underwritten on its capital, not its interest coverage: a loss quarter that leaves a single-A savings
     * bank's coverage under one still rolls its covered bonds. An operating firm at the same rating and coverage does
     * not.
     */
    public function testACapitalTargetingBankRefinancesThroughALossQuarter(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $health = $this->buildHealthForMaturity(interestCoverage: 0.6, dynamicSpread: 0.01);

        $bank = $this->buildMaturityIssuer('A', 0.006);
        $bank->setTicker('PLVR');
        $bank->setIndustry('Banks - Regional');

        $this->assertNotNull(\App\Data\Company\Sectors::strategyFor('Banks - Regional')->getTargetCapitalRatio($bank), 'PLVR steers to its capital ratio');
        $this->assertTrue($engine->rollMaturities($bank, $health, 0.0)->refinanced);
        $this->assertFalse($engine->rollMaturities($this->buildMaturityIssuer('A', 0.006), $health, 0.0)->refinanced);
    }

    /**
     * A lender with no capital target is still a lender: a card book provisioning through a downturn takes its
     * coverage under one without missing a coupon, and the market rolls its notes on its capital.
     */
    public function testALenderWithoutACapitalTargetRefinancesThroughAProvisioningQuarter(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $health = $this->buildHealthForMaturity(interestCoverage: -0.8, dynamicSpread: 0.03);

        $cardLender = $this->buildMaturityIssuer('BBB', 0.02);
        $cardLender->setTicker('STRK');
        $cardLender->setIndustry('Credit Services');

        $this->assertNull(\App\Data\Company\Sectors::strategyFor('Credit Services')->getTargetCapitalRatio($cardLender), 'STRK runs no capital target');
        $this->assertTrue($engine->rollMaturities($cardLender, $health, 0.0)->refinanced);
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
    /**
     * A missed maturity stays due until paid. Shut out two quarters running, a firm that pays the second quarter's own
     * slice still owes the first quarter's: it has not cured, and the grace clock keeps running. A reopened market
     * rolls the arrears with the slice, and only that refinanced share reprices.
     */
    public function testAMissedMaturityStaysDueUntilPaid(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $stock = $this->buildMaturityIssuer('CCC', 0.02);
        $stock->setWholesaleDebt('2000000000.00');
        $stock->setHistoricalFixedRate('0.06');
        $rollover = \App\Data\Company\Sectors::strategyFor('Auto Manufacturers')->getDebtMaturityRolloverRate();
        $shut = $this->buildHealthForMaturity(interestCoverage: 0.4, dynamicSpread: 0.02);

        $first = $engine->rollMaturities($stock, $shut, 10_000_000.0);
        $this->assertEqualsWithDelta((2_000_000_000.0 * $rollover) - 10_000_000.0, $first->unfundedShortfall, 1.0);
        $this->assertEqualsWithDelta($first->unfundedShortfall, (float) $stock->getPrincipalArrears(), 1.0, 'the missed principal is carried');

        // Next quarter the firm can pay exactly its new slice, and no more.
        $debt = (float) $stock->getWholesaleDebt();
        $slice = ($debt - $first->unfundedShortfall) * $rollover;
        $second = $engine->rollMaturities($stock, $shut, $slice);
        $this->assertEqualsWithDelta($slice + $first->unfundedShortfall, $second->maturingPrincipal, 1.0, 'the arrears fall due ahead of the slice');
        $this->assertEqualsWithDelta($first->unfundedShortfall, $second->unfundedShortfall, 1.0, 'paying the new slice leaves the old one unpaid');

        $open = $this->buildHealthForMaturity(interestCoverage: 6.0, dynamicSpread: 0.02);
        $stock->setCreditRating('BB');
        $debt = (float) $stock->getWholesaleDebt();
        $third = $engine->rollMaturities($stock, $open, 0.0);
        $this->assertTrue($third->refinanced);
        $this->assertSame(0.0, (float) $stock->getPrincipalArrears(), 'a refinanced roll clears the arrears');
        $share = $third->maturingPrincipal / $debt;
        $this->assertGreaterThan($rollover, $share);
        $this->assertEqualsWithDelta((0.06 * (1.0 - $share)) + (0.07 * $share), (float) $stock->getHistoricalFixedRate(), 1e-9, 'the refinanced share, arrears included, reprices');
    }

    public function testDebtFreeFirmHasNothingToRoll(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = $this->buildMaturityIssuer('AAA', 0.01);
        $stock->setWholesaleDebt('0.00');

        $roll = $engine->rollMaturities($stock, $this->buildHealthForMaturity(6.0, 0.01), 0.0);

        $this->assertEquals(0.0, $roll->maturingPrincipal);
        $this->assertTrue($roll->refinanced);
    }

    /**
     * A drawn revolver costs money without becoming term debt.
     *
     * The balance is priced here rather than inside the business models because they do not agree on where
     * they read it from: the corporate and the base financial physics charge whatever $debt they are handed,
     * while the bank, credit-services, clearing and insurance models re-read the entity themselves. A leg
     * pushed down into them would be charged twice by the first group and not at all by the second.
     */
    public function testADrawnRevolverIsChargedInterestWithoutTouchingWholesaleDebt(): void
    {
        $macro = MacroStateDTO::fromArray(['policy_rate_ema' => 0.04, 'yield_5y_ema' => 0.045, 'macro_credit_spread_ema' => 0.02]);

        $dry = $this->buildMaturityIssuer('BBB', 0.02);
        $baseline = $this->engine->calculateInterestExpense($dry, $macro);

        $drawn = $this->buildMaturityIssuer('BBB', 0.02);
        $drawn->setRevolverCommitment('5000000000.00');
        $drawn->setRevolverDrawn('1000000000.00');
        $withLine = $this->engine->calculateInterestExpense($drawn, $macro);

        $this->assertSame(
            $dry->getWholesaleDebt(),
            $drawn->getWholesaleDebt(),
            'pricing the facility must not disturb the term ladder',
        );
        $this->assertGreaterThan(
            $baseline->interestExpense,
            $withLine->interestExpense,
            'a drawn facility is borrowed money and has to be paid for',
        );
        $this->assertEqualsWithDelta(
            1_000_000_000.0,
            (float) $drawn->getTotalDebt() - (float) $dry->getTotalDebt(),
            1.0,
            'and it is leverage: coverage, the Altman test and the Merton distance all have to see it',
        );
    }

    /**
     * Every business model inherits the revolver leg, whatever its own interest physics does with the debt
     * argument. Five of the nine sector strategies ignore that argument entirely, so a leg written inside
     * them would have been silently skipped for banks, lenders, clearing houses and insurers.
     */
    public function testRevolverInterestIsChargedForEveryBusinessModel(): void
    {
        $macro = MacroStateDTO::fromArray(['policy_rate_ema' => 0.04, 'yield_5y_ema' => 0.045, 'macro_credit_spread_ema' => 0.02]);

        $seen = [];
        foreach (\App\Data\Company\Sectors::INDUSTRY_METRICS as $industry => $metrics) {
            $model = $metrics['business_model'] ?? 'none';
            if (isset($seen[$model])) {
                continue;
            }
            $seen[$model] = true;

            $dry = $this->buildMaturityIssuer('BBB', 0.02);
            $dry->setIndustry($industry);
            $baseline = $this->engine->calculateInterestExpense($dry, $macro);

            $drawn = $this->buildMaturityIssuer('BBB', 0.02);
            $drawn->setIndustry($industry);
            $drawn->setRevolverCommitment('5000000000.00');
            $drawn->setRevolverDrawn('1000000000.00');
            $withLine = $this->engine->calculateInterestExpense($drawn, $macro);

            $this->assertGreaterThan(
                $baseline->interestExpense,
                $withLine->interestExpense,
                "{$model} does not charge its drawn revolver any interest",
            );
        }

        $this->assertGreaterThan(5, count($seen), 'the sweep must actually cover the sector models');
    }

    /**
     * A price crash on its own must not shut an issuer out of the primary bond market.
     *
     * The rating ladder reads a market-implied Merton distance to default, and it used to fall through to a
     * live 'D' — which hasPrimaryMarketAccess treats as no access at all. So a drawdown alone closed
     * refinancing on a firm that was current on everything it owed, forced the maturity to be repaid in cash,
     * and manufactured the payment default the tape had only guessed at.
     */
    public function testAPriceCrashAloneDoesNotCloseThePrimaryMarket(): void
    {
        $stock = $this->buildMaturityIssuer('BBB', 0.01);
        $stock->setPrice('0.05');
        $stock->setSharesOutstanding('1000000000');
        $stock->setVolatility('1.20');
        $stock->setCurrentVolatility('1.20');

        // The credit itself is sound: a healthy spread and comfortable coverage.
        $health = $this->buildHealthForMaturity(interestCoverage: 6.0, dynamicSpread: 0.03);

        $roll = $this->engine->rollMaturities($stock, $health, 5_000_000_000.0, 0.0);

        $this->assertTrue($roll->refinanced, 'a collapsed share price is not a missed payment');
        $this->assertSame(0.0, $roll->unfundedShortfall);
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
            ebitda: 3_500_000_000.0,
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
            isUnderLeveraged: false,
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

        $lean = $engine->calculateAltmanZScore($build('1000000000.00'), 3_000_000_000.0, 40_000_000_000.0);
        $stretched = $engine->calculateAltmanZScore($build('6000000000.00'), 3_000_000_000.0, 40_000_000_000.0);

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
        $result = $engine->calculateAltmanZScore($stock, 3_000_000_000.0, 40_000_000_000.0);
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

    /**
     * The common credit cycle reaches every issuer through the market's credit indices, in proportion to its class
     * (Ben Dor et al. 2007): doubling the IG and HY indices doubles a BBB's class spread and a BB's alike. A recession
     * the indices have not priced moves no one's spread, so leverage in a downturn is not charged a second time.
     */
    public function testTheCreditCycleScalesEveryClassInProportionAndTheGapAloneMovesNothing(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $stock = $this->leveredFirm('LEVER', '1.00', '0.0163');
        $macro = static fn(float $ig, float $gap): MacroStateDTO => new MacroStateDTO(
            outputGapEma: $gap,
            yield5yEma: 0.03,
            macroCreditSpreadEma: $ig,
            macroCreditSpreadTrend: 0.013,
            highYieldCreditSpreadEma: $ig * 3.3,
        );

        foreach (['BBB', 'BB'] as $rating) {
            $stock->setCreditRating($rating);
            $calm = $engine->calculateInterestExpense($stock, $macro(0.013, 0.0))->dynamicSpread;
            $crisis = $engine->calculateInterestExpense($stock, $macro(0.026, 0.0))->dynamicSpread;
            $this->assertEqualsWithDelta(2.0 * $calm, $crisis, 1e-12, "{$rating}: twice the index is twice the class spread");
            $this->assertEqualsWithDelta($calm, $engine->calculateInterestExpense($stock, $macro(0.013, -0.04))->dynamicSpread, 1e-12, "{$rating}: an unpriced recession adds nothing");
        }
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
        $restaurant = $engine->calculateAltmanZScore($build('Restaurants'), 120_000_000.0, 2_000_000_000.0);
        $plain = $engine->calculateAltmanZScore($build('General'), 120_000_000.0, 2_000_000_000.0);

        $this->assertGreaterThan(
            \App\Data\Company\Sectors::getBusinessModelStrategy('none')->getLeaseIntensity($build('General')),
            \App\Data\Company\Sectors::getBusinessModelStrategy('restaurant')->getLeaseIntensity($build('Restaurants')),
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
            equityRiskPremium: 0.045,
        );
    }

    /**
     * The cost of equity is struck on the long government yield, the riskless rate matched to the duration of the
     * cash flows it discounts and the rate the equity premium is measured over (Damodaran 2008). The policy rate is
     * the bill rate: striking the hurdle on it understated every hurdle by the term spread and re-rated the whole
     * board with each hiking cycle while the long yield barely moved. It is today's long yield, not its moving
     * average: a smoothed rate keeps moving for months after the yield has, which made every valuation forecastable.
     */
    public function testCostOfEquityIsStruckOnTheLongYieldNotThePolicyRate(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = static fn(float $policyRate, float $longYield, float $longYieldAverage = 0.048): MacroStateDTO => new MacroStateDTO(
            inflationEma: 0.02,
            policyRateEma: $policyRate,
            yield5yEma: 0.045,
            yield10y: $longYield,
            yield10yEma: $longYieldAverage,
            macroCreditSpreadEma: 0.02,
            corporateTaxRate: 0.21,
            equityRiskPremium: 0.045,
        );
        $costOfEquity = fn(float $policyRate, float $longYield, float $longYieldAverage = 0.048): float => $engine->analyzeDebtHealth($this->leveredFirm('MID', '0.80', '0.006'), $macro($policyRate, $longYield, $longYieldAverage))->costOfEquity;

        $base = $costOfEquity(0.04, 0.048);

        $this->assertEqualsWithDelta($base, $costOfEquity(0.06, 0.048), 1e-12, 'A hike in the policy rate alone must not move the hurdle.');
        $this->assertEqualsWithDelta($base + 0.01, $costOfEquity(0.04, 0.058), 1e-9, 'The hurdle moves one for one with the long yield.');
        $this->assertEqualsWithDelta($base, $costOfEquity(0.04, 0.048, 0.030), 1e-12, 'The moving average of the long yield must not move the hurdle.');
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
     * The beta is levered on the equity the fundamentals support. A price crash the fair value does not share leaves
     * the beta where it stood: levered on the price, a fall raised the beta, which loaded the next market fall harder.
     * Before the first fair value is struck, the price stands in.
     */
    public function testTheBetaIsLeveredOnTheEquityTheFundamentalsSupport(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $beta = fn(string $price, ?float $fairEquity): float => $engine->analyzeDebtHealth(
            $this->leveredFirm('LEV', '1.00', '0.02')->setPrice($price)->setFairEquityValue($fairEquity),
            $macro,
        )->leveredBeta;

        $standing = $beta('10.00', 1.0e9);
        $this->assertEqualsWithDelta($standing, $beta('1.00', 1.0e9), 1e-12, 'A crash in the price alone does not re-lever the beta.');
        $this->assertLessThan($beta('10.00', 2.5e8), $standing, 'A fall in what the fundamentals support does.');
        $this->assertEqualsWithDelta($standing, $beta('10.00', null), 1e-12, 'Unstruck, the price stands in.');
        $this->assertGreaterThan($standing, $beta('1.00', null));
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
                sprintf('%s prices its equity below its own debt.', $ticker),
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
            'Two hedges with the same beta must still separate by their own credit risk.',
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
            'A 0.45 beta must cost more equity capital than a 0.10 beta.',
        );
    }

    /**
     * Borrowing reaches the spread through the rating: each step up in debt on an unchanged business is reviewed by the
     * agency, which reads the leverage against the business, and the issue then prices at the class it lands in. The
     * spread never narrows as debt is added and is a junk-bond spread away once the firm is levered into high yield.
     */
    public function testTheCreditSpreadWidensAsDebtPushesTheRatingDown(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $stock = $this->leveredFirm('LEVR', '1.00', '0.0109');
        $stock->setCreditRating('A');
        $engine->calibrateAssetVolatility($stock, $macro->policyRateEma);

        $spreads = [];
        $ratings = [];
        foreach ([100_000_000, 200_000_000, 400_000_000, 700_000_000, 1_200_000_000] as $debt) {
            $stock->setWholesaleDebt((string) $debt);
            for ($review = 0; $review < 4; $review++) {
                $engine->reviewRatingAndPriceDebt($stock, $macro);
            }
            $ratings[] = $stock->getCreditRating();
            $spreads[] = $engine->calculateInterestExpense($stock, $macro)->dynamicSpread;
        }

        for ($i = 1; $i < count($spreads); $i++) {
            $this->assertGreaterThanOrEqual($spreads[$i - 1], $spreads[$i], "step {$i}: more debt on the same business never costs less");
        }
        $this->assertSame('A', $ratings[0]);
        $this->assertLessThan(CreditRatingAgency::RATING_RANKS['BBB'], CreditRatingAgency::RATING_RANKS[$ratings[4]], 'levered past 5x EBITDA the firm is high yield');
        $this->assertGreaterThan($spreads[0] + 0.02, $spreads[4], 'and its debt prices a junk-bond spread wider');
    }

    /**
     * sigma_V is the business's risk: solved once from the configured equity volatility at the capital
     * structure it was configured at, recorded the first time a firm is evaluated without one, and not talked
     * down by later borrowing. It still moves with the equity's own volatility shocks. A lender keeps the
     * de-levered identity, because its wholesale book is matched against low-risk assets.
     */
    public function testAssetVolatilityIsTheBusinessRiskAndDebtDoesNotTalkItDown(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $stock = $this->leveredFirm('BIZ', '1.00', '0.01');

        $this->assertNull($stock->getAssetVolatility());
        $engine->calculateInterestExpense($stock, $macro);
        $recorded = $stock->getAssetVolatility();
        $this->assertEqualsWithDelta(
            CreditRisk::solveMertonAssetVolatility(1_000_000_000.0, 0.20, 500_000_000.0, $macro->policyRateEma, 5.0),
            $recorded,
            1e-12,
            'the first evaluation records the solution at the capital structure it finds',
        );

        $this->assertEqualsWithDelta($recorded, $engine->resolveAssetVolatility($stock, 1_000_000_000.0, 8_000_000_000.0, 0.04), 1e-12);

        $stock->setCurrentVolatility('0.40');
        $this->assertGreaterThan($recorded, $engine->resolveAssetVolatility($stock, 1_000_000_000.0, 500_000_000.0, 0.04));

        $bank = $this->leveredFirm('LEND', '1.00', '0.01');
        $bank->setIndustry('Banks - Diversified');
        $this->assertNull($engine->calibrateAssetVolatility($bank, 0.04));
        $this->assertLessThan(
            $engine->resolveAssetVolatility($bank, 1_000_000_000.0, 1_000_000_000.0, 0.04),
            $engine->resolveAssetVolatility($bank, 1_000_000_000.0, 4_000_000_000.0, 0.04),
        );
    }

    /**
     * The Merton horizon's equity volatility reverts at the speed the price process reverts the name's variance
     * at, jump regime included, so a vol spike is priced into credit for as long as it lasts in the tape.
     */
    public function testTheMertonHorizonVolRevertsAtThePriceProcesssOwnSpeed(): void
    {
        $engine = $this->costOfCapitalEngine();
        $bank = $this->leveredFirm('LEND', '1.00', '0.01');
        $bank->setIndustry('Banks - Diversified');
        $bank->setVolatility('0.20');
        $bank->setCurrentVolatility('0.40');
        $bank->setJumpIntensity('1.5');

        // A lender's asset vol is the de-levered identity, so at E = D it is half the horizon equity vol.
        $horizonVol = StochasticProcesses::averageMeanRevertingVolatility(0.40, 0.20, MarketEngine::varianceReversionSpeed(1.5), 5.0);

        $this->assertEqualsWithDelta(0.5 * $horizonVol, $engine->resolveAssetVolatility($bank, 1_000_000_000.0, 1_000_000_000.0, 0.04), 1e-12);
    }

    /**
     * A weighted average cannot exceed the dearer of its legs. A flat distress add-on of up to 15 points
     * made it do exactly that (a 22% WACC on a firm whose equity and debt both cost less); distress now
     * reaches the hurdle only through the yield the firm would pay to borrow and its levered beta.
     */
    public function testTheWaccStaysAWeightedAverageInALiquidityCrisis(): void
    {
        $engine = $this->costOfCapitalEngine();
        $stock = $this->leveredFirm('LOSS', '1.00', '0.02');
        $stock->setOperatingMargin('-0.10');

        $health = $engine->analyzeDebtHealth($stock, $this->neutralMacro());

        $this->assertTrue($health->isLiquidityCrisis);
        $netDebt = 500_000_000.0 - 50_000_000.0;
        $capital = 1_000_000_000.0 + $netDebt;
        // Loss-making: there are no taxes for the interest to shield.
        $expected = ((1_000_000_000.0 / $capital) * $health->costOfEquity) + (($netDebt / $capital) * $health->rawMetrics->currentMarketRate);
        $this->assertEqualsWithDelta($expected, $health->wacc, 1e-12);
        $this->assertLessThanOrEqual($health->costOfEquity, $health->wacc);
    }

    /**
     * The hurdle prices NEW capital, so the debt leg is today's yield and the coupon on debt raised years ago
     * does not enter it. The book cost still differs, because negative carry is a question about cash.
     */
    public function testTheWaccPricesDebtAtTodaysYieldNotItsLegacyCoupon(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $cheap = $this->leveredFirm('CHEP', '1.00', '0.01');
        $cheap->setHistoricalFixedRate('0.02');
        $dear = $this->leveredFirm('DEAR', '1.00', '0.01');
        $dear->setHistoricalFixedRate('0.09');

        $cheapHealth = $engine->analyzeDebtHealth($cheap, $macro);
        $dearHealth = $engine->analyzeDebtHealth($dear, $macro);

        $this->assertGreaterThan($cheapHealth->effectiveCost + 0.01, $dearHealth->effectiveCost);
        $this->assertEqualsWithDelta($cheapHealth->wacc, $dearHealth->wacc, 1e-12);
    }

    /**
     * A lender's hurdle is its cost of equity, which used to carry the same flat distress add-on. A loss
     * quarter does not move the lender's market price of capital, so it must not move the hurdle either.
     */
    public function testALendersHurdleIsNotRaisedByALossQuarter(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $profitable = $this->leveredFirm('PROF', '1.20', '0.01');
        $profitable->setIndustry('Investment Banking');
        $lossMaking = $this->leveredFirm('LOSS', '1.20', '0.01');
        $lossMaking->setIndustry('Investment Banking');
        $lossMaking->setOperatingMargin('-0.20');

        $profitableHealth = $engine->analyzeDebtHealth($profitable, $macro);
        $lossHealth = $engine->analyzeDebtHealth($lossMaking, $macro);

        $this->assertLessThan(0.0, $lossHealth->interestCoverage);
        $this->assertEqualsWithDelta($profitableHealth->costOfEquity, $lossHealth->costOfEquity, 1e-12);
    }

    /**
     * Lenders measure coverage and leverage on the last twelve months. A seasonal firm whose year repeats has
     * the same trailing year every quarter, so its creditworthiness must not change with the calendar; read
     * off one annualized quarter, the same firm could borrow in its peak season and not in its trough.
     */
    public function testCreditIsJudgedOnTheTrailingYearSoTheSeasonsCannotFlipIt(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $stock = $this->leveredFirm('SEAS', '1.00', '0.01');
        $year = [
            ['revenue' => 160_000_000.0, 'ebit' => -32_000_000.0],
            ['revenue' => 200_000_000.0, 'ebit' => 40_000_000.0],
            ['revenue' => 200_000_000.0, 'ebit' => 60_000_000.0],
            ['revenue' => 240_000_000.0, 'ebit' => 132_000_000.0],
        ];

        $trailingVerdicts = [];
        $quarterVerdicts = [];
        $window = $year;
        foreach ($year as $quarter) {
            $window = array_values(array_slice([...$window, $quarter], -4));
            $stock->setQuarterlyOperatingHistory($window);
            $stock->setReportedOperatingMargin($quarter['ebit'] / $quarter['revenue']);

            $trailing = $engine->analyzeTrailingDebtHealth($stock, $macro);
            $trailingVerdicts[] = [$trailing->canIssueDebt, round($trailing->interestCoverage, 9)];
            $quarterVerdicts[] = $engine->analyzeDebtHealth($stock, $macro, null, $stock->getReportedOperatingMargin())->canIssueDebt;
        }

        $this->assertCount(2, array_unique($quarterVerdicts), 'the fixture must swing across the gate when read one quarter at a time');
        $this->assertCount(1, array_unique(array_map('serialize', $trailingVerdicts)), 'a repeating year is the same credit every quarter');
        $this->assertTrue($trailingVerdicts[0][0], 'a year earning 200M on 800M of revenue clears the gate');
        $this->assertEqualsWithDelta(
            ['revenue' => 800_000_000.0, 'margin' => 0.25],
            $engine->resolveTrailingOperatingBasis($stock),
            1e-9,
        );
    }

    /**
     * Before a firm has reported, there is no trailing year to read, and the basis is the reported margin,
     * which is itself null until then and leaves the structural margin in place.
     */
    public function testTrailingBasisFallsBackToTheReportedMarginBeforeAYearIsRecorded(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $stock = $this->leveredFirm('NEWL', '1.00', '0.01');
        $stock->setReportedOperatingMargin(0.02);

        $this->assertNull($engine->resolveTrailingOperatingBasis($stock));
        $stock->setQuarterlyOperatingHistory([['revenue' => 100.0, 'ebit' => 10.0]]);
        $this->assertNull($engine->resolveTrailingOperatingBasis($stock), 'three quarters short of a year');

        $this->assertEquals(
            $engine->analyzeDebtHealth($stock, $macro, null, 0.02),
            $engine->analyzeTrailingDebtHealth($stock, $macro),
        );
    }

    /**
     * The firm's assets are valued where the market values them: the Merton asset value its equity price
     * implies at the business's own asset volatility. Below the debt's face the equity is out of the money,
     * which a collapsed share price says long before a trailing year does. Viability is read off the year's
     * cash operating earnings.
     */
    public function testTheGoingConcernIsTheAssetValueTheEquityPriceImplies(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $stock = $this->leveredFirm('GOCO', '1.00', '0.01');
        $stock->setQuarterlyOperatingHistory(array_fill(0, 4, ['revenue' => 200_000_000.0, 'ebit' => 30_000_000.0]));

        $going = $engine->assessGoingConcern($stock, $macro);
        $assetVolatility = $engine->resolveAssetVolatility($stock, 1_000_000_000.0, 500_000_000.0, $macro->policyRateEma);

        $this->assertEqualsWithDelta(
            CreditRisk::solveMertonAssetValue(1_000_000_000.0, $assetVolatility, 500_000_000.0, $macro->policyRateEma, 5.0),
            $going->assetValue,
            1.0,
        );
        $this->assertEqualsWithDelta(120_000_000.0, $going->trailingEbit, 1e-3);
        $this->assertEqualsWithDelta($engine->analyzeTrailingDebtHealth($stock, $macro)->rawMetrics->ebitda, $going->trailingEbitda, 1e-3);
        $this->assertEqualsWithDelta(50_000_000.0, $going->cash, 1e-6);
        $this->assertEqualsWithDelta(500_000_000.0, $going->claims, 1e-6);
        $this->assertTrue($going->isViable());
        $this->assertTrue($going->isSolvent(), 'equity worth twice the debt');

        // The market marks the equity down to 2% of the debt: the assets no longer cover it.
        $stock->setPrice('0.10');
        $this->assertFalse($engine->assessGoingConcern($stock, $macro)->isSolvent());

        $stock->setQuarterlyOperatingHistory(array_fill(0, 4, ['revenue' => 200_000_000.0, 'ebit' => -150_000_000.0]));
        $this->assertFalse($engine->assessGoingConcern($stock, $macro)->isViable(), 'a year that does not cover its cash costs');
    }

    /**
     * A bank's asset value is struck on its wholesale funding, net of the deposits its loans back, so its claims
     * are that funding too. Set against the deposits as well, every bank read as insolvent and could never sell
     * rescue equity, whatever its capital.
     */
    public function testADepositTakersGoingConcernIsTestedAgainstTheDebtItsValueIsStruckOn(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $bank = $this->leveredFirm('DEPO', '1.00', '0.01');
        $bank->setIndustry('Banks - Regional');
        $bank->setCustomerDeposits('20000000000');
        $bank->setQuarterlyOperatingHistory(array_fill(0, 4, ['revenue' => 200_000_000.0, 'ebit' => 30_000_000.0]));

        $going = $engine->assessGoingConcern($bank, $macro);

        $this->assertEqualsWithDelta(500_000_000.0, $going->claims, 1e-6, 'the wholesale funding, not the deposits');
        $this->assertTrue($going->isSolvent(), 'equity worth twice the wholesale funding');

        $bank->setPrice('0.10');
        $this->assertFalse($engine->assessGoingConcern($bank, $macro)->isSolvent(), 'a collapsed share price still reads as insolvency');
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
            equityRiskPremium: 0.05,
        );
    }

    /**
     * Prompt corrective action measures a bank on TANGIBLE equity over assets (12 USC 1831o): goodwill leaves
     * both sides, so a write-off moves the ratio not at all, and a bank whose book equity looks sound but whose
     * tangible equity is under 2% of its assets is critically undercapitalized and closed.
     */
    public function testTheCapitalRatioIsTangibleSoAWriteOffMovesNeitherSide(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $bank = static function (float $equity, float $goodwill): Stock {
            $stock = new Stock();
            $stock->setTicker('PCAB');
            $stock->setIndustry('Banks - Diversified');
            $stock->setTotalEquity((string) $equity);
            $stock->setGoodwill((string) $goodwill);
            $stock->setWholesaleDebt('950000000000');
            $stock->setPrice('10.00');
            $stock->setSharesOutstanding('1000000000');

            return $stock;
        };

        $carried = $engine->calculateAltmanZScore($bank(60e9, 20e9), 5e9, 40e9);
        $writtenOff = $engine->calculateAltmanZScore($bank(40e9, 0.0), 5e9, 40e9);
        $this->assertEqualsWithDelta($writtenOff['z_score'], $carried['z_score'], 1e-9);

        // Book equity 5% of assets, tangible equity 1.5%.
        $this->assertTrue($engine->calculateAltmanZScore($bank(50e9, 35e9), 5e9, 40e9)['is_bankrupt']);
        $this->assertFalse($engine->calculateAltmanZScore($bank(50e9, 0.0), 5e9, 40e9)['is_bankrupt']);
    }

    /**
     * The recapitalisation target is the manager's: a fortress runs the low leverage its style prescribes,
     * so debt the sector norm would call too little is where it means to be. The same balance sheet under
     * a neutral operator is under-levered and is recapitalised toward the sector's target.
     */
    public function testAFortressIsUnderLeveredOnlyAgainstItsOwnLeverageTarget(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);
        $macro = $this->leverageMacro();

        // Debt at 0.9x equity: under the conglomerate recap target (0.75 x the 1.5x tolerance), above a
        // fortress's (0.60 of that at full conviction).
        $operator = $this->leverageFixture('Conglomerates', '72000000', '10000000');
        $operator->setManagementStyle(ManagementStyle::Operator);
        $this->assertTrue($engine->analyzeDebtHealth($operator, $macro)->isUnderLeveraged, 'the fixture must sit under the sector target');

        $fortress = $this->leverageFixture('Conglomerates', '72000000', '10000000');
        $fortress->setManagementStyle(ManagementStyle::Fortress);
        $fortress->setManagementIntensity(ManagementProfile::MAX_INTENSITY);
        $this->assertFalse($engine->analyzeDebtHealth($fortress, $macro)->isUnderLeveraged);

        // An aggressive manager's target is capped at the sector's: its appetite shows in how hard it
        // borrows, not in a recap aimed at the limit itself.
        $empire = $this->leverageFixture('Conglomerates', '100000000', '10000000');
        $empire->setManagementStyle(ManagementStyle::EmpireBuilder);
        $empire->setManagementIntensity(ManagementProfile::MAX_INTENSITY);
        $this->assertFalse($engine->analyzeDebtHealth($empire, $macro)->isUnderLeveraged, 'debt at 1.25x equity is past the sector recap target');
    }

    /**
     * A dealer's return is struck on its equity, so shrinking the equity under it cannot raise that return, and
     * an institution holding its own capital ratio (Berger et al. 2008) distributes by that ratio. The same book
     * at 3x debt to equity, under half the 8x wholesale limit, is recapitalised only when it has no such target.
     */
    public function testAnInstitutionSteeringToItsCapitalTargetIsNotRecapitalised(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);
        $macro = $this->leverageMacro();

        $untargeted = $this->leverageFixture('Investment Banking', '240000000', '36000000');
        $this->assertTrue($engine->analyzeDebtHealth($untargeted, $macro)->isUnderLeveraged, 'the fixture must sit under the recap threshold');

        $targeted = $this->leverageFixture('Investment Banking', '240000000', '36000000');
        $targeted->setTicker('PERE');
        $this->assertNotNull(\App\Data\Company\Sectors::strategyFor('Investment Banking')->getTargetCapitalRatio($targeted));
        $this->assertFalse($engine->analyzeDebtHealth($targeted, $macro)->isUnderLeveraged);
    }

    /**
     * A producer's cash flow swings with the price deck, so its sector carries half the book leverage of the
     * market (Damodaran, Jan 2026: E&P 0.50 book D/E against 0.93 for all non-financials). The balance sheet a
     * conglomerate would call under-levered is already at a producer's norm, and is not recapitalised.
     */
    public function testAProducerAtItsIndustryLeverageIsNotRecapitalised(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);
        $macro = $this->leverageMacro();

        $this->assertTrue($engine->analyzeDebtHealth($this->leverageFixture('Conglomerates', '40000000', '10000000'), $macro)->isUnderLeveraged);
        $this->assertFalse($engine->analyzeDebtHealth($this->leverageFixture('Oil & Gas E&P', '40000000', '10000000'), $macro)->isUnderLeveraged);
        $this->assertSame(0.50, \App\Data\Company\Sectors::INDUSTRY_METRICS['Oil & Gas E&P']['equity_limit']);
    }

    /**
     * Cash above the operating balance could retire debt tomorrow, so an operating company's recap leverage is net
     * of it. The same 1.25x of gross debt is past the conglomerate target with only the operating balance in the
     * treasury and well under it with a cash pile beside it.
     */
    public function testIdleCashNetsOutOfTheRecapitalizationLeverage(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);
        $macro = $this->leverageMacro();
        $strategy = \App\Data\Company\Sectors::strategyFor('Conglomerates');
        $lease = (new CorporateMetrics())->calculateLeaseLiability(100000000.0, $strategy->getLeaseIntensity(new Stock()));

        $lean = $this->leverageFixture('Conglomerates', '100000000', '0');
        $targetCash = $lean->getManagementProfile()->appliedTargetCash($strategy->calculateTargetOperatingCash(100000000.0, 0.0, 100000000.0));
        $lean->setCorporateTreasury((string) $targetCash);
        $leanHealth = $engine->analyzeDebtHealth($lean, $macro);
        $this->assertEqualsWithDelta((100000000.0 + $lease) / 80000000.0, $leanHealth->debtToEquity, 1e-9, 'At the operating balance nothing nets.');
        $this->assertFalse($leanHealth->isUnderLeveraged);

        $rich = $this->leverageFixture('Conglomerates', '100000000', (string) ($targetCash + 40000000.0));
        $richHealth = $engine->analyzeDebtHealth($rich, $macro);
        $this->assertEqualsWithDelta((60000000.0 + $lease) / 80000000.0, $richHealth->debtToEquity, 1e-9);
        $this->assertTrue($richHealth->isUnderLeveraged);
    }

    /** A financial's treasury is its liquidity buffer, not idle cash: its recap leverage stays gross. */
    public function testAFinancialsRecapitalizationLeverageStaysGross(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency, $this->marketEventPublisherMock);
        $strategy = \App\Data\Company\Sectors::strategyFor('Investment Banking');
        $lease = (new CorporateMetrics())->calculateLeaseLiability(100000000.0, $strategy->getLeaseIntensity(new Stock()));

        $health = $engine->analyzeDebtHealth($this->leverageFixture('Investment Banking', '240000000', '90000000'), $this->leverageMacro());
        $this->assertEqualsWithDelta((240000000.0 + $lease) / 80000000.0, $health->debtToEquity, 1e-9);
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

    /** The page quotes the distance to default the rating agency grades on: one calculation, not two. */
    public function testThePageAndTheRatingAgencyReadTheSameDistanceToDefault(): void
    {
        $stock = new Stock();
        $stock->setTicker('DDTF');
        $stock->setIndustry('Tools & Accessories');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('100000000000');
        $stock->setWholesaleDebt('60000000000');
        $stock->setVolatility('0.30');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('80.00');
        $stock->setTotalRevenue('90000000000');
        $stock->setOperatingMargin('0.15');
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.04);

        $graded = null;
        $agency = $this->createMock(CreditRatingAgency::class);
        $agency->method('evaluateRating')->willReturnCallback(function (Stock $rated, float $distanceToDefault) use (&$graded): ?string {
            $graded = $distanceToDefault;

            return null;
        });
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $agency, null);

        $engine->reviewRatingAndPriceDebt($stock, $macroState);

        $this->assertNotNull($graded);
        $this->assertSame($graded, $engine->resolveDistanceToDefault($stock, $macroState));
    }

    /**
     * The firm's spread is its class's multiple of the market index today, times its own seeded multiple of the class,
     * so a wide market raises the cost of new debt and a tight one lowers it from the first basis point. The trend sets
     * only the through-the-cycle baseline a widening is judged against, never the spread itself.
     */
    public function testTheSpreadIsTheClassMultipleOfTheIndexAndTheTrendSetsOnlyTheBaseline(): void
    {
        $engine = $this->costOfCapitalEngine();
        $stock = $this->leveredFirm('CYCL', '1.20', '0.0163');
        $stock->setCreditRating('BBB');
        $metrics = fn(float $ema, float $trend): DebtMetricsDTO => $engine->calculateInterestExpense(
            $stock,
            new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.04, yield5yEma: 0.045, macroCreditSpreadEma: $ema, macroCreditSpreadTrend: $trend, corporateTaxRate: 0.21),
        );

        $multiple = static fn(float $seeded): float => CreditRisk::calculateIssuerSpreadMultiple($seeded, \App\Service\Math\MacroConstants::BASE_CREDIT_SPREAD, \App\Service\Math\MacroConstants::BASE_CREDIT_SPREAD * \App\Service\Math\MacroConstants::HY_BASE_SPREAD_MULTIPLIER);
        $bbb = CreditRisk::IG_CLASS_SPREAD_MULTIPLES['BBB'] * $multiple(0.0163);
        $this->assertEqualsWithDelta($bbb * 0.019, $metrics(0.019, 0.015)->dynamicSpread, 1e-12, 'A firm seeded at the BBB spread trades at the BBB multiple of the index.');
        $this->assertEqualsWithDelta($bbb * 0.012, $metrics(0.012, 0.015)->dynamicSpread, 1e-12, 'A tight market lowers the cost of new debt.');
        $this->assertEqualsWithDelta($metrics(0.019, 0.015)->dynamicSpread, $metrics(0.019, 0.018)->dynamicSpread, 1e-12, 'The trend does not move the spread.');
        $this->assertEqualsWithDelta($bbb * 0.015, $metrics(0.019, 0.015)->baselineSpread, 1e-12, 'It sets the through-the-cycle baseline.');

        $stock->setCreditSpread('0.0200');
        $this->assertEqualsWithDelta(0.0200 / (CreditRisk::IG_CLASS_SPREAD_MULTIPLES['BBB'] * 0.013), $multiple(0.0200), 1e-12, 'A seeded spread nearest the BBB class is read as a multiple of it.');
        $this->assertEqualsWithDelta(CreditRisk::IG_CLASS_SPREAD_MULTIPLES['BBB'] * $multiple(0.0200) * 0.019, $metrics(0.019, 0.015)->dynamicSpread, 1e-12, 'A firm seeded wide of its class carries that multiple.');
    }

    /**
     * An institution rated on its capital is rated as S&P's bank methodology would: from the country anchor plus what
     * its capital earns it, which tops out at A whatever distance to default its equity reads.
     */
    public function testAnInstitutionRatedOnItsCapitalTopsOutAtTheBankCeiling(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $this->creditRatingAgency);
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.04);
        $bank = new Stock();
        $bank->setTicker('BNKA');
        $bank->setIndustry('Banks - Diversified');
        $bank->setCreditRating('AAA');
        $bank->setTotalEquity('100000000');
        $bank->setCustomerDeposits('900000000');
        $bank->setWholesaleDebt('50000000');
        $bank->setCorporateTreasury('100000000');
        $bank->setSharesOutstanding('10000000');
        $bank->setPrice('40.00');
        $bank->setVolatility('0.15');
        $bank->setTotalRevenue('60000000');
        $bank->setOperatingMargin('0.30');
        $this->assertGreaterThan(CreditRatingAgency::THRESHOLD_AAA + CreditRatingAgency::HYSTERESIS_BUFFER, $engine->resolveDistanceToDefault($bank, $macroState), 'the equity reads the bank as AAA');

        $engine->reviewRatingAndPriceDebt($bank, $macroState);
        $this->assertSame('AA', $bank->getCreditRating(), 'one notch a review toward the ceiling');
        $engine->reviewRatingAndPriceDebt($bank, $macroState);
        $engine->reviewRatingAndPriceDebt($bank, $macroState);
        $this->assertSame(CreditRatingAgency::CAPITAL_RATED_RATING_CEILING, $bank->getCreditRating(), 'and no further');
    }

    /**
     * A firm opens rated as its balance sheet reads: the agency's review is repeated until it settles, instead of every
     * firm opening at the placeholder BBB and walking a notch a quarter for its first year.
     */
    public function testAnOpeningFirmIsSeededAtTheRatingItsBalanceSheetReads(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();

        $prudent = $this->leveredFirm('SEED', '1.00', '0.0109');
        $prudent->setRetainedEarnings('300000000');
        $prudent->setWholesaleDebt('100000000');
        $this->assertSame('BBB', $prudent->getCreditRating(), 'the placeholder grade');
        $this->assertTrue($engine->seedCreditRating($prudent, $macro));
        $this->assertSame('A', $prudent->getCreditRating(), 'low leverage on a satisfactory business anchors at A');
        $this->assertFalse($engine->seedCreditRating($prudent, $macro), 'a settled rating does not move');

        $levered = $this->leveredFirm('SEEL', '1.00', '0.0109');
        $levered->setRetainedEarnings('300000000');
        $levered->setWholesaleDebt('1000000000');
        $this->assertTrue($engine->seedCreditRating($levered, $macro));
        $this->assertSame('BB', $levered->getCreditRating(), 'at over 4x EBITDA its leverage anchors it in high yield');

        $levered->setCreditRating('AAA');
        $this->assertTrue($engine->seedCreditRating($levered, $macro));
        $this->assertSame('BB', $levered->getCreditRating(), 'from a stale AAA it is reviewed down three notches in one seeding');
    }

    /**
     * Acquisition notes are rated on the pro-forma balance sheet: the worse of the issuer's rating, the grade its
     * pro-forma distance to default reads and the anchor its pro-forma leverage sets. Borrowing never upgrades it.
     */
    public function testAProFormaRatingIsTheWorstOfTheRatingTheDistanceAndTheLeverage(): void
    {
        $stock = $this->leveredFirm('PROF', '1.00', '0.0109');
        $stock->setCreditRating('A');

        $this->assertSame('BB', $this->engine->resolveProFormaRating($stock, 8.0, 350.0, 100.0), 'leverage of 3.5x anchors at BB');
        $this->assertSame('B', $this->engine->resolveProFormaRating($stock, 1.2, 50.0, 100.0), 'a distance of 1.2 reads B');
        $this->assertSame('A', $this->engine->resolveProFormaRating($stock, 8.0, 50.0, 100.0), 'a modest deal keeps the rating');

        $stock->setCreditRating('BBB');
        $this->assertSame('BBB', $this->engine->resolveProFormaRating($stock, 10.0, 0.0, 100.0), 'a deal never upgrades the issuer');
    }

    /**
     * The primary market shuts to a class when the index it trades off blows out, as high yield did in 2008 and 2020:
     * a CCC issuer trading at a CCC spread in a calm market still rolls its maturity, the same issuer is shut out once
     * the HY index reaches crisis levels, and an investment-grade issuer refinances with the IG index at its record.
     */
    public function testTheMarketShutsWhenTheClassIndexBlowsOutNotAtAClasssOwnSpread(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $issuer = function (string $rating): Stock {
            $stock = $this->buildMaturityIssuer($rating, 0.02);
            $stock->setTotalRevenue('20000000000');
            $stock->setOperatingMargin('0.15');
            $stock->setSharesOutstanding('1000000000');
            $stock->setPrice('20.00');
            $stock->setVolatility('0.30');

            return $stock;
        };
        $macro = static fn(float $ig, float $hy): MacroStateDTO => new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.045, macroCreditSpreadEma: $ig, highYieldCreditSpreadEma: $hy);

        $calmCcc = $engine->analyzeDebtHealth($issuer('CCC'), $macro(0.013, 0.043));
        $this->assertGreaterThan(0.10, $calmCcc->rawMetrics->dynamicSpread, 'a CCC trades at its class spread');
        $this->assertGreaterThan(1.0, $calmCcc->interestCoverage);
        $this->assertTrue($engine->rollMaturities($issuer('CCC'), $calmCcc, 0.0)->refinanced, 'and still refinances in a calm market');

        $crisis = $macro(0.035, 0.115);
        $this->assertFalse($engine->rollMaturities($issuer('BB'), $engine->analyzeDebtHealth($issuer('BB'), $crisis), 0.0)->refinanced, 'high yield at 11.5% is shut');
        $this->assertTrue($engine->rollMaturities($issuer('A'), $engine->analyzeDebtHealth($issuer('A'), $macro(0.065, 0.215)), 0.0)->refinanced, 'IG at its record is not');
    }

    /**
     * Bondholders charge for equity volatility above the firm's norm (Campbell & Taksler 2003), 2 bp a point. A
     * volatile business's rating class already prices its normal risk, so it pays nothing at its own volatility; a
     * quiet one pays only above the market's normal 20%.
     */
    public function testTheVolatilityPremiumIsChargedOnlyAboveTheFirmsOwnNorm(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $spread = function (string $structural, string $current) use ($engine, $macro): float {
            $stock = $this->leveredFirm('VOLP', '1.00', '0.0163');
            $stock->setCreditRating('BBB');
            $stock->setVolatility($structural);
            $stock->setCurrentVolatility($current);

            return $engine->resolveCreditSpread($stock, $macro);
        };

        $base = $spread('0.45', '0.45');
        $this->assertEqualsWithDelta($spread('0.15', '0.15'), $base, 1e-12, 'a volatile business pays nothing at its own volatility');
        $this->assertEqualsWithDelta($base + 0.10 * 0.02, $spread('0.45', '0.55'), 1e-12, 'ten points above its norm cost 20 bp');
        $this->assertEqualsWithDelta($base + 0.05 * 0.02, $spread('0.15', '0.25'), 1e-12, 'a quiet firm pays only above 20%');
    }

    /**
     * The class curves are quoted at senior unsecured recoveries. A hedge fund borrows from its prime broker against
     * its portfolio, a secured claim. Seeded alike as a top credit and both rated B, the fund pays less than a sponsor
     * whose notes are unsecured: the default part of its spread is scaled to the secured recovery. A seeded spread is
     * read against the issuer's own recovery-adjusted curve, so the same seeding at the same class prices the same.
     */
    public function testSecuredPrimeBrokerageFundingPaysLessThanUnsecuredNotesAtTheSameRating(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $spreadFor = function (string $industry) use ($engine, $macro): float {
            $stock = $this->leveredFirm('LGDF', '1.00', '0.0035');
            $stock->setIndustry($industry);
            $stock->setCreditRating('B');

            return $engine->resolveCreditSpread($stock, $macro);
        };

        $secured = 1.0 - \App\Service\Market\Bond\CorporateDefaultService::RECOVERY_SENIOR_SECURED;
        $unsecured = 1.0 - \App\Service\Market\Bond\CorporateDefaultService::RECOVERY_SENIOR_UNSECURED;
        $this->assertEqualsWithDelta($secured, \App\Data\Company\Sectors::strategyFor('Hedge Fund')->getLossGivenDefault(), 1e-12);
        $this->assertEqualsWithDelta($unsecured, \App\Data\Company\Sectors::strategyFor('Private Equity')->getLossGivenDefault(), 1e-12);

        $unsecuredSpread = $spreadFor('Private Equity');
        $hy = $macro->highYieldCreditSpreadEma;
        $classB = CreditRisk::calculateRatingClassSpread('B', $macro->macroCreditSpreadEma, $hy);
        $this->assertEqualsWithDelta($classB * CreditRisk::calculateIssuerSpreadMultiple(0.0035, 0.013, 0.013 * 3.3), $unsecuredSpread, 1e-12, 'unsecured notes price on the class curve');
        $this->assertLessThan($unsecuredSpread, $spreadFor('Hedge Fund'), 'secured funding pays less');
    }

    /**
     * The issuer's multiple of its class is worked out once per seeded spread and recovery: a firm reseeded at another
     * spread, or moved to a sector with another recovery, prices as an engine that never saw it before.
     */
    public function testTheIssuerMultipleFollowsAReseededSpreadAndRecovery(): void
    {
        $engine = $this->costOfCapitalEngine();
        $macro = $this->neutralMacro();
        $stock = $this->leveredFirm('MEMO', '1.00', '0.0035');
        $stock->setCreditRating('BB');
        $engine->resolveCreditSpread($stock, $macro);

        $stock->setCreditSpread('0.0420');
        $this->assertSame($this->costOfCapitalEngine()->resolveCreditSpread($stock, $macro), $engine->resolveCreditSpread($stock, $macro), 'a reseeded spread');
        $stock->setIndustry('Hedge Fund');
        $this->assertSame($this->costOfCapitalEngine()->resolveCreditSpread($stock, $macro), $engine->resolveCreditSpread($stock, $macro), 'another recovery');
    }

    /**
     * Each firm type is anchored on the table S&P rates it with: a REIT on the real estate debt/EBITDA bands, a
     * reinsurer on its capital (the capital-rated ceiling, not the corporate matrix), private equity and a hedge fund
     * on debt to adjusted total equity. Read through the pro-forma rating, which takes the worse of the rating, the
     * distance to default (here AAA) and the anchor.
     */
    public function testEachFirmTypeIsAnchoredOnItsOwnTable(): void
    {
        $anchorOf = function (string $industry, float $netDebt, float $ebitda, string $equity = '100'): string {
            $stock = new Stock();
            $stock->setTicker('ANCH');
            $stock->setIndustry($industry);
            $stock->setCreditRating('AAA');
            $stock->setTotalEquity(\App\Service\Math\Decimal::of($equity));

            return $this->engine->resolveProFormaRating($stock, 10.0, $netDebt, $ebitda);
        };

        $this->assertSame('B', $anchorOf('Specialty Industrial Machinery', 600.0, 100.0), 'the corporate table: 6x is highly leveraged');
        $this->assertSame('BBB', $anchorOf('REIT - Industrial', 600.0, 100.0), 'the real estate bands: 6x is intermediate');
        $this->assertSame(CreditRatingAgency::CAPITAL_RATED_RATING_CEILING, $anchorOf('Insurance - Reinsurance', 660.0, 100.0), 'a reinsurer is rated on its capital');
        $this->assertSame('BB', $anchorOf('Private Equity', 240.0, 10.0), '2.4x adjusted equity is aggressive, whatever the EBITDA');
        $this->assertSame('BBB', $anchorOf('Hedge Fund', 120.0, 1.0), '1.2x is intermediate');
    }

    /**
     * Altman's samples held no regulated utilities, and a utility's thin asset turnover scores it grey however safe its
     * rate base, so its rating is read on the matrix and its distance to default alone. A firm of any other kind on the
     * same balance sheet is still capped by the zone.
     */
    public function testARegulatedUtilityIsNotCappedByAltmansGreyZone(): void
    {
        $zoneOf = function (string $industry): ?string {
            $stock = $this->leveredFirm('ZONE', '0.5', '0.010');
            $stock->setIndustry($industry);
            $stock->setTotalEquity('400000000');
            $stock->setWholesaleDebt('600000000');
            $stock->setTotalRevenue('250000000');
            $stock->setCreditRating('BBB');

            return (new \ReflectionMethod(DebtEngine::class, 'resolveRatingZScore'))
                ->invoke($this->engine, $stock, \App\Data\Company\Sectors::strategyFor($industry), 40_000_000.0, 250_000_000.0)['zone'];
        };

        $this->assertContains($zoneOf('General'), ['Grey', 'Distress'], 'Control: on Z\'\' this balance sheet is capped.');
        $this->assertNull($zoneOf('Utilities - Regulated Electric'));
    }

    /**
     * A REIT's solvency is measured on its undepreciated real estate, as its bond covenants cap total debt against
     * total undepreciated assets. A trust whose book equity is a quarter of its assets after years of distributing past
     * book income is sound when depreciation has taken as much again off the property's book value.
     */
    public function testAReitIsMeasuredOnItsUndepreciatedAssets(): void
    {
        // The same property at the same book value, bought for its book value or for half as much again.
        $trust = function (float $accumulatedDepreciation): Stock {
            $stock = new Stock();
            $stock->setTicker('UNDP');
            $stock->setIndustry('REIT - Industrial');
            $stock->setTotalEquity('250000000');
            $stock->setWholesaleDebt('750000000');
            $stock->setTotalRevenue('100000000');
            $stock->setGrossPpe((string) (900_000_000.0 + $accumulatedDepreciation));
            $stock->setAccumulatedDepreciation((string) $accumulatedDepreciation);

            return $stock;
        };

        $book = $this->engine->resolveTangibleCapitalBase($trust(0.0), 100_000_000.0);
        $depreciated = $this->engine->resolveTangibleCapitalBase($trust(500_000_000.0), 100_000_000.0);
        $this->assertEqualsWithDelta($book['capital'] + 500_000_000.0, $depreciated['capital'], 1.0);
        $this->assertEqualsWithDelta($book['assets'] + 500_000_000.0, $depreciated['assets'], 1.0);

        $this->assertNotSame('Safe', $this->engine->calculateAltmanZScore($trust(0.0), 0.0, 100_000_000.0)['zone'], 'Control: a quarter of book assets.');
        $this->assertSame('Safe', $this->engine->calculateAltmanZScore($trust(500_000_000.0), 0.0, 100_000_000.0)['zone']);
    }
}

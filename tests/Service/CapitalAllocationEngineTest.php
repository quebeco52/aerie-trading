<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Data\ManagementStyle;
use App\Entity\Stock;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
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
    private DebtHealthDTO $debtHealth;

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

        $this->debtHealth = $debtHealthMock;
        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($debtHealthMock);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($debtHealthMock);

        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->mathUtilityMock->method('calculateManagementFairValuePE')->willReturn(15.0);

        $this->treasuryEngineMock = $this->createStub(TreasuryEngine::class);

        $this->engine = new CapitalAllocationEngine(
            $this->corporateLedgerServiceMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );
    }

    /**
     * The fortress must be able to HOLD what it retains.
     *
     * Its reserves are the archetype, but the hoarding tests read any large balance as a defect and unlock
     * repurchases at a mega-hoarder pace to correct it — so the manager that withheld the dividend watched
     * the cash leave through the other leg anyway, and ended up distributing MORE than the steward beside
     * it. Both the target the firm runs to and the threshold that judges it now scale with the style, and
     * the payout bias governs the repurchase leg as well as the dividend.
     */
    public function testFortressKeepsTheReservesTheHoardingTestUsedToCorrectAway(): void
    {
        $operator = $this->buildCashRichFirm('HOARD_OP', null);
        $fortress = $this->buildCashRichFirm('HOARD_FT', ManagementStyle::Fortress);

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        mt_srand(20260915);
        $operatorResult = $this->engine->allocateCapital($operator, 10.00, 2.00, 100.00, 1_000_000.0, $macroState);
        mt_srand(20260915);
        $fortressResult = $this->engine->allocateCapital($fortress, 10.00, 2.00, 100.00, 1_000_000.0, $macroState);

        $operatorBuyback = (float) $operatorResult['total_cash_spent'];
        $fortressBuyback = (float) $fortressResult['total_cash_spent'];

        $this->assertGreaterThan(0.0, $operatorBuyback, 'The control firm must actually be force-fed, or the test proves nothing.');
        $this->assertLessThan(
            $operatorBuyback,
            $fortressBuyback,
            'Identical balance sheets: the only thing holding the cash in is the style.'
        );

        // Total distribution, not just the leg the bias used to reach. This is the assertion that would have
        // failed before: the fortress paid a smaller dividend and a LARGER buyback out of the same treasury.
        $operatorTotal = (float) $operatorResult['total_paid'] + $operatorBuyback;
        $fortressTotal = (float) $fortressResult['total_paid'] + $fortressBuyback;

        $this->assertLessThan(
            $operatorTotal,
            $fortressTotal,
            'A manager that retains must return less in total than the neutral baseline, through every channel combined.'
        );
    }

    /** A steward distributes what it will not reinvest, so the same balance sheet returns more. */
    public function testStewardReturnsMoreThanTheNeutralBaselineFromTheSameBalanceSheet(): void
    {
        $operator = $this->buildCashRichFirm('HOARD_O2', null);
        $steward = $this->buildCashRichFirm('HOARD_ST', ManagementStyle::Steward);

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        mt_srand(20260915);
        $operatorResult = $this->engine->allocateCapital($operator, 10.00, 2.00, 100.00, 1_000_000.0, $macroState);
        mt_srand(20260915);
        $stewardResult = $this->engine->allocateCapital($steward, 10.00, 2.00, 100.00, 1_000_000.0, $macroState);

        $operatorTotal = (float) $operatorResult['total_paid'] + (float) $operatorResult['total_cash_spent'];
        $stewardTotal = (float) $stewardResult['total_paid'] + (float) $stewardResult['total_cash_spent'];

        $this->assertGreaterThan($operatorTotal, $stewardTotal);
    }

    /** A cash-rich, profitable, undervalued firm: the configuration the buyback engine acts on hardest. */
    private function buildCashRichFirm(string $ticker, ?ManagementStyle $style): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('20000000');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('1.00');
        $stock->setLastDividend('1.00');
        $stock->setRetainedEarnings('50000000.00');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('1000000.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setRoicTtm('0.15');
        $stock->setBaselineRoic('0.15');
        $stock->setManagementStyle($style);

        return $stock;
    }

    /**
     * The District REIT rule is a floor under the smoothing: a trust paying well under its requirement and moving
     * toward target at an aristocrat's pace still distributes 85% of the quarter's taxable income. Taxable income is
     * net income, not the FFO the payout target is struck on, so the floor sits below the FFO target.
     */
    public function testAReitDistributesTheDistrictMinimumOfItsTaxableIncome(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST_REIT');
        $stock->setIndustry('REIT - Diversified');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.85');
        $stock->setDividendSpeed('0.02');
        $stock->setLastDividend('0.40');
        $stock->setRetainedEarnings('50000000.00');
        $stock->setDepreciationRate('0.05');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');

        // $1.00 of quarterly net income and $2.25 of FFO per share.
        $result = $this->engine->allocateCapital($stock, 9.00, 2.00, 100.00, 1000000.0, new MacroStateDTO(corporateTaxRate: 0.21), 1_000_000.0);

        $this->assertEqualsWithDelta(0.85, $result['dividend_paid'], 1e-9);
    }

    /**
     * A REIT owes 85% of its taxable income whether or not its cash flow covers it. With nothing above its operating
     * floor it borrows the distribution at its market rate while it can issue debt, and pays what its cash allows
     * once its lenders will not fund it.
     */
    public function testAReitBorrowsTheDistributionItsCashCannotCover(): void
    {
        $issued = [];
        $lender = $this->createStub(DebtEngine::class);
        $lender->method('analyzeTrailingDebtHealth')->willReturn($this->debtHealth);
        $lender->method('issueDebt')->willReturnCallback(static function (Stock $stock, float $amount, float $rate) use (&$issued): void {
            $issued[] = [$amount, $rate];
        });
        $engine = new CapitalAllocationEngine($this->corporateLedgerServiceMock, $this->corporateMetricsMock, $lender, $this->mathUtilityMock, $this->treasuryEngineMock);

        // $1.00 of quarterly taxable income on 1m shares; cash sits exactly on the 3% operating floor of a $5m base.
        $result = $engine->allocateCapital($this->cashStrappedReit(), 9.00, 0.00, 100.00, 1000000.0, new MacroStateDTO(corporateTaxRate: 0.21), 1_000_000.0);
        $this->assertEqualsWithDelta(0.85, $result['dividend_paid'], 1e-9);
        $this->assertCount(1, $issued);
        $this->assertEqualsWithDelta(850_000.0, $issued[0][0], 1e-6);
        $this->assertSame(0.05, $issued[0][1], 'Borrowed at its market rate, not a distress rate.');

        $refused = $this->createMock(DebtEngine::class);
        $refused->method('analyzeTrailingDebtHealth')->willReturn(new DebtHealthDTO(
            grossCost: 0.05, effectiveCost: 0.05, cashYield: 0.04, isNegativeCarry: false, isSevereNegativeCarry: false,
            interestCoverage: 5.0, wantsToPaydownDebt: false, canIssueDebt: false, debtTolerance: 1.5, wacc: 0.06,
            costOfEquity: 0.08, leveredBeta: 1.0, rawMetrics: $this->debtHealth->rawMetrics, isLiquidityCrisis: false,
            isLiquidityWarning: false, isUnderLeveraged: false
        ));
        $refused->expects($this->never())->method('issueDebt');
        $unfunded = (new CapitalAllocationEngine($this->corporateLedgerServiceMock, $this->corporateMetricsMock, $refused, $this->mathUtilityMock, $this->treasuryEngineMock))
            ->allocateCapital($this->cashStrappedReit(), 9.00, 0.00, 100.00, 1000000.0, new MacroStateDTO(corporateTaxRate: 0.21), 1_000_000.0);
        $this->assertSame(0.0, $unfunded['dividend_paid']);
    }

    private function cashStrappedReit(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TEST_REIT');
        $stock->setIndustry('REIT - Diversified');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('150000');
        $stock->setTargetPayoutRatio('0.85');
        $stock->setDividendSpeed('0.02');
        $stock->setLastDividend('0.40');
        $stock->setRetainedEarnings('50000000.00');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');

        return $stock;
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
        // A trust reports FFO as its earnings (Net Income + Depreciation), so its trailing year is FFO too:
        // $1.00 quarterly net income and $1.25 quarterly depreciation on 1M shares is $9.00 a year.
        $stock->setTotalNetIncome('9000000.00');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        // With an 80% target payout ratio on trailing FFO, the target quarterly dividend is $1.80 per share.
        $annualFfoEps = 9.00;
        $result = $this->engine->allocateCapital($stock, $annualFfoEps, 2.00, 100.00, 1000000.0, $macroState, 1_000_000.0);

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
        $stock->setTotalNetIncome('8000000.00');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        // Trailing EPS = $8.00 ($2.00 a quarter). Under 100% saturation, effective payout is 85% ($1.70 per share)
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

    /**
     * Managers are reluctant to cut (Lintner 1956). With earnings falling to what the dividend pays out but still a
     * profit, a payer holds its dividend rather than following its target payout down.
     */
    public function testAPayerHoldsItsDividendWhileEarningsFall(): void
    {
        [$peer, $peerStock] = $this->payDividendOnFallingEarnings(false, $this->buildHealth());

        $this->assertLessThan(1.00, 1.00 * $peerStock->getPolicyPayoutRatio(), 'Control: the target payout sits below the dividend.');
        $this->assertSame(1.00, $peer['dividend_paid'], 'A profitable year is not a cut.');
    }

    /**
     * Cuts follow losses (DeAngelo, DeAngelo & Skinner 1992): after a loss year a payer follows its target payout
     * down at its own adjustment speed. One known for an unbroken record of increases does not cut by choice even
     * then: it holds its dividend and keeps its standing.
     */
    public function testAfterALossYearAPeerCutsWhereAnAristocratHolds(): void
    {
        [$peer, $peerStock] = $this->payDividendOnFallingEarnings(false, $this->buildHealth(), trailingNetIncome: '-1000000.00');
        [$aristocrat, $aristocratStock] = $this->payDividendOnFallingEarnings(true, $this->buildHealth(), trailingNetIncome: '-1000000.00');

        $target = 1.00 * $peerStock->getPolicyPayoutRatio();
        $this->assertEqualsWithDelta(1.00 + (0.10 * ($target - 1.00)), $peer['dividend_paid'], 1e-9, 'After a loss a firm follows its target payout down at its own speed.');
        $this->assertSame(1.00, $aristocrat['dividend_paid'], 'An aristocrat holds its dividend through a loss year.');
        $this->assertTrue($aristocratStock->isDividendAristocrat());
    }

    /** A cut ends the record the aristocrat was known for, even one a liquidity crisis forced on it. */
    public function testAForcedCutEndsTheAristocratsRecord(): void
    {
        [$result, $stock] = $this->payDividendOnFallingEarnings(true, $this->buildHealth(interestCoverage: 0.5));

        $this->assertSame(0.0, $result['dividend_paid'], 'Coverage below one omits the dividend, aristocrat or not.');
        $this->assertFalse($stock->isDividendAristocrat());
        $this->assertContains('Cut its dividend, ending its record of dividend increases.', array_column($result['events'], 'description'));
    }

    /**
     * Managers raise external funds before they cut (Brav, Graham, Harvey & Michaely 2005). A payer whose quarter
     * leaves its treasury below its operating floor borrows the dividend it holds while its lenders will fund it.
     */
    public function testAPayerBorrowsRatherThanCutWhenAQuartersCashFallsShort(): void
    {
        [$peer, , $borrowed] = $this->payDividendShortOfCash(false);

        $this->assertEqualsWithDelta(1.00, $peer['dividend_paid'], 1e-9);
        $this->assertCount(1, $borrowed);
    }

    /**
     * After a loss year a payer no longer holds its dividend, so it borrows nothing to pay it and the cash cap takes
     * it; an aristocrat holds its dividend through the loss, borrows the shortfall, and keeps its standing.
     */
    public function testAfterALossYearOnlyAnAristocratBorrowsToHoldItsDividend(): void
    {
        [$aristocrat, $aristocratStock, $borrowed] = $this->payDividendShortOfCash(true, '-1000000.00');
        [$peer, , $peerBorrowed] = $this->payDividendShortOfCash(false, '-1000000.00');

        $this->assertEqualsWithDelta(1.00, $aristocrat['dividend_paid'], 1e-9);
        $this->assertTrue($aristocratStock->isDividendAristocrat(), 'A funded dividend is not a cut.');
        $this->assertCount(1, $borrowed);
        $this->assertSame([], $peerBorrowed);
        $this->assertSame(0.0, $peer['dividend_paid'], 'Control: without the record the cash cap takes the dividend.');
    }

    /**
     * A firm that pays out $1.00 a quarter from a $0.5m treasury in a quarter whose free cash flow is -$1.00 a share;
     * the trailing year earned $4.00 a share unless a loss year is given.
     *
     * @return array{0: array<string, mixed>, 1: Stock, 2: list<float>} The allocation, the firm, and each amount borrowed.
     */
    private function payDividendShortOfCash(bool $aristocrat, string $trailingNetIncome = '4000000.00'): array
    {
        $stock = new Stock();
        $stock->setTicker($aristocrat ? 'ARIS' : 'PEER');
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('500000');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.10');
        $stock->setLastDividend('1.00');
        $stock->setDividendAristocrat($aristocrat);
        $stock->setRetainedEarnings('50000000.00');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setRoicTtm('0.12');
        $stock->setTotalNetIncome($trailingNetIncome);

        $borrowed = [];
        $lender = $this->createStub(DebtEngine::class);
        $lender->method('analyzeTrailingDebtHealth')->willReturn($this->buildHealth());
        $lender->method('issueDebt')->willReturnCallback(static function (Stock $issuer, float $amount) use (&$borrowed): void {
            $borrowed[] = $amount;
        });

        $result = $this->buildEngineWith($lender)->allocateCapital($stock, 4.00, -1.00, 100.00, 1000000.0, new MacroStateDTO(corporateTaxRate: 0.21));

        return [$result, $stock, $borrowed];
    }

    /**
     * Cuts follow losses (DeAngelo, DeAngelo & Skinner 1992), not a return below the hurdle on earnings that still
     * cover the payout. A firm earning 1% on capital that costs it 10% keeps paying like any other profitable firm;
     * the rule this replaced omitted the dividend of any firm 800bps under its hurdle.
     */
    public function testAFirmEarningBelowItsHurdleKeepsPayingRatherThanOmitting(): void
    {
        [$result] = $this->payDividendOnFallingEarnings(false, $this->buildHealth(wacc: 0.10), '0.01');

        $this->assertSame(1.00, $result['dividend_paid']);
    }

    /**
     * Lintner partial adjustment at the speed the seed gives a smoothing payer: declared quarterly, the dividend
     * closes the share of its gap to target that Fama & Babiak (1968) measured in a year, not the 4-11% a year the
     * old aristocrat marker speeds closed.
     */
    public function testAPayerAtTheLintnerSpeedClosesTheCitedShareOfItsDividendGapInAYear(): void
    {
        $stock = new Stock();
        $stock->setTicker('PEER');
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed((string) FinancialConstants::LINTNER_QUARTERLY_ADJUSTMENT_SPEED);
        $stock->setLastDividend('0.10');
        $stock->setRetainedEarnings('50000000.00');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setRoicTtm('0.12');

        $engine = $this->buildEngineWith($this->debtEngineReturning($this->buildHealth()));
        for ($quarter = 0; $quarter < 4; $quarter++) {
            // The trailing year holds at $4.00 a share, so the target stands still while the dividend closes on it.
            $stock->setEarningsPerShare('4.00');
            $engine->allocateCapital($stock, 4.00, 1.00, 100.00, 1000000.0, new MacroStateDTO(corporateTaxRate: 0.21));
        }

        $target = 1.00 * $stock->getPolicyPayoutRatio();
        $closed = ((float) $stock->getLastDividend() - 0.10) / ($target - 0.10);
        $this->assertEqualsWithDelta(FinancialConstants::LINTNER_ANNUAL_ADJUSTMENT_SPEED, $closed, 0.002);
    }

    /**
     * Lintner (1956) and Fama & Babiak (1968) set the dividend against the year's earnings. A quarter earning three
     * times its run-rate leaves a trailing year that has not moved, so the dividend does not move either; a year
     * that has grown raises it.
     */
    public function testADividendIsSetAgainstTheYearsEarningsNotTheQuarters(): void
    {
        $pay = function (string $trailingNetIncome, float $annualizedQuarterEps): float {
            $stock = $this->buildDistributor('LINT');
            $stock->setTargetPayoutRatio('0.50');
            $stock->setDividendSpeed('1.00');
            $stock->setLastDividend('0.50');
            $stock->setTotalNetIncome($trailingNetIncome);

            return $this->buildEngineWith($this->debtEngineReturning($this->buildHealth()))
                ->allocateCapital($stock, $annualizedQuarterEps, 2.00, 10.00, 1000000.0, new MacroStateDTO(corporateTaxRate: 0.21))['dividend_paid'];
        };

        $steady = $pay('4000000.00', 4.00);
        $this->assertGreaterThan(0.0, $steady);
        $this->assertEqualsWithDelta($steady, $pay('4000000.00', 12.00), 1e-9, 'A strong quarter in an unchanged year is not a raise.');
        $this->assertGreaterThan($steady, $pay('6000000.00', 4.00), 'A year that has grown raises the dividend.');
    }

    /**
     * A firm paying $1.00 a quarter at 0.10 adjustment speed whose earnings fall to $1.00 a quarter, against a 30%
     * target payout; the trailing year earned $4.00 a share unless a loss year is given.
     *
     * @return array{0: array<string, mixed>, 1: Stock}
     */
    private function payDividendOnFallingEarnings(bool $aristocrat, DebtHealthDTO $health, string $roic = '0.12', string $trailingNetIncome = '4000000.00'): array
    {
        $stock = new Stock();
        $stock->setTicker($aristocrat ? 'ARIS' : 'PEER');
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.10');
        $stock->setLastDividend('1.00');
        $stock->setDividendAristocrat($aristocrat);
        $stock->setRetainedEarnings('50000000.00');
        $stock->setTotalRevenue('10000000.00');
        $stock->setOperatingMargin('0.20');
        $stock->setWholesaleDebt('10000000.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setRoicTtm($roic);
        $stock->setTotalNetIncome($trailingNetIncome);

        $result = $this->buildEngineWith($this->debtEngineReturning($health))
            ->allocateCapital($stock, 4.00, 1.00, 100.00, 1000000.0, new MacroStateDTO(corporateTaxRate: 0.21));

        return [$result, $stock];
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

    /**
     * Coverage, cost of capital and the hurdle that gate dividends, buybacks and borrowing are read off what
     * the firm earned over the last twelve months, as its lenders measure it (DebtEngine resolves that
     * window, and the reported margin before a first report). On the structural margin, which only
     * reinvestment decay moves, a firm in a margin collapse kept paying a dividend on coverage it no longer
     * had; on one annualized quarter the verdict flipped with the seasons.
     */
    public function testDistributionsAreGatedOnTheTrailingYearTheLendersRead(): void
    {
        $health = $this->buildHealth();
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);
        $stock = $this->buildDistributor('TTMD');

        $debtEngine = $this->createMock(DebtEngine::class);
        $debtEngine->expects($this->once())
            ->method('analyzeTrailingDebtHealth')
            ->with($this->identicalTo($stock), $this->identicalTo($macroState))
            ->willReturn($health);
        $debtEngine->expects($this->never())->method('analyzeDebtHealth');

        $this->buildEngineWith($debtEngine)->allocateCapital($stock, 4.00, 2.00, 10.00, 1000000.0, $macroState);
    }

    private function buildEngineWith(DebtEngine $debtEngine): CapitalAllocationEngine
    {
        return new CapitalAllocationEngine(
            $this->corporateLedgerServiceMock,
            $this->corporateMetricsMock,
            $debtEngine,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );
    }

    private function buildDistributor(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('Software - Infrastructure');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('10.00');
        $stock->setTotalEquity('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.00');
        $stock->setDividendSpeed('0.00');
        $stock->setLastDividend('0.00');
        $stock->setTotalRevenue('50000000.00');
        $stock->setRoicTtm('0.15');
        $stock->setWholesaleDebt('0.00');
        $stock->setCustomerDeposits('0.00');

        return $stock;
    }

    private function buildHealth(bool $hasLeverageHeadroom = true, float $interestCoverage = 5.0, float $wacc = 0.06): DebtHealthDTO
    {
        return new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: $interestCoverage,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: $wacc,
            costOfEquity: 0.08,
            leveredBeta: 1.0,
            rawMetrics: new DebtMetricsDTO(
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
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false,
            hasLeverageHeadroom: $hasLeverageHeadroom,
            netDebtToEbitda: $hasLeverageHeadroom ? 0.5 : 4.5,
            ebitdaCovenantLimit: 2.0
        );
    }

    /**
     * The restricted-payments clause that travels with every maintenance leverage covenant. This firm is
     * cash-rich and comfortably covered — nothing in the ICR or liquidity ladder would stop it — so the
     * covenant is the only thing that can, and a buyback here would retire the equity cushion sitting
     * underneath debt already too large for the cash flow supporting it.
     */
    public function testCovenantBreachBlocksTheBuybackTheCashPileWouldOtherwiseFund(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        $compliant = $this->buildDistributor('OKAY');
        $engine = $this->buildEngineWith($this->debtEngineReturning($this->buildHealth(true)));
        $allowed = $engine->allocateCapital($compliant, 4.00, 2.00, 10.00, 1000000.0, $macroState);

        $breached = $this->buildDistributor('BRCH');
        $engine = $this->buildEngineWith($this->debtEngineReturning($this->buildHealth(false)));
        $blocked = $engine->allocateCapital($breached, 4.00, 2.00, 10.00, 1000000.0, $macroState);

        $this->assertLessThan(1000000.0, $allowed['new_shares'], 'Control must actually repurchase, or the comparison proves nothing.');
        $this->assertSame(1000000.0, $blocked['new_shares'], 'A firm past its leverage covenant may not repurchase stock.');
    }

    /**
     * The dividend leg is a freeze, not a cut: lenders withhold consent for an INCREASE in distributions
     * while the firm is out of compliance, and falling earnings or a liquidity crisis own the collapse case.
     */
    public function testCovenantBreachFreezesTheDividendRatherThanCuttingIt(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        $build = function (string $ticker): Stock {
            $stock = $this->buildDistributor($ticker);
            $stock->setTargetPayoutRatio('0.50');
            $stock->setDividendSpeed('1.00');
            $stock->setLastDividend('0.20');
            $stock->setTotalNetIncome('4000000.00');

            return $stock;
        };

        $compliant = $build('RISE');
        $rising = $this->buildEngineWith($this->debtEngineReturning($this->buildHealth(true)))
            ->allocateCapital($compliant, 4.00, 2.00, 10.00, 1000000.0, $macroState);

        $breached = $build('FRZE');
        $frozen = $this->buildEngineWith($this->debtEngineReturning($this->buildHealth(false)))
            ->allocateCapital($breached, 4.00, 2.00, 10.00, 1000000.0, $macroState);

        $this->assertGreaterThan(0.20, $rising['dividend_paid'], 'Control must want to raise the dividend, or the freeze is untestable.');
        $this->assertSame(0.20, $frozen['dividend_paid'], 'A breach holds the distribution where it stands.');
        $this->assertGreaterThan(0.0, $frozen['dividend_paid'], 'A freeze is not a cut: falling earnings and a liquidity crisis own that case.');
    }

    private function debtEngineReturning(DebtHealthDTO $health): DebtEngine
    {
        $debtEngine = $this->createStub(DebtEngine::class);
        $debtEngine->method('analyzeDebtHealth')->willReturn($health);
        $debtEngine->method('analyzeTrailingDebtHealth')->willReturn($health);

        return $debtEngine;
    }

    /**
     * Partial adjustment toward a target capital ratio (Berger et al. 2008): the quarter's change, its retained
     * earnings included, closes the same share of the gap, compounding to the cited annual speed. At target the
     * bank returns exactly what it earned beyond holding the ratio; below target it keeps everything.
     */
    public function testACapitalSurplusClosesTheCitedShareOfTheGapEachYear(): void
    {
        $equity = 12.5;
        $assets = 100.0;
        for ($quarter = 0; $quarter < 4; $quarter++) {
            $opening = $equity / $assets;
            $equity += 0.4; // a quarter's retained earnings, held as cash
            $assets += 0.4;
            $repurchase = CapitalAllocationEngine::capitalTargetRepurchase($equity, $assets, 0.10, $opening);
            $equity -= $repurchase;
            $assets -= $repurchase;
        }
        $this->assertEqualsWithDelta(0.10 + (0.025 * (1.0 - CapitalAllocationEngine::CAPITAL_TARGET_ADJUSTMENT_SPEED)), $equity / $assets, 1e-12);

        $atTarget = CapitalAllocationEngine::capitalTargetRepurchase(10.4, 100.4, 0.10, 0.10);
        $this->assertEqualsWithDelta(0.10, (10.4 - $atTarget) / (100.4 - $atTarget), 1e-12, 'Holding the target, not drifting above it.');
        $this->assertSame(0.0, CapitalAllocationEngine::capitalTargetRepurchase(8.4, 100.4, 0.10, 0.08), 'Below target the bank rebuilds by retaining.');
    }

    /**
     * Borrowed cash lands on the asset side, so the most an institution can borrow at its target ratio is what
     * carries equity over assets exactly onto the target, and nothing once it is already there or below it.
     */
    public function testBorrowingCapacityStopsAtTheTargetCapitalRatio(): void
    {
        $capacity = CapitalAllocationEngine::capitalTargetBorrowingCapacity(12.5, 100.0, 0.10);
        $this->assertEqualsWithDelta(25.0, $capacity, 1e-12);
        $this->assertEqualsWithDelta(0.10, 12.5 / (100.0 + $capacity), 1e-12);

        $this->assertSame(0.0, CapitalAllocationEngine::capitalTargetBorrowingCapacity(10.0, 100.0, 0.10), 'At target there is no room.');
        $this->assertSame(0.0, CapitalAllocationEngine::capitalTargetBorrowingCapacity(8.0, 100.0, 0.10), 'Below target it borrows nothing.');
    }

    /**
     * Partial adjustment toward a target leverage (Flannery & Rangan 2006): each quarter's debt-financed repurchase
     * closes the same share of the gap, retained earnings included, compounding to the cited annual speed. At or
     * above target the firm recapitalizes nothing.
     */
    public function testALeverageGapClosesTheCitedShareOfItEachYear(): void
    {
        $equity = 100.0;
        $debt = 20.0;
        for ($quarter = 0; $quarter < 4; $quarter++) {
            $opening = $debt / $equity;
            $equity += 3.0; // a quarter's retained earnings
            $repurchase = CapitalAllocationEngine::leverageTargetRepurchase($equity, $debt, 0.75, $opening);
            $debt += $repurchase;
            $equity -= $repurchase;
        }
        $this->assertEqualsWithDelta(0.75 - (0.55 * (1.0 - CapitalAllocationEngine::LEVERAGE_TARGET_ADJUSTMENT_SPEED)), $debt / $equity, 1e-12);

        $this->assertSame(0.0, CapitalAllocationEngine::leverageTargetRepurchase(100.0, 75.0, 0.75, 0.75), 'At target there is nothing to recapitalize.');
        $this->assertSame(0.0, CapitalAllocationEngine::leverageTargetRepurchase(100.0, 90.0, 0.75, 0.90), 'Above target the firm does not recapitalize.');
    }

    /**
     * A leveraged recapitalization exchanges debt for equity (Denis & Denis 1993): the firm borrows what its excess
     * cash cannot pay of the quarter's repurchase and spends it on that repurchase. Borrowing the cash to hold it
     * left the leverage where it was and the proceeds for the capex gate to spend on plant.
     */
    public function testAnUnderLeveredFirmBorrowsOnlyToRetireTheEquityItsDebtReplaces(): void
    {
        [$result, $borrowed, $repurchase] = $this->recapitalize(hasLeverageHeadroom: true, excessCashInRepurchases: 0.0);

        $this->assertCount(1, $borrowed);
        $this->assertEqualsWithDelta($repurchase, $borrowed[0], 1e-6, 'With no excess cash the whole repurchase is borrowed.');
        $this->assertEqualsWithDelta($repurchase, $result['total_cash_spent'], 50.0, 'Every dollar borrowed retires stock, to the nearest share.');

        $endRatio = 0.20 + ((1.0 - ((1.0 - CapitalAllocationEngine::LEVERAGE_TARGET_ADJUSTMENT_SPEED) ** 0.25)) * (0.75 - 0.20));
        $this->assertEqualsWithDelta($endRatio, (20_000_000.0 + $borrowed[0]) / (100_000_000.0 - $result['total_cash_spent']), 1e-5, 'The swap ends the quarter on its partial adjustment.');
    }

    /** Excess cash funds the recapitalization first; debt is raised only for what it cannot cover. */
    public function testExcessCashFundsTheRecapitalizationBeforeAnyBorrowing(): void
    {
        [$result, $borrowed, $repurchase] = $this->recapitalize(hasLeverageHeadroom: true, excessCashInRepurchases: 3.0);

        $this->assertSame([], $borrowed);
        $this->assertGreaterThanOrEqual($repurchase - 50.0, $result['total_cash_spent'], 'The repurchase still happens, out of cash.');
    }

    /**
     * Book leverage moves too slowly to register an earnings collapse, so a firm past its leverage covenant can still
     * read as under-levered. The restricted-payments clause stops the repurchase, and with it the borrowing: this
     * differs from the borrowing case above only in the covenant flag.
     */
    public function testACovenantBreachStopsTheRecapitalizationTheEquityRatioWouldWaveThrough(): void
    {
        [$result, $borrowed] = $this->recapitalize(hasLeverageHeadroom: false, excessCashInRepurchases: 0.0);

        $this->assertSame([], $borrowed, 'A firm past its leverage covenant must not lever up to recapitalize.');
        $this->assertSame(10_000_000.0, $result['new_shares']);
    }

    /**
     * A treasury below its operating floor is refilled before any recapitalization: borrowing into it plugged the hole
     * and the repurchase it was raised for never happened.
     */
    public function testAFirmShortOfItsOperatingCashDoesNotRecapitalize(): void
    {
        [$result, $borrowed] = $this->recapitalize(hasLeverageHeadroom: true, excessCashInRepurchases: -0.05);

        $this->assertSame([], $borrowed);
        $this->assertSame(10_000_000.0, $result['new_shares']);
    }

    /**
     * An operating company at 0.2x debt to equity against a 0.75x target, holding its target cash plus the given
     * multiple of the quarter's recapitalization repurchase.
     *
     * @return array{0: array<string, mixed>, 1: list<float>, 2: float} The allocation, each amount borrowed, and the repurchase the gap calls for.
     */
    private function recapitalize(bool $hasLeverageHeadroom, float $excessCashInRepurchases): array
    {
        $stock = new Stock();
        $stock->setTicker('RCAP');
        $stock->setIndustry('Integrated Freight & Logistics');
        $stock->setSharesOutstanding('10000000');
        $stock->setPrice('50.00');
        $stock->setTotalEquity('100000000');
        $stock->setWholesaleDebt('20000000');
        $stock->setCustomerDeposits('0.00');
        $stock->setTargetPayoutRatio('0.00');
        $stock->setDividendSpeed('0.00');
        $stock->setLastDividend('0.00');
        $stock->setTotalRevenue('100000000.00');
        $stock->setOperatingMargin('0.50');
        $stock->setRoicTtm('0.12');

        $strategy = \App\Data\Sectors::strategyFor('Integrated Freight & Logistics');
        $targetCash = $stock->getManagementProfile()->appliedTargetCash($strategy->calculateTargetOperatingCash(5000000.0, 0.0, 20_000_000.0));
        $repurchase = CapitalAllocationEngine::leverageTargetRepurchase(100_000_000.0, 20_000_000.0, 0.75, 0.20);
        $stock->setCorporateTreasury((string) ($targetCash + ($excessCashInRepurchases * $repurchase)));

        $health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 50.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: new DebtMetricsDTO(
                interestExpense: 1_000_000.0,
                blendedRate: 0.05,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.02,
                currentMarketRate: 0.05,
                wholesaleRate: 0.05,
                ebit: 50_000_000.0,
                revenue: 100_000_000.0,
                depreciation: 0.0,
                ebitda: 50_000_000.0
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true,
            hasLeverageHeadroom: $hasLeverageHeadroom,
            netDebtToEbitda: $hasLeverageHeadroom ? 0.4 : 4.5,
            ebitdaCovenantLimit: 3.5,
            debtToEquity: 0.20,
            leverageTarget: 0.75
        );

        $borrowed = [];
        $debtEngine = $this->createMock(DebtEngine::class);
        $debtEngine->method('analyzeTrailingDebtHealth')->willReturn($health);
        $debtEngine->method('issueDebt')->willReturnCallback(function (Stock $issuer, float $amount) use (&$borrowed): void {
            $borrowed[] = $amount;
        });

        $result = $this->buildEngineWith($debtEngine)->allocateCapital($stock, 0.0, 0.0, 50.0, 10_000_000.0, new MacroStateDTO(corporateTaxRate: 0.21));

        return [$result, $borrowed, $repurchase];
    }

    /**
     * Banks lend out their cash, so the cash-pile tests never see a bank's surplus; its capital ratio does. The
     * same balance sheet above target is returned by the bank that manages toward one and kept by one that does not.
     */
    public function testABankAboveItsCapitalTargetBuysBackWhatAnUntargetedBankKeeps(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21);

        $targeted = $this->buildLenderAboveTarget('RIVR');
        $untargeted = $this->buildLenderAboveTarget('NO_TARGET_BANK');
        $returned = $this->engine->allocateCapital($targeted, 1.20, 0.30, 15.00, 1000000000.0, $macroState, 300000000.0);
        $kept = $this->engine->allocateCapital($untargeted, 1.20, 0.30, 15.00, 1000000000.0, $macroState, 300000000.0);

        $this->assertSame(1000000000.0, $kept['new_shares'], 'Control: nothing else in this balance sheet triggers a repurchase.');
        $this->assertLessThan(1000000000.0, $returned['new_shares'], 'RIVR sits above its 11.1% target and returns the surplus.');
    }

    private function buildLenderAboveTarget(string $ticker): Stock
    {
        $bank = new Stock();
        $bank->setTicker($ticker);
        $bank->setIndustry('Banks - Regional');
        $bank->setSharesOutstanding('1000000000');
        $bank->setPrice('15.00');
        $bank->setTotalEquity('12500000000'); // 12.5% of assets
        $bank->setCustomerDeposits('82500000000');
        $bank->setWholesaleDebt('5000000000');
        $bank->setCorporateTreasury('10000000000');
        $bank->setEarningAssets('90000000000');
        $bank->setCreditLossAllowance('0');
        $bank->setTargetPayoutRatio('0.00');
        $bank->setDividendSpeed('0.00');
        $bank->setLastDividend('0.00');
        $bank->setRetainedEarnings('5000000000.00');
        $bank->setTotalRevenue('4000000000.00');
        $bank->setOperatingMargin('0.30');
        $bank->setRoeTtm('0.05');

        return $bank;
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
        $sharesRepurchased = 1000000.0 - $result['new_shares'];

        $buybackEvent = null;
        foreach ($result['events'] as $event) {
            if (str_contains($event['description'], 'Bought back')) {
                $buybackEvent = $event;
                break;
            }
        }

        $this->assertNotNull($buybackEvent);
        // The price is not shocked by the report: the repurchase is queued as flow and moves the price
        // through the same impact channel as any other buyer, at the 10b-18 pace, on the ticker.
        $this->assertSame(0.0, $buybackEvent['shock']);
        $this->assertEqualsWithDelta($sharesRepurchased, $stock->getCorporateFlowBacklog(), 1e-6);
    }
}

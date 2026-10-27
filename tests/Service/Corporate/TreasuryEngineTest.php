<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\ManagementStyle;
use App\DTO\CapitalAllocationContext;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MaturityRollDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\InvestmentBankBusinessModel;
use App\Service\Model\Sector\ShadowBankBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Tests\Support\BalanceSheetAssertions;
use App\Tests\Support\DebtHealthBuilder;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TreasuryEngineTest extends TestCase
{
    use BalanceSheetAssertions;

    private CorporateMetrics&Stub $corporateMetrics;
    private DebtEngine&MockObject $debtEngine;
    private CapExEngine&Stub $capExEngine;
    private MathUtility $mathUtility;
    private TreasuryEngine $treasuryEngine;

    protected function setUp(): void
    {
        $this->corporateMetrics = $this->createStub(CorporateMetrics::class);
        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->capExEngine = $this->createStub(CapExEngine::class);
        $this->mathUtility = new MathUtility();
        // Seeded after the mocks: PHPUnit draws from the same generator to name a mock class the first time it builds
        // one, so a seed set before them gave each test a sequence that depended on which tests had run first.
        mt_srand(42);

        // These tests are not about the maturity wall, so no principal comes due in them.
        $this->debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO());

        $this->treasuryEngine = new TreasuryEngine(
            $this->corporateMetrics,
            $this->debtEngine,
            $this->capExEngine,
            $this->mathUtility,
        );
    }

    public function testUnderleveragedInvestmentBankIssuesDebtEvenWhenMarginalReturnIsBelowHurdle(): void
    {
        $stock = new Stock();
        $stock->setTicker('IBNK');
        $stock->setIndustry('Investment Banking');
        $stock->setTotalEquity('1000000000.00'); // $1B Equity
        $stock->setWholesaleDebt('0.00'); // $0 debt -> severely under-leveraged
        $stock->setCorporateTreasury('50000000.00');
        $stock->setCreditSpread('0.02');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000,
        );

        $strategy = new InvestmentBankBusinessModel();
        $ctx->strategy = $strategy;
        $ctx->businessModel = 'investment_bank';
        $ctx->newTreasury = 50_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 30_000_000.0,
            revenue: 100_000_000.0,
            depreciation: 2_000_000.0,
            ebitda: 32_000_000.0,
        );

        // Under-leveraged is TRUE, but severe saturation penalty makes marginal return < hurdle
        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.04)
            ->withCoverage(10.0)
            ->withDebtTolerance(8.0)
            ->withMetrics($debtMetrics)
            ->underLeveraged()
            ->build();

        // Saturation penalty drops marginal return below hurdle rate
        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02); // 2% < 10% Hurdle

        $this->debtEngine->expects($this->once())
            ->method('issueDebt')
            ->with($stock, $this->greaterThan(0.0), 0.05);

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertTrue($ctx->debtActionTaken);
        $this->assertTrue($ctx->recapActionTaken);
        $this->assertGreaterThan(0.0, $ctx->debtIssued);
    }

    /**
     * A dealer's return is struck on its equity, so the debt a 20% return invites past its capital target earns
     * nothing and only adds leverage. The same book, return and appetite borrow when the firm has no target and
     * stay put when it already sits at the ratio it steers to.
     */
    public function testADealerAtItsCapitalTargetDoesNotBorrowPastIt(): void
    {
        $issued = [];
        $this->debtEngine->method('issueDebt')->willReturnCallback(
            static function (Stock $stock, float $amount) use (&$issued): void {
                $issued[$stock->getTicker()] = $amount;
            },
        );
        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(4_187_500_000.0);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.20);

        foreach (['PERE', 'IBNK'] as $ticker) {
            $this->treasuryEngine->executeCorporateStrategy($this->dealerAtSeededLeverage($ticker));
        }

        $this->assertGreaterThan(0.0, $issued['IBNK'] ?? 0.0, 'Control: without a target the return pulls the dealer into the bond market.');
        // PERE opens at its 21.05% target: whatever it borrows still leaves 1bn of equity on at least that share of
        // 4.75bn of assets.
        $this->assertGreaterThanOrEqual(0.2105, 1_000_000_000.0 / (4_750_000_000.0 + ($issued['PERE'] ?? 0.0)));
    }

    /**
     * PERE's seeded book at a thousandth of the scale: 1bn of equity on 3.75bn of wholesale funding, 15% of it held in
     * cash.
     */
    private function dealerAtSeededLeverage(string $ticker): CapitalAllocationContext
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('Investment Banking');
        $stock->setManagementStyle(ManagementStyle::EmpireBuilder);
        $stock->setManagementIntensity(\App\Data\Company\ManagementProfile::MAX_INTENSITY);
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('3750000000.00');
        $stock->setCorporateTreasury('562500000.00');
        $stock->setEarningAssets('4187500000.00');
        $stock->setCreditSpread('0.015');

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: MacroStateDTO::fromArray(['policy_rate_ema' => 0.04, 'yield_5y_ema' => 0.045]),
            actualAnnualEps: 2.5,
            quarterlyFcfPerShare: 0.6,
            currentPrice: 40.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 62_500_000,
        );
        $ctx->strategy = new InvestmentBankBusinessModel();
        $ctx->businessModel = 'investment_bank';
        $ctx->newTreasury = 562_500_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 3_750_000_000.0;
        $ctx->customerDeposits = 0.0;
        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.04)
            ->withCoverage(10.0)
            ->withDebtTolerance(8.0)
            ->withMetrics(new DebtMetricsDTO(
                interestExpense: 190_000_000.0,
                blendedRate: 0.05,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.015,
                currentMarketRate: 0.05,
                wholesaleRate: 0.05,
                ebit: 330_000_000.0,
                revenue: 750_000_000.0,
                depreciation: 15_000_000.0,
                ebitda: 345_000_000.0,
            ))
            ->build();

        return $ctx;
    }

    public function testCommercialBankDoesNotUseWholesaleDebtForUnderleveraged(): void
    {
        $stock = new Stock();
        $stock->setTicker('JPM');
        $stock->setIndustry('Commercial Banking');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('50000000.00');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000,
        );

        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->newTreasury = 50_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 5_000_000_000.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 10_000_000.0,
            blendedRate: 0.04,
            historicalFixedRate: 0.04,
            dynamicSpread: 0.01,
            currentMarketRate: 0.04,
            wholesaleRate: 0.04,
            ebit: 30_000_000.0,
            revenue: 100_000_000.0,
            depreciation: 2_000_000.0,
            ebitda: 32_000_000.0,
        );

        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.04, 0.04, 0.04)
            ->withCoverage(3.0)
            ->withCostOfCapital(0.06, 0.10)
            ->withMetrics($debtMetrics)
            ->underLeveraged()
            ->build();

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(6_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02);

        // Commercial bank should NOT issue wholesale debt via underleveraged path
        $this->debtEngine->expects($this->never())->method('issueDebt');

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertFalse($ctx->debtActionTaken);
    }

    /**
     * A deposit-funded fortress bank holding exactly the cash it deploys down to: a lender measures its liquidity
     * shortfall against that same target, so the manager's cash bias does not make it borrow.
     *
     * @return array{CapitalAllocationContext, TreasuryEngine}
     */
    private function fortressLenderAtItsDeployTarget(float $treasury): array
    {
        $stock = new Stock();
        $stock->setTicker('FORT');
        $stock->setIndustry('Banks - Diversified');
        $stock->setTotalEquity('1000000000.00');
        $stock->setEarningAssets('5400000000.00');
        $stock->setCustomerDeposits('5000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury((string) $treasury);
        $stock->setManagementStyle(ManagementStyle::Fortress);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: MacroStateDTO::fromArray(['policy_rate_ema' => 0.04, 'yield_5y_ema' => 0.045]),
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000,
        );
        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->newTreasury = $treasury;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 5_000_000_000.0;
        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.04, 0.04, 0.04)
            ->withCoverage(3.0)
            ->withDebtTolerance(15.0)
            ->withCostOfCapital(0.06, 0.10)
            ->withMetrics(new DebtMetricsDTO(
                interestExpense: 10_000_000.0,
                blendedRate: 0.04,
                historicalFixedRate: 0.04,
                dynamicSpread: 0.01,
                currentMarketRate: 0.04,
                wholesaleRate: 0.04,
                ebit: 30_000_000.0,
                revenue: 100_000_000.0,
                depreciation: 2_000_000.0,
                ebitda: 32_000_000.0,
            ))
            ->build();

        // The return clears the hurdle and any draw with a chance fires, so only the bank's appetite decides.
        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(6_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.0);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.20);
        $draws = new ScriptedDraws(checkProbability: static fn(float $probability): bool => $probability > 0.0);

        return [$ctx, new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $draws)];
    }

    /**
     * Its deposits fund it: at the target it deploys down to (10% of deposits plus the 20% buffer, $600M), it borrows
     * nothing. Measured against the fortress's own 1.75x cash target it was always short, and borrowed every quarter.
     */
    public function testAFortressLenderDoesNotBorrowToRefillTheCashItDeploys(): void
    {
        [$ctx, $engine] = $this->fortressLenderAtItsDeployTarget(600_000_000.0);
        $this->debtEngine->expects($this->never())->method('issueDebt');

        $engine->executeCorporateStrategy($ctx);

        $this->assertFalse($ctx->debtActionTaken);
    }

    /** Short of the same target, the bank still borrows to restore its liquidity. */
    public function testALenderShortOfItsDeployTargetStillBorrows(): void
    {
        [$ctx, $engine] = $this->fortressLenderAtItsDeployTarget(300_000_000.0);
        $this->debtEngine->expects($this->once())->method('issueDebt');

        $engine->executeCorporateStrategy($ctx);

        $this->assertTrue($ctx->debtActionTaken);
    }

    public function testFinancialInstitutionsProtectedFromCashHoarderDeleveragingSweep(): void
    {
        $stock = new Stock();
        $stock->setTicker('PERE');
        $stock->setIndustry('Investment Banking');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('4000000000.00'); // $4B wholesale debt (4x leverage, healthy for IB)
        $stock->setCorporateTreasury('1000000000.00'); // $1B treasury (trading float)
        $stock->setRetainedEarnings('500000000.00');
        $stock->setCreditSpread('0.02');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000,
        );

        $ctx->strategy = new InvestmentBankBusinessModel();
        $ctx->businessModel = 'investment_bank';
        $ctx->newTreasury = 1_000_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 4_000_000_000.0;
        $ctx->customerDeposits = 0.0;
        $ctx->debtActionTaken = false;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 50_000_000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 100_000_000.0,
            revenue: 300_000_000.0,
            depreciation: 5_000_000.0,
            ebitda: 105_000_000.0,
        );

        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.04)
            ->withCoverage(2.0)
            ->withDebtTolerance(0.8) // industrial tolerance
            ->withCostOfCapital(0.06, 0.10)
            ->withMetrics($debtMetrics)
            ->build();

        $this->treasuryEngine->finalizeLiquidity($ctx);

        // Wholesale debt should NOT be swept down
        $this->assertEquals(4_000_000_000.0, (float) $stock->getWholesaleDebt());
    }

    public function testUnderleveragedShadowBankIssuesDebt(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHAD');
        $stock->setIndustry('Mortgage Finance');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('50000000.00');
        $stock->setCreditSpread('0.02');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000,
        );

        $ctx->strategy = new ShadowBankBusinessModel();
        $ctx->businessModel = 'shadow_bank';
        $ctx->newTreasury = 50_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 30_000_000.0,
            revenue: 100_000_000.0,
            depreciation: 2_000_000.0,
            ebitda: 32_000_000.0,
        );

        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.04)
            ->withCoverage(10.0)
            ->withDebtTolerance(8.0)
            ->withMetrics($debtMetrics)
            ->underLeveraged()
            ->build();

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02);

        $this->debtEngine->expects($this->once())
            ->method('issueDebt')
            ->with($stock, $this->greaterThan(0.0), 0.05);

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertTrue($ctx->debtActionTaken);
        $this->assertTrue($ctx->recapActionTaken);
        $this->assertGreaterThan(0.0, $ctx->debtIssued);
    }

    /**
     * An under-levered operating company recapitalizes by swapping debt for equity in CapitalAllocationEngine.
     * Borrowing here only parked the proceeds in its treasury, for the capex gate to spend on plant later.
     */
    public function testAnUnderLeveredOperatingCompanyBorrowsNothingIntoItsTreasury(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORP');
        $stock->setIndustry('Technology');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('50000000.00');
        $stock->setCreditSpread('0.02');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000,
        );

        $ctx->strategy = new StandardCorporateBusinessModel();
        $ctx->businessModel = 'standard_corporate';
        $ctx->newTreasury = 50_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 50_000_000.0,
            revenue: 200_000_000.0,
            depreciation: 5_000_000.0,
            ebitda: 55_000_000.0,
        );

        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.04)
            ->withCoverage(10.0)
            ->withDebtTolerance(1.5)
            ->withMetrics($debtMetrics)
            ->underLeveraged()
            ->build();

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02);

        $this->debtEngine->expects($this->never())->method('issueDebt');

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertFalse($ctx->recapActionTaken);
        $this->assertSame(0.0, $ctx->debtIssued);
        $this->assertSame(50_000_000.0, $ctx->newTreasury, 'No borrowed cash lands in the treasury.');
    }

    /**
     * ASC 718: stock-based compensation is an expense inside net income whose credit side is additional
     * paid-in capital. Equity must roll forward net of it, otherwise book value falls every quarter by a
     * charge that never left the company.
     */
    public function testStockCompensationIsCreditedBackToEquityAsPaidInCapital(): void
    {
        $stock = $this->createSolventCorporate();
        $ctx = $this->createAllocationContext($stock, stockCompensation: 40_000_000.0);

        $this->treasuryEngine->finalizeLiquidity($ctx);

        // Opening 1B + net income 100M + SBC 40M - dividends 0 - buybacks 0
        $this->assertEqualsWithDelta(1_140_000_000.0, (float) $stock->getTotalEquity(), 1.0);
    }

    /**
     * The credit is paid-in capital, not retained earnings: only income less distributions reaches RE.
     */
    public function testStockCompensationDoesNotInflateRetainedEarnings(): void
    {
        $stock = $this->createSolventCorporate();
        $ctx = $this->createAllocationContext($stock, stockCompensation: 40_000_000.0);

        $this->treasuryEngine->finalizeLiquidity($ctx);

        // Opening 500M + net income 100M, with no dividends paid
        $this->assertEqualsWithDelta(600_000_000.0, (float) $stock->getRetainedEarnings(), 1.0);
    }

    /**
     * A firm that grants no equity compensation must roll forward exactly as before this rule existed.
     */
    public function testEquityRollForwardUnchangedWhenThereIsNoStockCompensation(): void
    {
        $stock = $this->createSolventCorporate();
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);

        $this->treasuryEngine->finalizeLiquidity($ctx);

        $this->assertEqualsWithDelta(1_100_000_000.0, (float) $stock->getTotalEquity(), 1.0);
    }

    /**
     * The earnings engine writes the allocation's share count back to the stock after the treasury has run.
     * An offering that diluted the stock but left that count untouched was undone on the way out: the cash
     * and the equity stayed, the shares reverted, and every secondary was free money.
     */
    public function testEquityIssuanceReachesTheShareCountTheEngineWritesBack(): void
    {
        // A firm at seventy-five times earnings and thirty times book with returns well over its hurdle: the
        // bubble condition (2.5x a fair-value multiple that now carries the cycle's growth, ~20x here), and
        // the draw is pinned so the offering is certain to execute.
        $mathUtility = new ScriptedDraws(generateUniform: 0.0);
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $mathUtility);

        $stock = $this->createSolventCorporate();
        $stock->setSharesOutstanding('100000000');
        $stock->setRoicTtm('0.30');
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 300.0);
        $ctx->newShares = 100_000_000.0; // what the buyback step leaves when nothing is repurchased

        $engine->finalizeLiquidity($ctx);

        $this->assertGreaterThan(0.0, $ctx->equityRaised, 'the bubble offering must execute for this to test anything');
        // shares go out at the offering discount
        $sharesIssued = $ctx->equityRaised / (300.0 * (1.0 - TreasuryEngine::SEASONED_EQUITY_OFFER_DISCOUNT));
        $this->assertEqualsWithDelta(-$sharesIssued, $stock->getCorporateFlowBacklog(), 1.0, 'the placed stock is queued to flow back onto the tape');
        $this->assertEqualsWithDelta(100_000_000.0 + $sharesIssued, (float) $stock->getSharesOutstanding(), 1.0);
        $this->assertEqualsWithDelta(
            (float) $stock->getSharesOutstanding(),
            $ctx->newShares,
            1e-6,
            'the share count the engine writes back has to carry the dilution, or the raise is booked with no shares behind it',
        );

        // The offering's announcement return is the measured one, whatever the valuation that prompted it.
        $offerings = array_values(array_filter($ctx->events, static fn(array $e): bool => str_contains($e['description'] ?? '', 'offering')));
        $this->assertCount(1, $offerings);
        $this->assertSame(TreasuryEngine::SEASONED_EQUITY_OFFERING_ANNOUNCEMENT_PCT, $offerings[0]['shock'] ?? null);
    }

    /**
     * A loss quarter does not make a fairly priced stock rich: the board judges the price on the earnings its multiple
     * is struck on, which one quarter's loss barely moves, so it sells no stock into its own bad news.
     */
    public function testALossQuarterAtAFairPriceSellsNoStock(): void
    {
        $raise = function (float $price): float {
            $mathUtility = new ScriptedDraws(generateUniform: 0.0);
            $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $mathUtility);

            $stock = $this->createSolventCorporate();
            $stock->setSharesOutstanding('100000000');
            // It files a 30% return and was seeded to earn it, so the board values it there.
            $stock->setRoicTtm('0.30');
            $stock->setCurrentRoic('0.30');
            $stock->setBaselineRoic('0.30');
            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: $price, actualAnnualEps: -1.0);
            $ctx->newShares = 100_000_000.0;

            $engine->finalizeLiquidity($ctx);

            return $ctx->equityRaised;
        };

        $this->assertSame(0.0, $raise(50.0), 'A loss quarter at a fair price is no bubble.');
        $this->assertGreaterThan(0.0, $raise(300.0), 'A price far above the multiple still is, whatever the quarter.');
    }

    /**
     * An institution steering its capital to a target sells no stock into a premium valuation while it holds that
     * target, since the capital would leave again as the repurchase that returns the surplus; below its target it
     * may.
     */
    public function testAnInstitutionHoldingItsCapitalTargetSellsNoStockIntoAPremium(): void
    {
        $raise = function (float $targetShareOfRatio): float {
            $mathUtility = new ScriptedDraws(generateUniform: 0.0);
            $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $mathUtility);

            $stock = $this->createSolventCorporate();
            $stock->setSharesOutstanding('100000000');
            $stock->setRoicTtm('0.30');
            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 300.0);
            $ctx->strategy = new class ($targetShareOfRatio * (float) $stock->getTotalEquity() / $stock->getTotalAssets()) extends StandardCorporateBusinessModel {
                public function __construct(private readonly float $target) {}

                public function getTargetCapitalRatio(Stock $stock, ?MacroStateDTO $macroState = null): float
                {
                    return $this->target;
                }
            };
            $ctx->newShares = 100_000_000.0;

            $engine->finalizeLiquidity($ctx);

            return $ctx->equityRaised;
        };

        $this->assertSame(0.0, $raise(0.8), 'At its target, the capital raised would only be handed back.');
        $this->assertGreaterThan(0.0, $raise(1.2), 'Below its target it may sell into the premium.');
    }

    /**
     * A firm shut out of the bond market repays maturing principal out of cash. The principal genuinely
     * leaves the balance sheet, which is the whole difference between a maturity ladder and a coupon reset.
     */
    public function testRefusedRefinancingRepaysPrincipalFromCash(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 1_000_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 300_000_000.0, unfundedShortfall: 0.0),
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($ctx->refinancingRefused);
        $this->assertEqualsWithDelta(300_000_000.0, $ctx->principalRepaid, 1.0);
        $this->assertFalse($stock->isPaymentDefault(), 'a firm that pays in full has not defaulted');
    }

    /**
     * A maturity past the committed line is not a default while anyone will still lend.
     *
     * The fixture's revolver is 5x a $30M cash floor, so it takes $150M of the $290M shortfall. The rest is
     * raised as uncommitted emergency paper, because a firm that can borrow at a penalty rate for working
     * capital is not a firm that defaults on its bonds the same afternoon. Booking the shortfall straight to
     * an event of default while the overdraft leg beside it drew on an UNCAPPED emergency facility left the
     * contractual claim as the only one in the capital structure that could go short.
     */
    public function testMaturityBeyondTheCommitmentIsCoveredByEmergencyPaperWhileTheFirmCanBorrow(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        // Price zero: there is no equity market to rescue it either.
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0),
        );
        $this->debtEngine->method('issueDebt')->willReturnCallback(
            static function (Stock $target, float $amount): void {
                $target->setWholesaleDebt((string) ((float) $target->getWholesaleDebt() + $amount));
            },
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertFalse($stock->isPaymentDefault(), 'a shortfall the emergency market funded is not a default');
        $this->assertSame(0, $stock->getQuartersInDefault());
        $this->assertEqualsWithDelta(0.0, $ctx->unfundedMaturity, 1.0, 'the whole maturity was funded');
        $this->assertEqualsWithDelta(300_000_000.0, $ctx->principalRepaid, 1.0, 'cash, the commitment and emergency paper all reached the bondholders');
        $this->assertEqualsWithDelta(150_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the committed line went first');
        $this->assertTrue($ctx->failedEmergencyBorrow, 'borrowing past the commitment is still a liquidity failure');
    }

    /**
     * When no market will lend at any price, the maturity really is an event of default -- and it is a
     * curable one. The firm is flagged and its grace clock starts; FailureSweep no longer liquidates on it.
     */
    public function testMaturityBeyondTheCommitmentDefaultsWhenNoMarketWillLend(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;
        $ctx->health = $this->healthThatCannotIssue($ctx->health);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0),
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($stock->isPaymentDefault(), 'a maturity nobody will fund is an event of default');
        $this->assertSame(1, $stock->getQuartersInDefault(), 'the grace clock starts at one');

        // Distress is news, not a scripted price move: fair value already carries it through leverage and the cost of
        // equity.
        $this->assertNotEmpty($ctx->events);
        foreach ($ctx->events as $event) {
            $this->assertSame(0.0, (float) ($event['shock'] ?? 0.0), 'A distress event moved the price: ' . ($event['description'] ?? ''));
        }
        $this->assertEqualsWithDelta(140_000_000.0, $ctx->unfundedMaturity, 1.0, 'only the part the revolver could not cover is unfunded');
        $this->assertEqualsWithDelta(140_000_000.0, (float) $stock->getPrincipalArrears(), 1.0, 'and it stays due next quarter');
        $this->assertEqualsWithDelta(160_000_000.0, $ctx->principalRepaid, 1.0, 'cash plus the full commitment went to the bondholders');
        $this->assertGreaterThan(0.0, (float) $stock->getTotalEquity(), 'the firm is still notionally solvent');
    }

    /**
     * The missed payment is an event, not a state of nature. A firm that finds the money the following
     * quarter is current again, and the flag and its grace clock both clear. Nothing in the engine used to
     * clear this flag at all, which made one dollar of shortfall permanent.
     */
    public function testAMissedMaturityCuredTheFollowingQuarterClearsTheDefault(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;
        $ctx->health = $this->healthThatCannotIssue($ctx->health);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0),
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);
        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($stock->isPaymentDefault());

        // Next quarter the market reopens and rolls the notes.
        $cured = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $cured->wholesaleDebt = (float) $stock->getWholesaleDebt();
        $cured->newTreasury = 500_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO(maturingPrincipal: 0.0, refinanced: true));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);
        $engine->finalizeLiquidity($cured);

        $this->assertFalse($stock->isPaymentDefault(), 'a funded quarter cures the default');
        $this->assertSame(0, $stock->getQuartersInDefault(), 'and resets the grace clock');
        $this->assertSame(0.0, (float) $stock->getPrincipalArrears(), 'and nothing is left overdue');
    }

    /**
     * Debt overhang (Myers 1977): rescue equity is sold only while the firm's assets still cover the debt
     * ahead of the new shares. Below that, a new dollar goes to the creditors and nobody subscribes, so the
     * missed maturity stands and the firm restructures, rather than selling stock into the hole every quarter
     * until its shares have been diluted hundreds of times over.
     */
    public function testRescueEquityIsSoldOnlyWhileTheGoingConcernCoversItsDebt(): void
    {
        foreach (['solvent' => 3_010_000_000.0, 'insolvent' => 510_000_000.0] as $case => $assetValue) {
            $stock = $this->createSolventCorporate();
            $stock->setWholesaleDebt('2000000000.00');
            $stock->setSharesOutstanding('100000000');

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 50.0);
            $ctx->wholesaleDebt = 2_000_000_000.0;
            $ctx->newTreasury = 10_000_000.0;
            $ctx->health = $this->healthThatCannotIssue($ctx->health);

            $debtEngine = $this->createMock(DebtEngine::class);
            $debtEngine->method('rollMaturities')->willReturn(
                new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0),
            );
            $debtEngine->method('assessGoingConcern')->willReturn(new \App\DTO\GoingConcernDTO(
                trailingEbit: 150_000_000.0,
                trailingEbitda: 170_000_000.0,
                assetValue: $assetValue,
                cash: 10_000_000.0,
                claims: 2_000_000_000.0,
            ));
            // The subscription draw always clears, so the solvency test is the only thing that can refuse it.
            $mathUtility = new ScriptedDraws(generateUniform: 0.0);

            (new TreasuryEngine($this->corporateMetrics, $debtEngine, $this->capExEngine, $mathUtility))->finalizeLiquidity($ctx);

            if ($case === 'solvent') {
                $this->assertGreaterThan(0.0, $ctx->equityRaised, 'a solvent firm can still sell rescue equity');
                $this->assertFalse($stock->isPaymentDefault(), 'and the raise funds the maturity');
            } else {
                $this->assertSame(0.0, $ctx->equityRaised, 'nobody subscribes to shares that are already out of the money');
                $this->assertEqualsWithDelta(100_000_000.0, (float) $stock->getSharesOutstanding(), 1e-6);
                $this->assertTrue($stock->isPaymentDefault(), 'the missed maturity stands, and the firm restructures');
            }
        }
    }

    /**
     * The buyback runs before the treasury closes the quarter, so a rescue raise lands on the shares the buyback
     * left. Struck on the opening count instead, it put back every share the firm had just paid to retire.
     */
    public function testARescueRaiseAfterABuybackDilutesTheSharesTheBuybackLeft(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');
        $stock->setSharesOutstanding('95000000'); // the quarter opened at 100M and bought back 5M

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 50.0);
        $ctx->newShares = 95_000_000.0;
        $ctx->totalCashSpent = 250_000_000.0;
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;
        $ctx->health = $this->healthThatCannotIssue($ctx->health);

        $debtEngine = $this->createMock(DebtEngine::class);
        $debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0),
        );
        $debtEngine->method('assessGoingConcern')->willReturn(new \App\DTO\GoingConcernDTO(
            trailingEbit: 150_000_000.0,
            trailingEbitda: 170_000_000.0,
            assetValue: 3_010_000_000.0,
            cash: 10_000_000.0,
            claims: 2_000_000_000.0,
        ));
        $mathUtility = new ScriptedDraws(generateUniform: 0.0);

        (new TreasuryEngine($this->corporateMetrics, $debtEngine, $this->capExEngine, $mathUtility))->finalizeLiquidity($ctx);

        $this->assertGreaterThan(0.0, $ctx->equityRaised, 'the rescue must execute for this to test anything');
        $sharesIssued = $ctx->equityRaised / (50.0 * (1.0 - TreasuryEngine::SEASONED_EQUITY_OFFER_DISCOUNT));
        $this->assertEqualsWithDelta(95_000_000.0 + $sharesIssued, (float) $stock->getSharesOutstanding(), 1.0);
        $this->assertEqualsWithDelta((float) $stock->getSharesOutstanding(), $ctx->newShares, 1e-6, 'and the count the engine writes back carries both');
    }

    /**
     * A committed facility is a term contract. It is renegotiated upward as the business grows and it does
     * not shrink because one bad quarter shrank revenue -- which is exactly what a commitment recomputed
     * from current revenue on every call did, halving the backstop at the moment it was the only thing
     * between a solvent firm and an event of default.
     */
    public function testTheRevolverCommitmentRatchetsUpAndNeverDown(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO(maturingPrincipal: 0.0, refinanced: true));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $rich = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $rich->operatingBase = 10_000_000_000.0;
        $rich->newTreasury = 100_000_000.0;
        $engine->finalizeLiquidity($rich);

        $peak = (float) $stock->getRevolverCommitment();
        $this->assertGreaterThan(0.0, $peak, 'a facility is sized even in a quarter with nothing to draw for');

        // The recession arrives and the operating base halves.
        $poor = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $poor->operatingBase = 5_000_000_000.0;
        $poor->newTreasury = 100_000_000.0;
        $engine->finalizeLiquidity($poor);

        $this->assertEqualsWithDelta($peak, (float) $stock->getRevolverCommitment(), 1.0, 'the committed line does not shrink with revenue');
    }

    /** A drawn revolver is not term debt, so it must not enlarge the principal coming due next quarter. */
    public function testARevolverDrawDoesNotEnlargeTheMaturityWall(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 35_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0),
        );
        $this->debtEngine->expects($this->never())->method('issueDebt');
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0);
        $this->assertEqualsWithDelta(
            1_905_000_000.0,
            (float) $stock->getWholesaleDebt(),
            1.0,
            'the notes left the ladder and the facility took their place: next quarter rolls a SMALLER wall',
        );
    }

    /**
     * A maturity the bond market refuses to roll is drawn on the committed revolver before it can become a
     * default: that is what the facility is for. The maturity wall repays down to the cash floor, so the
     * balance is never negative on that account; a revolver that only closed overdrafts left a solvent firm
     * with an untouched credit line defaulting on principal the line would have funded.
     */
    public function testCommittedRevolverFundsAMaturityTheBondMarketRefused(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 35_000_000.0; // the $30M operating cash floor plus the $5M cash leg of the repayment

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0),
        );
        // The draw is NOT an issuance: it lands on the facility's own balance, priced at the revolver spread.
        $this->debtEngine->expects($this->never())->method('issueDebt');
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertFalse($stock->isPaymentDefault(), 'a maturity the revolver funded is not a default');
        $this->assertEqualsWithDelta(0.0, $ctx->unfundedMaturity, 1e-6);
        $this->assertEqualsWithDelta(100_000_000.0, $ctx->principalRepaid, 1.0, 'the whole maturity reached the bondholders');
        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the line carries what the bondholders were paid');
        $this->assertEqualsWithDelta(
            2_000_000_000.0,
            (float) $stock->getWholesaleDebt() + (float) $stock->getRevolverDrawn(),
            1.0,
            'the notes were refinanced onto the line, not added to it: total borrowings are unchanged',
        );
        $this->assertEqualsWithDelta(30_000_000.0, $ctx->newTreasury, 1.0, 'the draw passed straight through to the bondholders');
    }

    /**
     * A breached maintenance covenant is an Event of Default under the credit agreement, so once the going
     * concern no longer covers the debt the lenders refuse new draws exactly as for a missed payment. The
     * maturity the bond market refused is then a payment default, and the commitment is neither terminated
     * (the breach can cure) nor upsized (no bank enlarges a line to a borrower in breach).
     */
    public function testACovenantBreachBarsNewRevolverDrawsSoARefusedMaturityDefaults(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('20000000000.00');
        $stock->setRevolverCommitment('1000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 20_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;
        $ctx->operatingBase = 100_000_000_000.0; // would size a far larger line, were the ratchet free
        $ctx->health = $this->healthInCovenantBreach($ctx->health);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 3_000_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 2_990_000_000.0),
        );
        $this->debtEngine->method('assessGoingConcern')->willReturn($this->goingConcern(assetValue: 15_000_000_000.0, claims: 20_000_000_000.0));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertEqualsWithDelta(0.0, (float) $stock->getRevolverDrawn(), 1e-6, 'no new draw while the covenant is breached');
        $this->assertTrue($stock->isPaymentDefault(), 'the refused maturity nobody funded is an event of default');
        $this->assertEqualsWithDelta(2_990_000_000.0, $ctx->unfundedMaturity, 1.0);
        $this->assertEqualsWithDelta(1_000_000_000.0, (float) $stock->getRevolverCommitment(), 1.0, 'the commitment survives the breach and is not upsized');
        $this->assertContains(
            'Lenders refused a $1.00B draw on its revolving credit facility while it is in breach of its leverage covenant.',
            array_column($ctx->events, 'description'),
        );
    }

    /**
     * Most breaches are waived (Roberts & Sufi 2009): a borrower whose assets still cover every claim keeps its
     * line, because forcing a default on it gains the lenders nothing.
     */
    public function testLendersWaiveTheBreachOfABorrowerWhoseAssetsStillCoverItsDebt(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');
        $stock->setRevolverCommitment('150000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 35_000_000.0;
        $ctx->health = $this->healthInCovenantBreach($ctx->health);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0),
        );
        $this->debtEngine->method('assessGoingConcern')->willReturn($this->goingConcern(assetValue: 3_000_000_000.0, claims: 2_000_000_000.0));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the waived line funds the maturity');
        $this->assertFalse($stock->isPaymentDefault());
        $this->assertNotContains(
            'Lenders refused a $100.00M draw on its revolving credit facility while it is in breach of its leverage covenant.',
            array_column($ctx->events, 'description'),
        );
    }

    /** The commitment survived the breach, so the quarter leverage is back inside the covenant the line funds again. */
    public function testTheRevolverReopensOnceLeverageIsBackInsideTheCovenant(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');
        $stock->setRevolverCommitment('150000000.00');
        $roll = new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn($roll);
        $this->debtEngine->method('assessGoingConcern')->willReturn($this->goingConcern(assetValue: 1_500_000_000.0, claims: 2_000_000_000.0));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $breached = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $breached->wholesaleDebt = 2_000_000_000.0;
        $breached->newTreasury = 35_000_000.0;
        $breached->health = $this->healthInCovenantBreach($breached->health);
        $engine->finalizeLiquidity($breached);

        $this->assertTrue($stock->isPaymentDefault());

        $cured = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $cured->wholesaleDebt = (float) $stock->getWholesaleDebt();
        $cured->newTreasury = 35_000_000.0;
        $engine->finalizeLiquidity($cured);

        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the line funds the maturity again');
        $this->assertFalse($stock->isPaymentDefault(), 'and the funded quarter cures the default');
    }

    /**
     * A firm the primary market just refused cannot turn around and issue emergency paper into the same
     * closed market. Without this the maturity wall would be toothless: every refusal would be papered over
     * by penalty-rate borrowing from lenders who had just said no. The committed revolver is the one line
     * that still funds, and it prices at its own spread, never the emergency one.
     */
    public function testAFirmRefusedRefinancingCannotIssueEmergencyDebt(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 5_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0),
        );
        $ratesIssuedAt = [];
        $this->debtEngine->method('issueDebt')->willReturnCallback(
            static function (Stock $s, float $amount, float $rate) use (&$ratesIssuedAt): void {
                $ratesIssuedAt[] = $rate;
            },
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertCount(0, $ratesIssuedAt, 'the committed line funded it, and a draw is not an issuance');
        $this->assertEqualsWithDelta(125_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the facility carried the whole maturity and restored the $30M floor');
        $this->assertFalse($ctx->failedEmergencyBorrow, 'with the floor restored by the line, nothing was left to borrow');
    }

    /**
     * A firm past its cure period has lost its revolver, not its debts. A quarter it could not pay for left a
     * negative balance the treasury floored at zero, erasing the shortfall and creating the cash to cover it.
     * The emergency facility funds it instead: every dollar the treasury gains is a dollar the firm owes.
     */
    public function testAShortfallPastTheCurePeriodIsOwedNotErased(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setPaymentDefault(true);
        $stock->setQuartersInDefault(\App\Service\Math\FinancialConstants::PAYMENT_DEFAULT_GRACE_QUARTERS + 1);

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->newTreasury = -400_000_000.0; // the quarter's losses ran through the whole balance
        $ctx->refinancingRefused = true;    // and the bond market has shut the firm out

        $issued = 0.0;
        $this->debtEngine->method('issueDebt')->willReturnCallback(
            static function (Stock $s, float $amount) use (&$issued): void {
                $issued += $amount;
            },
        );

        $this->treasuryEngine->finalizeLiquidity($ctx);

        $this->assertGreaterThanOrEqual(0.0, (float) $stock->getCorporateTreasury());
        $this->assertEqualsWithDelta(
            (float) $stock->getCorporateTreasury() + 400_000_000.0,
            $issued,
            1.0,
            'the cash the treasury gained is matched by the debt it took on',
        );
    }

    /** The same health reading, with every lending door shut: no market will underwrite this credit. */
    private function healthThatCannotIssue(DebtHealthDTO $health): DebtHealthDTO
    {
        return $this->healthWithAccess($health, canIssueDebt: false, hasMarketAccess: false);
    }

    private function healthWithAccess(DebtHealthDTO $health, bool $canIssueDebt, bool $hasMarketAccess): DebtHealthDTO
    {
        return DebtHealthBuilder::from($health)
            ->withDebtIssuance($canIssueDebt)
            ->withMarketAccess($hasMarketAccess)
            ->build();
    }

    /**
     * The pecking order for a cash shortfall: the committed line, then the bond market, then equity (Myers & Majluf
     * 1984; Sufi 2009). A BBB issuer at 2.5x coverage, short of its $30M floor, used to fail the 3.5x coverage gate and
     * sell discounted stock while its revolver sat undrawn; it draws the line.
     *
     * @return array{0: CapitalAllocationContext, 1: Stock, 2: float} The context, the stock and the debt issued.
     */
    private function fundAShortfall(bool $hasMarketAccess, float $revolverDrawn): array
    {
        // A zero uniform: any rescue raise reached is certain to execute.
        $mathUtility = new ScriptedDraws(generateUniform: 0.0);
        $issued = 0.0;
        $this->debtEngine->method('issueDebt')->willReturnCallback(static function (Stock $target, float $amount) use (&$issued): void {
            $issued += $amount;
            $target->setWholesaleDebt((string) ((float) $target->getWholesaleDebt() + $amount));
        });
        $this->debtEngine->method('assessGoingConcern')->willReturn($this->goingConcern(assetValue: 3_000_000_000.0, claims: 300_000_000.0));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $mathUtility);

        $stock = $this->createSolventCorporate();
        $stock->setRevolverCommitment('150000000.00');
        $stock->setRevolverDrawn((string) $revolverDrawn);
        $stock->setSharesOutstanding('100000000');
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->newTreasury = 10_000_000.0;
        $ctx->newShares = 100_000_000.0;
        $ctx->health = $this->healthWithAccess($ctx->health, canIssueDebt: false, hasMarketAccess: $hasMarketAccess);

        $engine->finalizeLiquidity($ctx);

        return [$ctx, $stock, $issued];
    }

    public function testAShortfallBelowTheFloorDrawsTheLineBeforeSellingStock(): void
    {
        [$ctx, $stock, $issued] = $this->fundAShortfall(hasMarketAccess: true, revolverDrawn: 0.0);

        $this->assertEqualsWithDelta(20_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the line restores the $30M floor');
        $this->assertEqualsWithDelta(30_000_000.0, (float) $stock->getCorporateTreasury(), 1.0);
        $this->assertSame(0.0, $ctx->equityRaised, 'no stock is sold while the line is undrawn');
        $this->assertSame(0.0, $issued);
    }

    public function testPastTheLineAnIssuerTheMarketLendsToBorrowsRatherThanSellsStock(): void
    {
        [$ctx, $stock, $issued] = $this->fundAShortfall(hasMarketAccess: true, revolverDrawn: 150_000_000.0);

        $this->assertEqualsWithDelta(20_000_000.0, $issued, 1.0, 'the bond market funds what the exhausted line cannot');
        $this->assertSame(0.0, $ctx->equityRaised);
        $this->assertFalse($ctx->failedEmergencyBorrow);
    }

    /** Only a firm no lender will fund goes to the equity market, and it places stock at the cited 2.2% discount. */
    public function testOnlyAFirmNoLenderWillFundSellsStockAtTheOfferDiscount(): void
    {
        [$ctx, $stock, $issued] = $this->fundAShortfall(hasMarketAccess: false, revolverDrawn: 150_000_000.0);

        $this->assertSame(0.0, $issued);
        $this->assertGreaterThan(0.0, $ctx->equityRaised, 'the rescue raise is the last resort, and it is reached');
        $sharesIssued = $ctx->equityRaised / (50.0 * (1.0 - TreasuryEngine::SEASONED_EQUITY_OFFER_DISCOUNT));
        $this->assertEqualsWithDelta(100_000_000.0 + $sharesIssued, (float) $stock->getSharesOutstanding(), 1.0);
    }

    private function goingConcern(float $assetValue, float $claims): \App\DTO\GoingConcernDTO
    {
        return new \App\DTO\GoingConcernDTO(trailingEbit: 150_000_000.0, trailingEbitda: 170_000_000.0, assetValue: $assetValue, cash: 10_000_000.0, claims: $claims);
    }

    /** No market will lend, and net debt / EBITDA stands past the 3.0x covenant. */
    private function healthInCovenantBreach(DebtHealthDTO $health): DebtHealthDTO
    {
        return DebtHealthBuilder::from($health)->withDebtIssuance(false)->withMarketAccess(false)->withCovenant(5.0, 3.0, false)->build();
    }

    private function createSolventCorporate(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('SBCC');
        $stock->setIndustry('Information Technology Services');
        $stock->setTotalEquity('1000000000.00');
        $stock->setRetainedEarnings('500000000.00');
        $stock->setWholesaleDebt('200000000.00');
        $stock->setCorporateTreasury('300000000.00');
        $stock->setCreditSpread('0.02');

        return $stock;
    }

    /**
     * Pecking order (Myers & Majluf 1984): an operating company borrows for plant it has chosen to build, and only
     * the part its spare cash cannot fund. The same balance sheet with headroom and a return well over its hurdle
     * borrows when no plant budget applies, and borrows nothing once demand has been met.
     */
    public function testAFirmBorrowsForExpansionOnlyWhatItsPlantBudgetLeavesUnfunded(): void
    {
        $borrow = function (?float $expansionBudget): float {
            $stock = new Stock();
            $stock->setTicker('PECK');
            $stock->setTotalEquity('1000000000.00');
            $stock->setWholesaleDebt('200000000.00');
            $stock->setCorporateTreasury('300000000.00');

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
            $ctx->debtActionTaken = false;
            $ctx->expansionBudget = $expansionBudget;

            $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
            $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.0);
            $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.40);

            $issued = 0.0;
            $debtEngine = $this->createStub(DebtEngine::class);
            $debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO());
            $debtEngine->method('issueDebt')->willReturnCallback(static function (Stock $issuer, float $amount) use (&$issued): void {
                $issued += $amount;
            });
            $engine = new TreasuryEngine($this->corporateMetrics, $debtEngine, $this->createStub(CapExEngine::class), $this->mathUtility);
            mt_srand(3); // seeded once every mock is built (see setUp)
            $engine->executeCorporateStrategy($ctx);

            return $issued;
        };

        $this->assertGreaterThan(0.0, $borrow(null), 'The fixture only means anything while the unbudgeted firm borrows.');
        $this->assertSame(0.0, $borrow(0.0), 'Headroom alone is not a reason to borrow.');
    }

    /**
     * A fund closed at capacity takes no capital to grow: every fee manager's model borrows nothing to expand on a
     * balance sheet whose return clears its hurdle, where the same hedge fund model without a capacity borrows.
     */
    public function testAFundClosedAtCapacityBorrowsNothingToExpand(): void
    {
        $borrow = function (string $industry, BusinessModelInterface $strategy): float {
            $stock = new Stock();
            $stock->setTicker('FUND');
            $stock->setIndustry($industry);
            $stock->setTotalEquity('1000000000.00');
            $stock->setWholesaleDebt('200000000.00');
            $stock->setCorporateTreasury('300000000.00');

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
            $ctx->strategy = $strategy;
            $ctx->businessModel = \App\Data\Company\Sectors::INDUSTRY_METRICS[$industry]['business_model'];
            $ctx->debtActionTaken = false;

            $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
            $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.0);
            $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.40);

            $issued = 0.0;
            $debtEngine = $this->createStub(DebtEngine::class);
            $debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO());
            $debtEngine->method('issueDebt')->willReturnCallback(static function (Stock $issuer, float $amount) use (&$issued): void {
                $issued += $amount;
            });
            $engine = new TreasuryEngine($this->corporateMetrics, $debtEngine, $this->createStub(CapExEngine::class), $this->mathUtility);
            mt_srand(3); // seeded once every mock is built (see setUp)
            $engine->executeCorporateStrategy($ctx);

            return $issued;
        };

        $openFund = new class extends \App\Service\Model\Sector\HedgeFundBusinessModel {
            public function getCapacityShare(Stock $stock): ?float
            {
                return null;
            }
        };
        $this->assertGreaterThan(0.0, $borrow('Hedge Fund', $openFund), 'Control: an open fund borrows to expand.');
        foreach (['Hedge Fund', 'Private Equity', 'Asset Management', 'Distressed Debt'] as $industry) {
            $this->assertSame(0.0, $borrow($industry, \App\Data\Company\Sectors::strategyFor($industry)), $industry);
        }
    }

    /**
     * However it is funded, organic plant stops at what the flexible accelerator leaves after the earnings
     * engine's own growth spend: spare cash above it stays for distribution, and borrowing taken this quarter
     * no longer forces the proceeds into plant.
     */
    public function testOrganicCapexStopsAtThePlantBudgetEvenWhenDebtWasRaised(): void
    {
        $deploy = function (?float $expansionBudget, bool $debtRaised): CapitalAllocationContext {
            $stock = new Stock();
            $stock->setTicker('ACCL');
            $stock->setTotalEquity('1000000000.00');
            $stock->setCorporateTreasury('200000000.00');

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
            $ctx->newTreasury = 200_000_000.0;
            $ctx->debtActionTaken = $debtRaised;
            $ctx->debtIssued = $debtRaised ? 100_000_000.0 : 0.0;
            $ctx->expansionBudget = $expansionBudget;
            $ctx->health = DebtHealthBuilder::from($ctx->health)
                ->withDebtIssuance(false)
                ->withCostOfCapital(0.30, 0.32)
                ->build();

            $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
            $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.0);
            $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.40);

            $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->createStub(CapExEngine::class), $this->mathUtility);
            mt_srand(7); // seeded once every mock is built (see setUp)
            $engine->executeCorporateStrategy($ctx);

            return $ctx;
        };

        $budget = 5_000_000.0;
        $this->assertGreaterThan($budget, $deploy(null, false)->organicCapex, 'The fixture only means anything while the unbudgeted firm spends past the budget.');
        $this->assertEqualsWithDelta($budget, $deploy($budget, false)->organicCapex, 1e-6, 'Cash-funded plant stops at the budget.');
        $this->assertGreaterThan(0.0, $deploy(null, true)->organicCapex, 'Borrowing used to force the proceeds into plant.');
        $this->assertEqualsWithDelta(0.0, $deploy(0.0, true)->organicCapex, 1e-9, 'With demand met, borrowed cash builds nothing.');
    }

    /**
     * Fund the plant the earnings engine chose to build in a quarter whose own funds fell short of it.
     *
     * @return array{ctx: CapitalAllocationContext, built: float, borrowed: float}
     */
    private function fundPlant(BusinessModelInterface $strategy, float $budget, float $spareCash, bool $canBorrow, float $price = 50.0): array
    {
        $stock = new Stock();
        $stock->setTicker('PLNT');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('200000000.00');
        $stock->setSharesOutstanding('100000000');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: $price);
        $ctx->strategy = $strategy;
        $ctx->newTreasury = $strategy->calculateMinOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt) + $spareCash;
        $stock->setCorporateTreasury((string) $ctx->newTreasury);
        $ctx->expansionBudget = $budget;
        if (!$canBorrow) {
            $ctx->health = DebtHealthBuilder::from($ctx->health)->withDebtIssuance(false)->build();
        }

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.0);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.0);

        $borrowed = 0.0;
        $debtEngine = $this->createStub(DebtEngine::class);
        $debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO());
        $debtEngine->method('issueDebt')->willReturnCallback(static function (Stock $issuer, float $amount) use (&$borrowed): void {
            $borrowed += $amount;
        });
        $built = 0.0;
        $capExEngine = $this->createStub(CapExEngine::class);
        $capExEngine->method('allocateGrowthCapEx')->willReturnCallback(static function (Stock $owner, float $amount) use (&$built): void {
            $built += $amount;
        });
        $engine = new TreasuryEngine($this->corporateMetrics, $debtEngine, $capExEngine, $this->mathUtility);
        mt_srand(3); // seeded once every mock is built (see setUp)
        $engine->executeCorporateStrategy($ctx);

        return ['ctx' => $ctx, 'built' => $built, 'borrowed' => $borrowed];
    }

    /**
     * Plant the earnings engine chose to build and could not pay for from the quarter's funds is built whatever the
     * treasury's own appetite: from spare cash first, the rest borrowed, and all of it reported as capex. A firm the
     * market will not lend to builds what its cash covers and no more.
     */
    public function testPlannedPlantIsPaidFromSpareCashThenBorrowed(): void
    {
        $budget = 50_000_000.0;
        $funded = $this->fundPlant(new StandardCorporateBusinessModel(), $budget, 10_000_000.0, true);

        $this->assertEqualsWithDelta($budget, $funded['built'], 1e-6, 'the whole plan is queued as construction');
        $this->assertEqualsWithDelta(40_000_000.0, $funded['borrowed'], 1e-6, 'only what spare cash does not cover is borrowed');
        $this->assertEqualsWithDelta($budget, $funded['ctx']->organicCapex, 1e-6, 'and it reaches the cash flow statement');
        $this->assertEqualsWithDelta(0.0, $funded['ctx']->expansionBudget ?? 0.0, 1e-6);

        $shut = $this->fundPlant(new StandardCorporateBusinessModel(), $budget, 10_000_000.0, false);
        $this->assertEqualsWithDelta(10_000_000.0, $shut['built'], 1e-6, 'without lenders, its cash is what it builds');
        $this->assertSame(0.0, $shut['borrowed']);
        $this->assertEqualsWithDelta(100_000_000.0, (float) $shut['ctx']->stock->getSharesOutstanding(), 1e-6, 'a firm that retains its earnings sells no stock to build');
    }

    /**
     * A pass-through distributes what it earns, so what its lenders will not fund it raises in shares, at the offer
     * discount, while the offer clears book value per share. Below book it builds what its cash covers.
     */
    public function testAPassThroughSellsSharesForThePlantItsDebtCannotFund(): void
    {
        $passThrough = new class extends StandardCorporateBusinessModel {
            public function getMinimumDistributionRatio(): float
            {
                return 0.90;
            }
        };
        $budget = 50_000_000.0;
        $offer = 50.0 * (1.0 - TreasuryEngine::SEASONED_EQUITY_OFFER_DISCOUNT);

        $raised = $this->fundPlant($passThrough, $budget, 10_000_000.0, false);
        $this->assertEqualsWithDelta($budget, $raised['built'], 1e-6);
        $this->assertEqualsWithDelta(40_000_000.0, $raised['ctx']->equityRaised, 1e-6);
        $this->assertEqualsWithDelta(100_000_000.0 + (40_000_000.0 / $offer), $raised['ctx']->newShares, 1.0, 'the dilution reaches the share count the engine writes back');

        // Book is $10 a share: an offer at $8.80 would sell the holders' plant to new ones below what it cost.
        $belowBook = $this->fundPlant($passThrough, $budget, 10_000_000.0, false, 9.0);
        $this->assertEqualsWithDelta(10_000_000.0, $belowBook['built'], 1e-6);
        $this->assertSame(0.0, $belowBook['ctx']->equityRaised);
    }

    private function createAllocationContext(Stock $stock, float $stockCompensation, float $currentPrice = 50.0, float $actualAnnualEps = 4.0): CapitalAllocationContext
    {
        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: $actualAnnualEps,
            quarterlyFcfPerShare: 1.0,
            currentPrice: $currentPrice,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 100_000_000,
            stockCompensation: $stockCompensation,
        );

        $ctx->strategy = new StandardCorporateBusinessModel();
        $ctx->businessModel = 'tech';
        $ctx->quarterlyNetIncome = 100_000_000.0;
        $ctx->newTreasury = 300_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 200_000_000.0;
        $ctx->customerDeposits = 0.0;
        // No distributions this quarter, so equity moves only through income and the SBC credit.
        $ctx->totalPaid = 0.0;
        $ctx->totalCashSpent = 0.0;
        $ctx->debtActionTaken = true; // suppress the deleveraging sweep; this test isolates the equity roll-forward

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 10_000_000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 150_000_000.0,
            revenue: 800_000_000.0,
            depreciation: 20_000_000.0,
            ebitda: 170_000_000.0,
        );

        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.04)
            ->withCoverage(15.0)
            ->withDebtTolerance(1.0)
            ->withMetrics($debtMetrics)
            ->build();

        return $ctx;
    }

    /**
     * A lender short of operating cash sells from its book at a haircut before it borrows at penalty rates.
     * The slice sold takes its share of the allowance with it, the proceeds come in as cash, and the discount
     * is a loss charged to equity: the book shrinks by exactly what the claims on it lose.
     */
    public function testLenderShortOfCashSellsEarningAssetsAtAHaircutBeforeBorrowing(): void
    {
        $stock = new Stock();
        $stock->setTicker('RUNB');
        $stock->setIndustry('Banks - Diversified');
        $stock->setEarningAssets('5000000000.00');
        $stock->setCreditLossAllowance('100000000.00');
        $stock->setCustomerDeposits('4000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('50000000.00');
        $stock->setRetainedEarnings('500000000.00');
        // Equity is the residual of the sheet the fixture opens with, so it starts balanced.
        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));
        $equityBefore = (float) $stock->getTotalEquity();

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->quarterlyNetIncome = 0.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 4_000_000_000.0;
        $ctx->newTreasury = 50_000_000.0; // well below what a bank of this size has to hold

        $this->debtEngine->expects($this->never())->method('issueDebt');
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, 4_000_000_000.0, 0.0);
        $this->assertGreaterThan(50_000_000.0, $minOperatingCash, 'the fixture must actually be short');
        $this->assertGreaterThan(0.0, $ctx->assetSaleProceeds, 'the book was sold down');
        $this->assertEqualsWithDelta($minOperatingCash, (float) $stock->getCorporateTreasury(), 1.0, 'cash is restored to the operating floor');

        $haircut = TreasuryEngine::EARNING_ASSET_FIRE_SALE_HAIRCUT;
        $this->assertEqualsWithDelta($ctx->assetSaleProceeds * $haircut / (1.0 - $haircut), $ctx->assetSaleLoss, 1.0, 'the loss is the haircut on what was sold');
        $this->assertLessThan(5_000_000_000.0, (float) $stock->getEarningAssets());
        $this->assertLessThan(100_000_000.0, (float) $stock->getCreditLossAllowance(), 'the allowance on the slice sold leaves with it');
        $this->assertEqualsWithDelta($equityBefore - $ctx->assetSaleLoss, (float) $stock->getTotalEquity(), 1.0, 'the loss is charged to equity');

        $this->assertBalanceSheetBalances($stock, 'the sheet still balances after the fire sale', relativeTolerance: 0.0);
    }

    /**
     * Organic expansion is an NPV decision on the MARGINAL return — what the next dollar of plant earns at
     * the firm's current scale — not on the average return its existing plant still earns. A saturated firm
     * keeps a high average for as long as its old capital is in the ground; testing that let it deploy cash
     * below its hurdle indefinitely. Both cases here pass the pacing draw at the same probability, so the
     * only thing separating them is which side of the hurdle the marginal return falls on.
     */
    public function testOrganicCapexIsGatedOnTheMarginalReturnNotTheAverage(): void
    {
        $deploy = function (float $hurdle): CapitalAllocationContext {
            $stock = new Stock();
            $stock->setTicker('SAT');
            $stock->setTotalEquity('1000000000.00');
            $stock->setCorporateTreasury('200000000.00');
            $stock->setRoicTtm('0.60'); // the AVERAGE return: comfortably above either hurdle

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
            $ctx->newTreasury = 200_000_000.0; // above the cash target, well short of hoarder territory
            $ctx->debtActionTaken = false;     // no debt this quarter, so nothing forces a deployment
            $ctx->health = DebtHealthBuilder::from($ctx->health)
                ->withDebtIssuance(false)
                ->withCostOfCapital($hurdle, $hurdle + 0.02)
                ->build();

            // Saturation has taken the marginal return to 40%: the pacing draw sits at its 95% ceiling either way.
            $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
            $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.20);
            $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.40);

            $capExEngine = $this->createMock(CapExEngine::class);
            $capExEngine->expects($hurdle > 0.40 ? $this->never() : $this->once())->method('allocateGrowthCapEx');
            $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $capExEngine, $this->mathUtility);

            mt_srand(7); // seeded once every mock is built (see setUp)
            $engine->executeCorporateStrategy($ctx);

            return $ctx;
        };

        $cleared = $deploy(0.30); // marginal 40% > hurdle 30%
        $this->assertGreaterThan(0.0, $cleared->organicCapex, 'With the marginal return above the hurdle the firm expands.');

        $refused = $deploy(0.50); // average 60% > hurdle 50% > marginal 40%
        $this->assertEqualsWithDelta(0.0, $refused->organicCapex, 1e-9, 'An average return above the hurdle is not a reason to deploy when the next dollar earns less than it costs.');
    }

    /**
     * Plant is sized by demand and has to earn the firm's true cost of capital whoever runs it, from cash as from debt.
     * The marginal return below sits between the empire builder's own hurdle and the true cost of capital, so both a
     * neutral firm and an empire builder refuse it: the style shows in what management borrows, holds and pays out,
     * not in plant that does not pay.
     */
    public function testCashFundedPlantClearsTheTrueCostOfCapitalWhoeverRunsTheFirm(): void
    {
        $deploy = function (?ManagementStyle $style): CapitalAllocationContext {
            $stock = new Stock();
            $stock->setTicker('AGCY');
            $stock->setTotalEquity('1000000000.00');
            $stock->setCorporateTreasury('200000000.00');
            $stock->setRoicTtm('0.60');
            $stock->setManagementStyle($style);

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
            $ctx->newTreasury = 200_000_000.0;
            $ctx->debtActionTaken = false;
            $ctx->health = DebtHealthBuilder::from($ctx->health)
                ->withDebtIssuance(false)
                ->withCostOfCapital(0.50, 0.52)
                ->build();

            // The next dollar of plant earns 40%, against a true cost of capital of 50%.
            $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
            $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.20);
            $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.40);

            $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->createStub(CapExEngine::class), $this->mathUtility);
            mt_srand(7); // seeded once every mock is built (see setUp)
            $engine->executeCorporateStrategy($ctx);

            return $ctx;
        };

        // 0.50 x 0.75 = 0.375, which the 40% marginal return would clear on the empire builder's own hurdle.
        $this->assertLessThan(
            0.40,
            ManagementStyle::EmpireBuilder->appliedHurdle(0.50),
            'The fixture only means anything while the empire builder\'s hurdle sits below the marginal return.',
        );

        $neutral = $deploy(null);
        $this->assertEqualsWithDelta(0.0, $neutral->organicCapex, 1e-9, 'A disciplined firm does not buy a 40% return with 50% money.');

        $empireBuilder = $deploy(ManagementStyle::EmpireBuilder);
        $this->assertEqualsWithDelta(0.0, $empireBuilder->organicCapex, 1e-9, 'Nor does an empire builder, for plant.');
    }

    /**
     * A lender lends out whatever funding sits above its liquidity target, every quarter and in full, keeping
     * only this quarter's retained earnings back for capital return. The cash goes onto the earning-asset
     * ledger directly; nothing is queued as construction. A bank its regulator has frozen lends nothing.
     */
    public function testLenderDeploysExcessFundingIntoTheBookEveryQuarter(): void
    {
        $stock = new Stock();
        $stock->setTicker('LEND');
        $stock->setIndustry('Banks - Diversified');
        $stock->setEarningAssets('2000000000.00');
        $stock->setCreditLossAllowance('0.00');
        $stock->setCustomerDeposits('0.00'); // no passive deposit growth in this quarter, so the arithmetic is exact
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('500000000.00');
        $stock->setTotalEquity('2500000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;
        $ctx->newTreasury = 500_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->retainedEarningsThisQuarter = 100_000_000.0;
        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.04, 0.04, 0.03)
            ->withCoverage(10.0)
            ->withDebtIssuance(false)
            ->withDebtTolerance(15.0)
            ->withCostOfCapital(0.08, 0.09)
            ->withMetrics($ctx->health->rawMetrics)
            ->underLeveraged()
            ->build();

        $capExEngine = $this->createMock(CapExEngine::class);
        $capExEngine->expects($this->never())->method('allocateGrowthCapEx');
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $capExEngine, $this->mathUtility);

        $engine->executeCorporateStrategy($ctx);

        // Target cash is 5% of the operating base with no deposits or wholesale debt, held with a 20% buffer:
        // $60M. Of the $440M above it, $100M of retained earnings stays back, so $340M is lent.
        $this->assertEqualsWithDelta(340_000_000.0, $ctx->loanOriginations, 1.0);
        $this->assertEqualsWithDelta(340_000_000.0, $ctx->organicCapex, 1.0, 'the deployment is the quarter\'s investing flow');
        $this->assertEqualsWithDelta(2_340_000_000.0, (float) $stock->getEarningAssets(), 1.0);
        $this->assertEqualsWithDelta(160_000_000.0, $ctx->newTreasury, 1.0);
        $this->assertEqualsWithDelta(0.0, (float) $stock->getCipBalance(), 1.0, 'loans are never construction in progress');
    }

    public function testCapitalConstrainedLenderKeepsItsFundingInCash(): void
    {
        $stock = new Stock();
        $stock->setTicker('THIN');
        $stock->setIndustry('Banks - Diversified');
        $stock->setEarningAssets('2000000000.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('500000000.00');
        $stock->setTotalEquity('50000000.00'); // CET1 of 2.5%, far below the conservation buffer

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;
        $ctx->newTreasury = 500_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->retainedEarningsThisQuarter = 0.0;
        $ctx->health = DebtHealthBuilder::healthy()
            ->withCarry(0.04, 0.04, 0.03)
            ->withCoverage(10.0)
            ->withDebtIssuance(false)
            ->withDebtTolerance(15.0)
            ->withCostOfCapital(0.08, 0.09)
            ->withMetrics($ctx->health->rawMetrics)
            ->build();
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->executeCorporateStrategy($ctx);

        $this->assertEqualsWithDelta(0.0, $ctx->loanOriginations, 1.0, 'a bank below its capital buffer cannot add risk-weighted assets');
        $this->assertEqualsWithDelta(2_000_000_000.0, (float) $stock->getEarningAssets(), 1.0);
        $this->assertEqualsWithDelta(500_000_000.0, $ctx->newTreasury, 1.0);
    }

    /**
     * A financing action is news in proportion to the firm: one percent of its total assets, not a fixed dollar line.
     */
    public function testFinancingNewsIsMaterialToTheFirmRatherThanAFixedSum(): void
    {
        $operating = (new Stock())->setCorporateTreasury('2000000000')->setGrossPpe('18000000000');
        $totalAssets = $operating->getTotalAssets();
        $this->assertGreaterThan(0.0, $totalAssets);

        $this->assertTrue(TreasuryEngine::isNewsworthy($operating, $totalAssets * 0.0101));
        $this->assertFalse(TreasuryEngine::isNewsworthy($operating, $totalAssets * 0.0099));

        // Before its first ledger a firm's total assets are its equity plus its funding.
        $unreported = (new Stock())->setTotalEquity('3000000000')->setWholesaleDebt('1000000000');
        $this->assertFalse($unreported->hasBalanceSheetLedger());
        $this->assertTrue(TreasuryEngine::isNewsworthy($unreported, 41_000_000.0));
        $this->assertFalse(TreasuryEngine::isNewsworthy($unreported, 39_000_000.0));

        // The same $600M draw is news for the first firm and a rounding error for a $200B balance sheet.
        $large = (new Stock())->setCorporateTreasury('20000000000')->setGrossPpe('180000000000');
        $this->assertTrue(TreasuryEngine::isNewsworthy((new Stock())->setTotalEquity('20000000000'), 600_000_000.0));
        $this->assertFalse(TreasuryEngine::isNewsworthy($large, 600_000_000.0));
    }
}

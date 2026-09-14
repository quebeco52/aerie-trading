<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CapitalAllocationContext;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MaturityRollDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\InvestmentBankBusinessModel;
use App\Service\Model\Sector\ShadowBankBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TreasuryEngineTest extends TestCase
{
    private CorporateMetrics $corporateMetrics;
    private DebtEngine $debtEngine;
    private CapExEngine $capExEngine;
    private MathUtility $mathUtility;
    private TreasuryEngine $treasuryEngine;

    protected function setUp(): void
    {
        mt_srand(42);
        $this->corporateMetrics = $this->createStub(CorporateMetrics::class);
        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->capExEngine = $this->createStub(CapExEngine::class);
        $this->mathUtility = new MathUtility();

        // These tests are not about the maturity wall, so no principal comes due in them.
        $this->debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO());

        $this->treasuryEngine = new TreasuryEngine(
            $this->corporateMetrics,
            $this->debtEngine,
            $this->capExEngine,
            $this->mathUtility
        );
    }

    public function testUnderleveragedInvestmentBankIssuesDebtEvenWhenMarginalReturnIsBelowHurdle(): void
    {
        $stock = new Stock();
        $stock->setTicker('PERE');
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
            actualTotalNetIncome: 25_000_000
        );

        $strategy = new InvestmentBankBusinessModel();
        $ctx->strategy = $strategy;
        $ctx->businessModel = 'investment_bank';
        $ctx->isFinancial = true;
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
            ebitda: 32_000_000.0
        );

        // Under-leveraged is TRUE, but severe saturation penalty makes marginal return < hurdle
        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 10.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 8.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true
        );

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
            actualTotalNetIncome: 25_000_000
        );

        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->isFinancial = true;
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
            ebitda: 32_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.04,
            effectiveCost: 0.04,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 3.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 2.0,
            wacc: 0.06,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true
        );

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(6_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02);

        // Commercial bank should NOT issue wholesale debt via underleveraged path
        $this->debtEngine->expects($this->never())->method('issueDebt');

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertFalse($ctx->debtActionTaken);
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
            actualTotalNetIncome: 25_000_000
        );

        $ctx->strategy = new InvestmentBankBusinessModel();
        $ctx->businessModel = 'investment_bank';
        $ctx->isFinancial = true;
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
            ebitda: 105_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 2.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 0.8, // industrial tolerance
            wacc: 0.06,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

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
            actualTotalNetIncome: 25_000_000
        );

        $ctx->strategy = new ShadowBankBusinessModel();
        $ctx->businessModel = 'shadow_bank';
        $ctx->isFinancial = true;
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
            ebitda: 32_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 10.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 8.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true
        );

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

    public function testUnderleveragedStandardCorporateIssuesDebt(): void
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
            actualTotalNetIncome: 25_000_000
        );

        $ctx->strategy = new StandardCorporateBusinessModel();
        $ctx->businessModel = 'standard_corporate';
        $ctx->isFinancial = false;
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
            ebitda: 55_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 10.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true
        );

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
        $mathUtility = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateUniform'])->getMock();
        $mathUtility->method('generateUniform')->willReturn(0.0);
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $mathUtility);

        $stock = $this->createSolventCorporate();
        $stock->setSharesOutstanding('100000000');
        $stock->setRoicTtm('0.30');
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 300.0);
        $ctx->newShares = 100_000_000.0; // what the buyback step leaves when nothing is repurchased

        $engine->finalizeLiquidity($ctx);

        $this->assertGreaterThan(0.0, $ctx->equityRaised, 'the bubble offering must execute for this to test anything');
        $sharesIssued = $ctx->equityRaised / (300.0 * 0.90); // shares go out at the offering discount
        $this->assertEqualsWithDelta(-$sharesIssued, $stock->getCorporateFlowBacklog(), 1.0, 'the placed stock is queued to flow back onto the tape');
        $this->assertEqualsWithDelta(100_000_000.0 + $sharesIssued, (float) $stock->getSharesOutstanding(), 1.0);
        $this->assertEqualsWithDelta(
            (float) $stock->getSharesOutstanding(),
            $ctx->newShares,
            1e-6,
            'the share count the engine writes back has to carry the dilution, or the raise is booked with no shares behind it'
        );
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
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 300_000_000.0, unfundedShortfall: 0.0)
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($ctx->refinancingRefused);
        $this->assertEqualsWithDelta(300_000_000.0, $ctx->principalRepaid, 1.0);
        $this->assertFalse($stock->isPaymentDefault(), 'a firm that pays in full has not defaulted');
    }

    /**
     * Principal the firm can neither refinance, fund from cash, nor cover with its committed revolver is an
     * event of default. This is a distinct failure mode from insolvency: the balance sheet here is perfectly
     * solvent, the money simply was not there. The fixture's revolver is 5x a $30M cash floor, so it takes
     * $150M of the $290M shortfall and the remaining $140M is what defaults.
     */
    public function testMaturityBeyondTheRevolverCommitmentTriggersAPaymentDefault(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        // Price zero: there is no equity market to rescue it either.
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0)
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($stock->isPaymentDefault(), 'a maturity past the committed line must be an event of default');
        $this->assertEqualsWithDelta(140_000_000.0, $ctx->unfundedMaturity, 1.0, 'only the part the revolver could not cover is unfunded');
        $this->assertEqualsWithDelta(160_000_000.0, $ctx->principalRepaid, 1.0, 'cash plus the full commitment went to the bondholders');
        $this->assertGreaterThan(0.0, (float) $stock->getTotalEquity(), 'the firm is still notionally solvent');
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
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0)
        );
        // The draw is booked as wholesale debt at the revolver's price: market rate 5% plus 100 bps.
        $this->debtEngine->expects($this->once())->method('issueDebt')
            ->with($stock, 95_000_000.0, $this->equalToWithDelta(0.06, 1e-9))
            ->willReturnCallback(static function (Stock $s, float $amount): void {
                $s->setWholesaleDebt((string) ((float) $s->getWholesaleDebt() + $amount));
            });
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertFalse($stock->isPaymentDefault(), 'a maturity the revolver funded is not a default');
        $this->assertEqualsWithDelta(0.0, $ctx->unfundedMaturity, 1e-6);
        $this->assertEqualsWithDelta(100_000_000.0, $ctx->principalRepaid, 1.0, 'the whole maturity reached the bondholders');
        $this->assertEqualsWithDelta(2_000_000_000.0, (float) $stock->getWholesaleDebt(), 1.0, 'the notes were refinanced onto the line, not added to it');
        $this->assertEqualsWithDelta(30_000_000.0, $ctx->newTreasury, 1.0, 'the draw passed straight through to the bondholders');
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
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0)
        );
        $ratesIssuedAt = [];
        $this->debtEngine->method('issueDebt')->willReturnCallback(
            static function (Stock $s, float $amount, float $rate) use (&$ratesIssuedAt): void {
                $ratesIssuedAt[] = $rate;
            }
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($ctx->failedEmergencyBorrow);
        $this->assertCount(1, $ratesIssuedAt, 'the only borrowing is the revolver draw');
        $this->assertEqualsWithDelta(0.06, $ratesIssuedAt[0], 1e-9, 'priced at the revolver spread, not the emergency one');
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

    private function createAllocationContext(Stock $stock, float $stockCompensation, float $currentPrice = 50.0): CapitalAllocationContext
    {
        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 4.0,
            quarterlyFcfPerShare: 1.0,
            currentPrice: $currentPrice,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 100_000_000,
            stockCompensation: $stockCompensation
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
            ebitda: 170_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 15.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

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
        $ctx->isFinancial = true;
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

        $haircut = \App\Service\Math\FinancialConstants::EARNING_ASSET_FIRE_SALE_HAIRCUT;
        $this->assertEqualsWithDelta($ctx->assetSaleProceeds * $haircut / (1.0 - $haircut), $ctx->assetSaleLoss, 1.0, 'the loss is the haircut on what was sold');
        $this->assertLessThan(5_000_000_000.0, (float) $stock->getEarningAssets());
        $this->assertLessThan(100_000_000.0, (float) $stock->getCreditLossAllowance(), 'the allowance on the slice sold leaves with it');
        $this->assertEqualsWithDelta($equityBefore - $ctx->assetSaleLoss, (float) $stock->getTotalEquity(), 1.0, 'the loss is charged to equity');

        $assets = $stock->getTotalAssets();
        $claims = $stock->getTotalLiabilities() + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, 1.0, 'the sheet still balances after the fire sale');
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
            mt_srand(7);
            $stock = new Stock();
            $stock->setTicker('SAT');
            $stock->setTotalEquity('1000000000.00');
            $stock->setCorporateTreasury('200000000.00');
            $stock->setRoicTtm('0.60'); // the AVERAGE return: comfortably above either hurdle

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
            $ctx->newTreasury = 200_000_000.0; // above the cash target, well short of hoarder territory
            $ctx->debtActionTaken = false;     // no debt this quarter, so nothing forces a deployment
            $ctx->health = new DebtHealthDTO(
                grossCost: 0.05, effectiveCost: 0.05, cashYield: 0.04, isNegativeCarry: false, isSevereNegativeCarry: false,
                interestCoverage: 15.0, wantsToPaydownDebt: false, canIssueDebt: false, debtTolerance: 1.0, wacc: $hurdle,
                costOfEquity: $hurdle + 0.02, leveredBeta: 1.0, rawMetrics: $ctx->health->rawMetrics, isLiquidityCrisis: false,
                isLiquidityWarning: false, isUnderLeveraged: false
            );

            // Saturation has taken the marginal return to 40%: the pacing draw sits at its 95% ceiling either way.
            $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
            $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.20);
            $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.40);

            $capExEngine = $this->createMock(CapExEngine::class);
            $capExEngine->expects($hurdle > 0.40 ? $this->never() : $this->once())->method('allocateGrowthCapEx');
            $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $capExEngine, $this->mathUtility);

            $engine->executeCorporateStrategy($ctx);

            return $ctx;
        };

        $cleared = $deploy(0.30); // marginal 40% > hurdle 30%
        $this->assertGreaterThan(0.0, $cleared->organicCapex, 'With the marginal return above the hurdle the firm expands.');

        $refused = $deploy(0.50); // average 60% > hurdle 50% > marginal 40%
        $this->assertEqualsWithDelta(0.0, $refused->organicCapex, 1e-9, 'An average return above the hurdle is not a reason to deploy when the next dollar earns less than it costs.');
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
        $ctx->isFinancial = true;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;
        $ctx->newTreasury = 500_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->retainedEarningsThisQuarter = 100_000_000.0;
        $ctx->health = new DebtHealthDTO(
            grossCost: 0.04, effectiveCost: 0.04, cashYield: 0.03, isNegativeCarry: false, isSevereNegativeCarry: false,
            interestCoverage: 10.0, wantsToPaydownDebt: false, canIssueDebt: false, debtTolerance: 15.0, wacc: 0.08,
            costOfEquity: 0.09, leveredBeta: 1.0, rawMetrics: $ctx->health->rawMetrics, isLiquidityCrisis: false,
            isLiquidityWarning: false, isUnderLeveraged: true
        );

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
        $ctx->isFinancial = true;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;
        $ctx->newTreasury = 500_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->retainedEarningsThisQuarter = 0.0;
        $ctx->health = new DebtHealthDTO(
            grossCost: 0.04, effectiveCost: 0.04, cashYield: 0.03, isNegativeCarry: false, isSevereNegativeCarry: false,
            interestCoverage: 10.0, wantsToPaydownDebt: false, canIssueDebt: false, debtTolerance: 15.0, wacc: 0.08,
            costOfEquity: 0.09, leveredBeta: 1.0, rawMetrics: $ctx->health->rawMetrics, isLiquidityCrisis: false,
            isLiquidityWarning: false, isUnderLeveraged: false
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->executeCorporateStrategy($ctx);

        $this->assertEqualsWithDelta(0.0, $ctx->loanOriginations, 1.0, 'a bank below its capital buffer cannot add risk-weighted assets');
        $this->assertEqualsWithDelta(2_000_000_000.0, (float) $stock->getEarningAssets(), 1.0);
        $this->assertEqualsWithDelta(500_000_000.0, $ctx->newTreasury, 1.0);
    }
}

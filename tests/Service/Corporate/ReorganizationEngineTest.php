<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\GoingConcernDTO;
use App\DTO\MacroStateDTO;
use App\DTO\ReorganizationPlanDTO;
use App\Entity\Stock;
use App\Service\Corporate\ReorganizationEngine;
use PHPUnit\Framework\TestCase;

/**
 * The plan a Chapter 11 court would confirm, and what confirming it does to the firm's books.
 *
 * The fixture is a 'General' operating firm: minimum coverage 2.0 plus the 1.5 buffer new borrowing needs,
 * a 3.0x net-debt covenant, and exit notes at the 4% five-year plus its 1% spread.
 */
final class ReorganizationEngineTest extends TestCase
{
    private const EXIT_RATE = 0.05;
    private const REQUIRED_COVERAGE = 3.5;

    /**
     * Feasibility (§1129(a)(11)) sizes the exit debt as the tightest of what the reorganized firm can carry
     * on its own lender test (coverage, then the leverage covenant) and what the business is worth.
     */
    public function testExitDebtIsTheTightestOfCoverageCovenantAndValue(): void
    {
        $engine = new ReorganizationEngine();

        $coverageBinds = $engine->planReorganization($this->firm(), $this->macro(), $this->going(ebit: 100.0, ebitda: 1_000.0, assetValue: 10_000.0, cash: 0.0, claims: 20_000.0));
        $this->assertEqualsWithDelta(100.0 / (self::EXIT_RATE * self::REQUIRED_COVERAGE), $coverageBinds->exitDebt, 1e-9);

        $covenantBinds = $engine->planReorganization($this->firm(), $this->macro(), $this->going(ebit: 1_000.0, ebitda: 100.0, assetValue: 10_050.0, cash: 50.0, claims: 20_000.0));
        $this->assertEqualsWithDelta((3.0 * 100.0) + 50.0, $covenantBinds->exitDebt, 1e-9);

        $valueBinds = $engine->planReorganization($this->firm(), $this->macro(), $this->going(ebit: 1_000.0, ebitda: 1_000.0, assetValue: 400.0, cash: 0.0, claims: 20_000.0));
        $this->assertEqualsWithDelta(400.0, $valueBinds->exitDebt, 1e-9);

        $nothingToService = $engine->planReorganization($this->firm(), $this->macro(), $this->going(ebit: -50.0, ebitda: 20.0, assetValue: 10.0, cash: 10.0, claims: 1_000.0));
        $this->assertSame(0.0, $nothingToService->exitDebt, 'a business with no operating income carries no debt out');
        $this->assertEqualsWithDelta(1_000.0, $nothingToService->convertedClaims, 1e-9);
    }

    /**
     * Absolute priority (§1129(b)): the old holders keep only what the firm's assets are worth beyond every
     * claim. A solvent but illiquid firm's creditors are made whole in new equity, exactly; an insolvent
     * one's old shares get nothing; a firm that can carry all its debt keeps its shareholders intact.
     */
    public function testAbsolutePriorityLeavesTheOldHoldersOnlyTheResidual(): void
    {
        $engine = new ReorganizationEngine();

        // Assets worth 1,000 against 900 of claims, 400 of which the firm can carry (coverage caps it at 20 / 0.175).
        $solvent = $engine->planReorganization($this->firm(), $this->macro(), $this->going(ebit: 70.0, ebitda: 10_000.0, assetValue: 1_000.0, cash: 0.0, claims: 900.0));
        $this->assertEqualsWithDelta(400.0, $solvent->exitDebt, 1e-9);
        $this->assertEqualsWithDelta(100.0 / 600.0, $solvent->oldEquityShare, 1e-9);
        $this->assertEqualsWithDelta(
            $solvent->convertedClaims,
            (1.0 - $solvent->oldEquityShare) * $solvent->reorganizedEquityValue,
            1e-9,
            'solvent: the creditors receive new equity worth exactly the claims they gave up'
        );

        $insolvent = $engine->planReorganization($this->firm(), $this->macro(), $this->going(ebit: 70.0, ebitda: 10_000.0, assetValue: 500.0, cash: 0.0, claims: 900.0));
        $this->assertSame(0.0, $insolvent->oldEquityShare, 'insolvent: the old shares are out of the money');

        $reinstated = $engine->planReorganization($this->firm(), $this->macro(), $this->going(ebit: 700.0, ebitda: 10_000.0, assetValue: 5_000.0, cash: 0.0, claims: 900.0));
        $this->assertEqualsWithDelta(900.0, $reinstated->exitDebt, 1e-9);
        $this->assertSame(0.0, $reinstated->convertedClaims);
        $this->assertSame(1.0, $reinstated->oldEquityShare, 'every claim reinstated: the shareholders keep the company');
    }

    /**
     * The exchange is recorded at carrying value: liabilities fall by the converted claims and equity rises by
     * the same amount, so a balanced sheet stays balanced. The revolver is senior and is reinstated first.
     */
    public function testConfirmationSwapsDebtForEquityAndTheSheetStillBalances(): void
    {
        $stock = $this->ledgeredFirm(wholesale: 1_500.0, revolver: 300.0);
        $this->assertBalanced($stock);
        $equityBefore = (float) $stock->getTotalEquity();

        (new ReorganizationEngine())->applyPlan($stock, $this->plan(claims: 1_800.0, exitDebt: 200.0, oldEquityShare: 0.0, equityValue: 500.0));

        $this->assertEqualsWithDelta(200.0, (float) $stock->getRevolverDrawn(), 1e-9, 'the senior revolver is reinstated first');
        $this->assertEqualsWithDelta(0.0, (float) $stock->getWholesaleDebt(), 1e-9, 'the term notes take the exchange');
        $this->assertEqualsWithDelta($equityBefore + 1_600.0, (float) $stock->getTotalEquity(), 1e-6);
        $this->assertBalanced($stock);

        $stock = $this->ledgeredFirm(wholesale: 1_500.0, revolver: 100.0);
        (new ReorganizationEngine())->applyPlan($stock, $this->plan(claims: 1_600.0, exitDebt: 400.0, oldEquityShare: 0.0, equityValue: 500.0));
        $this->assertEqualsWithDelta(100.0, (float) $stock->getRevolverDrawn(), 1e-9);
        $this->assertEqualsWithDelta(300.0, (float) $stock->getWholesaleDebt(), 1e-9);
        $this->assertBalanced($stock);
    }

    /**
     * When the old shares are cancelled the creditors receive the same number of new ones, the reorganized
     * equity opens at its value per share, and fresh-start accounting (ASC 852-10-45-19: the old holders own
     * under half) eliminates the deficit. Everything queued against the old shares goes with them.
     */
    public function testCancelledSharesAreReissuedToTheCreditorsAtTheReorganizedValue(): void
    {
        $stock = $this->ledgeredFirm(wholesale: 1_500.0, revolver: 0.0);
        $stock->setSharesOutstanding('100');
        $stock->setRetainedEarnings('-2000');
        $stock->setShortInterestShares('12.00');
        $stock->addCorporateFlowBacklog(-40.0);
        $stock->setPaymentDefault(true);
        $stock->setQuartersInDefault(3);

        $price = (new ReorganizationEngine())->applyPlan($stock, $this->plan(claims: 1_500.0, exitDebt: 300.0, oldEquityShare: 0.0, equityValue: 900.0));

        $this->assertSame(9.0, $price);
        $this->assertSame(9.0, (float) $stock->getPrice());
        $this->assertEqualsWithDelta(100.0, (float) $stock->getSharesOutstanding(), 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $stock->getRetainedEarnings(), 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $stock->getShortInterestShares(), 1e-9);
        $this->assertSame(0.0, $stock->getCorporateFlowBacklog());
        $this->assertFalse($stock->isPaymentDefault());
        $this->assertSame(0, $stock->getQuartersInDefault());
        $this->assertEqualsWithDelta(self::EXIT_RATE, (float) $stock->getHistoricalFixedRate(), 1e-9, 'the exit notes are new paper');
    }

    /**
     * When the old holders keep a share, they keep their shares; the creditors are issued the rest, and that
     * paper reaches the tape through the flow channel. Holders keeping a majority stay on historical-cost
     * books: fresh start needs them below half.
     */
    public function testRetainedHoldersAreDilutedByTheCreditorIssue(): void
    {
        $stock = $this->ledgeredFirm(wholesale: 1_500.0, revolver: 0.0);
        $stock->setSharesOutstanding('100');
        $stock->setRetainedEarnings('-2000');

        (new ReorganizationEngine())->applyPlan($stock, $this->plan(claims: 1_500.0, exitDebt: 1_000.0, oldEquityShare: 0.8, equityValue: 1_000.0));

        $this->assertEqualsWithDelta(125.0, (float) $stock->getSharesOutstanding(), 1e-9, 'the old 100 shares are 80% of the new 125');
        $this->assertEqualsWithDelta(-25.0, $stock->getCorporateFlowBacklog(), 1e-9);
        $this->assertEqualsWithDelta(8.0, (float) $stock->getPrice(), 1e-9);
        $this->assertEqualsWithDelta(-2000.0, (float) $stock->getRetainedEarnings(), 1e-9, 'no fresh start while the old holders keep control');
    }

    public function testAReinstatementLeavesTheSharesAndPriceAlone(): void
    {
        $stock = $this->ledgeredFirm(wholesale: 900.0, revolver: 0.0);
        $stock->setSharesOutstanding('100');
        $stock->setPrice('42.00');
        $stock->setPaymentDefault(true);

        $price = (new ReorganizationEngine())->applyPlan($stock, $this->plan(claims: 900.0, exitDebt: 900.0, oldEquityShare: 1.0, equityValue: 4_100.0));

        $this->assertSame(42.0, $price);
        $this->assertSame('42.00', $stock->getPrice());
        $this->assertEqualsWithDelta(100.0, (float) $stock->getSharesOutstanding(), 1e-9);
        $this->assertEqualsWithDelta(900.0, (float) $stock->getWholesaleDebt(), 1e-9);
        $this->assertFalse($stock->isPaymentDefault());
    }

    private function firm(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('PLAN');
        $stock->setIndustry('General');
        $stock->setCreditSpread('0.01');

        return $stock;
    }

    /** A 'General' firm with a working-capital and plant ledger that balances before the plan. */
    private function ledgeredFirm(float $wholesale, float $revolver): Stock
    {
        $stock = $this->firm();
        $stock->setSharesOutstanding('100');
        $stock->setPrice('1.00');
        $stock->setCorporateTreasury('50');
        $stock->setReceivables('200');
        $stock->setReceivablesAllowance('0');
        $stock->setInventory('100');
        $stock->setPayables('150');
        $stock->setGrossPpe('900');
        $stock->setAccumulatedDepreciation('100');
        $stock->setPpeTaxBasis('800');
        $stock->setWholesaleDebt((string) $wholesale);
        $stock->setRevolverDrawn((string) $revolver);
        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));

        return $stock;
    }

    private function assertBalanced(Stock $stock): void
    {
        $this->assertEqualsWithDelta($stock->getTotalAssets() - $stock->getTotalLiabilities(), (float) $stock->getTotalEquity(), 1e-6, 'assets less liabilities must equal equity');
    }

    private function macro(): MacroStateDTO
    {
        return new MacroStateDTO(yield5yEma: 0.04);
    }

    private function going(float $ebit, float $ebitda, float $assetValue, float $cash, float $claims): GoingConcernDTO
    {
        return new GoingConcernDTO(trailingEbit: $ebit, trailingEbitda: $ebitda, assetValue: $assetValue, cash: $cash, claims: $claims);
    }

    private function plan(float $claims, float $exitDebt, float $oldEquityShare, float $equityValue): ReorganizationPlanDTO
    {
        return new ReorganizationPlanDTO(
            claims: $claims,
            exitDebt: $exitDebt,
            convertedClaims: $claims - $exitDebt,
            oldEquityShare: $oldEquityShare,
            reorganizedEquityValue: $equityValue,
            exitRate: self::EXIT_RATE,
        );
    }
}

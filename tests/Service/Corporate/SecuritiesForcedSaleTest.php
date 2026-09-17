<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\CapitalAllocationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\SecuritiesBookService;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Market\BondPricingEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The quarter the paper loss becomes a solvency event.
 *
 * A bank carrying an unrealized loss is not in trouble; a bank that has to SELL while carrying one is. The
 * sale is what converts a disclosure into cash that does not arrive, and the held-to-maturity tranche — the
 * half that was never in reported equity — is where the fresh hit to book value comes from.
 *
 * Before this existed the same path applied a flat five percent haircut and called it a fire sale, so the
 * depth of the discount never depended on what rates had actually done to the book.
 */
final class SecuritiesForcedSaleTest extends TestCase
{
    private const GROSS_BOOK = 400_000_000_000.0;
    private const EQUITY = 60_000_000_000.0;
    private const CASH_FLOOR = 40_000_000_000.0;

    private TreasuryEngine $engine;
    private ReflectionMethod $liquidate;

    protected function setUp(): void
    {
        $this->engine = (new ReflectionClass(TreasuryEngine::class))->newInstanceWithoutConstructor();
        $securities = new ReflectionProperty(TreasuryEngine::class, 'securitiesBook');
        $securities->setValue($this->engine, new SecuritiesBookService(new BondPricingEngine(new MathUtility())));

        $this->liquidate = new ReflectionMethod(TreasuryEngine::class, 'liquidateEarningAssetsForCash');
    }

    private function bank(float $mark): Stock
    {
        $stock = new Stock();
        $stock->setTicker('BANK');
        $stock->setIndustry('Banks - Diversified');
        $stock->setTotalEquity((string) self::EQUITY);
        $stock->setCorporateTreasury('1000000000');
        $stock->setEarningAssets((string) self::GROSS_BOOK);
        $stock->setCreditLossAllowance('0.0000');
        $stock->setFloatingDebtRatio('0.30');
        $stock->setSecuritiesDuration(4.5);
        $stock->setUnrealizedSecuritiesMark((string) $mark);

        return $stock;
    }

    private function runRun(Stock $stock): CapitalAllocationContext
    {
        $ctx = new CapitalAllocationContext($stock, new MacroStateDTO(), 1.0, 0.0, 10.0, 1_000_000_000.0);
        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->isFinancial = true;
        $ctx->newTreasury = 1_000_000_000.0;

        $this->liquidate->invoke($this->engine, $ctx, self::CASH_FLOOR);

        return $ctx;
    }

    /** With no mark carried, the path behaves exactly as it did before the phase: the haircut is the loss. */
    public function testAnUnmarkedBookSellsAtTheLiquidityHaircutAlone(): void
    {
        $stock = $this->bank(0.0);
        $ctx = $this->runRun($stock);

        self::assertGreaterThan(0.0, $ctx->assetSaleProceeds);
        self::assertEqualsWithDelta(
            $ctx->assetSaleProceeds / (1.0 - FinancialConstants::EARNING_ASSET_FIRE_SALE_HAIRCUT)
                * FinancialConstants::EARNING_ASSET_FIRE_SALE_HAIRCUT,
            $ctx->assetSaleLoss,
            1.0
        );
    }

    /**
     * The same run against a marked-down book raises less cash per dollar sold, so it has to sell more of
     * the book, and loses more doing it. That feedback is what a run actually is.
     */
    public function testAMarkedDownBookSellsMoreOfItselfAndLosesMoreDoingIt(): void
    {
        $clean = $this->bank(0.0);
        $marked = $this->bank(-60_000_000_000.0); // 15% under water

        $cleanCtx = $this->runRun($clean);
        $markedCtx = $this->runRun($marked);

        $cleanSold = self::GROSS_BOOK - (float) $clean->getEarningAssets();
        $markedSold = self::GROSS_BOOK - (float) $marked->getEarningAssets();

        self::assertGreaterThan($cleanSold, $markedSold, 'A deeper discount forces a bigger sale.');
        self::assertGreaterThan($cleanCtx->assetSaleLoss, $markedCtx->assetSaleLoss);
    }

    /**
     * Only the undisclosed tranche is charged to equity. The available-for-sale half was taken to equity
     * when the curve moved, and charging it again here would bill shareholders twice for one loss.
     *
     * Derived from what the sale actually consumed rather than recomputing the sizing, so this stays an
     * assertion about the accounting and does not quietly become a copy of the code under test.
     */
    public function testOnlyTheUndisclosedTrancheIsChargedToEquityOnTheSale(): void
    {
        $mark = -60_000_000_000.0;
        $stock = $this->bank($mark);
        $ctx = $this->runRun($stock);

        $sold = self::GROSS_BOOK - (float) $stock->getEarningAssets();
        $realized = $mark - (float) $stock->getUnrealizedSecuritiesMark();
        $haircutLoss = $sold * FinancialConstants::EARNING_ASSET_FIRE_SALE_HAIRCUT;
        $htmLoss = abs($realized) * FinancialConstants::DEFAULT_HTM_BOOK_SHARE;

        self::assertLessThan(0.0, $realized, 'The sale crystallized part of the loss.');
        self::assertEqualsWithDelta($haircutLoss + $htmLoss, $ctx->assetSaleLoss, 1_000.0);
        self::assertLessThan(
            $haircutLoss + abs($realized),
            $ctx->assetSaleLoss,
            'Charging the whole realized mark would double-bill the available-for-sale half.'
        );
    }

    /**
     * Securities go first, because they are the only part anyone bids for on the day. A sale big enough to
     * clear the whole securities portfolio realizes the whole mark and leaves nothing carried.
     */
    public function testSellingThroughTheWholeSecuritiesBookRealizesTheWholeMark(): void
    {
        $mark = -60_000_000_000.0;
        $stock = $this->bank($mark);
        $model = new CommercialBankBusinessModel();
        $securitiesBook = $model->resolveSecuritiesBook($stock);

        $this->runRun($stock);

        $sold = self::GROSS_BOOK - (float) $stock->getEarningAssets();
        self::assertGreaterThan($securitiesBook, $sold, 'This run is meant to sell past the securities book.');
        self::assertEqualsWithDelta(0.0, (float) $stock->getUnrealizedSecuritiesMark(), 1_000.0);
    }

    /** What is sold stops being carried: the mark leaves with the assets it was attached to. */
    public function testASmallSaleLeavesTheRestOfTheMarkOnTheBook(): void
    {
        $mark = -60_000_000_000.0;
        $stock = $this->bank($mark);
        $model = new CommercialBankBusinessModel();
        $securitiesBook = $model->resolveSecuritiesBook($stock);

        // A shortfall small enough that securities alone cover it, so part of the portfolio survives.
        $ctx = new CapitalAllocationContext($stock, new MacroStateDTO(), 1.0, 0.0, 10.0, 1_000_000_000.0);
        $ctx->strategy = $model;
        $ctx->isFinancial = true;
        $ctx->newTreasury = 1_000_000_000.0;
        $this->liquidate->invoke($this->engine, $ctx, 6_000_000_000.0);

        $sold = self::GROSS_BOOK - (float) $stock->getEarningAssets();
        $remaining = (float) $stock->getUnrealizedSecuritiesMark();

        self::assertLessThan($securitiesBook, $sold, 'Securities alone covered this shortfall.');
        self::assertEqualsWithDelta($mark * (1.0 - ($sold / $securitiesBook)), $remaining, 1_000.0);
        self::assertGreaterThan($mark, $remaining, 'Less book, less mark.');
        self::assertLessThan(0.0, $remaining, 'But not all of it is gone.');
    }

    /**
     * The same entry on the way up.
     *
     * A sale into a rally realizes a held-to-maturity gain, and the proceeds carry it either way. Flooring
     * the charge at zero credited the asset side and not equity, so the cash arrived from nowhere and the
     * sale did not balance. The available-for-sale half is still excluded, for the same reason it is on a
     * loss: equity already has it.
     */
    public function testTheUndisclosedTrancheIsCreditedToEquityOnASaleIntoARally(): void
    {
        $mark = 40_000_000_000.0;
        $stock = $this->bank($mark);
        $ctx = $this->runRun($stock);

        $sold = self::GROSS_BOOK - (float) $stock->getEarningAssets();
        $realized = $mark - (float) $stock->getUnrealizedSecuritiesMark();
        $haircutLoss = $sold * FinancialConstants::EARNING_ASSET_FIRE_SALE_HAIRCUT;
        $htmGain = $realized * FinancialConstants::DEFAULT_HTM_BOOK_SHARE;

        self::assertGreaterThan(0.0, $realized, 'The sale crystallized part of the gain.');
        self::assertEqualsWithDelta($haircutLoss - $htmGain, $ctx->assetSaleLoss, 1_000.0);
        self::assertLessThan(
            $haircutLoss,
            $ctx->assetSaleLoss,
            'The realized gain offsets the haircut instead of being dropped on the floor.'
        );
    }

    /** A rally is a gain, and a firm forced to sell into one raises MORE than carrying value. */
    public function testSellingIntoARallyRaisesMoreThanTheBookIsCarriedAt(): void
    {
        $loss = $this->runRun($this->bank(-40_000_000_000.0));
        $gain = $this->runRun($this->bank(40_000_000_000.0));

        self::assertGreaterThan($loss->assetSaleLoss * -1, $gain->assetSaleLoss * -1);
        self::assertLessThan($loss->assetSaleLoss, $gain->assetSaleLoss);
    }
}

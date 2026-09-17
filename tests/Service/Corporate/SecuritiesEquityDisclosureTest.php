<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Which readers of a balance sheet can see the securities mark, and which cannot.
 *
 * There are three different equity figures once a book is marked and they are supposed to disagree. Reported
 * equity carries the available-for-sale mark and not the held-to-maturity one. Economic equity carries both.
 * Regulatory capital carries whichever the institution elected. An institution can therefore publish an
 * intact capital ratio on a hollowed-out balance sheet, which is the state this whole mechanism exists to
 * make reachable.
 */
class SecuritiesEquityDisclosureTest extends TestCase
{
    private const EQUITY = 100_000_000_000.0;
    private const MARK = -20_000_000_000.0;

    private function bank(bool $aociFiltered): Stock
    {
        return StockBuilder::create('BANK')
            ->withTotalEquity(self::EQUITY)
            ->build()
            ->setUnrealizedSecuritiesMark((string) self::MARK)
            ->setAociFiltered($aociFiltered);
    }

    private function afsShare(): float
    {
        return 1.0 - FinancialConstants::DEFAULT_HTM_BOOK_SHARE;
    }

    public function testTheTrancheSplitOnTheEntityMatchesTheConstant(): void
    {
        $stock = $this->bank(true);

        self::assertEqualsWithDelta(self::MARK * $this->afsShare(), $stock->getRecognizedSecuritiesMark(), 1e-6);
        self::assertEqualsWithDelta(
            self::MARK * FinancialConstants::DEFAULT_HTM_BOOK_SHARE,
            $stock->getUnrecognizedSecuritiesMark(),
            1e-6
        );
    }

    /**
     * Economic equity is below reported equity by exactly the undisclosed tranche. This gap is the signal a
     * player is meant to be able to find in a filing before the market prices it.
     */
    public function testEconomicEquityIsShortOfReportedEquityByTheUndisclosedTranche(): void
    {
        $stock = $this->bank(true);

        self::assertEqualsWithDelta(
            self::EQUITY + (self::MARK * FinancialConstants::DEFAULT_HTM_BOOK_SHARE),
            $stock->getEconomicEquity(),
            1e-6
        );
        self::assertLessThan((float) $stock->getTotalEquity(), $stock->getEconomicEquity());
    }

    /** The Basel election, which is what decides whether a capital ratio reacts to the curve at all. */
    public function testTheAociElectionDecidesWhetherTheMarkReachesRegulatoryCapital(): void
    {
        $filtered = $this->bank(true);
        $included = $this->bank(false);

        self::assertEqualsWithDelta(
            self::EQUITY - (self::MARK * $this->afsShare()),
            $filtered->getRegulatoryEquity(),
            1e-6,
            'Filtering adds the loss back, so capital stands where it did.'
        );
        self::assertEqualsWithDelta(self::EQUITY, $included->getRegulatoryEquity(), 1e-6);
        self::assertGreaterThan($included->getRegulatoryEquity(), $filtered->getRegulatoryEquity());
    }

    /**
     * Two banks, one balance sheet, two regulatory fates. The filtered bank reports a capital ratio as
     * though the selloff never happened; the unfiltered one takes it in the quarter the curve moved.
     */
    public function testTwoIdenticalBanksReportDifferentCapitalRatiosOnTheSameLoss(): void
    {
        $model = new CommercialBankBusinessModel();

        $filtered = $this->bank(true)->setEarningAssets('900000000000')->setCorporateTreasury('50000000000');
        $included = $this->bank(false)->setEarningAssets('900000000000')->setCorporateTreasury('50000000000');

        $filteredRatio = $model->calculateCet1Ratio($filtered);
        $includedRatio = $model->calculateCet1Ratio($included);

        self::assertGreaterThan($includedRatio, $filteredRatio);

        // The unfiltered bank's ratio is short by the whole available-for-sale mark over the same RWA.
        $rwa = $model->calculateRiskWeightedAssets($filtered);
        self::assertEqualsWithDelta(
            abs(self::MARK * $this->afsShare()) / $rwa,
            $filteredRatio - $includedRatio,
            1e-9
        );
    }

    /**
     * Available-for-sale securities are carried at fair value, so the mark sits on the asset side as well as
     * in equity. Moving equity without moving an asset would put the difference into liabilities, where
     * every leverage and solvency screen in the market would read it as debt the firm never issued.
     */
    public function testTheRecognizedMarkIsCarriedOnTheAssetSideSoTheSheetStillBalances(): void
    {
        $clean = StockBuilder::create('BANK')->withTotalEquity(self::EQUITY)->withCorporateTreasury(50_000_000_000.0)->build();
        $marked = StockBuilder::create('BANK')->withTotalEquity(self::EQUITY)->withCorporateTreasury(50_000_000_000.0)->build()
            ->setUnrealizedSecuritiesMark((string) self::MARK);

        $assetDelta = $marked->getTotalAssets() - $clean->getTotalAssets();

        self::assertEqualsWithDelta(self::MARK * $this->afsShare(), $assetDelta, 1e-6);
        self::assertEqualsWithDelta(
            $marked->getTotalLiabilities(),
            $clean->getTotalLiabilities(),
            1e-6,
            'A valuation on the asset side is not a new liability.'
        );
    }

    public function testAnUnmarkedBookChangesNothing(): void
    {
        $stock = StockBuilder::create('BANK')->withTotalEquity(self::EQUITY)->build();

        self::assertSame(0.0, $stock->getRecognizedSecuritiesMark());
        self::assertSame(0.0, $stock->getUnrecognizedSecuritiesMark());
        self::assertEqualsWithDelta(self::EQUITY, $stock->getEconomicEquity(), 1e-6);
        self::assertEqualsWithDelta(self::EQUITY, $stock->getRegulatoryEquity(), 1e-6);
    }
}

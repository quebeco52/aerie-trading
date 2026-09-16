<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\AnchorHoldings;
use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConglomerateBusinessModel;
use App\Service\Model\Sector\InvestmentCompanyBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvestmentCompanyBusinessModelTest extends TestCase
{
    /** Capitalisation given to every holding in the fixture board; lands the portfolio within 1% of live seed prices. */
    private const FIXTURE_HOLDING_CAP = 300_000_000_000.0;

    private InvestmentCompanyBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new InvestmentCompanyBusinessModel();
    }

    /**
     * The multiple on a trust's book is one, so the term MarketEngine hands calculateFairValue() is net
     * asset value per share itself rather than a ROIC-scaled multiple of it. Struck across a wide range of
     * returns and hurdles because the whole point is that NEITHER moves it.
     */
    public function testBookMultipleIsAlwaysOneWhateverTheReturnOnCapital(): void
    {
        foreach ([0.01, 0.08, 0.12, 0.40] as $roic) {
            foreach ([0.04, 0.08, 0.15] as $hurdle) {
                $this->assertSame(1.0, $this->model->getIntrinsicPbMultiple($roic, $hurdle));
            }
        }

        // The parent prices the same book on what it earns over its hurdle, which is the behaviour this
        // model exists to replace.
        $this->assertGreaterThan(1.0, (new ConglomerateBusinessModel())->getIntrinsicPbMultiple(0.12, 0.08));
    }

    /**
     * The defining assertion: a trust's valuation does not move on its earnings line. Its reported profit
     * is dominated by the mark on the portfolio, which the book already carries, so pricing it through a
     * multiple would count the same information twice.
     */
    public function testFairValueIsNetAssetValueAndIgnoresTheEarningsLine(): void
    {
        $nav = 700.0;

        $boom = $this->model->calculateFairValue(earningsValue: 2_000.0, pbFairValue: $nav, normalizedEps: 90.0);
        $bust = $this->model->calculateFairValue(earningsValue: 1.0, pbFairValue: $nav, normalizedEps: -40.0);

        $this->assertSame($nav, $boom);
        $this->assertSame($nav, $bust);

        // The parent, on the same inputs, swings with earnings — 90% of its consensus is the earnings leg.
        $parent = new ConglomerateBusinessModel();
        $this->assertGreaterThan(
            $parent->calculateFairValue(1.0, $nav, -40.0),
            $parent->calculateFairValue(2_000.0, $nav, 90.0)
        );
    }

    /** The distribution is funded by dividends the trust RECEIVES, so it is a separate claim on value. */
    public function testDividendSupportBlendsIntoNetAssetValue(): void
    {
        $blended = $this->model->calculateFairValue(0.0, 700.0, 40.0, 900.0);

        $this->assertEqualsWithDelta(
            (700.0 * (1.0 - InvestmentCompanyBusinessModel::NAV_DDM_WEIGHT))
                + (900.0 * InvestmentCompanyBusinessModel::NAV_DDM_WEIGHT),
            $blended,
            1e-9
        );
    }

    /**
     * The holding-company discount is the defining feature of the class, and it is a sentiment index: it
     * gaps wide as the cycle turns down and closes in an expansion, while the assets underneath do neither.
     */
    public function testDiscountWidensIntoTheDownturnAndClampsBothEnds(): void
    {
        $neutral = $this->model->getStructuralValuationDiscount($this->neutralMacro());
        $slump   = $this->model->getStructuralValuationDiscount($this->neutralMacro(-0.025));
        $boom    = $this->model->getStructuralValuationDiscount($this->neutralMacro(0.030));

        $this->assertSame(InvestmentCompanyBusinessModel::NAV_DISCOUNT_BASE, $neutral);
        $this->assertGreaterThan($neutral, $slump);
        $this->assertLessThan($neutral, $boom);

        // Even at a cyclical extreme the trust is neither valued at its full assets nor at half of them.
        $this->assertSame(InvestmentCompanyBusinessModel::MAX_NAV_DISCOUNT, $this->model->getStructuralValuationDiscount($this->neutralMacro(-0.90)));
        $this->assertSame(InvestmentCompanyBusinessModel::MIN_NAV_DISCOUNT, $this->model->getStructuralValuationDiscount($this->neutralMacro(0.90)));
    }

    /**
     * The discount is a sentiment index, not only a cycle reading: frightened holders widen it while the
     * assets underneath do nothing at all, which is the Lee-Shleifer-Thaler result the class rests on.
     *
     * Twenty points either side of where the index sits at trend, since confidence enters as the residual:
     * the same twenty points read off its construction constant would be a cycle reading in disguise.
     */
    public function testConfidenceMovesTheDiscountWithTheCycleStandingStill(): void
    {
        $neutral = $this->model->getStructuralValuationDiscount($this->neutralMacro());

        $frightened = $this->neutralMacro(sentiment: MacroEngine::SENTIMENT_TREND_LEVEL - 20.0);
        $exuberant  = $this->neutralMacro(sentiment: MacroEngine::SENTIMENT_TREND_LEVEL + 20.0);

        $this->assertGreaterThan($neutral, $this->model->getStructuralValuationDiscount($frightened));
        $this->assertLessThan($neutral, $this->model->getStructuralValuationDiscount($exuberant));
    }

    private function neutralMacro(float $outputGapEma = 0.0, ?float $ppi = null, ?float $wage = null, ?float $sentiment = null): MacroStateDTO
    {
        $defaults = new MacroStateDTO();

        return new MacroStateDTO(
            outputGapEma: $outputGapEma,
            consumerSentimentIndexEma: $sentiment ?? MacroEngine::SENTIMENT_TREND_LEVEL,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION,
            producerPriceInflationEma: $ppi ?? $defaults->producerPriceInflationEma,
            wageGrowthEma: $wage ?? $defaults->wageGrowthEma,
        );
    }

    private function deterministicMath(): MathUtility
    {
        return new class extends MathUtility {
            public function generatePersistentZ(float $previousZ, float $phi, ?float $commonInnovation = null, float $commonLoading = 0.0): float
            {
                return 0.0;
            }

            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
    }

    /** A sphere whose stakes have never been priced: its books cannot be opened, and the model says so rather than guessing. */
    private function bareBrkw(float $treasury = 250_000_000_000.0): Stock
    {
        $stock = new Stock();
        $stock->setTicker('BRKW');
        $stock->setBeta('0.45');
        $stock->setTotalEquity('1880000000000');
        $stock->setCorporateTreasury((string) $treasury);

        return $stock;
    }

    /**
     * The same sphere carrying a real mark, struck through the ledger rather than typed in. Derived on
     * purpose: a portfolio written as a number beside the stake list goes stale the first time the list is
     * retuned, which is how this class lost three composition dials in a row.
     */
    private function brkw(float $treasury = 250_000_000_000.0): Stock
    {
        $stock = $this->bareBrkw($treasury);
        $board = [];

        foreach (array_keys(AnchorHoldings::forHolder('BRKW')) as $ticker) {
            $held = new Stock();
            $held->setTicker($ticker);
            $held->setPrice((string) (self::FIXTURE_HOLDING_CAP / 1_000_000_000.0));
            $held->setSharesOutstanding('1000000000');
            $board[] = $held;
        }

        $ledger = new AnchorStakeLedger();
        $ledger->beginTick($board);
        // The first mark opens the position and moves no equity, exactly as at seed.
        $ledger->markToMarket($stock);

        return $stock;
    }

    /** What the fixture's stakes are worth once the ledger has marked them against the flat board. */
    private function fixturePortfolio(): float
    {
        return array_sum(AnchorHoldings::forHolder('BRKW')) * self::FIXTURE_HOLDING_CAP;
    }

    /**
     * A trust owns no factories, so the parent's industrial stream must not appear on it — and the segments
     * it DOES report are the inverse of its asset mix: the wholly-owned minority of the portfolio is the
     * majority of the income statement, because only a controlled company is consolidated.
     */
    public function testReportsAHoldingPortfolioAndNoFactory(): void
    {
        $result = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $this->assertSame(
            ['wholly_owned', 'listed_portfolio', 'financial_investments'],
            array_keys($result->streamRevenue)
        );
        $this->assertArrayNotHasKey('industrial_manufacturing', $result->streamRevenue);

        // The portfolio is the smaller half of the income statement, because consolidation takes a whole
        // top line while a listed stake contributes only its dividend. Struck from the fixture's own
        // balance sheet, so retuning the stake list moves the test with the model rather than against it.
        $total = array_sum($result->streamRevenue);
        $this->assertGreaterThan(
            $result->streamRevenue['listed_portfolio'],
            $result->streamRevenue['wholly_owned'],
            'The revenue mix must invert the asset mix; that inversion is the whole point of the model.'
        );

        $expected = $this->expectedStreamShares($this->brkw());
        $this->assertEqualsWithDelta($expected['wholly_owned'], $result->streamRevenue['wholly_owned'] / $total, 0.001);
        $this->assertEqualsWithDelta($expected['listed_portfolio'], $result->streamRevenue['listed_portfolio'] / $total, 0.001);
        // The three sleeves are the whole balance sheet and add back to capital employed exactly. A sleeve
        // left over, or counted twice, puts part of the book in no stream — what every NAV dial ended up
        // doing.
        $sphere = $this->brkw();
        $consolidated = $sphere->getInvestedCapital() - $this->fixturePortfolio();
        $this->assertGreaterThan(0.0, $consolidated, 'The portfolio must leave a real consolidated sleeve.');
        $this->assertEqualsWithDelta(
            $sphere->getInvestedCapital() + 250_000_000_000.0,
            $consolidated + $this->fixturePortfolio() + 250_000_000_000.0,
            1.0
        );

        // A calm quarter charges nothing beyond the cost base it was handed.
        $this->assertEqualsWithDelta(0.08, $result->clampedMargin, 1e-9);
    }

    /**
     * The normalised stream shares a given balance sheet should produce, computed from the three balances.
     * Nothing typed, so retuning the stake list or a conversion rate moves the expectation with the model.
     *
     * @return array<string, float>
     */
    private function expectedStreamShares(Stock $stock): array
    {
        $treasury = max(0.0, (float) $stock->getCorporateTreasury());
        $listed = max(0.0, (float) ($stock->getListedStakesCarrying() ?? 0.0));
        $consolidated = max(0.0, $stock->getInvestedCapital() - $listed);

        $weights = [
            'wholly_owned' => $consolidated * InvestmentCompanyBusinessModel::WHOLLY_OWNED_ASSET_TURNOVER,
            'listed_portfolio' => $listed * InvestmentCompanyBusinessModel::LISTED_DIVIDEND_YIELD,
            'financial_investments' => $treasury * InvestmentCompanyBusinessModel::TREASURY_INCOME_YIELD,
        ];
        $total = array_sum($weights);

        return array_map(static fn (float $w): float => $total > 0.0 ? $w / $total : 0.0, $weights);
    }

    /** Once marked, the listed sleeve is READ from the balance sheet and nothing else has a say in it. */
    public function testTheListedSleeveFollowsTheMarkedStakesAndTheRestOfTheBookFollowsIt(): void
    {
        $macro = $this->neutralMacro();
        $math = $this->deterministicMath();

        $atMarket = $this->brkw();
        $halved = $this->brkw();
        $halved->setListedStakesCarrying((string) ($this->fixturePortfolio() / 2.0));

        $full = $this->model->computeActualFinancials($atMarket, 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $math);
        $small = $this->model->computeActualFinancials($halved, 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $math);

        $fullShare = $full->streamRevenue['listed_portfolio'] / array_sum($full->streamRevenue);
        $smallShare = $small->streamRevenue['listed_portfolio'] / array_sum($small->streamRevenue);

        $this->assertLessThan(
            $fullShare,
            $smallShare,
            'A portfolio worth half as much must upstream a smaller share of the group income.'
        );

        // A smaller portfolio hands the subsidiaries a LARGER share of the book: they did not lose value,
        // so they are more of what is left. Both expectations come from the balances themselves.
        $this->assertEqualsWithDelta($this->expectedStreamShares($halved)['listed_portfolio'], $smallShare, 0.001);
        $this->assertGreaterThan(
            $this->expectedStreamShares($atMarket)['wholly_owned'],
            $this->expectedStreamShares($halved)['wholly_owned'],
            'A halved portfolio must leave the subsidiaries a bigger share of the book, not the same one.'
        );
    }

    /**
     * An unpriced sphere reports no listed sleeve and refuses to open its books, rather than inventing a
     * share of them. The three dials this class used to carry each went stale within one retune — the last
     * claimed 60% of book against stakes worth 51.5%, a gap of 160 billion.
     */
    public function testAnUnpricedSphereRefusesToOpenItsBooksRatherThanGuessAtThem(): void
    {
        $bare = $this->bareBrkw();

        $this->assertNull($bare->getListedStakesCarrying());
        $this->assertNull(
            $this->model->getOpeningInvestmentAssets($bare),
            'A declared sphere with no price for its stakes must refuse, so the plant ledger stays closed.'
        );

        $result = $this->model->computeActualFinancials(
            $bare, 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $this->assertArrayNotHasKey('listed_portfolio', $result->streamRevenue);
    }

    /** A trust that declares no holdings is not waiting for a price — it simply has no portfolio. */
    public function testATrustWithNoDeclaredHoldingsOpensItsBooksOnTheWholeBalanceSheet(): void
    {
        $noHoldings = $this->bareBrkw();
        $noHoldings->setTicker('NOPE');

        $this->assertSame([], AnchorHoldings::forHolder('NOPE'));
        $this->assertSame(0.0, $this->model->getOpeningInvestmentAssets($noHoldings));
        $this->assertEqualsWithDelta(
            $noHoldings->getInvestedCapital(),
            $this->model->getPlantCapital($noHoldings, $noHoldings->getInvestedCapital()),
            1.0
        );
    }

    /**
     * "Investment Companies" is a filing category, not a product market. A sphere sells no units into a
     * downward-sloping demand curve, so the capacity balance, the rival drain and the Cournot haircut all
     * have to stand down — and BRKW is the only firm in that industry, where the balance would otherwise
     * price it against nobody.
     */
    public function testASphereSellsNoUnitsAndCompetesInNoProductMarket(): void
    {
        $this->assertSame(0.0, $this->model->getIndustrySubstitutability());

        $stock = $this->brkw();
        $stock->setIndustry('Investment Companies');
        $stock->setTotalRevenue('180000000000');

        $this->assertSame(
            0.0,
            \App\Service\Math\CorporateMetrics::getInstance()->calculateCournotPriceHaircut(
                $stock,
                0.25,
                $stock->getInvestedCapital(),
                $this->neutralMacro()
            ),
            'A trust takes no marginal-revenue haircut: it has no marginal unit to sell.'
        );
    }

    /**
     * The discount control mechanism: retiring a share below net asset value raises the value of every
     * share left, by the discount times the fraction retired, whatever the portfolio does next.
     */
    public function testRepurchasingBelowNetAssetValueIsAccretive(): void
    {
        $stock = $this->brkw();
        $stock->setSharesOutstanding('1000000000');

        // NAV per share is 1880: equity 1.88tn over a billion shares.
        $this->assertEqualsWithDelta(0.30, $this->model->resolveRepurchaseAccretion($stock, 1316.0), 1e-9);
        $this->assertSame(0.0, $this->model->resolveRepurchaseAccretion($stock, 1880.0));
        $this->assertSame(0.0, $this->model->resolveRepurchaseAccretion($stock, 2500.0), 'A premium is not accretion.');
        $this->assertSame(0.0, $this->model->resolveRepurchaseAccretion($stock, 0.0));
    }

    /** An operating company's repurchase case is the return it earns, which the earnings test already asks. */
    public function testAnOperatingCompanyClaimsNoBookAccretion(): void
    {
        $standard = new \App\Service\Model\Sector\StandardCorporateBusinessModel();

        $this->assertSame(0.0, $standard->resolveRepurchaseAccretion($this->brkw(), 100.0));
    }

    /**
     * The consolidated sleeve must survive a portfolio that runs away with the book.
     *
     * Struck as a share of NET ASSET VALUE it does not: the deferred tax on an unrealised gain grows the
     * stakes faster than the equity they sit in, so around four times the portfolio's value the residual
     * reaches zero and the subsidiaries stop being reported at all — a cliff, from a denominator. Over
     * invested capital, which carries the debt and the deferred tax, a mark moves both sides equally and
     * the subsidiaries stay exactly where they were, which is what actually happened to them.
     */
    public function testTheSubsidiariesSurviveAPortfolioThatRunsAwayWithTheBook(): void
    {
        $macro = $this->neutralMacro();
        $math = $this->deterministicMath();

        $opening = 968_200_000_000.0;
        $balances = [];

        foreach ([1.0, 2.0, 3.0, 5.0] as $multiple) {
            $stock = $this->brkw();
            $stock->setWholesaleDebt('140000000000');

            // The mark as the ledger books it: the asset in full, the tax withheld, the rest to equity.
            $gain = $opening * ($multiple - 1.0);
            $stock->setListedStakesCarrying((string) ($opening * $multiple));
            $stock->setDeferredTaxLiability((string) ($gain * 0.21));
            $stock->setTotalEquity((string) (1_880_000_000_000.0 + ($gain * 0.79)));

            $result = $this->model->computeActualFinancials(
                $stock, 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $math
            );

            $this->assertArrayHasKey(
                'wholly_owned',
                $result->streamRevenue,
                "The consolidated stream vanished at {$multiple}x the portfolio."
            );

            $balances[] = $stock->getInvestedCapital() - (float) $stock->getListedStakesCarrying();
        }

        // Invariant: the subsidiaries are worth what they were worth, whatever the portfolio did.
        foreach ($balances as $balance) {
            $this->assertEqualsWithDelta($balances[0], $balance, 1.0);
        }
    }

    /** Shares in another listed company are not plant: they wear out at no rate and no CapEx replaces them. */
    public function testThePortfolioIsNotDepreciatedAsPlant(): void
    {
        $stock = $this->brkw();
        $investedCapital = $stock->getInvestedCapital();

        // The investment line opens at the mark and nowhere else, so the plant it leaves is never the
        // portfolio itself.
        $opening = $this->model->getOpeningInvestmentAssets($stock);
        $this->assertEqualsWithDelta($this->fixturePortfolio(), $opening, 1.0);

        $this->assertEqualsWithDelta(
            $investedCapital - $this->fixturePortfolio(),
            $this->model->getPlantCapital($stock, $investedCapital),
            1.0,
            'Plant must be what is left after the portfolio, not the whole balance sheet.'
        );
    }

    /** Once marked, the investment line is the mark — the dial has no say in what the plant excludes. */
    public function testPlantFollowsTheMarkedPortfolio(): void
    {
        $stock = $this->brkw();
        $stock->setListedStakesCarrying('900000000000');

        $this->assertEqualsWithDelta(900_000_000_000.0, $this->model->getOpeningInvestmentAssets($stock), 1.0);
        $this->assertEqualsWithDelta(
            $stock->getInvestedCapital() - 900_000_000_000.0,
            $this->model->getPlantCapital($stock, $stock->getInvestedCapital()),
            1.0
        );
    }

    /**
     * The parent falls back to the capital proxy when no plant ledger exists. For a sphere that proxy IS
     * the portfolio, so a trust consolidating nothing would depreciate its shareholdings.
     */
    public function testASphereWithNoPlantDepreciatesNothing(): void
    {
        $stock = $this->brkw();

        $this->assertSame(0.0, $stock->getNetPpe());
        $this->assertSame(0.0, $this->model->getDepreciableBase($stock));
    }

    /** A trust that controls nothing at all is still a trust: the stream is not drawn and leaves no hole. */
    public function testASphereThatConsolidatesNothingReportsNoOperatingStream(): void
    {
        // Nothing is declared any more, so a sphere consolidates nothing by OWNING everything: stakes worth
        // the whole book less its cash leave no residual for a consolidated sleeve to be.
        $stock = $this->brkw();
        $stock->setListedStakesCarrying((string) (1_880_000_000_000.0 - 250_000_000_000.0));

        $result = $this->model->computeActualFinancials(
            $stock, 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $this->assertSame(['listed_portfolio', 'financial_investments'], array_keys($result->streamRevenue));
        $this->assertArrayNotHasKey('wholly_owned', $result->streamRevenue);

        // Nothing is consolidated, so nothing buys inputs and the cost base is exactly what it was handed.
        $this->assertEqualsWithDelta(0.08, $result->clampedMargin, 1e-9);
    }

    /**
     * The hoard must be paid for once. StandardTreasuryTrait pays every corporate the front rate on its
     * excess cash, and here the same balance is already a revenue stream running the contrarian float
     * physics over it — so the trait's version has to be off, or the treasury earns twice.
     */
    public function testTheTreasuryIsNotPaidForTwice(): void
    {
        $stock = $this->brkw();
        $stock->setTotalRevenue('170000000000');
        $stock->setWholesaleDebt('140000000000');

        $macro = $this->neutralMacro();
        $math = new MathUtility();

        $this->assertSame(0.0, $this->model->calculateInterestIncome($stock, $macro, $math));

        // The parent still books it, which is what makes the override load-bearing rather than decorative.
        $this->assertGreaterThan(
            0.0,
            (new ConglomerateBusinessModel())->calculateInterestIncome($stock, $macro, $math),
            'If the parent stopped paying interest income, this override would be silently pointless.'
        );

        // And the income the stream books in its place is real.
        $result = $this->model->computeActualFinancials(
            $stock, 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $this->deterministicMath()
        );
        $this->assertGreaterThan(0.0, $result->streamRevenue['financial_investments']);
    }

    /**
     * The treasury stream is funded by the balance sheet, not by a dial beside it. A sphere that spends its
     * hoard on a deal stops earning on the money it no longer has — which a declared weight could not say,
     * because it would still be declaring the old number.
     */
    public function testSpendingTheHoardShrinksTheTreasuryStream(): void
    {
        $macro = $this->neutralMacro();
        $math = $this->deterministicMath();

        $full  = $this->model->computeActualFinancials($this->brkw(250_000_000_000.0), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $math);
        $spent = $this->model->computeActualFinancials($this->brkw(25_000_000_000.0), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $math);

        $fullShare  = $full->streamRevenue['financial_investments'] / array_sum($full->streamRevenue);
        $spentShare = $spent->streamRevenue['financial_investments'] / array_sum($spent->streamRevenue);

        $this->assertGreaterThan($spentShare, $fullShare, 'The float must follow the cash that funds it.');
        $this->assertEqualsWithDelta($this->expectedStreamShares($this->brkw(250_000_000_000.0))['financial_investments'], $fullShare, 0.001);
        $this->assertEqualsWithDelta($this->expectedStreamShares($this->brkw(25_000_000_000.0))['financial_investments'], $spentShare, 0.001);

        // And the operating half picks up the slack rather than the group simply shrinking.
        $this->assertGreaterThan(
            $full->streamRevenue['wholly_owned'] / array_sum($full->streamRevenue),
            $spent->streamRevenue['wholly_owned'] / array_sum($spent->streamRevenue)
        );
    }

    /**
     * Dividends received arrive late and damped. A slump that has only just begun has not yet reached the
     * cheque, because the payout funding it was declared out of the earnings before it.
     */
    public function testDividendsReceivedLagTheCycleTheSubsidiariesFeelNow(): void
    {
        $slump = $this->neutralMacro(outputGapEma: -0.030);

        $result = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $slump, $this->deterministicMath()
        );

        $calm = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $ownedHit  = 1.0 - ($result->streamRevenue['wholly_owned'] / $calm->streamRevenue['wholly_owned']);
        $listedHit = 1.0 - ($result->streamRevenue['listed_portfolio'] / $calm->streamRevenue['listed_portfolio']);

        $this->assertGreaterThan(0.0, $ownedHit, 'Consolidated subsidiaries feel the slump immediately.');
        $this->assertGreaterThan(0.0, $listedHit, 'Dividends eventually feel it too.');
        $this->assertGreaterThan($listedHit, $ownedHit, 'The receipt line must be the quieter of the two.');
    }

    /**
     * Only the consolidated subsidiaries buy anything. A dividend received carries no cost of goods, so the
     * input basket must be charged against the operating stream's share and not against group revenue.
     */
    public function testOnlyTheConsolidatedSubsidiariesPayForInputs(): void
    {
        $costPush = $this->neutralMacro(ppi: 0.09, wage: 0.08);

        $withPush = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $costPush, $this->deterministicMath()
        );
        $calm = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $charged = $withPush->clampedMargin - $calm->clampedMargin;
        $this->assertGreaterThan(0.0, $charged, 'A cost push must reach the subsidiaries.');

        // Charged at the operating stream's share of revenue, so it cannot exceed the undiscounted drag.
        $ownedShare = $calm->streamRevenue['wholly_owned'] / $calm->actualRevenue;
        $this->assertLessThan(1.0, $ownedShare);
        $this->assertLessThan(0.05, $charged, 'A trust is not a factory; the basket reaches only part of it.');
    }

    /** A trust manufactures nothing, but what its holdings earn is what compounds the book it is priced on. */
    public function testStillAConglomerateForEverythingButItsPortfolio(): void
    {
        $this->assertInstanceOf(ConglomerateBusinessModel::class, $this->model);
        $this->assertNotContains('manufacturing_pmi_ema', $this->model->getOperatingMacroFields());
    }

    public function testRegisteredAgainstItsIndustry(): void
    {
        $this->assertInstanceOf(
            InvestmentCompanyBusinessModel::class,
            Sectors::getBusinessModelStrategy('investment_company')
        );
        $this->assertSame(
            'investment_company',
            Sectors::INDUSTRY_METRICS['Investment Companies']['business_model']
        );
    }

    /**
     * The intrinsic P/B multiple moved out of MarketEngine and into the valuation strategy. Every model
     * that did not ask for its own must still compute the expression that used to be inline, or the
     * extraction silently repriced the board.
     *
     * @return array<string, array{class-string}>
     */
    public static function standardModelProvider(): array
    {
        $models = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            $class = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            if ((new \ReflectionClass($class))->isAbstract() || $class === InvestmentCompanyBusinessModel::class) {
                continue;
            }
            $models[basename($file, '.php')] = [$class];
        }

        return $models;
    }

    /** @param class-string $modelClass */
    #[DataProvider('standardModelProvider')]
    public function testExtractionLeftEveryOtherModelOnTheOriginalMultiple(string $modelClass): void
    {
        $model = new $modelClass();

        foreach ([[0.12, 0.08], [0.02, 0.09], [0.90, 0.05], [0.10, 0.0001]] as [$roic, $hurdle]) {
            $this->assertSame(
                max(FinancialConstants::MIN_INTRINSIC_PB, min(FinancialConstants::MAX_INTRINSIC_PB, $roic / max(0.01, $hurdle))),
                $model->getIntrinsicPbMultiple($roic, $hurdle),
                $modelClass . ' no longer prices book the way MarketEngine did.'
            );
            $this->assertSame(0.0, $model->getStructuralValuationDiscount($this->neutralMacro(-0.03)));
        }
    }
}

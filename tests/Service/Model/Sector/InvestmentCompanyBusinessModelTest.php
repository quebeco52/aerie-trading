<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\AnchorHoldings;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConglomerateBusinessModel;
use App\Service\Model\Sector\InvestmentCompanyBusinessModel;
use App\Service\Model\Strategy\ValuationStrategyInterface;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class InvestmentCompanyBusinessModelTest extends TestCase
{
    /**
     * Capitalisation given to every holding in the fixture board; lands the portfolio within 1% of live seed prices.
     */
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
            $parent->calculateFairValue(2_000.0, $nav, 90.0),
        );
    }

    /** A distribution is paid out of net asset value, so a dividend discount value is no claim beside it. */
    public function testNetAssetValueIsTheWholeValueWhateverTheTrustDistributes(): void
    {
        $this->assertSame(700.0, $this->model->calculateFairValue(0.0, 700.0, 40.0, 900.0));
        $this->assertSame(700.0, $this->model->calculateFairValue(0.0, 700.0, 40.0));
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

        $frightened = $this->neutralMacro(sentiment: MacroConstants::SENTIMENT_TREND_LEVEL - 20.0);
        $exuberant  = $this->neutralMacro(sentiment: MacroConstants::SENTIMENT_TREND_LEVEL + 20.0);

        $this->assertGreaterThan($neutral, $this->model->getStructuralValuationDiscount($frightened));
        $this->assertLessThan($neutral, $this->model->getStructuralValuationDiscount($exuberant));
    }

    private function neutralMacro(float $outputGapEma = 0.0, ?float $ppi = null, float $realWageGap = 0.0, ?float $sentiment = null): MacroStateDTO
    {
        $defaults = new MacroStateDTO();

        return new MacroStateDTO(
            outputGapEma: $outputGapEma,
            consumerSentimentIndexEma: $sentiment ?? MacroConstants::SENTIMENT_TREND_LEVEL,
            // With output at trend, whatever moves confidence is mood.
            consumerSentimentMood: ($sentiment ?? MacroConstants::SENTIMENT_TREND_LEVEL) - MacroConstants::SENTIMENT_TREND_LEVEL,
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
            policyRateEma: MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION,
            producerPriceInflationEma: $ppi ?? $defaults->producerPriceInflationEma,
            realWageGap: $realWageGap,
        );
    }

    private function deterministicMath(): MathUtility
    {
        return new ScriptedDraws(generatePersistentZ: 0.0);
    }

    /**
     * A sphere whose stakes have never been priced: its books cannot be opened, and the model says so rather than
     * guessing.
     */
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
     * The revenue line is the subsidiaries' sales and nothing else: a stake books the dividend its company pays, and
     * the treasury its interest, both below the operating line, so neither runs through the subsidiaries' margin.
     */
    public function testRevenueIsTheSubsidiariesSalesAlone(): void
    {
        $result = $this->model->computeActualFinancials(
            $this->brkw(),
            100_000_000.0,
            0.08,
            3_000_000.0,
            0.0,
            $this->neutralMacro(),
            $this->deterministicMath(),
        );

        $this->assertSame(['wholly_owned'], array_keys($result->streamRevenue));
        $this->assertEqualsWithDelta(100_000_000.0, $result->actualRevenue, 1.0, 'A calm quarter sells what it was expected to.');
        // A calm quarter charges nothing beyond the cost base it was handed.
        $this->assertEqualsWithDelta(0.08, $result->clampedMargin, 1e-9);
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
            'A declared sphere with no price for its stakes must refuse, so the plant ledger stays closed.',
        );

        $result = $this->model->computeActualFinancials(
            $bare,
            100_000_000.0,
            0.08,
            3_000_000.0,
            0.0,
            $this->neutralMacro(),
            $this->deterministicMath(),
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
            1.0,
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
            \App\Service\Corporate\CorporateMetrics::getInstance()->calculateCournotPriceHaircut(
                $stock,
                0.25,
                $stock->getInvestedCapital(),
                $this->neutralMacro(),
            ),
            'A trust takes no marginal-revenue haircut: it has no marginal unit to sell.',
        );
    }

    /**
     * Discount control: the board buys when the discount is wider than the one the trust trades at through the cycle,
     * by how far below that price the market has its shares. At the normal discount it does not, or it would retire
     * its whole float.
     */
    public function testTheBoardBuysBackOnlyWhenTheDiscountIsWiderThanItsNormalOne(): void
    {
        $stock = $this->brkw();
        $stock->setSharesOutstanding('1000000000');

        // Struck on the net asset value it is handed, the sum of the parts: here 1880 a share.
        $nav = 1880.0;
        $normalPrice = $nav * (1.0 - InvestmentCompanyBusinessModel::NAV_DISCOUNT_BASE);
        $this->assertSame(0.0, $this->model->resolveRepurchaseAccretion($stock, $normalPrice, $nav), 'The normal discount is the price of the structure.');
        $this->assertEqualsWithDelta(1.0 - (0.6 * $nav / $normalPrice), $this->model->resolveRepurchaseAccretion($stock, 0.6 * $nav, $nav), 1e-9);
        $this->assertGreaterThan(0.0, $this->model->resolveRepurchaseAccretion($stock, 0.6 * $nav, $nav));
        $this->assertSame(0.0, $this->model->resolveRepurchaseAccretion($stock, $nav, $nav));
        $this->assertSame(0.0, $this->model->resolveRepurchaseAccretion($stock, 2500.0, $nav), 'A premium is not accretion.');
        $this->assertSame(0.0, $this->model->resolveRepurchaseAccretion($stock, 0.0, $nav));
    }

    /**
     * The net asset value takes the subsidiaries at what their earnings are worth: at their cost of capital they are
     * worth their capital, below it less, above it more, and the step is what the value-driver formula gives.
     */
    public function testTheSubsidiariesAreValuedOnWhatTheyEarn(): void
    {
        $stock = $this->brkw();
        $capital = $this->model->getPlantCapital($stock, $stock->getInvestedCapital());
        $this->assertGreaterThan(0.0, $capital);
        $taxRate = 0.20;
        $growth = 0.03;
        $earning = static function (float $return) use ($stock, $capital, $taxRate): void {
            // A year of filings whose operating line earned this return after tax, which the anchor agrees with.
            $stock->setBaselineRoic((string) $return);
            $stock->setQuarterlyOperatingHistory(array_fill(0, 4, ['revenue' => 1.0, 'ebit' => $capital * $return / (1.0 - $taxRate) / 4.0]));
        };

        $earning(0.08);
        $this->assertEqualsWithDelta(0.0, $this->model->resolveSubsidiaryRevaluation($stock, 0.08, $growth, $taxRate), $capital * 1e-9, 'earning their cost, worth their capital');

        $earning(0.06);
        $this->assertEqualsWithDelta($capital * (0.06 - 0.08) / (0.08 - $growth), $this->model->resolveSubsidiaryRevaluation($stock, 0.08, $growth, $taxRate), $capital * 1e-6);

        $earning(0.10);
        $this->assertGreaterThan(0.0, $this->model->resolveSubsidiaryRevaluation($stock, 0.08, $growth, $taxRate));
        $this->assertSame(0.0, (new \App\Service\Model\Sector\StandardCorporateBusinessModel())->resolveSubsidiaryRevaluation($stock, 0.08, $growth, $taxRate), 'an operating company is valued whole');
    }

    /** A trust never borrows to retire its shares, however little debt it carries; an operating company would. */
    public function testATrustNeverRecapitalisesItsSharesIntoDebt(): void
    {
        $this->assertFalse($this->model->isUnderLeveraged(0.0, 0.5, 50.0, 2.0));
        $this->assertTrue((new \App\Service\Model\Sector\StandardCorporateBusinessModel())->isUnderLeveraged(0.0, 0.5, 50.0, 2.0));
    }

    /**
     * The operating return leaves the stakes out, their dividends being booked below its line: beside it a stake earns
     * the asset cost of capital it is held for, its dividends and its price, less the tax the trust pays on the
     * dividends. Whether a company pays its cash out or buys back moves the split, not the return before that tax; an
     * operating company and an unmarked sphere hold nothing that earns it.
     */
    public function testAStakeEarnsItsCostOfCapitalThroughItsDividendAndItsPrice(): void
    {
        $sphere = $this->brkw();
        $assetCostOfCapital = 0.085;
        $taxRate = 0.20;
        $perStake = function (float $dividendYield, float $taxRate) use ($sphere, $assetCostOfCapital): float {
            $sphere->setEarningsMomentumZ([
                AnchorStakeLedger::STATE_DIVIDENDS_TRAILING => $dividendYield * $this->fixturePortfolio(),
                AnchorStakeLedger::STATE_TAX_RATE => $taxRate,
            ]);

            return $this->model->getExpectedHoldingReturn($sphere, $assetCostOfCapital) * $sphere->getInvestedCapital() / $this->fixturePortfolio();
        };

        $this->assertEqualsWithDelta($assetCostOfCapital, $perStake(0.02, 0.0), 1e-12, 'untaxed, the dividend and the price make the cost');
        $this->assertEqualsWithDelta($perStake(0.02, 0.0), $perStake(0.0, 0.0), 1e-12, 'a buyback leaves the same return in the price');
        $this->assertEqualsWithDelta($assetCostOfCapital - ($taxRate * 0.02), $perStake(0.02, $taxRate), 1e-12, 'the tax on the dividend is the trust\'s');

        $this->assertSame(0.0, $this->model->getExpectedHoldingReturn($this->bareBrkw(), $assetCostOfCapital), 'An unmarked sphere has no stakes to earn on.');
        $this->assertSame(0.0, (new \App\Service\Model\Sector\StandardCorporateBusinessModel())->getExpectedHoldingReturn($sphere, $assetCostOfCapital));
    }

    /** An operating company's repurchase case is the return it earns, which the earnings test already asks. */
    public function testAnOperatingCompanyClaimsNoBookAccretion(): void
    {
        $standard = new \App\Service\Model\Sector\StandardCorporateBusinessModel();

        $this->assertSame(0.0, $standard->resolveRepurchaseAccretion($this->brkw(), 100.0, 1880.0));
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
                $stock,
                100_000_000.0,
                0.08,
                3_000_000.0,
                0.0,
                $macro,
                $math,
            );

            $this->assertArrayHasKey(
                'wholly_owned',
                $result->streamRevenue,
                "The consolidated stream vanished at {$multiple}x the portfolio.",
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
            'Plant must be what is left after the portfolio, not the whole balance sheet.',
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
            1.0,
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

    /** A trust whose stakes are the whole book has no subsidiaries left to sell anything on. */
    public function testASphereThatConsolidatesNothingHasNoCapitalToSellOn(): void
    {
        $stock = $this->brkw();
        $stock->setListedStakesCarrying((string) $stock->getInvestedCapital());

        $this->assertSame(0.0, $this->model->getPlantCapital($stock, $stock->getInvestedCapital()));
    }

    /**
     * The treasury earns what a conglomerate's does, the money market and the credit book, on the cash itself: once,
     * below the operating line, and less when the hoard is spent.
     */
    public function testTheTreasuryEarnsTheParentsFloatOnTheCashItHolds(): void
    {
        $macro = $this->neutralMacro();
        $full = $this->brkw(250_000_000_000.0);
        $spent = $this->brkw(25_000_000_000.0);

        $this->assertEqualsWithDelta(
            (new ConglomerateBusinessModel())->calculateInterestIncome($full, $macro),
            $this->model->calculateInterestIncome($full, $macro),
            1e-6,
        );
        $this->assertGreaterThan(0.0, $this->model->calculateInterestIncome($full, $macro));
        $this->assertGreaterThan($this->model->calculateInterestIncome($spent, $macro), $this->model->calculateInterestIncome($full, $macro), 'The float follows the cash that funds it.');
    }

    /** The subsidiaries are an operating business: their sales feel a slump now, and their inputs a cost push. */
    public function testTheSubsidiariesFeelTheCycleAndTheirInputs(): void
    {
        $calm = $this->model->computeActualFinancials($this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $slump = $this->model->computeActualFinancials($this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(outputGapEma: -0.030), $this->deterministicMath());
        $costPush = $this->model->computeActualFinancials($this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(realWageGap: 0.03), $this->deterministicMath());

        $this->assertLessThan($calm->actualRevenue, $slump->actualRevenue);
        $this->assertGreaterThan(0.0, $costPush->clampedMargin - $calm->clampedMargin, 'A cost push must reach the subsidiaries.');
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
            Sectors::getBusinessModelStrategy('investment_company'),
        );
        $this->assertSame(
            'investment_company',
            Sectors::INDUSTRY_METRICS['Investment Companies']['business_model'],
        );
    }

    /**
     * The intrinsic P/B multiple moved out of MarketEngine and into the valuation strategy. Every model
     * that did not ask for its own must still compute the expression that used to be inline, or the
     * extraction silently repriced the board.
     *
     * @return array<string, array{class-string<ValuationStrategyInterface>}>
     */
    public static function standardModelProvider(): array
    {
        $dir = dirname(__DIR__, 4) . '/src/Service/Model/Sector';
        $files = glob($dir . '/*BusinessModel.php');
        self::assertNotFalse($files, "Could not list the sector models in {$dir}.");

        $models = [];
        foreach ($files as $file) {
            $class = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            self::assertTrue(is_subclass_of($class, ValuationStrategyInterface::class), "{$class} has no valuation strategy.");
            if ((new \ReflectionClass($class))->isAbstract() || $class === InvestmentCompanyBusinessModel::class) {
                continue;
            }
            $models[basename($file, '.php')] = [$class];
        }

        return $models;
    }

    /** @param class-string<ValuationStrategyInterface> $modelClass */
    #[DataProvider('standardModelProvider')]
    public function testExtractionLeftEveryOtherModelOnTheOriginalMultiple(string $modelClass): void
    {
        $model = new $modelClass();

        foreach ([[0.12, 0.08], [0.02, 0.09], [0.90, 0.05], [0.10, 0.0001]] as [$roic, $hurdle]) {
            $this->assertSame(
                max(FinancialConstants::MIN_INTRINSIC_PB, min(FinancialConstants::MAX_INTRINSIC_PB, $roic / max(0.01, $hurdle))),
                $model->getIntrinsicPbMultiple($roic, $hurdle),
                $modelClass . ' no longer prices book the way MarketEngine did.',
            );
            $this->assertSame(0.0, $model->getStructuralValuationDiscount($this->neutralMacro(-0.03)));
        }
    }

    public function testTheCostBaseFollowsTheConsolidatedSubsidiariesAlone(): void
    {
        // Equity 1000, cash 100, stakes 600: invested capital 900, consolidated sleeve 300.
        $stock = new Stock();
        $stock->setTicker('TRUST_TEST');
        $stock->setTotalEquity('1000');
        $stock->setCorporateTreasury('100');
        $stock->setListedStakesCarrying('600');
        $recession = new MacroStateDTO(outputGapEma: -0.03, exchangeRateIndexEma: 100.0);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // The subsidiaries are the whole revenue line, and move -0.03 x 0.70 x 0.90.
        $expected = -0.03 * 0.70 * 0.90;
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }

}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\News\MarketEventPublisher;
use App\Tests\Support\CorporateEngineFactory;
use PHPUnit\Framework\TestCase;

/**
 * Negative earnings pre-announcements (Kasznik & Lev 1995, "To Warn or Not to Warn").
 *
 * The asymmetry is the finding: firms heading into a large negative surprise warn, firms heading into a
 * large positive one stay quiet. What management may honestly know before the close is what the firm is
 * already carrying — an accrual reversal owed back, and the input cost the closing quarter puts on the cost
 * base, projected from the lag state the last report persisted and today's input prices — never a peek at
 * draws that have not been made.
 *
 * The other half of the contract is that a warning is NEWS. Consensus anchors on the last report's cost
 * ratio, so the squeeze that report booked is in the estimate; only what the closing quarter adds to it, at
 * the share analysts cannot read off published input-price series, is worth guiding on.
 */
final class EarningsPreAnnouncementTest extends TestCase
{
    use CorporateEngineFactory;

    private const TICKS_PER_YEAR = 252;

    /** Quarterly revenue is $1bn and the cost base is 60% of it, against $100m of quarterly operating earnings. */
    private const VARIABLE_COST_RATIO = 0.60;

    private const INDUSTRY = 'Conglomerates';

    private EarningsEngine $engine;
    /** @var list<array{0: string, 1: float}> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->published = [];

        $publisher = $this->createStub(MarketEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(
            function (mixed $asset, string $type, string $description, float $changePercent): array {
                $this->published[] = [$type, $changePercent];

                // Mirrors the real publisher: one event shape, which the caller wraps into a list.
                return ['type' => $type, 'ticker' => 'ZZZZ', 'description' => $description, 'change_percent' => $changePercent];
            },
        );

        $math = new MathUtility();
        $this->engine = self::earningsEngine($math, publisher: $publisher);
    }

    private function makeStock(
        float $accrualBank = 0.0,
        float $costLevel = 0.0,
        float $recovered = 0.0,
    ): Stock {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setIndustry(self::INDUSTRY);
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('100000000');
        $stock->setTotalEquity('5000000000');
        $stock->setRetainedEarnings('2000000000');
        $stock->setWholesaleDebt('2000000000');
        $stock->setCorporateTreasury('500000000');
        $stock->setVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalRevenue('4000000000');   // $1bn a quarter
        $stock->setOperatingMargin('0.10');      // $100m of structural quarterly operating earnings
        $stock->setTotalNetIncome('400000000');
        $stock->setRoicTtm('0.08');
        $stock->setStructuralVariableMargin(self::VARIABLE_COST_RATIO);
        $stock->setManagedAccrualBank($accrualBank);
        $stock->setEarningsMomentumZ([
            FinancialConstants::STATE_INPUT_COST_LEVEL => $costLevel,
            FinancialConstants::STATE_INPUT_COST_RECOVERY => $recovered,
        ]);

        return $stock;
    }

    /** Input prices with metals and producer prices well above trend: a squeeze opening in the market now. */
    private function shockedMacro(): MacroStateDTO
    {
        return new MacroStateDTO(industrialMetalsIndexEma: 300.0, producerPriceInflationEma: 0.08);
    }

    private function warn(Stock $stock, ?MacroStateDTO $macro = null, ?int $tick = null): array
    {
        return $this->engine->evaluatePreAnnouncement($stock, $macro ?? new MacroStateDTO(), $tick ?? $this->warningTick($stock), self::TICKS_PER_YEAR);
    }

    private function warningTick(Stock $stock): int
    {
        return EarningsEngine::resolvePreAnnouncementTick($stock->getTicker(), self::TICKS_PER_YEAR);
    }

    private function afterTax(MacroStateDTO $macro): float
    {
        return 1.0 - Sectors::strategyFor(self::INDUSTRY)->getEffectiveTaxRate($macro->corporateTaxRate);
    }

    /**
     * The shortfall is sized against structural earnings power, not the trailing print. Scaled by trailing
     * net income, a firm that had just broken even warned on a trivial reversal, and a firm running a larger
     * loss warned less because the absolute value grew.
     */
    public function testWarningIsScaledByStructuralEarningsPowerNotTheTrailingPrint(): void
    {
        // A $5m reversal due: about 6% of the after-tax earnings the business structurally makes in a quarter,
        // so no warning, even though the trailing figure is a rounding error away from zero.
        $breakEven = $this->makeStock(accrualBank: 5_000_000.0 / EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE);
        $breakEven->setTotalNetIncome('1000');

        $this->assertSame([], $this->warn($breakEven));
        $this->assertSame(0.0, $breakEven->getPreAnnouncedShortfall());

        // The same $60m reversal is the same miss against the same earnings power whether the firm is deep in
        // loss or not: a bigger loss does not make the shortfall smaller.
        $shallowLoss = $this->makeStock(accrualBank: 60_000_000.0 / EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE);
        $shallowLoss->setTotalNetIncome('-100000000');
        $deepLoss = $this->makeStock(accrualBank: 60_000_000.0 / EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE);
        $deepLoss->setTotalNetIncome('-4000000000');

        $this->assertNotEmpty($this->warn($shallowLoss));
        $this->assertNotEmpty($this->warn($deepLoss));
        $this->assertEqualsWithDelta($shallowLoss->getPreAnnouncedShortfall(), $deepLoss->getPreAnnouncedShortfall(), 1e-6, 'identical shortfall, identical earnings power');
    }

    /** A cost squeeze opening in the input markets this quarter is news, and gets warned. */
    public function testFreshCostSqueezeProducesAWarning(): void
    {
        $stock = $this->makeStock();

        $events = $this->warn($stock, $this->shockedMacro());

        $this->assertNotEmpty($events, 'A squeeze this size opening in one quarter is known before the close.');
        $this->assertSame('GUIDANCE', $this->published[0][0]);
        $this->assertLessThan(0.0, $this->published[0][1], 'A warning is bad news and must move the price down.');
    }

    /**
     * The regression this contract fixes: the squeeze the last report BOOKED is not warned again. That report's
     * cost ratio is what consensus anchors on, so a firm whose costs jumped last quarter, in a market that has not
     * moved since, has nothing new to tell. Comparing the booked gap with the one before it guided the same news
     * twice and turned the report that followed into a manufactured blowout beat.
     */
    public function testTheSqueezeTheLastReportBookedIsNotWarnedAgain(): void
    {
        $macro = $this->shockedMacro();
        $deviation = (new \App\Service\Model\Sector\ConglomerateBusinessModel())->resolveInputCostDeviation($macro);
        $stock = $this->makeStock(costLevel: $deviation, recovered: 0.0);

        $this->assertSame([], $this->warn($stock, $macro), 'The street has had this squeeze in its numbers since the last report.');
        $this->assertSame(0.0, $stock->getPreAnnouncedShortfall());
    }

    /** A squeeze that is EASING is not a warning either, however large the level it is easing from. */
    public function testEasingSqueezeIsNotWarned(): void
    {
        $stock = $this->makeStock(costLevel: 0.20, recovered: 0.12);

        $this->assertSame([], $this->warn($stock));
    }

    /** A small squeeze is absorbed silently rather than guided. */
    public function testImmaterialShortfallIsNotWarned(): void
    {
        $stock = $this->makeStock();

        $this->assertSame([], $this->warn($stock, new MacroStateDTO(industrialMetalsIndexEma: 110.0)));
        $this->assertSame(0.0, $stock->getPreAnnouncedShortfall());
    }

    /** The asymmetry: good news is never pre-announced, only bad. */
    public function testFavourableConditionsAreNeverPreAnnounced(): void
    {
        // Selling prices have run ahead of costs and input prices are falling — a positive surprise brewing.
        $stock = $this->makeStock(costLevel: 0.01, recovered: 0.10);

        $this->assertSame([], $this->warn($stock, new MacroStateDTO(industrialMetalsIndexEma: 60.0)), 'Kasznik & Lev: firms warn on bad news, not good.');
    }

    /** A model that books no input-cost drag has no cost squeeze to project, whatever the input markets do. */
    public function testAModelWithNoInputCostStateProjectsNoSqueeze(): void
    {
        $stock = $this->makeStock();
        $stock->setEarningsMomentumZ([]);

        $this->assertSame([], $this->warn($stock, $this->shockedMacro()));
    }

    /** Accrual reversals owed to earlier quarters are foreknowledge in their own right, and are firm-private. */
    public function testOwedAccrualReversalAloneCanTriggerAWarning(): void
    {
        $stock = $this->makeStock(accrualBank: 100_000_000.0 / EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE * 0.5);

        $this->assertNotEmpty($this->warn($stock), 'A firm that papered over earlier quarters knows the reversal is coming.');
    }

    /**
     * A reversal moves the reported number and not the economic one: analysts take it out of their estimate, so the
     * report confirms it, but fair value never carried it, so the warning leaves the price where it was and the report
     * cannot bounce it back.
     */
    public function testAReversalCutsTheEstimateButNotFairValue(): void
    {
        $stock = $this->makeStock(accrualBank: 100_000_000.0 / EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE * 0.5);
        $priceBefore = (float) $stock->getPrice();

        $this->assertNotEmpty($this->warn($stock));

        $this->assertEqualsWithDelta(50_000_000.0 * EarningsEngine::PREANNOUNCEMENT_CONSENSUS_ABSORPTION, $stock->getPreAnnouncedShortfall(), 1e-3);
        $this->assertSame(0.0, $stock->getPreAnnouncedEconomicShortfall());
        $this->assertEqualsWithDelta($priceBefore, (float) $stock->getPrice(), 1e-9);
    }

    /**
     * The published percentage has to be the move the price actually took.
     *
     * Every surface that renders an event — the stock page feed, the district flare — reads change_percent
     * as a realized return, so a headline reaction the price never took was simply a false print.
     */
    public function testPublishedReactionIsTheRealizedPriceMove(): void
    {
        $stock = $this->makeStock();
        $priceBefore = (float) $stock->getPrice();

        $this->warn($stock, $this->shockedMacro());

        $priceAfter = (float) $stock->getPrice();
        $this->assertLessThan($priceBefore, $priceAfter, 'A guidance cut has to actually reprice the stock.');
        $this->assertEqualsWithDelta($this->published[0][1] / 100.0, ($priceAfter - $priceBefore) / $priceBefore, 1e-6);
    }

    /**
     * One law for the same news: a warning moves the price by the report's immediate share of the fair-value
     * revision it causes, so a shortfall learned early moves the price as it would on the day. The old warning
     * ran an earnings response coefficient on the raw surprise percentage instead, which fair value never heard,
     * so the move reverted before the report.
     */
    public function testAWarningIsPricedByTheReportsOwnFairValueLaw(): void
    {
        $stock = $this->makeStock(accrualBank: 100_000_000.0 / EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE * 0.5);
        $before = clone $stock;
        $macro = $this->shockedMacro();

        $this->warn($stock, $macro);

        $after = clone $before;
        $after->setPreAnnouncedShortfall($stock->getPreAnnouncedShortfall());
        $after->setPreAnnouncedEconomicShortfall($stock->getPreAnnouncedEconomicShortfall());
        $this->assertGreaterThan(0.0, $stock->getPreAnnouncedEconomicShortfall());
        $this->assertGreaterThan($stock->getPreAnnouncedEconomicShortfall(), $stock->getPreAnnouncedShortfall(), 'The estimate takes the reversal too.');
        $debt = new DebtEngine(new MathUtility(), new CorporateMetrics());
        $market = new \App\Service\Market\Pricing\MarketEngine(new MathUtility());
        $anchors = new \App\Service\Corporate\Holdings\AnchorStakeLedger();
        $strike = static fn(Stock $s): float => $market->strikeFairValue(
            \App\DTO\MarketPricingContext::forStock($s, $macro, $debt->analyzeDebtHealth($s, $macro), $anchors),
        )['perceived_fair_value'];

        $expected = EarningsEngine::reportPriceGap($strike($before), $strike($after), 0.0, 0.0);
        $move = ((float) $stock->getPrice() / (float) $before->getPrice()) - 1.0;

        $this->assertLessThan(0.0, $move);
        $this->assertEqualsWithDelta($expected, $move, 1e-6, 'the warning is the report\'s response to the revision it causes');
    }

    public function testQuietQuarterLeavesThePriceAlone(): void
    {
        $stock = $this->makeStock(costLevel: 0.20, recovered: 0.0);

        $this->warn($stock);

        $this->assertSame(100.0, (float) $stock->getPrice());
    }

    /** Warnings only land in the pre-announcement window, not on arbitrary ticks. */
    public function testWarningOnlyFiresInItsWindow(): void
    {
        $stock = $this->makeStock();
        $offTick = ($this->warningTick($stock) + 5) % (int) (self::TICKS_PER_YEAR / 4);

        $this->assertSame([], $this->warn($stock, $this->shockedMacro(), $offTick));
    }

    /** The warning lands before the report it refers to. */
    public function testWarningPrecedesTheReport(): void
    {
        $this->assertLessThan(
            EarningsEngine::resolveReportingTick('ZZZZ', self::TICKS_PER_YEAR),
            EarningsEngine::resolvePreAnnouncementTick('ZZZZ', self::TICKS_PER_YEAR),
            'A pre-announcement that arrives after the report is not a pre-announcement.',
        );
    }

    /**
     * The guided figure is only the part consensus cannot already see, in the net income the estimate is struck
     * in. Analysts read published input-price series, so guiding the whole squeeze would double-count what is in
     * the estimate; and a cost is deductible, so the pre-tax dollars overstate the miss by 1/(1 - t).
     */
    public function testGuidedShortfallIsTheUnseenAfterTaxChange(): void
    {
        $macro = $this->shockedMacro();
        $stock = $this->makeStock();
        $projected = Sectors::strategyFor(self::INDUSTRY)->projectInputCostGap($stock, $macro);

        $this->warn($stock, $macro);

        $expected = $projected * self::VARIABLE_COST_RATIO * 1_000_000_000.0
            * (1.0 - FinancialConstants::ANALYST_COST_BASE_VISIBILITY)
            * $this->afterTax($macro)
            * EarningsEngine::PREANNOUNCEMENT_CONSENSUS_ABSORPTION;

        $this->assertGreaterThan(0.0, $projected);
        $this->assertEqualsWithDelta($expected, $stock->getPreAnnouncedShortfall(), 1.0);
    }

    /**
     * Before the first report opens the CIR margin state, the fallback has to be the VARIABLE cost ratio.
     *
     * The complement of the operating margin is the total cost ratio: it still carries fixed costs and
     * depreciation, neither of which scales with volume. Charging it against the quarter's revenue sized the
     * squeeze off the whole cost base and overstated the guided shortfall by 1/(1 - fixedCostRatio).
     */
    public function testFallbackCostRatioIsVariableRatherThanTotal(): void
    {
        // A sharper shock, so the squeeze clears the threshold against the larger earnings a 15% margin carries.
        $macro = new MacroStateDTO(industrialMetalsIndexEma: 500.0, producerPriceInflationEma: 0.12);
        $stock = $this->makeStock();
        $stock->setStructuralVariableMargin(null);   // no report has opened the margin process yet
        $stock->setOperatingMargin('0.15');
        $stock->setFixedCostRatio(0.35);
        $projected = Sectors::strategyFor(self::INDUSTRY)->projectInputCostGap($stock, $macro);

        $this->warn($stock, $macro);

        $variableCostRatio = (1.0 - 0.15) * (1.0 - 0.35);   // MarketResetCommand's construction
        $expected = $projected * $variableCostRatio * 1_000_000_000.0
            * (1.0 - FinancialConstants::ANALYST_COST_BASE_VISIBILITY)
            * $this->afterTax($macro)
            * EarningsEngine::PREANNOUNCEMENT_CONSENSUS_ABSORPTION;

        $this->assertEqualsWithDelta($expected, $stock->getPreAnnouncedShortfall(), 1.0);
    }

    /**
     * The squeeze is sized on the cost base the model charges its input gap to. A refiner charges fuel and catalysts
     * on its opex share and sells its crude back barrel for barrel, so guiding the same gap against its whole
     * variable cost ratio, crude included, overstated the miss many times over and every warned quarter then
     * printed a blowout beat.
     */
    public function testTheSqueezeIsSizedOnTheModelsOwnDragBase(): void
    {
        $macro = new MacroStateDTO(industrialMetalsIndexEma: 500.0, producerPriceInflationEma: 0.12);
        $stock = $this->makeStock();
        $state = $stock->getEarningsMomentumZ() ?? [];
        $state[FinancialConstants::STATE_INPUT_COST_DRAG_BASE] = 0.30;
        $stock->setEarningsMomentumZ($state);
        $projected = Sectors::strategyFor(self::INDUSTRY)->projectInputCostGap($stock, $macro);

        $this->warn($stock, $macro);

        $expected = $projected * 0.30 * 1_000_000_000.0
            * (1.0 - FinancialConstants::ANALYST_COST_BASE_VISIBILITY)
            * $this->afterTax($macro)
            * EarningsEngine::PREANNOUNCEMENT_CONSENSUS_ABSORPTION;
        $this->assertEqualsWithDelta($expected, $stock->getPreAnnouncedShortfall(), 1.0, 'charged on the 30% drag base, not the 60% variable cost ratio');
    }

    /** A bankrupt shell issues no guidance. */
    public function testBankruptFirmIssuesNoGuidance(): void
    {
        $stock = $this->makeStock();
        $stock->setIsBankrupt(true);

        $this->assertSame([], $this->warn($stock, $this->shockedMacro()));
    }
}

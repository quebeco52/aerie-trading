<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Deal;

use App\Data\Company\Segments;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Deal\DivestitureEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\News\MarketEventPublisher;
use App\Service\News\WireFormat;
use App\Tests\Support\DebtHealthBuilder;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DivestitureEngineTest extends TestCase
{
    use DealFixtures;

    private MarketEventPublisher&Stub $marketEventPublisherMock;
    private DebtEngine&Stub $debtEngineMock;
    private ScriptedDraws $mathUtilityMock;
    private CorporateMetrics&Stub $corporateMetricsMock;
    private DivestitureEngine $engine;

    protected function setUp(): void
    {
        $this->marketEventPublisherMock = $this->createStub(MarketEventPublisher::class);
        $this->debtEngineMock = $this->createStub(DebtEngine::class);
        $this->mathUtilityMock = new ScriptedDraws();
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);

        $this->engine = new DivestitureEngine(
            $this->marketEventPublisherMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->corporateMetricsMock,
        );
    }

    public function testEvaluateCorporateDivestitureExecutesSuccessfullyAndUpdatesBalanceSheet(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIVEST');
        $stock->setName('Divesting Corp');
        $stock->setIndustry('Technology');
        $stock->setPrice('20.00');
        $stock->setSharesOutstanding('10000000');
        $stock->setCorporateTreasury('1000000'); // Low cash
        $stock->setTotalEquity('50000000');
        $stock->setWholesaleDebt('20000000');
        $stock->setTotalRevenue('30000000');
        $stock->setOperatingMargin('-0.05'); // Negative margin -> distressed
        $stock->setRetainedEarnings('5000000');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.20,
            yield5yEma: 0.04,
            nominalGdpIndex: 1.0,
        );

        $debtMetricsMock = new DebtMetricsDTO(
            interestExpense: 1000000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: -1500000.0,
            revenue: 30000000.0,
            depreciation: 1000000.0,
            ebitda: -500000.0,
        );

        $debtHealthMock = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.02)
            ->withCoverage(-1.5)
            ->wantingToPayDownDebt()
            ->withDebtIssuance(false)
            ->withDebtTolerance(1.0)
            ->withCostOfCapital(0.12, 0.14)
            ->withLeveredBeta(1.5)
            ->withMetrics($debtMetricsMock)
            ->withLiquidityStress(true, false)
            ->build();

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($debtHealthMock);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($debtHealthMock);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(10000000.0);
        $this->corporateMetricsMock->method('calculateAddressableShare')->willReturn(0.10);
        // Divest 25%
        $this->mathUtilityMock->script(checkProbability: true, generateUniformBetween: 0.25);
        $this->marketEventPublisherMock->method('publish')->willReturn(['event_type' => 'DIVESTITURE']);

        $initialTreasury = (float) $stock->getCorporateTreasury();
        $initialDebt = (float) $stock->getWholesaleDebt();

        $result = $this->engine->evaluateCorporateDivestiture($stock, $macroState, 1.0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('shock', $result);
        // Cash proceeds must increase treasury
        $this->assertGreaterThan($initialTreasury, (float) $stock->getCorporateTreasury());
        // Debt must be shed
        $this->assertLessThan($initialDebt, (float) $stock->getWholesaleDebt());
    }

    public function testEvaluateCorporateDivestitureAbortsForBankruptStock(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setIsBankrupt(true);

        $macro = new MacroStateDTO();
        $result = $this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0);

        $this->assertNull($result);
    }

    // =========================================================================
    // BALANCE SHEET IDENTITY THROUGH DEALS
    // =========================================================================

    /**
     * A divestiture retires a pro rata slice of every ledger and books the difference between the proceeds
     * and that book value as the gain or loss. A fire sale below book is a real loss charged in full.
     */
    /**
     * The cash a seller keeps is not sold. Two sellers alike but for $5B more cash, which the market prices at face,
     * sell the same quarter of the same business: the buyer pays the same for it, and the announcement prices the same
     * gain or loss, whatever sits in the treasury that stays behind.
     */
    public function testDivestitureDoesNotPayForTheCashTheSellerKeeps(): void
    {
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0);
        $this->primeDistressedDivestiture(operatingBase: 100_000_000_000.0, fraction: 0.20);

        $sale = function (string $ticker, float $extraCash) use ($macroState): array {
            $stock = $this->buildLedgeredAcquirer($ticker, treasury: 1_000_000_000.0 + $extraCash, debt: 4_000_000_000.0, shares: 10_000_000.0);
            $stock->setPrice((string) (100.0 + ($extraCash / 10_000_000.0)));
            $stock->setOperatingMargin('-0.05');
            $stock->setEarningsPerShare('-0.10');
            $stock->setTotalNetIncome('-1000000000');
            $stock->setRoicTtm('0.00');
            $treasuryBefore = (float) $stock->getCorporateTreasury();
            $cap = (float) $stock->getPrice() * 10_000_000.0;

            $result = $this->engine->evaluateCorporateDivestiture($stock, $macroState, 1.0);
            $this->assertIsArray($result);

            return [(float) $stock->getCorporateTreasury() - $treasuryBefore, $result['shock'] * $cap];
        };

        [$leanProceeds, $leanGain] = $sale('LEAN', 0.0);
        [$richProceeds, $richGain] = $sale('RICH', 5_000_000_000.0);

        $this->assertGreaterThan(0.0, $leanProceeds);
        $this->assertEqualsWithDelta($leanProceeds, $richProceeds, 1.0, 'The buyer pays for the business, not for the cash the seller keeps.');
        $this->assertEqualsWithDelta($leanGain, $richGain, 1.0, 'The holders gain or lose the same dollars on the same sale.');
    }

    public function testDivestitureRetiresEveryLedgerProRataAndKeepsTheBalanceSheetBalanced(): void
    {
        $stock = $this->buildLedgeredAcquirer('DIVL', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRoicTtm('0.00');
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0);
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);

        $this->assertBalanced($stock, 'the fixture must open balanced');
        $equityBefore = (float) $stock->getTotalEquity();
        $treasuryBefore = (float) $stock->getCorporateTreasury();
        $debtBefore = (float) $stock->getWholesaleDebt();
        $before = [
            'grossPpe' => (float) $stock->getGrossPpe(),
            'accumulated' => (float) $stock->getAccumulatedDepreciation(),
            'taxBasis' => (float) $stock->getPpeTaxBasis(),
            'cip' => (float) $stock->getCipBalance(),
            'goodwill' => (float) $stock->getGoodwill(),
            'receivables' => (float) $stock->getReceivables(),
            'allowance' => (float) $stock->getReceivablesAllowance(),
            'inventory' => (float) $stock->getInventory(),
            'payables' => (float) $stock->getPayables(),
            'deferredTax' => (float) $stock->getDeferredTaxLiability(),
        ];
        $bookValueDisposed = 0.25 * (
            $stock->getNetPpe() + $before['cip'] + $before['goodwill'] + $stock->getNetReceivables() + $before['inventory']
            - $before['payables'] - $before['deferredTax']
        );
        $ageBefore = $stock->getAssetAge();

        $result = $this->engine->evaluateCorporateDivestiture($stock, $macroState, 1.0);
        $this->assertIsArray($result);

        $proceeds = (float) $stock->getCorporateTreasury() - $treasuryBefore;
        $debtShed = $debtBefore - (float) $stock->getWholesaleDebt();
        $this->assertGreaterThan(0.0, $proceeds);
        $this->assertEqualsWithDelta($debtBefore * 0.25, $debtShed, 1.0, 'the buyer assumes its share of the debt');

        $getters = [
            'grossPpe' => 'getGrossPpe', 'accumulated' => 'getAccumulatedDepreciation', 'taxBasis' => 'getPpeTaxBasis',
            'cip' => 'getCipBalance', 'goodwill' => 'getGoodwill', 'receivables' => 'getReceivables',
            'allowance' => 'getReceivablesAllowance', 'inventory' => 'getInventory', 'payables' => 'getPayables',
            'deferredTax' => 'getDeferredTaxLiability',
        ];
        foreach ($getters as $ledger => $getter) {
            $this->assertEqualsWithDelta($before[$ledger] * 0.75, (float) $stock->{$getter}(), 1.0, "{$ledger} leaves pro rata");
        }
        $this->assertEqualsWithDelta($ageBefore, $stock->getAssetAge(), 1e-9, 'the plant that stays is no older or younger');

        // Gain or loss on sale is proceeds less the book value that left, net of the debt the buyer took on.
        $expectedGain = $proceeds - ($bookValueDisposed - $debtShed);
        $this->assertEqualsWithDelta($equityBefore + $expectedGain, (float) $stock->getTotalEquity(), 1.0);
        $this->assertLessThan(0.0, $expectedGain, 'a fire sale at cents on the dollar is a loss, and it is not floored away');

        $this->assertBalanced($stock, 'a divestiture');
    }

    /**
     * ...and a seller as if the business it sold had never been its own.
     */
    public function testADivestitureRestatesTheTrailingYearProForma(): void
    {
        $stock = $this->buildLedgeredAcquirer('PROD', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRoicTtm('0.00');
        $history = [
            ['revenue' => 5_000_000_000.0, 'ebit' => -100_000_000.0],
            ['revenue' => 4_000_000_000.0, 'ebit' => -300_000_000.0],
            ['revenue' => 5_500_000_000.0, 'ebit' => 200_000_000.0],
            ['revenue' => 5_500_000_000.0, 'ebit' => -200_000_000.0],
        ];
        $stock->setQuarterlyOperatingHistory($history);
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);

        $this->assertIsArray($this->engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0));

        $restated = $stock->getQuarterlyOperatingHistory();
        $this->assertNotNull($restated);
        $this->assertCount(4, $restated);
        foreach ($restated as $i => $quarter) {
            $this->assertEqualsWithDelta($history[$i]['revenue'] * 0.75, $quarter['revenue'], 1.0);
            $this->assertEqualsWithDelta($history[$i]['ebit'] * 0.75, $quarter['ebit'], 1.0);
        }
    }

    /**
     * A downgrade raises the firm's WACC, not the cost of capital of its business: a firm whose long-run return
     * clears its asset cost of capital is not shedding a dying business because its financing got dearer.
     */
    public function testADowngradeAloneDoesNotMakeTheBusinessDying(): void
    {
        $stock = $this->buildLedgeredAcquirer('DOWN', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setBeta('0.40');
        $stock->setRoicTtm('0.065');
        $stock->setLongRunReturn('0.065');
        $stock->setTotalNetIncome('500000000');
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $macro = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, yield10y: 0.04, equityRiskPremium: 0.05, nominalGdpIndex: 1.0);

        $this->assertEqualsWithDelta(0.06, DivestitureEngine::businessCostOfCapital($stock, $macro), 1e-12);
        // The firm's 12% WACC would put it 5.5 points under its hurdle, past the dying line.
        $this->assertNull($this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0));
        $this->assertSame(8_000_000_000.0, $stock->getNetPpe());
    }

    /** A slump in the trailing year is not decline: the sale test reads the firm's long-run return. */
    public function testACyclicalTroughDoesNotTriggerADistressSale(): void
    {
        $stock = $this->buildLedgeredAcquirer('TROUGH', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setBeta('1.00');
        $stock->setRoicTtm('-0.04');
        $stock->setLongRunReturn('0.085');
        $stock->setTotalNetIncome('-300000000');
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $macro = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, yield10y: 0.04, equityRiskPremium: 0.05, nominalGdpIndex: 1.0);

        $this->assertNull($this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0));
        $this->assertSame(8_000_000_000.0, $stock->getNetPpe());
    }

    /**
     * A quarter's mixed cash-flow pattern is not decline either: a shake-out firm earning its keep keeps its divisions.
     */
    public function testAShakeOutQuarterDoesNotTriggerADistressSale(): void
    {
        $stock = $this->buildLedgeredAcquirer('SHAKE', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setBeta('1.00');
        $stock->setLongRunReturn('0.085');
        $stock->setLifecycleStage(\App\Data\Company\LifecycleStage::ShakeOut);
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $macro = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, yield10y: 0.04, equityRiskPremium: 0.05, nominalGdpIndex: 1.0);

        $this->assertNull($this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0));
        $this->assertSame(8_000_000_000.0, $stock->getNetPpe());
    }

    /**
     * A pro rata slice cannot move the return that triggered it, so a firm sells once per distress episode: it
     * sells again only after its return has recovered and fallen back.
     */
    public function testAFirmMakesOneDistressSalePerEpisode(): void
    {
        $stock = $this->buildLedgeredAcquirer('EPIS', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $distressed = static function (Stock $stock): void {
            $stock->setRoicTtm('-0.02');
            $stock->setLongRunReturn('-0.02');
            $stock->setTotalNetIncome('-300000000');
        };
        $distressed($stock);
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $macro = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0, totalTime: 3.0);

        $this->assertIsArray($this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0));
        $state = $stock->getEarningsMomentumZ();
        $this->assertNotNull($state);
        $this->assertSame(3.0, $state[DivestitureEngine::STATE_DISTRESS_SALE_EPISODE]);
        $plantAfterSale = $stock->getNetPpe();
        // The proceeds are spent, so no cash buffer stands between the firm and a second sale but the episode.
        $spendProceeds = static fn() => $stock->setCorporateTreasury('1000000000');
        $spendProceeds();

        $this->assertNull($this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0), 'the same episode does not sell again');
        $this->assertSame($plantAfterSale, $stock->getNetPpe());

        $stock->setRoicTtm('0.15');
        $stock->setLongRunReturn('0.15');
        $stock->setTotalNetIncome('500000000');
        $this->assertNull($this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0));
        $state = $stock->getEarningsMomentumZ();
        $this->assertNotNull($state);
        $this->assertArrayNotHasKey(DivestitureEngine::STATE_DISTRESS_SALE_EPISODE, $state, 'a recovered return closes the episode');

        $distressed($stock);
        $spendProceeds();
        $this->assertIsArray($this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0), 'a new episode may sell');
    }

    /**
     * The division sold is a pro rata slice of the firm, so the business kept earns the margin and return it
     * did before; a distressed seller is not made more efficient by selling. The sale fetches less than the
     * book value it gives up, and the headline says it booked a loss rather than a negative gain.
     */
    public function testADistressSaleLeavesTheKeptBusinessAsItWasAndReportsItsLoss(): void
    {
        $stock = $this->buildLedgeredAcquirer('SHED', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRoicTtm('0.00');
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);

        $published = null;
        $publisher = $this->createStub(MarketEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(static function (Stock $seller, string $type, string $description) use (&$published): array {
            $published = $description;

            return [];
        });
        $engine = new DivestitureEngine($publisher, $this->debtEngineMock, $this->mathUtilityMock, $this->corporateMetricsMock);

        $marginBefore = $stock->getOperatingMargin();
        $roicBefore = $stock->getBaselineRoic();
        $retainedBefore = (float) $stock->getRetainedEarnings();

        $this->assertIsArray($engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0));

        $this->assertSame($marginBefore, $stock->getOperatingMargin());
        $this->assertSame($roicBefore, $stock->getBaselineRoic());

        $loss = $retainedBefore - (float) $stock->getRetainedEarnings();
        $this->assertGreaterThan(0.0, $loss, 'a fire sale below book is a loss');
        $this->assertStringContainsString('booking a ' . WireFormat::money($loss) . ' loss on sale', (string) $published);
        $this->assertStringNotContainsString('$-', (string) $published);
    }

    /**
     * A company that keeps plant by segment sells a whole business when one is the size this kind of sale takes: the
     * one whose plant earns least, here by a dying business. Its plant and that share of every ledger leave, its share
     * of the sales goes, its record stops selling in its industry, and the company's other businesses keep all theirs.
     */
    public function testADistressedCompanySellsItsWeakestSegmentWhole(): void
    {
        $stock = $this->buildLedgeredAcquirer('TRIV', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setIndustry('Conglomerates');
        $stock->setRoicTtm('-0.02');
        $stock->setLongRunReturn('-0.02');
        $stock->setTotalNetIncome('-300000000');
        // A dying business sells 30-50% of itself: the industrial arm is that size and earns least on its plant.
        $stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.55, 'industrial' => 0.35, 'consumer' => 0.10], 1.0, []) + [
            SegmentBooks::STATE_TARGET . Segments::MAIN => 0.58,
            SegmentBooks::STATE_TARGET . 'industrial' => 0.31,
            SegmentBooks::STATE_TARGET . 'consumer' => 0.11,
            SegmentBooks::STATE_MARGIN . Segments::MAIN => 0.10,
            SegmentBooks::STATE_MARGIN . 'industrial' => 0.05,
            SegmentBooks::STATE_MARGIN . 'consumer' => 0.15,
            SegmentOutlook::STATE_SHARE . 'industrial' => 0.31,
        ]);
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $ledger->resolveSegmentSupply($stock, 'industrial', 5_000.0, 5_000.0, 0.1, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveSegmentSupply($stock, 'consumer', 4_000.0, 4_000.0, 0.1, 1.0, 0.0, 0.0, 10, 252);
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.42);
        $published = null;
        $publisher = $this->createStub(MarketEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(static function (Stock $seller, string $type, string $description) use (&$published): array {
            $published = $description;

            return [];
        });
        $engine = new DivestitureEngine($publisher, $this->debtEngineMock, $this->mathUtilityMock, $this->corporateMetricsMock, $ledger);
        $grossPpe = (float) $stock->getGrossPpe();

        $this->assertIsArray($engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0), 1.0));

        $this->assertEqualsWithDelta(0.65 * $grossPpe, (float) $stock->getGrossPpe(), 1.0, 'its share of the plant, not the drawn slice');
        $this->assertEqualsWithDelta(20_000_000_000.0 * (1.0 - 0.31), (float) $stock->getTotalRevenue(), 1.0, 'its share of the sales, not of the plant');
        $plant = SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []) ?? [];
        $this->assertSame(0.0, $plant['industrial']);
        $this->assertEqualsWithDelta(0.10 / 0.65, $plant['consumer'], 1e-12);
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $this->assertSame(0.0, $momentum[SegmentBooks::STATE_TARGET . 'industrial'], 'its lines sell nothing');
        $this->assertSame(0.0, $momentum[SegmentBooks::STATE_MARKET . 'industrial'], 'its market is no longer the company\'s');
        $this->assertSame(0.0, $momentum[SegmentOutlook::STATE_SHARE . 'industrial'], 'nor its industry\'s outlook');
        $this->assertSame(0.0, $store->readIndustry('Specialty Industrial Machinery')['TRIV/industrial']['revenue'], 'it sells nothing for the company');
        $this->assertSame(5_000.0, $store->readIndustry('Specialty Industrial Machinery')['TRIV/industrial']['capacity'], 'the buyer runs its plant');
        $this->assertSame(4_000.0, $store->readIndustry('Household & Personal Products')['TRIV/consumer']['capacity']);
        $this->assertStringContainsString('sold its industrial products business', (string) $published);
    }

    /**
     * The seller's announcement return is the sale's worth to its shareholders over their stake: the price
     * received less the pro-rata market value of the division given up, struck at the price before the sale.
     */
    public function testTheSellersAnnouncementReturnIsTheSalesGainOverItsMarketValue(): void
    {
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $stock = $this->buildLedgeredAcquirer('SELL', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setPrice('5.0');
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRoicTtm('0.00');
        $cashBefore = (float) $stock->getCorporateTreasury();
        $marketCap = 5.0 * 10_000_000.0;

        $sale = $this->engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0);

        $this->assertIsArray($sale);
        $proceeds = (float) $stock->getCorporateTreasury() - $cashBefore;
        $this->assertGreaterThan(0.0, $proceeds);
        $this->assertEqualsWithDelta(($proceeds - (0.25 * $marketCap)) / $marketCap, $sale['shock'], 1e-9);
    }

    /**
     * A forced seller with nothing to sell on earnings fetches the market's value of the slice less the fire-sale
     * discount, whatever its book says: a firm written down far below book gains nothing from the sale, and its
     * shareholders lose the discount on the slice sold. One valued far above book books a gain on the sale, and
     * loses the tax on it besides.
     */
    public function testAFireSaleLosesTheDiscountOnTheSliceWhateverTheSellersBook(): void
    {
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $sell = function (float $price): array {
            $stock = $this->buildLedgeredAcquirer('SELL', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
            $stock->setPrice((string) $price);
            $stock->setOperatingMargin('-0.05');
            $stock->setEarningsPerShare('-0.10');
            $stock->setTotalNetIncome('-1000000000');
            $stock->setRoicTtm('0.00');
            $cashBefore = (float) $stock->getCorporateTreasury();
            $sale = $this->engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0);
            $this->assertIsArray($sale);
            $shock = $sale['shock'];
            $salePrice = 0.25 * $price * 10_000_000.0 * (1.0 - DivestitureEngine::DIV_FIRE_SALE_DISCOUNT);

            return ['shock' => $shock, 'tax' => $salePrice - ((float) $stock->getCorporateTreasury() - $cashBefore), 'marketCap' => $price * 10_000_000.0];
        };

        $expected = -0.25 * DivestitureEngine::DIV_FIRE_SALE_DISCOUNT;
        $below = $sell(5.0);
        $this->assertEqualsWithDelta(0.0, $below['tax'], 1e-3, 'a loss on sale pays no tax');
        $this->assertEqualsWithDelta($expected, $below['shock'], 1e-9, 'written down far below book');
        $above = $sell(2_000.0);
        $this->assertGreaterThan(0.0, $above['tax']);
        $this->assertEqualsWithDelta($expected - ($above['tax'] / $above['marketCap']), $above['shock'], 1e-9, 'valued far above book, less the tax on the gain');
    }

    /**
     * A gain on selling a business is taxable income (ASC 740): the seller pays the tax from the proceeds and keeps
     * the gain after it. A loss is carried forward against the income to come, with no asset booked while the
     * firm's losses run.
     */
    public function testAGainOnSaleIsTaxedAndALossIsCarriedForward(): void
    {
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $sell = function (float $price): array {
            $stock = $this->buildLedgeredAcquirer('SELL', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
            $stock->setPrice((string) $price);
            $stock->setOperatingMargin('-0.05');
            $stock->setEarningsPerShare('-0.10');
            $stock->setTotalNetIncome('-1000000000');
            $stock->setRoicTtm('0.00');
            $before = ['equity' => (float) $stock->getTotalEquity(), 'cash' => (float) $stock->getCorporateTreasury(), 'retained' => (float) $stock->getRetainedEarnings()];
            $this->engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0);
            $salePrice = 0.25 * $price * 10_000_000.0 * (1.0 - DivestitureEngine::DIV_FIRE_SALE_DISCOUNT);

            return [$stock, $before, $salePrice];
        };
        $rate = \App\Data\Company\Sectors::getBusinessModelStrategy(\App\Data\Company\Sectors::businessModelFor('Technology'))->getEffectiveTaxRate(0.20);

        [$gainer, $before, $salePrice] = $sell(2_000.0);
        $tax = $salePrice - ((float) $gainer->getCorporateTreasury() - $before['cash']);
        $afterTaxGain = (float) $gainer->getTotalEquity() - $before['equity'];
        $this->assertGreaterThan(0.0, $afterTaxGain);
        $this->assertEqualsWithDelta($rate * ($afterTaxGain + $tax), $tax, 1.0, 'the gain is taxed at the rate, paid from the proceeds');
        $this->assertEqualsWithDelta($afterTaxGain, (float) $gainer->getRetainedEarnings() - $before['retained'], 1.0);

        [$loser, $before, $salePrice] = $sell(5.0);
        $loss = $before['equity'] - (float) $loser->getTotalEquity();
        $this->assertGreaterThan(0.0, $loss);
        $this->assertEqualsWithDelta($salePrice, (float) $loser->getCorporateTreasury() - $before['cash'], 1.0, 'no tax on a loss');
        $this->assertEqualsWithDelta($loss, (float) $loser->getNetOperatingLoss(), 1.0, 'the loss is carried forward');
        $this->assertSame(0.0, $loser->getDeferredTaxAsset(), 'and books no asset while the firm is losing money');
    }

    private function primeDistressedDivestiture(float $operatingBase, float $fraction): void
    {
        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 1000000000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: -1500000000.0,
            revenue: 20000000000.0,
            depreciation: 1000000000.0,
            ebitda: -500000000.0,
        );
        $health = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.02)
            ->withCoverage(-1.5)
            ->wantingToPayDownDebt()
            ->withDebtIssuance(false)
            ->withDebtTolerance(1.0)
            ->withCostOfCapital(0.12, 0.14)
            ->withLeveredBeta(1.5)
            ->withMetrics($debtMetrics)
            ->withLiquidityStress(true, false)
            ->build();

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($health);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($health);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn($operatingBase);
        $this->corporateMetricsMock->method('calculateAddressableShare')->willReturn(0.10);
        // One draw serves the divested fraction and every other uniform the deal takes.
        $this->mathUtilityMock->script(checkProbability: true, generateUniformBetween: $fraction);
        $this->marketEventPublisherMock->method('publish')->willReturn(['event_type' => 'DIVESTITURE']);
    }

    /**
     * A divested banking division takes its loans, its share of the allowance, its deposits and its cash
     * reserves with it. The seller's equity moves by the gain or loss against that book value, and the sheet
     * that remains still balances.
     */
    public function testLenderDivestitureShedsBookDepositsAndCashProRataAndBalances(): void
    {
        $stock = $this->divestingBank();
        $this->assertBalanced($stock, 'the fixture must open balanced');

        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0);
        // A large operating base keeps $3B of reserves from reading as a cash fortress that would call off the sale.
        $this->primeDistressedDivestiture(operatingBase: 40_000_000_000.0, fraction: 0.25);

        $equityBefore = (float) $stock->getTotalEquity();
        $treasuryBefore = (float) $stock->getCorporateTreasury();
        $bookValueDisposed = 0.25 * ($stock->getNetEarningAssets() + $treasuryBefore - 40_000_000_000.0);
        $debtShed = 5_000_000_000.0 * 0.25;

        $result = $this->engine->evaluateCorporateDivestiture($stock, $macroState, 1.0);
        $this->assertIsArray($result);

        $this->assertEqualsWithDelta(50_000_000_000.0 * 0.75, (float) $stock->getEarningAssets(), 1.0, 'the book leaves pro rata');
        $this->assertEqualsWithDelta(1_000_000_000.0 * 0.75, (float) $stock->getCreditLossAllowance(), 1.0, 'and so does its allowance');
        $this->assertEqualsWithDelta(40_000_000_000.0 * 0.75, (float) $stock->getCustomerDeposits(), 1.0, 'the deposits that funded it go to the buyer');
        $this->assertEqualsWithDelta(5_000_000_000.0 * 0.75, (float) $stock->getWholesaleDebt(), 1.0);

        // Cash: the division's reserves left, the proceeds arrived.
        $proceeds = (float) $stock->getCorporateTreasury() - ($treasuryBefore * 0.75);
        $this->assertGreaterThan(0.0, $proceeds);
        $expectedGain = $proceeds - ($bookValueDisposed - $debtShed);
        $this->assertEqualsWithDelta($equityBefore + $expectedGain, (float) $stock->getTotalEquity(), 1.0, 'equity moves by the gain or loss on the book value sold');

        $this->assertBalanced($stock, 'a lender divestiture');
    }

    /**
     * The securities a divested bank division holds take their available-for-sale mark and its deferred tax with them,
     * so the seller's equity sheds the slice's other comprehensive income at the sale, not at the next quarter's mark.
     */
    public function testALenderDivestitureTakesTheSlicesSecuritiesMarkWithIt(): void
    {
        $stock = $this->divestingBank();
        $stock->setUnrealizedSecuritiesMark('-2000000000');
        $stock->setSecuritiesDeferredTax(0.20 * $stock->getRecognizedSecuritiesMark());
        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));
        $this->assertBalanced($stock, 'the fixture must open balanced');

        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0);
        $this->primeDistressedDivestiture(operatingBase: 40_000_000_000.0, fraction: 0.25);

        $equityBefore = (float) $stock->getTotalEquity();
        $treasuryBefore = (float) $stock->getCorporateTreasury();
        $markNetOfTax = $stock->getRecognizedSecuritiesMark() - $stock->getSecuritiesDeferredTax();
        $deferredTaxBefore = $stock->getSecuritiesDeferredTax();
        $bookValueDisposed = 0.25 * ($stock->getNetEarningAssets() + $treasuryBefore - 40_000_000_000.0);

        $this->assertIsArray($this->engine->evaluateCorporateDivestiture($stock, $macroState, 1.0));

        $this->assertEqualsWithDelta(-2_000_000_000.0 * 0.75, (float) $stock->getUnrealizedSecuritiesMark(), 1.0, 'the slice takes its mark');
        $this->assertEqualsWithDelta($deferredTaxBefore * 0.75, $stock->getSecuritiesDeferredTax(), 1.0, 'and the deferred tax on it');
        $proceeds = (float) $stock->getCorporateTreasury() - ($treasuryBefore * 0.75);
        $expectedGain = $proceeds - ($bookValueDisposed - 5_000_000_000.0 * 0.25);
        $this->assertEqualsWithDelta($equityBefore + $expectedGain - 0.25 * $markNetOfTax, (float) $stock->getTotalEquity(), 1.0, 'the slice leaves other comprehensive income at the sale');
        $this->assertBalanced($stock, 'a lender divestiture with a securities mark');
    }

    /** A bank about to sell a quarter of itself: 50B of loans funded by 40B of deposits and 5B of wholesale debt. */
    private function divestingBank(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('DIVB');
        $stock->setName('Divesting Bank');
        $stock->setIndustry('Banks - Diversified');
        $stock->setPrice('20.00');
        $stock->setSharesOutstanding('1000000000');
        $stock->setTotalRevenue('4000000000');
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRetainedEarnings('2000000000');
        $stock->setRoeTtm('0.00');
        $stock->setBaselineRoe('0.08');
        $stock->setCorporateTreasury('3000000000');
        $stock->setCustomerDeposits('40000000000');
        $stock->setWholesaleDebt('5000000000');
        $stock->setEarningAssets('50000000000');
        $stock->setCreditLossAllowance('1000000000');
        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));

        return $stock;
    }
}

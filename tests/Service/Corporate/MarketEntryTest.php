<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Earnings\CapitalBudgetEngine;
use App\Service\Corporate\Earnings\FixedAssetLedger;
use App\Service\Corporate\Earnings\WorkingCapitalLedger;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Segment\EntryMarkets;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentEntry;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A carmaker with cash to spare enters the heavy-machinery business next door: it builds a twentieth-odd of its plant
 * there, booked as construction, at the margin it can learn to earn beside the incumbents; and each condition it needs
 * (the year of reports its peers' figures rest on, the cash, no venture already building, an incumbent to learn from,
 * room in the market, a venture worth its cost) stops it when missing.
 */
final class MarketEntryTest extends TestCase
{
    private const TICK = 300;
    private const TARGET = 'Farm & Heavy Construction Machinery';

    public function testACompanyWithCashToSpareBuildsIntoTheIndustryNextDoor(): void
    {
        [$ctx, $ledger, $store] = $this->entrant();

        $capex = $this->enter($ctx, $ledger);

        $construction = SegmentEntry::VENTURE_PLANT_SHARE * 10_000_000_000.0;
        $this->assertEqualsWithDelta($construction, $capex, 1e-3);
        $segment = Segments::enteredKey(self::TARGET);
        $this->assertSame(['industry' => self::TARGET, 'segment' => $segment, 'capex' => $capex, 'closeness' => 0.5], $ctx->marketEntry);
        $momentum = $ctx->stock->getEarningsMomentumZ() ?? [];
        $this->assertSame([Segments::MAIN => 1.0], SegmentBooks::plant($momentum), 'books open, nothing in service yet');
        $this->assertEqualsWithDelta([$segment => $construction], SegmentBooks::pending($momentum), 1e-3);
        $this->assertEqualsWithDelta(SegmentEntry::attainedMargin(0.30, 0.5), $momentum[SegmentBooks::STATE_MARGIN . $segment] ?? null, 1e-12);
        $this->assertSame(SegmentEntry::learningGap(0.5), $momentum[SegmentBooks::STATE_LEARNING . $segment] ?? null);
        $this->assertEqualsWithDelta($construction, (float) $ctx->stock->getCipBalance(), 1e-3, 'queued as construction');
        $record = $store->readIndustry(self::TARGET)[SegmentBooks::recordKey('ENTR', $segment)] ?? null;
        $this->assertNotNull($record);
        $this->assertSame(0.0, $record['capacity']);
        $this->assertGreaterThan(0.0, $record['entry_offset'] ?? 0.0, 'the fringe has yet to yield it its share');
        $this->assertEqualsWithDelta($construction * 1.5 / (4_000_000_000.0 / 0.3), $record['addressable_share'], 1e-12, 'its share of its new market, not its parent\'s of its own');

        // Its market grows with its new industry's outlook from the start, as its reports will file it.
        [$ctx, $ledger, $store] = $this->entrant();
        $store->writeOutlooks(['salt' => 0, 'quarter' => 1, 'industries' => [self::TARGET => ['x' => log(0.6), 'm' => log(0.6), 'v' => 0.0, 'f' => log(0.6), 'w' => 1.0, 'prior_belief' => log(0.6), 'revised_tick' => -1]]]);
        $this->assertGreaterThan(0.0, $this->enter($ctx, $ledger));
        $this->assertEqualsWithDelta(log(0.6), ($ctx->stock->getEarningsMomentumZ() ?? [])[SegmentBooks::STATE_OUTLOOK . $segment] ?? null, 1e-12);
    }

    public function testEachConditionStopsAnEntryWhenMissing(): void
    {
        $blocked = [
            'too early' => fn(EarningsSimulationContext $ctx): EarningsSimulationContext => $this->entrant(totalTime: 0.5)[0],
            'a venture already building' => function (EarningsSimulationContext $ctx): EarningsSimulationContext {
                $ctx->stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 1.0], 1.0, []) + [SegmentBooks::STATE_PENDING . Segments::enteredKey('Steel') => 1.0e8]);

                return $ctx;
            },
            'a venture still losing money' => function (EarningsSimulationContext $ctx): EarningsSimulationContext {
                $ctx->stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.97, Segments::enteredKey('Steel') => 0.03], 1.0, []) + [SegmentBooks::STATE_LEARNING . Segments::enteredKey('Steel') => 1.5]);

                return $ctx;
            },
            'one new industry at a time' => function (EarningsSimulationContext $ctx): EarningsSimulationContext {
                $ctx->stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.97, Segments::enteredKey('Steel') => 0.03], 1.0, []) + [SegmentBooks::STATE_LEARNING . Segments::enteredKey('Steel') => 0.1]);

                return $ctx;
            },
        ];
        foreach ($blocked as $why => $change) {
            [$ctx, $ledger] = $this->entrant();
            $ctx = $change($ctx);
            $this->assertSame(0.0, $this->enter($ctx, $ledger), $why);
            $this->assertNull($ctx->marketEntry, $why);
        }

        [$ctx, $ledger] = $this->entrant();
        $this->assertSame(0.0, $this->enter($ctx, $ledger, 0.02 * 10_000_000_000.0), 'not the cash');
        [$ctx, $ledger] = $this->entrant(peers: false);
        $this->assertSame(0.0, $this->enter($ctx, $ledger), 'no incumbent to learn from');
        [$ctx, $ledger] = $this->entrant(peerMargin: 0.03);
        $this->assertSame(0.0, $this->enter($ctx, $ledger), 'a venture not worth its cost');
        // Industrial machinery is next to a conglomerate, which would enter it but for being a holding company.
        [$ctx, $ledger] = $this->entrant(industry: 'Conglomerates', target: 'Specialty Industrial Machinery');
        $this->assertSame(0.0, $this->enter($ctx, $ledger), 'a holding company buys, it does not build');
        [$ctx, $ledger] = $this->entrant(incumbentShare: 0.995);
        $this->assertSame(0.0, $this->enter($ctx, $ledger), 'no room in the market');
    }

    /**
     * A business entered in play that has shown what it earns, and still earns less than its manager's hurdle on its
     * plant, is put up for sale: one built once it has learned enough to turn a profit, one bought once integrated. One
     * still learning or being integrated, or one that pays, is not; a venture given up on before failed ventures were
     * sold is put up for sale whatever it earns now.
     */
    public function testABusinessEnteredThatDoesNotPayIsPutUpForSaleOnceItKnows(): void
    {
        $segment = Segments::enteredKey(self::TARGET);
        $gate = function (float $margin, float $learning, bool $built = true, float $integrating = 0.0, bool $givenUp = false) use ($segment): EarningsSimulationContext {
            [$ctx, $ledger] = $this->entrant();
            $venture = $built ? [SegmentBooks::STATE_LEARNING . $segment => $learning, SegmentBooks::STATE_VENTURE_START . $segment => 1.0] : [SegmentBooks::STATE_INTEGRATING . $segment => $integrating];
            $venture += $givenUp ? [SegmentBooks::STATE_VENTURE_ABANDONED . $segment => 1.5] : [];
            $ctx->stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.97, $segment => 0.03], 1.0, []) + $venture);
            foreach ([Segments::MAIN => [0.97, 0.10], $segment => [0.03, $margin]] as $key => [$plant, $segmentMargin]) {
                $ctx->segments[$key] = ['plant' => $plant, 'sales' => $plant, 'margin' => $segmentMargin, 'turnover' => 1.0, 'market' => 0.1, 'price' => 1.0, 'load' => 1.0, 'drain' => 0.0, 'growth' => 0.0, 'leftover' => 0.0];
            }
            $corporateMetrics = new CorporateMetrics();
            $budget = new CapitalBudgetEngine(new CapExEngine(), $corporateMetrics, new FixedAssetLedger($corporateMetrics), new WorkingCapitalLedger($corporateMetrics), $ledger);
            (new ReflectionMethod(CapitalBudgetEngine::class, 'resolveSegmentGates'))->invoke($budget, $ctx, null);

            return $ctx;
        };

        $forSale = static fn(EarningsSimulationContext $ctx): ?float => ($ctx->stock->getEarningsMomentumZ() ?? [])[SegmentBooks::STATE_FOR_SALE . $segment] ?? null;
        $this->assertSame(2.0, $forSale($gate(0.02, 0.5)), 'a venture that has learned and does not pay');
        $this->assertNull($forSale($gate(0.02, 1.5)), 'still learning');
        $this->assertNull($forSale($gate(0.30, 0.5)), 'it pays');
        $this->assertSame(2.0, $forSale($gate(0.02, 0.0, built: false)), 'a business bought, once integrated');
        $this->assertNull($forSale($gate(0.02, 0.0, built: false, integrating: 0.5)), 'still showing what it earns');
        $this->assertSame(2.0, $forSale($gate(0.30, 0.5, givenUp: true)), 'given up on before failures were sold');
        $this->assertNull($forSale($gate(0.30, 0.0, built: false)), 'it pays');
    }

    /**
     * A company looks for markets next to the businesses it still means to keep: a restaurant chain that built into
     * industrial machinery looks next door to it, at heavy machinery, until it puts that business up for sale.
     */
    public function testACompanyLooksNextToTheBusinessesItKeeps(): void
    {
        $machinery = Segments::enteredKey('Specialty Industrial Machinery');
        [$ctx, $ledger] = $this->entrant(industry: 'Restaurants');
        $books = SegmentBooks::open([Segments::MAIN => 0.97, $machinery => 0.03], 1.0, []);
        $survey = fn(): array => (new EntryMarkets($ledger))->survey($ctx->stock, $ctx->macroState, $ctx->tickCount, $ctx->ticksPerYear);

        $ctx->stock->setEarningsMomentumZ($books);
        $this->assertArrayHasKey(self::TARGET, $survey());
        $ctx->stock->setEarningsMomentumZ($books + [SegmentBooks::STATE_FOR_SALE . $machinery => 1.5]);
        $this->assertArrayNotHasKey(self::TARGET, $survey(), 'not next to a business it is selling');
    }

    /**
     * Each business files whether its plant earns the company's cost of capital at a balanced price, its after-tax
     * margin times its turnover, whatever its industry's price this quarter: a company on one plant for its main
     * segment, one that keeps books for each segment.
     */
    public function testEachBusinessFilesWhetherItsPlantPaysAtABalancedPrice(): void
    {
        $pays = function (EarningsSimulationContext $ctx, IndustryShareLedger $ledger): array {
            $corporateMetrics = new CorporateMetrics();
            $budget = new CapitalBudgetEngine(new CapExEngine(), $corporateMetrics, new FixedAssetLedger($corporateMetrics), new WorkingCapitalLedger($corporateMetrics), $ledger);
            (new ReflectionMethod(CapitalBudgetEngine::class, 'resolveEarningPower'))->invoke($budget, $ctx);
            $filed = [];
            foreach ($ctx->stock->getEarningsMomentumZ() ?? [] as $key => $value) {
                if (str_starts_with((string) $key, SegmentBooks::STATE_PAYS)) {
                    $filed[substr((string) $key, strlen(SegmentBooks::STATE_PAYS))] = $value;
                }
            }

            return $filed;
        };
        [$ctx, $ledger] = $this->entrant();
        $hurdle = $ctx->stock->getManagementProfile()->growthHurdle($ctx->strategy->getHurdleRate($ctx->health), false);
        // A year's turnover of 1 after tax at 21%: the margin that just earns the hurdle.
        $breakEven = $hurdle / (1.0 - 0.21);
        $ctx->industryPriceLevel = 0.8;
        $ctx->stableMargin = 1.05 * $breakEven;
        $this->assertSame([Segments::MAIN => 1.0], $pays($ctx, $ledger), 'a glut this quarter does not count');
        $ctx->stableMargin = 0.95 * $breakEven;
        $this->assertSame([Segments::MAIN => 0.0], $pays($ctx, $ledger));

        [$ctx, $ledger] = $this->entrant();
        $segment = Segments::enteredKey(self::TARGET);
        foreach ([Segments::MAIN => 0.95 * $breakEven, $segment => 1.05 * $breakEven] as $key => $margin) {
            $ctx->segments[$key] = ['plant' => 0.5, 'sales' => 0.5, 'margin' => $margin, 'turnover' => 1.0, 'market' => 0.1, 'price' => 0.8, 'load' => 1.0, 'drain' => 0.0, 'growth' => 0.0, 'leftover' => 0.0];
        }
        $this->assertSame([Segments::MAIN => 0.0, $segment => 1.0], $pays($ctx, $ledger));
    }

    /**
     * A carmaker whose quarter's draw falls under its chance of entering, with an incumbent in heavy machinery that has
     * filed what its plant earns.
     *
     * @return array{0: EarningsSimulationContext, 1: IndustryShareLedger, 2: InMemoryIndustryShareStore}
     */
    private function entrant(float $totalTime = 2.0, bool $peers = true, float $incumbentShare = 0.3, float $peerMargin = 0.30, string $industry = 'Auto Manufacturers', string $target = self::TARGET): array
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.03, yield5yEma: 0.035, inflationEma: 0.02, potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: $totalTime);
        if ($peers) {
            $incumbent = (new Stock())->setTicker('MACH')->setIndustry($target)->setTotalRevenue('4000000000');
            $ledger->resolveIndustrySupply($incumbent, 4_000_000_000.0, $incumbentShare, IndustryShareLedger::trendNominalGdp($macro), $totalTime, 0.0, self::TICK, 252);
            $ledger->recordIdiosyncraticGain($incumbent, 4_000_000_000.0, 0.0, $incumbentShare, self::TICK, ['margin' => $peerMargin, 'turnover' => 1.5]);
        }

        $stock = new Stock();
        $stock->setTicker('ENTR');
        $stock->setIndustry($industry);
        $stock->setSharesOutstanding('1000000000');
        $stock->setTotalEquity('8000000000');
        $stock->setWholesaleDebt('2000000000');
        $stock->setTotalRevenue('10000000000');
        $stock->setOperatingMargin('0.10');
        $stock->setAssetTurnover('1.0');
        $stock->setGrossPpe('10000000000');
        $stock->setAccumulatedDepreciation('0');
        $ctx = new EarningsSimulationContext($stock, $macro, Sectors::strategyFor($industry), Sectors::businessModelFor($industry));
        $ctx->health = (new DebtEngine(new MathUtility(), new CorporateMetrics()))->analyzeTrailingDebtHealth($stock, $macro);
        $ctx->baselineRoic = 0.30;
        $ctx->corporateTaxRate = 0.21;
        $ctx->assetTurnover = 0.25;
        $ctx->stableMargin = 0.10;
        $ctx->investedCapital = 10_000_000_000.0;
        $ctx->tickCount = $this->enteringTick($stock);

        return [$ctx, $ledger, $store];
    }

    /** A report tick on which this company's keyed draw falls under its chance of entering. */
    private function enteringTick(Stock $stock): int
    {
        $chance = SegmentEntry::entryChance($stock->getManagementProfile()->acquisitionBias(), 0.25);
        for ($tick = self::TICK; $tick < self::TICK + 20_000; $tick++) {
            if (MathUtility::hashedUniform(0, "entry:{$stock->getTicker()}:{$tick}") < $chance) {
                return $tick;
            }
        }
        $this->fail('no tick enters');
    }

    private function enter(EarningsSimulationContext $ctx, IndustryShareLedger $ledger, float $spareFunds = 1e15): float
    {
        $corporateMetrics = new CorporateMetrics();
        $budget = new CapitalBudgetEngine(new CapExEngine(), $corporateMetrics, new FixedAssetLedger($corporateMetrics), new WorkingCapitalLedger($corporateMetrics), $ledger);

        return (new ReflectionMethod(CapitalBudgetEngine::class, 'enterMarket'))->invoke($budget, $ctx, $spareFunds);
    }
}

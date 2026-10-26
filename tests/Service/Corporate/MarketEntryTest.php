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
     * A venture that has learned enough to turn a profit, and still earns less than its manager's hurdle on its plant
     * at the margin its learning has shown, is given up on and the news says so; one still learning, or one that pays,
     * is not.
     */
    public function testAVentureThatDoesNotPayIsGivenUpOnceItKnows(): void
    {
        $segment = Segments::enteredKey(self::TARGET);
        $gate = function (float $margin, float $learning) use ($segment): EarningsSimulationContext {
            [$ctx, $ledger] = $this->entrant();
            $ctx->stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.97, $segment => 0.03], 1.0, []) + [SegmentBooks::STATE_LEARNING . $segment => $learning]);
            foreach ([Segments::MAIN => [0.97, 0.10], $segment => [0.03, $margin]] as $key => [$plant, $segmentMargin]) {
                $ctx->segments[$key] = ['plant' => $plant, 'sales' => $plant, 'margin' => $segmentMargin, 'turnover' => 1.0, 'market' => 0.1, 'price' => 1.0, 'load' => 1.0, 'drain' => 0.0, 'growth' => 0.0, 'leftover' => 0.0];
            }
            $corporateMetrics = new CorporateMetrics();
            $budget = new CapitalBudgetEngine(new CapExEngine(), $corporateMetrics, new FixedAssetLedger($corporateMetrics), new WorkingCapitalLedger($corporateMetrics), $ledger);
            (new ReflectionMethod(CapitalBudgetEngine::class, 'resolveSegmentGates'))->invoke($budget, $ctx, null);

            return $ctx;
        };

        $failed = $gate(0.02, 0.5);
        $this->assertSame(['industry' => self::TARGET, 'segment' => $segment], $failed->marketExit);
        $this->assertTrue(SegmentBooks::isAbandoned($failed->stock->getEarningsMomentumZ() ?? [], $segment));
        $this->assertNull($gate(0.02, 1.5)->marketExit, 'still learning');
        $this->assertNull($gate(0.30, 0.5)->marketExit, 'it pays');
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

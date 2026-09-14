<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\View\IndustryPositionBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class IndustryPositionBuilderTest extends TestCase
{
    private InMemoryIndustryShareStore $store;
    private IndustryShareLedger $ledger;

    protected function setUp(): void
    {
        $this->store = new InMemoryIndustryShareStore();
        $this->ledger = new IndustryShareLedger($this->store);
    }

    private function stock(string $ticker, string $industry, float $revenue, float $equity = 100_000_000_000.0): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry($industry);
        $stock->setTotalRevenue((string) $revenue);
        $stock->setTotalEquity((string) $equity);
        $stock->setSamRatio('1.00');
        $stock->setRoicTtm('0.20');

        return $stock;
    }

    /**
     * @param array<string, float>|null $kpis
     */
    private function builder(?array $kpis, int $tick = 100): IndustryPositionBuilder
    {
        $report = null;
        if ($kpis !== null) {
            $report = new CorporateReport();
            $report->setReportedKpis($kpis);
        }
        $reports = $this->createStub(CorporateReportRepository::class);
        $reports->method('findLatestFor')->willReturn($report);

        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn((string) $tick);

        return new IndustryPositionBuilder($this->ledger, new CorporateMetrics(), $reports, $redis, 252);
    }

    public function testAnIndustrialReadsItsShareTheBalanceAndTheLastReportsPriceResponse(): void
    {
        $firm = $this->stock('STL', 'Steel', 2_000.0);
        $rival = $this->stock('RVL', 'Steel', 2_000.0);
        $this->ledger->resolveIndustryCapacityRatio($firm, 2_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252);
        $this->ledger->resolveIndustryCapacityRatio($rival, 2_000.0, 0.5, 1.0, 0.0, 0.0, 20, 252);
        // The rival, half its market, then builds half again: 1,000 of excess plant over a market of 4,000.
        $this->ledger->resolveIndustryCapacityRatio($rival, 3_000.0, 0.5, 1.0, 0.0, 0.0, 80, 252);

        $view = $this->builder(['industry_price_level' => 0.93, 'rival_share_drain' => -0.012, 'own_price_volume_shift' => 0.004])
            ->build($firm, new MacroStateDTO());

        // Headline: $100B of capital against a $1T addressable market. The roster share is a separate reading.
        $this->assertEqualsWithDelta(0.10, $view['marketShare'], 1e-9);
        $industry = $view['industry'];
        $this->assertEqualsWithDelta(0.10, $industry['addressableShare'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $industry['rosterShare'], 1e-9);
        $this->assertSame(0.0, $industry['saturation']['penalty'], 'well under the saturation line');
        $this->assertEqualsWithDelta(0.20, $industry['saturation']['marginalReturn'], 1e-9);
        $this->assertTrue($industry['tracked']);
        $this->assertTrue($industry['pricesCapacity']);
        $this->assertEqualsWithDelta(1.25, $industry['capacityRatio'], 1e-9);
        $this->assertSame('overbuilt', $industry['balance']);
        $this->assertSame(2, $industry['rosterSize']);
        $this->assertEqualsWithDelta(0.5, $industry['peerShares']['RVL'], 1e-9);
        $this->assertEqualsWithDelta(0.93, $industry['priceLevel'], 1e-9);
        $this->assertEqualsWithDelta(-0.012, $industry['rivalShareDrain'], 1e-9);
        $this->assertEqualsWithDelta(0.004, $industry['ownPriceVolumeShift'], 1e-9);
    }

    public function testALenderIsNotPricedOnCapacityButStillHasAShareOfItsIndustry(): void
    {
        $bank = $this->stock('BNK', 'Banks - Regional', 500.0);
        $peer = $this->stock('BNQ', 'Banks - Regional', 1_500.0);
        $this->ledger->recordIdiosyncraticGain($peer, 1_500.0, 0.0, 0.5, 10);

        $view = $this->builder(null)->build($bank, new MacroStateDTO());

        $this->assertEqualsWithDelta(0.10, $view['marketShare'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $view['industry']['rosterShare'], 1e-9);
        $this->assertFalse($view['industry']['pricesCapacity']);
        $this->assertNull($view['industry']['capacityRatio']);
        $this->assertSame('unknown', $view['industry']['balance']);
        $this->assertNull($view['industry']['priceLevel'], 'no report has been filed yet');
    }

    public function testADelistedCompanyHasNoPositionLeft(): void
    {
        $shell = $this->stock('GONE', 'Steel', 2_000.0);
        $shell->setIsBankrupt(true);

        $view = $this->builder(null)->build($shell, new MacroStateDTO());

        $this->assertSame(0.0, $view['marketShare']);
        $this->assertFalse($view['industry']['tracked']);
        $this->assertSame([], $view['industry']['peerShares']);
        $this->assertNull($view['industry']['rosterShare']);
    }

    public function testALoneFirmShowsNoRosterShareButItsSaturationPenaltyIsVisible(): void
    {
        // $750B of capital against a $1T market: past the 50% line, so the Penrose friction and the
        // Cobb-Douglas decay both bite, and the page has to say so rather than print a meaningless 100%.
        $giant = $this->stock('GNT', 'Railroads', 5_000.0, 750_000_000_000.0);
        $this->ledger->resolveIndustryCapacityRatio($giant, 5_000.0, 0.75, 1.0, 0.0, 0.0, 10, 252);

        $view = $this->builder(null)->build($giant, new MacroStateDTO(nominalGdpIndex: 1.0));
        $industry = $view['industry'];

        $this->assertEqualsWithDelta(0.75, $view['marketShare'], 1e-9);
        $this->assertNull($industry['rosterShare'], 'alone in its industry: a roster share of 100% says nothing');
        $this->assertSame(1, $industry['rosterSize']);
        $this->assertGreaterThan(0.0, $industry['saturation']['penalty']);
        $this->assertGreaterThan(0.0, $industry['saturation']['severity']);
        $this->assertLessThan($industry['saturation']['trueReturn'], $industry['saturation']['marginalReturn']);
        $this->assertSame(FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD, $industry['saturation']['threshold']);
        // Its own plant against trend, weighed by its share, is still a balance worth showing.
        $this->assertEqualsWithDelta(1.0, $industry['capacityRatio'], 1e-9);
    }
}

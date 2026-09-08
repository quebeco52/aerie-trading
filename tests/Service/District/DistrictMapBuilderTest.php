<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Data\DistrictMap;
use App\Data\Sectors;
use App\DTO\DistrictPlotDTO;
use App\Entity\Stock;
use App\Service\District\DistrictConduitResolver;
use App\Service\District\DistrictMapBuilder;
use App\Service\District\DistrictWardComposer;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\BusinessModelRegistryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the presentation mapping from company fundamentals onto ward facades and the
 * institutions derived from their conduits.
 */
class DistrictMapBuilderTest extends TestCase
{
    private DistrictMapBuilder $builder;
    private DistrictWardComposer $composer;

    protected function setUp(): void
    {
        $registry = new class implements BusinessModelRegistryInterface {
            public function get(string $identifier): BusinessModelInterface
            {
                return Sectors::getBusinessModelStrategy($identifier);
            }

            public function has(string $identifier): bool
            {
                return true;
            }

            public function all(): array
            {
                return [];
            }
        };

        $this->builder = new DistrictMapBuilder(new DistrictConduitResolver($registry));
        $this->composer = new DistrictWardComposer();
    }

    private function makeStock(
        string $ticker,
        float $price = 100.0,
        float $shares = 1_000_000_000.0,
        string $rating = 'BBB',
        bool $bankrupt = false,
        string $importance = 'none',
    ): Stock {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Holdings');
        $stock->setSector('Financials');
        $stock->setIndustry('Banks - Diversified');
        $stock->setPrice((string) $price);
        $stock->setSharesOutstanding((string) $shares);
        $stock->setCreditRating($rating);
        $stock->setSystemicImportance($importance);
        $stock->setBaselineRoe('0.15');
        $stock->setIsBankrupt($bankrupt);

        return $stock;
    }

    /** @param Stock[] $stocks */
    private function buildPlots(array $stocks): array
    {
        $frontage = $this->composer->composeFrontage($stocks);

        return $this->builder->buildWard($frontage['slots'], $stocks);
    }

    public function testFacadeHeightIsClampedToTheEnvelope(): void
    {
        $tiny = $this->builder->calculateFacadeHeight(1.0);
        $vast = $this->builder->calculateFacadeHeight(1.0e18);

        // The logistic curve only asymptotically approaches its bounds — even at 1e18 market cap
        // it lands a fraction of a unit short of MAX_FACADE_HEIGHT, never exactly on it, and how
        // short depends on the floor/ceiling span. So assert the bound is approached and never
        // crossed rather than a fixed delta, which would break on every retune of the window.
        $this->assertGreaterThanOrEqual(DistrictMap::MIN_FACADE_HEIGHT, $tiny);
        $this->assertLessThan(DistrictMap::MIN_FACADE_HEIGHT + 1.0, $tiny);
        $this->assertLessThanOrEqual(DistrictMap::MAX_FACADE_HEIGHT, $vast);
        $this->assertGreaterThan(DistrictMap::MAX_FACADE_HEIGHT - 1.0, $vast);
    }

    public function testFacadeHeightRisesMonotonicallyWithMarketCap(): void
    {
        $small = $this->builder->calculateFacadeHeight(1.0e11);
        $medium = $this->builder->calculateFacadeHeight(1.0e12);
        $large = $this->builder->calculateFacadeHeight(5.0e12);

        $this->assertGreaterThan($small, $medium);
        $this->assertGreaterThan($medium, $large);
    }

    /**
     * The envelope exists to make cap legible as height, so the largest tenants a long-running
     * market produces must still read apart. A $10T and a $15T facade previously differed by
     * ~13 units out of 530 — visually identical — because the logistic window was centred on
     * $1T and both sat in its flat tail.
     */
    public function testTheLargestTenantsStillReadApart(): void
    {
        $ten = $this->builder->calculateFacadeHeight(1.0e13);
        $fifteen = $this->builder->calculateFacadeHeight(1.5e13);

        $this->assertGreaterThan(30.0, $fifteen - $ten);
        // And both stay clear of the ceiling, so an even larger tenant has somewhere left to go.
        $this->assertLessThan(DistrictMap::MAX_FACADE_HEIGHT - 100.0, $fifteen);
    }

    /**
     * A gridline crowded against its neighbour informs less than no gridline at all — the labels
     * are printed at GRIDLINE_LABEL_SIZE, so consecutive rules must clear that.
     */
    public function testGridlinesOnARowNeverCrowdEachOther(): void
    {
        $ys = [];
        foreach ($this->builder->buildGridlines(1) as $line) {
            $ys[] = $line['y'];
        }
        sort($ys);

        for ($i = 1; $i < count($ys); $i++) {
            $this->assertGreaterThan(
                (float) DistrictMap::GRIDLINE_LABEL_SIZE,
                $ys[$i] - $ys[$i - 1],
                'Consecutive gridline labels would overlap in the gutter'
            );
        }
    }

    public function testFacadesStandOnTheirOwnRowsGroundLine(): void
    {
        $plots = $this->buildPlots([$this->makeStock('LAKE'), $this->makeStock('RIVR')]);

        $this->assertNotEmpty($plots);
        foreach ($plots as $plot) {
            $this->assertEqualsWithDelta($plot->groundLine, $plot->y + $plot->height, 0.0001);
            $this->assertSame(DistrictMap::groundLineForRow($plot->row), $plot->groundLine);
            $this->assertGreaterThanOrEqual(0.0, $plot->y);
        }
    }

    public function testGridlinesAgreeWithTheFacadeCurveOnEveryRow(): void
    {
        $gridlines = $this->builder->buildGridlines(2);

        $this->assertCount(2 * count(DistrictMap::MARKET_CAP_GRIDLINES), $gridlines);

        foreach ($gridlines as $line) {
            $marketCap = DistrictMap::MARKET_CAP_GRIDLINES[$line['label']];
            $expected = DistrictMap::groundLineForRow($line['row']) - $this->builder->calculateFacadeHeight($marketCap);

            $this->assertEqualsWithDelta($expected, $line['y'], 0.0001);
        }
    }

    public function testTheSameCapSitsAtADifferentHeightOnEachRow(): void
    {
        $gridlines = $this->builder->buildGridlines(2);

        $upper = array_values(array_filter($gridlines, static fn (array $l) => $l['row'] === 0 && $l['label'] === '$1T'));
        $lower = array_values(array_filter($gridlines, static fn (array $l) => $l['row'] === 1 && $l['label'] === '$1T'));

        $this->assertNotSame(
            $upper[0]['y'],
            $lower[0]['y'],
            'A market cap maps to a facade height, so its rule must sit at each row\'s own y'
        );
    }

    public function testEverySlotIsRenderedInComposedOrder(): void
    {
        $stocks = [$this->makeStock('LAKE'), $this->makeStock('RIVR')];
        $frontage = $this->composer->composeFrontage($stocks);
        $plots = $this->builder->buildWard($frontage['slots'], $stocks);

        $this->assertSame(
            array_map(static fn (array $slot) => $slot['ticker'], $frontage['slots']),
            array_map(static fn (DistrictPlotDTO $plot) => $plot->ticker, $plots)
        );
    }

    public function testEveryQualifyingTenantCarriesItsIdentityAndRank(): void
    {
        $plots = $this->buildPlots([$this->makeStock('LAKE')]);

        $this->assertCount(1, $plots);
        $this->assertSame('LAKE', $plots[0]->ticker);
        $this->assertSame(1, $plots[0]->rank, 'The only tenant is the largest on the street');
        $this->assertSame('Financials', $plots[0]->sector);
    }

    public function testChangePercentIsCarriedThroughWhenSuppliedAndNullOtherwise(): void
    {
        $stocks = [$this->makeStock('LAKE')];
        $frontage = $this->composer->composeFrontage($stocks);

        $withChange = $this->builder->buildWard($frontage['slots'], $stocks, ['LAKE' => 0.0125]);
        $this->assertEqualsWithDelta(0.0125, $withChange[0]->changePercent, 0.0001);

        $withoutChange = $this->builder->buildWard($frontage['slots'], $stocks);
        $this->assertNull($withoutChange[0]->changePercent, 'No buffered history must read as unknown, not as flat');
    }

    public function testInvestmentGradeTenantsRenderAsSound(): void
    {
        $plot = $this->findPlot('LAKE', [$this->makeStock('LAKE', rating: 'AA')]);

        $this->assertSame('sound', $plot->condition);
    }

    public function testJunkRatedTenantsRenderAsDistressed(): void
    {
        $plot = $this->findPlot('LAKE', [$this->makeStock('LAKE', rating: 'CCC')]);

        $this->assertSame('distressed', $plot->condition);
    }

    public function testBankruptTenantsRenderAsRuins(): void
    {
        $plot = $this->findPlot('LAKE', [$this->makeStock('LAKE', price: 0.0, rating: 'D', bankrupt: true)]);

        $this->assertSame('ruin', $plot->condition);
        $this->assertEqualsWithDelta(DistrictMap::MIN_FACADE_HEIGHT, $plot->height, 1.0e-6);
    }

    public function testNoStocksYieldsNoPlots(): void
    {
        $this->assertSame([], $this->buildPlots([]));
    }

    public function testOccupiedPlotsCarryConduitsForTheirBusinessModel(): void
    {
        $plot = $this->findPlot('LAKE', [$this->makeStock('LAKE')]);

        // Banks - Diversified => commercial_bank, which reads land-registry fields — see
        // DistrictConduitTopologyTest::financialModelConduitProvider().
        $this->assertContains('land-registry', $plot->conduits);
    }

    public function testInstitutionsAreOnlyThoseWiredToAnOccupiedPlot(): void
    {
        $stocks = [$this->makeStock('LAKE')];
        $frontage = $this->composer->composeFrontage($stocks);
        $plots = $this->builder->buildWard($frontage['slots'], $stocks);
        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);

        $institutions = $this->builder->buildInstitutions($plots, $viewboxWidth);

        $expectedIds = [];
        foreach ($plots as $plot) {
            foreach ($plot->conduits as $id) {
                $expectedIds[$id] = true;
            }
        }

        $this->assertSame(array_keys($expectedIds), array_map(static fn ($i) => $i->id, $institutions));
        $this->assertNotEmpty($institutions);
    }

    public function testInstitutionsDoNotOverlapAndStayInsideTheCanvas(): void
    {
        $stocks = [$this->makeStock('LAKE'), $this->makeStock('RIVR'), $this->makeStock('ACC')];
        $frontage = $this->composer->composeFrontage($stocks);
        $plots = $this->builder->buildWard($frontage['slots'], $stocks);
        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);
        $institutions = $this->builder->buildInstitutions($plots, $viewboxWidth);

        $previousEdge = 0;
        foreach ($institutions as $institution) {
            $this->assertGreaterThanOrEqual($previousEdge, $institution->x);
            $this->assertLessThanOrEqual($viewboxWidth, $institution->x + $institution->width);
            $this->assertGreaterThanOrEqual(DistrictMap::MIN_INSTITUTION_WIDTH, $institution->width);
            $this->assertLessThanOrEqual(DistrictMap::MAX_INSTITUTION_WIDTH, $institution->width);
            $previousEdge = $institution->x + $institution->width;
        }
    }

    public function testResolveViewboxWidthGrowsToFitAWideInstitutionBandOnANarrowStreet(): void
    {
        // A single narrow-street tenant wired to several institutions must never let
        // MIN_INSTITUTION_WIDTH push the band past the frontage's own (narrow) width.
        $stocks = [$this->makeStock('LAKE')];
        $frontage = $this->composer->composeFrontage($stocks);
        $plots = $this->builder->buildWard($frontage['slots'], $stocks);

        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);
        $institutions = $this->builder->buildInstitutions($plots, $viewboxWidth);

        $this->assertGreaterThanOrEqual($frontage['viewboxWidth'], $viewboxWidth);
        foreach ($institutions as $institution) {
            $this->assertLessThanOrEqual($viewboxWidth, $institution->x + $institution->width);
        }
    }

    public function testNoOccupiedPlotsYieldsNoInstitutionsAndNoWidening(): void
    {
        $frontage = $this->composer->composeFrontage([]);
        $plots = $this->builder->buildWard($frontage['slots'], []);

        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);

        $this->assertSame($frontage['viewboxWidth'], $viewboxWidth);
        $this->assertSame([], $this->builder->buildInstitutions($plots, $viewboxWidth));
    }

    /** @param Stock[] $stocks */
    private function findPlot(string $ticker, array $stocks): DistrictPlotDTO
    {
        foreach ($this->buildPlots($stocks) as $plot) {
            if ($plot->ticker === $ticker) {
                return $plot;
            }
        }

        $this->fail(sprintf('No plot rendered for %s', $ticker));
    }
}

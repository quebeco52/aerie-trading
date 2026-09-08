<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Data\DistrictMap;
use App\Entity\Stock;
use App\Service\District\DistrictMapBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the presentation mapping from company fundamentals onto ward facades.
 */
class DistrictMapBuilderTest extends TestCase
{
    private DistrictMapBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DistrictMapBuilder();
    }

    private function makeStock(
        string $ticker,
        float $price = 100.0,
        float $shares = 1_000_000_000.0,
        string $rating = 'BBB',
        bool $bankrupt = false,
    ): Stock {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Holdings');
        $stock->setSector('Financials');
        $stock->setIndustry('Banks - Diversified');
        $stock->setPrice((string) $price);
        $stock->setSharesOutstanding((string) $shares);
        $stock->setCreditRating($rating);
        $stock->setSystemicImportance('none');
        $stock->setBaselineRoe('0.15');
        $stock->setIsBankrupt($bankrupt);

        return $stock;
    }

    public function testFacadeHeightIsClampedToTheEnvelope(): void
    {
        $tiny = $this->builder->calculateFacadeHeight(1.0);
        $vast = $this->builder->calculateFacadeHeight(1.0e18);

        $this->assertSame(DistrictMap::MIN_FACADE_HEIGHT, $tiny);
        $this->assertSame(DistrictMap::MAX_FACADE_HEIGHT, $vast);
    }

    public function testFacadeHeightRisesMonotonicallyWithMarketCap(): void
    {
        $small = $this->builder->calculateFacadeHeight(1.0e11);
        $medium = $this->builder->calculateFacadeHeight(1.0e12);
        $large = $this->builder->calculateFacadeHeight(5.0e12);

        $this->assertGreaterThan($small, $medium);
        $this->assertGreaterThan($medium, $large);
    }

    public function testOccupiedFacadesStandOnTheGroundLine(): void
    {
        $ground = (float) DistrictMap::WARDS['glasswater-row']['ground_line'];
        $plots = $this->builder->buildWard('glasswater-row', $this->buildTenants());

        $this->assertNotEmpty($plots);
        foreach ($plots as $plot) {
            $this->assertEqualsWithDelta($ground, $plot->y + $plot->height, 0.0001);
            $this->assertGreaterThanOrEqual(0.0, $plot->y);
        }
    }

    public function testEveryAuthoredPlotIsRenderedInOrder(): void
    {
        $plots = $this->builder->buildWard('glasswater-row', $this->buildTenants());
        $authored = array_keys(DistrictMap::plotsForWard('glasswater-row'));

        $this->assertSame($authored, array_map(static fn ($plot) => $plot->plotId, $plots));
    }

    public function testVacantLotsRenderUnoccupied(): void
    {
        $plots = $this->builder->buildWard('glasswater-row', $this->buildTenants());

        $vacant = array_values(array_filter($plots, static fn ($plot) => !$plot->isOccupied()));

        $this->assertNotEmpty($vacant, 'Expected reserved frontage on Glasswater Row');
        foreach ($vacant as $plot) {
            $this->assertNull($plot->ticker);
            $this->assertSame('vacant', $plot->condition);
            $this->assertSame(0, $plot->floors);
        }
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
        $this->assertSame(DistrictMap::MIN_FACADE_HEIGHT, $plot->height);
    }

    public function testMissingTenantsFallBackToAnEmptyLot(): void
    {
        $plots = $this->builder->buildWard('glasswater-row', []);

        foreach ($plots as $plot) {
            $this->assertFalse($plot->isOccupied());
        }
    }

    /** @return Stock[] */
    private function buildTenants(): array
    {
        $stocks = [];
        foreach (DistrictMap::tickersForWard('glasswater-row') as $ticker) {
            $stocks[] = $this->makeStock($ticker);
        }

        return $stocks;
    }

    /** @param Stock[] $stocks */
    private function findPlot(string $ticker, array $stocks): \App\DTO\DistrictPlotDTO
    {
        foreach ($this->builder->buildWard('glasswater-row', $stocks) as $plot) {
            if ($plot->ticker === $ticker) {
                return $plot;
            }
        }

        $this->fail(sprintf('No plot rendered for %s', $ticker));
    }
}

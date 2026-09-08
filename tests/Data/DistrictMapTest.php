<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\DistrictMap;
use App\Data\InitialMarket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the authored cartography: every plot must reference a real listing, sit in a declared
 * ward, and occupy exclusive street frontage inside its ward's canvas.
 */
class DistrictMapTest extends TestCase
{
    public static function wardProvider(): array
    {
        $wards = [];
        foreach (array_keys(DistrictMap::WARDS) as $slug) {
            $wards[$slug] = [$slug];
        }

        return $wards;
    }

    private static function listedTickers(): array
    {
        return array_column(InitialMarket::STOCKS, 'ticker');
    }

    private static function stockByTicker(string $ticker): ?array
    {
        foreach (InitialMarket::STOCKS as $stock) {
            if (($stock['ticker'] ?? null) === $ticker) {
                return $stock;
            }
        }

        return null;
    }

    public function testEveryPlotBelongsToADeclaredWard(): void
    {
        foreach (DistrictMap::PLOTS as $plotId => $plot) {
            $this->assertArrayHasKey(
                $plot['ward'],
                DistrictMap::WARDS,
                sprintf('Plot %s references undeclared ward "%s"', $plotId, $plot['ward'])
            );
        }
    }

    public function testEveryAssignedTickerIsAListedCompany(): void
    {
        $listed = self::listedTickers();

        foreach (DistrictMap::PLOTS as $plotId => $plot) {
            if ($plot['ticker'] === null) {
                continue;
            }

            $this->assertContains(
                $plot['ticker'],
                $listed,
                sprintf('Plot %s is assigned to "%s", which is not in InitialMarket', $plotId, $plot['ticker'])
            );
        }
    }

    public function testNoCompanyHoldsTwoPlots(): void
    {
        $seen = [];

        foreach (DistrictMap::PLOTS as $plotId => $plot) {
            if ($plot['ticker'] === null) {
                continue;
            }

            $this->assertArrayNotHasKey(
                $plot['ticker'],
                $seen,
                sprintf('%s holds both plot %s and plot %s', $plot['ticker'], $seen[$plot['ticker']] ?? '?', $plotId)
            );
            $seen[$plot['ticker']] = $plotId;
        }
    }

    #[DataProvider('wardProvider')]
    public function testPlotsDoNotOverlapAndStayInsideTheCanvas(string $wardSlug): void
    {
        $canvasWidth = DistrictMap::WARDS[$wardSlug]['viewbox_width'];
        $previousEdge = 0;

        foreach (DistrictMap::plotsForWard($wardSlug) as $plotId => $plot) {
            $this->assertGreaterThanOrEqual(
                $previousEdge,
                $plot['x'],
                sprintf('Plot %s overlaps the frontage of the plot to its west', $plotId)
            );
            $this->assertLessThanOrEqual(
                $canvasWidth,
                $plot['x'] + $plot['width'],
                sprintf('Plot %s extends past the eastern edge of the ward canvas', $plotId)
            );

            $previousEdge = $plot['x'] + $plot['width'];
        }
    }

    #[DataProvider('wardProvider')]
    public function testWardTenantsMatchTheWardSector(string $wardSlug): void
    {
        $expectedSector = DistrictMap::WARDS[$wardSlug]['sector'];

        foreach (DistrictMap::tickersForWard($wardSlug) as $ticker) {
            $stock = self::stockByTicker($ticker);

            $this->assertNotNull($stock, sprintf('%s is not defined in InitialMarket', $ticker));
            $this->assertSame(
                $expectedSector,
                $stock['sector'] ?? null,
                sprintf('%s sits in %s but is not a %s listing', $ticker, $wardSlug, $expectedSector)
            );
        }
    }

    #[DataProvider('wardProvider')]
    public function testWardReservesFrontageForFutureListings(string $wardSlug): void
    {
        $vacant = array_filter(
            DistrictMap::plotsForWard($wardSlug),
            static fn (array $plot): bool => $plot['ticker'] === null
        );

        $this->assertNotEmpty($vacant, sprintf('Ward %s has no vacant lot for procedural listings', $wardSlug));
    }

    #[DataProvider('wardProvider')]
    public function testFacadeEnvelopeFitsAboveTheGroundLine(string $wardSlug): void
    {
        $ward = DistrictMap::WARDS[$wardSlug];

        $this->assertLessThanOrEqual(
            $ward['ground_line'],
            DistrictMap::MAX_FACADE_HEIGHT,
            sprintf('Tallest facade in %s would overflow the top of the canvas', $wardSlug)
        );
        $this->assertLessThan(
            $ward['viewbox_height'],
            $ward['ground_line'],
            sprintf('Ward %s leaves no room below the ground line', $wardSlug)
        );
    }

    public function testInstitutionsDoNotOverlapAndStayInsideTheCanvas(): void
    {
        // Institutions are not yet ward-scoped (only one ward exists); this pins them against
        // Glasswater Row's canvas, the same way testPlotsDoNotOverlapAndStayInsideTheCanvas() does
        // for plots.
        $canvasWidth = DistrictMap::WARDS['glasswater-row']['viewbox_width'];
        $previousEdge = 0;

        foreach (DistrictMap::INSTITUTIONS as $institutionId => $institution) {
            $this->assertGreaterThanOrEqual(
                $previousEdge,
                $institution['x'],
                sprintf('Institution %s overlaps the structure to its west', $institutionId)
            );
            $this->assertLessThanOrEqual(
                $canvasWidth,
                $institution['x'] + $institution['width'],
                sprintf('Institution %s extends past the eastern edge of the ward canvas', $institutionId)
            );

            $previousEdge = $institution['x'] + $institution['width'];
        }
    }

    public function testInstitutionBandSitsAboveTheConduitAirspaceAndTallestFacade(): void
    {
        $institutionBottom = DistrictMap::INSTITUTION_BAND_TOP + DistrictMap::INSTITUTION_BAND_HEIGHT;

        $this->assertLessThan(
            DistrictMap::INSTITUTION_OUTLET_Y,
            $institutionBottom,
            'Institution structures must not extend below their own conduit outlet.'
        );

        $tallestRooftop = DistrictMap::WARDS['glasswater-row']['ground_line'] - DistrictMap::MAX_FACADE_HEIGHT;

        $this->assertLessThan(
            $tallestRooftop,
            DistrictMap::INSTITUTION_OUTLET_Y,
            'Conduits must have airspace to curve through above the tallest possible rooftop.'
        );
    }
}

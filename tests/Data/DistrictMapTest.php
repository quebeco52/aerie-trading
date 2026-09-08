<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\DistrictMap;
use App\Data\InitialMarket;
use App\Data\Sectors;
use PHPUnit\Framework\TestCase;

/**
 * Guards the authored cartography: FRONTAGE_ORDER seats every business model the listed universe
 * can actually carry, and the shared canvas geometry stays internally consistent. Which ~30
 * companies actually hold frontage on any given request is a live ranking, not authored data —
 * see App\Service\District\DistrictWardComposerTest for that behaviour.
 */
class DistrictMapTest extends TestCase
{
    /** @return list<string> distinct business models InitialMarket::STOCKS actually carries */
    private static function businessModelsInUse(): array
    {
        $models = [];
        foreach (InitialMarket::STOCKS as $stock) {
            $industry = $stock['industry'] ?? 'General';
            $models[Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none'] = true;
        }

        return array_keys($models);
    }

    /**
     * The coverage guarantee this design depends on: a company whose business model is not in
     * FRONTAGE_ORDER is invisible to DistrictWardComposer regardless of its market cap — see
     * DistrictWardComposer::composeFrontage()'s qualification filter. This is what would have
     * caught BRKW-style orphans (a company reclassified to a sector/model this list never
     * learned about).
     */
    public function testFrontageOrderCoversEveryBusinessModelInTheListedUniverse(): void
    {
        foreach (self::businessModelsInUse() as $businessModel) {
            $this->assertContains(
                $businessModel,
                DistrictMap::FRONTAGE_ORDER,
                sprintf('Business model "%s" is carried by a listed company but has no position in FRONTAGE_ORDER — it can never hold frontage.', $businessModel)
            );
        }
    }

    public function testFrontageOrderHasNoDuplicates(): void
    {
        $this->assertSame(
            array_unique(DistrictMap::FRONTAGE_ORDER),
            DistrictMap::FRONTAGE_ORDER,
            'A business model listed twice in FRONTAGE_ORDER would have its second position unreachable.'
        );
    }

    public function testStreetRosterSizeIsPositive(): void
    {
        $this->assertGreaterThan(0, DistrictMap::STREET_ROSTER_SIZE);
    }

    public function testEveryRowsFacadeEnvelopeFitsAboveItsGroundLine(): void
    {
        foreach (DistrictMap::ROW_GROUND_LINES as $row => $groundLine) {
            $this->assertLessThanOrEqual(
                $groundLine,
                DistrictMap::MAX_FACADE_HEIGHT,
                sprintf('Tallest facade on row %d would overflow the top of the canvas', $row)
            );
        }

        $this->assertLessThan(
            DistrictMap::VIEWBOX_HEIGHT,
            DistrictMap::ROW_GROUND_LINES[count(DistrictMap::ROW_GROUND_LINES) - 1] + DistrictMap::KERB_DEPTH,
            'The lowest row leaves no room for its kerb and the water below it'
        );
    }

    /**
     * The spacing rule the two-row canvas rests on: the tallest possible facade on a row must
     * clear the kerb of the row above it, or buildings would grow through the pavement.
     */
    public function testEachRowClearsTheKerbOfTheRowAbove(): void
    {
        $groundLines = DistrictMap::ROW_GROUND_LINES;

        for ($row = 1; $row < count($groundLines); $row++) {
            $tallestRooftop = $groundLines[$row] - DistrictMap::MAX_FACADE_HEIGHT;
            $kerbAbove = $groundLines[$row - 1] + DistrictMap::KERB_DEPTH;

            $this->assertGreaterThanOrEqual(
                $kerbAbove,
                $tallestRooftop,
                sprintf('A full-height facade on row %d would grow through row %d\'s kerb', $row, $row - 1)
            );
        }
    }

    public function testInstitutionBandSitsAboveTheConduitCorridorAndTallestFacade(): void
    {
        $institutionBottom = DistrictMap::INSTITUTION_BAND_TOP + DistrictMap::INSTITUTION_BAND_HEIGHT;

        $this->assertLessThan(
            DistrictMap::INSTITUTION_OUTLET_Y,
            $institutionBottom,
            'Institution structures must not extend below their own conduit outlet.'
        );

        $this->assertGreaterThan(
            DistrictMap::INSTITUTION_OUTLET_Y,
            DistrictMap::CONDUIT_CORRIDOR_Y,
            'The corridor conduits traverse must sit below the outlet they leave from.'
        );

        // The corridor only works if it clears every possible rooftop on the upper row —
        // otherwise a lower-row conduit's horizontal run would cut through buildings.
        $tallestRooftop = DistrictMap::ROW_GROUND_LINES[0] - DistrictMap::MAX_FACADE_HEIGHT - 7;

        $this->assertLessThan(
            $tallestRooftop,
            DistrictMap::CONDUIT_CORRIDOR_Y,
            'Conduits must traverse above the tallest possible rooftop on the upper row.'
        );
    }

    public function testEveryMacroSectorHasAPaletteEntry(): void
    {
        foreach (array_keys(Sectors::MACRO_SECTORS) as $sector) {
            $this->assertArrayHasKey(
                $sector,
                DistrictMap::SECTOR_PALETTE,
                sprintf('Sector "%s" would render with the fallback palette rather than its own hue', $sector)
            );
        }
    }

    public function testEveryPaletteEntryCarriesAFacadeStrokeAndWindowColour(): void
    {
        $entries = DistrictMap::SECTOR_PALETTE + ['fallback' => DistrictMap::SECTOR_PALETTE_FALLBACK];

        foreach ($entries as $sector => $palette) {
            foreach (['facade', 'stroke', 'window'] as $key) {
                $this->assertArrayHasKey($key, $palette, sprintf('Palette for "%s" is missing "%s"', $sector, $key));
                $this->assertMatchesRegularExpression(
                    '/^#[0-9a-f]{6}$/',
                    $palette[$key],
                    sprintf('Palette "%s" for "%s" is not a six-digit hex colour', $key, $sector)
                );
            }
        }
    }

    public function testPaletteForSectorFallsBackRatherThanFailing(): void
    {
        $this->assertSame(DistrictMap::SECTOR_PALETTE_FALLBACK, DistrictMap::paletteForSector(null));
        $this->assertSame(DistrictMap::SECTOR_PALETTE_FALLBACK, DistrictMap::paletteForSector('Nonexistent Sector'));
        $this->assertSame(DistrictMap::SECTOR_PALETTE['Financials'], DistrictMap::paletteForSector('Financials'));
    }

    public function testGridlineCapsFallInsideTheFacadeEnvelope(): void
    {
        $this->assertNotEmpty(DistrictMap::MARKET_CAP_GRIDLINES);

        foreach (DistrictMap::MARKET_CAP_GRIDLINES as $label => $marketCap) {
            $logCap = log10($marketCap);
            $this->assertGreaterThan(
                DistrictMap::MARKET_CAP_LOG_FLOOR,
                $logCap,
                sprintf('Gridline %s sits below the envelope floor and would pin to the shortest facade', $label)
            );
            $this->assertLessThan(
                DistrictMap::MARKET_CAP_LOG_CEILING,
                $logCap,
                sprintf('Gridline %s sits above the envelope ceiling and would pin to the tallest facade', $label)
            );
        }
    }

    public function testGroundLineForRowClampsRatherThanFailing(): void
    {
        $this->assertSame(DistrictMap::ROW_GROUND_LINES[0], DistrictMap::groundLineForRow(0));
        $this->assertSame(
            DistrictMap::ROW_GROUND_LINES[count(DistrictMap::ROW_GROUND_LINES) - 1],
            DistrictMap::groundLineForRow(99)
        );
    }

    public function testPlotWidthForImportanceCoversEveryTierInUse(): void
    {
        foreach (InitialMarket::STOCKS as $stock) {
            $width = DistrictMap::plotWidthForImportance($stock['systemic_importance'] ?? null);
            $this->assertGreaterThan(0, $width);
        }

        $this->assertSame(DistrictMap::PLOT_WIDTH_BY_IMPORTANCE['none'], DistrictMap::plotWidthForImportance(null));
        $this->assertSame(DistrictMap::PLOT_WIDTH_BY_IMPORTANCE['none'], DistrictMap::plotWidthForImportance('unrecognised-tier'));
    }
}

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

    public function testRowSpacingLeavesRoomForTheRoofFurniture(): void
    {
        // Rank label at y-14, titan beacon at y-20, event badge at y-18 with r=15, flare at y-34: all inside ROW_GAP.
        $this->assertGreaterThanOrEqual(40, DistrictMap::ROW_GAP);
        // The tallest thing on any roof is its furniture, standing on the 7-unit roofline.
        $this->assertGreaterThan(7 + DistrictMap::ROOF_FURNITURE_HEIGHT, DistrictMap::ROW_GAP, 'Roof furniture must stay inside the row gap');
        $this->assertGreaterThan(DistrictMap::SECTOR_BRACKET_LABEL_OFFSET, DistrictMap::KERB_DEPTH, 'The sector bracket label must sit inside the kerb band');
        $this->assertGreaterThan(DistrictMap::SECTOR_BRACKET_RULE_OFFSET, DistrictMap::SECTOR_BRACKET_LABEL_OFFSET);
        $this->assertGreaterThan(88, DistrictMap::SECTOR_BRACKET_RULE_OFFSET, 'The bracket rule must clear the change line printed at +88');
        $this->assertGreaterThan(0, DistrictMap::CANVAS_BOTTOM_MARGIN);
    }

    public function testInstitutionBandSitsAboveItsOwnOutletAndLanes(): void
    {
        $institutionBottom = DistrictMap::INSTITUTION_BAND_TOP + DistrictMap::INSTITUTION_BAND_HEIGHT;

        $this->assertLessThan(
            DistrictMap::INSTITUTION_OUTLET_Y,
            $institutionBottom,
            'Institution structures must not extend below their own conduit outlet.'
        );

        // The name, then the readouts each with a sparkline strip under it, all above the outlet.
        $this->assertLessThan(DistrictMap::INSTITUTION_READOUT_TOP_OFFSET, DistrictMap::INSTITUTION_LABEL_OFFSET);
        $this->assertGreaterThan(DistrictMap::INSTITUTION_SPARKLINE_GAP + DistrictMap::INSTITUTION_SPARKLINE_HEIGHT, DistrictMap::INSTITUTION_READOUT_PITCH, 'A sparkline must fit between two readout lines');
        $lowestSparklineBottom = $institutionBottom + DistrictMap::INSTITUTION_READOUT_TOP_OFFSET
            + (DistrictMap::INSTITUTION_READOUT_LINES - 1) * DistrictMap::INSTITUTION_READOUT_PITCH
            + DistrictMap::INSTITUTION_SPARKLINE_GAP + DistrictMap::INSTITUTION_SPARKLINE_HEIGHT;
        $this->assertLessThanOrEqual(DistrictMap::INSTITUTION_OUTLET_Y, $lowestSparklineBottom, 'The last sparkline must clear the conduit outlet');
        $this->assertLessThanOrEqual(DistrictMap::MIN_INSTITUTION_WIDTH, DistrictMap::INSTITUTION_SPARKLINE_WIDTH, 'A sparkline must fit inside the narrowest institution');

        foreach (DistrictMap::INSTITUTIONS as $id => $institution) {
            $this->assertLessThanOrEqual(DistrictMap::INSTITUTION_READOUT_LINES, count($institution['readouts']), sprintf('%s prints more readouts than the outlet is placed for', $id));
        }

        // A 3-unit dashed stroke needs clear space either side to read as its own lane.
        $this->assertGreaterThanOrEqual(10, DistrictMap::CONDUIT_LANE_PITCH);
        $this->assertGreaterThan(0, DistrictMap::CONDUIT_LANE_TOP_INSET);
        $this->assertGreaterThan(0, DistrictMap::CONDUIT_DROP_INSET);
        $this->assertLessThan(
            min(DistrictMap::PLOT_WIDTH_BY_IMPORTANCE) / 2,
            DistrictMap::CONDUIT_DROP_INSET,
            'The drop inset must leave the narrowest roof some width to spread drops across'
        );
    }

    public function testWindowGeometryFitsAtLeastOneColumnOnTheNarrowestPlot(): void
    {
        $narrowest = min(DistrictMap::PLOT_WIDTH_BY_IMPORTANCE);

        $this->assertGreaterThanOrEqual(1, intdiv($narrowest - DistrictMap::WINDOW_WALL_ALLOWANCE, DistrictMap::WINDOW_PITCH));
        $this->assertLessThan(DistrictMap::WINDOW_PITCH, DistrictMap::WINDOW_WIDTH);
        $this->assertLessThan(DistrictMap::FLOOR_HEIGHT, DistrictMap::WINDOW_HEIGHT);
    }

    public function testWindowLightingSharesAreOrderedAndInsideTheUnitInterval(): void
    {
        $this->assertGreaterThan(0.0, DistrictMap::WINDOW_LIT_SHARE_FLOOR);
        $this->assertGreaterThan(DistrictMap::WINDOW_LIT_SHARE_FLOOR, DistrictMap::WINDOW_LIT_SHARE_AT_BASELINE);
        $this->assertLessThan(1.0, DistrictMap::WINDOW_LIT_SHARE_AT_BASELINE, 'A tenant beating its baseline must have windows left to light');
    }

    public function testWindowTwinkleParametersAreWellFormed(): void
    {
        $this->assertGreaterThan(0.0, DistrictMap::WINDOW_TWINKLE_SHARE);
        $this->assertLessThan(0.5, DistrictMap::WINDOW_TWINKLE_SHARE, 'Twinkling windows are meant to be a minority');
        $this->assertGreaterThan(0.0, DistrictMap::WINDOW_TWINKLE_PERIOD_SECONDS);
        $this->assertGreaterThanOrEqual(3, DistrictMap::WINDOW_KEY_PRECISION, 'Keys need enough precision to place a window on either side of any lit share');
    }

    // --- Roof furniture ---

    /**
     * The same coverage rule as FRONTAGE_ORDER: a business model with no furniture would render
     * the defensive default, which is a silent mis-dressing rather than an error.
     */
    public function testEveryFrontageModelHasRoofFurniture(): void
    {
        foreach (DistrictMap::FRONTAGE_ORDER as $businessModel) {
            $this->assertArrayHasKey($businessModel, DistrictMap::ROOF_FURNITURE, sprintf('%s has no roof furniture', $businessModel));
        }

        foreach (DistrictMap::ROOF_FURNITURE as $businessModel => $symbol) {
            $this->assertContains($symbol, DistrictMap::ROOF_FURNITURE_SYMBOLS, sprintf('%s names an undefined symbol "%s"', $businessModel, $symbol));
        }
        foreach (DistrictMap::ROOF_FURNITURE_BY_INDUSTRY as $industry => $symbol) {
            $this->assertArrayHasKey($industry, Sectors::INDUSTRY_METRICS, sprintf('"%s" is not an industry the market knows', $industry));
            $this->assertContains($symbol, DistrictMap::ROOF_FURNITURE_SYMBOLS, sprintf('%s names an undefined symbol "%s"', $industry, $symbol));
        }
        $this->assertContains(DistrictMap::ROOF_FURNITURE_DEFAULT, DistrictMap::ROOF_FURNITURE_SYMBOLS);
        $this->assertSame(DistrictMap::ROOF_FURNITURE_SYMBOLS, array_values(array_unique(DistrictMap::ROOF_FURNITURE_SYMBOLS)));

        // Every symbol is worn by someone — an orphan glyph is authoring drift.
        $worn = array_unique(array_merge(array_values(DistrictMap::ROOF_FURNITURE), array_values(DistrictMap::ROOF_FURNITURE_BY_INDUSTRY)));
        foreach (DistrictMap::ROOF_FURNITURE_SYMBOLS as $symbol) {
            $this->assertContains($symbol, $worn, sprintf('No tenant wears the %s symbol', $symbol));
        }
    }

    /** The oil trades split by industry: exploration raises a derrick, refining and midstream a flare stack. */
    public function testIndustryFurnitureRefinesTheModelsGlyph(): void
    {
        $this->assertSame('derrick', DistrictMap::ROOF_FURNITURE_BY_INDUSTRY['Oil & Gas E&P']);
        $this->assertSame('flare_stack', DistrictMap::ROOF_FURNITURE_BY_INDUSTRY['Oil & Gas Refining & Marketing']);
        $this->assertSame('ingot', DistrictMap::ROOF_FURNITURE_BY_INDUSTRY['Copper']);
        $this->assertSame(DistrictMap::ROOF_FURNITURE['commodity'], 'derrick', 'The model fallback still dresses a commodity house the map never singled out');
    }

    /** The template must define a `<symbol id="roof-…">` for every symbol the map can name. */
    public function testTheStreetTemplateDefinesEveryRoofFurnitureSymbol(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../templates/district/index.html.twig');

        foreach (DistrictMap::ROOF_FURNITURE_SYMBOLS as $symbol) {
            $this->assertStringContainsString(sprintf('<symbol id="roof-%s"', $symbol), $template, sprintf('The template defines no roof-%s symbol', $symbol));
        }
    }

    public function testRoofFurnitureFitsBetweenTheRankPlateAndTheBadgeOnTheNarrowestRoof(): void
    {
        $narrowest = min(DistrictMap::PLOT_WIDTH_BY_IMPORTANCE);
        $furnitureWest = $narrowest - DistrictMap::ROOF_FURNITURE_EAST_MARGIN - DistrictMap::ROOF_FURNITURE_WIDTH;

        // "#30" at 24px in a 0.6em face is ~43 units from x+4.
        $this->assertGreaterThanOrEqual(47, $furnitureWest, 'Furniture overlaps the rank plate on a base plot');
        $this->assertGreaterThan(DistrictMap::ROOF_FURNITURE_WIDTH, DistrictMap::ROOF_FURNITURE_HEIGHT, 'The symbols are drawn taller than wide');
        // The badge circle is r=15 centred 6 in from the east edge.
        $this->assertGreaterThanOrEqual(21, DistrictMap::ROOF_FURNITURE_EAST_MARGIN, 'Furniture overlaps the event badge');
    }

    // --- Kerb lights ---

    public function testKerbLightsStayInsideTheKerbBand(): void
    {
        $this->assertGreaterThan(0, DistrictMap::KERB_LIGHT_DEPTH);
        $this->assertLessThanOrEqual(DistrictMap::KERB_DEPTH, DistrictMap::KERB_LIGHT_DEPTH);
        $this->assertGreaterThan(0.0, DistrictMap::KERB_LIGHT_OPACITY);
        $this->assertLessThanOrEqual(1.0, DistrictMap::KERB_LIGHT_OPACITY);
    }

    public function testTerraceWallAndPositionPennantParametersAreWellFormed(): void
    {
        $this->assertGreaterThan(0, DistrictMap::TERRACE_WALL_HEIGHT);
        $this->assertLessThan(DistrictMap::ROW_GAP, DistrictMap::TERRACE_WALL_HEIGHT);

        $this->assertGreaterThan(0, DistrictMap::POSITION_PENNANT_WIDTH);
        $this->assertGreaterThan(0, DistrictMap::POSITION_PENNANT_HEIGHT);
        $this->assertGreaterThan(0, DistrictMap::POSITION_PENNANT_INSET);
        // The flag must stay inside the narrowest plot so it never overhangs the neighbour.
        $this->assertLessThan(min(DistrictMap::PLOT_WIDTH_BY_IMPORTANCE), DistrictMap::POSITION_PENNANT_INSET + DistrictMap::POSITION_PENNANT_WIDTH);
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

    /**
     * The height window is fitted per request, so what the cartography pins is the shape of the
     * fit: headroom that actually pads, a minimum span that is a real span, and an empty-street
     * floor that lands in the capitalisations the street is built for.
     */
    public function testHeightWindowParametersAreWellFormed(): void
    {
        $this->assertGreaterThan(0.0, DistrictMap::MARKET_CAP_LOG_HEADROOM);
        $this->assertLessThan(1.0, DistrictMap::MARKET_CAP_LOG_HEADROOM, 'More than a decade of headroom would waste most of the envelope');
        $this->assertGreaterThan(2.0 * DistrictMap::MARKET_CAP_LOG_HEADROOM, DistrictMap::MARKET_CAP_LOG_MIN_SPAN);
        $this->assertGreaterThanOrEqual(9.0, DistrictMap::MARKET_CAP_LOG_EMPTY_FLOOR);
    }

    public function testGridlineMantissasAreAscendingWithinOneDecade(): void
    {
        $this->assertNotEmpty(DistrictMap::GRIDLINE_MANTISSAS);
        $previous = 0.0;
        foreach (DistrictMap::GRIDLINE_MANTISSAS as $mantissa) {
            $this->assertGreaterThan($previous, $mantissa);
            $this->assertLessThan(10.0, $mantissa);
            $previous = $mantissa;
        }
        $this->assertSame(1.0, DistrictMap::GRIDLINE_MANTISSAS[0], 'Every decade must start on its round figure');
        $this->assertGreaterThan((float) DistrictMap::GRIDLINE_LABEL_SIZE, DistrictMap::GRIDLINE_MIN_SPACING);
    }

    public function testEventBadgeWindowIsAFractionOfAYear(): void
    {
        $this->assertGreaterThan(0.0, DistrictMap::EVENT_BADGE_WINDOW_YEARS);
        $this->assertLessThanOrEqual(1.0, DistrictMap::EVENT_BADGE_WINDOW_YEARS);
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

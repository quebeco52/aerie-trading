<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Data\DistrictMap;
use App\Data\Sectors;
use App\DTO\DistrictCanvasDTO;
use App\DTO\DistrictHeightEnvelope;
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

    /**
     * The full derivation for a street, in the order the controller runs it.
     *
     * @param  Stock[] $stocks
     * @return array{frontage: array<string, mixed>, envelope: DistrictHeightEnvelope, canvas: DistrictCanvasDTO, plots: list<DistrictPlotDTO>}
     */
    private function compose(array $stocks): array
    {
        $frontage = $this->composer->composeFrontage($stocks);
        $envelope = $this->builder->resolveEnvelope($frontage['slots'], $stocks);
        $canvas = $this->builder->resolveCanvas($frontage['slots'], $stocks, $envelope, $frontage['rowCount']);

        return [
            'frontage' => $frontage,
            'envelope' => $envelope,
            'canvas' => $canvas,
            'plots' => $this->builder->buildWard($frontage['slots'], $stocks, $envelope, $canvas),
        ];
    }

    /** @param Stock[] $stocks */
    private function buildPlots(array $stocks): array
    {
        return $this->compose($stocks)['plots'];
    }

    /** A one-row canvas with sky enough for the full envelope, for gridline tests that need no street. */
    private function canvasFor(): DistrictCanvasDTO
    {
        $groundLine = 320.0 + DistrictMap::ROW_GAP + DistrictMap::MAX_FACADE_HEIGHT;

        return new DistrictCanvasDTO([$groundLine], [], 320.0, $groundLine + DistrictMap::KERB_DEPTH + DistrictMap::CANVAS_BOTTOM_MARGIN);
    }

    /** Reads "$500B" / "$2T" back into the capitalisation it labels. */
    private static function capFromLabel(string $label): float
    {
        $units = ['T' => 1.0e12, 'B' => 1.0e9, 'M' => 1.0e6];

        return (float) substr($label, 1, -1) * $units[substr($label, -1)];
    }

    /**
     * Enough tenants of one model to wrap onto two rows — DistrictMap::ROW_SPLIT_THRESHOLD — with
     * caps stepping down from $10T so the two rows have different tallest facades.
     *
     * @return Stock[]
     */
    private function fullStreet(): array
    {
        $stocks = [];
        for ($i = 0; $i < DistrictMap::ROW_SPLIT_THRESHOLD + 2; $i++) {
            $stocks[] = $this->makeStock(sprintf('T%02d', $i), price: 100.0, shares: 1.0e11 / (1 + $i));
        }

        return $stocks;
    }

    /** @param Stock[] $stocks */
    private function resolveEnvelope(array $stocks): DistrictHeightEnvelope
    {
        $frontage = $this->composer->composeFrontage($stocks);

        return $this->builder->resolveEnvelope($frontage['slots'], $stocks);
    }

    /** A street spanning $100B to $10T, with a window fitted to exactly that. */
    private function referenceEnvelope(): DistrictHeightEnvelope
    {
        return $this->resolveEnvelope([
            $this->makeStock('LAKE', price: 100.0, shares: 1.0e11),  // $10T
            $this->makeStock('SWAN', price: 100.0, shares: 1.0e10),  // $1T
            $this->makeStock('RIVR', price: 100.0, shares: 1.0e9),   // $100B
        ]);
    }

    public function testEnvelopeIsFittedToTheRosterWithHeadroomOnBothSides(): void
    {
        $envelope = $this->referenceEnvelope();

        $this->assertEqualsWithDelta(11.0 - DistrictMap::MARKET_CAP_LOG_HEADROOM, $envelope->logFloor, 1.0e-9);
        $this->assertEqualsWithDelta(13.0 + DistrictMap::MARKET_CAP_LOG_HEADROOM, $envelope->logCeiling, 1.0e-9);
    }

    /**
     * The whole reason the window is derived: with a fixed window centred on one figure, ranks
     * 12, 22 and 24 of a real roster once rendered at the same height. Fitted to the roster, the
     * smallest and largest tenants sit near the envelope ends and everything between reads apart.
     */
    public function testSmallestAndLargestTenantsUseTheWholeEnvelope(): void
    {
        $envelope = $this->referenceEnvelope();
        $smallest = $this->builder->calculateFacadeHeight(1.0e11, $envelope);
        $largest = $this->builder->calculateFacadeHeight(1.0e13, $envelope);

        $span = DistrictMap::MAX_FACADE_HEIGHT - DistrictMap::MIN_FACADE_HEIGHT;
        $headroomShare = DistrictMap::MARKET_CAP_LOG_HEADROOM / $envelope->span();

        $this->assertEqualsWithDelta(DistrictMap::MIN_FACADE_HEIGHT + $headroomShare * $span, $smallest, 1.0e-6);
        $this->assertEqualsWithDelta(DistrictMap::MAX_FACADE_HEIGHT - $headroomShare * $span, $largest, 1.0e-6);
    }

    /** "Log scale" has to mean it: equal ratios of capitalisation are equal distances of height. */
    public function testFacadeHeightIsLinearInLogCap(): void
    {
        $envelope = $this->referenceEnvelope();

        $tenBillionStep = $this->builder->calculateFacadeHeight(1.0e12, $envelope) - $this->builder->calculateFacadeHeight(1.0e11, $envelope);
        $trillionStep = $this->builder->calculateFacadeHeight(1.0e13, $envelope) - $this->builder->calculateFacadeHeight(1.0e12, $envelope);
        $doubling = $this->builder->calculateFacadeHeight(4.0e11, $envelope) - $this->builder->calculateFacadeHeight(2.0e11, $envelope);

        $this->assertEqualsWithDelta($tenBillionStep, $trillionStep, 1.0e-6);
        $this->assertEqualsWithDelta($tenBillionStep * log10(2.0), $doubling, 1.0e-6);
    }

    public function testFacadeHeightIsClampedOutsideTheEnvelope(): void
    {
        $envelope = $this->referenceEnvelope();

        $this->assertSame(DistrictMap::MIN_FACADE_HEIGHT, $this->builder->calculateFacadeHeight(1.0, $envelope));
        $this->assertSame(DistrictMap::MIN_FACADE_HEIGHT, $this->builder->calculateFacadeHeight(0.0, $envelope));
        $this->assertSame(DistrictMap::MAX_FACADE_HEIGHT, $this->builder->calculateFacadeHeight(1.0e18, $envelope));
    }

    public function testFacadeHeightRisesMonotonicallyWithMarketCap(): void
    {
        $envelope = $this->referenceEnvelope();
        $small = $this->builder->calculateFacadeHeight(1.0e11, $envelope);
        $medium = $this->builder->calculateFacadeHeight(1.0e12, $envelope);
        $large = $this->builder->calculateFacadeHeight(5.0e12, $envelope);

        $this->assertGreaterThan($small, $medium);
        $this->assertGreaterThan($medium, $large);
    }

    /**
     * A single-tenant street, or one whose tenants all share a cap, has no range of its own;
     * widening to MARKET_CAP_LOG_MIN_SPAN around the midpoint keeps a fractional difference
     * from being blown up to the full envelope.
     */
    public function testANarrowRosterIsWidenedToTheMinimumSpanAroundItsMidpoint(): void
    {
        $envelope = $this->resolveEnvelope([$this->makeStock('LAKE', price: 100.0, shares: 1.0e10)]); // $1T

        $this->assertEqualsWithDelta(DistrictMap::MARKET_CAP_LOG_MIN_SPAN, $envelope->span(), 1.0e-9);
        $this->assertEqualsWithDelta(12.0, ($envelope->logFloor + $envelope->logCeiling) / 2.0, 1.0e-9);
    }

    public function testAnEmptyStreetStillCarriesAWindow(): void
    {
        $envelope = $this->resolveEnvelope([]);

        $this->assertSame(DistrictMap::MARKET_CAP_LOG_EMPTY_FLOOR, $envelope->logFloor);
        $this->assertEqualsWithDelta(DistrictMap::MARKET_CAP_LOG_MIN_SPAN, $envelope->span(), 1.0e-9);
    }

    /** A bankrupt tenant at zero has no log cap; it must not drag the floor to minus infinity. */
    public function testAZeroCapTenantDoesNotDistortTheEnvelope(): void
    {
        $withRuin = $this->resolveEnvelope([
            $this->makeStock('LAKE', price: 100.0, shares: 1.0e11),
            $this->makeStock('RIVR', price: 100.0, shares: 1.0e9),
            $this->makeStock('VULT', price: 0.0, shares: 1.0e9, rating: 'D', bankrupt: true),
        ]);
        $without = $this->resolveEnvelope([
            $this->makeStock('LAKE', price: 100.0, shares: 1.0e11),
            $this->makeStock('RIVR', price: 100.0, shares: 1.0e9),
        ]);

        $this->assertEqualsWithDelta($without->logFloor, $withRuin->logFloor, 1.0e-9);
        $this->assertEqualsWithDelta($without->logCeiling, $withRuin->logCeiling, 1.0e-9);
    }

    public function testGridlinesAreThe125StepsInsideTheEnvelope(): void
    {
        $envelope = $this->referenceEnvelope();
        $labels = array_map(
            static fn (array $line) => $line['label'],
            $this->builder->buildGridlines($envelope, $this->canvasFor()),
        );

        // Window is ~$71B..$14T: every 1-2-5 step in that range, and nothing outside it.
        $this->assertSame(['$100B', '$200B', '$500B', '$1T', '$2T', '$5T', '$10T'], $labels);
    }

    /**
     * A gridline crowded against its neighbour informs less than no gridline at all — the labels
     * are printed at GRIDLINE_LABEL_SIZE, so consecutive rules must clear that whatever the window.
     */
    public function testGridlinesOnARowNeverCrowdEachOther(): void
    {
        // A very wide window, so the 1-2-5 steps would crowd without thinning.
        $envelope = new DistrictHeightEnvelope(8.0, 15.0);

        $ys = [];
        foreach ($this->builder->buildGridlines($envelope, $this->canvasFor()) as $line) {
            $ys[] = $line['y'];
        }
        $this->assertGreaterThan(1, count($ys));
        sort($ys);

        for ($i = 1; $i < count($ys); $i++) {
            $this->assertGreaterThanOrEqual(
                DistrictMap::GRIDLINE_MIN_SPACING,
                $ys[$i] - $ys[$i - 1],
                'Consecutive gridline labels would overlap in the gutter'
            );
            $this->assertGreaterThan((float) DistrictMap::GRIDLINE_LABEL_SIZE, $ys[$i] - $ys[$i - 1]);
        }
    }

    public function testGridlinesAgreeWithTheFacadeCurveOnEveryRow(): void
    {
        ['envelope' => $envelope, 'canvas' => $canvas] = $this->compose($this->fullStreet());
        $gridlines = $this->builder->buildGridlines($envelope, $canvas);

        $this->assertSame(2, $canvas->rowCount());
        $this->assertNotEmpty(array_filter($gridlines, static fn (array $l) => $l['row'] === 1));

        foreach ($gridlines as $line) {
            $expected = $canvas->groundLineForRow($line['row']) - $this->builder->calculateFacadeHeight(self::capFromLabel($line['label']), $envelope);

            $this->assertEqualsWithDelta($expected, $line['y'], 0.0001);
        }
    }

    /** The lower row's sky is sized to its own skyline, so it carries fewer rules than the upper row. */
    public function testAShorterRowCarriesFewerGridlines(): void
    {
        ['envelope' => $envelope, 'canvas' => $canvas] = $this->compose($this->fullStreet());
        $gridlines = $this->builder->buildGridlines($envelope, $canvas);

        $upper = count(array_filter($gridlines, static fn (array $l) => $l['row'] === 0));
        $lower = count(array_filter($gridlines, static fn (array $l) => $l['row'] === 1));

        $this->assertGreaterThan($lower, $upper);
        $this->assertGreaterThan(0, $lower);
    }

    /** A rule taller than a row's own sky would run through the lane band or the kerb above it. */
    public function testGridlinesNeverRiseAboveTheirRowsSky(): void
    {
        ['envelope' => $envelope, 'canvas' => $canvas] = $this->compose($this->fullStreet());

        $skyTop = [0 => $canvas->laneBandBottom, 1 => $canvas->rowGroundLines[0] + DistrictMap::KERB_DEPTH];
        foreach ($this->builder->buildGridlines($envelope, $canvas) as $line) {
            $this->assertGreaterThanOrEqual($skyTop[$line['row']] + DistrictMap::GRIDLINE_LABEL_SIZE, $line['y']);
        }
    }

    public function testFacadesStandOnTheirOwnRowsGroundLine(): void
    {
        ['canvas' => $canvas, 'plots' => $plots] = $this->compose($this->fullStreet());

        $this->assertNotEmpty($plots);
        $this->assertSame(2, $canvas->rowCount());
        foreach ($plots as $plot) {
            $this->assertEqualsWithDelta($plot->groundLine, $plot->y + $plot->height, 0.0001);
            $this->assertSame($canvas->groundLineForRow($plot->row), $plot->groundLine);
            $this->assertGreaterThanOrEqual(0.0, $plot->y);
        }
    }

    // --- Canvas ---

    /**
     * The rule in DistrictMap::ROW_GAP: each row gets the sky its own tallest facade needs plus a
     * headroom-sized move, not the theoretical maximum — which left the lower row's sky empty.
     */
    public function testEachRowIsGivenItsOwnTallestFacadePlusHeadroom(): void
    {
        ['envelope' => $envelope, 'canvas' => $canvas, 'plots' => $plots] = $this->compose($this->fullStreet());

        $headroomHeight = DistrictMap::MARKET_CAP_LOG_HEADROOM / $envelope->span()
            * (DistrictMap::MAX_FACADE_HEIGHT - DistrictMap::MIN_FACADE_HEIGHT);

        $tallest = [0 => 0.0, 1 => 0.0];
        foreach ($plots as $plot) {
            $tallest[$plot->row] = max($tallest[$plot->row], $plot->height);
        }
        $this->assertNotSame($tallest[0], $tallest[1], 'The fixture must give the rows different skylines');

        $expectedUpper = $canvas->laneBandBottom + DistrictMap::ROW_GAP + min(DistrictMap::MAX_FACADE_HEIGHT, $tallest[0] + $headroomHeight);
        $expectedLower = $expectedUpper + DistrictMap::KERB_DEPTH + DistrictMap::ROW_GAP + min(DistrictMap::MAX_FACADE_HEIGHT, $tallest[1] + $headroomHeight);

        $this->assertEqualsWithDelta($expectedUpper, $canvas->rowGroundLines[0], 1.0e-6);
        $this->assertEqualsWithDelta($expectedLower, $canvas->rowGroundLines[1], 1.0e-6);
        $this->assertEqualsWithDelta($expectedLower + DistrictMap::KERB_DEPTH + DistrictMap::CANVAS_BOTTOM_MARGIN, $canvas->viewboxHeight, 1.0e-6);
    }

    /** The largest tenant sits exactly one headroom below the ceiling, so its row is given the full envelope. */
    public function testTheRowHoldingTheLargestTenantClearsTheFullEnvelope(): void
    {
        ['canvas' => $canvas, 'plots' => $plots] = $this->compose($this->fullStreet());

        $largest = array_reduce($plots, static fn (?DistrictPlotDTO $carry, DistrictPlotDTO $p) => $carry === null || $p->height > $carry->height ? $p : $carry);
        $this->assertNotNull($largest);

        $skyTop = $largest->row === 0 ? $canvas->laneBandBottom : $canvas->rowGroundLines[$largest->row - 1] + DistrictMap::KERB_DEPTH;

        $this->assertEqualsWithDelta(
            $skyTop + DistrictMap::ROW_GAP + DistrictMap::MAX_FACADE_HEIGHT,
            $canvas->rowGroundLines[$largest->row],
            1.0e-6,
        );
    }

    public function testEveryFacadeAndItsRoofFurnitureClearsTheSkyAboveIt(): void
    {
        ['canvas' => $canvas, 'plots' => $plots] = $this->compose($this->fullStreet());

        foreach ($plots as $plot) {
            $skyTop = $plot->row === 0 ? $canvas->laneBandBottom : $canvas->rowGroundLines[$plot->row - 1] + DistrictMap::KERB_DEPTH;
            // The roof furniture, on the 7-unit roofline, is the highest thing that rides it.
            $this->assertGreaterThanOrEqual($skyTop, $plot->y - 7 - DistrictMap::ROOF_FURNITURE_HEIGHT, sprintf('%s pokes into the sky above its row', $plot->ticker));
        }
    }

    public function testEachWiredInstitutionOwnsOneLaneBelowTheOutlets(): void
    {
        ['canvas' => $canvas, 'plots' => $plots] = $this->compose([$this->makeStock('LAKE')]);

        $wired = [];
        foreach ($plots as $plot) {
            foreach ($plot->conduits as $id) {
                $wired[$id] = true;
            }
        }
        $this->assertSame(array_keys($wired), array_keys($canvas->laneYByInstitution));

        $previous = DistrictMap::INSTITUTION_OUTLET_Y;
        foreach ($canvas->laneYByInstitution as $laneY) {
            $this->assertGreaterThan($previous, $laneY);
            $previous = $laneY;
        }
        $this->assertEqualsWithDelta($previous + DistrictMap::CONDUIT_LANE_PITCH, $canvas->laneBandBottom, 1.0e-6);
    }

    public function testAnEmptyStreetStillHasARowAndAKerb(): void
    {
        $envelope = $this->builder->resolveEnvelope([], []);
        $canvas = $this->builder->resolveCanvas([], [], $envelope, 1);

        $this->assertSame(1, $canvas->rowCount());
        $this->assertSame([], $canvas->laneYByInstitution);
        $this->assertEqualsWithDelta(
            DistrictMap::INSTITUTION_OUTLET_Y + DistrictMap::CONDUIT_LANE_TOP_INSET + DistrictMap::ROW_GAP + DistrictMap::MIN_FACADE_HEIGHT + DistrictMap::MARKET_CAP_LOG_HEADROOM / $envelope->span() * (DistrictMap::MAX_FACADE_HEIGHT - DistrictMap::MIN_FACADE_HEIGHT),
            $canvas->rowGroundLines[0],
            1.0e-6,
        );
    }

    // --- Conduit drops ---

    public function testATenantsDropsAreSpreadAcrossItsRoofInInstitutionOrder(): void
    {
        $plot = $this->findPlot('LAKE', [$this->makeStock('LAKE', importance: 'titan')]);

        $this->assertGreaterThan(1, count($plot->conduits), 'A bank draws several conduits');
        $this->assertSame($plot->conduits, array_keys($plot->conduitDropX));

        $previous = $plot->x + DistrictMap::CONDUIT_DROP_INSET;
        foreach ($plot->conduitDropX as $x) {
            $this->assertGreaterThan($previous, $x);
            $this->assertLessThan($plot->x + $plot->width - DistrictMap::CONDUIT_DROP_INSET, $x);
            $previous = $x;
        }
    }

    public function testInstitutionsCarryTheLaneTheCanvasReservedForThem(): void
    {
        ['frontage' => $frontage, 'canvas' => $canvas, 'plots' => $plots] = $this->compose([$this->makeStock('LAKE')]);
        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);

        foreach ($this->builder->buildInstitutions($plots, $viewboxWidth, $canvas) as $institution) {
            $this->assertSame($canvas->laneYByInstitution[$institution->id], $institution->laneY);
        }
    }

    // --- Windows ---

    public function testLitShareIsLinearInTheReturnRatioAndClamped(): void
    {
        $floor = DistrictMap::WINDOW_LIT_SHARE_FLOOR;
        $atBaseline = DistrictMap::WINDOW_LIT_SHARE_AT_BASELINE;

        $this->assertEqualsWithDelta($atBaseline, $this->builder->calculateLitShare(0.15, 0.15), 1.0e-9);
        $this->assertEqualsWithDelta($floor + ($atBaseline - $floor) * 0.5, $this->builder->calculateLitShare(0.075, 0.15), 1.0e-9);
        $this->assertEqualsWithDelta(1.0, $this->builder->calculateLitShare(0.30, 0.15), 1.0e-9, 'Twice the baseline lights every window');
        $this->assertEqualsWithDelta(1.0, $this->builder->calculateLitShare(0.90, 0.15), 1.0e-9, 'Beyond that it clamps');
        $this->assertEqualsWithDelta($floor, $this->builder->calculateLitShare(-0.10, 0.15), 1.0e-9, 'A loss-maker keeps the floor lit');
        $this->assertEqualsWithDelta($atBaseline, $this->builder->calculateLitShare(0.12, 0.0), 1.0e-9, 'No baseline on file reads as at-baseline, not a division by zero');
    }

    public function testWindowLightingIsDeterministicAndMonotoneInTheShare(): void
    {
        $keys = $this->builder->windowKeys('LAKE', 6, 4);
        $dim = $this->builder->lightWindows($keys, 0.3);
        $again = $this->builder->lightWindows($this->builder->windowKeys('LAKE', 6, 4), 0.3);
        $bright = $this->builder->lightWindows($keys, 0.8);

        $this->assertSame($dim, $again, 'The same building must light the same windows on every render');
        $this->assertCount(6, $dim);
        $this->assertCount(4, $dim[0]);

        $litDim = 0;
        $litBright = 0;
        for ($floor = 0; $floor < 6; $floor++) {
            for ($col = 0; $col < 4; $col++) {
                $litDim += (int) $dim[$floor][$col];
                $litBright += (int) $bright[$floor][$col];
                if ($dim[$floor][$col]) {
                    $this->assertTrue($bright[$floor][$col], 'Raising the share only ever adds lit windows');
                }
            }
        }
        $this->assertGreaterThan($litDim, $litBright);
        $this->assertNotSame($this->builder->lightWindows($this->builder->windowKeys('SWAN', 6, 4), 0.3), $dim, 'Different buildings light differently');
    }

    public function testLitShareTracksTheShareOfWindowsActuallyLitAcrossTheStreet(): void
    {
        // Over many windows the hash scatter converges on the share it was drawn from.
        $lit = $this->builder->lightWindows($this->builder->windowKeys('LAKE', 200, 50), 0.6);
        $count = array_sum(array_map(static fn (array $row) => array_sum(array_map('intval', $row)), $lit));

        $this->assertEqualsWithDelta(0.6, $count / 10000, 0.03);
    }

    /**
     * The keys ship to the client, which compares them against a live lit share with the same
     * `<`; both sides must therefore hold the very same rounded figure — see
     * DistrictMap::WINDOW_KEY_PRECISION.
     */
    public function testWindowKeysAreUnitIntervalDrawsRoundedToTheShippedPrecision(): void
    {
        foreach ($this->builder->windowKeys('LAKE', 40, 5) as $row) {
            foreach ($row as $key) {
                $this->assertGreaterThanOrEqual(0.0, $key);
                $this->assertLessThan(1.0, $key);
                $this->assertSame(round($key, DistrictMap::WINDOW_KEY_PRECISION), $key);
            }
        }
    }

    public function testTwinklePhasesPickAStableMinorityIndependentOfTheLighting(): void
    {
        $phases = $this->builder->twinklePhases('LAKE', 200, 50);
        $this->assertSame($phases, $this->builder->twinklePhases('LAKE', 200, 50), 'The same windows must flicker on every render');

        $keys = $this->builder->windowKeys('LAKE', 200, 50);
        $twinklers = 0;
        $twinklersLitLate = 0;
        foreach ($phases as $floor => $row) {
            foreach ($row as $column => $phase) {
                if ($phase === null) {
                    continue;
                }
                $twinklers++;
                $this->assertGreaterThanOrEqual(0.0, $phase);
                $this->assertLessThan(1.0, $phase);
                if ($keys[$floor][$column] > DistrictMap::WINDOW_TWINKLE_SHARE) {
                    $twinklersLitLate++;
                }
            }
        }

        $this->assertEqualsWithDelta(DistrictMap::WINDOW_TWINKLE_SHARE, $twinklers / 10000, 0.02);
        $this->assertGreaterThan(0, $twinklersLitLate, 'Flicker must not be the same draw as lighting priority, or only the first-lit windows would ever flicker');
    }

    public function testPlotsCarryTheirWindowGridAndLighting(): void
    {
        $stock = $this->makeStock('LAKE', importance: 'titan');
        $stock->setCurrentRoe('0.30');
        $stock->setBaselineRoe('0.15');
        $plot = $this->findPlot('LAKE', [$stock]);

        $this->assertSame(intdiv(190 - DistrictMap::WINDOW_WALL_ALLOWANCE, DistrictMap::WINDOW_PITCH), $plot->windowColumns);
        $this->assertCount($plot->floors, $plot->litWindows);
        $this->assertCount($plot->windowColumns, $plot->litWindows[0]);
        $this->assertEqualsWithDelta(1.0, $plot->litShare, 1.0e-9, 'Twice the baseline lights every window');
        $this->assertEqualsWithDelta(0.30, $plot->returnOnCapital, 1.0e-9);
        $this->assertEqualsWithDelta(0.15, $plot->baselineReturnOnCapital, 1.0e-9);
        foreach ($plot->litWindows as $row) {
            $this->assertNotContains(false, $row);
        }
        $this->assertSame($plot->litWindows, $this->builder->lightWindows($plot->windowKeys, $plot->litShare), 'The shipped keys must reproduce the lighting');
        $this->assertCount($plot->floors, $plot->twinklePhases);
        $this->assertCount($plot->windowColumns, $plot->twinklePhases[0]);
        $this->assertSame(DistrictMapBuilder::RETURN_FIELD_FINANCIAL, $plot->returnField, 'A bank lights from ROE on a live tick');
        $this->assertSame(DistrictMap::ROOF_FURNITURE['commercial_bank'], $plot->roofFurniture);
        $this->assertSame('coin', $plot->roofFurniture);
    }

    public function testNonFinancialTenantsLightFromRoicAndDressTheirOwnRoof(): void
    {
        $stock = $this->makeStock('STEL');
        $stock->setIndustry('Steel');
        $stock->setBaselineRoic('0.10');
        $stock->setCurrentRoic('0.05');
        $plot = $this->findPlot('STEL', [$stock]);

        $this->assertSame(DistrictMapBuilder::RETURN_FIELD_OPERATING, $plot->returnField);
        $this->assertSame(DistrictMap::ROOF_FURNITURE['steel_manufacturing'], $plot->roofFurniture);
        $this->assertEqualsWithDelta($this->builder->calculateLitShare(0.05, 0.10), $plot->litShare, 1.0e-9);
    }

    public function testRoofFurnitureIsResolvedByIndustryBeforeBusinessModel(): void
    {
        $this->assertSame('derrick', $this->builder->roofFurnitureFor('Oil & Gas E&P', 'commodity'));
        $this->assertSame('flare_stack', $this->builder->roofFurnitureFor('Oil & Gas Refining & Marketing', 'commodity'));
        $this->assertSame('coin', $this->builder->roofFurnitureFor('Banks - Regional', 'commercial_bank'), 'An industry with no entry of its own falls back to its model');
        $this->assertSame(DistrictMap::ROOF_FURNITURE_DEFAULT, $this->builder->roofFurnitureFor('General', 'none'));

        $refiner = $this->makeStock('REFN');
        $refiner->setIndustry('Oil & Gas Refining & Marketing');
        $refiner->setBaselineRoic('0.10');
        $this->assertSame('flare_stack', $this->findPlot('REFN', [$refiner])->roofFurniture);
    }

    // --- Sector runs ---

    public function testAdjacentSameSectorPlotsFormOneRunPerRow(): void
    {
        $stocks = $this->fullStreet();
        $stocks[3]->setSector('Information Technology');
        $stocks[4]->setSector('Information Technology');
        ['plots' => $plots] = $this->compose($stocks);

        $runs = $this->builder->buildSectorRuns($plots);

        // Each run spans exactly its plots, never crosses a row, and covers every plot once.
        $covered = 0;
        foreach ($runs as $run) {
            $members = array_filter($plots, static fn (DistrictPlotDTO $p) => $p->row === $run['row'] && $p->x >= $run['x'] && $p->x + $p->width <= $run['x'] + $run['width']);
            $this->assertNotEmpty($members);
            foreach ($members as $member) {
                $this->assertSame($run['sector'], $member->sector);
            }
            $covered += count($members);
        }
        $this->assertSame(count($plots), $covered);

        $sectors = array_map(static fn (array $r) => $r['sector'], $runs);
        $this->assertContains('Information Technology', $sectors);
        $this->assertGreaterThanOrEqual(3, count($runs), 'Financials, then IT, then Financials again at least');
    }

    public function testARunCarriesItsNameOnlyWhereItIsWideEnough(): void
    {
        $narrow = $this->buildPlots([$this->makeStock('LAKE')]);          // one 100-unit plot
        $wide = $this->buildPlots(array_slice($this->fullStreet(), 0, 4)); // four plots on one row

        $narrowRuns = $this->builder->buildSectorRuns($narrow);
        $wideRuns = $this->builder->buildSectorRuns($wide);

        $this->assertNull($narrowRuns[0]['label'], '"Financials" at 20 units a glyph does not fit a 100-unit plot');
        $this->assertSame('Financials', $wideRuns[0]['label']);
    }

    public function testTheSameCapSitsAtADifferentHeightOnEachRow(): void
    {
        ['envelope' => $envelope, 'canvas' => $canvas] = $this->compose($this->fullStreet());
        $gridlines = $this->builder->buildGridlines($envelope, $canvas);

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
        ['frontage' => $frontage, 'plots' => $plots] = $this->compose($stocks);

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
        ['frontage' => $frontage, 'envelope' => $envelope, 'canvas' => $canvas] = $this->compose($stocks);

        $withChange = $this->builder->buildWard($frontage['slots'], $stocks, $envelope, $canvas, ['LAKE' => 0.0125]);
        $this->assertEqualsWithDelta(0.0125, $withChange[0]->changePercent, 0.0001);

        $withoutChange = $this->builder->buildWard($frontage['slots'], $stocks, $envelope, $canvas);
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
        // A bankrupt company never qualifies at a reconstitution; it stands on the street only
        // because the roster was frozen while it was solvent, so it is composed via that path.
        $stocks = [$this->makeStock('LAKE', price: 0.0, rating: 'D', bankrupt: true)];
        $frontage = $this->composer->composeFrontageForRoster($stocks, ['LAKE']);
        $envelope = $this->builder->resolveEnvelope($frontage['slots'], $stocks);
        $canvas = $this->builder->resolveCanvas($frontage['slots'], $stocks, $envelope, $frontage['rowCount']);
        $plots = $this->builder->buildWard($frontage['slots'], $stocks, $envelope, $canvas);
        $this->assertCount(1, $plots);
        $plot = $plots[0];

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
        ['frontage' => $frontage, 'canvas' => $canvas, 'plots' => $plots] = $this->compose($stocks);
        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);

        $institutions = $this->builder->buildInstitutions($plots, $viewboxWidth, $canvas);

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
        ['frontage' => $frontage, 'canvas' => $canvas, 'plots' => $plots] = $this->compose($stocks);
        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);
        $institutions = $this->builder->buildInstitutions($plots, $viewboxWidth, $canvas);

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
        ['frontage' => $frontage, 'canvas' => $canvas, 'plots' => $plots] = $this->compose($stocks);

        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);
        $institutions = $this->builder->buildInstitutions($plots, $viewboxWidth, $canvas);

        $this->assertGreaterThanOrEqual($frontage['viewboxWidth'], $viewboxWidth);
        foreach ($institutions as $institution) {
            $this->assertLessThanOrEqual($viewboxWidth, $institution->x + $institution->width);
        }
    }

    public function testNoOccupiedPlotsYieldsNoInstitutionsAndNoWidening(): void
    {
        ['frontage' => $frontage, 'canvas' => $canvas, 'plots' => $plots] = $this->compose([]);

        $viewboxWidth = $this->builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);

        $this->assertSame($frontage['viewboxWidth'], $viewboxWidth);
        $this->assertSame([], $this->builder->buildInstitutions($plots, $viewboxWidth, $canvas));
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

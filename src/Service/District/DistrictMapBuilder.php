<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\Data\Sectors;
use App\Data\StockInfo;
use App\DTO\DistrictCanvasDTO;
use App\DTO\DistrictHeightEnvelope;
use App\DTO\DistrictInstitutionDTO;
use App\DTO\DistrictPlotDTO;
use App\Entity\Stock;
use App\Service\Market\CreditRatingAgency;

/**
 * Builds the render state for the district street elevation from live company fundamentals.
 *
 * Every transform here is presentation-only: facade heights are a log-scale display mapping,
 * not a financial model, which is why they are deliberately kept out of MathUtility. No value
 * produced by this service may ever be read back into the simulation.
 *
 * Call order for one request: resolveEnvelope() → resolveCanvas() → buildWard() →
 * resolveViewboxWidth() → buildInstitutions() / buildGridlines() / buildDistrictRuns(). Each step
 * only needs what the previous ones returned.
 */
class DistrictMapBuilder
{
    // --- Live Payload Fields ---
    /** Field of a live stock tick carrying return on equity — what lights a financial's windows (StockTracker publishes it). */
    public const RETURN_FIELD_FINANCIAL = 'current_roe';
    /** Field of a live stock tick carrying return on invested capital — what lights every other tenant's windows. */
    public const RETURN_FIELD_OPERATING = 'current_roic';

    public function __construct(
        private readonly DistrictConduitResolver $conduitResolver,
    ) {
    }

    /**
     * Assembles the ordered plot list for the street, pairing composed geometry with live stocks.
     * Draw it against the same envelope and canvas resolved for these slots, or the gridlines,
     * the lanes and the client's live resize will disagree with the facades.
     *
     * @param  list<array{ticker: string, x: int, width: int, row: int, rank: int}> $slots
     * @param  Stock[]                                                              $stocks
     * @param  array<string, float>                                                 $changeByTicker fractional price change per ticker
     * @return list<DistrictPlotDTO>
     */
    public function buildWard(array $slots, array $stocks, DistrictHeightEnvelope $envelope, DistrictCanvasDTO $canvas, array $changeByTicker = []): array
    {
        $plots = [];
        foreach ($this->occupiedSlots($slots, $stocks) as $i => [$slot, $stock]) {
            $plots[] = $this->buildOccupiedPlot(
                sprintf('%s-%02d', DistrictMap::WARD_SLUG, $i + 1),
                $slot,
                $stock,
                $envelope,
                $canvas,
                $changeByTicker[$slot['ticker']] ?? null,
            );
        }

        return $plots;
    }

    /**
     * Derives the height window from the tenants actually holding frontage: the roster's own
     * log10 cap range, padded by DistrictMap::MARKET_CAP_LOG_HEADROOM on both sides and widened
     * to DistrictMap::MARKET_CAP_LOG_MIN_SPAN around its midpoint if narrower than that.
     *
     * A window fitted to the roster is what lets the whole street differentiate: a fixed window
     * has to bracket every roster a long-running market might produce, which parks whichever end
     * the real roster occupies in a compressed tail.
     *
     * @param list<array{ticker: string, x: int, width: int, row: int, rank: int}> $slots
     * @param Stock[]                                                              $stocks
     */
    public function resolveEnvelope(array $slots, array $stocks): DistrictHeightEnvelope
    {
        $logCaps = [];
        foreach ($this->occupiedSlots($slots, $stocks) as [, $stock]) {
            $marketCap = $this->marketCapOf($stock);
            if ($marketCap > 0.0) {
                $logCaps[] = log10($marketCap);
            }
        }

        if ($logCaps === []) {
            return new DistrictHeightEnvelope(
                DistrictMap::MARKET_CAP_LOG_EMPTY_FLOOR,
                DistrictMap::MARKET_CAP_LOG_EMPTY_FLOOR + DistrictMap::MARKET_CAP_LOG_MIN_SPAN,
            );
        }

        $floor = min($logCaps) - DistrictMap::MARKET_CAP_LOG_HEADROOM;
        $ceiling = max($logCaps) + DistrictMap::MARKET_CAP_LOG_HEADROOM;

        $shortfall = DistrictMap::MARKET_CAP_LOG_MIN_SPAN - ($ceiling - $floor);
        if ($shortfall > 0.0) {
            $floor -= $shortfall / 2.0;
            $ceiling += $shortfall / 2.0;
        }

        return new DistrictHeightEnvelope($floor, $ceiling);
    }

    /**
     * Lays the street out vertically for this request — see DistrictMap::ROW_GAP for the rule.
     *
     * The lane band comes first, one lane per institution any tenant is wired to, then each row
     * in turn is given ROW_GAP plus its clearance: its own tallest facade plus the height a
     * headroom-sized cap move would add, capped at MAX_FACADE_HEIGHT. A row with no tenants is
     * given the shortest facade's height, so an empty street still draws a kerb.
     *
     * @param list<array{ticker: string, x: int, width: int, row: int, rank: int}> $slots
     * @param Stock[]                                                              $stocks
     * @param int                                                                  $rowCount rows the composer wrapped the frontage into
     */
    public function resolveCanvas(array $slots, array $stocks, DistrictHeightEnvelope $envelope, int $rowCount): DistrictCanvasDTO
    {
        $occupied = $this->occupiedSlots($slots, $stocks);

        $tallestByRow = array_fill(0, max(1, $rowCount), DistrictMap::MIN_FACADE_HEIGHT);
        $wired = [];
        foreach ($occupied as [$slot, $stock]) {
            $height = $this->calculateFacadeHeight($this->marketCapOf($stock), $envelope);
            $tallestByRow[$slot['row']] = max($tallestByRow[$slot['row']] ?? 0.0, $height);
            foreach ($this->conduitsOf($stock) as $institutionId) {
                $wired[$institutionId] = true;
            }
        }
        ksort($tallestByRow);

        $laneYByInstitution = [];
        $laneY = DistrictMap::INSTITUTION_OUTLET_Y + DistrictMap::CONDUIT_LANE_TOP_INSET;
        foreach (array_keys(DistrictMap::INSTITUTIONS) as $institutionId) {
            if (isset($wired[$institutionId])) {
                $laneYByInstitution[$institutionId] = (float) $laneY;
                $laneY += DistrictMap::CONDUIT_LANE_PITCH;
            }
        }
        $laneBandBottom = (float) $laneY;

        // The room a tenant has to grow before the envelope clamps it, in facade units.
        $headroomHeight = DistrictMap::MARKET_CAP_LOG_HEADROOM / $envelope->span()
            * (DistrictMap::MAX_FACADE_HEIGHT - DistrictMap::MIN_FACADE_HEIGHT);

        $rowGroundLines = [];
        $skyTop = $laneBandBottom;
        foreach ($tallestByRow as $tallest) {
            $clearance = min(DistrictMap::MAX_FACADE_HEIGHT, $tallest + $headroomHeight);
            $groundLine = $skyTop + DistrictMap::ROW_GAP + $clearance;
            $rowGroundLines[] = $groundLine;
            $skyTop = $groundLine + DistrictMap::KERB_DEPTH;
        }

        return new DistrictCanvasDTO(
            rowGroundLines: $rowGroundLines,
            laneYByInstitution: $laneYByInstitution,
            laneBandBottom: $laneBandBottom,
            viewboxHeight: $skyTop + DistrictMap::CANVAS_BOTTOM_MARGIN,
        );
    }

    /**
     * Pairs each composed slot with the live stock that holds it, in slot order. Every slot
     * DistrictWardComposer produces is occupied — the roster is a live ranking, not a fixed set
     * of lots — so a slot whose stock has since vanished (a data race against the query that
     * supplied $stocks) is simply skipped.
     *
     * @param  list<array{ticker: string, x: int, width: int, row: int, rank: int}> $slots
     * @param  Stock[]                                                              $stocks
     * @return list<array{0: array{ticker: string, x: int, width: int, row: int, rank: int}, 1: Stock}>
     */
    private function occupiedSlots(array $slots, array $stocks): array
    {
        $byTicker = [];
        foreach ($stocks as $stock) {
            $byTicker[$stock->getTicker()] = $stock;
        }

        $occupied = [];
        foreach ($slots as $slot) {
            $stock = $byTicker[$slot['ticker']] ?? null;
            if ($stock instanceof Stock) {
                $occupied[] = [$slot, $stock];
            }
        }

        return $occupied;
    }

    /**
     * Market-capitalisation reference gridlines, one set per frontage row.
     *
     * Every 1-2-5 step of a decade (DistrictMap::GRIDLINE_MANTISSAS) that falls inside the
     * envelope is a candidate; candidates are then thinned bottom-up so consecutive rules on a
     * row stay at least DistrictMap::GRIDLINE_MIN_SPACING apart. A capitalisation maps to a facade
     * *height*, not to an absolute y, so the same reference sits at a different y on each row
     * and every row carries its own set. Reuses calculateFacadeHeight() directly, so a rule can
     * never disagree with the facades beside it. Rules taller than a row's own sky are dropped:
     * they would run through the lane band or the kerb above.
     *
     * @return list<array{row: int, y: float, label: string}>
     */
    public function buildGridlines(DistrictHeightEnvelope $envelope, DistrictCanvasDTO $canvas): array
    {
        $references = $this->gridlineReferences($envelope);

        $lines = [];
        $skyTop = $canvas->laneBandBottom;
        foreach ($canvas->rowGroundLines as $row => $groundLine) {
            foreach ($references as ['marketCap' => $marketCap, 'label' => $label]) {
                $y = $groundLine - $this->calculateFacadeHeight($marketCap, $envelope);
                if ($y < $skyTop + DistrictMap::GRIDLINE_LABEL_SIZE) {
                    continue;
                }

                $lines[] = ['row' => $row, 'y' => $y, 'label' => $label];
            }
            $skyTop = $groundLine + DistrictMap::KERB_DEPTH;
        }

        return $lines;
    }

    /**
     * The capitalisations worth ruling for a given envelope, ascending. Thinning keeps the lower
     * of any two candidates that would crowd, so the scale always starts from its round bottom.
     *
     * @return list<array{marketCap: float, label: string}>
     */
    private function gridlineReferences(DistrictHeightEnvelope $envelope): array
    {
        $references = [];
        $lastHeight = null;

        for ($exponent = (int) floor($envelope->logFloor); $exponent <= (int) ceil($envelope->logCeiling); $exponent++) {
            foreach (DistrictMap::GRIDLINE_MANTISSAS as $mantissa) {
                $logCap = $exponent + log10($mantissa);
                if ($logCap < $envelope->logFloor || $logCap > $envelope->logCeiling) {
                    continue;
                }

                $marketCap = $mantissa * 10 ** $exponent;
                $height = $this->calculateFacadeHeight($marketCap, $envelope);
                if ($lastHeight !== null && $height - $lastHeight < DistrictMap::GRIDLINE_MIN_SPACING) {
                    continue;
                }

                $references[] = ['marketCap' => $marketCap, 'label' => $this->formatGridlineLabel($marketCap)];
                $lastHeight = $height;
            }
        }

        return $references;
    }

    /**
     * Prints a reference capitalisation the way the gutter has always printed them: "$500B",
     * "$2T". Mantissas of 1, 2 and 5 always yield a whole number in the unit below, so no
     * decimals are ever needed and the label stays within the width FRONTAGE_GUTTER reserves.
     */
    private function formatGridlineLabel(float $marketCap): string
    {
        foreach (['T' => 1.0e12, 'B' => 1.0e9, 'M' => 1.0e6] as $suffix => $unit) {
            if ($marketCap >= $unit) {
                return sprintf('$%s%s', rtrim(rtrim(number_format($marketCap / $unit, 1, '.', ''), '0'), '.'), $suffix);
            }
        }

        return sprintf('$%s', number_format($marketCap, 0, '.', ''));
    }

    /**
     * Runs of adjacent same-district plots on each row, for the kerb's brackets. A run carries its
     * district's name only where it is wide enough to print it at
     * DistrictMap::SECTOR_BRACKET_LABEL_SIZE; a narrower run keeps the rule alone.
     *
     * Bracketed on district rather than GICS sector because the street is ORDERED by district (see
     * DistrictMap::FRONTAGE_DISTRICTS): bracketing on a taxonomy the order does not follow cut the
     * banking run in two around a data house, printed the oil houses twice across a row wrap, and
     * left every one-plot sector unnamed. A run also carries the sectors standing in it, so the
     * legend's sector filter can still dim the brackets its sector has no house in.
     *
     * @param  list<DistrictPlotDTO> $plots in frontage order
     * @return list<array{row: int, x: int, width: int, district: string, sectors: list<string>, label: string|null}>
     */
    public function buildDistrictRuns(array $plots): array
    {
        $runs = [];
        $current = null;
        $opened = [];

        foreach ($plots as $plot) {
            $district = $plot->district ?? '';
            $sector = $plot->sector ?? '';

            if ($current !== null && $current['row'] === $plot->row && $current['district'] === $district) {
                $current['width'] = $plot->x + $plot->width - $current['x'];
                $current['sectors'][$sector] = true;
                continue;
            }

            if ($current !== null) {
                $runs[] = $finished = $this->finishDistrictRun($current, ($opened[$current['district']] ?? false) === true);
                // Claimed only once the name has actually been PRINTED: a fragment too narrow to
                // carry it leaves the district unnamed, and the next one should print the name in
                // full rather than announce the continuation of something never announced.
                $opened[$current['district']] = isset($opened[$current['district']]) || $finished['label'] !== null;
            }
            $current = ['row' => $plot->row, 'x' => $plot->x, 'width' => $plot->width, 'district' => $district, 'sectors' => [$sector => true]];
        }

        if ($current !== null) {
            $runs[] = $this->finishDistrictRun($current, isset($opened[$current['district']]) && $opened[$current['district']]);
        }

        return $runs;
    }

    /**
     * A district the wrap has already opened on an earlier row is NAMED AS A CONTINUATION rather
     * than named again. Aligning every wrap to a district edge would cost this street a quarter of
     * its rendered scale (see DistrictWardComposer::wrapIndices), so a block does still get cut in
     * two now and then — but a reader seeing the same name twice reads two places, while a reader
     * seeing "cont." reads one place carried onto the next line, which is what it is.
     *
     * @param  array{row: int, x: int, width: int, district: string, sectors: array<string, bool>} $run
     * @return array{row: int, x: int, width: int, district: string, sectors: list<string>, label: string|null}
     */
    private function finishDistrictRun(array $run, bool $continued = false): array
    {
        $name = $continued && $run['district'] !== ''
            ? $run['district'] . DistrictMap::DISTRICT_CONTINUATION_SUFFIX
            : $run['district'];

        $labelWidth = mb_strlen($name) * DistrictMap::SECTOR_BRACKET_LABEL_ADVANCE * DistrictMap::SECTOR_BRACKET_LABEL_SIZE
            + 2 * DistrictMap::SECTOR_BRACKET_LABEL_PADDING;

        return [
            'row' => $run['row'],
            'x' => $run['x'],
            'width' => $run['width'],
            'district' => $run['district'],
            'sectors' => array_values(array_filter(array_keys($run['sectors']), static fn (string $sector): bool => $sector !== '')),
            'label' => $name !== '' && $labelWidth <= $run['width'] ? $name : null,
        ];
    }

    /**
     * The final rendered viewBox width for the street: its frontage width, widened if needed so
     * the institution band never overflows it. A narrow street can still be wired to several
     * institutions — MIN_INSTITUTION_WIDTH must never be satisfied by letting an institution
     * overflow the canvas, so the canvas grows to fit it instead.
     *
     * @param list<DistrictPlotDTO> $plots
     */
    public function resolveViewboxWidth(array $plots, int $frontageViewboxWidth): int
    {
        $count = count($this->wiredInstitutionIds($plots));
        if ($count === 0) {
            return $frontageViewboxWidth;
        }

        $minRequired = DistrictMap::FRONTAGE_GUTTER + DistrictMap::FRONTAGE_MARGIN
            + ($count * DistrictMap::MIN_INSTITUTION_WIDTH)
            + (($count - 1) * DistrictMap::FRONTAGE_GAP);

        return max($frontageViewboxWidth, $minRequired);
    }

    /**
     * Derives the institutions rendered on the street: exactly those at least one plot draws a
     * conduit from, laid out evenly between the gutter and the east margin — see
     * App\Data\DistrictMap::INSTITUTIONS for why an institution carries no geometry of its own.
     * Each takes the conduit lane the canvas reserved for it.
     *
     * @param  list<DistrictPlotDTO> $plots
     * @return list<DistrictInstitutionDTO>
     */
    public function buildInstitutions(array $plots, int $viewboxWidth, DistrictCanvasDTO $canvas): array
    {
        $ids = $this->wiredInstitutionIds($plots);
        $count = count($ids);
        if ($count === 0) {
            return [];
        }

        $available = $viewboxWidth - DistrictMap::FRONTAGE_GUTTER - DistrictMap::FRONTAGE_MARGIN
            - (($count - 1) * DistrictMap::FRONTAGE_GAP);
        $width = (int) min(
            DistrictMap::MAX_INSTITUTION_WIDTH,
            max(DistrictMap::MIN_INSTITUTION_WIDTH, $available / $count)
        );

        $institutions = [];
        $x = DistrictMap::FRONTAGE_GUTTER;
        foreach ($ids as $institutionId) {
            $config = DistrictMap::INSTITUTIONS[$institutionId];

            $institutions[] = new DistrictInstitutionDTO(
                id: $institutionId,
                label: $config['label'],
                shortLabel: $config['short_label'] ?? $config['label'],
                x: $x,
                width: $width,
                laneY: $canvas->laneYByInstitution[$institutionId]
                    ?? throw new \LogicException(sprintf('Canvas reserved no lane for institution "%s"; resolve it from the same slots.', $institutionId)),
                fields: $config['fields'],
                readouts: $config['readouts'],
                stressRules: $config['stress_rules'] ?? [],
            );

            $x += $width + DistrictMap::FRONTAGE_GAP;
        }

        return $institutions;
    }

    /**
     * @param  list<DistrictPlotDTO> $plots
     * @return list<string> institution ids, in DistrictMap::INSTITUTIONS declaration order
     */
    private function wiredInstitutionIds(array $plots): array
    {
        $wired = [];
        foreach ($plots as $plot) {
            foreach ($plot->conduits as $institutionId) {
                $wired[$institutionId] = true;
            }
        }

        return array_values(array_intersect(array_keys(DistrictMap::INSTITUTIONS), array_keys($wired)));
    }

    /**
     * Builds the facade for a plot held by a listed company.
     *
     * @param array{ticker: string, x: int, width: int, row: int, rank: int} $slot
     */
    private function buildOccupiedPlot(
        string $plotId,
        array $slot,
        Stock $stock,
        DistrictHeightEnvelope $envelope,
        DistrictCanvasDTO $canvas,
        ?float $changePercent,
    ): DistrictPlotDTO {
        $price = (float) $stock->getPrice();
        $marketCap = $this->marketCapOf($stock);
        $height = $this->calculateFacadeHeight($marketCap, $envelope);
        $groundLine = $canvas->groundLineForRow($slot['row']);

        $businessModel = $this->businessModelOf($stock);
        $isFinancial = Sectors::isFinancial($businessModel);
        [$returnOnCapital, $baselineReturn] = $isFinancial
            ? [(float) $stock->getCurrentRoe() ?: (float) $stock->getBaselineRoe(), (float) $stock->getBaselineRoe()]
            : [(float) $stock->getCurrentRoic() ?: (float) $stock->getBaselineRoic(), (float) $stock->getBaselineRoic()];

        $floors = $this->calculateFloors($height);
        $windowColumns = intdiv($slot['width'] - DistrictMap::WINDOW_WALL_ALLOWANCE, DistrictMap::WINDOW_PITCH);
        $band = $windowColumns * DistrictMap::WINDOW_PITCH - (DistrictMap::WINDOW_PITCH - DistrictMap::WINDOW_WIDTH);
        $litShare = $this->calculateLitShare($returnOnCapital, $baselineReturn);
        $windowKeys = $this->windowKeys($stock->getTicker(), $floors, $windowColumns);
        $conduits = $this->conduitsOf($stock);

        return new DistrictPlotDTO(
            plotId: $plotId,
            x: $slot['x'],
            width: $slot['width'],
            height: $height,
            y: $groundLine - $height,
            row: $slot['row'],
            groundLine: $groundLine,
            floors: $floors,
            windowColumns: $windowColumns,
            windowInset: ($slot['width'] - $band) / 2.0,
            litWindows: $this->lightWindows($windowKeys, $litShare),
            windowKeys: $windowKeys,
            twinklePhases: $this->twinklePhases($stock->getTicker(), $floors, $windowColumns),
            litShare: $litShare,
            returnField: $isFinancial ? self::RETURN_FIELD_FINANCIAL : self::RETURN_FIELD_OPERATING,
            roofFurniture: $this->roofFurnitureFor($stock->getIndustry() ?? '', $businessModel),
            ticker: $stock->getTicker(),
            name: (string) $stock->getName(),
            rank: $slot['rank'],
            sector: $stock->getSector(),
            district: DistrictMap::districtFor($businessModel),
            industry: $stock->getIndustry(),
            systemicImportance: $stock->getSystemicImportance(),
            creditRating: $stock->getCreditRating(),
            condition: $this->determineCondition($stock),
            price: $price,
            marketCap: $marketCap,
            returnOnCapital: $returnOnCapital,
            baselineReturnOnCapital: $baselineReturn,
            changePercent: $changePercent,
            blurb: $this->extractBlurb($stock->getTicker()),
            conduits: $conduits,
            conduitDropX: $this->spreadConduitDrops($slot, $conduits),
        );
    }

    /**
     * Maps market capitalisation onto the street's facade height envelope: linear in log10
     * across the window, clamped at both ends. Display transform only — it carries no financial
     * meaning. Mirrored by assets/controllers/district_controller.js resizeFacade(), which must
     * stay in step or a facade jumps on the first live tick after load.
     */
    public function calculateFacadeHeight(float $marketCap, DistrictHeightEnvelope $envelope): float
    {
        return DistrictMap::MIN_FACADE_HEIGHT
            + $envelope->normalise($marketCap) * (DistrictMap::MAX_FACADE_HEIGHT - DistrictMap::MIN_FACADE_HEIGHT);
    }

    /**
     * Share of a facade's windows to light from the tenant's return on capital against its
     * baseline — see DistrictMap::WINDOW_LIT_SHARE_AT_BASELINE. A tenant with no baseline on
     * file (or a non-positive one) is treated as earning its baseline exactly, rather than
     * dividing by it.
     */
    public function calculateLitShare(float $returnOnCapital, float $baselineReturn): float
    {
        $ratio = $baselineReturn > 0.0 ? $returnOnCapital / $baselineReturn : 1.0;
        $share = DistrictMap::WINDOW_LIT_SHARE_FLOOR
            + (DistrictMap::WINDOW_LIT_SHARE_AT_BASELINE - DistrictMap::WINDOW_LIT_SHARE_FLOOR) * $ratio;

        return max(DistrictMap::WINDOW_LIT_SHARE_FLOOR, min(1.0, $share));
    }

    /**
     * Each window's lighting priority, [floor][column]: a stable pseudo-random draw from a hash
     * of the window's address, rounded to DistrictMap::WINDOW_KEY_PRECISION so the client can
     * compare the very same figure against a live lit share. Identical on every render, so the
     * street never flickers between requests.
     *
     * @return list<list<float>>
     */
    public function windowKeys(string $ticker, int $floors, int $columns): array
    {
        $keys = [];
        for ($floor = 0; $floor < $floors; $floor++) {
            $row = [];
            for ($column = 0; $column < $columns; $column++) {
                $row[] = $this->addressHash(sprintf('%s:%d:%d', $ticker, $floor, $column));
            }
            $keys[] = $row;
        }

        return $keys;
    }

    /**
     * Decides which windows are lit, [floor][column]: a window is lit when its priority falls
     * under the share. The scatter reads as a building at night rather than a bar filling from
     * the bottom, and raising the share only ever adds windows to the ones already lit.
     * Mirrored by district_controller.js relightWindows(), which applies the same comparison
     * to the same keys on a live tick.
     *
     * @param  list<list<float>> $windowKeys
     * @return list<list<bool>>
     */
    public function lightWindows(array $windowKeys, float $litShare): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (float $key): bool => $key < $litShare, $row),
            $windowKeys,
        );
    }

    /**
     * Which windows are drawn a step brighter while lit, [floor][column]: a stable draw for a
     * marked window, null for one that burns steady. A second hash of the address, salted so
     * it is independent of the lighting priority — otherwise the marked windows would always be
     * the ones lit first. See DistrictMap::WINDOW_TWINKLE_SHARE.
     *
     * @return list<list<float|null>>
     */
    public function twinklePhases(string $ticker, int $floors, int $columns): array
    {
        $phases = [];
        for ($floor = 0; $floor < $floors; $floor++) {
            $row = [];
            for ($column = 0; $column < $columns; $column++) {
                $draw = $this->addressHash(sprintf('%s:%d:%d:twinkle', $ticker, $floor, $column));
                $row[] = $draw < DistrictMap::WINDOW_TWINKLE_SHARE
                    ? round($draw / DistrictMap::WINDOW_TWINKLE_SHARE, DistrictMap::WINDOW_KEY_PRECISION)
                    : null;
            }
            $phases[] = $row;
        }

        return $phases;
    }

    /** A stable draw in [0, 1) from a string, rounded to DistrictMap::WINDOW_KEY_PRECISION. */
    private function addressHash(string $address): float
    {
        return round(crc32($address) / 0x100000000, DistrictMap::WINDOW_KEY_PRECISION);
    }

    /**
     * The rooftop symbol for a tenant: its industry's, else its business model's, else the
     * default — see DistrictMap::ROOF_FURNITURE_BY_INDUSTRY.
     */
    public function roofFurnitureFor(string $industry, string $businessModel): string
    {
        return DistrictMap::ROOF_FURNITURE_BY_INDUSTRY[$industry]
            ?? DistrictMap::ROOF_FURNITURE[$businessModel]
            ?? DistrictMap::ROOF_FURNITURE_DEFAULT;
    }

    /**
     * Spreads a tenant's conduit drops evenly across its roof, inside CONDUIT_DROP_INSET, in
     * institution declaration order — so the drops arrive in the same left-to-right order the
     * institutions stand in and never overlay one another.
     *
     * @param  array{ticker: string, x: int, width: int, row: int, rank: int} $slot
     * @param  list<string>                                                   $conduits
     * @return array<string, float>
     */
    private function spreadConduitDrops(array $slot, array $conduits): array
    {
        $count = count($conduits);
        if ($count === 0) {
            return [];
        }

        $inset = min(DistrictMap::CONDUIT_DROP_INSET, $slot['width'] / 4.0);
        $usable = $slot['width'] - 2 * $inset;

        $drops = [];
        foreach ($conduits as $i => $institutionId) {
            $drops[$institutionId] = $slot['x'] + $inset + $usable * ($i + 1) / ($count + 1);
        }

        return $drops;
    }

    /** Counts the window bands that fit on a facade of the given height. */
    private function calculateFloors(float $height): int
    {
        return max(1, (int) floor(($height - DistrictMap::FIRST_FLOOR_INSET) / DistrictMap::FLOOR_HEIGHT));
    }

    /**
     * Classifies a facade as ruined, distressed, or sound from solvency and credit standing.
     * Reuses the agency's rating ladder rather than restating the hierarchy.
     */
    private function determineCondition(Stock $stock): string
    {
        if ($stock->isBankrupt()) {
            return 'ruin';
        }

        $rank = CreditRatingAgency::RATING_RANKS[$stock->getCreditRating()] ?? CreditRatingAgency::RATING_RANKS['BBB'];

        return $rank < DistrictMap::INVESTMENT_GRADE_RANK ? 'distressed' : 'sound';
    }

    private function marketCapOf(Stock $stock): float
    {
        return (float) $stock->getPrice() * (float) $stock->getSharesOutstanding();
    }

    private function businessModelOf(Stock $stock): string
    {
        return Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
    }

    /** @return list<string> institution ids, in DistrictMap::INSTITUTIONS declaration order */
    private function conduitsOf(Stock $stock): array
    {
        return $this->conduitResolver->conduitsForBusinessModel($this->businessModelOf($stock));
    }

    /** Returns the opening paragraph of the company's lore entry for the info panel. */
    private function extractBlurb(string $ticker): ?string
    {
        $description = StockInfo::DESCRIPTIONS[$ticker] ?? null;
        if ($description === null) {
            return null;
        }

        return trim(explode("\n\n", $description)[0]);
    }
}

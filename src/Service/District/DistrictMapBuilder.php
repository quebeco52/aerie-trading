<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\Data\Sectors;
use App\Data\StockInfo;
use App\DTO\DistrictInstitutionDTO;
use App\DTO\DistrictPlotDTO;
use App\Entity\Stock;
use App\Service\Market\CreditRatingAgency;
use App\Service\Math\MathUtility;

/**
 * Builds the render state for the district street elevation from live company fundamentals.
 *
 * Every transform here is presentation-only: facade heights are a log-scale display mapping,
 * not a financial model, which is why they are deliberately kept out of MathUtility. No value
 * produced by this service may ever be read back into the simulation.
 */
class DistrictMapBuilder
{
    public function __construct(
        private readonly DistrictConduitResolver $conduitResolver,
    ) {
    }

    /**
     * Assembles the ordered plot list for the street, pairing composed geometry with live stocks.
     * Every slot DistrictWardComposer produces is occupied — the roster is a live ranking, not a
     * fixed set of lots — so a slot whose stock has since vanished (a data race against the query
     * that supplied $stocks) is simply skipped.
     *
     * @param  list<array{ticker: string, x: int, width: int, row: int, rank: int}> $slots
     * @param  Stock[]                                                              $stocks
     * @param  array<string, float>                                                 $changeByTicker fractional price change per ticker
     * @return list<DistrictPlotDTO>
     */
    public function buildWard(array $slots, array $stocks, array $changeByTicker = []): array
    {
        $byTicker = [];
        foreach ($stocks as $stock) {
            $byTicker[$stock->getTicker()] = $stock;
        }

        $plots = [];
        foreach ($slots as $i => $slot) {
            $stock = $byTicker[$slot['ticker']] ?? null;
            if (!$stock instanceof Stock) {
                continue;
            }

            $plots[] = $this->buildOccupiedPlot(
                sprintf('%s-%02d', DistrictMap::WARD_SLUG, $i + 1),
                $slot,
                $stock,
                $changeByTicker[$slot['ticker']] ?? null,
            );
        }

        return $plots;
    }

    /**
     * Market-capitalisation reference gridlines, one set per frontage row.
     *
     * A capitalisation maps to a facade *height*, not to an absolute y, so the same reference
     * sits at a different y on each row and every row carries its own set. Reuses
     * calculateFacadeHeight() directly, so a rule can never disagree with the facades beside it.
     *
     * @return list<array{row: int, y: float, label: string}>
     */
    public function buildGridlines(int $rowCount): array
    {
        $lines = [];
        for ($row = 0; $row < $rowCount; $row++) {
            foreach (DistrictMap::MARKET_CAP_GRIDLINES as $label => $marketCap) {
                $lines[] = [
                    'row' => $row,
                    'y' => DistrictMap::groundLineForRow($row) - $this->calculateFacadeHeight($marketCap),
                    'label' => $label,
                ];
            }
        }

        return $lines;
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
     *
     * @param  list<DistrictPlotDTO> $plots
     * @return list<DistrictInstitutionDTO>
     */
    public function buildInstitutions(array $plots, int $viewboxWidth): array
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
    private function buildOccupiedPlot(string $plotId, array $slot, Stock $stock, ?float $changePercent): DistrictPlotDTO
    {
        $price = (float) $stock->getPrice();
        $marketCap = $price * (float) $stock->getSharesOutstanding();
        $height = $this->calculateFacadeHeight($marketCap);
        $groundLine = DistrictMap::groundLineForRow($slot['row']);

        $businessModel = Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
        $returnOnCapital = Sectors::isFinancial($businessModel)
            ? ((float) $stock->getCurrentRoe() ?: (float) $stock->getBaselineRoe())
            : ((float) $stock->getCurrentRoic() ?: (float) $stock->getBaselineRoic());

        return new DistrictPlotDTO(
            plotId: $plotId,
            x: $slot['x'],
            width: $slot['width'],
            height: $height,
            y: $groundLine - $height,
            row: $slot['row'],
            groundLine: $groundLine,
            floors: $this->calculateFloors($height),
            ticker: $stock->getTicker(),
            name: (string) $stock->getName(),
            rank: $slot['rank'],
            sector: $stock->getSector(),
            industry: $stock->getIndustry(),
            systemicImportance: $stock->getSystemicImportance(),
            creditRating: $stock->getCreditRating(),
            condition: $this->determineCondition($stock),
            price: $price,
            marketCap: $marketCap,
            returnOnCapital: $returnOnCapital,
            changePercent: $changePercent,
            blurb: $this->extractBlurb($stock->getTicker()),
            conduits: $this->conduitResolver->conduitsForBusinessModel($businessModel),
        );
    }

    /**
     * Maps market capitalisation onto the street's facade height envelope on a log10 scale.
     * Display transform only — it carries no financial meaning.
     * Uses a logistic curve to eliminate hard saturation at the bounds — smaller and larger
     * companies still differentiate visually past the floor/ceiling anchors.
     */
    public function calculateFacadeHeight(float $marketCap): float
    {
        $logCap = log10(max($marketCap, 1.0));

        $midpoint = (DistrictMap::MARKET_CAP_LOG_CEILING + DistrictMap::MARKET_CAP_LOG_FLOOR) / 2.0;
        $halfSpan = (DistrictMap::MARKET_CAP_LOG_CEILING - DistrictMap::MARKET_CAP_LOG_FLOOR) / 2.0;
        // Steepness that lands the floor/ceiling at EDGE_TOLERANCE / (1 - EDGE_TOLERANCE).
        $steepness = log((1.0 - DistrictMap::MARKET_CAP_LOG_EDGE_TOLERANCE) / DistrictMap::MARKET_CAP_LOG_EDGE_TOLERANCE) / $halfSpan;

        $normalised = MathUtility::logisticUnitInterval($logCap, $midpoint, $steepness);

        return DistrictMap::MIN_FACADE_HEIGHT
            + $normalised * (DistrictMap::MAX_FACADE_HEIGHT - DistrictMap::MIN_FACADE_HEIGHT);
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

<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\Data\Sectors;
use App\Data\StockInfo;
use App\DTO\DistrictPlotDTO;
use App\Entity\Stock;
use App\Service\Market\CreditRatingAgency;
use App\Service\Math\MathUtility;

/**
 * Builds the render state for a district ward elevation from live company fundamentals.
 *
 * Every transform here is presentation-only: facade heights are a log-scale display mapping,
 * not a financial model, which is why they are deliberately kept out of MathUtility. No value
 * produced by this service may ever be read back into the simulation.
 */
class DistrictMapBuilder
{
    /**
     * Assembles the ordered plot list for a ward, pairing authored geometry with live stocks.
     *
     * @param  Stock[] $stocks Candidate stocks; entries without a plot in this ward are ignored.
     * @return list<DistrictPlotDTO>
     */
    public function buildWard(string $wardSlug, array $stocks): array
    {
        $byTicker = [];
        foreach ($stocks as $stock) {
            $byTicker[$stock->getTicker()] = $stock;
        }

        $plots = [];
        foreach (DistrictMap::plotsForWard($wardSlug) as $plotId => $plot) {
            $stock = $plot['ticker'] !== null ? ($byTicker[$plot['ticker']] ?? null) : null;

            $plots[] = $stock instanceof Stock
                ? $this->buildOccupiedPlot($plotId, $plot, $stock)
                : $this->buildVacantPlot($plotId, $plot);
        }

        return $plots;
    }

    /**
     * Builds the facade for a plot held by a listed company.
     *
     * @param array{ward: string, x: int, width: int, ticker: string|null} $plot
     */
    private function buildOccupiedPlot(string $plotId, array $plot, Stock $stock): DistrictPlotDTO
    {
        $price = (float) $stock->getPrice();
        $marketCap = $price * (float) $stock->getSharesOutstanding();
        $height = $this->calculateFacadeHeight($marketCap);

        $businessModel = Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
        $returnOnCapital = Sectors::isFinancial($businessModel)
            ? ((float) $stock->getCurrentRoe() ?: (float) $stock->getBaselineRoe())
            : ((float) $stock->getCurrentRoic() ?: (float) $stock->getBaselineRoic());

        return new DistrictPlotDTO(
            plotId: $plotId,
            x: $plot['x'],
            width: $plot['width'],
            height: $height,
            y: $this->groundLine($plot['ward']) - $height,
            floors: $this->calculateFloors($height),
            ticker: $stock->getTicker(),
            name: $stock->getName(),
            industry: $stock->getIndustry(),
            systemicImportance: $stock->getSystemicImportance(),
            creditRating: $stock->getCreditRating(),
            condition: $this->determineCondition($stock),
            price: $price,
            marketCap: $marketCap,
            returnOnCapital: $returnOnCapital,
            blurb: $this->extractBlurb($stock->getTicker()),
            conduits: DistrictMap::conduitsForBusinessModel($businessModel),
        );
    }

    /**
     * Builds an empty lot held in reserve for a future or procedural listing.
     *
     * @param array{ward: string, x: int, width: int, ticker: string|null} $plot
     */
    private function buildVacantPlot(string $plotId, array $plot): DistrictPlotDTO
    {
        $height = DistrictMap::MIN_FACADE_HEIGHT / 3;

        return new DistrictPlotDTO(
            plotId: $plotId,
            x: $plot['x'],
            width: $plot['width'],
            height: $height,
            y: $this->groundLine($plot['ward']) - $height,
            floors: 0,
        );
    }

    /**
     * Maps market capitalisation onto the ward's facade height envelope on a log10 scale.
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
        return max(1, (int) floor($height / DistrictMap::FLOOR_HEIGHT));
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

    /** Resolves the baseline y coordinate that facades stand on for the given ward. */
    private function groundLine(string $wardSlug): float
    {
        return (float) (DistrictMap::WARDS[$wardSlug]['ground_line'] ?? 880);
    }
}

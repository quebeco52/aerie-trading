<?php

declare(strict_types=1);

namespace App\Service\Corporate\Segment;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Model\Strategy\OperatingStrategyInterface;

/**
 * Opens a company's plant books (SegmentBooks): a company whose seeded lines sell into several markets at the first
 * report that finds their revenue shares filed, and a company in one market when it enters another, with all its plant
 * in its main segment.
 */
final class SegmentBookOpener
{
    public function __construct(
        private readonly CorporateMetrics $corporateMetrics,
        /** Zero-sum industry share ledger; null (unit tests, harnesses) sizes no segment against its industry. */
        private readonly ?IndustryShareLedger $industryShareLedger = null,
    ) {}

    /**
     * Opens the books of a company in one market as it enters another: all its plant in its main segment, which earns
     * the company's own margin and turnover (SegmentBooks::openEconomics()), so the segment it enters is booked beside
     * economics that are already the main segment's. Nothing for a company whose books are open.
     */
    public function openForEntry(Stock $stock, OperatingStrategyInterface $strategy, MacroStateDTO $macroState, int $tick, int $ticksPerYear): void
    {
        if (SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []) !== null) {
            return;
        }

        $this->open($stock, $strategy, $macroState, $tick, $ticksPerYear, [Segments::MAIN => 1.0]);
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $stock->setEarningsMomentumZ(SegmentBooks::openEconomics($stock->getTicker(), $momentum, (float) $stock->getAssetTurnover(), (float) $stock->getOperatingMargin()) + $momentum);
    }

    /**
     * Each segment takes its share of the plant and a market of its own: another segment enters at its share of the
     * market its industry's firms sell into (SegmentBooks::entryMarketShare()), the main segment keeps the company's
     * share of its own, and the industry ledger moves the other segments out of the main record. The lean the plan
     * carried on one plant goes.
     *
     * A model whose growth covered every line on one plant covers its main segment's alone once the books open, so each
     * segment's market growth is read with the books open, and the main record's path change is folded into its anchor:
     * neither the main segment's market nor its trend plant moves the day the books open.
     *
     * @param array<string, float> $plant Segment => its share of the plant.
     */
    public function open(Stock $stock, OperatingStrategyInterface $strategy, MacroStateDTO $macroState, int $tick, int $ticksPerYear, array $plant): void
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $firmShare = $this->corporateMetrics->calculateAddressableShare($stock, $strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital()), $macroState);
        $marketShares = [];
        foreach (SegmentBooks::industries($stock) as $segment => $industry) {
            $market = $segment === Segments::MAIN ? null : $this->industryShareLedger?->resolveIndustryMarketRevenue(
                $industry,
                $stock->getTicker(),
                $macroState,
                IndustryShareLedger::secularExcessGrowth(Sectors::strategyFor($industry), $stock),
                $tick,
                $ticksPerYear,
            );
            if ($market !== null) {
                $marketShares[$segment] = SegmentBooks::entryMarketShare(($plant[$segment] ?? 0.0) * (float) $stock->getTotalRevenue(), $market, $firmShare);
            }
        }

        $firmGrowth = CorporateMetrics::secularMarketGrowth($stock, $macroState->totalTime);
        $excessBefore = IndustryShareLedger::secularExcessGrowth($strategy, $stock);
        $stock->setEarningsMomentumZ(SegmentBooks::open($plant, 1.0, []) + $momentum);
        $opened = SegmentBooks::open($plant, $firmGrowth, SegmentBooks::marketGrowths($stock, $macroState->totalTime), $firmShare, $marketShares);
        foreach (array_keys($momentum) as $key) {
            if (str_starts_with((string) $key, SegmentOutlook::STATE_TILT)) {
                unset($momentum[$key]);
            }
        }
        $stock->setEarningsMomentumZ($opened + $momentum);
        $this->industryShareLedger?->openSegmentBooks($stock, $plant[Segments::MAIN] ?? 0.0, $macroState->totalTime, $excessBefore, IndustryShareLedger::secularExcessGrowth($strategy, $stock));
    }
}

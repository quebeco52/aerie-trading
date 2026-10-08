<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Sectors;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Sector\BiotechBusinessModel;

/**
 * A drug company's pipeline and patent position, the way its annual report lays them out: the late-stage programmes
 * and the readouts they imply, the marketed book by when its exclusivity runs out, and how long the cash lasts.
 *
 * Every figure is the model's own ledger as the last report left it (BiotechBusinessModel::describePipeline());
 * the ladder only groups the cohorts the model carries by the years of exclusivity they have left.
 */
class BiotechPipelineBuilder
{
    // --- Patent Expiry Ladder ---

    /** Upper edges of the ladder's rungs, in years of exclusivity left: the horizons a pharma filing's patent schedule is read in. */
    public const EXPIRY_RUNG_EDGES_YEARS = [1.0, 3.0, 5.0, 10.0];

    public function __construct(
        private readonly CorporateReportRepository $reports,
    ) {}

    /**
     * @return array{pipeline: array{lateStageAssets: float, readoutsPerYear: float, protectedShare: float, franchiseChange: float, rndReplacement: float, ramping: int, ladder: list<array{label: string, share: float, count: int|null}>, runway: array{years: float|null, quarterlyBurn: float}|null}|null}
     */
    public function build(Stock $stock): array
    {
        $strategy = Sectors::getBusinessModelStrategy(Sectors::businessModelFor($stock->getIndustry()));
        $book = !$stock->isBankrupt() && $strategy instanceof BiotechBusinessModel ? $strategy->describePipeline($stock) : null;
        if ($book === null) {
            return ['pipeline' => null];
        }

        return ['pipeline' => [
            'lateStageAssets' => $book['lateStageAssets'],
            'readoutsPerYear' => $book['readoutsPerYear'],
            'protectedShare' => $book['protectedShare'],
            // The marketed book against the one the company listed with (1.0).
            'franchiseChange' => $book['franchiseIndex'] - 1.0,
            'rndReplacement' => $book['rndReplacement'],
            'ramping' => count(array_filter(
                $book['cohorts'],
                static fn (array $cohort): bool => $cohort['exclusivityQuarters'] > 0.0 && $cohort['adoption'] < BiotechBusinessModel::LAUNCH_PEAK_ADOPTION
            )),
            'ladder' => self::ladder($book['cohorts'], $book['offPatentShare']),
            'runway' => $this->runway($stock),
        ]];
    }

    /**
     * Marketed sales by the years of exclusivity left, then what has lost it: the cohorts still eroding, and the
     * off-patent and established book the model no longer tracks drug by drug (so it carries no count).
     *
     * @param list<array{share: float, adoption: float, exclusivityQuarters: float}> $cohorts
     * @return list<array{label: string, share: float, count: int|null}>
     */
    public static function ladder(array $cohorts, float $offPatentShare): array
    {
        $edges = self::EXPIRY_RUNG_EDGES_YEARS;
        $rungs = [];
        $lower = 0.0;
        foreach ($edges as $i => $edge) {
            $rungs[] = ['label' => $i === 0 ? sprintf('Within %d year', (int) $edge) : sprintf('%d to %d years', (int) $lower, (int) $edge), 'share' => 0.0, 'count' => 0];
            $lower = $edge;
        }
        $rungs[] = ['label' => sprintf('Over %d years', (int) $lower), 'share' => 0.0, 'count' => 0];
        $eroding = ['label' => 'Exclusivity lost, still eroding', 'share' => 0.0, 'count' => 0];

        foreach ($cohorts as $cohort) {
            if ($cohort['exclusivityQuarters'] <= 0.0) {
                $eroding['share'] += $cohort['share'];
                $eroding['count']++;
                continue;
            }
            $years = $cohort['exclusivityQuarters'] / FinancialConstants::QUARTERS_PER_YEAR;
            $rung = count($edges);
            foreach ($edges as $i => $edge) {
                if ($years <= $edge) {
                    $rung = $i;
                    break;
                }
            }
            $rungs[$rung]['share'] += $cohort['share'];
            $rungs[$rung]['count']++;
        }

        $rungs[] = $eroding;
        $rungs[] = ['label' => 'Off patent and established', 'share' => $offPatentShare, 'count' => null];

        return $rungs;
    }

    /**
     * Years the cash on hand covers at the last quarter's free cash outflow. R&D is the company's capital spending,
     * so the burn is free cash flow, not operating cash flow. Null years when the quarter generated cash.
     *
     * @return array{years: float|null, quarterlyBurn: float}|null Null before a report states its cash flow.
     */
    private function runway(Stock $stock): ?array
    {
        $report = $this->reports->findLatestFor($stock);
        if ($report === null || $report->getFreeCashFlow() === null) {
            return null;
        }

        $burn = max(0.0, -(float) $report->getFreeCashFlow());
        $cash = max(0.0, (float) $report->getTreasury());

        return [
            'years' => $burn > 0.0 ? $cash / ($burn * FinancialConstants::QUARTERS_PER_YEAR) : null,
            'quarterlyBurn' => $burn,
        ];
    }
}

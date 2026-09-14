<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;

/**
 * Where a company sits in its market, on two denominators that answer different questions.
 *
 * The ADDRESSABLE share is the firm's reach into the whole serviceable market it sells into, modelled
 * rivals or not (CorporateMetrics::calculateScaleRatio). It is the headline, because most industries here
 * have a single modelled firm and that firm is not a monopolist: the rest of its market is simply not
 * simulated. It is also the number the saturation physics is struck on, so the penalty it carries —
 * Penrose margin friction and the Cobb-Douglas decay of marginal return past half the market — is shown
 * beside it rather than left to be inferred.
 *
 * The ROSTER share is the firm's share of what the MODELLED industry sells, from the industry ledger. It
 * only means something when there are modelled rivals, and is shown only then. The ledger also supplies
 * the roster's installed plant against its trend plant and the industry balance those imply (the rest of
 * the industry is a fringe supplying at trend); the last quarterly report supplies what that did to the price.
 * The page never strikes the balance itself: the ledger is written by the ticker and only read here.
 */
class IndustryPositionBuilder
{
    // --- Balance Bands ---

    /** Capacity over trend demand at or above which the industry reads as overbuilt. */
    private const OVERBUILT_RATIO = 1.05;

    /** Capacity over trend demand at or below which the industry reads as tight. */
    private const TIGHT_RATIO = 0.95;

    public function __construct(
        private readonly IndustryShareLedger $ledger,
        private readonly CorporateMetrics $corporateMetrics,
        private readonly CorporateReportRepository $reports,
        private readonly \Redis $redis,
        private readonly int $ticksPerYear,
    ) {}

    /**
     * @return array{
     *     marketShare: float,
     *     industry: array{
     *         tracked: bool,
     *         pricesCapacity: bool,
     *         substitutability: float,
     *         addressableShare: float,
     *         saturation: array{threshold: float, penalty: float, severity: float, trueReturn: float, marginalReturn: float, moatFactor: float},
     *         rosterShare: float|null,
     *         peerShares: array<string, float>,
     *         rosterSize: int,
     *         capacityRatio: float|null,
     *         installedCapacity: float|null,
     *         trendCapacity: float|null,
     *         balance: string,
     *         priceLevel: float|null,
     *         rivalShareDrain: float|null,
     *         ownPriceVolumeShift: float|null
     *     }
     * }
     */
    public function build(Stock $stock, MacroStateDTO $macroState): array
    {
        $businessModel = Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
        $strategy = Sectors::getBusinessModelStrategy($businessModel);
        $substitutability = $strategy->getIndustrySubstitutability();
        // A lender sells a yield, not a unit: the capacity balance is not struck for it (see EarningsEngine).
        $pricesCapacity = !$strategy->isFinancial() && $substitutability > 0.0;

        $tick = (int) ($this->redis->get('simulation_tick_count') ?: 0);
        $description = $stock->isBankrupt() ? null : $this->ledger->describeIndustry(
            $stock,
            $pricesCapacity ? [
                'trend_nominal_gdp' => IndustryShareLedger::trendNominalGdp($macroState),
                'total_time' => $macroState->totalTime,
                'secular_excess_growth' => IndustryShareLedger::secularExcessGrowth($strategy, $stock),
            ] : null,
            $tick,
            $this->ticksPerYear
        );

        $kpis = $this->reports->findLatestFor($stock)?->getReportedKpis() ?? [];
        $capacityRatio = $description['capacity_ratio'] ?? null;

        $peerShares = [];
        foreach ($description['peers'] ?? [] as $ticker => $peer) {
            $peerShares[$ticker] = $peer['revenue_share'];
        }
        $rosterSize = count($peerShares);

        // A lender's capital employed is funded by deposits and wholesale borrowing, so equity is the
        // base its reach is measured against; an industrial's is the capital it has put to work.
        $evaluationCapital = $strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital());
        $addressableShare = $stock->isBankrupt() ? 0.0 : min(
            FinancialConstants::MAX_ADDRESSABLE_MARKET_SHARE,
            $this->corporateMetrics->calculateScaleRatio($evaluationCapital, $macroState->nominalGdpIndex, (float) $stock->getSamRatio())
        );

        // What that reach costs. The penalty is margin friction in return points; severity is the share of
        // the firm's return it eats; the marginal return is what the NEXT dollar of capital earns.
        $trueReturn = $strategy->getTrueReturn($stock);
        $saturationPenalty = $stock->isBankrupt() ? 0.0 : $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $macroState);

        return [
            'marketShare' => $addressableShare,
            'industry' => [
                'tracked' => $description !== null,
                'pricesCapacity' => $pricesCapacity,
                'substitutability' => $substitutability,
                'addressableShare' => $addressableShare,
                'saturation' => [
                    'threshold' => FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD,
                    'penalty' => $saturationPenalty,
                    'severity' => $this->corporateMetrics->calculateSaturationSeverity($saturationPenalty, $trueReturn),
                    'trueReturn' => $trueReturn,
                    'marginalReturn' => $stock->isBankrupt() ? 0.0 : $this->corporateMetrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, $evaluationCapital, $macroState),
                    'moatFactor' => FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()] ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'],
                ],
                // Only a share of something when there is someone else in the roster to share it with.
                'rosterShare' => $rosterSize > 1 ? $description['revenue_share'] : null,
                'peerShares' => $peerShares,
                'rosterSize' => $rosterSize,
                'capacityRatio' => $capacityRatio,
                'installedCapacity' => $description['installed_capacity'] ?? null,
                'trendCapacity' => $description['trend_capacity'] ?? null,
                'balance' => match (true) {
                    $capacityRatio === null => 'unknown',
                    $capacityRatio >= self::OVERBUILT_RATIO => 'overbuilt',
                    $capacityRatio <= self::TIGHT_RATIO => 'tight',
                    default => 'balanced',
                },
                'priceLevel' => isset($kpis['industry_price_level']) ? (float) $kpis['industry_price_level'] : null,
                'rivalShareDrain' => isset($kpis['rival_share_drain']) ? (float) $kpis['rival_share_drain'] : null,
                'ownPriceVolumeShift' => isset($kpis['own_price_volume_shift']) ? (float) $kpis['own_price_volume_shift'] : null,
            ],
        ];
    }

    /** The widest move the balance can make in a firm's realized price, for the page to scale a gauge by. */
    public static function maxPriceResponse(): float
    {
        return FinancialConstants::MAX_INDUSTRY_PRICE_RESPONSE;
    }
}

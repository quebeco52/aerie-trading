<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\IndustryMarkets;
use App\Data\Company\IndustryMoats;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Repository\StockRepository;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Deal\MergerReview;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;

/**
 * Where a company sits in its total addressable market: listed sellers and the unlisted competitive fringe
 * together, on one consistent denominator.
 *
 * Whole-market shares come from the industry ledger once filings have anchored the market (the same shares
 * MergerReview and M&A deal sizing read), and fall back before first filings to IndustryMarkets and current
 * addressable shares. When an industry does not sell into one shared market (Conglomerates with zero
 * substitutability across multiple firms), no shared market share or concentration index is struck.
 *
 * The ledger also supplies the roster's installed plant against its trend plant and the industry balance
 * those imply (the rest of the industry is a fringe supplying at trend); the last quarterly report supplies
 * what that did to the price. The page never strikes the balance itself: the ledger is written by the ticker
 * and only read here. The industry's outlook is the public's reading of where its long-run demand is
 * heading, never the truth behind it.
 */
class IndustryPositionBuilder
{
    // --- Balance Bands ---

    /** Capacity over trend demand at or above which the industry reads as overbuilt. */
    private const OVERBUILT_RATIO = 1.05;

    /** Capacity over trend demand at or below which the industry reads as tight. */
    private const TIGHT_RATIO = 0.95;

    /** The badge tone of each balance: both ends are a caution, read amber, since red is the colour of a fall. */
    private const BALANCE_TONES = ['overbuilt' => 'warn', 'tight' => 'warn', 'balanced' => 'accent', 'unknown' => 'neutral'];

    // --- Trailing Return ---

    /** Filings the trailing return averages: a year of quarters. */
    private const TRAILING_REPORTS = 4;

    public function __construct(
        private readonly IndustryShareLedger $ledger,
        private readonly CorporateMetrics $corporateMetrics,
        private readonly CorporateReportRepository $reports,
        private readonly \Redis $redis,
        private readonly int $ticksPerYear,
        private readonly ?StockRepository $stocks = null,
    ) {}

    /**
     * @return array{
     *     marketShare: float,
     *     industry: array{
     *         tracked: bool,
     *         pricesCapacity: bool,
     *         isFinancial: bool,
     *         sharedMarket: bool,
     *         addressableShare: float,
     *         saturation: array{threshold: float, penalty: float, severity: float, trueReturn: float, trailingReturn: ?float, marginalReturn: float, moat: array{label: string, spread: float}},
     *         marketSize: float|null,
     *         marketGdpShare: float|null,
     *         marketShares: array<string, float>,
     *         listedShare: float|null,
     *         fringeShare: float|null,
     *         herfindahl: float|null,
     *         highlyConcentrated: bool,
     *         concentrationLine: float,
     *         listedCount: int,
     *         capacityRatio: float|null,
     *         installedCapacity: float|null,
     *         trendCapacity: float|null,
     *         balance: string,
     *         balanceTone: string,
     *         priceMove: float|null,
     *         rivalShareDrain: float|null,
     *         ownPriceVolumeShift: float|null,
     *         idlePlant: float|null,
     *         outlook: array{level: float, doubt: float, revision: float|null}|null,
     *         mergerReview: array{herfindahl: float, highlyConcentrated: bool, acquirerShare: float, fringeShare: float, clearedShare: float, reviewBinds: bool, screens: array{delta: float, concentrated: float, shareCeiling: float}}|null
     *     }
     * }
     */
    public function build(Stock $stock, MacroStateDTO $macroState): array
    {
        $industry = (string) $stock->getIndustry();
        $strategy = Sectors::strategyFor($industry);
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
            $this->ticksPerYear,
        );

        $rawMergerMarket = $stock->isBankrupt() ? null : $this->ledger->describeMergerMarket(
            $stock,
            $macroState,
            IndustryShareLedger::secularExcessGrowth($strategy, $stock),
            $tick,
            $this->ticksPerYear,
        );

        $liveStocks = [];
        if (!$stock->isBankrupt()) {
            if ($this->stocks !== null && $industry !== '') {
                $seen = [];
                foreach ($this->stocks->findBy(['industry' => $industry]) as $candidate) {
                    if (!$candidate->isBankrupt()) {
                        $liveStocks[] = $candidate;
                        $seen[(string) $candidate->getTicker()] = true;
                    }
                }
                if (!isset($seen[(string) $stock->getTicker()])) {
                    $liveStocks[] = $stock;
                }
            } else {
                $liveStocks = [$stock];
            }
        }

        $structure = $this->resolveMarketStructure($industry, $liveStocks, $macroState, $rawMergerMarket);

        $kpis = $this->reports->findLatestFor($stock)?->getReportedKpis() ?? [];
        $capacityRatio = $description['capacity_ratio'] ?? null;

        // A lender's capital employed is funded by deposits and wholesale borrowing, so equity is the
        // base its reach is measured against; an industrial's is the capital it has put to work.
        $evaluationCapital = $strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital());
        $addressableShare = $stock->isBankrupt() ? 0.0 : min(
            FinancialConstants::MAX_ADDRESSABLE_MARKET_SHARE,
            $this->corporateMetrics->calculateAddressableShare($stock, $evaluationCapital, $macroState),
        );

        // What that reach costs. The penalty is margin friction in return points; severity is the share of
        // the firm's return it eats; the marginal return is what the NEXT dollar of capital earns.
        $trueReturn = $strategy->getTrueReturn($stock);
        // What the last four filings measured is what the page calls trailing. The model's own return is that pulled
        // toward what competition lets the business keep, and it stays the base the saturation figures are struck on.
        $filedReturns = [];
        foreach ($this->reports->findForStocksNewestFirst([$stock], self::TRAILING_REPORTS) as $report) {
            $filed = $strategy->isFinancial() ? $report->getReturnOnEquity() : $report->getRoic();
            if ($filed !== null) {
                $filedReturns[] = (float) $filed;
            }
        }
        $balance = match (true) {
            $capacityRatio === null => 'unknown',
            $capacityRatio >= self::OVERBUILT_RATIO => 'overbuilt',
            $capacityRatio <= self::TIGHT_RATIO => 'tight',
            default => 'balanced',
        };
        $saturationPenalty = $stock->isBankrupt() ? 0.0 : $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $macroState);

        return [
            'marketShare' => $addressableShare,
            'industry' => [
                'tracked' => $description !== null,
                'pricesCapacity' => $pricesCapacity,
                'isFinancial' => $strategy->isFinancial(),
                'sharedMarket' => $structure['sharedMarket'],
                'addressableShare' => $addressableShare,
                'saturation' => [
                    'threshold' => FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD,
                    'penalty' => $saturationPenalty,
                    'severity' => $this->corporateMetrics->calculateSaturationSeverity($saturationPenalty, $trueReturn),
                    'trueReturn' => $trueReturn,
                    'trailingReturn' => $filedReturns === [] ? null : array_sum($filedReturns) / count($filedReturns),
                    'marginalReturn' => $stock->isBankrupt() ? 0.0 : $this->corporateMetrics->calculateMarginalReturn($stock, $trueReturn, $evaluationCapital, $macroState),
                    'moat' => ['label' => IndustryMoats::forStock($stock)->label(), 'spread' => IndustryMoats::forStock($stock)->spread()],
                ],
                'marketSize' => $structure['marketSize'],
                'marketGdpShare' => $structure['marketGdpShare'],
                'marketShares' => $structure['shares'],
                'listedShare' => $structure['listedShare'],
                'fringeShare' => $structure['fringeShare'],
                'herfindahl' => $structure['herfindahl'],
                'highlyConcentrated' => $structure['highlyConcentrated'],
                'concentrationLine' => $structure['concentrationLine'],
                'listedCount' => $structure['listedCount'],
                'capacityRatio' => $capacityRatio,
                'installedCapacity' => $description['installed_capacity'] ?? null,
                'trendCapacity' => $description['trend_capacity'] ?? null,
                'balance' => $balance,
                'balanceTone' => self::BALANCE_TONES[$balance],
                // The realised price against a balanced industry's, as a move: 0.93 reads as 7% under.
                'priceMove' => isset($kpis['industry_price_level']) ? (float) $kpis['industry_price_level'] - 1.0 : null,
                'rivalShareDrain' => isset($kpis['rival_share_drain']) ? (float) $kpis['rival_share_drain'] : null,
                'ownPriceVolumeShift' => isset($kpis['own_price_volume_shift']) ? (float) $kpis['own_price_volume_shift'] : null,
                // The share of the company's plant a glut past the deepest price cut left standing, last report.
                'idlePlant' => isset($kpis['idle_plant_share']) ? (float) $kpis['idle_plant_share'] : null,
                // What the public expects of the industry's long-run demand; shared by every company in it.
                'outlook' => $this->ledger->describeOutlook($industry),
                'mergerReview' => $stock->isBankrupt() ? null : $this->mergerReview($macroState, $rawMergerMarket),
            ],
        ];
    }

    /**
     * Whole-market structure of one industry: total market size (listed sellers plus the unlisted competitive fringe),
     * each listed seller's share of that whole market, listed vs fringe split, and Herfindahl index across listed
     * sellers. Reads the industry ledger when priced, or falls back to IndustryMarkets and current addressable
     * shares before the first filings arrive.
     *
     * @param list<Stock> $liveStocks
     * @return array{
     *     sharedMarket: bool,
     *     marketSize: float|null,
     *     marketGdpShare: float|null,
     *     shares: array<string, float>,
     *     listedShare: float|null,
     *     fringeShare: float|null,
     *     herfindahl: float|null,
     *     highlyConcentrated: bool,
     *     concentrationLine: float,
     *     listedCount: int
     * }
     */
    public function industryMarketStructure(string $industry, array $liveStocks, MacroStateDTO $macroState): array
    {
        $liveStocks = array_values(array_filter($liveStocks, static fn(Stock $s): bool => !$s->isBankrupt()));
        $strategy = Sectors::strategyFor($industry);
        $sharedMarket = $industry !== '' && $liveStocks !== [] && ($strategy->getIndustrySubstitutability() > 0.0 || count($liveStocks) <= 1);
        $mergerMarket = null;
        if ($sharedMarket) {
            $tick = (int) ($this->redis->get('simulation_tick_count') ?: 0);
            foreach ($liveStocks as $candidate) {
                $candidateMarket = $this->ledger->describeMergerMarket(
                    $candidate,
                    $macroState,
                    IndustryShareLedger::secularExcessGrowth($strategy, $candidate),
                    $tick,
                    $this->ticksPerYear,
                );
                if ($candidateMarket !== null) {
                    $mergerMarket = $candidateMarket;
                    break;
                }
            }
        }

        return $this->resolveMarketStructure($industry, $liveStocks, $macroState, $mergerMarket);
    }

    /**
     * @param list<Stock> $liveStocks
     * @param array{market_revenue: float, acquirer_share: float, herfindahl: float, fringe_share: float, shares: array<string, float>}|null $mergerMarket
     * @return array{
     *     sharedMarket: bool,
     *     marketSize: float|null,
     *     marketGdpShare: float|null,
     *     shares: array<string, float>,
     *     listedShare: float|null,
     *     fringeShare: float|null,
     *     herfindahl: float|null,
     *     highlyConcentrated: bool,
     *     concentrationLine: float,
     *     listedCount: int
     * }
     */
    private function resolveMarketStructure(string $industry, array $liveStocks, MacroStateDTO $macroState, ?array $mergerMarket): array
    {
        $strategy = Sectors::strategyFor($industry);
        $concentrationLine = MergerReview::reviewScreens($macroState->mergerReviewLeniency)['concentrated'];
        $liveStocks = array_values(array_filter($liveStocks, static fn(Stock $s): bool => !$s->isBankrupt()));
        $sharedMarket = $industry !== '' && $liveStocks !== [] && ($strategy->getIndustrySubstitutability() > 0.0 || count($liveStocks) <= 1);

        if (!$sharedMarket) {
            return [
                'sharedMarket' => false,
                'marketSize' => null,
                'marketGdpShare' => null,
                'shares' => [],
                'listedShare' => null,
                'fringeShare' => null,
                'herfindahl' => null,
                'highlyConcentrated' => false,
                'concentrationLine' => $concentrationLine,
                'listedCount' => count($liveStocks),
            ];
        }

        if ($mergerMarket !== null) {
            $shares = [];
            foreach ($mergerMarket['shares'] as $recordKey => $share) {
                $ticker = SegmentBooks::ownerOf((string) $recordKey);
                $shares[$ticker] = ($shares[$ticker] ?? 0.0) + $share;
            }
            arsort($shares);
            $marketSize = $mergerMarket['market_revenue'] > 0.0 ? $mergerMarket['market_revenue'] : null;
            $listedShare = min(1.0, max(0.0, array_sum($shares)));
            $fringeShare = $mergerMarket['fringe_share'];
            $herfindahl = $mergerMarket['herfindahl'];
            $listedCount = count($shares);
        } else {
            $shares = [];
            foreach ($liveStocks as $member) {
                $evalCap = $strategy->getEvaluationCapital((float) $member->getTotalEquity(), $member->getInvestedCapital());
                $share = min(
                    FinancialConstants::MAX_ADDRESSABLE_MARKET_SHARE,
                    $this->corporateMetrics->calculateAddressableShare($member, $evalCap, $macroState),
                );
                if ($share > 0.0) {
                    $shares[(string) $member->getTicker()] = $share;
                }
            }
            arsort($shares);
            $totalListed = array_sum($shares);
            $listedShare = min(1.0, $totalListed);
            $fringeShare = max(0.0, 1.0 - $totalListed);
            $herfindahl = min(1.0, array_sum(array_map(static fn(float $s): float => $s * $s, $shares)));
            if (IndustryMarkets::hasIndustry($industry)) {
                $marketSize = IndustryMarkets::openingMarket($industry)
                    * $macroState->nominalGdpIndex
                    * CorporateMetrics::secularMarketGrowth($liveStocks[0], $macroState->totalTime);
            } else {
                $lead = $liveStocks[0];
                $leadRev = (float) $lead->getTotalRevenue();
                $leadShare = $shares[(string) $lead->getTicker()] ?? 0.0;
                $marketSize = $leadRev > 0.0 && $leadShare > 0.0 ? $leadRev / $leadShare : null;
            }
            $listedCount = count($liveStocks);
        }

        $nominalGdp = MacroConstants::DISTRICT_GDP_USD * $macroState->nominalGdpIndex;
        $marketGdpShare = $marketSize !== null && $nominalGdp > 0.0 ? $marketSize / $nominalGdp : null;
        $highlyConcentrated = $herfindahl > $concentrationLine;

        return [
            'sharedMarket' => true,
            'marketSize' => $marketSize,
            'marketGdpShare' => $marketGdpShare,
            'shares' => $shares,
            'listedShare' => $listedShare,
            'fringeShare' => $fringeShare,
            'herfindahl' => $herfindahl,
            'highlyConcentrated' => $highlyConcentrated,
            'concentrationLine' => $concentrationLine,
            'listedCount' => $listedCount,
        ];
    }

    /**
     * The market as horizontal merger review sees it, read from the same ledger call the M&A engine sizes
     * its deals on (AcquisitionEngine::resolveLargestAvailableTarget): the roster's concentration,
     * the competitive fringe still off the board, and the largest share a deal could add, the lesser of
     * that fringe and what clears the Fair Trading Board's screens in force, which it returns too. Null
     * before the ledger has priced the firm's market, which is also when the engine applies no review.
     *
     * @param array{market_revenue: float, acquirer_share: float, herfindahl: float, fringe_share: float, shares: array<string, float>}|null $market
     * @return array{herfindahl: float, highlyConcentrated: bool, acquirerShare: float, fringeShare: float, clearedShare: float, reviewBinds: bool, screens: array{delta: float, concentrated: float, shareCeiling: float}}|null
     */
    private function mergerReview(MacroStateDTO $macroState, ?array $market): ?array
    {
        if ($market === null) {
            return null;
        }

        $reviewCap = MergerReview::maxClearedTargetShare($market['acquirer_share'], $market['herfindahl'], $macroState->mergerReviewLeniency);
        $screens = MergerReview::reviewScreens($macroState->mergerReviewLeniency);

        return [
            'herfindahl' => $market['herfindahl'],
            'highlyConcentrated' => $market['herfindahl'] > $screens['concentrated'],
            'acquirerShare' => $market['acquirer_share'],
            'fringeShare' => $market['fringe_share'],
            'clearedShare' => min($market['fringe_share'], $reviewCap),
            'reviewBinds' => $reviewCap < $market['fringe_share'],
            'screens' => $screens,
        ];
    }
}

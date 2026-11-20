<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\FinancialBooks;
use App\Data\Company\IndustryMarkets;
use App\Data\Company\IndustryMoats;
use App\Data\Company\Sectors;
use App\Data\Company\Segments;
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
     *         hasFinancialBooks: bool,
     *         sharedMarket: bool,
     *         addressableShare: float,
     *         saturation: array{threshold: float, penalty: float, severity: float, trueReturn: float, trailingReturn: ?float, marginalReturn: float, moat: array{label: string, spread: float}},
     *         marketSize: float|null,
     *         marketGdpShare: float|null,
     *         marketShares: array<string, float>,
     *         marketShareByIndustry: array<string, float>,
     *         otherMarkets: list<array{industry: string, slug: string, label: string, isFinancial: bool, marketSize: float|null, marketGdpShare: float|null, marketShares: array<string, float>, ownShare: float|null, fringeShare: float|null, herfindahl: float|null, highlyConcentrated: bool, concentrationLine: float}>,
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
        $otherMarkets = $stock->isBankrupt() ? [] : $this->otherMarkets($stock, $macroState, $tick);
        $marketShareByIndustry = [];
        $ticker = (string) $stock->getTicker();
        if ($structure['sharedMarket'] && isset($structure['shares'][$ticker])) {
            $marketShareByIndustry[$industry] = $structure['shares'][$ticker];
        }
        foreach ($otherMarkets as $other) {
            if ($other['ownShare'] !== null) {
                $marketShareByIndustry[$other['industry']] = $other['ownShare'];
            }
        }

        return [
            'marketShare' => $addressableShare,
            'industry' => [
                'tracked' => $description !== null,
                'pricesCapacity' => $pricesCapacity,
                'isFinancial' => $strategy->isFinancial(),
                'hasFinancialBooks' => FinancialBooks::hasBooks($stock),
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
                'marketShareByIndustry' => $marketShareByIndustry,
                'otherMarkets' => $otherMarkets,
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
     * Whole-market structure for an industry whose listed sellers operate there only through segments (no listed firm
     * has it as its main industry).
     *
     * @param list<array{stock: Stock, segment: string}> $sellers
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
    public function segmentIndustryMarketStructure(string $industry, array $sellers, MacroStateDTO $macroState): array
    {
        $strategy = Sectors::strategyFor($industry);
        $concentrationLine = MergerReview::reviewScreens($macroState->mergerReviewLeniency)['concentrated'];
        $tick = (int) ($this->redis->get('simulation_tick_count') ?: 0);

        $mergerMarket = null;
        foreach ($sellers as $seller) {
            $candidateMarket = $this->ledger->describeMergerMarket(
                $seller['stock'],
                $macroState,
                IndustryShareLedger::secularExcessGrowth($strategy, $seller['stock']),
                $tick,
                $this->ticksPerYear,
                $seller['segment'],
            );
            if ($candidateMarket !== null) {
                $mergerMarket = $candidateMarket;
                break;
            }
        }

        if ($mergerMarket === null) {
            return [
                'sharedMarket' => true,
                'marketSize' => null,
                'marketGdpShare' => null,
                'shares' => [],
                'listedShare' => null,
                'fringeShare' => null,
                'herfindahl' => null,
                'highlyConcentrated' => false,
                'concentrationLine' => $concentrationLine,
                'listedCount' => count($sellers),
            ];
        }

        return $this->structureFromLedger($mergerMarket, $macroState, $concentrationLine);
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
            return $this->structureFromLedger($mergerMarket, $macroState, $concentrationLine);
        }

        $trendGdp = IndustryShareLedger::trendNominalGdp($macroState);
        $lead = $liveStocks[0];
        $marketSize = IndustryMarkets::hasIndustry($industry)
            ? IndustryMarkets::openingMarket($industry) * $trendGdp * CorporateMetrics::secularMarketGrowth($lead, $macroState->totalTime)
            : null;

        $shares = [];
        foreach ($liveStocks as $member) {
            $ticker = (string) $member->getTicker();
            if ($ticker === '') {
                continue;
            }
            if ($marketSize !== null && $marketSize > 0.0 && FinancialBooks::hasBooks($member)) {
                $mainWeight = FinancialBooks::shares($member)[FinancialBooks::MAIN] ?? 1.0;
                $share = min(1.0, ((float) $member->getTotalRevenue() * $mainWeight) / $marketSize);
            } else {
                $evalCap = $strategy->getEvaluationCapital((float) $member->getTotalEquity(), $member->getInvestedCapital());
                $share = min(
                    FinancialConstants::MAX_ADDRESSABLE_MARKET_SHARE,
                    $this->corporateMetrics->calculateAddressableShare($member, $evalCap, $macroState),
                );
            }
            if ($share > 0.0) {
                $shares[$ticker] = $share;
            }
        }

        if ($this->stocks !== null && $marketSize !== null && $marketSize > 0.0) {
            $shares = $this->addFinancialBookSellers($industry, $marketSize, $shares);
        }

        arsort($shares);
        $totalListed = array_sum($shares);
        $listedShare = min(1.0, $totalListed);
        $fringeShare = max(0.0, 1.0 - $totalListed);
        $herfindahl = min(1.0, array_sum(array_map(static fn(float $s): float => $s * $s, $shares)));
        if ($marketSize === null) {
            $leadRev = (float) $lead->getTotalRevenue();
            $leadShare = $shares[(string) $lead->getTicker()] ?? 0.0;
            $marketSize = $leadRev > 0.0 && $leadShare > 0.0 ? $leadRev / $leadShare : null;
        }

        return $this->formatMarketStructure(
            $marketSize,
            $shares,
            $listedShare,
            $fringeShare,
            $herfindahl,
            $trendGdp,
            $concentrationLine,
            $shares !== [] ? count($shares) : count($liveStocks),
        );
    }

    /**
     * @param array{market_revenue: float, acquirer_share: float, herfindahl: float, fringe_share: float, shares: array<string, float>} $mergerMarket
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
    private function structureFromLedger(array $mergerMarket, MacroStateDTO $macroState, float $concentrationLine): array
    {
        $shares = [];
        foreach ($mergerMarket['shares'] as $recordKey => $share) {
            $ticker = SegmentBooks::ownerOf((string) $recordKey);
            $shares[$ticker] = ($shares[$ticker] ?? 0.0) + $share;
        }
        arsort($shares);
        $marketSize = $mergerMarket['market_revenue'] > 0.0 ? $mergerMarket['market_revenue'] : null;

        return $this->formatMarketStructure(
            $marketSize,
            $shares,
            min(1.0, max(0.0, array_sum($shares))),
            $mergerMarket['fringe_share'],
            $mergerMarket['herfindahl'],
            IndustryShareLedger::trendNominalGdp($macroState),
            $concentrationLine,
            count($shares),
        );
    }

    /**
     * @param array<string, float> $shares
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
    private function formatMarketStructure(
        ?float $marketSize,
        array $shares,
        float $listedShare,
        float $fringeShare,
        float $herfindahl,
        float $trendGdpIndex,
        float $concentrationLine,
        int $listedCount,
    ): array {
        $nominalGdp = MacroConstants::DISTRICT_GDP_USD * $trendGdpIndex;
        $marketGdpShare = $marketSize !== null && $nominalGdp > 0.0 ? $marketSize / $nominalGdp : null;

        return [
            'sharedMarket' => true,
            'marketSize' => $marketSize,
            'marketGdpShare' => $marketGdpShare,
            'shares' => $shares,
            'listedShare' => $listedShare,
            'fringeShare' => $fringeShare,
            'herfindahl' => $herfindahl,
            'highlyConcentrated' => $herfindahl > $concentrationLine,
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

    /**
     * Secondary markets this company sells into via FinancialBooks or held SegmentBooks, each with whole-market share
     * breakdown (rivals and unlisted fringe) and HHI.
     *
     * @return list<array{industry: string, slug: string, label: string, isFinancial: bool, marketSize: float|null, marketGdpShare: float|null, marketShares: array<string, float>, ownShare: float|null, fringeShare: float|null, herfindahl: float|null, highlyConcentrated: bool, concentrationLine: float}>
     */
    private function otherMarkets(Stock $stock, MacroStateDTO $macroState, int $tick): array
    {
        $secondary = [];
        if (FinancialBooks::hasBooks($stock)) {
            foreach (FinancialBooks::industries($stock) as $book => $targetIndustry) {
                if ($book !== FinancialBooks::MAIN) {
                    $secondary[$book] = ['industry' => $targetIndustry, 'label' => FinancialBooks::label($stock, $book)];
                }
            }
        } elseif (SegmentBooks::keepsBooks($stock)) {
            $hasPlant = SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []) !== null;
            foreach (SegmentBooks::industries($stock) as $segment => $targetIndustry) {
                if ($segment === Segments::MAIN || ($hasPlant && !SegmentBooks::holdsPlant($stock, $segment))) {
                    continue;
                }
                $secondary[$segment] = ['industry' => $targetIndustry, 'label' => SegmentBooks::label($stock, $segment)];
            }
        }
        if ($secondary === []) {
            return [];
        }

        $ticker = (string) $stock->getTicker();
        $concentrationLine = MergerReview::reviewScreens($macroState->mergerReviewLeniency)['concentrated'];
        $markets = [];
        foreach ($secondary as $key => ['industry' => $targetIndustry, 'label' => $label]) {
            $targetStrategy = Sectors::strategyFor($targetIndustry);
            $mergerMarket = $this->ledger->describeMergerMarket(
                $stock,
                $macroState,
                IndustryShareLedger::secularExcessGrowth($targetStrategy, $stock),
                $tick,
                $this->ticksPerYear,
                $key,
            );
            $otherStructure = null;
            if ($mergerMarket !== null) {
                $otherStructure = $this->structureFromLedger($mergerMarket, $macroState, $concentrationLine);
            } elseif (FinancialBooks::hasBooks($stock) && $this->stocks !== null) {
                $livePeers = array_values(array_filter(
                    $this->stocks->findBy(['industry' => $targetIndustry]),
                    static fn(Stock $s): bool => !$s->isBankrupt(),
                ));
                if ($livePeers !== []) {
                    $otherStructure = $this->industryMarketStructure($targetIndustry, $livePeers, $macroState);
                } elseif (IndustryMarkets::hasIndustry($targetIndustry)) {
                    $trendGdp = IndustryShareLedger::trendNominalGdp($macroState);
                    $marketSize = IndustryMarkets::openingMarket($targetIndustry)
                        * $trendGdp
                        * CorporateMetrics::secularMarketGrowth($stock, $macroState->totalTime);
                    $shares = $marketSize > 0.0 ? $this->addFinancialBookSellers($targetIndustry, $marketSize, []) : [];
                    arsort($shares);
                    $totalListed = array_sum($shares);
                    $otherStructure = $this->formatMarketStructure(
                        $marketSize > 0.0 ? $marketSize : null,
                        $shares,
                        min(1.0, $totalListed),
                        max(0.0, 1.0 - $totalListed),
                        min(1.0, array_sum(array_map(static fn(float $s): float => $s * $s, $shares))),
                        $trendGdp,
                        $concentrationLine,
                        count($shares),
                    );
                }
            }

            if ($otherStructure === null || !$otherStructure['sharedMarket'] || !isset($otherStructure['shares'][$ticker])) {
                continue;
            }
            $markets[] = [
                'industry' => $targetIndustry,
                'slug' => Sectors::industrySlug($targetIndustry),
                'label' => $label,
                'isFinancial' => $targetStrategy->isFinancial(),
                'marketSize' => $otherStructure['marketSize'],
                'marketGdpShare' => $otherStructure['marketGdpShare'],
                'marketShares' => $otherStructure['shares'],
                'ownShare' => $otherStructure['shares'][$ticker],
                'fringeShare' => $otherStructure['fringeShare'],
                'herfindahl' => $otherStructure['herfindahl'],
                'highlyConcentrated' => $otherStructure['highlyConcentrated'],
                'concentrationLine' => $otherStructure['concentrationLine'],
            ];
        }

        return $markets;
    }

    /**
     * @param array<string, float> $shares
     * @return array<string, float>
     */
    private function addFinancialBookSellers(string $industry, float $marketSize, array $shares): array
    {
        if ($this->stocks === null || $marketSize <= 0.0) {
            return $shares;
        }
        foreach (FinancialBooks::sellersForIndustry($industry) as ['ticker' => $sellerTicker, 'book' => $book]) {
            $seller = $this->stocks->findOneByTicker($sellerTicker);
            if ($seller === null || $seller->isBankrupt() || isset($shares[$sellerTicker])) {
                continue;
            }
            $weight = FinancialBooks::shares($seller)[$book] ?? 0.0;
            $share = min(1.0, ((float) $seller->getTotalRevenue() * $weight) / $marketSize);
            if ($share > 0.0) {
                $shares[$sellerTicker] = $share;
            }
        }

        return $shares;
    }
}

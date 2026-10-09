<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\CompanyResearch;
use App\Data\LifecycleStage;
use App\Data\DistrictCalendar;
use App\Data\StockInfo;
use App\Data\StrategicHoldings;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Repository\BondRepository;
use App\Repository\EtfEventRepository;
use App\Repository\StockEventRepository;
use App\Service\Corporate\EarningsEngine;
use App\Service\Macro\MacroStateProvider;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Chart\PriceChangeFeed;
use App\Service\Market\Trading\SecuritiesLendingDesk;
use App\Service\Math\FinancialConstants;

/**
 * Assembles everything the instrument page renders, for a listed company or for the index fund.
 *
 * This was a controller action of some three hundred lines with eleven services injected into it,
 * which meant the page's composition could only be read by reading the whole of it, and none of it
 * could be exercised without a request. Each block now has a builder that states what it is for;
 * this one decides which blocks a given instrument has.
 */
class StockPageBuilder
{
    // --- Page Composition ---

    /** Filings and announcements listed on the page before the history is truncated. */
    private const EVENT_ROWS = 15;

    // --- Borrow Warnings ---

    /** Borrow fee above which the panel flags the name as expensive to short (~5%/yr; general collateral is ~0.3%). */
    public const EXPENSIVE_BORROW_FEE = 0.05;

    /** Utilization of the lendable supply at which the panel warns that the borrow is running out. */
    public const SCARCE_BORROW_UTILIZATION = 0.90;

    public function __construct(
        private readonly MacroStateProvider $macroStateProvider,
        private readonly CompanySnapshotBuilder $companySnapshot,
        private readonly EtfCompositionBuilder $etfComposition,
        private readonly PeerTableBuilder $peerTable,
        private readonly AnchorPortfolioBuilder $anchorPortfolio,
        private readonly IndustryPositionBuilder $industryPosition,
        private readonly ViewerPositionBuilder $viewerPosition,
        private readonly StockEventRepository $stockEvents,
        private readonly EtfEventRepository $etfEvents,
        private readonly PriceChangeFeed $priceChangeFeed,
        private readonly LiquidityEngine $liquidityEngine,
        private readonly SecuritiesLendingDesk $lendingDesk,
        private readonly OptionChainBuilder $optionChain,
        private readonly CreditHealthBuilder $creditHealth,
        private readonly FinancialSummaryBuilder $financialSummary,
        private readonly BiotechPipelineBuilder $biotechPipeline,
        private readonly int $ticksPerYear,
        private readonly ?BondRepository $bonds = null,
        private readonly ?\Redis $redis = null,
    ) {}

    /**
     * @return array<string, mixed> The template payload for stock/index.html.twig.
     */
    public function build(Stock|Etf $asset, string $ticker, ?User $viewer): array
    {
        $isEtf = $asset instanceof Etf;
        $macroState = $this->macroStateProvider->liveState();
        $this->liquidityEngine->setStampDutyRate($macroState->stampDutyRate);

        // A fund carries no bankruptcy flag, so only a company can be delisted.
        $isDelisted = !$isEtf && $asset->isBankrupt();

        $payload = [
            'asset' => $asset,
            'isEtf' => $isEtf,
            // Null rather than a flat 0.00% when the ticker has no usable buffered history, so the
            // header prints the change as unknown instead of as a day that did not move.
            'changePercent' => $isDelisted ? null : $this->priceChangeFeed->changeForTicker($ticker, (float) $asset->getPrice()),
            // The lore copy, as the district map reads it, so an edit shows without a reseed; the seeded column covers
            // a listing the lore does not know.
            'generalInfo' => StockInfo::DESCRIPTIONS[$ticker] ?? $asset->getDescription(),
            'hasProfile' => CompanyResearch::for($ticker) !== null,
            'events' => $isEtf
                ? $this->etfEvents->findRecentFor($asset, self::EVENT_ROWS)
                : $this->stockEvents->findRecentFor($asset, self::EVENT_ROWS),
            'ticksPerYear' => $this->ticksPerYear,
            'macro' => $macroState,
            'lifecycleStages' => LifecycleStage::cases(),
        ];

        $payload += $this->viewerPosition->build($asset, $ticker, $viewer);
        $payload += $isEtf
            ? $this->fundBlocks($asset)
            : $this->companyBlocks($asset, $macroState) + $this->optionChain->build($asset, $viewer, $macroState);

        return $payload;
    }

    /**
     * The blocks only a listed company has: its valuation, its peers, and the cost of trading it.
     *
     * @return array<string, mixed>
     */
    private function companyBlocks(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $corporateBonds = [];
        if ($this->bonds !== null && !$stock->isBankrupt()) {
            foreach ($this->bonds->findActiveByIssuer($stock) as $bond) {
                $corporateBonds[] = [
                    'ticker' => $bond->getTicker(),
                    'name' => $bond->getName(),
                    'couponRate' => (float) $bond->getCouponRate(),
                    'cleanPrice' => (float) $bond->getCleanPrice(),
                    'yieldToMaturity' => (float) $bond->getYieldToMaturity(),
                    'modifiedDuration' => (float) $bond->getModifiedDuration(),
                    'maturesAtTime' => (float) $bond->getMaturesAtTime(),
                    'yearsToMaturity' => $bond->yearsToMaturity($macroState->totalTime),
                ];
            }
        }

        return $this->companySnapshot->build($stock, $macroState)
            + $this->industryPosition->build($stock, $macroState)
            + $this->creditHealth->build($stock, $macroState)
            + $this->financialSummary->build($stock)
            + $this->biotechPipeline->build($stock)
            + [
            'peers' => $this->peerTable->build($stock),
            // What a permanent-capital sphere actually owns; null for every firm that owns no stakes.
            'anchorPortfolio' => $this->anchorPortfolio->build($stock),
            // The District's own stake in this company, held off the float; zero for every company but its clearinghouse.
            'strategicStake' => StrategicHoldings::stake($stock->getTicker()),
            'corporateBonds' => $corporateBonds,
            'allAssets' => [],
            'pieLabels' => [],
            'pieData' => [],
            'sharesMap' => [],
            'components' => [],
            'indexFacts' => null,
            // Depth and the cost of crossing it. Shown because a page that quotes a price without
            // saying what size costs is only telling half of what a trade is going to do.
            'advShares' => $this->liquidityEngine->averageDailyVolume($stock),
            'halfSpread' => $this->liquidityEngine->halfSpreadFraction($stock),
            // What it costs to be short this name, and how much of it is left to borrow.
            'borrowFee' => $this->lendingDesk->borrowFee($stock),
            'availableToBorrow' => $this->lendingDesk->availableToBorrow($stock),
            'shortUtilization' => $this->lendingDesk->utilization($stock),
            'borrowWarnings' => self::borrowWarnings(),
            'shortInterest' => $stock->isBankrupt() ? null : $this->shortInterest($stock),
            'nextReport' => $stock->isBankrupt() ? null : $this->nextReport($stock, $macroState),
            'management' => self::managementSummary($stock),
        ];
    }

    /**
     * Shares sold short, against the float and against what trades in a day (days to cover), the two ways an
     * exchange's short-interest report states it.
     *
     * @return array{shares: float, floatShare: float|null, daysToCover: float|null}
     */
    private function shortInterest(Stock $stock): array
    {
        $shorted = (float) $stock->getShortInterestShares();
        $floatShares = (float) $stock->getSharesOutstanding() * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));
        $adv = $this->liquidityEngine->averageDailyVolume($stock);

        return [
            'shares' => $shorted,
            'floatShare' => $floatShares > 0.0 ? $shorted / $floatShares : null,
            'daysToCover' => $adv > 0.0 ? $shorted / $adv : null,
        ];
    }

    /**
     * The date of the company's next results and the quarter they will cover (ReportCalendar).
     *
     * @return array{dateline: string, quarter: string}|null Null where the tick counter cannot be read.
     */
    private function nextReport(Stock $stock, \App\DTO\MacroStateDTO $macroState): ?array
    {
        if ($this->redis === null) {
            return null;
        }

        $time = ReportCalendar::nextReportTime(
            (string) $stock->getTicker(),
            (int) ($this->redis->get('simulation_tick_count') ?: 0),
            $this->ticksPerYear,
            $macroState->totalTime
        );

        return [
            'dateline' => DistrictCalendar::dateline($time),
            'quarter' => DistrictCalendar::quarter($time - EarningsEngine::REPORT_INTERVAL_YEARS),
        ];
    }

    /** @return array{expensiveFee: float, scarceUtilization: float, recallUtilization: float} */
    private static function borrowWarnings(): array
    {
        return [
            'expensiveFee' => self::EXPENSIVE_BORROW_FEE,
            'scarceUtilization' => self::SCARCE_BORROW_UTILIZATION,
            'recallUtilization' => FinancialConstants::BUY_IN_UTILIZATION_THRESHOLD,
        ];
    }

    /**
     * Who is running the company, and for how long.
     *
     * The archetype and how firmly it is held are shown; the dials behind them are not. A player is meant
     * to read the policy off the firm's behaviour — the payout, the capital budget, the deals — and this
     * card only says what kind of manager to expect it from, which is what a market already knows about a
     * sitting chief executive.
     *
     * @return array<string, mixed>
     */
    public static function managementSummary(Stock $stock): array
    {
        $profile = $stock->getManagementProfile();

        return [
            'style' => $profile->style->value,
            'label' => $profile->style->label(),
            'mandate' => $profile->style->mandate(),
            'conviction' => $profile->convictionLabel(),
            'tenureYears' => $stock->getCeoTenureYears(),
        ];
    }

    /**
     * The blocks only the fund has: what it holds, in what weight, and what it costs to hold.
     *
     * The fund is not borrowable and is not quoted against a company's own book, so the trading-cost
     * readings stand down to the fund's fixed spread rather than being computed from a share count.
     *
     * @return array<string, mixed>
     */
    private function fundBlocks(Etf $fund): array
    {
        // A fund is the vehicle for one published index; a ticker the enum does not know is the whole board.
        $index = MarketIndex::tryFrom((string) $fund->getTicker()) ?? MarketIndex::Composite;

        return $this->etfComposition->build($index, $fund) + [
            'isFinancial' => false,
            'isInsurer' => false,
            'businessModel' => 'none',
            'marketCap' => 0.0,
            'peRatio' => null,
            'marketShare' => 0.0,
            'industry' => null,
            'lifecycleStage' => null,
            // The fund's own yield, and a real one: what it has actually paid out over the trailing year
            // out of the dividends its constituents paid it. A price index has no yield; a fund holding
            // the basket does, and reporting zero was the visible face of it keeping the cash.
            'dividendYield' => (float) $fund->getPrice() > 0.0
                ? $fund->trailingDistribution() / (float) $fund->getPrice()
                : 0.0,
            'analystTargets' => null,
            'netAssetValue' => null,
            'capitalThresholds' => null,
            'capital' => null,
            'creditHealth' => null,
            'financialSummary' => [],
            'peers' => [],
            'anchorPortfolio' => null,
            'strategicStake' => 0.0,
            'corporateBonds' => [],
            'advShares' => 0.0,
            'halfSpread' => FinancialConstants::ETF_HALF_SPREAD,
            'borrowFee' => 0.0,
            'availableToBorrow' => 0.0,
            'shortUtilization' => 0.0,
            'borrowWarnings' => self::borrowWarnings(),
            'shortInterest' => null,
            'nextReport' => null,
            'kpiSeries' => [],
            'pipeline' => null,
            'management' => null,
            // The fund carries no class of its own: contracts are written on companies here, not on the
            // index, so the panel stands down rather than rendering an empty ladder.
            'optionsListed' => false,
            'optionsReason' => 'Contracts are written on listed companies, not on the index fund.',
            'optionExpiries' => [],
            'optionDealerGamma' => 0.0,
            'optionDealerGammaPerPercent' => 0.0,
            'optionOpenInterest' => 0,
            'optionMultiplier' => FinancialConstants::OPTION_CONTRACT_MULTIPLIER,
        ];
    }
}

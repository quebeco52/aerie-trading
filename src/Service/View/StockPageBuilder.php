<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\LifecycleStage;
use App\Data\StockInfo;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Repository\EtfEventRepository;
use App\Repository\StockEventRepository;
use App\Service\Macro\MacroStateProvider;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\PriceChangeFeed;
use App\Service\Market\SecuritiesLendingDesk;
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

    public function __construct(
        private readonly MacroStateProvider $macroStateProvider,
        private readonly CompanySnapshotBuilder $companySnapshot,
        private readonly EtfCompositionBuilder $etfComposition,
        private readonly PeerTableBuilder $peerTable,
        private readonly ViewerPositionBuilder $viewerPosition,
        private readonly StockEventRepository $stockEvents,
        private readonly EtfEventRepository $etfEvents,
        private readonly PriceChangeFeed $priceChangeFeed,
        private readonly LiquidityEngine $liquidityEngine,
        private readonly SecuritiesLendingDesk $lendingDesk,
        private readonly int $ticksPerYear,
    ) {}

    /**
     * @return array<string, mixed> The template payload for stock/index.html.twig.
     */
    public function build(Stock|Etf $asset, string $ticker, ?User $viewer): array
    {
        $isEtf = $asset instanceof Etf;
        $macroState = $this->macroStateProvider->liveState();

        // A fund carries no bankruptcy flag, so only a company can be delisted.
        $isDelisted = !$isEtf && $asset->isBankrupt();

        $payload = [
            'asset' => $asset,
            'isEtf' => $isEtf,
            // Null rather than a flat 0.00% when the ticker has no usable buffered history, so the
            // header prints the change as unknown instead of as a day that did not move.
            'changePercent' => $isDelisted ? null : $this->priceChangeFeed->changeForTicker($ticker, (float) $asset->getPrice()),
            'generalInfo' => $asset->getDescription(),
            'quote' => StockInfo::getQuote($ticker),
            'events' => $isEtf
                ? $this->etfEvents->findRecentFor($asset, self::EVENT_ROWS)
                : $this->stockEvents->findRecentFor($asset, self::EVENT_ROWS),
            'ticksPerYear' => $this->ticksPerYear,
            'economic_cycle' => $macroState->economicCycleLabel(),
            'macro' => $macroState,
            'lifecycleStages' => LifecycleStage::cases(),
        ];

        $payload += $this->viewerPosition->build($asset, $ticker, $viewer);
        $payload += $isEtf ? $this->fundBlocks($asset) : $this->companyBlocks($asset, $macroState);

        return $payload;
    }

    /**
     * The blocks only a listed company has: its valuation, its peers, and the cost of trading it.
     *
     * @return array<string, mixed>
     */
    private function companyBlocks(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        return $this->companySnapshot->build($stock, $macroState) + [
            'peers' => $this->peerTable->build($stock),
            'allAssets' => [],
            'pieLabels' => [],
            'pieData' => [],
            'sharesMap' => [],
            'components' => [],
            // Depth and the cost of crossing it. Shown because a page that quotes a price without
            // saying what size costs is only telling half of what a trade is going to do.
            'advShares' => $this->liquidityEngine->averageDailyVolume($stock),
            'halfSpread' => $this->liquidityEngine->halfSpreadFraction($stock),
            // What it costs to be short this name, and how much of it is left to borrow.
            'borrowFee' => $this->lendingDesk->borrowFee($stock),
            'availableToBorrow' => $this->lendingDesk->availableToBorrow($stock),
            'shortUtilization' => $this->lendingDesk->utilization($stock),
        ];
    }

    /**
     * The blocks only the fund has: what it holds, and in what weight.
     *
     * The fund is not borrowable and is not quoted against a company's own book, so the trading-cost
     * readings stand down to the fund's fixed spread rather than being computed from a share count.
     *
     * @return array<string, mixed>
     */
    private function fundBlocks(Etf $etf): array
    {
        return $this->etfComposition->build() + [
            'isFinancial' => false,
            'businessModel' => 'none',
            'marketCap' => 0.0,
            'peRatio' => null,
            'targetPE' => 20.00,
            'investedCapital' => 0.0,
            'marketShare' => 0.0,
            'lifecycleStage' => null,
            'dividendYield' => 0.0,
            'analystTargets' => null,
            'peers' => [],
            'advShares' => 0.0,
            'halfSpread' => FinancialConstants::ETF_HALF_SPREAD,
            'borrowFee' => 0.0,
            'availableToBorrow' => 0.0,
            'shortUtilization' => 0.0,
        ];
    }
}

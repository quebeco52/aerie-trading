<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Stock;
use App\Repository\EtfRepository;
use App\Repository\StockRepository;
use App\Service\Macro\MacroStateProvider;
use App\Service\Market\Chart\PriceChangeFeed;
use App\Service\Market\Index\MarketIndex;
use App\Service\Math\MacroConstants;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller responsible for the main landing page and market dashboard.
 */
class HomeController extends AbstractController
{
    /**
     * Displays the main landing page and market dashboard.
     *
     * Fetches the primary market ETF and a list of all available stocks,
     * calculates their current market capitalization, and sorts them from largest to smallest.
     *
     * The macro vitals come through MacroStateProvider rather than off the engine, so this page and
     * /economy describe the same economy: the engine's own cold-Redis opening is its simulation seed and
     * differs from the snapshot openings on 28 fields, which is the drift the provider exists to end.
     *
     * @param StockRepository $stockRepository The listed companies shown on the board.
     *
     * @return Response Returns the rendered home page view with market data.
     */
    #[Route('/', name: 'app_home')]
    public function index(StockRepository $stockRepository, EtfRepository $etfs, MacroStateProvider $macroStateProvider, PriceChangeFeed $priceChangeFeed): Response
    {
        $stocks = $stockRepository->findAll();
        $macroState = $macroStateProvider->liveState();

        $marketData = $this->buildBaseMarketData($stocks, $priceChangeFeed->changeByTicker($stocks));
        usort($marketData, function ($a, $b) {
            if ($a['isBankrupt'] !== $b['isBankrupt']) {
                return $a['isBankrupt'] ? 1 : -1;
            }
            return $b['marketCap'] <=> $a['marketCap'];
        });

        // If the user is not logged in, render a dedicated landing page
        if (!$this->getUser()) {
            return $this->render('home/landing.html.twig', [
                'etf'        => $etfs->findOneByTicker(MarketIndex::benchmark()->value),
                'top_stocks' => array_slice($marketData, 0, 6), // Show a preview of the top 6 stocks
                'breadth'    => $this->buildBreadth($marketData),
                'macro'      => $macroState->toArray(),
                'cycleLabel' => $macroState->economicCycleLabel(),
            ]);
        }

        return $this->render('home/index.html.twig', [
            'indices' => $this->indexTiles($etfs),
            'stocks'  => $marketData,
            'macro'   => $macroState->toArray(),
            'cycleLabel' => $macroState->economicCycleLabel(),
            'inflationTarget' => MacroConstants::TARGET_INFLATION,
        ]);
    }

    /**
     * The published indices as the board quotes them, benchmark first, each with the fund it trades as.
     *
     * @return list<array{index: MarketIndex, ticker: string, name: string, price: float, label: string, isBenchmark: bool}>
     */
    private function indexTiles(EtfRepository $etfs): array
    {
        $tiles = [];
        foreach (MarketIndex::cases() as $index) {
            $fund = $etfs->findOneByTicker($index->value);
            if ($fund === null) {
                continue;
            }

            $tiles[] = [
                'index' => $index,
                'ticker' => $index->value,
                'name' => (string) $fund->getName(),
                'price' => (float) $fund->getPrice(),
                'label' => $index->label(),
                'isBenchmark' => $index === MarketIndex::benchmark(),
            ];
        }

        return $tiles;
    }

    /**
     * Builds the base market data array shared across page and API endpoints.
     *
     * @param Stock[] $stocks
     * @param array<string, float> $changes ticker => fractional change, absent when unbuffered
     * @return array<int, array{ticker: string, name: string, sector: string, price: float, shares: float, marketCap: float, currentRoic: float, isBankrupt: bool, changePercent: float|null}>
     */
    private function buildBaseMarketData(array $stocks, array $changes = []): array
    {
        $marketData = [];
        foreach ($stocks as $stock) {
            $price     = (float) $stock->getPrice();
            $shares    = (float) $stock->getSharesOutstanding();
            $marketCap = $price * $shares;

            $businessModel = \App\Data\Company\Sectors::businessModelFor($stock->getIndustry());
            $isFinancial   = \App\Data\Company\Sectors::isFinancial($businessModel);
            $effectiveRoic = $isFinancial
                ? ((float) $stock->getCurrentRoe() ?: (float) $stock->getBaselineRoe())
                : ((float) $stock->getCurrentRoic() ?: (float) $stock->getBaselineRoic());

            $marketData[] = [
                'ticker'      => $stock->getTicker(),
                'name'        => $stock->getName(),
                'sector'      => $stock->getSector(),
                'price'       => $price,
                'shares'      => $shares,
                'marketCap'   => $marketCap,
                'currentRoic' => $effectiveRoic,
                'isBankrupt'  => $stock->isBankrupt(),
                // Null, not zero: "no history buffered yet" and "has not moved" are different
                // facts, and the table prints them differently.
                'changePercent' => $changes[$stock->getTicker()] ?? null,
            ];
        }
        return $marketData;
    }

    /**
     * Summarises the session for the signed-out landing page: how many names are up, how many
     * are down, and the single largest move either way.
     *
     * Names with nothing buffered yet are counted as unchanged rather than as decliners, so a
     * freshly started exchange does not read as a market-wide sell-off.
     *
     * @param array<int, array{ticker: string, changePercent: float|null, isBankrupt: bool}> $marketData
     * @return array{advancing: int, declining: int, unchanged: int, topMover: array{ticker: string, changePercent: float}|null}
     */
    private function buildBreadth(array $marketData): array
    {
        $advancing = 0;
        $declining = 0;
        $unchanged = 0;
        $topMover  = null;

        foreach ($marketData as $row) {
            $change = $row['changePercent'];

            if ($row['isBankrupt'] || $change === null || abs($change) < 0.00005) {
                $unchanged++;
                continue;
            }

            $change > 0 ? $advancing++ : $declining++;

            if ($topMover === null || abs($change) > abs($topMover['changePercent'])) {
                $topMover = ['ticker' => $row['ticker'], 'changePercent' => $change];
            }
        }

        return [
            'advancing' => $advancing,
            'declining' => $declining,
            'unchanged' => $unchanged,
            'topMover'  => $topMover,
        ];
    }
}

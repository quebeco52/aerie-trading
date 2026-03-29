<?php

namespace App\Service;

use App\Entity\Stock;
use App\Entity\StockHistory;
use App\Data\SectorPE;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for tracking and updating stock prices.
 *
 * This service orchestrates the market simulation for individual stocks.
 * It calculates new prices based on market conditions, handles random market shocks,
 * processes earnings events, and persists historical data.
 */
class StockTracker
{
    private float $currentMarketVol = 0.15;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEngine $marketEngine,
        private EarningsEngine $earningsEngine,
        private CorporateActionEngine $corporateActionEngine,
        private MarketEvent $eventService,
        private MathUtility $mathUtility,
    ) {}

    public function updateStocks(array $stocks, float $dt, array $liveSectorPEs, bool $recordHistory): array
    {

        $stockUpdates = [];
        $totalMarketCap = 0.0;
        $events = [];

        // Market noise calculation (Systemic shock applied to all stocks)
        $marketZ = $this->mathUtility->generateStandardNormal();
        
        // THE DISTRICT VIX (Dynamic Market Volatility)
        $this->updateDistrictVix($dt);
        
        $marketVol = $this->currentMarketVol;

        foreach ($stocks as $stock) {
            $sectorName = $stock->getSector();
            $targetPE = $liveSectorPEs[$sectorName] ?? 20.0;

            // Determine Volatility
            $baselineVol = (float) $stock->getVolatility();
            $currentVol = $stock->getCurrentVolatility() !== null
                ? (float) $stock->getCurrentVolatility()
                : $baselineVol;

            // Calculate new price
            $calculation = $this->marketEngine->calculateNextPrice(
                currentPrice: (float) $stock->getPrice(),
                currentVolatility: $currentVol,
                longTermVolatility: $baselineVol,
                earningsPerShare: (float) $stock->getEarningsPerShare(),
                targetPE: $targetPE,
                dt: $dt,
                lambda: (float) $stock->getJumpIntensity(),
                jumpMean: (float) $stock->getJumpMean(),
                jumpVol: (float) $stock->getJumpVol(),
                beta: (float) $stock->getBeta(),
                marketZ: $marketZ,
                marketVol: $marketVol
            );

            $newPrice = $calculation['price'];
            $nextVolatility = $calculation['next_volatility'];

            // Handle Market Shocks via Doctrine Entities
            if ($calculation['shock'] !== null) {
                $events[] = $this->eventService->publish($stock, 'SHOCK', "Sudden market shock detected.", $calculation['shock']);
            }

            // Earnings Engine
            $earningsEvent = $this->earningsEngine->calculate($stock, $dt);
            if ($earningsEvent) {
                $events[] = $earningsEvent;
            }


            $newEps = (float) $stock->getEarningsPerShare();
            $sharesOutstanding = (int) $stock->getSharesOutstanding();

            // CORPORATE ACTIONS (SPLITS)
            $splitResult = $this->corporateActionEngine->processSplits(
                $stock, 
                $newPrice, 
                $newEps, 
                $sharesOutstanding
            );

            // Unpack the results (they will be identical if no split occurred)
            $newPrice = $splitResult['price'];
            $newEps = $splitResult['eps'];
            $sharesOutstanding = $splitResult['shares'];
            $splitEvent = $splitResult['event'];

            // ==========================================
            // UPDATE THE DOCTRINE ENTITY
            // ==========================================
            $stock->setPrice((string) $newPrice);
            $stock->setCurrentVolatility((string) $nextVolatility);
            
            // Only update these if a split actually happened
            if ($splitEvent) {
                $stock->setEarningsPerShare((string) $newEps);
                $stock->setSharesOutstanding($sharesOutstanding);
                $events[] = $splitEvent;
            }

            // Calculate Market Cap
            $currentMarketCap = $newPrice * (float) $stock->getSharesOutstanding();
            $totalMarketCap += $currentMarketCap;

            $stockUpdates[] = [
                'ticker' => $stock->getTicker(),
                'sector' => $sectorName,
                'price' => round($newPrice, 2),
                'market_cap' => $currentMarketCap,
                'current_volatility' => round($nextVolatility * 100, 2),
            ];

            if ($recordHistory) {
                $history = new StockHistory();
                $history->setStock($stock);
                $history->setPrice((string) $newPrice);
                $this->entityManager->persist($history);
            }
        }

        return [
            'updates' => $stockUpdates,
            'total_cap' => $totalMarketCap,
            'events' => $events
        ];
    }

    private function updateDistrictVix(float $dt): void
    {
        // Generate a separate Z-score to drive the VIX itself
        $vixZ = $this->mathUtility->generateStandardNormal();

        $vixKappa = 4.0;       // Speed of reversion (Pulls it back to normal)
        $vixBaseline = 0.15;   // The long-term normal market volatility (15%)
        $vixVolOfVol = 0.08;   // How violently the VIX itself can swing

        // Mean-reverting random walk for global volatility
        $this->currentMarketVol += $vixKappa * ($vixBaseline - $this->currentMarketVol) * $dt 
                                 + $vixVolOfVol * sqrt($dt) * $vixZ;

        // Hard bounds to prevent the math from breaking (5% floor, 50% ceiling)
        $this->currentMarketVol = max(0.05, min(0.50, $this->currentMarketVol));
    }
}

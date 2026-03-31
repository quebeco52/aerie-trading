<?php

namespace App\Service;

use App\Data\EconomicCycle;
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
    /**
     * @var float The current systemic market volatility (VIX equivalent).
     */
    private float $currentMarketVol = 0.15;

    /**
     * Constructor.
     *
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param MarketEngine $marketEngine Engine for calculating stock price movements.
     * @param EarningsEngine $earningsEngine Engine for processing quarterly earnings reports.
     * @param CorporateActionEngine $corporateActionEngine Engine for handling corporate actions like stock splits.
     * @param MarketEvent $eventService Publisher for market events, shocks, and headlines.
     * @param MathUtility $mathUtility Utility for generating standard normal distributions.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEngine $marketEngine,
        private EarningsEngine $earningsEngine,
        private CorporateActionEngine $corporateActionEngine,
        private MarketEvent $eventService,
        private MathUtility $mathUtility,
    ) {}

    /**
     * Updates the prices and states of a collection of stocks for a single time step.
     *
     * This method orchestrates the full market simulation loop for each stock, including:
     * - Calculating the next price and volatility using the MarketEngine.
     * - Checking for and processing random market shocks.
     * - Evaluating potential quarterly earnings reports.
     * - Processing corporate actions like stock splits or reverse splits.
     * - Optionally recording the historical price data.
     *
     * @param Stock[] $stocks        Array of Stock entities to update.
     * @param float   $dt            The time step delta (e.g., in years).
     * @param array   $liveSectorPEs Associative array mapping sector names to their current live P/E ratios.
     * @param bool    $recordHistory Whether to persist the new prices to the stock history table.
     * @param EconomicCycle|null $economicCycle The current state of the macroeconomic cycle.
     * 
     * @return array{updates: array, total_cap: float, events: array, market_vol: float} Aggregated results of the update.
     */
    public function updateStocks(array $stocks, float $dt, array $liveSectorPEs, bool $recordHistory, ?EconomicCycle $economicCycle = null): array
    {

        $stockUpdates = [];
        $totalMarketCap = 0.0;
        $events = [];


        // Generate the systemic shock for this tick
        $marketZ = $this->mathUtility->generateStandardNormal();

        // THE DISTRICT VIX (Dynamic Market Volatility)
        $this->updateDistrictVariance($dt, $economicCycle);

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
                marketVol: $marketVol,
                economicCycle: $economicCycle
            );

            $newPrice = $calculation['price'];
            $nextVolatility = $calculation['next_volatility'];

            // Handle Market Shocks via Doctrine Entities
            if ($calculation['shock'] !== null) {
                $events[] = $this->eventService->publish($stock, 'SHOCK', "Sudden market shock detected.", $calculation['shock']);
            }

            // Earnings Engine
            $earningsEvent = $this->earningsEngine->calculate($stock, $dt, $economicCycle);
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

            // UPDATE THE DOCTRINE ENTITY
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
            'events' => $events,
            'market_vol' => $this->currentMarketVol
        ];
    }

    /**
     * Updates the overarching market volatility (The District VIX).
     *
     * Applies a Heston-style stochastic variance process with mean reversion,
     * along with a jump diffusion mechanism to simulate sudden market-wide volatility spikes.
     *
     * @param float $dt The time step delta.
     */
    private function updateDistrictVariance(float $dt, ?EconomicCycle $economicCycle = null): void
    {
        $kappa = 6.0;
        $longTermVolatility = 0.15;
        $volOfVol = 0.30;

        $currentVariance = pow($this->currentMarketVol, 2);
        $longTermVariance = pow($longTermVolatility, 2);

        // Base Heston Variance Process
        $w2 = $this->mathUtility->generateStandardNormal();
        
        $dv = $kappa * ($longTermVariance - $currentVariance) * $dt
            + $volOfVol * sqrt($currentVariance) * sqrt($dt) * $w2;

        $nextVariance = $currentVariance + $dv;

        // The Jump Mechanism (Applied directly to variance)
        $annualJumpProbability = 0.80;
        $stepJumpProbability = $annualJumpProbability * $dt;

        if (mt_rand() / mt_getrandmax() < $stepJumpProbability) {
            $jumpZ = $this->mathUtility->generateStandardNormal();
            
            // Calculate volatility jump severity (e.g., 5% to 35% absolute)
            $volJumpSeverity = 0.05 + (abs($jumpZ) * 0.10);
            
            // Convert the current state + jump back into variance
            $spikedVolatility = sqrt(max(0.000001, $nextVariance)) + $volJumpSeverity;
            $nextVariance = pow($spikedVolatility, 2);
        }

        // Full Truncation & Conversion back to Volatility
        $nextVariance = max(0.000001, $nextVariance);
        $this->currentMarketVol = sqrt($nextVariance);

        // Hard bounds (Converted back to volatility terms)
        $this->currentMarketVol = max(0.08, min(0.80, $this->currentMarketVol));
    }
}

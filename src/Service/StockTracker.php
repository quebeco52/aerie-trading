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
     * @param array<string, float>   $liveSectorPEs Associative array mapping sector names to their current live P/E ratios.
     * @param bool    $recordHistory Whether to persist the new prices to the stock history table.
     * @param EconomicCycle|null $economicCycle The current state of the macroeconomic cycle.
     * 
     * @return array{updates: array<mixed>, total_cap: float, events: array<mixed>, market_vol: float, history: array<mixed>}
     */
    public function updateStocks(array $stocks, float $dt, array $liveSectorPEs, bool $recordHistory, ?EconomicCycle $economicCycle = null, int $tickCount = 0, int $ticksPerYear = 252): array
    {

        $stockUpdates = [];
        $totalMarketCap = 0.0;
        $events = [];
        $historyData = [];


        // Generate the systemic shock for this tick
        $marketZ = $this->mathUtility->generateStandardNormal();

        // THE DISTRICT VIX (Dynamic Market Volatility)
        $this->updateDistrictVariance($dt, $marketZ, $economicCycle);

        $marketVol = $this->currentMarketVol;

        foreach ($stocks as $stock) {
            $sectorName = $stock->getSector();
            $targetPE = $liveSectorPEs[$sectorName] ?? 20.0;

            // Determine Volatility
            $baselineVol = (float) $stock->getVolatility();
            $currentVol = (float) ($stock->getCurrentVolatility() ?? $baselineVol);
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
                economicCycle: $economicCycle,
            );

            $newPrice = $calculation['price'];
            $nextVolatility = $calculation['next_volatility'];

            // Handle Market Shocks via Doctrine Entities
            if ($calculation['shock'] !== null) {
                $events[] = $this->eventService->publish($stock, 'SHOCK', "Sudden market shock detected.", $calculation['shock']);
            }

            // Earnings Engine
            $earningsEvent = $this->earningsEngine->calculate($stock, $economicCycle, $tickCount, $ticksPerYear);
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
                $stock->setSharesOutstanding((string) $sharesOutstanding);
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

                $historyData[] = [
                    'stock_id' => $stock->getId(),
                    'price' => $newPrice
                ];
            }
        }

        return [
            'updates' => $stockUpdates,
            'total_cap' => $totalMarketCap,
            'events' => $events,
            'market_vol' => $this->currentMarketVol,
            'history' => $historyData,
        ];
    }

    /**
     * Updates the overarching market volatility (The District VIX).
     *
     * Applies a Heston-style stochastic variance process with mean reversion,
     * along with a jump diffusion mechanism to simulate sudden market-wide volatility spikes.
     *
     * @param float $dt The time step delta.
     * @param float $marketZ The systemic market shock generated for this tick.
     * @param EconomicCycle|null $economicCycle The current macro cycle.
     */
    private function updateDistrictVariance(float $dt, float $marketZ,?EconomicCycle $economicCycle = null): void
    {

        $cycleVolModifier = 1.0;
        if ($economicCycle) {
            $cycleVolModifier = $economicCycle->getVolatilityModifier();
        }

        $this->currentMarketVol = $this->mathUtility->calculateHestonVolatility(
            currentVolatility: $this->currentMarketVol,
            longTermVolatility: 0.15 * $cycleVolModifier,
            kappa: 6.0,
            volOfVol: 0.30,
            rho: -0.7,
            dt: $dt,
            z1: $marketZ,
        );


        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: 0.80,
            jumpMean: 0.05,
            jumpVol: 0.10,
            dt: $dt
        );

        if ($jumpData['exponent'] !== null) {
            // Apply the volatility jump directly
            $volJumpSeverity = abs($jumpData['exponent']);
            $this->currentMarketVol += $volJumpSeverity;
        }

        // Hard bounds (Converted back to volatility terms)
        $this->currentMarketVol = max(0.08, min(0.80, $this->currentMarketVol));
    }
}

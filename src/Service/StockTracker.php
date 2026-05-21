<?php

namespace App\Service;

use App\Entity\Stock;
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
        private MergerAndAcquisitionEngine $maEngine,
        private MarketEvent $eventService,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility
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
     * @param bool    $recordHistory Whether to persist the new prices to the stock history table.
     * @param array   $macroState    The current state of the macroeconomic cycle.
     * 
     * @return array{updates: array<mixed>, total_cap: float, events: array<mixed>, market_vol: float, history: array<mixed>}
     */
    public function updateStocks(array $stocks, float $dt, bool $recordHistory, array $macroState = [], int $tickCount = 0, int $ticksPerYear = 252): array
    {

        $stockUpdates = [];
        $totalMarketCap = 0.0;
        $events = [];
        $historyData = [];


        // Generate the systemic shock for this tick
        $marketZ = $this->mathUtility->generateStandardNormal();

        // THE DISTRICT VIX (Dynamic Market Volatility)
        $this->updateDistrictVariance($dt, $marketZ, $macroState);

        $marketVol = $this->currentMarketVol;

        foreach ($stocks as $stock) {

            $sectorName = $stock->getSector();

            // Determine Volatility
            $baselineVol = (float) $stock->getVolatility();
            $currentVol = (float) ($stock->getCurrentVolatility() ?? $baselineVol);

            // M&A
            $maResult = $this->maEngine->evaluatePrivateAcquisition($stock, $macroState, $dt);
            $maShock = 0.0;
            if ($maResult) {
                $events[] = $maResult['event'];
                $maShock = $maResult['shock'];
            }
            
            // DIVESTITURE (Spin-offs)
            // A company won't acquire and divest in the exact same tick
            if (!$maResult) {
                $divestResult = $this->maEngine->evaluateCorporateDivestiture($stock, $macroState, $dt);
                if ($divestResult) {
                    $events[] = $divestResult['event'];
                    $maShock = $divestResult['shock'];
                }
            }

            // Fetch true, dynamic WACC from the DebtEngine
            $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);

            $sharesOutstanding = (float) $stock->getSharesOutstanding();
            $shares = max(1.0, $sharesOutstanding);

            // Fetch the industry limits and structural data
            $industryKey = $stock->getIndustry() ?: 'General';
            $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industryKey] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
            $isLeveragedIndustry = $metrics['leveraged_industry'] ?? false;
            $baselineIndustryPE = $metrics['pe'] ?? 20.0;
            $revenuePerShare = (float) $stock->getTotalRevenue() / $shares;

            // Calculate new price (GBM + SVJJ)
            $calculation = $this->marketEngine->calculateNextPrice(
                currentPrice: (float) $stock->getPrice(),
                currentVolatility: $currentVol,
                longTermVolatility: $baselineVol,
                earningsPerShare: (float) $stock->getEarningsPerShare(),
                dt: $dt,
                lambda: (float) $stock->getJumpIntensity(),
                jump_vol: (float) $stock->getJumpVol(),
                beta: (float) $stock->getBeta(),
                marketZ: $marketZ,
                marketVol: $marketVol,
                macroState: $macroState,
                fcfPerShare: $stock->getFreeCashFlowPerShare() !== null ? (float) $stock->getFreeCashFlowPerShare() : null,
                bookValuePerShare: (float) $stock->getBookValuePerShare(),
                maShock: $maShock,
                currentRoic: (float) ($stock->getCurrentRoic() ?: $stock->getBaselineRoic()),
                dividendPerShare: (float) $stock->getLastDividend(),
                liveWacc: $health['wacc'],
                baselineIndustryPE: $baselineIndustryPE,
                revenuePerShare: $revenuePerShare,
                isLeveragedIndustry: $isLeveragedIndustry,
                liveCostOfEquity: $health['cost_of_equity'] ?? 0.10
            );

            $newPrice = $calculation['price'];
            $nextVolatility = $calculation['next_volatility'];

            if ($calculation['shock'] !== null) {
                $events[] = $this->eventService->publish($stock, 'SHOCK', "Sudden market shock detected.", $calculation['shock']);
            }
            $stock->setPrice((string) $newPrice);
            $stock->setCurrentVolatility((string) $nextVolatility);

            

            // Earnings Engine
            $generatedEvents = $this->earningsEngine->calculate($stock, $macroState, $tickCount, $ticksPerYear);
            if (!empty($generatedEvents)) {
                $events = array_merge($events, $generatedEvents);
            }
            $currentPriceAfterEarnings = (float) $stock->getPrice();

            // CORPORATE ACTIONS (SPLITS)
            $splitResult = $this->corporateActionEngine->processSplits(
                $stock,
                $currentPriceAfterEarnings,
                (float) $stock->getSharesOutstanding()
            );

            // Unpack the results
            $finalPrice = $splitResult['price'];
            $newShares = $splitResult['shares'];
            $splitEvent = $splitResult['event'] ?? null;

            // Always update the price
            $stock->setPrice((string) $finalPrice);

            // Check if the math actually changed
            if ($sharesOutstanding !== $newShares) {
                $stock->setSharesOutstanding((string) $newShares);
            }

            // Only push the event to the array if one was actually generated
            if ($splitEvent) {
                $events[] = $splitEvent;
            }

            // Calculate Market Cap
            $currentMarketCap = $finalPrice * $newShares;
            $totalMarketCap += $currentMarketCap;

            // Determine if a fundamental corporate event occurred this tick
            $isFundamentalTick = !empty($generatedEvents) || $maResult || (isset($divestResult) && $divestResult);


            $stockUpdate = [
                'ticker' => $stock->getTicker(),
                'sector' => $sectorName,
                'industry' => $stock->getIndustry() ?: 'General',
                'price' => round($finalPrice, 2),
                'market_cap' => $currentMarketCap,
                'current_volatility' => round($nextVolatility * 100, 2),
                'current_roic' => (float) $stock->getCurrentRoic() != 0.0 ? (float) $stock->getCurrentRoic() : (float) $stock->getBaselineRoic(),
                'shares' => $newShares,
                'eps' => (float) $stock->getEarningsPerShare(),
                'treasury' => (float) $stock->getCorporateTreasury(),
                'equity' => (float) $stock->getTotalEquity(),
                'invested_capital' => $stock->getInvestedCapital(),
                'debt_ratio' => (float) $stock->getDebtToEquityRatio(),
                'analyst_targets' => $calculation['analyst_targets'],
                'perceived_fair_value' => $calculation['perceived_fair_value'],
            ];


            // Only update Market Share on the UI when Corporate Fundamentals actually change
            // This prevents the percentage from jittering constantly as the Nominal GDP index expands
            if ($isFundamentalTick) {
                $investedCapital = $stock->getInvestedCapital();
                $equity = (float) $stock->getTotalEquity();
                $nominalGdpIndex = $macroState['nominal_gdp_index'] ?? 1.0;
                $samRatio = (float) $stock->getSamRatio();
                
                $evaluationCapital = $isLeveragedIndustry ? ($equity + (float) $stock->getWholesaleDebt()) : $investedCapital;
                $marketShare = min(0.9999, $this->mathUtility->calculateMarketShare($evaluationCapital, $nominalGdpIndex, $samRatio));
                
                $stockUpdate['market_share'] = round($marketShare * 100, 2);
            }
            
            $stockUpdates[] = $stockUpdate;

            if ($recordHistory) {

                $historyData[] = [
                    'stock_id' => $stock->getId(),
                    'price' => $finalPrice
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
     * Applies the advanced Quadratic-Exponential (QE) scheme for the variance process,
     * along with the SVJJ Kou double-exponential jump mechanism to simulate 
     * mathematically rigorous market-wide panics.
     *
     * @param float $dt         The time step delta.
     * @param float $marketZ    The systemic market shock generated for this tick.
     * @param array $macroState The current macro state (inflation, output gap, etc).
     */
    private function updateDistrictVariance(float $dt, float $marketZ, array $macroState = []): void
    {
        $cycleVolModifier = 1.0;
        if (!empty($macroState)) {
            // Positive output gap (boom) reduces vol slightly, negative gap (bust) increases vol
            $cycleVolModifier = 1.0 - ($macroState['output_gap'] ?? 0.0);
        }

        $longTermVol = 0.15 * $cycleVolModifier;

        $currentVar = $this->currentMarketVol * $this->currentMarketVol;
        $longTermVar = $longTermVol * $longTermVol;

        // Calculate macro shocks using the new SVJJ Kou model
        // Macro panics are highly asymmetric: 10% chance of a sudden volatility crush, 90% chance of a volatility explosion
        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: 0.80,
            pUp: 0.10,
            etaUp: 10.0,
            etaDown: 5.0,  // Very fat left tail for deep macroeconomic panics
            muV: 0.05,     // Base variance jump size
            dt: $dt
        );

        // Adjust theta downwards so the steady state expectation equals longTermVar
        // E[VarJump] = (pUp * muV * 0.5) + (pDown * muV)
        $expectedVarJump = (0.10 * 0.05 * 0.5) + (0.90 * 0.05);
        $jumpVarianceDrag = (0.80 * $expectedVarJump) / 6.0;
        $adjustedTheta = max(0.0001, $longTermVar - $jumpVarianceDrag);

        // Advance the variance using the strictly positive QE scheme
        $nextVar = $this->mathUtility->calculateQEVarianceStep(
            currentVar: $currentVar,
            theta: $adjustedTheta,
            kappa: 6.0,
            sigma: 0.30,
            dt: $dt
        );

        // Add the contemporaneous market-wide variance jump
        $nextVar += $jumpData['var_jump'];

        // Convert back to volatility
        $this->currentMarketVol = sqrt($nextVar);

        // Hard bounds to prevent the global simulation from permanently breaking
        $this->currentMarketVol = max(0.08, min(0.80, $this->currentMarketVol));
    }
}
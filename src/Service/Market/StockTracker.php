<?php

namespace App\Service\Market;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;

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
     * Constructor.
     *
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param MarketEngine $marketEngine Engine for calculating stock price movements.
     * @param EarningsEngine $earningsEngine Engine for processing quarterly earnings reports.
     * @param CorporateActionEngine $corporateActionEngine Engine for handling corporate actions like stock splits.
     * @param MarketEventPublisher $eventService Publisher for market events, shocks, and headlines.
     * @param MathUtility $mathUtility Utility for generating standard normal distributions.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEngine $marketEngine,
        private EarningsEngine $earningsEngine,
        private CorporateActionEngine $corporateActionEngine,
        private MergerAndAcquisitionEngine $maEngine,
        private MarketEventPublisher $eventService,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics
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
     * @param MacroStateDTO|array $macroState    The current state of the macroeconomic cycle.
     * 
     * @return array{updates: array<mixed>, total_cap: float, events: array<mixed>, market_vol: float, history: array<mixed>}
     */
    public function updateStocks(array $stocks, float $dt, bool $recordHistory, \App\DTO\MacroStateDTO|array $macroState = [], int $tickCount = 0, int $ticksPerYear = 252): array
    {

        $stockUpdates = [];
        $historyData = [];
        $totalMarketCap = 0.0;
        $events = [];

        // Pull systemic variables from the Macro Engine
        $macroDTO = $macroState instanceof \App\DTO\MacroStateDTO ? $macroState : \App\DTO\MacroStateDTO::fromArray($macroState);
        $marketZ = $macroDTO->marketZ;
        $marketVol = $macroDTO->marketVolatility;

        foreach ($stocks as $stock) {

            $sectorName = $stock->getSector();

            // Determine Volatility
            $baselineVol = (float) $stock->getVolatility();
            $currentVol = (float) ($stock->getCurrentVolatility() ?? $baselineVol);

            // M&A
            $maResult = $this->maEngine->evaluatePrivateAcquisition($stock, $macroDTO, $dt);
            $maShock = 0.0;
            if ($maResult) {
                $events[] = $maResult['event'];
                $maShock = $maResult['shock'];
            }

            // DIVESTITURE (Spin-offs)
            // A company won't acquire and divest in the exact same tick
            if (!$maResult) {
                $divestResult = $this->maEngine->evaluateCorporateDivestiture($stock, $macroDTO, $dt);
                if ($divestResult) {
                    $events[] = $divestResult['event'];
                    $maShock = $divestResult['shock'];
                }
            }

            // Fetch true, dynamic WACC from the DebtEngine
            $health = $this->debtEngine->analyzeDebtHealth($stock, $macroDTO);

            $sharesOutstanding = (float) $stock->getSharesOutstanding();
            $shares = max(1.0, $sharesOutstanding);

            // Fetch the industry limits and structural data
            $industryKey = $stock->getIndustry() ?: 'General';
            $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industryKey] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
            $businessModel = $metrics['business_model'] ?? 'none';
            $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
            $baselineIndustryPE = $metrics['pe'] ?? 20.0;

            // Annualize the quarterly revenue so the Market Engine correctly evaluates Price-to-Sales
            $revenuePerShare = ((float) $stock->getTotalRevenue() * 4.0) / $shares;

            $effectiveRoic = $isFinancial
                ? (float) ($stock->getCurrentRoe() ?: $stock->getBaselineRoe())
                : (float) ($stock->getCurrentRoic() ?: $stock->getBaselineRoic());

            $roicTtm = $isFinancial
                ? (float) $stock->getRoeTtm()
                : (float) $stock->getRoicTtm();

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
                macroState: $macroDTO,
                fcfPerShare: $stock->getFreeCashFlowPerShare() !== null ? (float) $stock->getFreeCashFlowPerShare() : null,
                bookValuePerShare: (float) $stock->getBookValuePerShare(),
                maShock: $maShock,
                currentRoic: $effectiveRoic,
                roicTtm: $roicTtm,
                dividendPerShare: (float) $stock->getLastDividend(),
                liveWacc: $health['wacc'],
                baselineIndustryPE: $baselineIndustryPE,
                revenuePerShare: $revenuePerShare,
                businessModel: $businessModel,
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
            $generatedEvents = $this->earningsEngine->calculate($stock, $macroDTO, $tickCount, $ticksPerYear);
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
                'current_roic' => $effectiveRoic, // Backwards compatible fix so frontend JS updates the UI with ROE for banks
                'current_roe' => (float) $stock->getCurrentRoe() != 0.0 ? (float) $stock->getCurrentRoe() : (float) $stock->getBaselineRoe(),
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
                $nominalGdpIndex = $macroDTO->nominalGdpIndex;
                $samRatio = (float) $stock->getSamRatio();

                $evaluationCapital = $isFinancial ? $equity : $investedCapital;
                $marketShare = min(0.9999, $this->corporateMetrics->calculateMarketShare($evaluationCapital, $nominalGdpIndex, $samRatio));

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
            'history' => $historyData,
            'total_cap' => $totalMarketCap,
            'events' => $events,
            'market_vol' => $marketVol
        ];
    }
}

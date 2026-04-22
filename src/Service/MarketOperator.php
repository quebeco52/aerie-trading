<?php

namespace App\Service;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The "Invisible Hand" of the Aerie District.
 * Runs periodically to prevent the math models from destroying the economy.
 */
class MarketOperator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MarketEvent $marketEvent
    ) {}

    /**
     * Wakes up periodically to enforce the laws of game-design physics.
     */
    public function enforceMarketStability(array $stocks): array
    {
        $this->logger->info("The Market Operator is reviewing the district...");
        $generatedEvents = [];

        // Calculate the Total Market Cap of the entire district on the fly
        $totalMarketCap = 0;
        foreach ($stocks as $stock) {
            $totalMarketCap += ((float) $stock->getPrice()) * ((int) $stock->getSharesOutstanding());
        }
        // Fallback to prevent division by zero in case of total economic collapse
        if ($totalMarketCap <= 0) $totalMarketCap = 1;

        foreach ($stocks as $stock) {
            $price = (float) $stock->getPrice();
            $shares = (int) $stock->getSharesOutstanding();
            $marketCap = $price * $shares;
            $eps = (float) $stock->getEarningsPerShare();
            $name = $stock->getName();
            $systemicImportance = $stock->getSystemicImportance() ?? 'none';

            $this->applyBailoutRule($stock, $totalMarketCap, $marketCap, $price, $shares, $eps, $systemicImportance);
            
            $restructureEvents = $this->applyRestructuringRule($stock, $marketCap, $name);
            if ($restructureEvents !== null) {
                $generatedEvents = array_merge($generatedEvents, $restructureEvents);
                continue;
            }

            $this->applyDominanceRubberBandRule($stock, $totalMarketCap, $marketCap, $price, $eps);

            // RULE : Volatility Dampening

            $currentVol = (float) $stock->getCurrentVolatility();
            if ($currentVol > 1.50) {
                $stock->setCurrentVolatility((string) ($currentVol * 0.90));
            }
        }

        return $generatedEvents;
    }

    /**
     * Applies the bailout rule to systemically important stocks.
     *
     * Prevents "Titan" and "Systemic" class stocks from falling below a certain
     * percentage of the total market cap by subsidizing their price and EPS.
     *
     * @param Stock  $stock              The stock to evaluate.
     * @param float  $totalMarketCap     The total market capitalization of the entire district.
     * @param float  $marketCap          The current market capitalization of the stock.
     * @param float  $price              The current price of the stock.
     * @param int    $shares             The number of outstanding shares.
     * @param float  $eps                The current earnings per share.
     * @param string $systemicImportance The systemic importance tier of the stock.
     * @return void
     */
    private function applyBailoutRule(Stock $stock, float $totalMarketCap, float $marketCap, float $price, int $shares, float $eps, string $systemicImportance): void
    {
        $bailoutFloor = 0.0;
        $bailoutMultiplier = 1.0;
        $bailoutTier = null;

        switch ($systemicImportance) {
            case 'titan':
                $bailoutFloor = $totalMarketCap * 0.04;
                $bailoutMultiplier = 1.03;
                $bailoutTier = 'TITAN PROTECTION';
                break;
            case 'systemic':
                $bailoutFloor = $totalMarketCap * 0.015;
                $bailoutMultiplier = 1.02;
                $bailoutTier = 'SYSTEMIC BAILOUT';
                break;
            case 'base':
                $bailoutFloor = $totalMarketCap * 0.01;
                $bailoutMultiplier = 1.02;
                $bailoutTier = 'BASE CLASS BAILOUT';
                break;
        }

        if ($bailoutTier && $marketCap < $bailoutFloor) {
            $stock->setPrice((string) ($price * $bailoutMultiplier));

            $splitRatio = max(1.0, $shares / 1_000_000_000.0);
            $targetEps = 0.40 / $splitRatio;
            $boostEps = 0.20 / $splitRatio;

            $newEps = $eps < $targetEps ? min($targetEps, $eps + $boostEps) : $eps * $bailoutMultiplier;
            $stock->setEarningsPerShare((string) $newEps);

            $this->logger->info("{$bailoutTier}: {$stock->getTicker()} subsidized (Fell below dominance floor).");
        }
    }

    /**
     * Evaluates and applies bankruptcy restructuring for failing companies.
     *
     * If a company's market cap falls below $1B, it undergoes either a hostile
     * takeover (liquidation) or a white knight bailout, resetting its financials.
     *
     * @param Stock  $stock     The failing stock to restructure.
     * @param float  $marketCap The current market capitalization.
     * @param string $name      The name of the company.
     * @return array|null Returns generated market events if a restructuring occurred, otherwise null.
     */
    private function applyRestructuringRule(Stock $stock, float $marketCap, string $name): ?array
    {
        if ($marketCap >= 1000000000) {
            return null;
        }

        $isHostile = mt_rand(1, 100) > 50;

        if ($isHostile) {
            $this->logger->info("BANKRUPTCY DETECTED: {$stock->getTicker()} collapsed. Black Swan Capital initiating hostile liquidation.");
            $event1Desc = "{$name} ({$stock->getTicker()}) was liquidated in a hostile takeover by Black Swan Capital. Shareholder equity wiped to 0.";
            $event2Desc = "Black Swan Capital has stripped {$stock->getTicker()} of its assets and relisted the hollowed-out shell at $50.00.";
        } else {
            $this->logger->info("BANKRUPTCY IMMINENT: {$stock->getTicker()} collapsing. Lakebird Bank initiating a bailout.");
            $event1Desc = "{$name} ({$stock->getTicker()}) secured a last-minute emergency bailout from Lakebird Bank. Retail shares diluted to secure funding.";
            $event2Desc = "Lakebird Bank has stabilized {$stock->getTicker()}'s balance sheet. Trading resumes at $50.00.";
        }

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_stocks SET quantity = 0, version = version + 1 WHERE stock_id = :id',
            ['id' => $stock->getId()]
        );

        $stock->setPrice("50.00");
        $stock->setSharesOutstanding("1000000000");
        $stock->setEarningsPerShare((string) (mt_rand(325, 433) / 100));
        $stock->setCorporateTreasury("5000000000.00");
        $stock->setTotalEquity("20000000000.00");
        $stock->setRetainedEarnings("0.00");
        $stock->setTotalDebt("10000000000.00"); // Give the restructured company a healthy 0.5x D/E ratio

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM stock_history WHERE stock_id = :id',
            ['id' => $stock->getId()]
        );

        return [
            $this->marketEvent->publish($stock, 'BANKRUPTCY', $event1Desc, -100.00),
            $this->marketEvent->publish($stock, 'BAILOUT', $event2Desc, 0.00)
        ];
    }

    /**
     * Applies the Market Dominance Rubber Band (Law of Large Numbers) rule.
     *
     * Introduces a gravitational drag on companies that grow too large relative to the
     * total market index, preventing runaway monopolies by compressing EPS or Price.
     *
     * @param Stock $stock          The stock to evaluate.
     * @param float $totalMarketCap The total market capitalization of the entire district.
     * @param float $marketCap      The current market capitalization of the stock.
     * @param float $price          The current price of the stock.
     * @param float $eps            The current earnings per share.
     * @return void
     */
    private function applyDominanceRubberBandRule(Stock $stock, float $totalMarketCap, float $marketCap, float $price, float $eps): void
    {
        $dominanceRatio = $marketCap / $totalMarketCap;
        $softCap = 0.20; 
        $hardCap = 0.30;

        if ($dominanceRatio > $softCap) {
            $excess = ($dominanceRatio - $softCap) / ($hardCap - $softCap);
            $excess = min(1.0, max(0.0, $excess));

            $maxDrag = 0.025; 
            $gravityPull = $excess * $maxDrag;

            $peRatio = $eps > 0 ? $price / $eps : 999;
            
            if ($peRatio < 35.0 && $eps > 0) {
                $stock->setEarningsPerShare((string) ($eps * (1.0 - $gravityPull)));
                $stock->setPrice((string) ($price * (1.0 - ($gravityPull / 2.0))));
                $dragType = "EPS";
            } else {
                $stock->setPrice((string) ($price * (1.0 - $gravityPull)));
                $dragType = "Price";
            }
            
            $pct = round($dominanceRatio * 100, 2);
            $this->logger->info("GRAVITY WELL: {$stock->getTicker()} {$dragType} rubber-banded (Dominance: {$pct}%, PE: " . round($peRatio, 1) . ")");
        }
    }
}

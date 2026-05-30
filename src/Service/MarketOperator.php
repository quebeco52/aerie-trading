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
        private MarketEvent $marketEvent,
        private DebtEngine $debtEngine
    ) {}

    /**
     * Wakes up periodically to enforce the laws of game-design physics.
     *
     * @param array<Stock> $stocks An array of stock entities to review.
     * @return array<mixed> Generated market events resulting from restructuring actions.
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
            $name = $stock->getName();

            $restructureEvents = $this->applyRestructuringRule($stock, $marketCap, $name);
            if ($restructureEvents !== null) {
                $generatedEvents = array_merge($generatedEvents, $restructureEvents);
                continue;
            }

            // RULE : Volatility Dampening

            $currentVol = (float) $stock->getCurrentVolatility();
            if ($currentVol > 1.50) {
                $stock->setCurrentVolatility((string) ($currentVol * 0.90));
            }
        }

        return $generatedEvents;
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
        $revenue = (float) $stock->getTotalRevenue();
        $margin = (float) $stock->getOperatingMargin();
        $ebit = $revenue * $margin;

        $zScoreData = $this->debtEngine->calculateAltmanZScore($stock, $ebit, $revenue, (float) $stock->getPrice());
        if (!$zScoreData['is_bankrupt']) {
            return null; // The company is surviving; abort the restructuring
        }

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';

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
            'DELETE FROM user_stocks WHERE stock_id = :id',
            ['id' => $stock->getId()]
        );

        $stock->setPrice("50.00");
        $stock->setSharesOutstanding("1000000000");
        $stock->setEarningsPerShare((string) (mt_rand(325, 433) / 100));
        $stock->setFreeCashFlowPerShare("0.00");
        $stock->setCurrentRoic($stock->getBaselineRoic());
        $stock->setHistoricalFixedRate("0.05");
        $stock->setCorporateTreasury("5000000000.00");
        $stock->setTotalEquity("20000000000.00");
        $stock->setRetainedEarnings("0.00");
        $stock->setWholesaleDebt("10000000000.00"); // Give the restructured company a healthy 0.5x D/E ratio

        if ($businessModel === 'commercial_bank' || $businessModel === 'insurance') {
            // For a restructured bank or insurance company, assume 85% of its new debt is deposits/float.
            $stock->setCustomerDeposits("8500000000.00");
            $stock->setWholesaleDebt("1500000000.00"); // The remaining 1.5B is Wholesale
        } else {
            $stock->setCustomerDeposits("0.00");
        }

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM stock_history WHERE stock_id = :id',
            ['id' => $stock->getId()]
        );

        return [
            $this->marketEvent->publish($stock, 'BANKRUPTCY', $event1Desc, -100.00),
            $this->marketEvent->publish($stock, 'BAILOUT', $event2Desc, 0.00)
        ];
    }
}

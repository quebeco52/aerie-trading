<?php

namespace App\Service\Market;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Service\Corporate\DebtEngine;
use App\Service\Event\MarketEventPublisher;

/**
 * The "Invisible Hand" of the Aerie District.
 * Runs periodically to prevent the math models from destroying the economy.
 */
class MarketOperator
{

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MarketEventPublisher $marketEvent,
        private DebtEngine $debtEngine,
        private \App\Service\Math\MathUtility $mathUtility
    ) {}

    /**
     * Wakes up periodically to enforce the laws of game-design physics.
     *
     * Iterates over all stocks to prevent runaway balance sheet feedback loops:
     * 1. Evaluates companies for potential bankruptcy restructuring.
     * 2. Damps extreme instantaneous volatility spikes back towards safety bounds.
     *
     * @param Stock[]       $stocks     List of all active stock entities.
     * @param MacroStateDTO $macroState The current macroeconomic state.
     * @return array List of generated market events to broadcast.
     */
    public function enforceMarketStability(array $stocks, MacroStateDTO $macroState): array
    {
        $this->logger->info("The Market Operator is reviewing the district...");
        $generatedEvents = [];

        // Calculate the Total Market Cap of active companies in the district on the fly
        $totalMarketCap = 0;
        foreach ($stocks as $stock) {
            if ($stock->isBankrupt()) {
                continue;
            }
            $totalMarketCap += ((float) $stock->getPrice()) * ((int) $stock->getSharesOutstanding());
        }
        // Fallback to prevent division by zero in case of total economic collapse
        if ($totalMarketCap <= 0) $totalMarketCap = 1;

        foreach ($stocks as $stock) {
            if ($stock->isBankrupt()) {
                continue;
            }

            $price = (float) $stock->getPrice();
            $shares = (int) $stock->getSharesOutstanding();
            $marketCap = $price * $shares;
            $name = $stock->getName();

            $restructureEvents = $this->applyRestructuringRule($stock, $marketCap, $name, $macroState);
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
     * Evaluates and applies permanent bankruptcy for failing companies.
     *
     * If a company fails the Altman Z''-score insolvency test, it is permanently killed:
     * - Status marked as bankrupt, share price drops to 0, volatility drops to 0.
     * - Open orders are cancelled (refunding cash for buy orders).
     * - Shareholder equity is wiped (user holdings deleted).
     * - All historical quarters, financial reports, lore events, and charts remain frozen in place.
     *
     * @param Stock         $stock     The failing stock to evaluate.
     * @param float         $marketCap The current market capitalization.
     * @param string        $name      The name of the company.
     * @param MacroStateDTO $macroState The current macroeconomic state.
     * @return array|null Returns generated market events if bankruptcy occurred, otherwise null.
     */
    private function applyRestructuringRule(Stock $stock, float $marketCap, string $name, MacroStateDTO $macroState): ?array
    {
        $revenue = (float) $stock->getTotalRevenue();
        $margin = (float) $stock->getOperatingMargin();
        $ebit = $revenue * $margin;

        $zScoreData = $this->debtEngine->calculateAltmanZScore($stock, $ebit, $revenue, (float) $stock->getPrice());
        if (!$zScoreData['is_bankrupt']) {
            return null; // The company is surviving; abort bankruptcy
        }

        $this->logger->info("BANKRUPTCY DETECTED: {$stock->getName()} ({$stock->getTicker()}) collapsed into insolvency. Company permanently terminated.");

        $stock->setIsBankrupt(true);
        $stock->setPrice('0.00000000');
        $stock->setCurrentVolatility('0.0000');

        // Cancel all OPEN trade orders for this ticker and refund BUY escrow
        $openOrders = $this->entityManager->getRepository(\App\Entity\TradeOrder::class)->findBy([
            'ticker' => $stock->getTicker(),
            'status' => 'OPEN'
        ]);

        foreach ($openOrders as $order) {
            if ($order->getAction() === 'BUY' && $order->getLimitPrice()) {
                $user = $order->getUser();
                if ($user) {
                    $escrowCashStr = \bcmul((string) $order->getLimitPrice(), (string) $order->getQuantity(), 4);
                    $user->setCashBalance(\bcadd((string) $user->getCashBalance(), $escrowCashStr, 4));
                    $this->entityManager->persist($user);
                }
            }
            $order->setStatus('CANCELLED');
            $this->entityManager->persist($order);
        }

        // Wipe user stock positions as shareholder equity is wiped out to 0
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM user_stocks WHERE stock_id = :id',
            ['id' => $stock->getId()]
        );

        $this->entityManager->persist($stock);
        $this->entityManager->flush();

        // NOTE: We preserve stock_history, corporate_report, and stock_events.
        // Historical quarters, charts, and lore records remain frozen in place.

        $eventDesc = "{$name} ({$stock->getTicker()}) has collapsed into insolvency and filed for Chapter 7 bankruptcy liquidation. Shareholder equity wiped to 0 and trading permanently halted.";

        return [
            $this->marketEvent->publish($stock, 'BANKRUPTCY', $eventDesc, -100.00)
        ];
    }
}


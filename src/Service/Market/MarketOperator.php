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
                $this->redistributeAddressableMarket($stock, $stocks);
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
     * Trailing-year EBIT rebuilt from the four reported quarters: net income grossed back up for tax when
     * positive (a loss pays none), plus the year's interest. Null until a full year has been reported, when
     * the caller falls back to the latest margin.
     */
    private function resolveTrailingEbit(Stock $stock, MacroStateDTO $macroState): ?float
    {
        $history = $stock->getQuarterlyNetIncomeHistory() ?? [];
        if (count($history) < \App\Service\Corporate\EarningsEngine::TTM_QUARTERS) {
            return null;
        }

        $trailingNetIncome = array_sum(array_map('floatval', $history));
        $preTaxIncome = $trailingNetIncome > 0.0
            ? $trailingNetIncome / max(0.01, 1.0 - $macroState->corporateTaxRate)
            : $trailingNetIncome;
        $interestExpense = $this->debtEngine->analyzeDebtHealth($stock, $macroState)?->rawMetrics?->interestExpense ?? 0.0;

        return $preTaxIncome + max(0.0, $interestExpense);
    }

    /**
     * Industry consolidation: a failed rival's demand does not vanish with it. Surviving peers in the same
     * industry absorb most of its addressable market in proportion to their own, which is how exits leave
     * survivors with more share and pricing headroom (airline, steel and retail consolidations). The
     * remainder leaks to substitutes or is destroyed.
     *
     * @param Stock[] $stocks
     */
    private function redistributeAddressableMarket(Stock $failed, array $stocks): void
    {
        $industry = $failed->getIndustry();
        if ($industry === null) {
            return;
        }

        $peers = array_values(array_filter(
            $stocks,
            static fn (Stock $peer): bool => $peer !== $failed && !$peer->isBankrupt() && $peer->getIndustry() === $industry
        ));
        if ($peers === []) {
            return;
        }

        $recaptured = ((float) $failed->getSamRatio()) * \App\Service\Math\FinancialConstants::MARKET_EXIT_RECAPTURE_FRACTION;
        if ($recaptured <= 0.0) {
            return;
        }

        $peerAddressableTotal = array_sum(array_map(static fn (Stock $peer): float => (float) $peer->getSamRatio(), $peers));
        foreach ($peers as $peer) {
            $weight = $peerAddressableTotal > 0.0 ? ((float) $peer->getSamRatio()) / $peerAddressableTotal : 1.0 / count($peers);
            $peer->setSamRatio((string) (((float) $peer->getSamRatio()) + ($recaptured * $weight)));
        }

        $this->logger->info(sprintf(
            'CONSOLIDATION: %d surviving %s peers absorbed %.0f%% of %s\'s addressable market.',
            count($peers),
            $industry,
            \App\Service\Math\FinancialConstants::MARKET_EXIT_RECAPTURE_FRACTION * 100,
            $failed->getTicker()
        ));
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

        // Solvency is tested on the margin the firm actually reported, not its structural operating margin.
        // The structural figure only moves through asset reinvestment decay, so a firm whose realized margin
        // had collapsed kept passing the Altman test on the strength of a plant it could no longer run
        // profitably. Falls back to the structural margin only before the first earnings report.
        $margin = $stock->getReportedOperatingMargin() ?? (float) $stock->getOperatingMargin();
        // Altman's X3 is EBIT over the trailing YEAR. Annualizing the latest quarter's margin let one
        // catastrophic print (the fire-sale quarter after a distressed divestiture) liquidate a firm whose
        // three preceding quarters were sound, with positive equity and a going business. Once four
        // quarters have been reported the ratio is struck on them, as it was built to be.
        $ebit = $this->resolveTrailingEbit($stock, $macroState) ?? $revenue * $margin;

        // Two independent ways to fail. Insolvency is the balance sheet no longer covering the claims on it;
        // a payment default is principal coming due that nobody would refinance and the firm could not pay.
        // A firm can pass the solvency test on paper and still fail the second, which is how most real
        // defaults happen: the assets were fine, the money just was not there on the day.
        $isPaymentDefault = $stock->isPaymentDefault();
        $zScoreData = $this->debtEngine->calculateAltmanZScore($stock, $ebit, $revenue, (float) $stock->getPrice());
        if (!$zScoreData['is_bankrupt'] && !$isPaymentDefault) {
            return null; // The company is surviving; abort bankruptcy
        }

        $failureMode = $isPaymentDefault ? 'defaulted on maturing debt' : 'collapsed into insolvency';
        $this->logger->info("BANKRUPTCY DETECTED: {$stock->getName()} ({$stock->getTicker()}) {$failureMode}. Company permanently terminated.");

        $stock->setIsBankrupt(true);
        $stock->setPrice('0.00000000');
        $stock->setCurrentVolatility('0.0000');
        $stock->setCreditRating('D');

        // Cancel all OPEN trade orders for this ticker and refund BUY escrow
        $openOrders = $this->entityManager->getRepository(\App\Entity\TradeOrder::class)
            ->findOpenByTicker($stock->getTicker());

        foreach ($openOrders as $order) {
            if ($order->getAction() === 'BUY' && $order->getLimitPrice()) {
                $user = $order->getUser();
                if ($user) {
                    $escrowCashStr = \bcmul((string) $order->getLimitPrice(), (string) $order->getQuantity(), 4);
                    $user->setCashBalance(\bcadd((string) $user->getCashBalance(), $escrowCashStr, 4));
                    $this->entityManager->persist($user);
                }
            }
            $order->setStatus(\App\Entity\TradeOrder::STATUS_CANCELLED);
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

        $eventDesc = $isPaymentDefault
            ? "{$name} ({$stock->getTicker()}) missed a principal payment it could not refinance and filed for Chapter 7 bankruptcy liquidation. Shareholder equity wiped to 0 and trading permanently halted."
            : "{$name} ({$stock->getTicker()}) has collapsed into insolvency and filed for Chapter 7 bankruptcy liquidation. Shareholder equity wiped to 0 and trading permanently halted.";

        return [
            $this->marketEvent->publish($stock, 'BANKRUPTCY', $eventDesc, -100.00)
        ];
    }
}


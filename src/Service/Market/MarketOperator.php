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
        /** Industry roster and capacity balance; null (unit tests) leaves a failed firm's plant to age out of it. */
        private ?\App\Service\Corporate\Industry\IndustryShareLedger $industryShareLedger = null,
        /** Settles the failed firm's listed debt; null (unit tests without a bond desk) leaves its issues alone. */
        private ?CorporateDefaultService $corporateDefault = null
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
                // Industry consolidation: the failed firm's plant leaves the capacity balance, its demand
                // does not, and the survivors sell into a tighter market at a better price. This used to
                // be approximated by handing peers a slice of the failed firm's addressable market.
                $this->industryShareLedger?->retireFirm($stock);
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

        // The creditors are not the shareholders. Equity has just been wiped to zero above, but a bondholder
        // stands ahead of it and is owed whatever the estate is worth — so the listed debt is settled at its
        // recovery rather than disappearing with the company. Skipping this would make credit strictly worse
        // than equity in the one scenario it exists to be better in.
        $settledIssues = $this->corporateDefault?->settle($stock, $macroState, new \DateTime()) ?? [];

        $this->entityManager->persist($stock);
        $this->entityManager->flush();

        // NOTE: We preserve stock_history, corporate_report, and stock_events.
        // Historical quarters, charts, and lore records remain frozen in place.

        $eventDesc = $isPaymentDefault
            ? "{$name} ({$stock->getTicker()}) missed a principal payment it could not refinance and filed for Chapter 7 bankruptcy liquidation. Shareholder equity wiped to 0 and trading permanently halted."
            : "{$name} ({$stock->getTicker()}) has collapsed into insolvency and filed for Chapter 7 bankruptcy liquidation. Shareholder equity wiped to 0 and trading permanently halted.";

        if ($settledIssues !== []) {
            // Face-weighted, not the first issue's: seniorities recover differently, so quoting one of them
            // as the answer would misreport the estate the moment a firm has more than one kind of claim.
            $totalFace = array_sum(array_column($settledIssues, 'face'));
            $recovery = $totalFace > 0.0
                ? (array_sum(array_map(
                    static fn (array $issue): float => $issue['recovery'] * $issue['face'],
                    $settledIssues
                )) / $totalFace) * 100.0
                : $settledIssues[0]['recovery'] * 100.0;

            $eventDesc .= sprintf(
                ' %d listed bond issue%s settled at %.1f cents on the dollar.',
                count($settledIssues),
                count($settledIssues) === 1 ? '' : 's',
                $recovery
            );
        }

        return [
            $this->marketEvent->publish($stock, 'BANKRUPTCY', $eventDesc, -100.00)
        ];
    }
}


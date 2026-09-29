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
        private ?CorporateDefaultService $corporateDefault = null,
        /** Puts a confirmed Chapter 11 plan into effect; stateless, so a unit test gets the real one. */
        private \App\Service\Corporate\ReorganizationEngine $reorganizationEngine = new \App\Service\Corporate\ReorganizationEngine(),
        /** Cash-settles the options on shares a plan cancels; null (unit tests without an options layer) leaves them. */
        private ?OptionSettlementEngine $optionSettlement = null
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
     * Decides whether a company fails this pass, and if it does, which way.
     *
     * A lender, an insurer or a REIT is closed on its capital ratio, as a regulator closes a bank whose equity
     * has fallen through the statutory floor; that path is unchanged. An operating company is different: it
     * files when it cannot pay what it owes, not when a credit score turns negative. Altman's Z'' predicts
     * failure, it does not adjudicate it, and liquidating on it wound up firms that were current on every
     * obligation — WEAV with $433B of equity, on a trailing EBIT that had counted a goodwill write-off. So the
     * trigger is the cash-flow test of insolvency: a payment default still uncured once the grace period has
     * lapsed, when the lenders accelerate. What follows is Chapter 11 for a business worth more alive than
     * broken up, and Chapter 7 for one that no longer covers its cash operating costs.
     *
     * @return array|null Market events if the company failed this pass, otherwise null.
     */
    private function applyRestructuringRule(Stock $stock, float $marketCap, string $name, MacroStateDTO $macroState): ?array
    {
        $strategy = \App\Data\Sectors::strategyFor($stock->getIndustry());
        $isAccelerated = $stock->isPaymentDefault()
            && $stock->getQuartersInDefault() > \App\Service\Math\FinancialConstants::PAYMENT_DEFAULT_GRACE_QUARTERS;

        if ($strategy->requiresAlternativeZScore()) {
            // The alternative score is a capital ratio; operating income does not enter it.
            $revenue = (float) $stock->getTotalRevenue();
            $margin = $stock->getReportedOperatingMargin() ?? (float) $stock->getOperatingMargin();
            $zScoreData = $this->debtEngine->calculateAltmanZScore($stock, $revenue * $margin, $revenue, (float) $stock->getPrice());
            if (!$zScoreData['is_bankrupt']) {
                return null;
            }

            // An insolvent firm that is ALSO past its cure period is a creditor-driven filing rather than a
            // balance sheet that simply ran out of room; the two read differently on the tape.
            return $this->liquidate($stock, $macroState, $isAccelerated
                ? "{$name} ({$stock->getTicker()}) failed to cure an event of default and was forced into Chapter 7 bankruptcy liquidation by its creditors."
                : "{$name} ({$stock->getTicker()}) has collapsed into insolvency and filed for Chapter 7 bankruptcy liquidation.");
        }

        if (!$isAccelerated) {
            return null;
        }

        $going = $this->debtEngine->assessGoingConcern($stock, $macroState);
        if (!$going->isViable()) {
            return $this->liquidate(
                $stock,
                $macroState,
                "{$name} ({$stock->getTicker()}) failed to cure an event of default, and with a business that no longer covers its cash operating costs it was liquidated under Chapter 7."
            );
        }

        return $this->reorganize($stock, $name, $macroState, $going);
    }

    /**
     * Chapter 7: the firm is wound up and leaves the board.
     *
     * - Status marked as bankrupt, share price drops to 0, volatility drops to 0.
     * - Open orders are cancelled (refunding cash for buy orders) and shareholder equity is wiped.
     * - The listed debt is settled at its recovery.
     * - All historical quarters, financial reports, lore events, and charts remain frozen in place.
     *
     * @return array Market events.
     */
    private function liquidate(Stock $stock, MacroStateDTO $macroState, string $filing): array
    {
        $this->logger->info("BANKRUPTCY DETECTED: {$stock->getName()} ({$stock->getTicker()}) liquidated. Company permanently terminated.");

        $stock->setIsBankrupt(true);
        $stock->setPrice('0.00000000');
        $stock->setCurrentVolatility('0.0000');
        $stock->setCreditRating('D');

        $this->cancelEquity($stock);

        // Industry consolidation: the failed firm's plant leaves the capacity balance, its demand does not,
        // and the survivors sell into a tighter market at a better price. A reorganized firm keeps operating
        // its plant, so only a liquidation retires it.
        $this->industryShareLedger?->retireFirm($stock);

        // The creditors are not the shareholders. Equity has just been wiped to zero above, but a bondholder
        // stands ahead of it and is owed whatever the estate is worth — so the listed debt is settled at its
        // recovery rather than disappearing with the company. Skipping this would make credit strictly worse
        // than equity in the one scenario it exists to be better in.
        $settledIssues = $this->corporateDefault?->settle($stock, $macroState, new \DateTime()) ?? [];

        $this->entityManager->persist($stock);
        $this->entityManager->flush();

        // NOTE: We preserve stock_history, corporate_report, and stock_events.
        // Historical quarters, charts, and lore records remain frozen in place.

        $eventDesc = $filing . ' Shareholder equity wiped to 0 and trading permanently halted.' . $this->describeSettledIssues($settledIssues);

        return [
            $this->marketEvent->publish($stock, 'BANKRUPTCY', $eventDesc, -100.00)
        ];
    }

    /**
     * Chapter 11: a prepackaged plan confirmed within the quarter, and the company keeps trading.
     *
     * The plan swaps the debt the business cannot carry for its equity, divided under absolute priority. When
     * the old holders are out of the money their shares are cancelled — positions, open orders and the listed
     * options written on them go with them — and the creditors own the reorganized company.
     *
     * @return array Market events.
     */
    private function reorganize(Stock $stock, string $name, MacroStateDTO $macroState, \App\DTO\GoingConcernDTO $going): array
    {
        $plan = $this->reorganizationEngine->planReorganization($stock, $macroState, $going);
        $priceBefore = (float) $stock->getPrice();

        if ($plan->oldEquityShare <= 0.0) {
            $this->cancelEquity($stock);
            $this->optionSettlement?->settleCancelledUnderlying($stock);
        }

        $openingPrice = $this->reorganizationEngine->applyPlan($stock, $plan);

        // Impaired notes are exchanged, so the listed issues settle at their recovery; a plan that reinstates
        // every claim leaves them outstanding on their own terms.
        $settledIssues = $plan->convertedClaims > 0.0
            ? ($this->corporateDefault?->settle($stock, $macroState, new \DateTime()) ?? [])
            : [];

        $this->entityManager->persist($stock);
        $this->entityManager->flush();

        $this->logger->info("REORGANIZATION: {$name} ({$stock->getTicker()}) emerged from Chapter 11.");

        $eventDesc = sprintf(
            '%s (%s) failed to cure an event of default and confirmed a prepackaged Chapter 11 plan: $%sB of debt exchanged for the reorganized equity and $%sB reinstated as new notes.',
            $name,
            $stock->getTicker(),
            number_format($plan->convertedClaims / 1_000_000_000, 2),
            number_format($plan->exitDebt / 1_000_000_000, 2)
        );
        $eventDesc .= match (true) {
            $plan->oldEquityShare <= 0.0 => ' The old shares were cancelled and the creditors now own the company.',
            $plan->oldEquityShare >= 1.0 => ' The creditors were reinstated in full and the shareholders keep the company.',
            default => sprintf(' The old shareholders keep %.1f%% of the reorganized company.', $plan->oldEquityShare * 100.0),
        };
        $eventDesc .= $this->describeSettledIssues($settledIssues);

        $changePercent = $plan->oldEquityShare <= 0.0
            ? -100.0
            : (($openingPrice / max(0.01, $priceBefore)) - 1.0) * 100.0;

        return [
            $this->marketEvent->publish($stock, 'REORGANIZATION', $eventDesc, $changePercent)
        ];
    }

    /**
     * Cancels the old equity: open orders are cancelled with buy escrow refunded, and every position is wiped.
     */
    private function cancelEquity(Stock $stock): void
    {
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

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM user_stocks WHERE stock_id = :id',
            ['id' => $stock->getId()]
        );
    }

    /**
     * How the listed debt came out, face-weighted rather than the first issue's: seniorities recover
     * differently, so quoting one of them as the answer would misreport the estate the moment a firm has more
     * than one kind of claim.
     *
     * @param array<int, array{ticker: string, recovery: float, face: float}> $settledIssues
     */
    private function describeSettledIssues(array $settledIssues): string
    {
        if ($settledIssues === []) {
            return '';
        }

        $totalFace = array_sum(array_column($settledIssues, 'face'));
        $recovery = $totalFace > 0.0
            ? (array_sum(array_map(
                static fn (array $issue): float => $issue['recovery'] * $issue['face'],
                $settledIssues
            )) / $totalFace) * 100.0
            : $settledIssues[0]['recovery'] * 100.0;

        return sprintf(
            ' %d listed bond issue%s settled at %.1f cents on the dollar.',
            count($settledIssues),
            count($settledIssues) === 1 ? '' : 's',
            $recovery
        );
    }
}

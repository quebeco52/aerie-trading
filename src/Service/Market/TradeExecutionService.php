<?php

namespace App\Service\Market;

use App\DTO\ExecutionQuoteDTO;
use App\DTO\ResolvedAssetDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Entity\UserBond;
use App\Entity\UserEtf;
use App\Entity\UserStock;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Math\MathUtility;
use App\Service\User\Portfolio;
use Doctrine\ORM\EntityManagerInterface;

class TradeExecutionService
{
    // --- Order Validation ---
    /** Lowest limit price an order may rest at, matching the $0.01 floor the price process itself clamps to. */
    public const MIN_LIMIT_PRICE = '0.01';

    /** Ceiling on a limit price. Escrow is stored at DECIMAL(18,4), so the price times the 1e9 quantity cap has to stay inside it. */
    public const MAX_LIMIT_PRICE = '100000000.0000';

    /**
     * The sides an order may be placed on. Anything not listed here fell through to the SELL branch and
     * escrowed shares against an order nothing would ever fill.
     *
     * SHORT and COVER are their own actions rather than a SELL that happens to exceed the position. Opening
     * a short by overselling would turn every fat-fingered sale into a borrowed position with unbounded
     * downside, and nothing in the order would record that the trader meant it.
     */
    public const VALID_ACTIONS = ['BUY', 'SELL', 'SHORT', 'COVER'];

    /** Sides that acquire stock and pay cash out. */
    private const BUY_SIDE_ACTIONS = ['BUY', 'COVER'];

    /** Sides that take on new exposure, and so have to be collateralized before they are allowed. */
    private const EXPOSURE_INCREASING_ACTIONS = ['BUY', 'SHORT'];

    public function __construct(
        private EntityManagerInterface $em,
        private Portfolio $portfolio,
        private \Redis $redis,
        private \Psr\Log\LoggerInterface $logger,
        private AssetResolver $assetResolver,
        private LiquidityEngine $liquidityEngine,
        private OrderFlowStoreInterface $orderFlow,
        private MarginEngine $marginEngine,
        private SecuritiesLendingDesk $lendingDesk,
        private OptionTradeService $optionTradeService,
        private \App\Service\User\CashLedger $cashLedger
    ) {}

    /** Whether an action buys stock (and pays cash) or sells it (and receives cash). */
    public static function isBuySide(string $action): bool
    {
        return in_array($action, self::BUY_SIDE_ACTIONS, true);
    }

    /**
     * Places one order and reports which asset class it was filled in.
     *
     * The class comes back because the units differ by it — an option order is counted in contracts of a
     * hundred shares — and the caller that has to say what just happened would otherwise be left inferring
     * it from the ticker's shape, which a bond symbol such as G02-001 defeats.
     *
     * @return string The asset class filled: STOCK, ETF, BOND or OPTION.
     */
    public function executeOrder(User $user, string $ticker, string $action, string $orderType, int $quantity, ?string $limitPrice = null): string
    {
        if ($quantity <= 0) {
            throw new \Exception('Invalid quantity.');
        }

        // Loose here, strict once the instrument is known. The two desks share BUY, SELL and COVER but
        // differ on the fourth — an equity is SHORTed and a contract is WRITTEN — and which one is legal is
        // a property of what is being traded, so it cannot be decided before the ticker has been resolved.
        if (!in_array($action, self::VALID_ACTIONS, true) && !in_array($action, OptionTradeService::VALID_ACTIONS, true)) {
            throw new \Exception('Invalid order action.');
        }

        if ($orderType === 'LIMIT') {
            $limitPrice = $this->validateLimitPrice($limitPrice);
        }

        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);

            $asset = $this->assetResolver->resolve($ticker);

            if ($asset === null) {
                throw new \Exception('Asset not found.');
            }

            $haltReason = $asset->haltReason();
            if ($haltReason !== null) {
                throw new \Exception($haltReason);
            }

            // An option settles in contracts, is paid for in full, and is collateralized against its
            // underlying rather than against itself, so it is filled by its own desk. Routing here rather
            // than at the controller keeps one front door: a player types a symbol, and what happens next is
            // a property of the symbol.
            if ($asset->entity instanceof OptionContract) {
                if ($orderType !== 'MARKET') {
                    throw new \Exception('Listed options trade at market. A resting option order is not supported yet.');
                }

                $this->optionTradeService->execute($user, $asset->entity, $action, $quantity);

                $this->em->flush();
                $this->portfolio->recordUserSnapshot($user);
                $this->em->flush();
                $this->em->getConnection()->commit();

                return 'OPTION';
            }

            if (!in_array($action, self::VALID_ACTIONS, true)) {
                throw new \Exception("{$action} is not an action on {$asset->ticker()}.");
            }

            $assetType = $asset->type;
            $livePrice = (float) $asset->price();
            $quantityStr = (string) $quantity;
            $currentCashStr = (string) $user->getCashBalance();

            $stock = $asset->entity instanceof Stock ? $asset->entity : null;

            // Size has to be checked against depth before anything else: past a couple of days' volume the
            // square-root law is extrapolation, and quoting an extrapolated price would be a made-up number
            // presented as a fill.
            if ($stock !== null) {
                $maximum = $this->liquidityEngine->maximumOrderSize($stock);
                if ($quantity > $maximum) {
                    throw new \Exception(sprintf(
                        'Order exceeds available liquidity in %s. The desk will take up to %s shares at once; work larger positions in pieces.',
                        $asset->ticker(),
                        number_format(floor($maximum))
                    ));
                }
            }

            $quote = $this->liquidityEngine->quoteAsset(
                $stock,
                $assetType,
                $action,
                $quantity,
                $livePrice,
                $asset->entity instanceof \App\Entity\Bond && !$asset->entity->isSovereign()
            );
            $livePriceStr = MathUtility::formatDecimal($quote->executionPrice, 4);
            $totalValueStr = \bcmul($livePriceStr, $quantityStr, 4);

            $userAsset = $this->assetResolver->findHolding($user, $asset);

            $order = new TradeOrder();
            $order->setUser($user);
            $order->setTicker($ticker);
            $order->setAssetType($assetType);
            $order->setAction($action);
            $order->setOrderType($orderType);
            $order->setQuantity($quantity);
            $order->setLimitPrice($limitPrice);

            if ($orderType === 'MARKET') {
                // Execute immediately at market price
                $userAsset = $this->settlePosition($user, $asset, $userAsset, $action, $quantity, $totalValueStr, $stock);

                $this->recordFill($order, $quote, $quantity, $action, $asset->ticker(), $assetType);
                $this->em->persist($order);

            } else if ($orderType === 'LIMIT') {
                // Check if it crosses immediately
                $shouldFillImmediately = false;
                $limitPriceFloat = (float) $limitPrice;

                // Tested against the price the order would actually fill at, not against mid. A limit is a
                // promise about the worst price the trader will accept, and crossing on mid would break it
                // the moment the spread or the order's own impact pushed the fill through the limit.
                if (self::isBuySide($action) && $limitPriceFloat >= $quote->executionPrice) {
                    $shouldFillImmediately = true;
                } elseif (!self::isBuySide($action) && $limitPriceFloat <= $quote->executionPrice) {
                    $shouldFillImmediately = true;
                }

                if ($shouldFillImmediately) {
                    // Fill immediately at LIVE PRICE, not limit price (better execution)
                    $userAsset = $this->settlePosition($user, $asset, $userAsset, $action, $quantity, $totalValueStr, $stock);
                    $this->recordFill($order, $quote, $quantity, $action, $asset->ticker(), $assetType);
                } else {
                    // Escrow and save as OPEN
                    if ($action === 'BUY') {
                        $escrowCashStr = \bcmul((string) $limitPrice, $quantityStr, 4);
                        $this->requireFunding($user, (float) $escrowCashStr, $action);
                        $this->cashLedger->debit($user, $escrowCashStr);
                    } elseif ($action === 'COVER') {
                        // A resting COVER escrows nothing, for the same reason it is not gated on buying
                        // power: it is the closing leg of a position that is already collateralized. Holding
                        // the cash here would do the opposite of what the fill does — the borrow stays open
                        // while the cash leaves, so equity falls by the full notional while the requirement
                        // does not move, and the account can be called for placing the one order that would
                        // have saved it. The purchase is paid for when it fills.
                        $held = $userAsset !== null ? (int) $userAsset->getQuantity() : 0;
                        if ($held >= 0 || abs($held) < $quantity) {
                            throw new \Exception('No short position of that size to cover.');
                        }
                    } elseif ($action === 'SHORT') {
                        // A resting short escrows nothing. Borrow is located and margin is checked when it
                        // fills, not when it is placed: reserving stock against an order that may never
                        // cross would let a trader take a name hard-to-borrow for everyone else with an
                        // offer nobody can hit.
                        if ($stock === null) {
                            throw new \Exception('Only equities can be sold short.');
                        }
                        if (!$user->isMarginEnabled()) {
                            throw new \Exception('Short selling requires a margin account.');
                        }
                    } else { // SELL
                        if (!$userAsset || $userAsset->getQuantity() < $quantity) {
                            throw new \Exception('Insufficient shares for limit order.');
                        }
                        $this->assetResolver->removeFromHolding($userAsset, $quantity);
                    }
                    $order->setStatus(TradeOrder::STATUS_OPEN);
                }
                
                $this->em->persist($order);
            } else {
                throw new \Exception('Invalid order type.');
            }

            $this->em->persist($user);
            $this->em->flush();
            $this->portfolio->recordUserSnapshot($user);
            $this->em->flush();
            $this->em->getConnection()->commit();

            if ($orderType === 'LIMIT' && $order->getStatus() === TradeOrder::STATUS_OPEN) {
                $this->updateRedisBounds($ticker);
            }

            return $assetType;

        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Moves the stock and the cash for one fill, in whichever of the four directions the action names.
     *
     * One place for all of it, because the four actions differ in exactly two ways — which side of the
     * position they move, and what has to be true beforehand — and spelling that out four times is how the
     * pre-trade checks drift apart from each other.
     *
     * @param string $totalValue Consideration for the fill, as a bcmath string.
     * @param Stock|null $stock  The instrument when it is an equity; short selling exists only for equities.
     */
    private function settlePosition(
        User $user,
        ResolvedAssetDTO $asset,
        UserStock|UserEtf|UserBond|null $userAsset,
        string $action,
        int $quantity,
        string $totalValue,
        ?Stock $stock
    ): UserStock|UserEtf|UserBond {
        $held = $userAsset !== null ? (int) $userAsset->getQuantity() : 0;

        if (self::isBuySide($action)) {
            if ($action === 'COVER') {
                if ($held >= 0 || abs($held) < $quantity) {
                    throw new \Exception('No short position of that size to cover.');
                }
            }

            $this->requireFunding($user, (float) $totalValue, $action);
            $this->cashLedger->debit($user, $totalValue);

            $userAsset = $this->assetResolver->addToHolding($user, $asset, $userAsset, $quantity);

            if ($action === 'COVER' && $stock !== null) {
                $this->adjustShortInterest($stock, -$quantity);
            }

            return $userAsset;
        }

        if ($action === 'SHORT') {
            if ($stock === null) {
                throw new \Exception('Only equities can be sold short.');
            }
            if (!$user->isMarginEnabled()) {
                throw new \Exception('Short selling requires a margin account.');
            }
            if ($held < 0 && $userAsset === null) {
                throw new \Exception('Position lookup failed.');
            }

            $available = $this->lendingDesk->availableToBorrow($stock);
            if ($quantity > $available) {
                throw new \Exception(sprintf(
                    'Only %s shares of %s are available to borrow.',
                    number_format(floor($available)),
                    $stock->getTicker()
                ));
            }

            $this->requireFunding($user, (float) $totalValue, $action);

            // Proceeds are credited, then immediately collateralize the borrowed stock. They are not free
            // cash: MarginEngine subtracts the position's market value straight back out of equity.
            $this->cashLedger->credit($user, $totalValue);
            $userAsset = $this->assetResolver->addToHolding($user, $asset, $userAsset, -$quantity);
            $this->adjustShortInterest($stock, $quantity);

            return $userAsset;
        }

        // SELL
        if ($userAsset === null || $held < $quantity) {
            throw new \Exception('Insufficient shares.');
        }

        $this->cashLedger->credit($user, $totalValue);
        $this->assetResolver->removeFromHolding($userAsset, $quantity);

        return $userAsset;
    }

    /**
     * Rejects a trade the account cannot collateralize.
     *
     * A cash account is measured against settled cash; a margin account against buying power, which is what
     * is left once the existing book is collateralized, geared by the initial requirement.
     */
    private function requireFunding(User $user, float $notional, string $action): void
    {
        if (!$user->isMarginEnabled()) {
            if ($action === 'SHORT') {
                throw new \Exception('Short selling requires a margin account.');
            }

            if ($notional > (float) $user->getCashBalance() + 1e-6) {
                throw new \Exception('Insufficient funds.');
            }

            return;
        }

        // Buying power gates new exposure only. COVER closes a borrow: it releases the collateral standing
        // behind the position, so the maintenance requirement falls by more than the cash the purchase
        // consumes and the account ends up safer than it started. Gating it would block the one trade that
        // repairs a distressed short — and since buying power at the Reg-T initial requirement is zero by
        // construction, it blocked covering on healthy accounts too, which silently disabled every forced
        // buy-in in ForcedLiquidationService and left the recall leg of a squeeze unable to complete.
        if (!in_array($action, self::EXPOSURE_INCREASING_ACTIONS, true)) {
            return;
        }

        if (!$this->marginEngine->canOpen($user, $notional)) {
            throw new \Exception('Insufficient buying power for this order.');
        }
    }

    /**
     * Moves a name's total short interest, which is what prices its borrow for everyone.
     *
     * @param int $delta Shares added to (positive) or removed from (negative) the total.
     */
    private function adjustShortInterest(Stock $stock, int $delta): void
    {
        $updated = max(0.0, (float) $stock->getShortInterestShares() + $delta);
        $stock->setShortInterestShares(MathUtility::formatDecimal($updated, 2));
    }

    /**
     * Stamps a fill onto its order and reports the flow it generated.
     *
     * The signed quantity goes to the order flow store rather than moving the price here: impact is applied
     * once per tick, on the ticker, against the net of everything that traded in that window. Applying it
     * per order would let a trader split a position into a hundred pieces and pay a hundred separate
     * square roots, which is cheaper than the one they should have paid.
     */
    private function recordFill(TradeOrder $order, ExecutionQuoteDTO $quote, int $quantity, string $action, string $ticker, string $assetType): void
    {
        $order->setFilledQuantity($quantity);
        $order->setExecutionPrice(MathUtility::formatDecimal($quote->executionPrice, 4));
        $order->setSpreadCost(MathUtility::formatDecimal($quote->spreadCost, 4));
        $order->setImpactCost(MathUtility::formatDecimal($quote->impactCost, 4));
        $order->setStatus(TradeOrder::STATUS_FILLED);
        $order->setFilledAt(new \DateTime());

        if ($assetType === 'STOCK') {
            // A cover buys stock and a short sells it, so flow follows the side rather than the label.
            $this->orderFlow->record($ticker, self::isBuySide($action) ? (float) $quantity : -(float) $quantity);
        }
    }

    /**
     * Normalizes a limit price, rejecting anything the escrow arithmetic cannot safely hold.
     *
     * The price arrives straight off the request. Unvalidated, a NEGATIVE limit inverted the escrow: the
     * funds check passed against a negative requirement and the debit became a credit, so placing a BUY at
     * -5 minted cash. A non-numeric one reached bcmath, which throws a ValueError — an Error, not an
     * Exception, so neither this class nor the controller caught it and the open transaction leaked.
     */
    private function validateLimitPrice(?string $limitPrice): string
    {
        if ($limitPrice === null || !is_numeric(trim($limitPrice))) {
            throw new \Exception('A limit order requires a numeric limit price.');
        }

        // Rendered rather than passed through: is_numeric accepts "1e5", and bcmath rejects exponent
        // notation outright. %F never emits one, so this is the form the escrow arithmetic can consume.
        $normalized = sprintf('%.4F', (float) trim($limitPrice));

        if (\bccomp($normalized, self::MIN_LIMIT_PRICE, 4) < 0) {
            throw new \Exception('Limit price must be at least $' . self::MIN_LIMIT_PRICE . '.');
        }

        if (\bccomp($normalized, self::MAX_LIMIT_PRICE, 4) > 0) {
            throw new \Exception('Limit price is above the maximum accepted value.');
        }

        return $normalized;
    }

    public function cancelOrder(User $user, int $orderId): void
    {
        $this->em->getConnection()->beginTransaction();
        try {
            $this->em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);

            $order = $this->em->getRepository(TradeOrder::class)->findOpenForUserById($user, $orderId);
            if (!$order) {
                throw new \Exception('Order not found or already processed.');
            }

            $ticker = $order->getTicker();
            $quantity = $order->getQuantity();

            if ($order->getAction() === 'BUY') {
                // Refund cash, paying down the borrowing it was drawn from first.
                $this->cashLedger->credit($user, \bcmul((string) $order->getLimitPrice(), (string) $quantity, 4));
            } elseif ($order->getAction() === 'SELL') {
                // Refund the escrowed shares. A resting SHORT and a resting COVER escrowed nothing, so
                // there is nothing to give back and no branch for either.
                $asset = $this->assetResolver->resolve($ticker);
                if ($asset === null) {
                    throw new \Exception('Asset not found.');
                }

                $userAsset = $this->assetResolver->findHolding($user, $asset);
                $this->assetResolver->addToHolding($user, $asset, $userAsset, $quantity);
            }

            $order->setStatus(TradeOrder::STATUS_CANCELLED);
            $this->em->persist($order);
            $this->em->persist($user);
            $this->em->flush();
            $this->em->getConnection()->commit();

            $this->updateRedisBounds($ticker);

        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            throw $e;
        }
    }

    public function processLimitOrders(string $ticker, float $currentPrice): void
    {
        // Runs on the messenger worker: ProcessLimitOrdersMessage is routed to the async transport, so the
        // ticker only enqueues and never carries these queries or row locks inside its tick transaction.
        $openOrders = $this->em->getRepository(TradeOrder::class)->findOpenByTicker($ticker);

        foreach ($openOrders as $order) {
            $limitPrice = (float) $order->getLimitPrice();
            $shouldFill = false;

            if (self::isBuySide($order->getAction()) && $currentPrice <= $limitPrice) {
                $shouldFill = true;
            } elseif (!self::isBuySide($order->getAction()) && $currentPrice >= $limitPrice) {
                $shouldFill = true;
            }

            if ($shouldFill) {
                $this->fillOpenOrder($order, $currentPrice);
            }
        }

        // Always leave the book's bounds in Redis, even when nothing filled and even when there is nothing
        // open. The ticker only checks a ticker whose bounds key exists, so a flushed or evicted Redis
        // otherwise left every resting order un-checked forever — this call is what heals that, and writing
        // the empty case too stops a ticker with no orders from being re-checked on every tick.
        $this->updateRedisBounds($ticker);
    }

    private function fillOpenOrder(TradeOrder $order, float $executionPrice): void
    {
        $this->em->getConnection()->beginTransaction();
        try {
            $user = $order->getUser();
            $this->em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);

            // Re-check status in case it was cancelled while in queue. The entity has to be refreshed to
            // read it: $order came out of the identity map before the lock was taken, so a cancel committed
            // by the web process in the meantime is invisible here and the order would fill a second time
            // against escrow that has already been refunded.
            $this->em->refresh($order);

            if ($order->getStatus() !== TradeOrder::STATUS_OPEN) {
                $this->em->getConnection()->rollBack();
                return;
            }

            $ticker = $order->getTicker();
            $quantity = $order->getQuantity();
            $limitPrice = (float) $order->getLimitPrice();

            $asset = $this->assetResolver->resolve($ticker);
            if ($asset === null) {
                throw new \Exception('Asset not found.');
            }

            $stock = $asset->entity instanceof Stock ? $asset->entity : null;
            $quote = $this->liquidityEngine->quoteAsset($stock, $asset->type, $order->getAction(), $quantity, $executionPrice);

            // A resting order fills at or better than its limit, never through it. The touch that triggered
            // this fill was the mid, and the spread and the order's own impact sit on top of it: without
            // this check a BUY resting at 100 could fill at 100.30 the moment the mid ticked to 100.
            if (self::isBuySide($order->getAction()) && $quote->executionPrice > $limitPrice) {
                $this->em->getConnection()->rollBack();
                return;
            }
            if (!self::isBuySide($order->getAction()) && $quote->executionPrice < $limitPrice) {
                $this->em->getConnection()->rollBack();
                return;
            }

            $fillPrice = $quote->executionPrice;
            $userAsset = $this->assetResolver->findHolding($user, $asset);

            $action = $order->getAction();
            $considerationStr = \bcmul(MathUtility::formatDecimal($fillPrice, 4), (string) $quantity, 4);

            if ($action === 'COVER') {
                // Nothing was escrowed when this rested, so the purchase is paid for now. It needs no
                // funding gate for the same reason the immediate path does not: the cash out and the borrow
                // released cancel in equity, and the requirement falls by the short's maintenance rate.
                //
                // The position is re-checked here rather than trusted from placement: the short can have
                // been closed by another order, by a buy-in, or by this same order filling in pieces while
                // this one rested, and covering stock the account no longer owes would open a long.
                $held = $userAsset !== null ? (int) $userAsset->getQuantity() : 0;
                if ($held >= 0 || abs($held) < $quantity) {
                    $this->em->getConnection()->rollBack();
                    return;
                }

                $this->cashLedger->debit($user, $considerationStr);

                if ($stock !== null) {
                    $this->adjustShortInterest($stock, -$quantity);
                }

                $this->assetResolver->addToHolding($user, $asset, $userAsset, $quantity);

            } elseif ($action === 'BUY') {
                // Cash was escrowed at the limit. A better fill refunds the difference, paying down any
                // borrowing the escrow was drawn from before it reaches the settled balance.
                $refundStr = \bcsub(\bcmul((string) $limitPrice, (string) $quantity, 4), $considerationStr, 4);

                if (\bccomp($refundStr, '0.0000', 4) > 0) {
                    $this->cashLedger->credit($user, $refundStr);
                }

                $this->assetResolver->addToHolding($user, $asset, $userAsset, $quantity);

            } elseif ($action === 'SHORT') {
                // Nothing was escrowed, so the borrow is located and the margin checked now. Either can
                // have gone against the order while it rested, and a short opened without a locate is a
                // position the desk cannot actually deliver.
                if ($stock === null || $quantity > $this->lendingDesk->availableToBorrow($stock)) {
                    $this->em->getConnection()->rollBack();
                    return;
                }

                if (!$this->marginEngine->canOpen($user, (float) $considerationStr)) {
                    $this->em->getConnection()->rollBack();
                    return;
                }

                $this->cashLedger->credit($user, $considerationStr);
                $this->assetResolver->addToHolding($user, $asset, $userAsset, -$quantity);
                $this->adjustShortInterest($stock, $quantity);

            } else { // SELL
                // Shares were already escrowed. Just give them the cash from the sale.
                $this->cashLedger->credit($user, $considerationStr);
            }

            $this->recordFill($order, $quote, $quantity, $order->getAction(), $ticker, $asset->type);

            $this->em->persist($order);
            $this->em->persist($user);
            $this->em->flush();
            $this->portfolio->recordUserSnapshot($user);
            $this->em->flush();
            $this->em->getConnection()->commit();

            $this->updateRedisBounds($ticker);

        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }

            // The order stays OPEN with its escrow still held, so this has to be visible: silently swallowing
            // it left a user's money committed to an order that would never be reported as having failed.
            $this->logger->error('Limit order fill failed; order left open with escrow held.', [
                'order_id' => $order->getId(),
                'ticker' => $order->getTicker(),
                'execution_price' => $executionPrice,
                'exception' => $e,
            ]);
        }
    }

    private function updateRedisBounds(string $ticker): void
    {
        // Finds the highest BUY limit and lowest SELL limit
        $conn = $this->em->getConnection();
        
        $sqlBuy = "SELECT MAX(limit_price) as max_buy FROM trade_orders WHERE ticker = :ticker AND action = 'BUY' AND status = 'OPEN'";
        $buyResult = $conn->executeQuery($sqlBuy, ['ticker' => $ticker])->fetchOne();
        $highestBuy = $buyResult ? (float)$buyResult : 0.0;

        $sqlSell = "SELECT MIN(limit_price) as min_sell FROM trade_orders WHERE ticker = :ticker AND action = 'SELL' AND status = 'OPEN'";
        $sellResult = $conn->executeQuery($sqlSell, ['ticker' => $ticker])->fetchOne();
        $lowestSell = $sellResult ? (float)$sellResult : 999999999.0;

        $this->redis->set("limit_bounds:$ticker", json_encode([
            'buy' => $highestBuy,
            'sell' => $lowestSell
        ]));
    }

}

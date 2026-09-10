<?php

namespace App\Service\Market;

use App\DTO\ExecutionQuoteDTO;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
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

    /** The only two sides an order may be placed on. Anything else fell through to the SELL branch and escrowed shares against an order nothing would ever fill. */
    public const VALID_ACTIONS = ['BUY', 'SELL'];

    public function __construct(
        private EntityManagerInterface $em,
        private Portfolio $portfolio,
        private \Redis $redis,
        private \Psr\Log\LoggerInterface $logger,
        private AssetResolver $assetResolver,
        private LiquidityEngine $liquidityEngine,
        private OrderFlowStoreInterface $orderFlow
    ) {}

    public function executeOrder(User $user, string $ticker, string $action, string $orderType, int $quantity, ?string $limitPrice = null): void
    {
        if ($quantity <= 0) {
            throw new \Exception('Invalid quantity.');
        }

        if (!in_array($action, self::VALID_ACTIONS, true)) {
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

            $quote = $this->liquidityEngine->quoteAsset($stock, $assetType, $action, $quantity, $livePrice);
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
                if ($action === 'BUY') {
                    if (\bccomp($currentCashStr, $totalValueStr, 4) < 0) {
                        throw new \Exception('Insufficient funds.');
                    }
                    $user->setCashBalance(\bcsub($currentCashStr, $totalValueStr, 4));
                    $userAsset = $this->assetResolver->addToHolding($user, $asset, $userAsset, $quantity);
                } else { // SELL
                    if (!$userAsset || $userAsset->getQuantity() < $quantity) {
                        throw new \Exception('Insufficient shares.');
                    }
                    $user->setCashBalance(\bcadd($currentCashStr, $totalValueStr, 4));
                    $this->assetResolver->removeFromHolding($userAsset, $quantity);
                }

                $this->recordFill($order, $quote, $quantity, $action, $asset->ticker(), $assetType);
                $this->em->persist($order);

            } else if ($orderType === 'LIMIT') {
                // Check if it crosses immediately
                $shouldFillImmediately = false;
                $limitPriceFloat = (float) $limitPrice;

                // Tested against the price the order would actually fill at, not against mid. A limit is a
                // promise about the worst price the trader will accept, and crossing on mid would break it
                // the moment the spread or the order's own impact pushed the fill through the limit.
                if ($action === 'BUY' && $limitPriceFloat >= $quote->executionPrice) {
                    $shouldFillImmediately = true;
                } elseif ($action === 'SELL' && $limitPriceFloat <= $quote->executionPrice) {
                    $shouldFillImmediately = true;
                }

                if ($shouldFillImmediately) {
                    // Fill immediately at LIVE PRICE, not limit price (better execution)
                    if ($action === 'BUY') {
                        if (\bccomp($currentCashStr, $totalValueStr, 4) < 0) {
                            throw new \Exception('Insufficient funds.');
                        }
                        $user->setCashBalance(\bcsub($currentCashStr, $totalValueStr, 4));
                        $userAsset = $this->assetResolver->addToHolding($user, $asset, $userAsset, $quantity);
                    } else { // SELL
                        if (!$userAsset || $userAsset->getQuantity() < $quantity) {
                            throw new \Exception('Insufficient shares.');
                        }
                        $user->setCashBalance(\bcadd($currentCashStr, $totalValueStr, 4));
                        $this->assetResolver->removeFromHolding($userAsset, $quantity);
                    }
                    $this->recordFill($order, $quote, $quantity, $action, $asset->ticker(), $assetType);
                } else {
                    // Escrow and save as OPEN
                    if ($action === 'BUY') {
                        $escrowCashStr = \bcmul((string) $limitPrice, $quantityStr, 4);
                        if (\bccomp($currentCashStr, $escrowCashStr, 4) < 0) {
                            throw new \Exception('Insufficient funds for limit order.');
                        }
                        $user->setCashBalance(\bcsub($currentCashStr, $escrowCashStr, 4));
                    } else { // SELL
                        if (!$userAsset || $userAsset->getQuantity() < $quantity) {
                            throw new \Exception('Insufficient shares for limit order.');
                        }
                        $this->assetResolver->removeFromHolding($userAsset, $quantity);
                    }
                    $order->setStatus('OPEN');
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

            if ($orderType === 'LIMIT' && $order->getStatus() === 'OPEN') {
                $this->updateRedisBounds($ticker);
            }

        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            throw $e;
        }
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
        $order->setStatus('FILLED');
        $order->setFilledAt(new \DateTime());

        if ($assetType === 'STOCK') {
            $this->orderFlow->record($ticker, $action === 'BUY' ? (float) $quantity : -(float) $quantity);
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

            $order = $this->em->getRepository(TradeOrder::class)->findOneBy(['id' => $orderId, 'user' => $user, 'status' => 'OPEN']);
            if (!$order) {
                throw new \Exception('Order not found or already processed.');
            }

            $ticker = $order->getTicker();
            $quantity = $order->getQuantity();

            if ($order->getAction() === 'BUY') {
                // Refund cash
                $escrowCashStr = \bcmul((string) $order->getLimitPrice(), (string) $quantity, 4);
                $user->setCashBalance(\bcadd((string) $user->getCashBalance(), $escrowCashStr, 4));
            } else {
                // Refund the escrowed units
                $asset = $this->assetResolver->resolve($ticker);
                if ($asset === null) {
                    throw new \Exception('Asset not found.');
                }

                $userAsset = $this->assetResolver->findHolding($user, $asset);
                $this->assetResolver->addToHolding($user, $asset, $userAsset, $quantity);
            }

            $order->setStatus('CANCELLED');
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
        // This is called by the background worker
        $openOrders = $this->em->getRepository(TradeOrder::class)->findBy(['ticker' => $ticker, 'status' => 'OPEN']);

        foreach ($openOrders as $order) {
            $limitPrice = (float) $order->getLimitPrice();
            $shouldFill = false;

            if ($order->getAction() === 'BUY' && $currentPrice <= $limitPrice) {
                $shouldFill = true;
            } elseif ($order->getAction() === 'SELL' && $currentPrice >= $limitPrice) {
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

            if ($order->getStatus() !== 'OPEN') {
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
            if ($order->getAction() === 'BUY' && $quote->executionPrice > $limitPrice) {
                $this->em->getConnection()->rollBack();
                return;
            }
            if ($order->getAction() === 'SELL' && $quote->executionPrice < $limitPrice) {
                $this->em->getConnection()->rollBack();
                return;
            }

            $fillPrice = $quote->executionPrice;
            $userAsset = $this->assetResolver->findHolding($user, $asset);

            if ($order->getAction() === 'BUY') {
                // Cash was already escrowed at limit price. If execution price is better (lower), refund the difference.
                $escrowedCashStr = \bcmul((string) $limitPrice, (string) $quantity, 4);
                $actualCostStr = \bcmul(MathUtility::formatDecimal($fillPrice, 4), (string) $quantity, 4);
                $refundStr = \bcsub($escrowedCashStr, $actualCostStr, 4);

                if (\bccomp($refundStr, '0.0000', 4) > 0) {
                    $user->setCashBalance(\bcadd((string) $user->getCashBalance(), $refundStr, 4));
                }
                
                $this->assetResolver->addToHolding($user, $asset, $userAsset, $quantity);

            } else { // SELL
                // Shares were already escrowed. Just give them the cash from the sale.
                $saleValueStr = \bcmul(MathUtility::formatDecimal($fillPrice, 4), (string) $quantity, 4);
                $user->setCashBalance(\bcadd((string) $user->getCashBalance(), $saleValueStr, 4));
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

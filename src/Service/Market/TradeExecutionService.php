<?php

namespace App\Service\Market;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Entity\UserEtf;
use App\Entity\UserStock;
use App\Service\User\Portfolio;
use Doctrine\ORM\EntityManagerInterface;

class TradeExecutionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Portfolio $portfolio,
        private \Redis $redis
    ) {}

    public function executeOrder(User $user, string $ticker, string $action, string $orderType, int $quantity, ?string $limitPrice = null): void
    {
        if ($quantity <= 0) {
            throw new \Exception('Invalid quantity.');
        }

        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);

            $stock = $this->em->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
            $etf = null;
            if (!$stock) {
                $etf = $this->em->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
            }

            if (!$stock && !$etf) {
                throw new \Exception('Asset not found.');
            }

            $asset = $stock ?? $etf;
            $assetType = $stock ? 'STOCK' : 'ETF';
            $livePrice = (float) $asset->getPrice();
            $totalValue = $livePrice * $quantity;
            $currentCash = (float) $user->getCashBalance();

            $userAsset = null;
            if ($stock) {
                $userAsset = $this->em->getRepository(UserStock::class)->findOneBy(['user' => $user, 'stock' => $stock]);
            } else {
                $userAsset = $this->em->getRepository(UserEtf::class)->findOneBy(['user' => $user, 'etf' => $etf]);
            }

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
                    if ($currentCash < $totalValue) {
                        throw new \Exception('Insufficient funds.');
                    }
                    $user->setCashBalance((string)($currentCash - $totalValue));
                    $userAsset = $this->addAssetToUser($user, $stock, $etf, $userAsset, $quantity);
                } else { // SELL
                    if (!$userAsset || $userAsset->getQuantity() < $quantity) {
                        throw new \Exception('Insufficient shares.');
                    }
                    $user->setCashBalance((string)($currentCash + $totalValue));
                    $this->removeAssetFromUser($userAsset, $quantity);
                }

                $order->setFilledQuantity($quantity);
                $order->setExecutionPrice((string)$livePrice);
                $order->setStatus('FILLED');
                $order->setFilledAt(new \DateTime());
                $this->em->persist($order);

            } else if ($orderType === 'LIMIT') {
                // Check if it crosses immediately
                $shouldFillImmediately = false;
                $limitPriceFloat = (float) $limitPrice;

                if ($action === 'BUY' && $limitPriceFloat >= $livePrice) {
                    $shouldFillImmediately = true;
                } elseif ($action === 'SELL' && $limitPriceFloat <= $livePrice) {
                    $shouldFillImmediately = true;
                }

                if ($shouldFillImmediately) {
                    // Fill immediately at LIVE PRICE, not limit price (better execution)
                    if ($action === 'BUY') {
                        if ($currentCash < $totalValue) {
                            throw new \Exception('Insufficient funds.');
                        }
                        $user->setCashBalance((string)($currentCash - $totalValue));
                        $userAsset = $this->addAssetToUser($user, $stock, $etf, $userAsset, $quantity);
                    } else { // SELL
                        if (!$userAsset || $userAsset->getQuantity() < $quantity) {
                            throw new \Exception('Insufficient shares.');
                        }
                        $user->setCashBalance((string)($currentCash + $totalValue));
                        $this->removeAssetFromUser($userAsset, $quantity);
                    }
                    $order->setFilledQuantity($quantity);
                    $order->setExecutionPrice((string)$livePrice);
                    $order->setStatus('FILLED');
                    $order->setFilledAt(new \DateTime());
                } else {
                    // Escrow and save as OPEN
                    if ($action === 'BUY') {
                        $escrowCash = $limitPriceFloat * $quantity;
                        if ($currentCash < $escrowCash) {
                            throw new \Exception('Insufficient funds for limit order.');
                        }
                        $user->setCashBalance((string)($currentCash - $escrowCash));
                    } else { // SELL
                        if (!$userAsset || $userAsset->getQuantity() < $quantity) {
                            throw new \Exception('Insufficient shares for limit order.');
                        }
                        $this->removeAssetFromUser($userAsset, $quantity);
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

        } catch (\Exception $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            throw $e;
        }
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
                $escrowCash = (float)$order->getLimitPrice() * $quantity;
                $user->setCashBalance((string)((float)$user->getCashBalance() + $escrowCash));
            } else {
                // Refund shares
                $stock = $this->em->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
                $etf = null;
                if (!$stock) {
                    $etf = $this->em->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
                }

                $userAsset = null;
                if ($stock) {
                    $userAsset = $this->em->getRepository(UserStock::class)->findOneBy(['user' => $user, 'stock' => $stock]);
                } else {
                    $userAsset = $this->em->getRepository(UserEtf::class)->findOneBy(['user' => $user, 'etf' => $etf]);
                }

                $this->addAssetToUser($user, $stock, $etf, $userAsset, $quantity);
            }

            $order->setStatus('CANCELLED');
            $this->em->persist($order);
            $this->em->persist($user);
            $this->em->flush();
            $this->em->getConnection()->commit();

            $this->updateRedisBounds($ticker);

        } catch (\Exception $e) {
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
    }

    private function fillOpenOrder(TradeOrder $order, float $executionPrice): void
    {
        $this->em->getConnection()->beginTransaction();
        try {
            $user = $order->getUser();
            $this->em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);

            // Re-check status in case it was cancelled while in queue
            if ($order->getStatus() !== 'OPEN') {
                $this->em->getConnection()->rollBack();
                return;
            }

            $ticker = $order->getTicker();
            $quantity = $order->getQuantity();
            $limitPrice = (float) $order->getLimitPrice();

            $stock = $this->em->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
            $etf = null;
            if (!$stock) {
                $etf = $this->em->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
            }

            $userAsset = null;
            if ($stock) {
                $userAsset = $this->em->getRepository(UserStock::class)->findOneBy(['user' => $user, 'stock' => $stock]);
            } else {
                $userAsset = $this->em->getRepository(UserEtf::class)->findOneBy(['user' => $user, 'etf' => $etf]);
            }

            if ($order->getAction() === 'BUY') {
                // Cash was already escrowed at limit price. If execution price is better (lower), refund the difference.
                $escrowedCash = $limitPrice * $quantity;
                $actualCost = $executionPrice * $quantity;
                $refund = $escrowedCash - $actualCost;

                if ($refund > 0) {
                    $user->setCashBalance((string)((float)$user->getCashBalance() + $refund));
                }
                
                $this->addAssetToUser($user, $stock, $etf, $userAsset, $quantity);

            } else { // SELL
                // Shares were already escrowed. Just give them the cash from the sale.
                $saleValue = $executionPrice * $quantity;
                $user->setCashBalance((string)((float)$user->getCashBalance() + $saleValue));
            }

            $order->setFilledQuantity($quantity);
            $order->setExecutionPrice((string)$executionPrice);
            $order->setStatus('FILLED');
            $order->setFilledAt(new \DateTime());

            $this->em->persist($order);
            $this->em->persist($user);
            $this->em->flush();
            $this->portfolio->recordUserSnapshot($user);
            $this->em->flush();
            $this->em->getConnection()->commit();

            $this->updateRedisBounds($ticker);

        } catch (\Exception $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            // Log error
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

    private function addAssetToUser(User $user, ?Stock $stock, ?Etf $etf, $userAsset, int $quantity)
    {
        if (!$userAsset) {
            if ($stock) {
                $userAsset = new UserStock();
                $userAsset->setUser($user);
                $userAsset->setStock($stock);
            } else {
                $userAsset = new UserEtf();
                $userAsset->setUser($user);
                $userAsset->setEtf($etf);
            }
            $userAsset->setQuantity(0);
            $this->em->persist($userAsset);
        }
        $userAsset->setQuantity($userAsset->getQuantity() + $quantity);
        return $userAsset;
    }

    private function removeAssetFromUser($userAsset, int $quantity)
    {
        $newQuantity = $userAsset->getQuantity() - $quantity;
        $userAsset->setQuantity($newQuantity);
        if ($newQuantity === 0) {
            $this->em->remove($userAsset);
        }
    }
}

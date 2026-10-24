<?php

declare(strict_types=1);

namespace App\Service\Market\Trading;

use App\DTO\ExecutionQuoteDTO;
use App\DTO\PlacedOrderDTO;
use App\DTO\ResolvedAssetDTO;
use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Service\Market\Bond\BondPricingEngine;
use App\Service\Market\Bond\BondTracker;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Market\Option\OptionTradeService;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Math\Decimal;
use App\Service\Math\FinancialConstants;
use App\Service\Notification\PlayerNotifier;
use App\Service\User\Portfolio;
use Doctrine\ORM\EntityManagerInterface;

class TradeExecutionService
{
    // --- Order Validation ---
    /** Lowest limit price an order may rest at, matching the $0.01 floor the price process itself clamps to. */
    public const MIN_LIMIT_PRICE = '0.01';

    /**
     * Ceiling on a limit price. Escrow is stored at DECIMAL(18,4), so the price times the 1e9 quantity cap has to stay
     * inside it.
     */
    public const MAX_LIMIT_PRICE = '100000000.0000';

    /**
     * The sides an order may be placed on.
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
        private \App\Service\User\CashLedger $cashLedger,
        private ?\App\Service\Macro\MacroStateProvider $macroStates = null,
        private ?PlayerNotifier $notifier = null,
        private ?BondPricingEngine $bondPricing = null,
    ) {}

    /**
     * The price one unit deals at: a bond off the live curve (BondPricingEngine::dealingPrice()), anything else at the
     * row the ticker writes on every frame. Without a live economy to price against, the row stands in.
     */
    private function dealingPrice(ResolvedAssetDTO $asset): float
    {
        if ($asset->entity instanceof Bond && $this->bondPricing !== null && $this->macroStates !== null) {
            return $this->bondPricing->dealingPrice($asset->entity, $this->macroStates->liveState());
        }

        return (float) $asset->price();
    }

    /** The shares already queued in a name for the tick an order fills in, which its fill meets in the book. */
    private function pendingFlow(?Stock $stock): float
    {
        return $stock !== null ? $this->orderFlow->pending($stock->getTicker()) : 0.0;
    }

    /**
     * The book's quote for an order of this size about a mid: what a placement, a resting fill and the cost preview all
     * deal at. A fund is quoted off its own arbitrage band rather than a market-wide constant.
     */
    private function quote(ResolvedAssetDTO $asset, string $action, int $quantity, float $mid): ExecutionQuoteDTO
    {
        $stock = $asset->stock();

        return $this->liquidityEngine->quoteAsset(
            $asset->entity instanceof Etf ? $asset->entity : $stock,
            $asset->type,
            $action,
            $quantity,
            $mid,
            $asset->entity instanceof Bond && !$asset->entity->isSovereign(),
            $this->pendingFlow($stock),
        );
    }

    /**
     * Re-marks a bond the desk has just dealt at the valuation it dealt off. The ladder is marked once a trading day
     * and net worth reads the row, so a holding bought off the live curve would otherwise be valued at the morning's
     * price until the next mark, a gain or loss nobody made.
     */
    private function stampBondMark(Bond $bond): void
    {
        if ($this->bondPricing === null || $this->macroStates === null) {
            return;
        }

        ['valuation' => $valuation, 'spread' => $spread] = $this->bondPricing->dealingValuation($bond, $this->macroStates->liveState());
        BondTracker::stampMark($this->em->getConnection(), $bond, $valuation, $spread, new \DateTimeImmutable());
    }

    /**
     * The stamp duty the Diet has in force on each side of a share trade, the founding rate before the ticker has
     * published an economy. The liquidity engine reads the same law for the depth an order meets.
     */
    private function liveStampDutyRate(): float
    {
        return $this->macroStates?->liveState()->stampDutyRate ?? FinancialConstants::STAMP_DUTY_RATE;
    }

    /**
     * What an order of this size would pay to cross the book, quoted exactly as execution quotes it, as
     * fractions of its consideration at mid so the ticket can reprice them off the live tape. Null for a
     * symbol the desk does not quote this way (an option) or does not know.
     *
     * @return array{spread: float, impact: float, participation: float, maximum: ?float}|null
     */
    public function previewCosts(string $ticker, string $action, int $quantity): ?array
    {
        $asset = $this->assetResolver->resolve($ticker);
        if ($asset === null || $asset->entity instanceof OptionContract || $quantity <= 0) {
            return null;
        }

        $mid = $this->dealingPrice($asset);
        if ($mid <= 0.0) {
            return null;
        }

        $stock = $asset->stock();
        $quote = $this->quote($asset, $action, $quantity, $mid);
        $consideration = $mid * $quantity;

        return [
            'spread' => $quote->spreadCost / $consideration,
            'impact' => $quote->impactCost / $consideration,
            'participation' => $quote->participationRate,
            'maximum' => $stock !== null ? floor($this->liquidityEngine->maximumOrderSize($stock)) : null,
        ];
    }

    /** Whether an action buys stock (and pays cash) or sells it (and receives cash). */
    public static function isBuySide(string $action): bool
    {
        return in_array($action, self::BUY_SIDE_ACTIONS, true);
    }

    /**
     * Whether a fill at this price respects the order's limit.
     *
     * A buy never pays more than its limit and a sell never accepts less.
     */
    private static function limitAllowsFill(string $action, float $limitPrice, float $executionPrice): bool
    {
        return self::isBuySide($action)
            ? $limitPrice >= $executionPrice
            : $limitPrice <= $executionPrice;
    }

    /**
     * Whether the market has gone through this stop's trigger.
     *
     * A stop is armed on the far side of the market from a limit: a sell stop sits BELOW the price and
     * fires when it falls to it, which is the opposite of a sell limit. Getting this backwards turns every
     * stop-loss into a take-profit.
     */
    private static function stopIsTriggered(string $action, float $stopPrice, float $currentPrice): bool
    {
        return self::isBuySide($action)
            ? $currentPrice >= $stopPrice
            : $currentPrice <= $stopPrice;
    }

    /**
     * Whether an order fills at this quote, on entry and off the book alike: a stop once the mid has gone through its
     * trigger, and anything with a limit only if the price it would actually fill at respects it.
     *
     * A stop already on the wrong side of the market at entry is one the market has already moved through, so it fires
     * there rather than resting behind a trigger that can never fire again. A limit is tested against the fill price,
     * not the mid: crossing on mid would break the promise the moment the spread or the order's own impact pushed the
     * fill through it.
     */
    private static function fillsAt(TradeOrder $order, ExecutionQuoteDTO $quote): bool
    {
        $action = $order->getAction();
        if ($order->isStop() && !self::stopIsTriggered($action, (float) $order->getStopPrice(), $quote->midPrice)) {
            return false;
        }

        return !$order->hasLimitCap() || self::limitAllowsFill($action, (float) $order->getLimitPrice(), $quote->executionPrice);
    }

    /**
     * Runs one change to an account in a transaction of its own, the account row locked and read fresh first, so a
     * placement, a cancel and a resting fill on the same account queue behind each other rather than write over each
     * other. Anything thrown rolls the whole change back and is thrown on.
     *
     * @template T
     * @param callable(): T $change
     * @return T
     */
    private function inLockedAccount(User $user, callable $change): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $this->em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);
            $result = $change();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Places one order and reports how the desk took it: its asset class, whose units differ (an option order is
     * counted in contracts of a hundred shares, and a bond symbol such as G02-001 defeats inferring the class from
     * the ticker), and whether it filled or rests on the book.
     *
     * @throws TradeRejectedException The desk refused the order; the message is written for the player.
     */
    public function executeOrder(User $user, string $ticker, string $action, string $orderType, int $quantity, ?string $limitPrice = null, ?string $stopPrice = null, ?string $origin = null): PlacedOrderDTO
    {
        [$limitPrice, $stopPrice] = $this->validateTicket($action, $orderType, $quantity, $limitPrice, $stopPrice);

        $placed = $this->inLockedAccount($user, function () use ($user, $ticker, $action, $orderType, $quantity, $limitPrice, $stopPrice, $origin): PlacedOrderDTO {
            $asset = $this->resolveTradableAsset($ticker);

            // An option settles in contracts, is paid for in full, and is collateralized against its
            // underlying rather than against itself, so it is filled by its own desk. Routing here rather
            // than at the controller keeps one front door: a player types a symbol, and what happens next is
            // a property of the symbol.
            if ($asset->entity instanceof OptionContract) {
                return $this->executeOptionOrder($user, $asset, $asset->entity, $action, $orderType, $quantity);
            }

            $this->assertDeskTakes($asset, $action, $quantity);

            $order = (new TradeOrder())
                ->setUser($user)
                ->setTicker($ticker)
                ->setAssetType($asset->type)
                ->setAction($action)
                ->setOrderType($orderType)
                ->setQuantity($quantity)
                ->setLimitPrice($limitPrice)
                ->setStopPrice($stopPrice)
                ->setOrigin($origin);

            $this->fillOrRest($user, $asset, $order);
            $this->recordPlacement($user, $asset, $order);

            return PlacedOrderDTO::fromOrder($order);
        });

        if ($placed->resting) {
            $this->refreshBoundsAfterCommit($ticker);
        }

        return $placed;
    }

    /**
     * Checks a ticket before the account is locked: a positive quantity, an action one of the desks knows, a listed
     * order type, and the limit and stop prices that type needs, normalized for the escrow arithmetic. A price the type
     * does not use is dropped.
     *
     * @return array{0: numeric-string|null, 1: numeric-string|null} The limit and stop prices the order carries.
     */
    private function validateTicket(string $action, string $orderType, int $quantity, ?string $limitPrice, ?string $stopPrice): array
    {
        if ($quantity <= 0) {
            throw new TradeRejectedException('Invalid quantity.');
        }

        // Loose here, strict once the instrument is known. The two desks share BUY, SELL and COVER but
        // differ on the fourth — an equity is SHORTed and a contract is WRITTEN — and which one is legal is
        // a property of what is being traded, so it cannot be decided before the ticker has been resolved.
        if (!in_array($action, self::VALID_ACTIONS, true) && !in_array($action, OptionTradeService::VALID_ACTIONS, true)) {
            throw new TradeRejectedException('Invalid order action.');
        }

        if (!in_array($orderType, TradeOrder::VALID_TYPES, true)) {
            throw new TradeRejectedException('Invalid order type.');
        }

        // A stop needs the price that wakes it; a stop-limit needs that AND the worst price it will accept
        // once awake. They are different numbers and an order carrying only one of them is not placeable.
        if ($orderType === TradeOrder::TYPE_LIMIT || $orderType === TradeOrder::TYPE_STOP_LIMIT) {
            $limitPrice = $this->validateLimitPrice($limitPrice);
        } else {
            $limitPrice = null;
        }

        if ($orderType === TradeOrder::TYPE_STOP || $orderType === TradeOrder::TYPE_STOP_LIMIT) {
            $stopPrice = $this->validateLimitPrice($stopPrice);
        } else {
            $stopPrice = null;
        }

        return [$limitPrice, $stopPrice];
    }

    /** The asset a ticker names, refused when the desk does not know the symbol or its trading is halted. */
    private function resolveTradableAsset(string $ticker): ResolvedAssetDTO
    {
        $asset = $this->assetResolver->resolve($ticker);

        if ($asset === null) {
            throw new TradeRejectedException('Asset not found.');
        }

        $haltReason = $asset->haltReason();
        if ($haltReason !== null) {
            throw new TradeRejectedException($haltReason);
        }

        return $asset;
    }

    /** Fills an option order on the option desk, which deals at market only, and records the account's snapshot. */
    private function executeOptionOrder(User $user, ResolvedAssetDTO $asset, OptionContract $contract, string $action, string $orderType, int $quantity): PlacedOrderDTO
    {
        if ($orderType !== TradeOrder::TYPE_MARKET) {
            throw new TradeRejectedException('Listed options trade at market. A resting option order is not supported yet.');
        }

        $this->optionTradeService->execute($user, $contract, $action, $quantity);

        $this->em->flush();
        $this->portfolio->recordUserSnapshot($user);
        $this->em->flush();

        return new PlacedOrderDTO('OPTION', $action, $quantity, $asset->ticker(), TradeOrder::TYPE_MARKET, null, null, false);
    }

    /**
     * Refuses what this desk cannot take: an action that belongs to the option desk, or a size past the book's depth.
     */
    private function assertDeskTakes(ResolvedAssetDTO $asset, string $action, int $quantity): void
    {
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new TradeRejectedException("{$action} is not an action on {$asset->ticker()}.");
        }

        // Size has to be checked against depth before anything else: past a couple of days' volume the
        // square-root law is extrapolation, and quoting an extrapolated price would be a made-up number
        // presented as a fill.
        $stock = $asset->stock();
        if ($stock !== null) {
            $maximum = $this->liquidityEngine->maximumOrderSize($stock);
            if ($quantity > $maximum) {
                throw new TradeRejectedException(sprintf(
                    'Order exceeds available liquidity in %s. The desk will take up to %s shares at once; work larger positions in pieces.',
                    $asset->ticker(),
                    number_format(floor($maximum)),
                ));
            }
        }
    }

    /**
     * Quotes a new order off the book and settles it there when it fills at that quote; otherwise rests it, taking its
     * escrow. A refused fill is refused to the player.
     */
    private function fillOrRest(User $user, ResolvedAssetDTO $asset, TradeOrder $order): void
    {
        // A marketable order fills at the live price, not at its limit, which is the better execution.
        $quote = $this->quote($asset, $order->getAction(), $order->getQuantity(), $this->dealingPrice($asset));
        if (self::fillsAt($order, $quote)) {
            $refusal = $this->settle($user, $asset, $order, $quote, false);
            if ($refusal !== null) {
                throw new TradeRejectedException($refusal);
            }
        } else {
            $this->placeResting($user, $asset, $order);
        }
    }

    /** Writes a placed order and its account, re-marks a bond the desk has just dealt, and records the snapshot. */
    private function recordPlacement(User $user, ResolvedAssetDTO $asset, TradeOrder $order): void
    {
        $this->em->persist($order);
        $this->em->persist($user);
        $this->em->flush();
        if ($asset->entity instanceof Bond && $order->getStatus() === TradeOrder::STATUS_FILLED) {
            $this->stampBondMark($asset->entity);
        }
        $this->portfolio->recordUserSnapshot($user);
        $this->em->flush();
    }

    /**
     * Rests an order on the book, taking into escrow what its fill will draw on: a limit BUY's cash at its limit and a
     * SELL's units (TradeOrder::escrowedCash(), escrowsUnits()). Whatever escrows nothing is checked again when it
     * fills, since the account can change while it rests.
     */
    private function placeResting(User $user, ResolvedAssetDTO $asset, TradeOrder $order): void
    {
        $action = $order->getAction();
        $quantity = $order->getQuantity();
        $escrow = $order->escrowedCash();

        if ($escrow !== null) {
            $refusal = $this->fundingRefusal($user, (float) $escrow, $action);
            if ($refusal !== null) {
                throw new TradeRejectedException($refusal);
            }
            $this->cashLedger->debit($user, $escrow);
        } elseif ($action === 'BUY') {
            // A buy stop escrows nothing, for the same reason a resting short does not: it may never trigger, and a
            // stop has no fill price to reserve against anyway; it takes what the book gives it. Buying power is
            // checked when it actually fires.
            if (!$asset->entity instanceof Stock && !$asset->entity instanceof Etf) {
                throw new TradeRejectedException('Stop orders are for equities and funds.');
            }
        } elseif ($action === 'COVER') {
            // Resting COVER escrows nothing: closing leg of an already-collateralized position.
            $holding = $this->assetResolver->findHolding($user, $asset);
            if (($holding !== null ? (int) $holding->getQuantity() : 0) > -$quantity) {
                throw new TradeRejectedException('No short position of that size to cover.');
            }
        } elseif ($action === 'SHORT') {
            // A resting short escrows nothing. Borrow is located and margin is checked when it fills, not when it is
            // placed: reserving stock against an order that may never cross would let a trader take a name
            // hard-to-borrow for everyone else with an offer nobody can hit.
            if ($asset->stock() === null) {
                throw new TradeRejectedException('Only equities can be sold short.');
            }
            if (!$user->isMarginEnabled()) {
                throw new TradeRejectedException('Short selling requires a margin account.');
            }
        } else { // SELL
            $holding = $this->assetResolver->findHolding($user, $asset);
            if ($holding === null || (int) $holding->getQuantity() < $quantity) {
                throw new TradeRejectedException('Insufficient shares for a resting sell order.');
            }
            $this->assetResolver->removeFromHolding($holding, $quantity);
        }

        $order->setStatus(TradeOrder::STATUS_OPEN);
    }

    /**
     * Fills an order at a quote: moves the stock and the cash in whichever of the four directions the action names
     * and stamps the fill on the order, or says why the account cannot take it and moves nothing.
     *
     * One place for a fill at placement and a fill off the book, because the four actions differ in exactly two ways
     * (which side of the position they move, and what has to be true beforehand) and two copies of that rule drift
     * apart. The two fills differ only in what placement already took: a resting limit BUY pays from the cash it
     * escrowed at its limit and gets the difference back, and a resting SELL delivers the units it escrowed. A resting
     * order is re-checked in full, since the account, the borrow and the margin can all have moved while it rested.
     *
     * Stamp duty is paid on top whichever way the stock moves: a buyer needs the consideration and the duty, a seller
     * receives the consideration and pays the duty out of it.
     *
     * @param bool $fromBook Whether the order rested before it filled, so its escrow pays part of the fill.
     * @return string|null Why the fill is refused, written for the player; null once it has settled.
     */
    private function settle(User $user, ResolvedAssetDTO $asset, TradeOrder $order, ExecutionQuoteDTO $quote, bool $fromBook): ?string
    {
        $action = $order->getAction();
        $quantity = $order->getQuantity();
        $stock = $asset->stock();
        $consideration = \bcmul(Decimal::format($quote->executionPrice, 4), (string) $quantity, 4);
        $duty = $this->stampDuty($stock, $consideration);
        $escrowedCash = $fromBook ? $order->escrowedCash() : null;

        $holding = $this->assetResolver->findHolding($user, $asset);
        $held = $holding !== null ? (int) $holding->getQuantity() : 0;

        if (self::isBuySide($action)) {
            if ($action === 'COVER' && $held > -$quantity) {
                return 'No short position of that size to cover.';
            }

            // What the account still has to find: the price and the duty, less any escrow already set against them.
            $owed = \bcadd($consideration, $duty, 4);
            if ($escrowedCash !== null) {
                $owed = \bcsub($owed, $escrowedCash, 4);
            }
            if (\bccomp($owed, '0.0000', 4) > 0) {
                $refusal = $this->fundingRefusal($user, (float) $owed, $action);
                if ($refusal !== null) {
                    return $refusal;
                }
            }

            if ($escrowedCash === null) {
                $this->cashLedger->debit($user, $consideration);
            } elseif (\bccomp($refund = \bcsub($escrowedCash, $consideration, 4), '0.0000', 4) > 0) {
                // A fill better than the limit refunds the difference, paying down any borrowing the escrow was drawn
                // from before it reaches the settled balance.
                $this->cashLedger->credit($user, $refund);
            }
            $this->payStampDuty($user, $duty);
            $this->assetResolver->addToHolding($user, $asset, $holding, $quantity);
            if ($stock !== null) {
                $this->adjustShortInterest($stock, $held, $held + $quantity);
            }
        } elseif ($action === 'SHORT') {
            if ($stock === null) {
                return 'Only equities can be sold short.';
            }
            $refusal = $this->shortSaleRefusal($user, $stock, $held, $quantity, $consideration);
            if ($refusal !== null) {
                return $refusal;
            }

            // Proceeds are credited, then immediately collateralize the borrowed stock. They are not free
            // cash: MarginEngine subtracts the position's market value straight back out of equity.
            $this->cashLedger->credit($user, $consideration);
            $this->payStampDuty($user, $duty);
            $this->assetResolver->addToHolding($user, $asset, $holding, -$quantity);
            $this->adjustShortInterest($stock, $held, $held - $quantity);
        } else { // SELL
            if (!($fromBook && $order->escrowsUnits())) {
                if ($holding === null || $held < $quantity) {
                    return 'Insufficient shares.';
                }
                $this->assetResolver->removeFromHolding($holding, $quantity);
            }

            $this->cashLedger->credit($user, $consideration);
            $this->payStampDuty($user, $duty);
        }

        $this->recordFill($order, $asset, $quote, $duty);

        return null;
    }

    /**
     * Why an account cannot sell this stock short, or null when it can: the margin account a short needs, the borrow
     * the lending desk can locate for what the sale takes past the shares held, and the collateral for the proceeds.
     *
     * @param numeric-string $consideration
     */
    private function shortSaleRefusal(User $user, Stock $stock, int $held, int $quantity, string $consideration): ?string
    {
        if (!$user->isMarginEnabled()) {
            return 'Short selling requires a margin account.';
        }

        $available = $this->lendingDesk->availableToBorrow($stock);
        if (self::sharesBorrowed($held, $held - $quantity) > $available) {
            return sprintf('Only %s shares of %s are available to borrow.', number_format(floor($available)), $stock->getTicker());
        }

        return $this->fundingRefusal($user, (float) $consideration, 'SHORT');
    }

    /**
     * Stamp duty on a fill: listed shares only, at the District rate on the consideration.
     *
     * Funds are exempt, as Hong Kong exempts ETF transfers, and bonds and options carry none, so only an equity
     * fill is charged. The tax is levied on each side of the trade, so buyer and seller each pay it.
     *
     * @param numeric-string $consideration
     * @return numeric-string The duty, as a bcmath string.
     */
    private function stampDuty(?Stock $stock, string $consideration): string
    {
        if ($stock === null) {
            return '0.0000';
        }

        return \bcmul($consideration, Decimal::format($this->liveStampDutyRate(), 6), 4);
    }

    /**
     * Pays the duty out of the account, borrowing any part the settled balance does not cover like any payment.
     *
     * @param numeric-string $stampDuty
     */
    private function payStampDuty(User $user, string $stampDuty): void
    {
        if (\bccomp($stampDuty, '0.0000', 4) > 0) {
            $this->cashLedger->debit($user, $stampDuty);
        }
    }

    /**
     * Why the account cannot collateralize a trade of this size, or null when it can.
     *
     * A cash account is measured against settled cash; a margin account against buying power, which is what
     * is left once the existing book is collateralized, geared by the initial requirement.
     */
    private function fundingRefusal(User $user, float $notional, string $action): ?string
    {
        if (!$user->isMarginEnabled()) {
            return $notional > (float) $user->getCashBalance() + 1e-6 ? 'Insufficient funds.' : null;
        }

        // Buying power gates new exposure only; COVER closes existing short exposure.
        if (!in_array($action, self::EXPOSURE_INCREASING_ACTIONS, true)) {
            return null;
        }

        return $this->marginEngine->canOpen($user, $notional) ? null : 'Insufficient buying power for this order.';
    }

    /**
     * Moves a name's total short interest, which is what prices its borrow for everyone, by the change in one account's
     * short position, whatever action moved it: a buy against a short returns the stock as a cover does, and a short
     * sale against shares held borrows only what it sells beyond them.
     */
    private function adjustShortInterest(Stock $stock, int $heldBefore, int $heldAfter): void
    {
        $delta = self::sharesBorrowed($heldBefore, $heldAfter);
        if ($delta === 0) {
            return;
        }

        $updated = max(0.0, (float) $stock->getShortInterestShares() + $delta);
        $stock->setShortInterestShares(Decimal::format($updated, 2));
    }

    /** Shares newly borrowed (positive) or returned (negative) when a position moves from one size to another. */
    public static function sharesBorrowed(int $heldBefore, int $heldAfter): int
    {
        return max(0, -$heldAfter) - max(0, -$heldBefore);
    }

    /**
     * Stamps a fill onto its order and reports the flow it generated.
     *
     * The signed quantity goes to the order flow store rather than moving the price here: impact is applied
     * once per tick, on the ticker, against the net of everything that traded in that window. Applying it
     * per order would let a trader split a position into a hundred pieces and pay a hundred separate
     * square roots, which is cheaper than the one they should have paid.
     *
     * @param numeric-string $stampDuty
     */
    private function recordFill(TradeOrder $order, ResolvedAssetDTO $asset, ExecutionQuoteDTO $quote, string $stampDuty): void
    {
        $quantity = $order->getQuantity();
        $order->setFilledQuantity($quantity);
        $order->setExecutionPrice(Decimal::format($quote->executionPrice, 4));
        $order->setSpreadCost(Decimal::format($quote->spreadCost, 4));
        $order->setImpactCost(Decimal::format($quote->impactCost, 4));
        $order->setStampDuty($stampDuty);
        $order->setStatus(TradeOrder::STATUS_FILLED);
        $order->setFilledAt(new \DateTime());

        if ($asset->type === 'STOCK' || $asset->type === 'ETF') {
            // Record order flow by execution direction (buy/cover vs sell/short) for stocks and ETFs.
            $this->orderFlow->record($asset->ticker(), self::isBuySide($order->getAction()) ? (float) $quantity : -(float) $quantity);
        }
    }

    /**
     * Normalizes a limit price, rejecting anything the escrow arithmetic cannot safely hold.
     *
     * The price arrives straight off the request. A negative limit would invert the escrow, and a non-numeric one
     * reaches bcmath, which throws a ValueError (an Error, not an Exception).
     *
     * @return numeric-string
     */
    private function validateLimitPrice(?string $limitPrice): string
    {
        if ($limitPrice === null || !is_numeric(trim($limitPrice))) {
            throw new TradeRejectedException('A limit order requires a numeric limit price.');
        }

        // Rendered rather than passed through: is_numeric accepts "1e5", and bcmath rejects exponent
        // notation outright. %F never emits one, so this is the form the escrow arithmetic can consume.
        $normalized = Decimal::format((float) trim($limitPrice), 4);

        if (\bccomp($normalized, self::MIN_LIMIT_PRICE, 4) < 0) {
            throw new TradeRejectedException('Limit price must be at least $' . self::MIN_LIMIT_PRICE . '.');
        }

        if (\bccomp($normalized, self::MAX_LIMIT_PRICE, 4) > 0) {
            throw new TradeRejectedException('Limit price is above the maximum accepted value.');
        }

        return $normalized;
    }

    /** Withdraws a resting order and hands back what it escrowed: a limit BUY's cash, a SELL's units. */
    public function cancelOrder(User $user, int $orderId): void
    {
        $ticker = $this->inLockedAccount($user, function () use ($user, $orderId): string {
            $order = $this->em->getRepository(TradeOrder::class)->findOpenForUserById($user, $orderId);
            if (!$order) {
                throw new TradeRejectedException('Order not found or already processed.');
            }

            $escrow = $order->escrowedCash();
            if ($escrow !== null) {
                // Refund cash, paying down the borrowing it was drawn from first.
                $this->cashLedger->credit($user, $escrow);
            } elseif ($order->escrowsUnits()) {
                $asset = $this->assetResolver->resolve($order->getTicker());
                if ($asset === null) {
                    throw new TradeRejectedException('Asset not found.');
                }

                $this->assetResolver->addToHolding($user, $asset, $this->assetResolver->findHolding($user, $asset), $order->getQuantity());
            }

            $order->setStatus(TradeOrder::STATUS_CANCELLED);
            $this->em->persist($order);
            $this->em->persist($user);
            $this->em->flush();

            return $order->getTicker();
        });

        $this->refreshBoundsAfterCommit($ticker);
    }

    public function processLimitOrders(string $ticker, float $currentPrice): void
    {
        // Runs on the messenger worker: ProcessLimitOrdersMessage is routed to the async transport, so the
        // ticker only enqueues and never carries these queries or row locks inside its tick transaction.
        $asset = null;

        foreach ($this->em->getRepository(TradeOrder::class)->findOpenByTicker($ticker) as $order) {
            $action = $order->getAction();

            // Tested on the tape first, so an order nowhere near its price takes no lock.
            $shouldFill = $order->isStop()
                ? self::stopIsTriggered($action, (float) $order->getStopPrice(), $currentPrice)
                : self::limitAllowsFill($action, (float) $order->getLimitPrice(), $currentPrice);
            if (!$shouldFill) {
                continue;
            }

            $asset ??= $this->assetResolver->resolve($ticker);
            if ($asset === null) {
                $this->logger->error('Resting orders on a ticker the desk cannot resolve; left open with escrow held.', ['ticker' => $ticker]);
                break;
            }

            $this->fillOpenOrder($order, $asset, $currentPrice);
        }

        $this->notifier?->publish();

        // Always leave the book's bounds in Redis, even when nothing filled and even when there is nothing
        // open. The ticker only checks a ticker whose bounds key exists, so a flushed or evicted Redis
        // otherwise left every resting order un-checked forever — this call is what heals that, and writing
        // the empty case too stops a ticker with no orders from being re-checked on every tick.
        $this->updateRedisBounds($ticker);
    }

    /**
     * Fills one resting order the tape has reached. An order the account can no longer take (the cash spent, the
     * borrow gone, the margin used) is not an error to report to anybody: the trader placed it correctly and the world
     * moved, so it stays open and tries again.
     */
    private function fillOpenOrder(TradeOrder $order, ResolvedAssetDTO $asset, float $currentPrice): void
    {
        $user = $order->getUser();

        try {
            $fill = $this->inLockedAccount($user, function () use ($order, $asset, $user, $currentPrice): ?ExecutionQuoteDTO {
                // Re-check status in case it was cancelled while in queue. The entity has to be refreshed to
                // read it: $order came out of the identity map before the lock was taken, so a cancel committed
                // by the web process in the meantime is invisible here and the order would fill a second time
                // against escrow that has already been refunded.
                $this->em->refresh($order);
                if ($order->getStatus() !== TradeOrder::STATUS_OPEN) {
                    return null;
                }

                $quote = $this->quote($asset, $order->getAction(), $order->getQuantity(), $currentPrice);
                if (!self::fillsAt($order, $quote) || $this->settle($user, $asset, $order, $quote, true) !== null) {
                    return null;
                }

                $this->em->persist($order);
                $this->em->persist($user);
                $this->em->flush();
                $this->portfolio->recordUserSnapshot($user);
                $this->em->flush();

                return $quote;
            });
        } catch (\Throwable $e) {
            // The order stays OPEN with its escrow still held, so the failure has to be visible.
            $this->logger->error('Limit order fill failed; order left open with escrow held.', [
                'order_id' => $order->getId(),
                'ticker' => $order->getTicker(),
                'execution_price' => $currentPrice,
                'exception' => $e,
            ]);

            return;
        }

        if ($fill !== null && $this->notifier !== null && $user->getId() !== null) {
            $this->notifier->queue(
                $user->getId(),
                \App\Entity\Notification::KIND_FILL,
                PlayerNotifier::fillLine($order->getAction(), $order->getQuantity(), $asset->ticker(), $fill->executionPrice),
                sprintf('Your %s order filled.', strtolower(str_replace('_', '-', $order->getOrderType()))),
                $asset->ticker(),
                PlayerNotifier::assetLink($asset->type, $asset->ticker()),
            );
        }
    }

    /**
     * Rewrites the book's bounds once an order change is committed. A failure is logged, not thrown: the change
     * stands, and an error would tell the player it failed and invite a duplicate. The stale key is dropped if
     * Redis still answers, since the ticker checks a ticker with no key once and the check rewrites it.
     */
    private function refreshBoundsAfterCommit(string $ticker): void
    {
        try {
            $this->updateRedisBounds($ticker);
        } catch (\Throwable $e) {
            $this->logger->error('Order change committed, but the resting-order bounds were not refreshed.', [
                'ticker' => $ticker,
                'exception' => $e,
            ]);
            try {
                $this->redis->del("limit_bounds:{$ticker}");
            } catch (\RedisException) {
                // Redis is unreachable; the error logged above is the record.
            }
        }
    }

    private function updateRedisBounds(string $ticker): void
    {
        $conn = $this->em->getConnection();

        // Price touch trigger bounds: compute upper and lower activation triggers for all resting order types.
        $lowerSql = "SELECT MAX(CASE
                WHEN order_type IN ('LIMIT', 'STOP_LIMIT') AND action IN ('BUY', 'COVER') THEN limit_price
                WHEN order_type IN ('STOP', 'STOP_LIMIT') AND action IN ('SELL', 'SHORT') THEN stop_price
            END) FROM trade_orders WHERE ticker = :ticker AND status = 'OPEN'";
        $lowerResult = $conn->executeQuery($lowerSql, ['ticker' => $ticker])->fetchOne();
        $touchFromAbove = $lowerResult !== null && $lowerResult !== false ? (float) $lowerResult : 0.0;

        $upperSql = "SELECT MIN(CASE
                WHEN order_type IN ('LIMIT', 'STOP_LIMIT') AND action IN ('SELL', 'SHORT') THEN limit_price
                WHEN order_type IN ('STOP', 'STOP_LIMIT') AND action IN ('BUY', 'COVER') THEN stop_price
            END) FROM trade_orders WHERE ticker = :ticker AND status = 'OPEN'";
        $upperResult = $conn->executeQuery($upperSql, ['ticker' => $ticker])->fetchOne();
        $touchFromBelow = $upperResult !== null && $upperResult !== false ? (float) $upperResult : 999999999.0;

        // The keys keep their names: the ticker reads 'buy' as "dispatch if the price fell to here" and
        // 'sell' as "dispatch if it rose to here", which is what both now mean for every order type.
        $this->redis->set("limit_bounds:$ticker", json_encode([
            'buy' => $touchFromAbove,
            'sell' => $touchFromBelow,
        ]));
    }

}

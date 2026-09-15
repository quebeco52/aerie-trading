<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Entity\UserOption;
use App\Entity\UserStock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Settles contracts at expiry: exercise, assignment, and delivery of the underlying.
 *
 * Exercise by exception, as a clearing house does it — anything in the money by a tick is exercised and the
 * writer on the other side is assigned, without either party being asked. A holder who would rather abandon
 * a contract sells it before expiry, which is what the last day of a chain is actually for.
 *
 * Delivery is PHYSICAL. Every one of the four cases is the same identity:
 *
 *     shares  += contracts * multiplier * (call ? +1 : -1)   [signed by the holder's side]
 *     cash    -= shares delta * strike
 *
 * A long call pays the strike and receives stock; a short call delivers stock and is paid; a long put
 * delivers stock and is paid; a short put pays and receives stock. Writing it as one signed transfer rather
 * than four branches is not brevity — it is the reason the two sides of a contract cannot disagree about
 * what changed hands.
 *
 * The account is not asked whether it can afford the result. A holder who exercises into stock they cannot
 * fund ends the day with a margin debit, and one assigned on stock they do not own ends it short; both are
 * what really happens, and both are already handled — ForcedLiquidationService sweeps the account on the
 * next tick and sells whatever it has to. Blocking delivery instead would make an option a contract that
 * only settles when convenient, which is the one thing an option is not.
 */
final class OptionSettlementEngine
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\User\CashLedger $cashLedger,
    ) {}

    /**
     * Settles every contract whose expiry has passed.
     *
     * @return array<int, array{ticker: string, underlying: string, settlement: float, exercised: bool, positions: int}>
     *         One record per contract settled, for the tick's event feed.
     */
    public function settle(float $currentTime): array
    {
        $repository = $this->em->getRepository(OptionContract::class);
        $settled = [];

        foreach ($repository->findExpiring($currentTime) as $contract) {
            $settled[] = $this->settleContract($contract);
        }

        return $settled;
    }

    /**
     * @return array{ticker: string, underlying: string, settlement: float, exercised: bool, positions: int}
     */
    private function settleContract(OptionContract $contract): array
    {
        $stock = $contract->getStock();

        // The settlement price is the underlying's last print. A bankrupt shell settles every call at zero
        // and every put at the full strike, which is the correct answer rather than a special case.
        $settlementPrice = (float) $stock->getPrice();
        $intrinsic = $contract->intrinsicValue($settlementPrice);
        $exercised = $intrinsic >= FinancialConstants::OPTION_EXERCISE_THRESHOLD;

        $positions = $this->em->getRepository(UserOption::class)->findBy(['contract' => $contract]);

        foreach ($positions as $position) {
            if ($exercised) {
                $this->deliver($position, $contract, $stock, $settlementPrice);
            }

            $this->em->remove($position);
        }

        $contract->setStatus(OptionContract::STATUS_EXPIRED)
            ->setOpenInterest(0)
            ->setPrice(MathUtility::formatDecimal($intrinsic, 8))
            ->setDelta('0.00000000')
            ->setGamma('0.000000000000')
            ->setVega('0.00000000')
            ->setTheta('0.00000000')
            ->setUpdatedAt(new \DateTime());

        return [
            'ticker' => $contract->getTicker(),
            'underlying' => $stock->getTicker(),
            'settlement' => $settlementPrice,
            'exercised' => $exercised,
            'positions' => count($positions),
        ];
    }

    /**
     * The shares one settled position delivers or receives.
     *
     * All four cases in one signed expression. The holder's side carries the sign of the position and the
     * contract's side carries the sign of the right: a call takes stock IN and a put sends it OUT, and
     * writing the contract flips both. The cash is then the mirror of it at the strike, which is why the two
     * legs of a settlement can never disagree about what happened.
     *
     *     long call  (+1, call) -> +100 shares, -100K cash
     *     short call (-1, call) -> -100 shares, +100K cash
     *     long put   (+1, put)  -> -100 shares, +100K cash
     *     short put  (-1, put)  -> +100 shares, -100K cash
     *
     * @param int  $contracts Signed position, positive long and negative written.
     * @param bool $isCall    Whether the contract is a call.
     * @return int Signed shares; positive is stock received and paid for.
     */
    public static function shareDelta(int $contracts, bool $isCall): int
    {
        return $contracts
            * FinancialConstants::OPTION_CONTRACT_MULTIPLIER
            * ($isCall ? 1 : -1);
    }

    /**
     * Moves the stock and the cash for one settled position.
     *
     * The share leg is recorded as a filled order at the STRIKE, not at the market. That is the price the
     * shares actually changed hands at, and it is what the cost-basis calculator has to see: booking an
     * exercise at the market price would show a profitable call as having been opened flat, and the gain
     * would vanish from the account's history even though the cash moved.
     */
    private function deliver(UserOption $position, OptionContract $contract, Stock $stock, float $settlementPrice): void
    {
        $contracts = (int) $position->getQuantity();

        if ($contracts === 0) {
            return;
        }

        $user = $position->getUser();
        $strike = (float) $contract->getStrike();

        $shareDelta = self::shareDelta($contracts, $contract->isCall());

        $consideration = MathUtility::formatDecimal(abs((float) $shareDelta) * $strike, 4);

        if ($shareDelta > 0) {
            $this->cashLedger->debit($user, $consideration);
        } else {
            $this->cashLedger->credit($user, $consideration);
        }

        $this->moveShares($user, $stock, $shareDelta);

        $order = (new TradeOrder())
            ->setUser($user)
            ->setTicker($stock->getTicker())
            ->setAssetType('STOCK')
            ->setAction($shareDelta > 0 ? 'BUY' : 'SELL')
            ->setOrderType('EXERCISE')
            ->setQuantity(abs($shareDelta))
            ->setFilledQuantity(abs($shareDelta))
            ->setExecutionPrice(MathUtility::formatDecimal($strike, 4))
            ->setSpreadCost('0.0000')
            ->setImpactCost('0.0000')
            ->setStatus(TradeOrder::STATUS_FILLED)
            ->setFilledAt(new \DateTime());

        $this->em->persist($order);
        $this->em->persist($user);
    }

    /**
     * Adds a signed share delta to a position, opening or closing the row as needed.
     *
     * Short interest moves with it. Assignment on a call the account does not own the stock for creates a
     * genuine short position, borrowed like any other, and leaving it out of the name's short interest would
     * hide it from the borrow fee and from the buy-in that prices it.
     */
    private function moveShares(User $user, Stock $stock, int $shareDelta): void
    {
        $holding = $this->em->getRepository(UserStock::class)->findOneBy(['user' => $user, 'stock' => $stock]);

        if ($holding === null) {
            $holding = (new UserStock())->setUser($user)->setStock($stock)->setQuantity(0);
            $this->em->persist($holding);
        }

        $before = (int) $holding->getQuantity();
        $after = $before + $shareDelta;

        $holding->setQuantity($after);

        if ($after === 0) {
            $this->em->remove($holding);
        }

        // Only the part of the move that is actually borrowed counts: going from -100 to +100 returns a
        // hundred borrowed shares and buys a hundred outright, and charging the whole swing would leave the
        // name's short interest permanently wrong.
        $shortBefore = max(0, -$before);
        $shortAfter = max(0, -$after);

        if ($shortBefore !== $shortAfter) {
            $updated = max(0.0, (float) $stock->getShortInterestShares() + ($shortAfter - $shortBefore));
            $stock->setShortInterestShares(MathUtility::formatDecimal($updated, 2));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Market\Bond;

use App\Entity\Bond;
use App\Entity\CouponPayment;
use App\Entity\TradeOrder;
use App\Service\Notification\PlayerNotifier;
use App\Service\User\Portfolio;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Raw SQL for the two cash events a bond produces: the coupon and the redemption of face at maturity.
 *
 * Follows CorporateLedgerService::processDividendPayment: the ledger rows are written first and the cash
 * credit is derived FROM those rows, so rounding happens once and the sum of coupon_payment.amount equals
 * the cash actually paid by construction rather than by two independently rounded subqueries agreeing.
 */
class BondLedgerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        /** Tells each holder the issue has closed and what it paid; null (unit tests) tells no one. */
        private ?PlayerNotifier $notifier = null,
    ) {}

    /**
     * Pays one coupon to every holder of an issue.
     *
     * Holdings are user_bonds plus the bonds escrowed behind an open SELL, mirroring the dividend rule and
     * for the same reason: the trade engine has already removed a resting seller's bonds from user_bonds,
     * so they have to be added back or the seller is underpaid for bonds they still own. An open BUY is
     * excluded — it escrows cash, not bonds, and paying on it would let anyone rest a limit buy far below
     * market and collect the coupon stream on bonds they never bought, escrow still refundable on cancel.
     *
     * @param Bond               $bond           The paying issue.
     * @param float              $couponPerBond  Cash per bond for this payment date.
     * @param float              $simulationTime Simulation time in years the payment fell due.
     * @param \DateTimeInterface $paidAt         Payment timestamp, the join key between both statements.
     */
    public function processCouponPayment(Bond $bond, float $couponPerBond, float $simulationTime, \DateTimeInterface $paidAt): void
    {
        if ($couponPerBond <= 0.0) {
            return;
        }

        $this->distribute($bond, $couponPerBond, CouponPayment::TYPE_COUPON, $simulationTime, $paidAt);
    }

    /**
     * Redeems an issue at face and closes out every holding.
     *
     * The holdings are deleted after the credit rather than left at par: a matured bond is not an asset
     * worth face, it is cash that has already been paid, and leaving the rows behind double-counts it in
     * every NAV snapshot from then on. Open orders on the issue are cancelled by the same reasoning — a
     * resting order against an instrument that no longer trades can never fill, and a resting BUY would
     * hold the user's cash hostage indefinitely.
     *
     * @param Bond               $bond           The maturing issue.
     * @param float              $simulationTime Simulation time in years of redemption.
     * @param \DateTimeInterface $paidAt         Redemption timestamp.
     */
    public function processRedemption(Bond $bond, float $simulationTime, \DateTimeInterface $paidAt): void
    {
        $this->closeOut($bond, (float) $bond->getFaceValue(), CouponPayment::TYPE_REDEMPTION, $simulationTime, $paidAt);
    }

    /**
     * Settles an issue whose borrower failed, at whatever the claim actually recovers.
     *
     * Mechanically the same event as a redemption — the holders are paid, the working orders are torn down
     * and the positions close — and deliberately routed through the same code, because the ways this can go
     * wrong are the ways redemption could: escrow destroyed with the order that held it, or a holding left
     * pointing at an instrument that no longer pays. What differs is only the amount, and that a default
     * pays it EARLY and short rather than on the day and in full.
     *
     * @param Bond               $bond             The failed issue.
     * @param float              $recoveryPerBond  Cash per bond, the recovery share of face.
     * @param float              $simulationTime   Simulation time in years of settlement.
     * @param \DateTimeInterface $paidAt           Settlement timestamp.
     */
    public function processDefaultSettlement(
        Bond $bond,
        float $recoveryPerBond,
        float $simulationTime,
        \DateTimeInterface $paidAt,
    ): void {
        $this->closeOut($bond, max(0.0, $recoveryPerBond), CouponPayment::TYPE_RECOVERY, $simulationTime, $paidAt);
    }

    /**
     * Pays out an issue its issuer buys back before maturity, at the market price, and closes every position and order
     * standing against it: the same close-out as a redemption, at what the bonds are worth rather than at face.
     */
    public function processCall(Bond $bond, float $pricePerBond, float $simulationTime, \DateTimeInterface $paidAt): void
    {
        $this->closeOut($bond, max(0.0, $pricePerBond), CouponPayment::TYPE_CALL, $simulationTime, $paidAt);
    }

    /**
     * Pays an issue out for the last time and closes every position and order standing against it.
     *
     * @param float  $amountPerBond Cash per bond.
     * @param string $paymentType   CouponPayment::TYPE_*.
     */
    private function closeOut(
        Bond $bond,
        float $amountPerBond,
        string $paymentType,
        float $simulationTime,
        \DateTimeInterface $paidAt,
    ): void {
        $conn = $this->entityManager->getConnection();

        $this->distribute($bond, $amountPerBond, $paymentType, $simulationTime, $paidAt);
        $this->notifyClosedHoldings($bond, $amountPerBond, $paymentType);

        // Each holding closes on a settlement sale at what the issue paid, so the gain or loss against its cost is
        // realised like any sale. Written before the resting sells below are cancelled, since their bonds count.
        $conn->executeStatement(
            "INSERT INTO trade_orders
                 (user_id, asset_type, ticker, action, order_type, quantity, filled_quantity, execution_price,
                  spread_cost, impact_cost, stamp_duty, status, created_at, filled_at, origin)
             SELECT held.user_id, 'BOND', :ticker, 'SELL', :order_type, held.units, held.units, :price,
                    0, 0, 0, 'FILLED', :paid_at, :paid_at, :origin
             FROM (" . Portfolio::unitsHeldSql('BOND') . ") held
             WHERE held.units > 0",
            [
                'ticker' => $bond->getTicker(),
                'bond_id' => $bond->getId(),
                'price' => number_format($amountPerBond, 4, '.', ''),
                'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                'order_type' => TradeOrder::TYPE_SETTLEMENT,
                'origin' => TradeOrder::ORIGIN_SETTLEMENT,
            ],
        );

        // Refund the cash escrowed behind open BUYs before the orders are dropped, otherwise the escrow is
        // simply destroyed along with the row. Summed per account first: a multi-table UPDATE changes each users
        // row once however many orders it joins, so an account with several bids would get one of them back.
        $conn->executeStatement(
            "UPDATE users u
             INNER JOIN (
                 SELECT o.user_id, SUM(o.limit_price * o.quantity) AS escrow
                 FROM trade_orders o
                 WHERE o.ticker = :ticker
                   AND o.asset_type = 'BOND'
                   AND o.status = 'OPEN'
                   AND " . TradeOrder::ESCROWS_CASH_SQL . "
                 GROUP BY o.user_id
             ) refunds ON refunds.user_id = u.id
             SET u.cash_balance = u.cash_balance + refunds.escrow",
            ['ticker' => $bond->getTicker()],
        );

        $conn->executeStatement(
            "UPDATE trade_orders SET status = 'CANCELLED'
             WHERE ticker = :ticker AND asset_type = 'BOND' AND status = 'OPEN'",
            ['ticker' => $bond->getTicker()],
        );

        $conn->executeStatement(
            'DELETE FROM user_bonds WHERE bond_id = :bond_id',
            ['bond_id' => $bond->getId()],
        );
    }

    /**
     * One message per holder of a closed issue, bonds escrowed behind an open sell included, as the payment counts
     * them.
     */
    private function notifyClosedHoldings(Bond $bond, float $amountPerBond, string $paymentType): void
    {
        if ($this->notifier === null) {
            return;
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT held.user_id, held.units AS quantity FROM (' . Portfolio::unitsHeldSql('BOND') . ') held',
            ['bond_id' => $bond->getId(), 'ticker' => $bond->getTicker()],
        );
        $ticker = $bond->getTicker();
        $face = max(0.01, (float) $bond->getFaceValue());
        $title = match ($paymentType) {
            CouponPayment::TYPE_RECOVERY => sprintf('%s defaulted and was settled', $ticker),
            CouponPayment::TYPE_CALL => sprintf('%s was bought back by its issuer', $ticker),
            default => sprintf('%s matured', $ticker),
        };
        $this->notifier->queueForHolders(
            $rows,
            $ticker,
            $title,
            static function (float $quantity) use ($paymentType, $amountPerBond, $face): string {
                return match ($paymentType) {
                    CouponPayment::TYPE_RECOVERY => sprintf('Your %s bonds were settled at %.1f%% of face, $%s in all.', number_format($quantity), $amountPerBond / $face * 100.0, number_format($quantity * $amountPerBond, 2)),
                    CouponPayment::TYPE_CALL => sprintf('Your %s bonds were bought back at %.1f%% of face, $%s in all.', number_format($quantity), $amountPerBond / $face * 100.0, number_format($quantity * $amountPerBond, 2)),
                    default => sprintf('Your %s bonds were repaid at face, $%s in all.', number_format($quantity), number_format($quantity * $amountPerBond, 2)),
                };
            },
            PlayerNotifier::assetLink('BOND', $ticker),
        );
    }

    /**
     * Writes the ledger rows for a cash event, then credits cash from exactly those rows.
     *
     * @param Bond               $bond           The paying issue.
     * @param float              $amountPerBond  Cash per bond.
     * @param string             $paymentType    CouponPayment::TYPE_*.
     * @param float              $simulationTime Simulation time in years.
     * @param \DateTimeInterface $paidAt         Payment timestamp, identical across both statements.
     */
    private function distribute(Bond $bond, float $amountPerBond, string $paymentType, float $simulationTime, \DateTimeInterface $paidAt): void
    {
        $conn = $this->entityManager->getConnection();
        $paidAtStr = $paidAt->format('Y-m-d H:i:s');

        $conn->executeStatement(
            "INSERT INTO coupon_payment
                 (user_id, ticker, payment_type, bonds_held, amount_per_bond, amount, simulation_time, paid_at)
             SELECT holdings.user_id,
                    :ticker,
                    :payment_type,
                    holdings.units,
                    :per_bond,
                    ROUND(holdings.units * :per_bond, 2),
                    :simulation_time,
                    :paid_at
             FROM (" . Portfolio::unitsHeldSql('BOND') . ") holdings
             WHERE ROUND(holdings.units * :per_bond, 2) > 0",
            [
                'ticker' => $bond->getTicker(),
                'payment_type' => $paymentType,
                'per_bond' => $amountPerBond,
                'bond_id' => $bond->getId(),
                'simulation_time' => $simulationTime,
                'paid_at' => $paidAtStr,
            ],
        );

        $conn->executeStatement(
            "UPDATE users u
             INNER JOIN coupon_payment c
                     ON c.user_id = u.id
                    AND c.ticker  = :ticker
                    AND c.paid_at = :paid_at
                    AND c.payment_type = :payment_type
             SET u.cash_balance = u.cash_balance + c.amount",
            ['ticker' => $bond->getTicker(), 'paid_at' => $paidAtStr, 'payment_type' => $paymentType],
        );
    }
}

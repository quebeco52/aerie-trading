<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Bond;
use App\Entity\CouponPayment;
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
        private EntityManagerInterface $entityManager
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
        \DateTimeInterface $paidAt
    ): void {
        $this->closeOut($bond, max(0.0, $recoveryPerBond), CouponPayment::TYPE_RECOVERY, $simulationTime, $paidAt);
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
        \DateTimeInterface $paidAt
    ): void {
        $conn = $this->entityManager->getConnection();

        $this->distribute($bond, $amountPerBond, $paymentType, $simulationTime, $paidAt);

        // Refund the cash escrowed behind open BUYs before the orders are dropped, otherwise the escrow is
        // simply destroyed along with the row.
        $conn->executeStatement(
            "UPDATE users u
             INNER JOIN trade_orders o
                     ON o.user_id = u.id
                    AND o.ticker = :ticker
                    AND o.asset_type = 'BOND'
                    AND o.status = 'OPEN'
                    AND o.action = 'BUY'
             SET u.cash_balance = u.cash_balance + (o.limit_price * (o.quantity - o.filled_quantity))",
            ['ticker' => $bond->getTicker()]
        );

        $conn->executeStatement(
            "UPDATE trade_orders SET status = 'CANCELLED'
             WHERE ticker = :ticker AND asset_type = 'BOND' AND status = 'OPEN'",
            ['ticker' => $bond->getTicker()]
        );

        $conn->executeStatement(
            'DELETE FROM user_bonds WHERE bond_id = :bond_id',
            ['bond_id' => $bond->getId()]
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
                    holdings.total_bonds,
                    :per_bond,
                    ROUND(holdings.total_bonds * :per_bond, 2),
                    :simulation_time,
                    :paid_at
             FROM (
                 SELECT user_id, SUM(total_qty) AS total_bonds
                 FROM (
                     SELECT user_id, quantity AS total_qty
                     FROM user_bonds
                     WHERE bond_id = :bond_id AND quantity > 0
                     UNION ALL
                     SELECT user_id, (quantity - filled_quantity) AS total_qty
                     FROM trade_orders
                     WHERE ticker = :ticker AND asset_type = 'BOND' AND status = 'OPEN' AND action = 'SELL'
                 ) combined_bonds
                 GROUP BY user_id
             ) holdings
             WHERE ROUND(holdings.total_bonds * :per_bond, 2) > 0",
            [
                'ticker' => $bond->getTicker(),
                'payment_type' => $paymentType,
                'per_bond' => $amountPerBond,
                'bond_id' => $bond->getId(),
                'simulation_time' => $simulationTime,
                'paid_at' => $paidAtStr,
            ]
        );

        $conn->executeStatement(
            "UPDATE users u
             INNER JOIN coupon_payment c
                     ON c.user_id = u.id
                    AND c.ticker  = :ticker
                    AND c.paid_at = :paid_at
                    AND c.payment_type = :payment_type
             SET u.cash_balance = u.cash_balance + c.amount",
            ['ticker' => $bond->getTicker(), 'paid_at' => $paidAtStr, 'payment_type' => $paymentType]
        );
    }
}

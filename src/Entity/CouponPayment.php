<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single coupon or redemption credited to one user for one bond issue.
 *
 * The same reasoning as DividendPayment: the cash credit is a bulk SQL UPDATE, so without a ledger row the
 * balance simply rises with nothing to attribute it to and total return is uncomputable, since only the
 * price leg was ever recorded. A bond makes that worse than a stock does — most of a held-to-maturity
 * return IS the coupon stream, so a bond desk without this table reports a portfolio that quietly loses to
 * par while actually making money.
 */
#[ORM\Entity]
#[ORM\Table(name: 'coupon_payment')]
#[ORM\Index(name: 'idx_coupon_user_paid', columns: ['user_id', 'paid_at'])]
#[ORM\Index(name: 'idx_coupon_user_ticker', columns: ['user_id', 'ticker'])]
class CouponPayment
{
    /** A scheduled interest payment. */
    public const TYPE_COUPON = 'COUPON';

    /** Return of face value at maturity, which also closes out the holding. */
    public const TYPE_REDEMPTION = 'REDEMPTION';

    /** What a holder got back when the issuer failed: the claim settled at its recovery, not at face. */
    public const TYPE_RECOVERY = 'RECOVERY';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $ticker;

    #[ORM\Column(length: 12, options: ['default' => self::TYPE_COUPON])]
    private string $paymentType = self::TYPE_COUPON;

    #[ORM\Column(type: Types::BIGINT)]
    private string $bondsHeld;

    /** Cash per bond: the coupon amount, or the face value on a redemption. */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private string $amountPerBond;

    /** Cash actually credited, rounded once at write time to match users.cash_balance scale. */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    /** Simulation time in years at which the payment fell due. */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.0])]
    private float $simulationTime = 0.0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $paidAt;

    public function __construct()
    {
        $this->paidAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function setTicker(string $ticker): static
    {
        $this->ticker = $ticker;

        return $this;
    }

    public function getPaymentType(): string
    {
        return $this->paymentType;
    }

    public function setPaymentType(string $paymentType): static
    {
        $this->paymentType = $paymentType;

        return $this;
    }

    public function getBondsHeld(): string
    {
        return $this->bondsHeld;
    }

    public function setBondsHeld(string $bondsHeld): static
    {
        $this->bondsHeld = $bondsHeld;

        return $this;
    }

    public function getAmountPerBond(): string
    {
        return $this->amountPerBond;
    }

    public function setAmountPerBond(string $amountPerBond): static
    {
        $this->amountPerBond = $amountPerBond;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getSimulationTime(): float
    {
        return $this->simulationTime;
    }

    public function setSimulationTime(float $simulationTime): static
    {
        $this->simulationTime = $simulationTime;

        return $this;
    }

    public function getPaidAt(): \DateTime
    {
        return $this->paidAt;
    }

    public function setPaidAt(\DateTime $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }
}

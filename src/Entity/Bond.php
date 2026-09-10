<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\Math\FinancialConstants;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One sovereign bond issue: a fixed coupon and a fixed redemption date, struck at auction and traded
 * until it matures.
 *
 * Issues are real rather than constant-maturity trackers. A ten-year sold three years ago is a seven-year
 * today and a 6.75-year next quarter, and that ageing is most of what makes a ladder a decision: the issue
 * rolls down the curve, its duration falls, and its sensitivity to a given rate move falls with it. A
 * constant-maturity synthetic never ages, so it earns no roll-down and never de-risks, which removes both
 * sides of the trade.
 *
 * Time is held as simulation years (MacroState::$totalTime), not as a wall-clock date. The tick rate is
 * configurable and a bond's economics depend on how much simulated time is left, never on how long the
 * process has been running.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bonds')]
#[ORM\Index(name: 'idx_bond_status_matures', columns: ['status', 'matures_at_time'])]
#[ORM\Index(name: 'idx_bond_on_the_run', columns: ['tenor_years', 'is_on_the_run'])]
class Bond
{
    /** Outstanding and tradable. */
    public const STATUS_ACTIVE = 'ACTIVE';

    /** Redeemed at par; holdings have been cashed out and the issue no longer trades. */
    public const STATUS_MATURED = 'MATURED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $ticker;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Original maturity in years at auction (2, 5, 10, 30). Kept for grouping; it is not the remaining life. */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $tenorYears;

    /** Annual coupon rate struck at auction, in eighths of a percent. Fixed for the life of the issue. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6)]
    private string $couponRate = '0.000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $faceValue;

    /** Simulation time in years at auction. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $issuedAtTime = 0.0;

    /** Simulation time in years at redemption: issuedAtTime plus the original tenor. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $maturesAtTime = 0.0;

    /** Simulation time in years of the most recent coupon paid, so the next one and the accrual are derivable. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $lastCouponTime = 0.0;

    /** The newest issue at this tenor. One per tenor; the previous holder is demoted at the next auction. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isOnTheRun = false;

    #[ORM\Column(length: 10, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    /**
     * Dirty price of one bond: present value including accrued interest.
     *
     * Dirty rather than the clean price a desk quotes, because this is the column the trading path
     * multiplies by quantity to get cash. Quoting clean here would hand the buyer the seller's accrued
     * interest for free at every fill. The clean quote is derived for display.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $price;

    /** Dirty price less accrued interest: the quoted price, which does not saw upward through a coupon period. */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $cleanPrice;

    /** Coupon earned by the seller since the last payment, on an actual/actual basis. */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8, options: ['default' => '0.00000000'])]
    private string $accruedInterest = '0.00000000';

    /** Continuously compounded yield that reproduces the current dirty price. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, options: ['default' => '0.000000'])]
    private string $yieldToMaturity = '0.000000';

    /** First-order price sensitivity to a parallel yield shift, in years. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, options: ['default' => '0.000000'])]
    private string $modifiedDuration = '0.000000';

    /** Second-order price sensitivity, in years squared. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, options: ['default' => '0.000000'])]
    private string $convexity = '0.000000';

    /** Face amount sold at auction. Sets how large a position the desk can absorb. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, options: ['default' => '0.00'])]
    private string $outstandingFace = '0.00';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
        $this->faceValue = (string) FinancialConstants::BOND_FACE_VALUE;
        $this->price = (string) FinancialConstants::BOND_FACE_VALUE;
        $this->cleanPrice = (string) FinancialConstants::BOND_FACE_VALUE;
    }

    /**
     * Years of simulated time until redemption, floored at zero.
     *
     * @param float $currentTime Current simulation time in years.
     */
    public function yearsToMaturity(float $currentTime): float
    {
        return max(0.0, $this->maturesAtTime - $currentTime);
    }

    /** Cash paid at each coupon date: the annual rate on face, divided across the year's payments. */
    public function couponAmount(): float
    {
        return ((float) $this->couponRate * (float) $this->faceValue) / FinancialConstants::BOND_COUPON_FREQUENCY;
    }

    /** Length of one coupon period in years. */
    public function couponPeriodYears(): float
    {
        return 1.0 / FinancialConstants::BOND_COUPON_FREQUENCY;
    }

    public function isMatured(float $currentTime): bool
    {
        return $this->maturesAtTime - $currentTime <= FinancialConstants::BOND_MATURITY_EPSILON;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getTenorYears(): string
    {
        return $this->tenorYears;
    }

    public function setTenorYears(string $tenorYears): static
    {
        $this->tenorYears = $tenorYears;

        return $this;
    }

    public function getCouponRate(): string
    {
        return $this->couponRate;
    }

    public function setCouponRate(string $couponRate): static
    {
        $this->couponRate = $couponRate;

        return $this;
    }

    public function getFaceValue(): string
    {
        return $this->faceValue;
    }

    public function setFaceValue(string $faceValue): static
    {
        $this->faceValue = $faceValue;

        return $this;
    }

    public function getIssuedAtTime(): float
    {
        return $this->issuedAtTime;
    }

    public function setIssuedAtTime(float $issuedAtTime): static
    {
        $this->issuedAtTime = $issuedAtTime;

        return $this;
    }

    public function getMaturesAtTime(): float
    {
        return $this->maturesAtTime;
    }

    public function setMaturesAtTime(float $maturesAtTime): static
    {
        $this->maturesAtTime = $maturesAtTime;

        return $this;
    }

    public function getLastCouponTime(): float
    {
        return $this->lastCouponTime;
    }

    public function setLastCouponTime(float $lastCouponTime): static
    {
        $this->lastCouponTime = $lastCouponTime;

        return $this;
    }

    public function isOnTheRun(): bool
    {
        return $this->isOnTheRun;
    }

    public function setIsOnTheRun(bool $isOnTheRun): static
    {
        $this->isOnTheRun = $isOnTheRun;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getCleanPrice(): string
    {
        return $this->cleanPrice;
    }

    public function setCleanPrice(string $cleanPrice): static
    {
        $this->cleanPrice = $cleanPrice;

        return $this;
    }

    public function getAccruedInterest(): string
    {
        return $this->accruedInterest;
    }

    public function setAccruedInterest(string $accruedInterest): static
    {
        $this->accruedInterest = $accruedInterest;

        return $this;
    }

    public function getYieldToMaturity(): string
    {
        return $this->yieldToMaturity;
    }

    public function setYieldToMaturity(string $yieldToMaturity): static
    {
        $this->yieldToMaturity = $yieldToMaturity;

        return $this;
    }

    public function getModifiedDuration(): string
    {
        return $this->modifiedDuration;
    }

    public function setModifiedDuration(string $modifiedDuration): static
    {
        $this->modifiedDuration = $modifiedDuration;

        return $this;
    }

    public function getConvexity(): string
    {
        return $this->convexity;
    }

    public function setConvexity(string $convexity): static
    {
        $this->convexity = $convexity;

        return $this;
    }

    public function getOutstandingFace(): string
    {
        return $this->outstandingFace;
    }

    public function setOutstandingFace(string $outstandingFace): static
    {
        $this->outstandingFace = $outstandingFace;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}

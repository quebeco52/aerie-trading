<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One listed European option on one stock.
 *
 * A contract is a row rather than a quote computed on demand because open interest has to live somewhere:
 * the desk's hedging flow is a function of what the market has actually written, not of what could be
 * written, and that is the whole point of listing options in a simulation that already has an order-flow
 * channel. The premium and greeks are marked here each repricing tick so the chain reads without recomputing
 * it, but the trade path always reprices the contract it is filling — a resting mark is for display.
 *
 * European, and exercised by exception at expiry. American early exercise is only ever optimal on a put, or
 * on a call over a dividend, and pricing that needs a lattice per contract per tick; the whole chain would
 * cost more than the price process it is written on.
 */
#[ORM\Entity(repositoryClass: \App\Repository\OptionContractRepository::class)]
#[ORM\Table(name: 'option_contracts')]
#[ORM\Index(name: 'option_underlying_status', columns: ['stock_id', 'status'])]
#[ORM\Index(name: 'option_status_expiry', columns: ['status', 'expires_at_time'])]
class OptionContract
{
    /** Listed and quoting. */
    public const STATUS_ACTIVE = 'ACTIVE';

    /** Past expiry, settled, and no longer tradable. */
    public const STATUS_EXPIRED = 'EXPIRED';

    /** The right to buy the underlying at the strike. */
    public const TYPE_CALL = 'CALL';

    /** The right to sell it. */
    public const TYPE_PUT = 'PUT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $ticker;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Stock $stock;

    #[ORM\Column(length: 4)]
    private string $optionType = self::TYPE_CALL;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $strike;

    /**
     * The monthly listing serial this expiry falls on.
     *
     * Contracts are listed against a grid rather than against a date so that every name's chain expires on
     * the same days, which is what makes an expiry a market-wide event rather than 52 unrelated ones.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $expirySerial = 0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $expiresAtTime = 0.0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $listedAtTime = 0.0;

    #[ORM\Column(length: 10, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    /** Mark premium per SHARE, mid of the desk's quote. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0.00000000'])]
    private string $price = '0.00000000';

    /** The volatility the mark was struck at, after the smile. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, options: ['default' => '0.000000'])]
    private string $impliedVolatility = '0.000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 8, options: ['default' => '0.00000000'])]
    private string $delta = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 12, options: ['default' => '0.000000000000'])]
    private string $gamma = '0.000000000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0.00000000'])]
    private string $vega = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0.00000000'])]
    private string $theta = '0.00000000';

    /**
     * Contracts held long by the market, which the desk is therefore short.
     *
     * Signed from the customer's side: positive is a public net long and negative a public net short. It is
     * what the desk's hedge is computed from, so it has to carry the sign rather than a gross count.
     */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $openInterest = 0;

    /**
     * The public's net position in this contract, held by everyone who is not a player.
     *
     * Kept apart from the players' open interest rather than summed into it, because the two are written by
     * different things: this one by the demand model, that one by fills. Merging them would mean the demand
     * model could only move the total by reading the part it does not own, and a player closing a position
     * would look to it like public demand falling.
     */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $structuralOpenInterest = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
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

    public function getStock(): Stock
    {
        return $this->stock;
    }

    public function setStock(Stock $stock): static
    {
        $this->stock = $stock;

        return $this;
    }

    public function getOptionType(): string
    {
        return $this->optionType;
    }

    public function setOptionType(string $optionType): static
    {
        $this->optionType = $optionType;

        return $this;
    }

    /** Whether this contract is a call, which is the form every pricing call wants. */
    public function isCall(): bool
    {
        return $this->optionType === self::TYPE_CALL;
    }

    public function getStrike(): string
    {
        return $this->strike;
    }

    public function setStrike(string $strike): static
    {
        $this->strike = $strike;

        return $this;
    }

    public function getExpirySerial(): int
    {
        return $this->expirySerial;
    }

    public function setExpirySerial(int $expirySerial): static
    {
        $this->expirySerial = $expirySerial;

        return $this;
    }

    public function getExpiresAtTime(): float
    {
        return $this->expiresAtTime;
    }

    public function setExpiresAtTime(float $expiresAtTime): static
    {
        $this->expiresAtTime = $expiresAtTime;

        return $this;
    }

    public function getListedAtTime(): float
    {
        return $this->listedAtTime;
    }

    public function setListedAtTime(float $listedAtTime): static
    {
        $this->listedAtTime = $listedAtTime;

        return $this;
    }

    /** Years of life left, floored at zero: an expiry that has passed has no negative time value. */
    public function timeToExpiry(float $currentTime): float
    {
        return max(0.0, $this->expiresAtTime - $currentTime);
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

    public function getImpliedVolatility(): string
    {
        return $this->impliedVolatility;
    }

    public function setImpliedVolatility(string $impliedVolatility): static
    {
        $this->impliedVolatility = $impliedVolatility;

        return $this;
    }

    public function getDelta(): string
    {
        return $this->delta;
    }

    public function setDelta(string $delta): static
    {
        $this->delta = $delta;

        return $this;
    }

    public function getGamma(): string
    {
        return $this->gamma;
    }

    public function setGamma(string $gamma): static
    {
        $this->gamma = $gamma;

        return $this;
    }

    public function getVega(): string
    {
        return $this->vega;
    }

    public function setVega(string $vega): static
    {
        $this->vega = $vega;

        return $this;
    }

    public function getTheta(): string
    {
        return $this->theta;
    }

    public function setTheta(string $theta): static
    {
        $this->theta = $theta;

        return $this;
    }

    public function getOpenInterest(): int|string
    {
        return $this->openInterest;
    }

    public function setOpenInterest(int|string $openInterest): static
    {
        $this->openInterest = $openInterest;

        return $this;
    }

    public function getStructuralOpenInterest(): int|string
    {
        return $this->structuralOpenInterest;
    }

    public function setStructuralOpenInterest(int|string $structuralOpenInterest): static
    {
        $this->structuralOpenInterest = $structuralOpenInterest;

        return $this;
    }

    /**
     * Everyone who is long this contract, players and public together.
     *
     * This is the number the desk is short, so it is the number its hedge is computed from.
     */
    public function customerOpenInterest(): int
    {
        return (int) $this->openInterest + (int) $this->structuralOpenInterest;
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

    /** Intrinsic value per share at a given underlying price; what the contract settles for at expiry. */
    public function intrinsicValue(float $underlyingPrice): float
    {
        $strike = (float) $this->strike;

        return $this->isCall()
            ? max(0.0, $underlyingPrice - $strike)
            : max(0.0, $strike - $underlyingPrice);
    }
}

<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a publicly traded stock on the Aerie Exchange.
 * 
 * This entity holds not only standard asset information (ticker, name, price)
 * but also the specific financial metrics and stochastic variables (volatility, beta, 
 * jump-diffusion parameters) required by the market simulation engine to calculate 
 * organic price movements.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stocks')]
class Stock
{
    /**
     * @var int|null The unique internal database identifier.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var string|null The unique stock ticker symbol (e.g., 'LAKE').
     */
    #[ORM\Column(length: 10, unique: true)]
    private ?string $ticker = null;

    /**
     * @var string|null The full corporate name of the company.
     */
    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var string|null The economic sector, used for macroeconomic P/E drift and sector rotation.
     */
    #[ORM\Column(length: 50, options: ['default' => 'General'])]
    private ?string $sector = 'General';

    /**
     * @var string|null The current trading price of the stock.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '100.00000000'])]
    private ?string $price = '100.00000000';

    /**
     * @var string|null The total number of shares, used to calculate total Market Capitalization.
     */
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true, 'default' => 1000000])]
    private ?string $sharesOutstanding = '1000000';

    /**
     * @var string|null Earnings per share, used to calculate fundamental valuation (P/E ratio).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true, options: ['default' => '10.00000000'])]
    private ?string $earningsPerShare = '10.00000000';

    /**
     * @var string|null Baseline annual volatility (sigma) used in the stochastic pricing models.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, options: ['default' => '0.02'])]
    private ?string $volatility = '0.02';

    /**
     * @var string|null Dynamic current volatility, allowing the stock to experience periods of high/low turbulence.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $currentVolatility = null;

    /**
     * @var string|null The stock's price sensitivity to broader market/sector movements.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true, options: ['default' => '1.00'])]
    private ?string $beta = '1.00';

    /**
     * @var string|null Average number of sudden price jumps per year (lambda in a Merton jump-diffusion model).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true, options: ['default' => '2.00'])]
    private ?string $jumpIntensity = '2.00';

    /**
     * @var string|null The average log-return size of a sudden jump (can be positive or negative).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true, options: ['default' => '-0.01'])]
    private ?string $jumpMean = '-0.01';

    /**
     * @var string|null The standard deviation of the jump size, dictating how extreme jumps can be.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true, options: ['default' => '0.10'])]
    private ?string $jumpVol = '0.10';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicker(): ?string
    {
        return $this->ticker;
    }

    public function setTicker(string $ticker): static
    {
        $this->ticker = $ticker;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(string $sector): static
    {
        $this->sector = $sector;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getSharesOutstanding(): ?string
    {
        return $this->sharesOutstanding;
    }

    public function setSharesOutstanding(string $sharesOutstanding): static
    {
        $this->sharesOutstanding = $sharesOutstanding;

        return $this;
    }

    public function getEarningsPerShare(): ?string
    {
        return $this->earningsPerShare;
    }

    public function setEarningsPerShare(?string $earningsPerShare): static
    {
        $this->earningsPerShare = $earningsPerShare;

        return $this;
    }

    public function getVolatility(): ?string
    {
        return $this->volatility;
    }

    public function setVolatility(string $volatility): static
    {
        $this->volatility = $volatility;

        return $this;
    }

    public function getCurrentVolatility(): ?string
    {
        return $this->currentVolatility;
    }

    public function setCurrentVolatility(?string $currentVolatility): static
    {
        $this->currentVolatility = $currentVolatility;

        return $this;
    }

    public function getBeta(): ?string
    {
        return $this->beta;
    }

    public function setBeta(?string $beta): static
    {
        $this->beta = $beta;

        return $this;
    }

    public function getJumpIntensity(): ?string
    {
        return $this->jumpIntensity;
    }

    public function setJumpIntensity(?string $jumpIntensity): static
    {
        $this->jumpIntensity = $jumpIntensity;

        return $this;
    }

    public function getJumpMean(): ?string
    {
        return $this->jumpMean;
    }

    public function setJumpMean(?string $jumpMean): static
    {
        $this->jumpMean = $jumpMean;

        return $this;
    }

    public function getJumpVol(): ?string
    {
        return $this->jumpVol;
    }

    public function setJumpVol(?string $jumpVol): static
    {
        $this->jumpVol = $jumpVol;

        return $this;
    }
}

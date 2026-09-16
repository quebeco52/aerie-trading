<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\EtfRepository::class)]
#[ORM\Table(name: 'etfs')]
class Etf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, unique: true)]
    private string $ticker;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '100.00'])]
    private string $price = '100.00';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // --- The Fund's Own Books ---
    //
    // A published index is a number. A FUND that tracks it is a portfolio with costs and income, and the two
    // do not have the same return. These five columns are the whole difference:
    //
    //   price = indexLevel x basketPerShare + accruedIncome
    //
    // `basketPerShare` is how many index units one fund share still owns. It starts at one and falls only
    // when the fund has to sell something to pay its fee, which is why a fund lags its index over time by
    // roughly its expense ratio — the tracking difference every real factsheet reports.
    //
    // `cumulativeFeesPaid` is what the fund has charged over its life, per share. It is kept separately
    // because the basket records only the fees that had to be met by SELLING something, and in an ordinary
    // market the dividend income covers the fee every quarter — so the basket sits at one and a holder
    // reading it alone would conclude the fund had been free.
    //
    // `cumulativeTradingCosts` is the spread the fund has crossed rebalancing itself. An index restrikes its
    // weights by arithmetic; a fund has to trade to follow it, and that trade is not free. Kept apart from
    // the fee because they answer different questions — what the manager charges, and what the index's own
    // turnover costs to track — and because a cap-weighted fund pays almost none of the second.
    //
    // `accruedIncome` is the dividend cash the fund has received from its constituents and not yet paid out.
    // The index is a PRICE index, so that cash is nowhere in the level; a fund holding the basket really
    // does receive it, and a holder who never received it was being quietly robbed of the dividend yield
    // every year. It is paid out quarterly, and the price drops by exactly what leaves.

    /** Annual fee the fund charges, accrued continuously against net assets. Paid from income where there is any, and out of the basket where there is not. */
    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 6, options: ['default' => '0.000500'])]
    private string $expenseRatio = '0.000500';

    /** Index units one fund share still owns. Starts at 1 and ratchets down as fees are met by selling; this IS the fund's cumulative tracking difference. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 12, options: ['default' => '1.000000000000'])]
    private string $basketPerShare = '1.000000000000';

    /** Dividend cash received from constituents and not yet distributed, per fund share. Carried in the price until it is paid out. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0.00000000'])]
    private string $accruedIncome = '0.00000000';

    /** Fees charged over the fund's life, per share. The basket only records what had to be SOLD to meet a fee; almost all of it is met out of income, and a holder who read the basket alone would think the fund had cost them nothing. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0.00000000'])]
    private string $cumulativeFeesPaid = '0.00000000';

    /** Spread the fund has crossed rebalancing itself, per share, over its life. Separate from the fee because it is a different cost with a different cause: the fee is what the manager charges, this is what the index's own turnover costs to follow. */
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0.00000000'])]
    private string $cumulativeTradingCosts = '0.00000000';

    /** The last four distributions per share, newest first. A trailing yield is a real factsheet figure and it cannot be derived from a single payment. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $recentDistributions = null;

    /** When the fund last paid out, or null before it ever has. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastDistributionAt = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

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

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getExpenseRatio(): float
    {
        return (float) $this->expenseRatio;
    }

    public function setExpenseRatio(float $expenseRatio): static
    {
        $this->expenseRatio = (string) max(0.0, $expenseRatio);

        return $this;
    }

    public function getBasketPerShare(): float
    {
        return (float) $this->basketPerShare;
    }

    public function setBasketPerShare(float $basketPerShare): static
    {
        $this->basketPerShare = (string) max(0.0, $basketPerShare);

        return $this;
    }

    public function getCumulativeFeesPaid(): float
    {
        return (float) $this->cumulativeFeesPaid;
    }

    public function setCumulativeFeesPaid(float $cumulativeFeesPaid): static
    {
        $this->cumulativeFeesPaid = (string) max(0.0, $cumulativeFeesPaid);

        return $this;
    }

    public function getCumulativeTradingCosts(): float
    {
        return (float) $this->cumulativeTradingCosts;
    }

    public function setCumulativeTradingCosts(float $cumulativeTradingCosts): static
    {
        $this->cumulativeTradingCosts = (string) max(0.0, $cumulativeTradingCosts);

        return $this;
    }

    public function getAccruedIncome(): float
    {
        return (float) $this->accruedIncome;
    }

    public function setAccruedIncome(float $accruedIncome): static
    {
        $this->accruedIncome = (string) max(0.0, $accruedIncome);

        return $this;
    }

    /**
     * The last four distributions per share, newest first.
     *
     * @return list<float>
     */
    public function getRecentDistributions(): array
    {
        return array_values(array_map('floatval', $this->recentDistributions ?? []));
    }

    /**
     * Records a distribution, keeping only the trailing year of them.
     *
     * Four, because a trailing yield is an ANNUAL figure and the fund pays quarterly. Keeping one payment
     * and multiplying it by four would report a yield off whichever quarter happened to be last, and
     * dividend income is not evenly spread across a year.
     */
    public function recordDistribution(float $perShare, \DateTimeInterface $paidAt): static
    {
        $recent = $this->getRecentDistributions();
        array_unshift($recent, $perShare);

        $this->recentDistributions = array_slice($recent, 0, FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR);
        $this->lastDistributionAt = $paidAt;

        return $this;
    }

    /** What the fund has paid out over the trailing year, per share. */
    public function trailingDistribution(): float
    {
        return array_sum($this->getRecentDistributions());
    }

    public function getLastDistributionAt(): ?\DateTimeInterface
    {
        return $this->lastDistributionAt;
    }

    /**
     * The index level behind this fund's price, with the fund's own accounting stripped back out.
     *
     * The published level is what the committee restates its divisor against and what the fund is measured
     * for tracking against, so anything reading a level off the fund has to undo the fee drag and the
     * undistributed income first. Reading the price as if it were the level would feed the fund's own costs
     * back into the index at every reconstitution.
     */
    public function getIndexLevel(): float
    {
        $basket = $this->getBasketPerShare();

        if ($basket <= 0.0) {
            return (float) $this->price;
        }

        return max(0.0, ((float) $this->price - $this->getAccruedIncome()) / $basket);
    }
}
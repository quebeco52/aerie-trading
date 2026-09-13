<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single cash dividend received by one user for one holding, at one ex-date.
 *
 * The cash credit itself is a bulk SQL UPDATE in CorporateLedgerService, so without this table a
 * distribution was invisible: the balance simply rose. Nothing could attribute the rise to a payer,
 * no surface could show dividend income, and total return was uncomputable because only the price
 * leg of it had ever been recorded.
 *
 * Rows are written by the same statement that derives the cash credit, so the ledger sums to the
 * cash by construction rather than by two queries agreeing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dividend_payment')]
#[ORM\Index(name: 'idx_dividend_user_paid', columns: ['user_id', 'paid_at'])]
#[ORM\Index(name: 'idx_dividend_user_ticker', columns: ['user_id', 'ticker'])]
#[ORM\UniqueConstraint(name: 'uniq_dividend_user_ticker_paid', columns: ['user_id', 'ticker', 'paid_at'])]
class DividendPayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Matches TradeOrder's convention ('STOCK' or 'ETF') so an ETF distribution lands in the same ledger. */
    #[ORM\Column(length: 10)]
    private string $assetType = 'STOCK';

    #[ORM\Column(length: 10)]
    private string $ticker;

    /**
     * Shares the payment was measured against, in the share units current at the ex-date.
     *
     * Not restated by CorporateLedgerService::processStockSplit(), unlike user_stocks and trade_orders:
     * the cash in `amount` was really received and rescaling it would rewrite history. This column and
     * dividendPerShare are therefore historical units and are not comparable across a split.
     */
    #[ORM\Column(type: Types::BIGINT)]
    private string $sharesHeld;

    /** Per-share rate at the ex-date, in the same pre-restatement units as sharesHeld. */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private string $dividendPerShare;

    /** Cash actually credited, rounded once at write time to match users.cash_balance scale. */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

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

    public function getAssetType(): string
    {
        return $this->assetType;
    }

    public function setAssetType(string $assetType): static
    {
        $this->assetType = $assetType;

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

    public function getSharesHeld(): string
    {
        return $this->sharesHeld;
    }

    public function setSharesHeld(string $sharesHeld): static
    {
        $this->sharesHeld = $sharesHeld;

        return $this;
    }

    public function getDividendPerShare(): string
    {
        return $this->dividendPerShare;
    }

    public function setDividendPerShare(string $dividendPerShare): static
    {
        $this->dividendPerShare = $dividendPerShare;

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

<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\OptionContract;
use App\Entity\Stock;

/**
 * A ticker resolved to the instrument behind it, with the asset-class tag the order ledger records.
 *
 * Exists so the trading path asks "what is this and what does it cost" once, rather than re-deriving it from
 * a chain of nullable entity variables at every site that needs it. That chain worked while there were two
 * asset classes and one of them could be inferred from the other being null; a third makes every such site a
 * three-way branch, and there are four of them.
 */
final readonly class ResolvedAssetDTO
{
    public function __construct(
        public Stock|Etf|Bond|OptionContract $entity,
        public string $type,
    ) {}

    public function ticker(): string
    {
        return $this->entity->getTicker();
    }

    /**
     * The price one unit trades at.
     *
     * For a bond this is the dirty price, so quantity times price is the cash that actually changes hands
     * and the seller is paid the coupon they have accrued. For an option it is the premium per SHARE, which
     * the contract multiplier turns into the consideration for one contract.
     */
    public function price(): string
    {
        return (string) $this->entity->getPrice();
    }

    /**
     * Why trading is halted in this instrument, or null if it is open.
     *
     * A matured bond is halted for the same reason a bankrupt company is: the instrument still has rows in
     * the database but no longer has a market, and an order against it could never be honoured.
     */
    public function haltReason(): ?string
    {
        if ($this->entity instanceof Stock && $this->entity->isBankrupt()) {
            return "Trading is halted for {$this->entity->getTicker()}. The company is bankrupt.";
        }

        if ($this->entity instanceof Bond && $this->entity->getStatus() !== Bond::STATUS_ACTIVE) {
            // A defaulted issue and a matured one are both untradable, but for opposite reasons, and telling
            // a holder their bond "matured" when the borrower failed is the wrong thing to say about it.
            $reason = $this->entity->getStatus() === Bond::STATUS_DEFAULTED
                ? 'The issuer defaulted and the claim has been settled at its recovery.'
                : 'The issue has matured.';

            return "Trading is halted for {$this->entity->getTicker()}. {$reason}";
        }

        if ($this->entity instanceof OptionContract) {
            if ($this->entity->getStatus() !== OptionContract::STATUS_ACTIVE) {
                return "Trading is halted for {$this->entity->getTicker()}. The contract has expired.";
            }

            if ($this->entity->getStock()->isBankrupt()) {
                return "Trading is halted for {$this->entity->getTicker()}. The underlying is bankrupt.";
            }
        }

        return null;
    }
}

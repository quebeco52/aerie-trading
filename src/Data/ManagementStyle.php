<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Persistent management style, after Bertrand & Schoar (2003), "Managing with Style: The Effect of
 * Managers on Firm Policies", Quarterly Journal of Economics.
 *
 * Tracking managers across firms, they found individual fixed effects that persist and explain a
 * material share of the variation in investment, financial and payout policy — two firms with identical
 * fundamentals are run differently depending on who runs them. The styles below are that finding
 * expressed as biases on dials the engines already have, not as new behaviour branches.
 *
 * The investment dimension is Jensen (1986), "Agency Costs of Free Cash Flow": a manager with cash and
 * weak discipline reinvests below the cost of capital rather than distributing, and growth financed that
 * way destroys value while still growing the firm. That is why the style bends the HURDLE RATE a manager
 * applies rather than simply spending more: an empire builder is not investing harder, it is accepting
 * projects a disciplined board would reject.
 */
enum ManagementStyle: string
{
    /** The balanced default: invests at the cost of capital and pays out what it cannot reinvest. */
    case Operator = 'operator';
    /** Jensen's agency case: growth is the objective, so projects clear a hurdle below the true cost of capital and payout is minimised. */
    case EmpireBuilder = 'empire_builder';
    /** The disciplined distributor: a deliberately high internal hurdle, and cash the firm cannot beat it with goes back to shareholders. */
    case Steward = 'steward';
    /** Balance-sheet conservatism: reinvests cautiously and retains cash against the cycle rather than distributing it. */
    case Fortress = 'fortress';

    /** Multiplier on the firm's target payout ratio. */
    public function payoutBias(): float
    {
        return match ($this) {
            self::Operator => 1.00,
            self::EmpireBuilder => 0.60,
            self::Steward => 1.35,
            self::Fortress => 0.85,
        };
    }

    /** Multiplier on the reinvestment (capex) ratio the firm plans against. */
    public function reinvestmentBias(): float
    {
        return match ($this) {
            self::Operator => 1.00,
            self::EmpireBuilder => 1.30,
            self::Steward => 0.85,
            self::Fortress => 0.80,
        };
    }

    /**
     * Multiplier on the hurdle rate management actually applies to growth projects. Below one is the
     * agency cost itself: capital deployed into returns that do not clear the true cost of capital.
     */
    public function hurdleBias(): float
    {
        return match ($this) {
            self::Operator => 1.00,
            self::EmpireBuilder => 0.75,
            self::Steward => 1.20,
            self::Fortress => 1.10,
        };
    }

    public static function tryFromNullable(?string $value): self
    {
        return $value !== null ? (self::tryFrom($value) ?? self::Operator) : self::Operator;
    }
}

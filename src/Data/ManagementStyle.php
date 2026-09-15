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
 *
 * Two rules govern where a bias may be read, and both matter for the mechanic to mean anything:
 *
 *  1. A style bends what MANAGEMENT chooses, never what the world imposes. The applied hurdle is a
 *     preference; the true cost of capital that values the firm, the regulator's capital limits, the
 *     minimum operating cash that keeps it solvent and the impairment test are not. The wedge between the
 *     hurdle management applies and the one the market discounts at IS the agency cost, so collapsing the
 *     two would delete the mechanic rather than strengthen it.
 *  2. A bias must reach every gate that decides the same question, or it is not a fixed effect. A manager
 *     that is disciplined about debt-funded plant and reckless about cash-funded plant is not a style, it
 *     is a bug.
 *
 * Operator is the neutral baseline BY CONSTRUCTION: every multiplier is exactly 1.0 and every additive
 * term exactly 0.0, so an unassigned firm behaves as though the mechanic were absent. Keep it that way.
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

    /**
     * Multiplier on the firm's total shareholder payout — dividends and repurchases alike.
     *
     * It has to govern both legs or it governs neither: biasing the dividend alone leaves the cash it
     * retained sitting in a treasury the buyback engine then drains, so the "retaining" manager ends up
     * distributing more than the one that pays it out, just through the other leg.
     */
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

    /**
     * Multiplier on the DISCRETIONARY cash target — the buffer above the solvency floor, which is what the
     * hoarding tests measure a firm against.
     *
     * Opler, Pinkowitz, Stulz & Williamson (1999), "The determinants and implications of corporate cash
     * holdings": precautionary balances are a policy choice with a large unexplained firm component, and
     * Bertrand & Schoar find a manager fixed effect in it directly. Without this dial the fortress is
     * self-defeating — it retains by paying out less, trips the hoarder threshold on the cash it retained,
     * and is force-fed into buybacks at a mega-hoarder pace. Raising its target is what lets it hold dry
     * powder through the cycle instead of having the balance sheet corrected out from under it.
     */
    public function cashTargetBias(): float
    {
        return match ($this) {
            self::Operator => 1.00,
            self::EmpireBuilder => 0.80,
            self::Steward => 0.90,
            self::Fortress => 1.75,
        };
    }

    /**
     * Multiplier on how much of its available debt capacity the firm is willing to draw.
     *
     * Bertrand & Schoar find manager fixed effects in financial policy as strongly as in investment, and
     * an empire is a levered thing. This deliberately biases APPETITE and not the capacity itself: the
     * balance-sheet and coverage limits, and a regulated financial's equity limit, are constraints the
     * world imposes, so a style that moved them would be letting a CEO vote on Basel.
     */
    public function leverageBias(): float
    {
        return match ($this) {
            self::Operator => 1.00,
            self::EmpireBuilder => 1.30,
            self::Steward => 0.90,
            self::Fortress => 0.60,
        };
    }

    /**
     * Multiplier on the hazard of the firm attempting an acquisition in a given year.
     *
     * Empire building is not mostly organic — it is bought (Morck, Shleifer & Vishny 1990, "Do Managerial
     * Objectives Drive Bad Acquisitions?"), which is also what makes the archetype observable: the deals,
     * the goodwill, and the impairment that follows are all on the tape.
     */
    public function acquisitionBias(): float
    {
        return match ($this) {
            self::Operator => 1.00,
            self::EmpireBuilder => 2.50,
            self::Steward => 0.50,
            self::Fortress => 0.60,
        };
    }

    /**
     * Premium paid over the target's standalone value, as a fraction of it. Additive, so zero is neutral.
     *
     * Roll's (1986) hubris hypothesis: the bidder that wins is the one that most overestimates the target,
     * and pays the difference. Hayward & Hambrick (1997) tie the size of the premium to the acquirer's
     * management directly. This is the overpayment wedge ONLY — the synergy that is genuinely realised is
     * drawn separately and is not touched here, so the premium is pure capital that buys no earnings and
     * lands in goodwill to be impaired later.
     */
    public function hubrisPremium(): float
    {
        return match ($this) {
            self::Operator => 0.00,
            self::EmpireBuilder => 0.25,
            self::Steward => 0.00,
            self::Fortress => 0.00,
        };
    }

    /**
     * The hurdle management actually applies to a capital-deployment decision, given the firm's true cost
     * of capital. Every such gate must resolve it through here so that one manager holds one hurdle.
     */
    public function appliedHurdle(float $trueHurdleRate): float
    {
        return $trueHurdleRate * $this->hurdleBias();
    }

    /** The discretionary cash balance this manager runs the firm at, given the model's own target. */
    public function appliedTargetCash(float $targetOperatingCash): float
    {
        return $targetOperatingCash * $this->cashTargetBias();
    }

    /**
     * The base the hoarding thresholds are measured against, scaled the same way as the target.
     *
     * "Is this firm sitting on too much cash?" is the same question the cash target answers, so the two have
     * to move together. Biasing only the target would not have saved the fortress: the thresholds are a
     * fraction of the operating base and dwarf the target itself, so the firm still tripped them on the
     * reserves it holds deliberately and was force-fed into repurchases at a mega-hoarder pace.
     */
    public function appliedHoardingBase(float $operatingBase): float
    {
        return $operatingBase * $this->cashTargetBias();
    }

    /** Display name for the archetype. */
    public function label(): string
    {
        return match ($this) {
            self::Operator => 'Operator',
            self::EmpireBuilder => 'Empire Builder',
            self::Steward => 'Steward',
            self::Fortress => 'Fortress',
        };
    }

    /**
     * What this management is expected to do with the firm's capital, in a sentence. Shared by the
     * succession announcement and the company page, so the market is told one story about a manager.
     */
    public function mandate(): string
    {
        return match ($this) {
            self::Operator => 'expected to invest at the cost of capital and distribute the rest',
            self::EmpireBuilder => 'mandated to grow, and will fund projects a stricter board would refuse',
            self::Steward => 'expected to hold a high internal hurdle and return what it cannot beat',
            self::Fortress => 'expected to rebuild the balance sheet and hold cash against the cycle',
        };
    }

    public static function tryFromNullable(?string $value): self
    {
        return $value !== null ? (self::tryFrom($value) ?? self::Operator) : self::Operator;
    }
}

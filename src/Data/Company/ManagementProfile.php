<?php

declare(strict_types=1);

namespace App\Data\Company;

use App\Service\Math\MathUtility;
use App\Service\Model\Strategy\TreasuryStrategyInterface;

/**
 * An individual manager: an archetype from [[ManagementStyle]] plus how strongly they hold it.
 *
 * Bertrand & Schoar (2003) estimate a DISTRIBUTION of manager fixed effects, not a handful of types. Four
 * enum cases carry the shape of that distribution — where in policy space each kind of manager sits — but on
 * their own they also say every empire builder runs an identical firm, which is the finding flattened into
 * its own means. The intensity restores the spread around them: it is the one number that separates two
 * managers who would be filed under the same label.
 *
 * It scales the DISTANCE of every dial from neutral rather than the dial itself, so the arithmetic degrades
 * the way it should at both ends. An intensity of 1 reproduces the archetype exactly; 0.5 is someone who
 * leans that way without committing; 0 is a manager who wears the label and runs the firm at the market
 * default. That is also what keeps Operator exactly neutral for every possible draw — its dials are already
 * 1.0 and 0.0, and scaling a distance of zero leaves zero — so an unassigned firm stays untouched no matter
 * what intensity it happens to hold.
 */
final readonly class ManagementProfile
{
    /** Median of the intensity draw: the archetype's published dials are the typical manager of that kind. */
    public const INTENSITY_MEDIAN = 1.0;
    /**
     * Log-scale dispersion of the draw, the spread Bertrand & Schoar find within a style rather than between styles.
     */
    public const INTENSITY_SIGMA = 0.28;
    /** Floor: below this a manager is indistinguishable from a neutral operator wearing the label. */
    public const MIN_INTENSITY = 0.35;
    /** Ceiling: a tail draw must not invert a dial or push a hurdle somewhere no board would sign off on. */
    public const MAX_INTENSITY = 1.80;

    public function __construct(
        public ManagementStyle $style,
        public float $intensity = self::INTENSITY_MEDIAN,
    ) {}

    /**
     * Draws the disposition of an individual manager.
     *
     * Log-normal because the quantity is a positive multiplier and its dispersion is naturally proportional,
     * with the median pinned at 1 so the archetype stays the typical manager of its kind rather than drifting
     * off it. Clamped at both ends: the tails of a log-normal are not a place to let a hurdle rate live.
     */
    public static function drawIntensity(MathUtility $mathUtility): float
    {
        $draw = $mathUtility->calculateLogNormalSynergy(0.0, self::INTENSITY_SIGMA) * self::INTENSITY_MEDIAN;

        return max(self::MIN_INTENSITY, min(self::MAX_INTENSITY, $draw));
    }

    public static function forStyle(ManagementStyle $style, ?float $intensity): self
    {
        return new self($style, $intensity === null
            ? self::INTENSITY_MEDIAN
            : max(self::MIN_INTENSITY, min(self::MAX_INTENSITY, $intensity)));
    }

    /** Multiplier on the firm's total shareholder payout — dividends and repurchases alike. */
    public function payoutBias(): float
    {
        return $this->scale($this->style->payoutBias());
    }

    /**
     * The share of earnings this manager pays out of a target share: the payout bias scales the odds of paying out, so
     * a steward pays out more than the target and an empire builder less, and neither ever more than the firm earns.
     * Scaled on the share itself, a steward on an 85% target paid out 115% and borrowed the rest.
     */
    public function appliedPayoutRatio(float $targetPayoutRatio): float
    {
        $target = max(0.0, min(1.0, $targetPayoutRatio));
        $bias = $this->payoutBias();

        return ($target * $bias) / ((1.0 - $target) + ($target * $bias));
    }

    /** Multiplier on the reinvestment (capex) ratio the firm plans against. */
    public function reinvestmentBias(): float
    {
        return $this->scale($this->style->reinvestmentBias());
    }

    /** Multiplier on the hurdle rate management applies where its appetite sizes the growth (growthHurdle()). */
    public function hurdleBias(): float
    {
        return $this->scale($this->style->hurdleBias());
    }

    /** Multiplier on the discretionary cash target the firm runs to. */
    public function cashTargetBias(): float
    {
        return $this->scale($this->style->cashTargetBias());
    }

    /** Multiplier on how much of its available debt capacity the firm is willing to draw. */
    public function leverageBias(): float
    {
        return $this->scale($this->style->leverageBias());
    }

    /** Multiplier on the hazard of the firm attempting an acquisition in a given year. */
    public function acquisitionBias(): float
    {
        return $this->scale($this->style->acquisitionBias());
    }

    /**
     * The share of what its weaker segments would lose to the stronger ones that headquarters gives them anyway.
     * Additive, so it scales directly; a share, so all of it at most.
     */
    public function weakSegmentSupport(): float
    {
        return min(1.0, $this->intensity * $this->style->weakSegmentSupport());
    }

    /** Premium paid over a target's standalone value. Additive, so it scales directly rather than about 1. */
    public function hubrisPremium(): float
    {
        return $this->intensity * $this->style->hubrisPremium();
    }

    /** The hurdle this manager actually applies to a capital-deployment decision. */
    public function appliedHurdle(float $trueHurdleRate): float
    {
        return $trueHurdleRate * $this->hurdleBias();
    }

    /**
     * The hurdle growth spending must clear. Plant is sized by demand and has to earn the firm's true cost of capital
     * whoever runs it; a lender's book is sized by management's appetite, so its own hurdle applies there.
     */
    public function growthHurdle(float $trueHurdleRate, bool $isLender): float
    {
        return $isLender ? $this->appliedHurdle($trueHurdleRate) : $trueHurdleRate;
    }

    /**
     * The cash balance this manager runs the firm at: the liability backing as it stands and the discretionary buffer
     * above it scaled by the manager's bias. Bertrand & Schoar's manager effect is on the buffer a manager chooses; a
     * fortress that scaled an insurer's float backing too held 175% of what it owed policyholders and never had a
     * dollar of excess cash to return.
     */
    public function appliedTargetCash(float $targetOperatingCash, float $liabilityBacking = 0.0): float
    {
        $backing = max(0.0, min($liabilityBacking, $targetOperatingCash));

        return $backing + (($targetOperatingCash - $backing) * $this->cashTargetBias());
    }

    /**
     * The cash balance this manager runs the firm at, from the strategy's own target and the liability backing inside
     * it.
     */
    public function managedTargetCash(TreasuryStrategyInterface $strategy, float $operatingBase, float $customerDeposits, float $wholesaleDebt): float
    {
        return $this->appliedTargetCash(
            $strategy->calculateTargetOperatingCash($operatingBase, $customerDeposits, $wholesaleDebt),
            $strategy->calculateLiabilityBackingCash($customerDeposits, $wholesaleDebt),
        );
    }

    /** The base the hoarding thresholds are measured against, scaled the same way as the target. */
    public function appliedHoardingBase(float $operatingBase): float
    {
        return $operatingBase * $this->cashTargetBias();
    }

    /**
     * How firmly this manager holds the archetype, for presentation only. The engines read the dials, never
     * this label, and a player is meant to infer the disposition from the firm's behaviour rather than be
     * told the draw.
     */
    public function convictionLabel(): string
    {
        return match (true) {
            $this->intensity < 0.70 => 'nominal',
            $this->intensity < 1.30 => 'characteristic',
            default => 'pronounced',
        };
    }

    /** Scales a multiplier's DISTANCE from neutral, which leaves an already-neutral dial neutral. */
    private function scale(float $bias): float
    {
        return 1.0 + ($this->intensity * ($bias - 1.0));
    }
}

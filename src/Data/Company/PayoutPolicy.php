<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * A board's dividend policy: the share of earnings it targets, on a five-step ladder. Boards announce a policy and
 * change it now and then, not every quarter, so the policy is a step the board reviews once a year (reviewed()), and
 * the dividend follows its target at the firm's adjustment speed. Its industry sets the usual opening step
 * (IndustryPayoutPolicies) and lore moves a firm off it.
 */
enum PayoutPolicy: string
{
    // --- Payout Steps ---
    /** Retain: no dividend, every dollar back into a business still growing. */
    public const RETAIN_RATIO = 0.0;
    /** Low: a fifth of earnings, a token dividend beside heavy reinvestment. */
    public const LOW_RATIO = 0.20;
    /** Balanced: about a third, the listed firm's usual share. */
    public const BALANCED_RATIO = 0.35;
    /** Income: half, a mature firm whose reinvestment needs fall short of its earnings. */
    public const INCOME_RATIO = 0.50;
    /** Harvest: three quarters, a cash cow with little left to build. */
    public const HARVEST_RATIO = 0.75;

    // --- Annual Review ---
    /**
     * How far past the midpoint between two steps the payout the board's position implies must sit before it moves
     * (2.5pp): a firm sitting on the midpoint does not switch policy back and forth from one year to the next.
     */
    public const REVIEW_MARGIN = 0.025;

    case Retain = 'retain';
    case Low = 'low';
    case Balanced = 'balanced';
    case Income = 'income';
    case Harvest = 'harvest';

    /** The share of earnings this policy targets. */
    public function ratio(): float
    {
        return match ($this) {
            self::Retain => self::RETAIN_RATIO,
            self::Low => self::LOW_RATIO,
            self::Balanced => self::BALANCED_RATIO,
            self::Income => self::INCOME_RATIO,
            self::Harvest => self::HARVEST_RATIO,
        };
    }

    /** Its place on the ladder, from 0 (retain) to 4 (harvest). */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /** The step a number of steps up (positive) or down the ladder, held to its ends. */
    public function shifted(int $steps): self
    {
        $cases = self::cases();

        return $cases[max(0, min(count($cases) - 1, $this->rank() + $steps))];
    }

    /** The step whose ratio is nearest a payout share; a share halfway between two takes the higher. */
    public static function nearest(float $payoutRatio): self
    {
        $best = self::Retain;
        foreach (self::cases() as $case) {
            if (abs($payoutRatio - $case->ratio()) <= abs($payoutRatio - $best->ratio()) + 1e-9) {
                $best = $case;
            }
        }

        return $best;
    }

    /**
     * The policy after the board's annual review: one step toward the payout its position implies, once that payout
     * sits past the midpoint to the next step by the review margin, and no further in one year.
     */
    public function reviewed(float $impliedRatio): self
    {
        $up = $this->shifted(1);
        if ($up !== $this && $impliedRatio > (($this->ratio() + $up->ratio()) / 2.0) + self::REVIEW_MARGIN) {
            return $up;
        }
        $down = $this->shifted(-1);
        if ($down !== $this && $impliedRatio < (($this->ratio() + $down->ratio()) / 2.0) - self::REVIEW_MARGIN) {
            return $down;
        }

        return $this;
    }

    /** The policy as the District's pages name it. */
    public function label(): string
    {
        return match ($this) {
            self::Retain => 'Retain',
            self::Low => 'Low',
            self::Balanced => 'Balanced',
            self::Income => 'Income',
            self::Harvest => 'Harvest',
        };
    }
}

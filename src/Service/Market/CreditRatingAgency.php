<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Stock;

/**
 * Service responsible for evaluating a company's Distance to Default (from Merton's model)
 * and assigning discrete alphanumeric credit ratings (AAA through D).
 */
class CreditRatingAgency
{
    // --- Threshold Constants (Distance to Default d2) ---
    /** Distance to Default threshold center for AAA rating bracket. */
    public const THRESHOLD_AAA = 3.5;
    /** Distance to Default threshold center for AA rating bracket. */
    public const THRESHOLD_AA  = 3.0;
    /** Distance to Default threshold center for A rating bracket. */
    public const THRESHOLD_A   = 2.5;
    /** Distance to Default threshold center for BBB rating bracket. */
    public const THRESHOLD_BBB = 2.0;
    /** Distance to Default threshold center for BB rating bracket. */
    public const THRESHOLD_BB  = 1.5;
    /** Distance to Default threshold center for B rating bracket. */
    public const THRESHOLD_B   = 1.0;
    /** Distance to Default threshold center for CCC rating bracket. */
    public const THRESHOLD_CCC = 0.5;

    // --- Hysteresis Buffer ---
    /** Hysteresis buffer requiring d2 to exceed threshold +/- buffer to prevent border oscillation. */
    public const HYSTERESIS_BUFFER = 0.15;

    // --- Rating Hierarchy ---
    /** Discrete numerical ranks for credit rating brackets from highest (AAA=7) to default (D=0). */
    public const RATING_RANKS = [
        'AAA' => 7,
        'AA'  => 6,
        'A'   => 5,
        'BBB' => 4,
        'BB'  => 3,
        'B'   => 2,
        'CCC' => 1,
        'D'   => 0,
    ];

    /**
     * Evaluates the company's Distance to Default and updates its assigned credit rating.
     *
     * @param Stock $stock             The stock entity being evaluated.
     * @param float $distanceToDefault The continuous Distance to Default score (d2) from Merton's model.
     * @return string|null The new credit rating bracket if a transition occurred, or null if the rating stayed the same.
     */
    public function evaluateRating(Stock $stock, float $distanceToDefault): ?string
    {
        $oldRating = $stock->getCreditRating();

        // 1. Calculate the pure mathematical rating based on the current d2 score
        $targetRating = $this->convertDistanceToRating($distanceToDefault, $oldRating);

        if ($targetRating !== $oldRating) {
            // 2. Clamp the transition to prevent multi-notch whiplash
            $clampedRating = $this->clampRatingTransition($oldRating, $targetRating);

            // 3. Update the stock entity
            $stock->setCreditRating($clampedRating);
            return $clampedRating;
        }

        return null;
    }

    /**
     * Converts a continuous Distance to Default (d2) score into an alphanumeric rating bracket.
     * Includes Hysteresis to prevent rapid upgrades/downgrades.
     */
    public function convertDistanceToRating(float $distanceToDefault, string $currentRating = 'BBB'): string
    {
        $currentRank = self::RATING_RANKS[$currentRating] ?? 4; // Assume BBB default if missing

        // Evaluate from top to bottom
        if ($this->qualifiesForBracket($distanceToDefault, self::THRESHOLD_AAA, $currentRank, 7)) {
            return 'AAA';
        }
        if ($this->qualifiesForBracket($distanceToDefault, self::THRESHOLD_AA, $currentRank, 6)) {
            return 'AA';
        }
        if ($this->qualifiesForBracket($distanceToDefault, self::THRESHOLD_A, $currentRank, 5)) {
            return 'A';
        }
        if ($this->qualifiesForBracket($distanceToDefault, self::THRESHOLD_BBB, $currentRank, 4)) {
            return 'BBB';
        }
        if ($this->qualifiesForBracket($distanceToDefault, self::THRESHOLD_BB, $currentRank, 3)) {
            return 'BB';
        }
        if ($this->qualifiesForBracket($distanceToDefault, self::THRESHOLD_B, $currentRank, 2)) {
            return 'B';
        }
        if ($this->qualifiesForBracket($distanceToDefault, self::THRESHOLD_CCC, $currentRank, 1)) {
            return 'CCC';
        }

        return 'D';
    }

    /**
     * Determines if a d2 score qualifies for a specific rating bracket, accounting for sticky hysteresis buffers.
     */
    private function qualifiesForBracket(float $distanceToDefault, float $bracketThreshold, int $currentRank, int $evaluatingRank): bool
    {
        if ($currentRank === $evaluatingRank) {
            // ALREADY IN BRACKET: Give them the benefit of the doubt. 
            // They only fall out if they drop BELOW the threshold MINUS the buffer.
            return $distanceToDefault >= ($bracketThreshold - self::HYSTERESIS_BUFFER);
        } elseif ($currentRank < $evaluatingRank) {
            // TRYING TO UPGRADE: Make it hard.
            // They must climb ABOVE the threshold PLUS the buffer.
            return $distanceToDefault >= ($bracketThreshold + self::HYSTERESIS_BUFFER);
        } else {
            // TRYING TO DOWNGRADE: 
            // If they are currently higher than this rank, we only check the standard threshold.
            // (The hysteresis check happened on the higher bracket above this one).
            return $distanceToDefault >= $bracketThreshold;
        }
    }

    /**
     * Clamps the rating transition to a maximum of one notch per evaluation.
     * Agencies rarely downgrade a company from 'A' to 'CCC' in a single day.
     */
    private function clampRatingTransition(string $oldRating, string $targetRating): string
    {
        $oldRank = self::RATING_RANKS[$oldRating] ?? 4;
        $targetRank = self::RATING_RANKS[$targetRating] ?? 4;

        // If target is more than 1 rank away, clamp it.
        if ($targetRank > $oldRank + 1) {
            $clampedRank = $oldRank + 1;
        } elseif ($targetRank < $oldRank - 1) {
            $clampedRank = $oldRank - 1;
        } else {
            return $targetRating; // Safe 1-notch transition
        }

        // Convert the clamped numeric rank back to a string rating
        $flippedRanks = array_flip(self::RATING_RANKS);
        return $flippedRanks[$clampedRank];
    }

    /**
     * Determines if the transition from oldRating to newRating represents a downgrade.
     */
    public function isDowngrade(string $oldRating, string $newRating): bool
    {
        $oldRank = self::RATING_RANKS[$oldRating] ?? 4;
        $newRank = self::RATING_RANKS[$newRating] ?? 4;

        return $newRank < $oldRank;
    }
}

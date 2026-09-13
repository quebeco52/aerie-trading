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

    // --- Altman Z''-Score Distress Bounds ---
    /** Altman Z''-Score threshold below which a firm is in deep financial distress (speculative ceiling CCC). */
    public const ALTMAN_DISTRESS_THRESHOLD = 1.10;
    /** Altman Z''-Score threshold below which a firm is in the grey zone (speculative ceiling BB). */
    public const ALTMAN_GREY_THRESHOLD = 2.60;
    /** Altman Z''-Score threshold below which a firm is insolvent/defaulting (rating D). */
    public const ALTMAN_INSOLVENT_THRESHOLD = 0.00;

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
     * Evaluates the company's Distance to Default, Altman Z''-score, and balance sheet solvency
     * to assign an updated credit rating.
     *
     * @param Stock       $stock             The stock entity being evaluated.
     * @param float       $distanceToDefault The continuous Distance to Default score (d2) from Merton's model.
     * @param float|null  $altmanZScore      The company's Altman Z''-score for accounting solvency.
     * @return string|null The new credit rating bracket if a transition occurred, or null if the rating stayed the same.
     */
    public function evaluateRating(Stock $stock, float $distanceToDefault, ?float $altmanZScore = null): ?string
    {
        $oldRating = $stock->getCreditRating();

        if ($stock->isBankrupt()) {
            if ($oldRating !== 'D') {
                $stock->setCreditRating('D');
                return 'D';
            }
            return null;
        }

        // 1. Calculate the pure structural rating based on current d2 score
        $targetRating = $this->convertDistanceToRating($distanceToDefault, $oldRating);
        $targetRank = self::RATING_RANKS[$targetRating] ?? 4;

        // 2. Fundamental & Balance Sheet Solvency Caps
        $capRank = 7; // AAA default ceiling

        // A company with negative book equity is balance sheet insolvent
        if ((float) $stock->getTotalEquity() <= 0.0) {
            $capRank = min($capRank, self::RATING_RANKS['CCC']);
        }

        if ($altmanZScore !== null) {
            if ($altmanZScore < self::ALTMAN_INSOLVENT_THRESHOLD) {
                $capRank = min($capRank, self::RATING_RANKS['D']);
            } elseif ($altmanZScore < self::ALTMAN_DISTRESS_THRESHOLD) {
                $capRank = min($capRank, self::RATING_RANKS['CCC']);
            } elseif ($altmanZScore < self::ALTMAN_GREY_THRESHOLD) {
                $capRank = min($capRank, self::RATING_RANKS['BB']);
            }
        }

        $effectiveTargetRank = min($targetRank, $capRank);
        $flippedRanks = array_flip(self::RATING_RANKS);
        $effectiveTargetRating = $flippedRanks[$effectiveTargetRank];

        if ($effectiveTargetRating !== $oldRating) {
            // Severe distress is a statement about the balance sheet, not about where the market-implied
            // d2 happens to point. Treating any CCC-or-worse target as severe defeated the notch clamp in
            // exactly the case it exists for: a firm whose accounts never deteriorated could be carried from
            // BBB to D in three evaluations on nothing but a rise in its equity volatility. Agencies move one
            // notch at a time precisely because a market-implied score is noisier than the credit is.
            $isSevereDistress = ($altmanZScore !== null && $altmanZScore < self::ALTMAN_DISTRESS_THRESHOLD)
                || ((float) $stock->getTotalEquity() <= 0.0);

            // During fatal distress or insolvency, execute an immediate emergency downgrade
            // rather than artificially maintaining investment-grade ratings via notch damping.
            if ($isSevereDistress && $effectiveTargetRank < (self::RATING_RANKS[$oldRating] ?? 4)) {
                $clampedRating = $effectiveTargetRating;
            } else {
                $clampedRating = $this->clampRatingTransition($oldRating, $effectiveTargetRating);
            }

            if ($clampedRating !== $oldRating) {
                $stock->setCreditRating($clampedRating);
                return $clampedRating;
            }
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

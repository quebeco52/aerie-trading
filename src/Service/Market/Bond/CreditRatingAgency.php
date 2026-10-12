<?php

declare(strict_types=1);

namespace App\Service\Market\Bond;

use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;

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

    // --- Hysteresis Buffer ---
    /** d2 past a bracket edge before the grade moves (0.9 notch at 6 notches per d2 unit): Altman & Rijken's (2004) migration threshold of 1.25-1.5 notches from a class's centre, 0.75-1.0 notch past its edge. */
    public const HYSTERESIS_BUFFER = 0.15;

    // --- Altman Z''-Score Distress Bounds ---
    /** Altman Z''-Score threshold below which a firm is in deep financial distress (speculative ceiling CCC). */
    public const ALTMAN_DISTRESS_THRESHOLD = 1.10;
    /** Altman Z''-Score threshold below which a firm is in the grey zone (speculative ceiling BB). */
    public const ALTMAN_GREY_THRESHOLD = 2.60;
    /** Z'' past a zone edge before the cap it sets binds or lifts: the same 0.9 notch as HYSTERESIS_BUFFER, at ~0.3 of Z'' per notch between A- and B on Altman, Hartzell & Peck's (1995) EM-score rating equivalents (second-hand, not re-pinned). */
    public const ALTMAN_ZONE_HYSTERESIS = 0.25;

    // --- Business Risk / Financial Risk Matrix (S&P, "Business Risk/Financial Risk Matrix Expanded", 2009) ---
    /** Debt/EBITDA upper bounds of the financial risk profiles: minimal <1.5x, modest 1.5-2x, intermediate 2-3x, significant 3-4x, aggressive 4-5x, highly leveraged above. */
    public const FINANCIAL_RISK_DEBT_TO_EBITDA_BOUNDS = [1.5, 2.0, 3.0, 4.0, 5.0];
    /** Debt/EBITDA upper bounds on S&P's medial volatility table, minimal to aggressive: <1.75x, 1.75-2.5x, 2.5-3.5x, 3.5-4.5x, 4.5-5.5x ("Corporate Methodology", 2013), the table S&P assessed a regulated electric utility with generation on (Louisville Gas & Electric, 2016). */
    public const MEDIAL_VOLATILITY_DEBT_TO_EBITDA_BOUNDS = [1.75, 2.5, 3.5, 4.5, 5.5];
    /** Debt/EBITDA upper bounds for a real estate company, minimal to aggressive: <2.5x, 2.5-4.5x, 4.5-7.5x, 7.5-9.5x, 9.5-13x, highly leveraged above (S&P, "Key Credit Factors For The Real Estate Industry", 2018, table 1). */
    public const REAL_ESTATE_DEBT_TO_EBITDA_BOUNDS = [2.5, 4.5, 7.5, 9.5, 13.0];
    /** Debt to adjusted total equity upper bounds for an asset manager investing its own balance sheet, minimal to aggressive: <0.4x, 0.4-0.8x, 0.8-1.5x, 1.5-2x, 2-3x, highly leveraged above (S&P, "Key Credit Factors For Asset Managers", 2014, table 1). */
    public const DEBT_TO_ADJUSTED_EQUITY_BOUNDS = [0.4, 0.8, 1.5, 2.0, 3.0];
    /** Anchors for an excellent business risk profile, S&P's assessment of rate-regulated utilities: AAA, AA, A, A-, BBB, and BB for the blank highly leveraged cell, at whole-letter grades. */
    public const EXCELLENT_BUSINESS_RISK_ANCHORS = ['AAA', 'AA', 'A', 'A', 'BBB', 'BB'];
    /** Anchors for a satisfactory business risk profile, "typical of a low investment-grade industrial issuer": A-, BBB+, BBB, BB+, BB-, B+ at whole-letter grades. */
    public const SATISFACTORY_BUSINESS_RISK_ANCHORS = ['A', 'BBB', 'BBB', 'BB', 'BB', 'B'];

    // --- Time-Weighted Core Ratios (S&P, "Corporate Methodology", 2013) ---
    /** Weights of the current year, the year before and the year before that in a core ratio: S&P's 25%, 15% and 10%. */
    public const CORE_RATIO_YEAR_WEIGHTS = [0.25, 0.15, 0.10];
    /** Weight of S&P's two forecast years, 25% each, read on the issuer's base case. */
    public const CORE_RATIO_FORECAST_WEIGHT = 0.50;

    // --- Bank Rating Methodology (S&P, "Banks: Rating Methodology And Assumptions", 2021) ---
    /** Highest rating an institution rated on its capital reaches: the US bank anchor (bbb+) plus the most a very strong capital and earnings assessment adds (+2 notches), a, at whole-letter grades; insurers are held to it too. */
    public const CAPITAL_RATED_RATING_CEILING = 'A';

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
     * The Altman zone the rating caps read, with the same hysteresis the grade brackets carry: a firm the zone's cap
     * does not yet bind has to fall past the edge by ALTMAN_ZONE_HYSTERESIS before it does, and one already held at or
     * below the cap has to clear the edge by as much before it lifts. Without it a score sitting on 2.60 capped the
     * rating at BB one quarter and released it the next.
     */
    public static function altmanZoneWithHysteresis(float $zScore, string $currentRating): string
    {
        $rank = self::RATING_RANKS[$currentRating] ?? self::RATING_RANKS['BBB'];
        $distressEdge = self::ALTMAN_DISTRESS_THRESHOLD + ($rank > self::RATING_RANKS['CCC'] ? -self::ALTMAN_ZONE_HYSTERESIS : self::ALTMAN_ZONE_HYSTERESIS);
        $greyEdge = self::ALTMAN_GREY_THRESHOLD + ($rank > self::RATING_RANKS['BB'] ? -self::ALTMAN_ZONE_HYSTERESIS : self::ALTMAN_ZONE_HYSTERESIS);

        return match (true) {
            $zScore < $distressEdge => 'Distress',
            $zScore < $greyEdge => 'Grey',
            default => 'Safe',
        };
    }

    /**
     * Evaluates the company's Distance to Default, Altman Z''-score, and balance sheet solvency
     * to assign an updated credit rating.
     *
     * @param Stock       $stock             The stock entity being evaluated.
     * @param float       $distanceToDefault The continuous Distance to Default score (d2) from Merton's model.
     * @param float|null  $altmanZScore      The company's Altman Z''-score for accounting solvency.
     * @param string|null $altmanZone        That score's zone as the firm's OWN model classified it.
     * @param string|null $leverageAnchor    Ceiling from the firm's leverage (financialRiskAnchor(), adjustedEquityLeverageAnchor()), or CAPITAL_RATED_RATING_CEILING for a firm rated on its capital; null for none.
     * @return string|null The new credit rating bracket if a transition occurred, or null if the rating stayed the same.
     */
    public function evaluateRating(Stock $stock, float $distanceToDefault, ?float $altmanZScore = null, ?string $altmanZone = null, ?string $leverageAnchor = null): ?string
    {
        $oldRating = $stock->getCreditRating();

        // D is a statement of fact, not of opinion: the agencies assign it when an obligor has actually
        // missed a payment or filed, and they withdraw it when the obligor is current again. It is therefore
        // written here and nowhere else — a market-implied distance to default, however bad, cannot reach it.
        if ($stock->isBankrupt() || $stock->isPaymentDefault()) {
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

        // The agencies rate on leverage against the business, or a bank on its country anchor and its capital, not on
        // the equity market: a Merton distance to default reads a low-debt, low-volatility firm as near riskless, so
        // the market-implied grade only ever lowers this.
        if ($leverageAnchor !== null) {
            $capRank = min($capRank, self::RATING_RANKS[$leverageAnchor] ?? $capRank);
        }

        // A company with negative book equity is balance sheet insolvent
        if ((float) $stock->getTotalEquity() <= 0.0) {
            $capRank = min($capRank, self::RATING_RANKS['CCC']);
        }

        // The zone, when the caller supplies it, is the firm's own model classifying its own score, and it
        // has to win over the numeric brackets. Those brackets are struck on the 1968 Z'' scale, while a
        // financial's ALTERNATIVE score is a capital ratio in percentage points: read against a grey zone
        // of 2.60, a bank three points from statutory failure looks comfortably safe and a clearinghouse
        // carrying a healthy ratio against a member margin pool looks distressed. For an operating firm the
        // two agree by construction — the standard path zones on these same two numbers.
        $isDistressed = $altmanZone !== null
            ? $altmanZone === 'Distress'
            : ($altmanZScore !== null && $altmanZScore < self::ALTMAN_DISTRESS_THRESHOLD);
        $isGrey = $altmanZone !== null
            ? $altmanZone === 'Grey'
            : ($altmanZScore !== null && $altmanZScore < self::ALTMAN_GREY_THRESHOLD);

        if ($isDistressed) {
            // Insolvent on the accounts but still paying: the lowest rating an obligor that has not
            // actually missed anything can hold. FailureSweep liquidates on this score in any case.
            $capRank = min($capRank, self::RATING_RANKS['CCC']);
        } elseif ($isGrey) {
            $capRank = min($capRank, self::RATING_RANKS['BB']);
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
            $isSevereDistress = $isDistressed || ((float) $stock->getTotalEquity() <= 0.0);

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
     * The rating S&P's business risk/financial risk matrix anchors a firm at, from its net debt to EBITDA: the corporate
     * table, or a sector's own (REAL_ESTATE_DEBT_TO_EBITDA_BOUNDS). A firm with no debt is minimal financial risk; one with
     * debt and no EBITDA is highly leveraged.
     *
     * @param list<float> $bounds Upper bounds of the financial risk profiles, minimal to aggressive.
     */
    public static function financialRiskAnchor(float $netDebt, float $ebitda, bool $excellentBusinessRisk, array $bounds = self::FINANCIAL_RISK_DEBT_TO_EBITDA_BOUNDS): string
    {
        return self::anchorFor(self::financialRiskProfile($netDebt, $ebitda, $bounds), $excellentBusinessRisk);
    }

    /**
     * Net debt and EBITDA on S&P's time-weighted basis: the current year and the two before it at CORE_RATIO_YEAR_WEIGHTS
     * and the base case for the two forecast years at CORE_RATIO_FORECAST_WEIGHT, the weights of years not yet observed
     * spread over those that are. Numerator and denominator are weighted apart (a pooled ratio), which matches S&P's
     * weighted ratio while debt is steady and stays defined through a loss year.
     *
     * @param list<array{net_debt: float, ebitda: float}> $quarters Rated quarters, newest first, each on its trailing twelve months.
     * @param array{net_debt: float, ebitda: float}|null $forecast The base case for the forecast years; none weighs the observed years alone.
     * @return array{net_debt: float, ebitda: float}
     */
    public static function timeWeightedLeverage(array $quarters, ?array $forecast = null): array
    {
        $netDebt = $forecast !== null ? self::CORE_RATIO_FORECAST_WEIGHT * $forecast['net_debt'] : 0.0;
        $ebitda = $forecast !== null ? self::CORE_RATIO_FORECAST_WEIGHT * $forecast['ebitda'] : 0.0;
        $weight = $forecast !== null ? self::CORE_RATIO_FORECAST_WEIGHT : 0.0;
        foreach (self::CORE_RATIO_YEAR_WEIGHTS as $year => $yearWeight) {
            $quarter = $quarters[$year * EarningsEngine::TTM_QUARTERS] ?? null;
            if ($quarter === null) {
                break;
            }
            $netDebt += $yearWeight * $quarter['net_debt'];
            $ebitda += $yearWeight * $quarter['ebitda'];
            $weight += $yearWeight;
        }

        return $weight > 0.0
            ? ['net_debt' => $netDebt / $weight, 'ebitda' => $ebitda / $weight]
            : ['net_debt' => 0.0, 'ebitda' => 0.0];
    }

    /** How many rated quarters timeWeightedLeverage() reads: the current one and one a year back for each earlier year. */
    public static function timeWeightedLeverageQuarters(): int
    {
        return (count(self::CORE_RATIO_YEAR_WEIGHTS) - 1) * EarningsEngine::TTM_QUARTERS + 1;
    }

    /**
     * The anchor for an asset manager that invests its own balance sheet, which S&P rates on debt to adjusted total equity
     * (reported equity less goodwill and intangibles) rather than on cash flow, at a satisfactory business risk profile.
     */
    public static function adjustedEquityLeverageAnchor(float $debt, float $adjustedTotalEquity): string
    {
        return self::anchorFor(self::financialRiskProfile($debt, $adjustedTotalEquity, self::DEBT_TO_ADJUSTED_EQUITY_BOUNDS), false);
    }

    /**
     * @param list<float> $bounds
     * @return int Financial risk profile, 0 (minimal) to count($bounds) (highly leveraged).
     */
    private static function financialRiskProfile(float $debt, float $base, array $bounds): int
    {
        if ($debt <= 0.0) {
            return 0;
        }
        if ($base <= 0.0) {
            return count($bounds);
        }
        foreach ($bounds as $i => $bound) {
            if ($debt / $base < $bound) {
                return $i;
            }
        }

        return count($bounds);
    }

    private static function anchorFor(int $profile, bool $excellentBusinessRisk): string
    {
        return ($excellentBusinessRisk ? self::EXCELLENT_BUSINESS_RISK_ANCHORS : self::SATISFACTORY_BUSINESS_RISK_ANCHORS)[$profile];
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
        // Floored at B. This ladder reads a MARKET-IMPLIED distance to default, equity price and volatility, and
        // S&P assigns CCC only to an unsustainable capital structure or a default foreseeable within a year ("Criteria
        // For Assigning 'CCC+', 'CCC', 'CCC-', And 'CC' Ratings", 2012): here the Altman distress zone or negative
        // equity, which cap the rating in evaluateRating(). A crash on its own is neither.
        return 'B';
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

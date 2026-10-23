<?php

declare(strict_types=1);

namespace App\Service\Market\Bond;

/**
 * Which of S&P's methodologies the rating agency rates a firm on: the score its distress caps read, and the table its
 * leverage anchor is read from. A business model declares its own (AbstractBusinessModel::RATING_METHODOLOGY).
 */
enum RatingMethodology
{
    /** The corporate methodology: Altman's Z'' and debt to EBITDA on the standard volatility table. */
    case Corporate;
    /**
     * A rate-regulated utility: no Z'' (Altman's samples held no regulated utility, and a thin asset turnover scores
     * one grey however safe its rate base), and an excellent business risk read on the medial volatility table.
     */
    case RegulatedUtility;
    /**
     * A REIT: its capital ratio on undepreciated real estate, as its bond covenants cap debt, and S&P's real estate
     * debt-to-EBITDA bands.
     */
    case RealEstate;
    /**
     * An asset manager ("Key Credit Factors For Asset Managers", 2014): no Z'', business and financial risk alone on
     * the corporate table.
     */
    case AssetManager;
    /** An asset manager investing its own balance sheet (S&P's hybrid model): debt to adjusted total equity. */
    case BalanceSheetInvestor;
    /** A bank, an insurer or another institution steering to a capital ratio: rated on its capital, not cash flow. */
    case CapitalRated;

    /**
     * Whether the firm's solvency is scored on its capital ratio rather than Altman's Z'': every financial and a REIT,
     * balance sheets Altman's samples did not hold.
     */
    public function scoresSolvencyOnCapital(): bool
    {
        return match ($this) {
            self::RealEstate, self::AssetManager, self::BalanceSheetInvestor, self::CapitalRated => true,
            self::Corporate, self::RegulatedUtility => false,
        };
    }

    /** Whether the agency's distress caps read a solvency score at all. */
    public function capsOnSolvencyScore(): bool
    {
        return match ($this) {
            self::Corporate, self::RealEstate, self::CapitalRated => true,
            self::RegulatedUtility, self::AssetManager, self::BalanceSheetInvestor => false,
        };
    }
}

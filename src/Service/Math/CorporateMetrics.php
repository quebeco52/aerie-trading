<?php

namespace App\Service\Math;

use App\Entity\Stock;

/**
 * Extracts domain-specific metric calculations away from the pure stochastic MathUtility.
 */
class CorporateMetrics
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getIndustryDepreciationRate(string $industry): float
    {
        return \App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? 0.05;
    }

    /**
     * How much of its serviceable addressable market a firm's capital already spans: invested capital over
     * the SAM the firm was seeded with, scaled by nominal GDP. A SCALE reading for the saturation physics
     * (Penrose bloat, diminishing marginal return, the TAM cap), private to the firm and non-rival — it is
     * not a share of the industry's sales. That figure lives in the industry ledger's revenue share.
     */
    public function calculateScaleRatio(float $investedCapital, float $nominalGdpIndex, float $samRatio, float $baselineSectorTam = FinancialConstants::BASELINE_SECTOR_TAM): float
    {
        $dynamicSam = $baselineSectorTam * $nominalGdpIndex * $samRatio;
        return $investedCapital / max(1.0, $dynamicSam);
    }

    public function calculateOperatingBase(float $revenue, float $equity, float $floor = 10000000.0): float
    {
        return max($revenue, $equity, $floor);
    }

    public function calculateLiveInvestedCapital(float $equity, float $debt, float $treasury): float
    {
        return max(1.0, max($equity * 0.50, ($equity + $debt - $treasury)));
    }

    /**
     * Calculates variable margin friction from market saturation, following the Penrose (1959) Limit to Growth
     * and Hayashi (1982) Q-Theory with Quadratic Adjustment Costs.
     *
     * As a company pushes its capital footprint ($investedCapital) beyond optimal market share ($optimalThreshold),
     * administrative bloat, coordination friction, and SG&A costs increase quadratically above threshold ($excessRatio^2).
     */
    public function calculateMarketSaturationPenalty(Stock $stock, float $investedCapital, \App\DTO\MacroStateDTO $macroState): float
    {
        $nominalGdpIndex = $macroState->nominalGdpIndex;
        $samRatio = (float) $stock->getSamRatio();
        $marketShare = $this->calculateScaleRatio($investedCapital, $nominalGdpIndex, $samRatio);

        $moatFactor = FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()]
            ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'];

        // Penrose Effect / Hayashi Quadratic Adjustment Costs: C(I) ∝ I^2
        $optimalThreshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        $excessRatio = max(0.0, ($marketShare - $optimalThreshold) / max(0.01, 1.0 - $optimalThreshold));
        $convexBloat = ($excessRatio * $excessRatio) * FinancialConstants::DISECONOMY_FRICTION_COEFF;

        $saturationPenalty = $convexBloat * $moatFactor;

        return $saturationPenalty;
    }

    /**
     * Calculates diminishing marginal return on capital above optimal market scale, following the
     * Cobb-Douglas Marginal Productivity Model: ROIC_marginal = ROIC_base * (K / K_optimal)^(-α).
     *
     * In neoclassical investment theory (Tobin's Q / Hayashi 1982), marginal productivity of capital (MP_K)
     * decays power-law asymptotically as capital accumulation (K) outpaces serviceable demand (K_optimal).
     * The elasticity parameter α is scaled by the firm's economic moat ($moatFactor) to reflect resistance to saturation.
     */
    public function calculateMarginalReturn(Stock $stock, float $trueReturn, float $saturationPenalty, float $investedCapital, \App\DTO\MacroStateDTO $macroState): float
    {
        $nominalGdpIndex = $macroState->nominalGdpIndex;
        $samRatio = (float) $stock->getSamRatio();
        $marketShare = $this->calculateScaleRatio($investedCapital, $nominalGdpIndex, $samRatio);

        $baseReturn = max(0.0, $trueReturn - $saturationPenalty - $this->calculateCournotPriceHaircut($stock, $marketShare, $investedCapital, $macroState));

        return $this->applyScaleDiseconomies($stock, $baseReturn, $marketShare);
    }

    /**
     * Cobb-Douglas diminishing marginal productivity above the firm's optimal operating scale:
     * ROIC_marginal = ROIC_base x (K / K_optimal)^(-alpha), with a moat factor softening the elasticity for
     * firms whose position genuinely defends the extra scale.
     *
     * This is the third and last component of a marginal return, after the saturation penalty and the
     * Cournot price haircut, and it is the one a firm growing WITH its market never pays: the decay is a
     * function of share, so at constant share the next unit of capital is as productive as the last. That is
     * why it belongs on the share-taking tranche alone, exactly like the Cournot haircut beside it.
     *
     * Extracted so the earnings engine's structural growth gate and the treasury's deployment gates apply
     * one law rather than two: the treasury applied the decay and the earnings gate did not, so the two were
     * composing the same marginal return from different parts. Note that the Penrose saturation penalty
     * dominates this term over the band where it first bites, so on the structural path it is a backstop
     * against the penalty being softened later rather than a brake that fires on its own today.
     */
    public function applyScaleDiseconomies(Stock $stock, float $return, float $marketShare): float
    {
        $optimalThreshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        if ($marketShare <= $optimalThreshold) {
            return max(0.0, $return);
        }

        $moatFactor = FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()]
            ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'];

        $capitalScale = $marketShare / max(0.01, $optimalThreshold);
        $effectiveElasticity = FinancialConstants::CAPITAL_MARGINAL_ELASTICITY * $moatFactor;

        return max(0.0, $return * pow($capitalScale, -$effectiveElasticity));
    }

    /**
     * What the next unit of plant costs the firm on the plant it already runs: a quantity-setting firm sells
     * every unit into one industry price, so adding capacity lowers the price on all of its output (Cournot;
     * the Lerner term s/e of the marginal-revenue factor). Per unit of capital that is the asset turnover
     * times the after-tax share of revenue lost, so the marginal return is the average return less this
     * haircut. It is the mirror image of the industry capacity balance in the earnings engine: the firm
     * internalises the price response the ledger will charge it, and stops building before the price cut
     * has eaten its margin. Financials sell a yield, not a unit, and are left alone; so is any model whose
     * output is declared non-substitutable.
     */
    public function calculateCournotPriceHaircut(Stock $stock, float $marketShare, float $investedCapital, \App\DTO\MacroStateDTO $macroState): float
    {
        $revenue = (float) $stock->getTotalRevenue();
        if ($revenue <= 0.0 || $investedCapital <= 0.0 || $marketShare <= 0.0) {
            return 0.0;
        }

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $substitutability = $strategy->getIndustrySubstitutability();
        if ($strategy->isFinancial() || $substitutability <= 0.0) {
            return 0.0;
        }

        $marginalRevenueFactor = MathUtility::getInstance()->calculateCournotMarginalRevenueFactor(
            $marketShare,
            FinancialConstants::COURNOT_DEMAND_ELASTICITY,
            $substitutability
        );
        $assetTurnover = $revenue / $investedCapital;

        return $assetTurnover * (1.0 - $macroState->corporateTaxRate) * (1.0 - $marginalRevenueFactor);
    }

    /**
     * Capitalized operating lease liability under IFRS 16 / ASC 842, approximated as a sector-specific
     * multiple of annual revenue. Debt-like for leverage and solvency; the rent itself stays in fixed costs.
     */
    public function calculateLeaseLiability(float $annualRevenue, float $leaseIntensity): float
    {
        return max(0.0, $annualRevenue) * max(0.0, $leaseIntensity);
    }

    /**
     * Writes the three trade balances implied by a set of day counts and returns the resulting net figure.
     *
     * Receivables scale with revenue (they are billed sales); inventory and payables scale with the cost
     * base (they are carried at cost, not at what the firm hopes to sell them for). Used both to seed the
     * ledger and to roll it forward, so an opening balance sheet and its first quarter are built by the
     * same arithmetic and cannot disagree by construction.
     *
     * @param array{dso?: float, dio?: float, dpo?: float} $days
     */
    public function buildWorkingCapitalBalances(Stock $stock, array $days, float $annualRevenue, float $annualCosts): float
    {
        $perDay = FinancialConstants::DAYS_PER_YEAR;

        $stock->setReceivables((string) max(0.0, max(0.0, $annualRevenue) * ($days['dso'] ?? 0.0) / $perDay));
        $stock->setInventory((string) max(0.0, max(0.0, $annualCosts) * ($days['dio'] ?? 0.0) / $perDay));
        $stock->setPayables((string) max(0.0, max(0.0, $annualCosts) * ($days['dpo'] ?? 0.0) / $perDay));

        return (float) $stock->getNetWorkingCapital();
    }

    /**
     * Sets the expected-credit-loss allowance to its target for the current default outlook (ASC 326), so a
     * ledger opens the way a real one would: with the loss the firm already expects on its receivables
     * provided for, rather than discovered and charged in the first quarter.
     */
    public function seedReceivablesAllowance(Stock $stock, float $corporateDefaultRate): void
    {
        $receivables = (float) ($stock->getReceivables() ?? 0.0);
        $lossRate = max(0.0, min(1.0, $corporateDefaultRate)) * FinancialConstants::TRADE_RECEIVABLE_LGD;

        $stock->setReceivablesAllowance((string) max(0.0, $receivables * $lossRate));
    }

    /**
     * Seeds the fixed-asset ledger for a firm that has never reported, so depreciation has a real asset
     * account to run against from the first quarter.
     *
     * Net PP&E is invested capital less the other things invested capital is made of (working capital,
     * goodwill and construction in progress), which is the accounting identity read backwards. Working
     * capital keeps its sign: a business funded by its suppliers (negative working capital) carries MORE
     * plant than its invested capital, because part of the plant is paid for with trade credit that
     * invested capital nets out. Clamping it to zero left that plant off the books and the sheet out of
     * balance by exactly the payables float. The floor is a bare viability minimum, not a target: a firm
     * whose capital is all trade cycle has almost no plant and, correctly, almost no depreciation.
     * Gross cost is then grossed up by the assumed
     * age of the plant, because a firm mid-life carries assets whose historical cost exceeds their book
     * value. Seeding gross and accumulated separately (rather than starting a brand-new plant) matters:
     * a zero-age base would under-depreciate for years and overstate early free cash flow.
     */
    public function seedFixedAssetLedger(
        Stock $stock,
        float $investedCapital,
        float $netWorkingCapital,
        float $goodwill,
        float $constructionInProgress,
        float $assetAgeRatio = FinancialConstants::SEED_ASSET_AGE_RATIO
    ): void {
        $capital = abs($investedCapital);
        $netPpe = max(
            $capital * FinancialConstants::MIN_PPE_SHARE_OF_CAPITAL,
            $capital - $netWorkingCapital - max(0.0, $goodwill) - max(0.0, $constructionInProgress)
        );

        $age = min(0.90, max(0.0, $assetAgeRatio));
        $grossPpe = $netPpe / (1.0 - $age);

        $stock->setGrossPpe((string) $grossPpe);
        $stock->setAccumulatedDepreciation((string) ($grossPpe - $netPpe));
    }

    /**
     * Seeds the earning-asset ledger for a balance-sheet business that has never reported.
     *
     * Everything the institution funds and does not hold as cash is deployed in loans and securities, so the
     * net book is equity plus all funding less idle cash less any goodwill an acquisition left behind: the
     * accounting identity read backwards, exactly as the plant ledger is seeded. Gross is that net book
     * grossed up by the lifetime loss already expected on it, so the allowance opens at its target and the
     * first report does not book a phantom provision to build one (the same lesson the receivables
     * allowance taught). A fixture whose cash exceeds its funding cannot balance; its book is empty.
     */
    public function seedEarningAssetLedger(Stock $stock, float $lifetimeLossRate): void
    {
        $netBook = max(
            0.0,
            (float) $stock->getTotalEquity()
            + (float) $stock->getTotalDebt()
            - max(0.0, (float) $stock->getCorporateTreasury())
            - max(0.0, (float) $stock->getGoodwill())
        );
        $rate = max(0.0, min(0.50, $lifetimeLossRate));
        $grossBook = $netBook / (1.0 - $rate);

        $stock->setEarningAssets((string) $grossBook);
        $stock->setCreditLossAllowance((string) ($grossBook - $netBook));
    }

    public function calculateInterestCoverageRatio(float $ebit, float $interestExpense): float
    {
        if ($interestExpense <= 0.0) {
            return 999.0;
        }
        return $ebit / $interestExpense;
    }

    /**
     * Calculates the severity of market saturation [0.0 to 1.0] by comparing the saturation penalty
     * to the theoretical baseline economic return.
     *
     * In corporate life-cycle theory (DeAngelo, DeAngelo & Stulz 2006; Jensen 1986), as saturation penalty
     * approaches or exceeds the baseline economic return, internal growth opportunities vanish,
     * signaling a structural transition from growth capital retention to mature cash-cow distribution.
     */
    public function calculateSaturationSeverity(float $saturationPenalty, float $trueReturn): float
    {
        if ($saturationPenalty <= 0.0) {
            return 0.0;
        }
        $baselineReturn = max(0.01, $trueReturn + $saturationPenalty);
        return min(1.0, max(0.0, $saturationPenalty / $baselineReturn));
    }

    /**
     * Calculates the effective life-cycle target dividend payout ratio according to the
     * DeAngelo-DeAngelo (2006) Life-Cycle Theory of Dividends.
     *
     * As market saturation severity increases (diminishing marginal returns on reinvestment),
     * the optimal retention ratio collapses and the target payout ratio dynamically expands
     * from baseline towards the mature cash-cow ceiling (LIFE_CYCLE_MAX_PAYOUT_RATIO).
     */
    public function calculateLifeCyclePayoutRatio(float $baselinePayoutRatio, float $saturationSeverity): float
    {
        $maxPayout = FinancialConstants::LIFE_CYCLE_MAX_PAYOUT_RATIO;
        return min($maxPayout, max($baselinePayoutRatio, $baselinePayoutRatio + (($maxPayout - $baselinePayoutRatio) * $saturationSeverity)));
    }
}

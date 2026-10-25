<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\TimeSeries;

/**
 * A firm's measures read off its Stock: operating base, invested and revenue-generating capital, scale and saturation
 * penalties, the Cournot price haircut, lease liability, interest coverage, the life-cycle payout, and the opening
 * working-capital and asset ledgers.
 */
class CorporateMetrics
{
    // --- Market Saturation & Bureaucratic Bloat ---
    /** Baseline total addressable market size ($1T) for standard corporate sectors. */
    public const BASELINE_SECTOR_TAM = 1_000_000_000_000.00;
    /** Penrose bureaucratic friction coefficient penalizing margins at extreme scale. */
    public const DISECONOMY_FRICTION_COEFF = 0.20;

    // --- Capital Allocation & Life-Cycle Physics ---
    /** Maximum effective dividend payout ratio (85%) for fully saturated mature cash cows. */
    public const LIFE_CYCLE_MAX_PAYOUT_RATIO = 0.85;

    // --- CapEx & Construction in Progress (CIP) ---
    /**
     * Maximum fraction of physical/invested capital that can be deferred as unplaced Construction in Progress (25%).
     */
    public const MAX_CIP_CAPITAL_DEDUCTION_RATIO = 0.25;

    // --- Fixed Asset Ledger (PP&E) ---
    /**
     * Viability floor on net PP&E as a share of invested capital. Kept minimal on purpose: a distributor's or staffing
     * firm's capital genuinely IS its working capital, and a larger floor would invent plant the balance sheet cannot
     * fund.
     */
    public const MIN_PPE_SHARE_OF_CAPITAL = 0.02;

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getIndustryDepreciationRate(string $industry): float
    {
        return \App\Data\Company\Sectors::metricsFor($industry)['depreciation'];
    }

    /**
     * How much of its serviceable addressable market a firm's capital already spans: invested capital over
     * the SAM the firm was seeded with, scaled by nominal GDP. A SCALE reading for the saturation physics
     * (Penrose bloat, diminishing marginal return, the TAM cap), private to the firm and non-rival — it is
     * not a share of the industry's sales. That figure lives in the industry ledger's revenue share.
     */
    public function calculateScaleRatio(float $investedCapital, float $nominalGdpIndex, float $samRatio, float $secularMarketGrowth = 1.0): float
    {
        $dynamicSam = self::BASELINE_SECTOR_TAM * $nominalGdpIndex * $samRatio * $secularMarketGrowth;
        return $investedCapital / max(1.0, $dynamicSam);
    }

    /**
     * The firm's scale ratio on its own market: the seeded SAM grown with nominal GDP and with its sector's secular
     * drift over the economy, faded as the industry ledger fades it, so saturation and the ledger's capacity balance
     * read the same market.
     */
    public function calculateAddressableShare(Stock $stock, float $capital, \App\DTO\MacroStateDTO $macroState): float
    {
        return $this->calculateScaleRatio($capital, $macroState->nominalGdpIndex, (float) $stock->getSamRatio(), self::secularMarketGrowth($stock, $macroState->totalTime));
    }

    /**
     * How far the firm's sector has grown past (or fallen behind) the economy since the open: exp of its faded secular
     * excess, times the industry outlook the firm last filed (IndustryOutlook), as the ledger's trend market grows.
     */
    public static function secularMarketGrowth(Stock $stock, float $totalTime): float
    {
        $excess = IndustryShareLedger::secularExcessGrowth(\App\Data\Company\Sectors::strategyFor($stock->getIndustry()), $stock);
        $outlook = (float) (($stock->getEarningsMomentumZ() ?? [])[Industry\IndustryOutlook::STATE_BELIEF] ?? 0.0);

        return exp(TimeSeries::fadedExcessIntegral($excess, 0.0, $totalTime, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS) + $outlook);
    }

    public function calculateOperatingBase(float $revenue, float $equity, float $floor = 10000000.0): float
    {
        return max($revenue, $equity, $floor);
    }

    /**
     * Treasury the business does not need: cash over the model's own target on its operating base, without a manager's
     * taste for more. None for a financial, whose cash is its balance sheet. Valued at face wherever the firm is
     * valued.
     */
    public static function excessOperatingCash(Stock $stock): float
    {
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        if ($strategy->isFinancial()) {
            return 0.0;
        }

        return max(0.0, (float) $stock->getCorporateTreasury() - $strategy->calculateTargetOperatingCash(
            self::getInstance()->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity()),
            (float) $stock->getCustomerDeposits(),
            (float) $stock->getWholesaleDebt(),
        ));
    }

    public function calculateLiveInvestedCapital(float $equity, float $debt, float $treasury): float
    {
        return max(1.0, max($equity * 0.50, ($equity + $debt - $treasury)));
    }

    /**
     * The capital that produces revenue: invested capital less plant not yet in service and less goodwill.
     * Goodwill is the price paid over the identifiable assets (ASC 805) and runs no plant, so impairing it
     * leaves capacity where it was. Construction is deducted only up to its capped share of capital.
     */
    public static function revenueGeneratingCapital(float $investedCapital, float $constructionInProgress, float $goodwill): float
    {
        $capital = abs($investedCapital);
        $cipDeduction = min($capital * self::MAX_CIP_CAPITAL_DEDUCTION_RATIO, max(0.0, $constructionInProgress));

        return max(0.0, $capital - $cipDeduction - max(0.0, $goodwill));
    }

    /**
     * Calculates variable margin friction from market saturation, following the Penrose (1959) Limit to Growth
     * and Hayashi (1982) Q-Theory with Quadratic Adjustment Costs.
     *
     * As a company pushes its capital footprint ($investedCapital) beyond optimal market share ($optimalThreshold),
     * administrative bloat, coordination friction, and SG&A costs increase quadratically above threshold
     * ($excessRatio^2).
     */
    public function calculateMarketSaturationPenalty(Stock $stock, float $investedCapital, \App\DTO\MacroStateDTO $macroState): float
    {
        $marketShare = $this->calculateAddressableShare($stock, $investedCapital, $macroState);

        $moatFactor = FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()]
            ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'];

        // Penrose Effect / Hayashi Quadratic Adjustment Costs: C(I) ∝ I^2
        $optimalThreshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        $excessRatio = max(0.0, ($marketShare - $optimalThreshold) / max(0.01, 1.0 - $optimalThreshold));
        $convexBloat = ($excessRatio * $excessRatio) * self::DISECONOMY_FRICTION_COEFF;

        $saturationPenalty = $convexBloat * $moatFactor;

        return $saturationPenalty;
    }

    /**
     * The return on the next unit of capital: the firm's return state, which already carries the saturation penalty
     * on its whole base, less what that unit raises the penalty on the base (the derivative of E x p(E): Penrose 1959,
     * Hayashi 1982) and less the Cournot haircut on the output it adds.
     */
    public function calculateMarginalReturn(Stock $stock, float $trueReturn, float $investedCapital, \App\DTO\MacroStateDTO $macroState): float
    {
        $marketShare = $this->calculateAddressableShare($stock, $investedCapital, $macroState);

        return max(0.0, $trueReturn - $this->calculateMarginalSaturationDrag($stock, $marketShare) - $this->calculateCournotPriceHaircut($stock, $marketShare, $investedCapital, $macroState));
    }

    /**
     * What one more unit of capital adds to the saturation penalty on the capital already in place: share x dp/dshare
     * of the quadratic penalty, nothing below the optimal share. A firm growing with its market keeps its share and
     * never pays it.
     */
    public function calculateMarginalSaturationDrag(Stock $stock, float $marketShare): float
    {
        $optimalThreshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        if ($marketShare <= $optimalThreshold) {
            return 0.0;
        }

        $moatFactor = FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()]
            ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'];
        $span = max(0.01, 1.0 - $optimalThreshold);

        return 2.0 * self::DISECONOMY_FRICTION_COEFF * $moatFactor * $marketShare * ($marketShare - $optimalThreshold) / ($span * $span);
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

        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        $substitutability = $strategy->getIndustrySubstitutability();
        if ($strategy->isFinancial() || $substitutability <= 0.0) {
            return 0.0;
        }

        $marginalRevenueFactor = FirmEconomics::calculateCournotMarginalRevenueFactor(
            $marketShare,
            FinancialConstants::COURNOT_DEMAND_ELASTICITY,
            $substitutability,
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
     * The lease liability the firm carries: its ledger once its first report has struck one, the revenue multiple
     * before. The ledger follows contracted payments (ASC 842-20-30-1), not the quarter's sales, so a slump that cuts
     * revenue leaves the rent and the debt-like obligation behind it standing.
     */
    public function resolveLeaseLiability(Stock $stock, float $annualRevenue, float $leaseIntensity): float
    {
        $carried = $stock->getLeaseLiability();

        return $carried !== null ? max(0.0, $carried) : $this->calculateLeaseLiability($annualRevenue, $leaseIntensity);
    }

    /**
     * The lease ledger rolled one step (ASC 842): leases are signed for the capacity the firm runs, so a firm that has
     * grown its plant carries the obligation at once, and the balance runs off only as leases expire, a share dt/term a
     * year, when the capacity behind them is no longer needed.
     */
    public static function rollLeaseLiability(?float $openingLiability, float $capacityRevenue, float $leaseIntensity, float $dt): float
    {
        $required = max(0.0, $capacityRevenue) * max(0.0, $leaseIntensity);
        if ($openingLiability === null || $required >= $openingLiability) {
            return $required;
        }

        return max($required, $openingLiability * (1.0 - min(1.0, $dt / FinancialConstants::LEASE_TERM_YEARS)));
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
        float $assetAgeRatio = FinancialConstants::SEED_ASSET_AGE_RATIO,
    ): void {
        $capital = abs($investedCapital);
        $netPpe = max(
            $capital * self::MIN_PPE_SHARE_OF_CAPITAL,
            $capital - $netWorkingCapital - max(0.0, $goodwill) - max(0.0, $constructionInProgress),
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
            - max(0.0, (float) $stock->getGoodwill()),
        );
        $rate = max(0.0, min(0.50, $lifetimeLossRate));
        $grossBook = $netBook / (1.0 - $rate);

        $stock->setEarningAssets((string) $grossBook);
        $stock->setCreditLossAllowance((string) ($grossBook - $netBook));
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
        $maxPayout = self::LIFE_CYCLE_MAX_PAYOUT_RATIO;
        return min($maxPayout, max($baselinePayoutRatio, $baselinePayoutRatio + (($maxPayout - $baselinePayoutRatio) * $saturationSeverity)));
    }
}

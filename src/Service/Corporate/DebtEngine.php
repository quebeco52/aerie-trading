<?php

namespace App\Service\Corporate;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\MarketEventPublisher;
use App\Service\Macro\MacroEngine;
use App\Service\Market\Bond\CreditRatingAgency;
use App\Service\Math\CreditRisk;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\Valuation;

class DebtEngine
{
    // --- Debt Analysis ---
    /** 300 bps spread is severe threshold for arbitrage hurdle. */
    private const ARBITRAGE_HURDLE = 0.030;

    // --- Leverage Physics ---
    /** Cap extreme D/E or D/EBITDA ratios. */
    private const MAX_LEVERAGE_RATIO = 15.0;

    // --- Issuer Credit Spread: Equity Volatility Premium ---
    /** Equity volatility below which bondholders charge no idiosyncratic premium (20%, about the VIX's long-run mean of 19.5); a firm whose own configured volatility is higher is charged only above that, since its rating class already prices its normal risk. */
    private const VOLATILITY_PREMIUM_THRESHOLD = 0.20;
    /** Spread per unit of equity volatility above that threshold, 2 bps per point: Campbell & Taksler (2003) firm volatility channel, magnitude not re-pinned. */
    private const VOLATILITY_PREMIUM_SENSITIVITY = 0.02;
    /** Loss given default the class curves are quoted at: the ICE BofA indices are senior unsecured notes, recovering 48% (CorporateDefaultService; Moody's ultimate recoveries 1987-2021, 47.4%). */
    private const CLASS_CURVE_LOSS_GIVEN_DEFAULT = 1.0 - \App\Service\Market\Bond\CorporateDefaultService::RECOVERY_SENIOR_UNSECURED;
    /** Floor on a firm's baseline spread (15 bps), so a tight credit market never prices a cash-rich issuer's debt through the riskless curve. */
    private const MIN_BASELINE_CREDIT_SPREAD = 0.0015;

    // --- Leverage Covenant (Net Debt / EBITDA) ---
    /** Sector limits at or above this are a no-test sentinel: financials are bound by regulatory capital, not cash-flow leverage. */
    public const EBITDA_COVENANT_EXEMPT_LIMIT = 999.0;
    /** Fallback Net Debt / EBITDA covenant for an industry carrying no calibrated limit. */
    public const DEFAULT_EBITDA_COVENANT_LIMIT = 3.0;

    // --- CAPM / Beta Limits ---
    /** Prevent runaway WACC in standard CAPM by capping debt to equity ratio. */
    private const MAX_BETA_DEBT_TO_EQUITY = 2.5;

    // --- Refinancing Hurdles ---
    /** 150 bps drop triggers early refinancing. */
    private const RATE_REFINANCE_THRESHOLD = 0.015;
    /** 15% of debt retired per quarter if early refinancing is triggered. */
    private const ACCELERATED_DEBT_TURNOVER = 0.15;

    // --- Merton Default Horizon ---
    /** Horizon the structural default model is struck on; 5 years is the standard tenor for corporate credit spreads. */
    private const MERTON_HORIZON_YEARS = 5.0;
    /** Floor on the asset volatility the Merton model is struck at; below it a distance to default stops meaning anything. */
    private const MIN_ASSET_VOLATILITY = 0.02;

    // --- Maturity Wall & Primary Market Access ---
    /** Spread of the credit index an issuer's class trades off at which its primary market shuts: the HY index reached it in 2008 and 2020; the IG index never does, so an investment-grade issuer refinances through any recession. */
    private const PRIMARY_MARKET_CLOSURE_SPREAD = 0.10;
    /** Lowest credit rating that can still refinance a maturity at any price. */
    public const REFINANCING_RATING_FLOOR = 'CCC';
    /** Interest coverage below which lenders will not roll a maturity: the firm cannot service what it already owes. */
    private const REFINANCING_MIN_COVERAGE = 1.0;

    // --- Through-the-Cycle Leverage ---
    /** Key on the newest operating-history quarter holding the net debt and EBITDA each rating review read, newest first (CreditRatingAgency::timeWeightedLeverage()). */
    public const RATED_LEVERAGE_KEY = 'rated_leverage';

    // --- Altman Z'' (Altman 1995) ---
    /** Equity over total liabilities for a firm that owes nothing: the 10x cap Altman applies to the ratio, a firm with no claims ahead of its equity. */
    private const ALTMAN_X4_UNLEVERED_CAP = 10.0;

    // --- Committed Revolving Credit Facility ---
    /** Drawn-revolver spread (+100 bps) over the issuer's market rate; a pre-negotiated facility prices inside emergency paper. */
    public const REVOLVER_DRAW_SPREAD_PENALTY = 0.01;

    public function __construct(
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        private ?CreditRatingAgency $creditRatingAgency = null,
        private ?MarketEventPublisher $marketEventPublisher = null
    ) {}

    /**
     * Calculates the gross and blended interest expenses for a company's debt structure.
     *
     * The debt is priced at the firm's market spread (resolveCreditSpread()), on the rating the agency assigns
     * here when the maturity wall advances.
     *
     * @param Stock $stock           The stock entity being analyzed.
     * @param MacroStateDTO $macroState      The current macroeconomic state.
     * @param bool  $advanceMaturity Whether to advance the maturity wall and lock in new blended rates.
     * @return \App\DTO\DebtMetricsDTO
     */
    public function calculateInterestExpense(Stock $stock, \App\DTO\MacroStateDTO $macroState, bool $advanceMaturity = false, ?float $overrideRevenue = null, ?float $overrideMargin = null): \App\DTO\DebtMetricsDTO
    {
        $debt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState->policyRateEma;
        $yield5y = $macroState->yield5yEma;

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Company\Sectors::businessModelFor($industry);
        $strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($businessModel);

        $revenue = $overrideRevenue ?? (float) $stock->getTotalRevenue();

        if ($revenue <= 0.0) {
            $strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($businessModel);

            $targetMetrics = $strategy->getTargetMetrics($stock, $macroState, $this->mathUtility);
            $investedCapital = $targetMetrics['invested_capital'];
            $baselineRoic = max(0.01, (float) $targetMetrics['baseline_roic']);
            $marginFallback = max(0.01, (float) $stock->getOperatingMargin());
            $assetTurnover = $baselineRoic / $marginFallback;
            $revenue = $investedCapital * $assetTurnover;
        }

        $margin = $overrideMargin ?? (float) $stock->getOperatingMargin();
        $ebit = $revenue * $margin;

        // Calculate Depreciation to find true Cash Flow (EBITDA)
        $depreciationRate = $this->resolveDepreciationRate($stock);

        // Depreciation runs on the same base the earnings engine charges it against (net PP&E for physical
        // businesses, the capital proxy for financial ones), so coverage and EBITDA here agree with the
        // income statement instead of depreciating goodwill and working capital.
        $depreciation = max(0.0, $strategy->getDepreciableBase($stock)) * $depreciationRate;
        $ebitda = $ebit + $depreciation;

        if ($debt <= 0.0) {
            if ($this->creditRatingAgency !== null && $advanceMaturity) {
                $oldRating = $stock->getCreditRating();
                $zScoreData = $this->resolveRatingZScore($stock, $strategy, $ebit, $revenue);
                $anchor = self::leverageAnchor($stock, $strategy, 0.0, $ebitda, $this->recordRatedLeverage($stock, 0.0, $ebitda), self::baseCaseLeverage($stock, 0.0, $revenue, $depreciation));
                $newRating = $this->creditRatingAgency->evaluateRating($stock, 10.0, $zScoreData['z_score'], $zScoreData['zone'], $anchor);
                if ($newRating !== null && $this->marketEventPublisher !== null) {
                    $isDowngrade = $this->creditRatingAgency->isDowngrade($oldRating, $newRating);
                    $eventType = $isDowngrade ? 'CREDIT_DOWNGRADE' : 'CREDIT_UPGRADE';
                    $desc = $isDowngrade
                        ? sprintf('[CREDIT DOWNGRADE] %s: Credit rating downgraded from %s to %s due to deteriorating fundamental solvency.', $stock->getTicker(), $oldRating, $newRating)
                        : sprintf('[CREDIT UPGRADE] %s: Credit rating upgraded from %s to %s following balance sheet strengthening.', $stock->getTicker(), $oldRating, $newRating);
                    $changePct = $isDowngrade ? -3.0 : 2.0;
                    $this->marketEventPublisher->publish($stock, $eventType, $desc, $changePct);
                }
            }

            $creditSpread = $this->resolveCreditSpread($stock, $macroState);

            return new \App\DTO\DebtMetricsDTO(
                0.0,
                0.0,
                (float) $stock->getHistoricalFixedRate(),
                $creditSpread,
                $yield5y + $creditSpread,
                $yield5y + $creditSpread,
                $ebit,
                $revenue,
                $depreciation,
                $ebitda,
                $this->resolveCreditSpread($stock, $macroState, null, true),
                self::resolveMarketIndexSpread($stock->getCreditRating(), $macroState)
            );
        }

        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $netDebt = max(0.0, $strategy->getNetDebtCapital($debt, $wholesaleDebt, $treasury));
        $totalEquity = (float) $stock->getTotalEquity();
        $equityLimit = \App\Data\Company\Sectors::metricsFor($industry)['equity_limit'];

        $distanceToDefault = $this->resolveDistanceToDefault($stock, $macroState);

        if ($this->creditRatingAgency !== null && $advanceMaturity) {
            $oldRating = $stock->getCreditRating();
            $zScoreData = $this->resolveRatingZScore($stock, $strategy, $ebit, $revenue);
            $anchor = self::leverageAnchor($stock, $strategy, $netDebt, $ebitda, $this->recordRatedLeverage($stock, $netDebt, $ebitda), self::baseCaseLeverage($stock, $netDebt, $revenue, $depreciation));
            $newRating = $this->creditRatingAgency->evaluateRating($stock, $distanceToDefault, $zScoreData['z_score'], $zScoreData['zone'], $anchor);
            if ($newRating !== null && $this->marketEventPublisher !== null) {
                $isDowngrade = $this->creditRatingAgency->isDowngrade($oldRating, $newRating);
                $eventType = $isDowngrade ? 'CREDIT_DOWNGRADE' : 'CREDIT_UPGRADE';
                $desc = $isDowngrade
                    ? sprintf('[CREDIT DOWNGRADE] %s: Credit rating downgraded from %s to %s due to deteriorating credit profile.', $stock->getTicker(), $oldRating, $newRating)
                    : sprintf('[CREDIT UPGRADE] %s: Credit rating upgraded from %s to %s following balance sheet strengthening.', $stock->getTicker(), $oldRating, $newRating);
                $changePct = $isDowngrade ? -3.0 : 2.0;
                $this->marketEventPublisher->publish($stock, $eventType, $desc, $changePct);
            }
        }

        // Priced after the agency has had its say, on the rating it has just assigned.
        $dynamicSpread = $this->resolveCreditSpread($stock, $macroState);

        // Fixed-rate corporate debt is priced off the 5-Year Yield curve, not the overnight Policy Rate
        $currentMarketFixedRate = $yield5y + $dynamicSpread;

        $historicalRate = (float) $stock->getHistoricalFixedRate();

        if ($advanceMaturity) {
            // Maturity wall: the business model sets how fast the fixed-rate book rolls to market. Refinancing ahead
            // of maturity when rates fall pays only for a speculative-grade issuer, whose bonds turn callable at a
            // fixed premium after their non-call period; investment-grade bonds carry make-whole calls, which price
            // the call at the Treasury curve plus a small spread, so retiring them early saves nothing (Powers &
            // Tsyplakov 2008). Letting every issuer refinance down gave coupons a free option on falling rates.
            $turnover = $strategy->getDebtMaturityRolloverRate();
            $isSpeculativeGrade = (\App\Service\Market\Bond\CreditRatingAgency::RATING_RANKS[(string) $stock->getCreditRating()] ?? \App\Service\Market\Bond\CreditRatingAgency::RATING_RANKS['BBB'])
                < \App\Service\Market\Bond\CreditRatingAgency::RATING_RANKS['BBB'];
            if ($isSpeculativeGrade && $currentMarketFixedRate < ($historicalRate - self::RATE_REFINANCE_THRESHOLD)) {
                $turnover = self::ACCELERATED_DEBT_TURNOVER;
            }
            $blendedFixedRate = ($historicalRate * (1.0 - $turnover)) + ($currentMarketFixedRate * $turnover);
        } else {
            $blendedFixedRate = $historicalRate;
        }

        $floatingInterestRate = $policyRate + $dynamicSpread;

        // Customer Deposits & Leverage Physics
        $strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($businessModel);
        // The strategies are handed the FUNDED book only — term debt and deposits — because the revolver is
        // priced here instead. They do not agree on where they read the balance from: the corporate and the
        // base financial physics charge whatever $debt they are given, while the bank, credit, clearing and
        // insurance models re-read the entity themselves. A revolver leg pushed down into them would be
        // charged twice by the first group and not at all by the second.
        $revolverDrawn = max(0.0, (float) $stock->getRevolverDrawn());
        $fundedDebt = max(0.0, $debt - $revolverDrawn);
        $expenseMetrics = $strategy->calculateInterestExpenseAndWholesaleRate($stock, $blendedFixedRate, $floatingInterestRate, $currentMarketFixedRate, $policyRate, $equityLimit, $totalEquity, $fundedDebt, $macroState);
        $interestExpense = $expenseMetrics->interestExpense;
        $wholesaleRate = $expenseMetrics->wholesaleRate;

        // A revolving credit facility is floating by construction — a reference rate plus a contracted
        // margin — so it is never part of the blended fixed coupon the term book carries.
        if ($revolverDrawn > 0.0) {
            $interestExpense += $revolverDrawn * ($floatingInterestRate + self::REVOLVER_DRAW_SPREAD_PENALTY);
        }

        $trueBlendedRate = $debt > 1.0 ? ($interestExpense / $debt) : 0.0;

        return new \App\DTO\DebtMetricsDTO(
            $interestExpense,
            $trueBlendedRate,
            $blendedFixedRate,
            $dynamicSpread,
            $currentMarketFixedRate,
            $wholesaleRate,
            $ebit,
            $revenue,
            $depreciation,
            $ebitda,
            $this->resolveCreditSpread($stock, $macroState, null, true),
            self::resolveMarketIndexSpread($stock->getCreditRating(), $macroState)
        );
    }

    /** The credit index a rating class trades off: IG for an investment-grade rating, HY below it. */
    private static function resolveMarketIndexSpread(string $rating, \App\DTO\MacroStateDTO $macroState): float
    {
        $ranks = CreditRatingAgency::RATING_RANKS;

        return ($ranks[$rating] ?? $ranks['BBB']) >= $ranks['BBB'] ? $macroState->macroCreditSpreadEma : $macroState->highYieldCreditSpreadEma;
    }

    /**
     * The spread the firm borrows at over the sovereign curve, and its listed bonds trade at.
     *
     * Its rating class's spread today (CreditRisk::calculateRatingClassSpread: Jarrow, Lando & Turnbull 1997 rating
     * pricing, each class proportional to the market's IG or HY index, Ben Dor et al. 2007), its default part restated
     * at the recovery the firm's own debt carries (calculateRecoveryAdjustedSpread), times the firm's own
     * multiple of its class, read off the spread it was seeded at (calculateIssuerSpreadMultiple), plus the premium
     * bondholders charge when the firm's equity volatility runs above its norm (Campbell & Taksler 2003). The common credit cycle
     * arrives through the indices; leverage and default risk through the rating.
     *
     * @param string|null $rating          Rating to price at; the firm's own when null (a pro-forma one for a deal).
     * @param bool        $throughTheCycle The spread with the indices at their measured trend and no volatility premium.
     */
    public function resolveCreditSpread(Stock $stock, \App\DTO\MacroStateDTO $macroState, ?string $rating = null, bool $throughTheCycle = false): float
    {
        $igSpread = $macroState->macroCreditSpreadEma;
        $hySpread = $macroState->highYieldCreditSpreadEma;
        if ($throughTheCycle && $igSpread > 0.0) {
            $cycle = $macroState->macroCreditSpreadTrend / $igSpread;
            $igSpread *= $cycle;
            $hySpread *= $cycle;
        }

        $lossGivenDefault = \App\Data\Company\Sectors::strategyFor($stock->getIndustry())->getLossGivenDefault();
        $issuerMultiple = CreditRisk::calculateIssuerSpreadMultiple(
            (float) $stock->getCreditSpread(),
            MacroEngine::BASE_CREDIT_SPREAD,
            MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
            $lossGivenDefault,
            self::CLASS_CURVE_LOSS_GIVEN_DEFAULT
        );
        $classSpread = CreditRisk::calculateRatingClassSpread($rating ?? $stock->getCreditRating(), $igSpread, $hySpread);
        $spread = CreditRisk::calculateRecoveryAdjustedSpread($classSpread, $lossGivenDefault, self::CLASS_CURVE_LOSS_GIVEN_DEFAULT) * $issuerMultiple;

        if (!$throughTheCycle) {
            $structuralVolatility = (float) $stock->getVolatility();
            $volatility = (float) $stock->getCurrentVolatility() ?: $structuralVolatility;
            $spread += max(0.0, ($volatility - max(self::VOLATILITY_PREMIUM_THRESHOLD, $structuralVolatility)) * self::VOLATILITY_PREMIUM_SENSITIVITY);
        }

        return max(self::MIN_BASELINE_CREDIT_SPREAD, $spread);
    }

    /**
     * Rates a firm as the agency would on its opening balance sheet, before it has a rating history: the evaluation is
     * repeated until it settles, as the one-notch clamp would bring it there over successive reviews.
     *
     * @return bool Whether the rating moved.
     */
    public function seedCreditRating(Stock $stock, \App\DTO\MacroStateDTO $macroState): bool
    {
        if ($this->creditRatingAgency === null) {
            return false;
        }

        $opening = $stock->getCreditRating();
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        $revenue = (float) $stock->getTotalRevenue();
        $ebit = $revenue * (float) $stock->getOperatingMargin();
        $ebitda = $ebit + max(0.0, $strategy->getDepreciableBase($stock)) * $this->resolveDepreciationRate($stock);
        $debt = (float) $stock->getTotalDebt();
        $netDebt = max(0.0, $strategy->getNetDebtCapital($debt, (float) $stock->getWholesaleDebt(), (float) $stock->getCorporateTreasury()));
        $distanceToDefault = $debt > 0.0 ? $this->resolveDistanceToDefault($stock, $macroState) : 10.0;
        $zScoreData = $this->resolveRatingZScore($stock, $strategy, $ebit, $revenue);
        $anchor = self::leverageAnchor($stock, $strategy, $netDebt, $ebitda);

        for ($review = 0; $review < count(CreditRatingAgency::RATING_RANKS); $review++) {
            if ($this->creditRatingAgency->evaluateRating($stock, $distanceToDefault, $zScoreData['z_score'], $zScoreData['zone'], $anchor) === null) {
                break;
            }
        }

        return $stock->getCreditRating() !== $opening;
    }

    /**
     * The rating a debt-funded deal carries on the pro-forma balance sheet, as an agency rates an acquisition financing:
     * the worse of the issuer's current rating, the grade its pro-forma distance to default reads, and the anchor its
     * pro-forma leverage sets. Borrowing never upgrades the issuer.
     */
    public function resolveProFormaRating(Stock $stock, float $proFormaDistanceToDefault, float $proFormaNetDebt, float $proFormaEbitda): string
    {
        $current = $stock->getCreditRating();
        if ($this->creditRatingAgency === null) {
            return $current;
        }

        $ranks = CreditRatingAgency::RATING_RANKS;
        $rank = min($ranks[$current] ?? $ranks['BBB'], $ranks[$this->creditRatingAgency->convertDistanceToRating($proFormaDistanceToDefault, $current)]);
        $anchor = self::leverageAnchor($stock, \App\Data\Company\Sectors::strategyFor($stock->getIndustry()), $proFormaNetDebt, $proFormaEbitda);
        $rank = min($rank, $ranks[$anchor] ?? $rank);

        return array_flip($ranks)[$rank];
    }

    /**
     * Merton's distance to default (d2) over the credit horizon: firm value is market equity plus the debt
     * obligations the model is judged on, at the firm's de-levered asset volatility. The rating agency grades
     * on it and the stock page quotes it, so both read this one calculation.
     */
    public function resolveDistanceToDefault(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        $totalDebtObligations = max(0.01, $strategy->getDeleveragingEvaluationDebt((float) $stock->getTotalDebt(), (float) $stock->getWholesaleDebt()));
        $marketCap = max(1.0, (float) $stock->getPrice() * max(1.0, (float) $stock->getSharesOutstanding()));
        $policyRate = $macroState->policyRateEma;

        return CreditRisk::calculateDistanceToDefault(
            $marketCap + $totalDebtObligations,
            $totalDebtObligations,
            $this->resolveAssetVolatility($stock, $marketCap, $totalDebtObligations, $policyRate),
            $policyRate,
            self::MERTON_HORIZON_YEARS
        );
    }

    /**
     * Volatility of the firm's assets for the Merton model (sigma_V).
     *
     * An operating firm's asset volatility is the risk of its business, and borrowing does not change it:
     * leverage raises the EQUITY's volatility instead, sigma_E = N(d1) sigma_V V / E. This simulation holds
     * each name's equity volatility at its configured figure whatever the firm borrows, so de-levering that
     * figure at the CURRENT capital structure, sigma_E E / V, made the assets look safer with every dollar of
     * debt and the distance to default climbed again past D/V of about 0.7. sigma_V is therefore solved once,
     * at the capital structure the equity volatility was configured at, and afterwards moves only with the
     * equity volatility's own departure from that figure (an earnings shock, a volatile market).
     *
     * A financial keeps the de-levered identity: the wholesale book it grows is matched against low-risk
     * assets, so its asset volatility does fall as that book grows.
     *
     * @param float $equityValue  Market value of equity (E).
     * @param float $debtValue    Debt the default barrier is struck on (D).
     * @param float $riskFreeRate Rate the Merton model discounts at.
     */
    public function resolveAssetVolatility(Stock $stock, float $equityValue, float $debtValue, float $riskFreeRate): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $metrics = \App\Data\Company\Sectors::metricsFor($industry);
        $strategy = \App\Data\Company\Sectors::strategyFor($industry);

        // Average mean-reverting equity volatility over the Merton horizon, reverting at the speed the price
        // process itself reverts the name's variance at, as the option desk's term structure does.
        $spotVolatility = max(0.05, (float) ($stock->getCurrentVolatility() ?? $stock->getVolatility()));
        $structuralVolatility = max(0.05, (float) ($stock->getVolatility() ?: $spotVolatility));
        $equityVolatility = max(0.05, StochasticProcesses::averageMeanRevertingVolatility(
            $spotVolatility,
            $structuralVolatility,
            \App\Service\Market\Pricing\MarketEngine::varianceReversionSpeed((float) $stock->getJumpIntensity()),
            self::MERTON_HORIZON_YEARS
        ));

        $equityShare = $equityValue / max(1.0, $equityValue + $debtValue);
        if ($strategy->isFinancial()) {
            // A dying lender has E approaching 0, which would shrink its asset volatility to 0 and grant it a
            // AAA rating, so E/V is not allowed below half of what the sector's leverage limit implies.
            $floorEV = (1.0 / (1.0 + (float) $metrics['equity_limit'])) * 0.50;

            return max(self::MIN_ASSET_VOLATILITY, $equityVolatility * max($floorEV, $equityShare));
        }

        $businessVolatility = $stock->getAssetVolatility() ?? $this->calibrateAssetVolatility($stock, $riskFreeRate);
        if ($businessVolatility === null) {
            // Nothing to solve from yet (no market value): the identity at today's capital structure.
            return max(self::MIN_ASSET_VOLATILITY, $equityVolatility * $equityShare);
        }

        return max(self::MIN_ASSET_VOLATILITY, $businessVolatility * ($equityVolatility / $structuralVolatility));
    }

    /**
     * Solves and records an operating firm's asset volatility at its current capital structure.
     *
     * Called where the configured equity volatility and the balance sheet were set together (the seed), and
     * lazily the first time a firm without one is evaluated. A financial carries none; see
     * resolveAssetVolatility().
     *
     * @return float|null sigma_V, or null for a financial or a firm with no market value to solve from.
     */
    public function calibrateAssetVolatility(Stock $stock, float $riskFreeRate): ?float
    {
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        if ($strategy->isFinancial()) {
            return null;
        }

        $equityValue = (float) $stock->getPrice() * (float) $stock->getSharesOutstanding();
        $debtValue = $strategy->getDeleveragingEvaluationDebt((float) $stock->getTotalDebt(), (float) $stock->getWholesaleDebt());
        $assetVolatility = CreditRisk::solveMertonAssetVolatility(
            $equityValue,
            max(0.05, (float) $stock->getVolatility()),
            max(0.0, $debtValue),
            $riskFreeRate,
            self::MERTON_HORIZON_YEARS
        );
        if ($assetVolatility <= 0.0) {
            return null;
        }

        $stock->setAssetVolatility($assetVolatility);

        return $assetVolatility;
    }

    /**
     * Revenue and operating margin over the last twelve months: the basis a lender measures coverage and
     * leverage on, where a firm's seasons and one quarter's charges wash out. Annualizing a single quarter
     * instead let creditworthiness flip with the calendar.
     *
     * @return array{revenue: float, margin: float}|null Null until a window is recorded, or with no revenue in it.
     */
    public function resolveTrailingOperatingBasis(Stock $stock): ?array
    {
        $history = $stock->getQuarterlyOperatingHistory() ?? [];
        if (count($history) < EarningsEngine::TTM_QUARTERS) {
            return null;
        }

        $revenue = array_sum(array_column($history, 'revenue'));
        if ($revenue <= 0.0) {
            return null;
        }

        return ['revenue' => $revenue, 'margin' => array_sum(array_column($history, 'ebit')) / $revenue];
    }

    /**
     * Debt health on the last twelve months, as a lender underwrites it. Before the first earnings report it
     * reads the reported margin, which is itself null until then and falls back to the structural one.
     */
    public function analyzeTrailingDebtHealth(Stock $stock, MacroStateDTO $macroState): \App\DTO\DebtHealthDTO
    {
        $trailing = $this->resolveTrailingOperatingBasis($stock);
        if ($trailing === null) {
            return $this->analyzeDebtHealth($stock, $macroState, null, $stock->getReportedOperatingMargin());
        }

        return $this->analyzeDebtHealth($stock, $macroState, $trailing['revenue'], $trailing['margin']);
    }

    /**
     * What the firm is worth as a going concern against what it owes.
     *
     * The assets are valued where the market values them: the Merton (1974) asset value its equity price
     * implies, at the business's own asset volatility, as KMV reads a firm's market value of assets (Crosbie
     * & Bohn 2003). That is the reorganization value a plan divides, and below the debt's face it is the
     * point at which the equity is out of the money. Capitalizing the trailing year instead valued any
     * business in a cyclical trough at nothing. Whether the business covers its cash operating costs is read
     * off the last twelve months.
     */
    public function assessGoingConcern(Stock $stock, MacroStateDTO $macroState, ?\App\DTO\DebtHealthDTO $health = null): \App\DTO\GoingConcernDTO
    {
        $health ??= $this->analyzeTrailingDebtHealth($stock, $macroState);
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());

        $equityValue = max(0.0, (float) $stock->getPrice() * (float) $stock->getSharesOutstanding());
        $debtValue = max(0.0, $strategy->getDeleveragingEvaluationDebt((float) $stock->getTotalDebt(), (float) $stock->getWholesaleDebt()));
        $riskFreeRate = $macroState->policyRateEma;
        $assetValue = CreditRisk::solveMertonAssetValue(
            $equityValue,
            $this->resolveAssetVolatility($stock, $equityValue, $debtValue, $riskFreeRate),
            $debtValue,
            $riskFreeRate,
            self::MERTON_HORIZON_YEARS
        );

        return new \App\DTO\GoingConcernDTO(
            trailingEbit: $health->rawMetrics->ebit,
            trailingEbitda: $health->rawMetrics->ebitda,
            assetValue: $assetValue,
            cash: max(0.0, (float) $stock->getCorporateTreasury()),
            claims: max(0.0, (float) $stock->getTotalDebt()),
        );
    }

    /**
     * Analyzes the overarching debt health and capital structure of the company.
     *
     * Determines the Weighted Average Cost of Capital (WACC), Cost of Equity (CAPM),
     * Levered Beta (Hamada Equation), and evaluates if the company is in a liquidity
     * crisis or suffering from negative carry.
     *
     * @param Stock $stock      The stock entity being analyzed.
     * @param MacroStateDTO $macroState The current macroeconomic state.
     * @return \App\DTO\DebtHealthDTO
     */
    public function analyzeDebtHealth(Stock $stock, \App\DTO\MacroStateDTO $macroState, ?float $overrideRevenue = null, ?float $overrideMargin = null): \App\DTO\DebtHealthDTO
    {
        $currentDebt = (float) $stock->getTotalDebt();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $equity = (float) $stock->getTotalEquity();
        $stockPrice = (float) $stock->getPrice();
        $marketCap = $stockPrice > 0.0 ? ($stockPrice * max(1.0, (float) $stock->getSharesOutstanding())) : max(1.0, $equity);
        $corporateTaxRate = $macroState->corporateTaxRate;

        $industry = $stock->getIndustry() ?: 'General';
        $metrics = \App\Data\Company\Sectors::metricsFor($industry);
        $strategy = \App\Data\Company\Sectors::strategyFor($industry);

        $debtMetrics = $this->calculateInterestExpense($stock, $macroState, false, $overrideRevenue, $overrideMargin);

        $ebit = $debtMetrics->ebit;
        $interestExpense = $debtMetrics->interestExpense;

        $costMetrics = $strategy->getDebtCostMetrics($debtMetrics, $currentDebt, $wholesaleDebt, $interestExpense);
        $grossCostOfDebt = $costMetrics->grossCostOfDebt;
        $totalInterestCost = $costMetrics->totalInterestCost;
        $evalDebt = max(1.0, $strategy->getDeleveragingEvaluationDebt($currentDebt, $wholesaleDebt));

        // 1. DYNAMIC TAX SHIELD (Phantom Tax Shield Fix)
        // A company only receives a tax shield on its debt if it actually pays taxes.
        $taxesWithoutDebt = max(0.0, $ebit) * $corporateTaxRate;
        $taxesWithDebt = max(0.0, $ebit - $totalInterestCost) * $corporateTaxRate;
        $taxSavings = $taxesWithoutDebt - $taxesWithDebt;

        $impliedTaxShieldRate = $totalInterestCost > 0 ? ($taxSavings / $totalInterestCost) : ($ebit > 0 ? $corporateTaxRate : 0.0);

        if ($currentDebt > 0 || $wholesaleDebt > 0) {
            $effectiveCostOfDebt = ($totalInterestCost - $taxSavings) / $evalDebt;
        } else {
            $effectiveCostOfDebt = $grossCostOfDebt * (1.0 - $corporateTaxRate);
        }

        // CALCULATE NET DEBT FIRST
        $treasury = (float) $stock->getCorporateTreasury();

        $netDebtCapital = $strategy->getNetDebtCapital($currentDebt, $wholesaleDebt, $treasury);

        // Hamada levered beta: preserves signed beta magnitude while adjusting for capital structure leverage.
        $baseBeta = (float) $stock->getBeta();

        // For Beta Levering and WACC weights, we MUST use Market Value of Equity, not Book Value!
        // Use Net Debt Capital so Cash Hoarders aren't penalized with fake risk.
        $debtToEquity = $marketCap > 0 ? ($netDebtCapital / $marketCap) : self::MAX_BETA_DEBT_TO_EQUITY;

        // Standard CAPM breaks down during insolvency. Cap D/E to prevent runaway WACC.
        $effectiveDebtToEquity = min(self::MAX_BETA_DEBT_TO_EQUITY, $debtToEquity);

        $leveredBeta = $strategy->calculateLeveredBeta($baseBeta, $impliedTaxShieldRate, $effectiveDebtToEquity, $this->mathUtility);

        // Cost of Equity (CAPM) on the long government yield: the riskless rate matched to the duration of the cash
        // flows being valued (Damodaran 2008, "What is the riskfree rate?"), which is also the rate the premium is
        // measured over. The policy rate is the return on bills; discounting a perpetuity at it understates the
        // hurdle by the term spread and swings every valuation with the short end of the monetary cycle. It is today's
        // yield, the one an investor can lock in now, not an average of past ones: a smoothed rate moves for months after
        // the yield has, so every valuation built on it could be forecast.
        $equityRiskPremium = $macroState->equityRiskPremium;
        $costOfEquity = Valuation::calculateCAPM($macroState->yield10y, $leveredBeta, $equityRiskPremium);

        // Absolute priority hurdle: cost of equity floored at marginal market borrowing rate.
        $costOfEquity = max($debtMetrics->currentMarketRate, $costOfEquity);

        // Weighted Average Cost of Capital (WACC)
        $totalCapital = $netDebtCapital + $marketCap;

        $weightEquity = $totalCapital > 0 ? ($marketCap / $totalCapital) : 1.0;
        $weightDebt = $totalCapital > 0 ? ($netDebtCapital / $totalCapital) : 0.0;

        // The WACC is the hurdle for NEW capital, so its debt leg is the yield the firm would pay to borrow
        // today after the tax shield it actually earns, not the coupon on debt raised years ago (Brealey,
        // Myers & Allen). Distress reaches the hurdle through that yield and the levered beta, and as a
        // weighted average it can never exceed the dearer of its two legs.
        $marginalCostOfDebt = $strategy->getMarginalCostOfDebt($debtMetrics) * (1.0 - $impliedTaxShieldRate);
        $wacc = Valuation::calculateWACC($weightEquity, $costOfEquity, $weightDebt, $marginalCostOfDebt);

        $minIcr = $strategy->getMinIcr();

        // Interest income generated by a company's cash treasury is a core component of Cash Flow Available for Debt Service (CFADS).
        // By adding it to EBIT before calculating the ICR, we prevent massive cash fortresses from suffering false liquidity crises.
        // Pass the realized wholesale rate (already carrying dynamic Merton/BGG spread widening) so strategies
        // whose earning assets reprice off their own funding cost cannot price income off a stale calm-market
        // benchmark while this same tick charges the distressed rate on the liability side.
        $interestIncome = $strategy->calculateInterestIncome($stock, $macroState, $this->mathUtility, $debtMetrics->wholesaleRate);
        $ebit += $interestIncome;

        $depreciation = $debtMetrics->depreciation;
        $interestCoverage = $strategy->getInterestCoverage($ebit, $interestExpense, $depreciation);

        $yieldOnCash = $strategy->calculateCashYield($macroState);

        // Fetch the CFO's target Debt-to-Equity limit
        $equityLimit = $metrics['equity_limit'];

        // Scale the negative carry panic threshold by the sector's structural equity leverage tolerance.
        // A normal company (1.0) = 1.0x multiplier. Financials (9.0) = 9.0x multiplier (Banks do not care about negative carry!)
        $hurdleMultiplier = max(1.0, $equityLimit / 1.0);
        $hurdle = self::ARBITRAGE_HURDLE * $hurdleMultiplier;

        // 4. TAX-FREE CASH YIELDS FOR ZOMBIES
        // If a company is unprofitable, they have Net Operating Losses (NOLs) that shield interest income from taxes.
        $effectiveYieldTaxRate = $ebit > 0 ? $corporateTaxRate : 0.0;
        $effectiveYieldOnCash = $yieldOnCash * (1.0 - $effectiveYieldTaxRate);

        $isSevereNegativeCarry = $effectiveCostOfDebt > ($effectiveYieldOnCash + $hurdle);
        $isNegativeCarry = $effectiveCostOfDebt > $effectiveYieldOnCash;

        // A company should only execute an Arbitrage Paydown if the debt is bleeding them via negative carry.
        // A drop in earnings (low ICR) should NEVER cause a company to burn its precious liquidity buffer to pay off cheap principal!
        // They must hold the cash to weather the recession.
        $wantsToPaydownDebt = ($wholesaleDebt > 0) && $isSevereNegativeCarry;

        $icrBuffer = $strategy->getRequiredIcrBuffer();
        $canIssueDebt = $interestCoverage >= ($minIcr + $icrBuffer);

        // Maintenance leverage covenant: Net Debt / EBITDA constraint on funded debt.
        $ebitdaCovenantLimit = (float) ($metrics['ebitda_limit'] ?? self::DEFAULT_EBITDA_COVENANT_LIMIT);
        $netDebtToEbitda = $debtMetrics->ebitda > 0.0
            ? min(self::MAX_LEVERAGE_RATIO, $netDebtCapital / $debtMetrics->ebitda)
            : self::MAX_LEVERAGE_RATIO;
        $hasLeverageHeadroom = $ebitdaCovenantLimit >= self::EBITDA_COVENANT_EXEMPT_LIMIT
            || $netDebtToEbitda < $ebitdaCovenantLimit;

        // Macro-Economic Leverage Tolerance
        $macroDebtTolerance = $equityLimit;

        // Capitalized operating leases (IFRS 16 / ASC 842) are debt-like obligations for leverage purposes;
        // their rent is already inside fixed costs, so they add no interest here.
        $leaseLiability = $this->corporateMetrics->resolveLeaseLiability($stock, $debtMetrics->revenue, $strategy->getLeaseIntensity());
        // An operating company's leverage is struck net of the cash it holds above its manager's operating balance,
        // so a repurchase paid from idle cash moves it as far as one paid with new debt. A financial's treasury is
        // its trading and liquidity buffer, not idle cash, and its ratio stays gross.
        $recapDebt = $strategy->isFinancial()
            ? $currentDebt + $leaseLiability
            : CreditRisk::netBookDebt($currentDebt, $leaseLiability, $treasury, $this->resolveTargetOperatingCash($stock, $strategy, $equity));
        $currentDebtRatio = $recapDebt / max(1.0, $equity);
        // A recapitalisation moves the firm toward the leverage its MANAGER targets (Bertrand & Schoar 2003
        // find the fixed effect in leverage; Graham 2000 the persistently conservative borrower), so the
        // test is struck at the same share of the tolerance the manager draws of its debt capacity. Capped at
        // one: several models already set their recap target within 15% of the limit itself. An institution
        // steering to its own capital ratio is never under-leveraged in this sense: its capital target sets
        // its distributions (Berger et al. 2008), and its return is struck on equity, so no recap raises it.
        $managerDebtTolerance = $macroDebtTolerance * min(1.0, $stock->getManagementProfile()->leverageBias());
        $isUnderLeveraged = $strategy->getTargetCapitalRatio($stock) === null
            && $strategy->isUnderLeveraged($currentDebtRatio, $managerDebtTolerance, $interestCoverage, $minIcr);

        $isLiquidityCrisis = $interestCoverage < 0;
        $isLiquidityWarning = $interestCoverage >= 0 && $interestCoverage < $minIcr;

        return new \App\DTO\DebtHealthDTO(
            $grossCostOfDebt,
            $effectiveCostOfDebt,
            $yieldOnCash,
            $isNegativeCarry,
            $isSevereNegativeCarry,
            $interestCoverage,
            $wantsToPaydownDebt,
            $canIssueDebt,
            $macroDebtTolerance,
            $wacc,
            $costOfEquity,
            $leveredBeta,
            $debtMetrics,
            $isLiquidityCrisis,
            $isLiquidityWarning,
            $isUnderLeveraged,
            $hasLeverageHeadroom,
            $netDebtToEbitda,
            $ebitdaCovenantLimit,
            $currentDebtRatio,
            $strategy->getLeverageTarget($managerDebtTolerance),
            $this->hasPrimaryMarketAccess($stock, $debtMetrics, $interestCoverage)
        );
    }

    /** The firm's own depreciation rate, or its industry's when it carries none. */
    private function resolveDepreciationRate(Stock $stock): float
    {
        $customDepreciation = (float) $stock->getDepreciationRate();

        return $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($stock->getIndustry() ?: 'General');
    }

    /** The operating balance the firm's manager runs to, as CapitalAllocationEngine measures excess cash against it. */
    private function resolveTargetOperatingCash(Stock $stock, \App\Service\Model\BusinessModelInterface $strategy, float $equity): float
    {
        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), $equity);

        return $stock->getManagementProfile()->managedTargetCash($strategy, $operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
    }

    /**
     * A capital-ratio firm's loss-absorbing capital and the assets it stands behind, both tangible: the
     * prompt corrective action measure, tangible equity over total assets (12 USC 1831o). Goodwill leaves
     * both sides, as every regulator deducts it, so a write-off moves neither. The assets are the balance
     * sheet the firm carries once its ledger is open, not the funding proxy. Client money held in custody
     * leaves them too: it is bankruptcy-remote and matched by a liability to the client, and leaving it in
     * measured a clearinghouse against a margin pool that grows with volatility, so the score fell hardest
     * in exactly the conditions it was meant to survive.
     *
     * @return array{capital: float, assets: float}
     */
    public function resolveTangibleCapitalBase(Stock $stock, float $revenue): array
    {
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        $leaseLiability = $this->corporateMetrics->resolveLeaseLiability($stock, $revenue, $strategy->getLeaseIntensity());

        $totalAssets = $stock->hasBalanceSheetLedger()
            ? $stock->getTotalAssets($leaseLiability)
            : (float) $stock->getTotalEquity() + (float) $stock->getTotalDebt() + $leaseLiability;

        return [
            'capital' => $stock->getTangibleEquity(),
            'assets' => max(1.0, $totalAssets - max(0.0, (float) $stock->getGoodwill()) - $strategy->getSegregatedCustodyLiabilities($stock)),
        ];
    }

    /**
     * Calculates the Altman Z''-Score (Double Prime) for modern, non-manufacturing corporate bankruptcy prediction.
     * 
     * Evaluates working capital, retained earnings, operating income, and book equity to
     * determine if the company is at imminent risk of insolvency.
     *
     * @param Stock $stock        The stock entity being evaluated.
     * @param float $ebit         Earnings Before Interest and Taxes.
     * @param float $revenue      Total Revenue.
     * @return array{z_score: float, zone: string, is_bankrupt: bool}
     */
    public function calculateAltmanZScore(Stock $stock, float $ebit, float $revenue): array
    {
        $equity = (float) $stock->getTotalEquity();
        $treasury = (float) $stock->getCorporateTreasury();
        $retainedEarnings = (float) $stock->getRetainedEarnings();

        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());

        // IFRS 16 / ASC 842: the capitalized lease liability sits with debt and the right-of-use asset with assets.
        $leaseLiability = $this->corporateMetrics->resolveLeaseLiability($stock, $revenue, $strategy->getLeaseIntensity());
        $debt = (float) $stock->getTotalDebt() + $leaseLiability;

        // Accounting Proxy: Assets = Liabilities + Equity
        $totalAssets = max(1.0, $equity + $debt);

        if ($strategy->requiresAlternativeZScore()) {
            $capitalBase = $this->resolveTangibleCapitalBase($stock, $revenue);
            $capitalRatio = $capitalBase['capital'] / $capitalBase['assets'];
            $zScore = max(-100.0, min(100.0, $capitalRatio * 100.0)); // Convert to percentage points (e.g., 8% capital = 8.0 score)

            $distressThreshold = $strategy->getDistressEquityThreshold();
            $warningThreshold = $strategy->getWarningEquityThreshold();
            $bankruptThreshold = $strategy->getBankruptEquityThreshold();

            $zone = 'Safe';
            if ($zScore < $distressThreshold) {
                $zone = 'Distress';
            } elseif ($zScore < $warningThreshold) {
                $zone = 'Grey';
            }

            return [
                'z_score' => $zScore,
                'zone' => $zone,
                'is_bankrupt' => $zScore < $bankruptThreshold
            ];
        }

        if ($stock->hasWorkingCapitalLedger()) {
            // The real current balances. Debt due within a year is what the maturity ladder says comes
            // due in the next four quarters; the rest is long-term and is not a current claim.
            $currentAssets = $treasury + $stock->getNetReceivables() + $stock->getNetInventory();
            $currentDebt = (float) $stock->getWholesaleDebt() * min(1.0, $strategy->getDebtMaturityRolloverRate() * 4.0);
            $currentLiabilities = (float) ($stock->getPayables() ?? 0.0) + $currentDebt;
            $workingCapital = $currentAssets - $currentLiabilities;

            if ($stock->getGrossPpe() !== null) {
                $totalAssets = max(1.0, $stock->getTotalAssets($leaseLiability));
            }
        } else {
            // No trade ledger yet: fall back to the proxies the model started with.
            $currentLiabilities = $debt * 0.20;
            $workingCapital = $treasury - $currentLiabilities;
        }

        // The 4 Z''-Score Ratios (X5 Revenue/Assets is removed for non-manufacturing). Z'' (Altman 1995) replaced
        // the 1968 model's market equity with BOOK equity over total liabilities, which is what its 6.56/3.26/
        // 6.72/1.05 weights and 1.10/2.60 cut-offs were fitted on; the equity market is already read through the
        // Merton distance to default.
        $totalLiabilities = max(0.0, $totalAssets - $equity);
        $x1 = $workingCapital / $totalAssets;
        $x2 = $retainedEarnings / $totalAssets;
        $x3 = $ebit / $totalAssets;
        $x4 = $totalLiabilities > 0.0 ? ($equity / $totalLiabilities) : self::ALTMAN_X4_UNLEVERED_CAP;

        // The Z''-Score Formula (Modern Service/Tech/Financial Weights)
        $zScore = (6.56 * $x1) + (3.26 * $x2) + (6.72 * $x3) + (1.05 * $x4);
        $zScore = max(-100.0, min(100.0, $zScore));

        // Z'' has different threshold thresholds than the 1968 model
        $zone = 'Safe';
        if ($zScore < 1.10) {
            $zone = 'Distress';
        } elseif ($zScore < 2.60) {
            $zone = 'Grey';
        }

        return [
            'z_score' => $zScore,
            'zone' => $zone,
            // A negative Z'' score is a near-mathematical certainty of insolvency
            'is_bankrupt' => $zScore < 0.00,
        ];
    }

    /**
     * Rolls the quarter's maturing principal down the maturity ladder.
     *
     * The rollover rate a business model declares already implies an average tenor of 1 / (4 x rate) years,
     * so the same fraction of the debt stock comes due each quarter. Until now that only ever repriced the
     * coupon: principal was immortal and no firm could ever be refused a refinancing. That removed the most
     * common way real companies actually fail — not a slow slide into insolvency, but a maturity landing in
     * a quarter when nobody will lend (commercial paper in 2008, high yield in March 2020).
     *
     * Market access is deliberately looser than the test for taking on NEW leverage: an investment-grade
     * issuer refinances straight through a recession. It closes only when spreads have blown out to crisis
     * levels, the rating is below the market's floor, or the firm cannot cover the interest it already owes.
     * When it closes the principal must be repaid in cash, and whatever cash cannot cover is a shortfall the
     * treasury has to fund with emergency financing or default on.
     *
     * Only cash above the operating floor can be handed to a bondholder. A firm does not pay its last dollar
     * of working capital against principal and then fail to make payroll; it defaults on the bond with cash
     * still in the bank, and that cash is what funds the restructuring. Sweeping the treasury to zero instead
     * both destroyed the going concern and removed the means to cure the default.
     *
     * @param float $availableCash Cash on hand before the repayment.
     * @param float $cashFloor     Operating cash that cannot be spent on principal.
     */
    public function rollMaturities(
        Stock $stock,
        \App\DTO\DebtHealthDTO $health,
        float $availableCash,
        float $cashFloor = 0.0
    ): \App\DTO\MaturityRollDTO
    {
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        if ($wholesaleDebt <= 0.0) {
            return new \App\DTO\MaturityRollDTO(0.0, true, 0.0, 0.0);
        }

        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());

        $maturing = $wholesaleDebt * max(0.0, $strategy->getDebtMaturityRolloverRate());
        if ($maturing <= 0.0) {
            return new \App\DTO\MaturityRollDTO(0.0, true, 0.0, 0.0);
        }

        if ($this->hasPrimaryMarketAccess($stock, $health->rawMetrics, $health->interestCoverage)) {
            // Refinanced in the primary market: the principal survives and only its coupon reprices, which
            // calculateInterestExpense() has already done through the blended fixed rate.
            return new \App\DTO\MaturityRollDTO($maturing, true, 0.0, 0.0);
        }

        $repaid = min($maturing, max(0.0, $availableCash - max(0.0, $cashFloor)));
        $shortfall = $maturing - $repaid;

        $stock->setWholesaleDebt((string) max(0.0, $wholesaleDebt - $repaid));

        return new \App\DTO\MaturityRollDTO($maturing, false, $repaid, $shortfall);
    }

    /** A lender, an insurer, or any institution steering to a capital ratio, is underwritten and rated on its capital, not its cash flow. */
    private static function isUnderwrittenOnCapital(Stock $stock, \App\Service\Model\BusinessModelInterface $strategy): bool
    {
        return $strategy instanceof \App\Service\Model\Sector\CommercialBankBusinessModel
            || $strategy instanceof \App\Service\Model\Sector\InsuranceBusinessModel
            || $strategy->getTargetCapitalRatio($stock) !== null;
    }

    /**
     * The score the agency's distress caps read: the Altman Z'' (or a financial's capital ratio in its place), except for
     * an asset manager, which S&P rates on the corporate methodology's business and financial risk alone ("Key Credit
     * Factors For Asset Managers", 2014); Altman's sample held no financials.
     *
     * @return array{z_score: float|null, zone: string|null, is_bankrupt?: bool}
     */
    private function resolveRatingZScore(Stock $stock, \App\Service\Model\BusinessModelInterface $strategy, float $ebit, float $revenue): array
    {
        if ($strategy instanceof \App\Service\Model\Sector\AssetManagementBusinessModel) {
            return ['z_score' => null, 'zone' => null];
        }

        $scored = $this->calculateAltmanZScore($stock, $ebit, $revenue);
        if ($strategy->requiresAlternativeZScore()) {
            return $scored;
        }

        // An operating firm's Z'' is read against its zones with the agency's hysteresis, so a score on an edge does
        // not cap and release the rating on alternate quarters.
        $scored['zone'] = \App\Service\Market\Bond\CreditRatingAgency::altmanZoneWithHysteresis($scored['z_score'], (string) $stock->getCreditRating());

        return $scored;
    }

    /** An asset manager investing its own balance sheet (S&P's hybrid model): private equity, a hedge fund, a distressed-debt investor. */
    private static function investsItsBalanceSheet(\App\Service\Model\BusinessModelInterface $strategy): bool
    {
        return $strategy instanceof \App\Service\Model\Sector\PrivateEquityBusinessModel
            || $strategy instanceof \App\Service\Model\Sector\HedgeFundBusinessModel
            || $strategy instanceof \App\Service\Model\Sector\DistressedDebtBusinessModel;
    }

    /**
     * The ceiling on a firm's rating before its market-implied grade: for an operating firm the one its leverage sets
     * (CreditRatingAgency::financialRiskAnchor()), on S&P's real estate bands for a REIT; for an asset manager investing
     * its balance sheet, debt to adjusted total equity (adjustedEquityLeverageAnchor()); for an institution rated on its
     * capital the bank methodology's (CreditRatingAgency::CAPITAL_RATED_RATING_CEILING). A rate-regulated utility
     * carries S&P's excellent business risk profile, read on the medial volatility table. Debt to EBITDA is rated on
     * S&P's time-weighted years (CreditRatingAgency::timeWeightedLeverage()) when the review has them, $ratedQuarters
     * and the $baseCase forecast, else on the figures given; a pro-forma deal is rated on its own year, as S&P weights a
     * transformational event.
     *
     * @param list<array{net_debt: float, ebitda: float}> $ratedQuarters Reviews' net debt and EBITDA, newest first.
     * @param array{net_debt: float, ebitda: float}|null $baseCase The forecast years (baseCaseLeverage()).
     */
    private static function leverageAnchor(Stock $stock, \App\Service\Model\BusinessModelInterface $strategy, float $netDebt, float $ebitda, array $ratedQuarters = [], ?array $baseCase = null): string
    {
        if (self::isUnderwrittenOnCapital($stock, $strategy)) {
            return CreditRatingAgency::CAPITAL_RATED_RATING_CEILING;
        }
        if (self::investsItsBalanceSheet($strategy)) {
            return CreditRatingAgency::adjustedEquityLeverageAnchor($netDebt, $stock->getTangibleEquity());
        }

        $basis = $ratedQuarters !== []
            ? CreditRatingAgency::timeWeightedLeverage($ratedQuarters, $baseCase)
            : ['net_debt' => $netDebt, 'ebitda' => $ebitda];
        if ($strategy instanceof \App\Service\Model\Sector\ReitBusinessModel) {
            return CreditRatingAgency::financialRiskAnchor($basis['net_debt'], $basis['ebitda'], false, CreditRatingAgency::REAL_ESTATE_DEBT_TO_EBITDA_BOUNDS);
        }
        $isUtility = $strategy instanceof \App\Service\Model\Sector\UtilityBusinessModel;

        return CreditRatingAgency::financialRiskAnchor(
            $basis['net_debt'],
            $basis['ebitda'],
            $isUtility,
            $isUtility ? CreditRatingAgency::MEDIAL_VOLATILITY_DEBT_TO_EBITDA_BOUNDS : CreditRatingAgency::FINANCIAL_RISK_DEBT_TO_EBITDA_BOUNDS
        );
    }

    /**
     * The base case S&P's forecast years are rated on: net debt where it stands and EBITDA at the firm's structural
     * operating margin on its trailing revenue, the margin the consensus is struck on, with this year's depreciation.
     *
     * @return array{net_debt: float, ebitda: float}
     */
    private static function baseCaseLeverage(Stock $stock, float $netDebt, float $revenue, float $depreciation): array
    {
        return ['net_debt' => $netDebt, 'ebitda' => $revenue * (float) $stock->getOperatingMargin() + $depreciation];
    }

    /**
     * Records the net debt and trailing EBITDA this review rates on against the quarter the report has just added to the
     * operating history, after those the previous quarter's review recorded, and returns them newest first, as many as
     * CreditRatingAgency::timeWeightedLeverage() reads. The record travels with the history, so a deal that restates it
     * pro forma (MergerAndAcquisitionEngine) starts the window over; a quarter reviewed twice rewrites its own entry.
     *
     * @return list<array{net_debt: float, ebitda: float}>
     */
    private function recordRatedLeverage(Stock $stock, float $netDebt, float $ebitda): array
    {
        $current = ['net_debt' => $netDebt, 'ebitda' => $ebitda];
        $history = array_values($stock->getQuarterlyOperatingHistory() ?? []);
        if ($history === []) {
            return [$current];
        }

        $last = count($history) - 1;
        $earlier = $last > 0 ? ($history[$last - 1][self::RATED_LEVERAGE_KEY] ?? []) : [];
        $rated = array_slice([$current, ...$earlier], 0, CreditRatingAgency::timeWeightedLeverageQuarters());
        $history[$last][self::RATED_LEVERAGE_KEY] = $rated;
        $stock->setQuarterlyOperatingHistory($history);

        return $rated;
    }

    /**
     * Whether the primary market will lend to this issuer at any price: roll its maturity or fund a cash shortfall.
     */
    private function hasPrimaryMarketAccess(Stock $stock, \App\DTO\DebtMetricsDTO $metrics, float $interestCoverage): bool
    {
        // The market shuts to a class when the index it trades off blows out, not because a CCC trades at a CCC spread;
        // an issuer's own distress closes it through its rating and its coverage below.
        if (($metrics->marketIndexSpread ?? $metrics->dynamicSpread) >= self::PRIMARY_MARKET_CLOSURE_SPREAD) {
            return false;
        }

        // Coverage is how a lender underwrites an operating borrower, whose interest is a charge on its cash flow. A
        // lender, or any institution steering to a capital ratio, is underwritten on its capital instead: its interest
        // expense is its cost of goods, and a provisioning quarter is not a missed coupon. Its rating reads the capital.
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        if (!self::isUnderwrittenOnCapital($stock, $strategy) && $interestCoverage < self::REFINANCING_MIN_COVERAGE) {
            return false;
        }

        $ranks = \App\Service\Market\Bond\CreditRatingAgency::RATING_RANKS;

        return ($ranks[$stock->getCreditRating()] ?? $ranks['BBB'])
            >= ($ranks[self::REFINANCING_RATING_FLOOR] ?? 1);
    }

    /**
     * Issues new wholesale debt and recalculates the blended historical fixed rate.
     * 
     * @param Stock $stock         The stock entity issuing debt.
     * @param float $amountIssued  The amount of new debt issued.
     * @param float $costOfNewDebt The fixed interest rate for the newly issued debt.
     */
    public function issueDebt(Stock $stock, float $amountIssued, float $costOfNewDebt): void
    {
        if ($amountIssued <= 0.0) return;

        $currentWholesaleDebt = (float) $stock->getWholesaleDebt();
        $newWholesaleDebt = $currentWholesaleDebt + $amountIssued;

        $oldHistoricalRate = (float) $stock->getHistoricalFixedRate();
        $weightedRate = (($currentWholesaleDebt * $oldHistoricalRate) + ($amountIssued * $costOfNewDebt)) / $newWholesaleDebt;

        $stock->setHistoricalFixedRate((string) $weightedRate);
        $stock->setWholesaleDebt((string) $newWholesaleDebt);
    }
}

<?php

namespace App\Service\Corporate;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\MarketEventPublisher;
use App\Service\Macro\MacroEngine;
use App\Service\Market\CreditRatingAgency;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;

class DebtEngine
{
    // --- Debt Analysis ---
    /** 300 bps spread is severe threshold for arbitrage hurdle. */
    private const ARBITRAGE_HURDLE = 0.030;

    // --- Leverage Physics ---
    /** Cap extreme D/E or D/EBITDA ratios. */
    private const MAX_LEVERAGE_RATIO = 15.0;
    /** 25% max Junk Bond penalty spread. */
    private const MAX_LEVERAGE_PENALTY = 0.25;
    /** Penalty rate for high leverage. */
    private const LEVERAGE_PENALTY_RATE = 0.20;
    /** Base penalty for high leverage. */
    private const LEVERAGE_PENALTY_BASE = 0.010;
    /** BGG (1999) Financial Accelerator external finance premium sensitivity to leverage during recessions. */
    private const BGG_ACCELERATOR_SENSITIVITY = 0.050;

    // --- CAPM / Beta Limits ---
    /** Prevent runaway WACC in standard CAPM by capping debt to equity ratio. */
    private const MAX_BETA_DEBT_TO_EQUITY = 2.5;
    /** Dampen double-counting of historical debt when calculating Levered Beta. */
    private const HAMADA_DAMPENING_FACTOR = 0.25;

    // --- Refinancing Hurdles ---
    /** 150 bps drop triggers early refinancing. */
    private const RATE_REFINANCE_THRESHOLD = 0.015;
    /** 15% of debt retired per quarter if early refinancing is triggered. */
    private const ACCELERATED_DEBT_TURNOVER = 0.15;

    // --- Merton Default Horizon ---
    /** Horizon the structural default model is struck on; 5 years is the standard tenor for corporate credit spreads. */
    private const MERTON_HORIZON_YEARS = 5.0;
    /**
     * Mean-reversion speed of idiosyncratic equity volatility, in reversions per year. Derived from the rate
     * the earnings engine itself cools a shock, -ln(1 - VOLATILITY_COOLING_FACTOR) * 4 quarters, so the credit
     * model and the volatility process agree on how long a surprise is expected to last.
     */
    private const EQUITY_VOL_REVERSION_SPEED = 1.1507;

    // --- Maturity Wall & Primary Market Access ---
    /** Dynamic credit spread above which the primary market is shut to the issuer; high-yield spreads reached this in 2008 and 2020. */
    private const PRIMARY_MARKET_CLOSURE_SPREAD = 0.10;
    /** Lowest credit rating that can still refinance a maturity at any price. */
    private const REFINANCING_RATING_FLOOR = 'CCC';
    /** Interest coverage below which lenders will not roll a maturity: the firm cannot service what it already owes. */
    private const REFINANCING_MIN_COVERAGE = 1.0;

    public function __construct(
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        private ?CreditRatingAgency $creditRatingAgency = null,
        private ?MarketEventPublisher $marketEventPublisher = null
    ) {}

    /**
     * Calculates the gross and blended interest expenses for a company's debt structure.
     *
     * Applies the macro credit cycle, volatility risk premiums, and industry-specific
     * leverage penalties (Junk Bond blowouts) to determine the true cost of debt.
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

        $rawCreditSpread = (float) $stock->getCreditSpread();
        $volatility = (float) $stock->getCurrentVolatility() ?: (float) $stock->getVolatility();

        $rawBeta = (float) $stock->getBeta();

        // THE MACROECONOMIC CREDIT CYCLE
        // Spreads widen during recessions (negative gap) as lenders panic, and tighten during booms.
        // High-beta (cyclical) stocks see their spreads widen much faster than low-beta (defensive) stocks.
        $betaSensitivity = $rawBeta >= 0.0 ? max(0.5, $rawBeta) : min(-0.5, $rawBeta);

        // Use the aggregate Macro Credit Spread (excess over the 200bps baseline)
        $aggregateCreditSpread = $macroState->macroCreditSpreadEma;
        $macroCreditExcess = max(0.0, $aggregateCreditSpread - 0.02);

        // High beta stocks suffer the full brunt (or more) of credit market blowouts
        $macroCreditAdjustment = $macroCreditExcess * abs($betaSensitivity);

        // IDIOSYNCRATIC VOLATILITY PREMIUM
        // Bondholders hate individual uncertainty. High stock volatility pays a risk premium.
        $volatilityPremium = max(0.0, ($volatility - 0.20) * 0.02);

        // Calculate the Dynamic Baseline Spread
        // Floored at 15 bps (0.0015) so ultra-safe Titans don't get negative spreads during massive economic booms.
        $baselineCreditSpread = max(0.0015, $rawCreditSpread + $macroCreditAdjustment + $volatilityPremium);

        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

        $revenue = $overrideRevenue ?? (float) $stock->getTotalRevenue();

        if ($revenue <= 0.0) {
            $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

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
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);

        // Depreciation runs on the same base the earnings engine charges it against (net PP&E for physical
        // businesses, the capital proxy for financial ones), so coverage and EBITDA here agree with the
        // income statement instead of depreciating goodwill and working capital.
        $depreciation = max(0.0, $strategy->getDepreciableBase($stock)) * $depreciationRate;
        $ebitda = $ebit + $depreciation;

        if ($debt <= 0.0) {
            if ($this->creditRatingAgency !== null && $advanceMaturity) {
                $oldRating = $stock->getCreditRating();
                $zScoreData = $this->calculateAltmanZScore($stock, $ebit, $revenue, (float) $stock->getPrice());
                $newRating = $this->creditRatingAgency->evaluateRating($stock, 10.0, $zScoreData['z_score']);
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

            return new \App\DTO\DebtMetricsDTO(
                0.0,
                0.0,
                (float) $stock->getHistoricalFixedRate(),
                $baselineCreditSpread,
                $yield5y + $baselineCreditSpread,
                $yield5y + $baselineCreditSpread,
                $ebit,
                $revenue,
                $depreciation,
                $ebitda
            );
        }

        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $totalDebtObligations = max(0.01, $strategy->getDeleveragingEvaluationDebt($debt, $wholesaleDebt));
        $netDebt = max(0.0, $strategy->getNetDebtCapital($debt, $wholesaleDebt, $treasury));
        $totalEquity = (float) $stock->getTotalEquity();


        // Fetch our Dual Constraints
        $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industry] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
        $ebitdaLimit = $metrics['ebitda_limit'];
        $equityLimit = $metrics['equity_limit'];

        // MERTON'S STRUCTURAL MODEL OF DEFAULT
        // Prices corporate credit spreads dynamically based on Default Probability
        // The default probability runs to MERTON_HORIZON_YEARS, so the volatility it is struck on has to be
        // the volatility expected to prevail over that horizon, not today's. Spot volatility here is ratcheted
        // by every material earnings surprise and capped at MAX_VOLATILITY_MULTIPLIER times baseline, so a
        // cyclical firm whose earnings routinely surprise sat permanently at 3x its structural volatility.
        // Carried undiminished through a five-year d2, that alone drove an issuer with a Safe Altman score,
        // 6x interest coverage and compounding equity to a D rating, which shut it out of the primary market
        // and defaulted it on the next maturity. Averaging the mean-reverting process over the horizon prices
        // the shock for as long as it is actually expected to last.
        $spotVolatility = max(0.05, (float) ($stock->getCurrentVolatility() ?? $stock->getVolatility()));
        $structuralVolatility = max(0.05, (float) ($stock->getVolatility() ?: $spotVolatility));
        $equityVolatility = $this->mathUtility->averageMeanRevertingVolatility(
            $spotVolatility,
            $structuralVolatility,
            self::EQUITY_VOL_REVERSION_SPEED,
            self::MERTON_HORIZON_YEARS
        );
        $equityVolatility = max(0.05, $equityVolatility); // Minimum vol failsafe

        $marketCap = max(1.0, (float) $stock->getPrice() * max(1.0, (float) $stock->getSharesOutstanding()));
        // For default modeling, Firm Value V = Market Equity + Total Debt Obligations
        $assetValue = $marketCap + $totalDebtObligations;

        // Asset Volatility approximation: sigma_V = sigma_E * (E / V)
        // A dying company with massive debt has E approaching 0, which would shrink Asset Volatility to 0 and falsely grant a AAA rating.
        // We calculate their natural maximum leverage (EquityLimit) and prevent E/V from compressing below 50% of that natural limit.
        $naturalMinEVRatio = 1.0 / (1.0 + $equityLimit);
        $floorEV = $naturalMinEVRatio * 0.50;

        $assetVolatility = $equityVolatility * max($floorEV, $marketCap / $assetValue);
        $assetVolatility = max(0.02, $assetVolatility); // Minimum asset vol failsafe

        $policyRate = $macroState->policyRateEma;

        // Debt maturity is approximated at 5 years for standard corporate credit spreads
        $timeToMaturity = self::MERTON_HORIZON_YEARS;

        $lossGivenDefault = $strategy->getLossGivenDefault();

        $distanceToDefault = $this->mathUtility->calculateDistanceToDefault(
            $assetValue,
            $totalDebtObligations,
            $assetVolatility,
            $policyRate,
            $timeToMaturity
        );

        if ($this->creditRatingAgency !== null && $advanceMaturity) {
            $oldRating = $stock->getCreditRating();
            $zScoreData = $this->calculateAltmanZScore($stock, $ebit, $revenue, (float) $stock->getPrice());
            $newRating = $this->creditRatingAgency->evaluateRating($stock, $distanceToDefault, $zScoreData['z_score']);
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

        $mertonSpread = $this->mathUtility->calculateMertonCreditSpread(
            $distanceToDefault,
            $lossGivenDefault,
            $timeToMaturity
        );

        // BERNANKE-GERTLER-GILCHRIST (1999) FINANCIAL ACCELERATOR
        // Agency costs between borrowers and lenders amplify credit friction during economic downturns.
        // Highly leveraged firms face an external finance premium during recessions.
        $firmLeverage = $marketCap > 0.0 ? ($totalDebtObligations / $marketCap) : self::MAX_LEVERAGE_RATIO;
        $recessionDepth = max(0.0, -$macroState->outputGapEma);
        $bggAcceleratorPremium = min(self::MAX_LEVERAGE_PENALTY, self::BGG_ACCELERATOR_SENSITIVITY * $firmLeverage * $recessionDepth);

        $dynamicSpread = $baselineCreditSpread + $mertonSpread + $bggAcceleratorPremium;

        // Fixed-rate corporate debt is priced off the 5-Year Yield curve, not the overnight Policy Rate
        $currentMarketFixedRate = $yield5y + $dynamicSpread;

        $historicalRate = (float) $stock->getHistoricalFixedRate();

        if ($advanceMaturity) {
            // Maturity wall: the business model sets how fast the fixed-rate book rolls to market.
            $turnover = $strategy->getDebtMaturityRolloverRate();
            if ($currentMarketFixedRate < ($historicalRate - self::RATE_REFINANCE_THRESHOLD)) {
                $turnover = self::ACCELERATED_DEBT_TURNOVER;
            }
            $blendedFixedRate = ($historicalRate * (1.0 - $turnover)) + ($currentMarketFixedRate * $turnover);
        } else {
            $blendedFixedRate = $historicalRate;
        }

        $floatingInterestRate = $policyRate + $dynamicSpread;

        // Customer Deposits & Leverage Physics
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $expenseMetrics = $strategy->calculateInterestExpenseAndWholesaleRate($stock, $blendedFixedRate, $floatingInterestRate, $currentMarketFixedRate, $policyRate, $equityLimit, $totalEquity, $debt);
        $interestExpense = $expenseMetrics->interestExpense;
        $wholesaleRate = $expenseMetrics->wholesaleRate;

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
            $ebitda
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
        $policyRate = $macroState->policyRateEma;
        $corporateTaxRate = $macroState->corporateTaxRate;


        $industry = $stock->getIndustry() ?: 'General';
        $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industry] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
        $businessModel = $metrics['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

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

        // Levered Beta (The Penalty for Greed)
        // The firm's own signed beta goes into Hamada. Leverage multiplies systematic exposure, so it
        // amplifies the magnitude and can never flip the sign: a levered hedge is a bigger hedge, not a
        // market-following asset. The previous max(0.5, abs(beta)) did two ad-hoc jobs at once and did both
        // badly. abs() erased the very sign that makes an inverse-beta name a hedge, and the 0.5 floor
        // handed every firm below it an identical cost of equity, so a water utility at 0.15 and an
        // industrial REIT at 0.50 were discounted at exactly the same rate.
        $baseBeta = (float) $stock->getBeta();

        // For Beta Levering and WACC weights, we MUST use Market Value of Equity, not Book Value!
        // Use Net Debt Capital so Cash Hoarders aren't penalized with fake risk.
        $debtToEquity = $marketCap > 0 ? ($netDebtCapital / $marketCap) : self::MAX_BETA_DEBT_TO_EQUITY;

        // Standard CAPM breaks down during insolvency. Cap D/E to prevent runaway WACC.
        $effectiveDebtToEquity = min(self::MAX_BETA_DEBT_TO_EQUITY, $debtToEquity);

        $leveredBeta = $strategy->calculateLeveredBeta($baseBeta, $impliedTaxShieldRate, $effectiveDebtToEquity, $this->mathUtility);

        // Cost of Equity (CAPM) - Unified to Policy Rate to perfectly match MarketEngine valuation physics
        $equityRiskPremium = $macroState->equityRiskPremium;
        $costOfEquity = $this->mathUtility->calculateCAPM($policyRate, $leveredBeta, $equityRiskPremium);

        // Absolute priority. CAPM on its own is happy to hand a negative-beta hedge a required return below
        // the risk-free rate, which is defensible as portfolio theory and indefensible as a hurdle rate: no
        // board funds projects below what its own lenders charge. Equity is the junior claim on the same
        // cash flows, so it cannot require less than the debt ranking above it.
        //
        // The floor is the MARGINAL rate the firm would borrow at today, not its blended book cost. A firm
        // carrying cheap legacy fixed-rate debt has a funding advantage, not less risk, and discounting its
        // equity at that stale rate would capitalise the advantage twice. Using the current market rate also
        // makes the floor carry the firm's own credit risk, so a distressed fund is floored at its junk
        // yield while a fortress utility is floored just over the policy rate. The distress premium below is
        // added on top of this structural minimum.
        $costOfEquity = max($debtMetrics->currentMarketRate, $costOfEquity);

        // Weighted Average Cost of Capital (WACC)
        $totalCapital = $netDebtCapital + $marketCap;

        $weightEquity = $totalCapital > 0 ? ($marketCap / $totalCapital) : 1.0;
        $weightDebt = $totalCapital > 0 ? ($netDebtCapital / $totalCapital) : 0.0;
        $baseWacc = $this->mathUtility->calculateWACC($weightEquity, $costOfEquity, $weightDebt, $effectiveCostOfDebt);

        // DISTRESS PENALTY
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        
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

        // Check if the company has a massive cash hoard to weather the storm
        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $minOperatingCash = $strategy->calculateMinOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $hasCashBuffer = ((float) $stock->getCorporateTreasury()) > ($minOperatingCash * 1.5);

        $distressPremium = 0.0;
        if ($interestCoverage < $minIcr && $interestCoverage >= 0) {
            $maxPenalty = $hasCashBuffer ? 0.05 : 0.10;
            $penaltyMultiplier = $maxPenalty / max(0.01, $minIcr);
            $distressPremium = ($minIcr - $interestCoverage) * $penaltyMultiplier;
        } elseif ($interestCoverage < 0) {
            // Milder penalty if they have cash to survive the negative quarter
            $distressPremium = $hasCashBuffer ? 0.05 : 0.15;
        }

        $wacc = $baseWacc + $distressPremium;

        if ($strategy->appliesDistressPremiumToCostOfEquity()) {
            // Financial institutions use Cost of Equity as their hurdle rate, so it must also suffer the distress penalty!
            $costOfEquity += $distressPremium;
        }

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

        // Macro-Economic Leverage Tolerance
        $macroDebtTolerance = $equityLimit;

        // Capitalized operating leases (IFRS 16 / ASC 842) are debt-like obligations for leverage purposes;
        // their rent is already inside fixed costs, so they add no interest here.
        $leaseLiability = $this->corporateMetrics->calculateLeaseLiability($debtMetrics->revenue, $strategy->getLeaseIntensity());
        $currentDebtRatio = ($currentDebt + $leaseLiability) / max(1.0, $equity);
        $isUnderLeveraged = $strategy->isUnderLeveraged(
            $currentDebtRatio,
            $macroDebtTolerance,
            $interestCoverage,
            $minIcr,
            $costOfEquity,
            $effectiveCostOfDebt
        );

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
            $isUnderLeveraged
        );
    }

    /**
     * Calculates the Altman Z''-Score (Double Prime) for modern, non-manufacturing corporate bankruptcy prediction.
     * 
     * Evaluates working capital, retained earnings, operating income, and equity to 
     * determine if the company is at imminent risk of insolvency.
     * 
     * @param Stock $stock        The stock entity being evaluated.
     * @param float $ebit         Earnings Before Interest and Taxes.
     * @param float $revenue      Total Revenue.
     * @param float $currentPrice Current share price.
     * @return array{z_score: float, zone: string, is_bankrupt: bool}
     */
    public function calculateAltmanZScore(Stock $stock, float $ebit, float $revenue, float $currentPrice): array
    {
        $equity = (float) $stock->getTotalEquity();
        $treasury = (float) $stock->getCorporateTreasury();
        $retainedEarnings = (float) $stock->getRetainedEarnings();
        $shares = max(1.0, (float) $stock->getSharesOutstanding());

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

        // IFRS 16 / ASC 842: the capitalized lease liability sits with debt and the right-of-use asset with assets.
        $leaseLiability = $this->corporateMetrics->calculateLeaseLiability($revenue, $strategy->getLeaseIntensity());
        $debt = (float) $stock->getTotalDebt() + $leaseLiability;

        // Accounting Proxy: Assets = Liabilities + Equity
        $totalAssets = max(1.0, $equity + $debt);
        $marketCap = $currentPrice * $shares;

        if ($strategy->requiresAlternativeZScore()) {
            // A bank's capital ratio is struck on the balance sheet it actually carries once the earning-asset
            // ledger is open: loans net of expected losses, not the funding proxy.
            if ($stock->hasBalanceSheetLedger()) {
                $totalAssets = max(1.0, $stock->getTotalAssets($leaseLiability));
            }
            $capitalRatio = $equity / $totalAssets;
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

        // The 4 Z''-Score Ratios (X5 Revenue/Assets is removed for non-manufacturing)
        $x1 = $workingCapital / $totalAssets;
        $x2 = $retainedEarnings / $totalAssets;
        $x3 = $ebit / $totalAssets;
        $x4 = $debt > 0 ? ($marketCap / $debt) : 10.0; // Cap at 10

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

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

        $maturing = $wholesaleDebt * max(0.0, $strategy->getDebtMaturityRolloverRate());
        if ($maturing <= 0.0) {
            return new \App\DTO\MaturityRollDTO(0.0, true, 0.0, 0.0);
        }

        if ($this->hasPrimaryMarketAccess($stock, $health)) {
            // Refinanced in the primary market: the principal survives and only its coupon reprices, which
            // calculateInterestExpense() has already done through the blended fixed rate.
            return new \App\DTO\MaturityRollDTO($maturing, true, 0.0, 0.0);
        }

        $repaid = min($maturing, max(0.0, $availableCash - max(0.0, $cashFloor)));
        $shortfall = $maturing - $repaid;

        $stock->setWholesaleDebt((string) max(0.0, $wholesaleDebt - $repaid));

        return new \App\DTO\MaturityRollDTO($maturing, false, $repaid, $shortfall);
    }

    /**
     * Whether the primary market will roll this issuer's maturity at any price.
     */
    private function hasPrimaryMarketAccess(Stock $stock, \App\DTO\DebtHealthDTO $health): bool
    {
        $dynamicSpread = $health->rawMetrics->dynamicSpread ?? (float) $stock->getCreditSpread();
        if ($dynamicSpread >= self::PRIMARY_MARKET_CLOSURE_SPREAD) {
            return false;
        }

        if ($health->interestCoverage < self::REFINANCING_MIN_COVERAGE) {
            return false;
        }

        $ranks = \App\Service\Market\CreditRatingAgency::RATING_RANKS;

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

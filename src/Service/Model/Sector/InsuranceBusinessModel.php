<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\StreamContext;
use App\DTO\InterestExpenseDTO;
use App\DTO\DebtExpansionAppetiteDTO;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Insurance companies.
 * 
 * Financial Physics:
 * - Revenue (Premiums) is highly sticky and predictable.
 * - Variance comes from Catastrophes (Claims/Underwriting losses).
 * - Structural profits come from "The Float" (investing premium cash before it's paid out).
 * - Evaluated on Return on Equity (ROE) rather than ROIC.
 */
class InsuranceBusinessModel extends BaseFinancialBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Premium volume is sticky through the cycle. */
    public const OPERATING_CYCLICALITY = 0.70;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Underwriting, claims and distribution payroll is roughly half of an insurer's overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.50;

    // --- Demand Transmission Lag ---
    /** Years for a move in the output gap to reach the order book. Policies are annual: exposure only reprices as the book comes up for renewal. */
    public const DEMAND_LAG_YEARS = 0.50;

    // --- Reporting Incentives ---
    /** Propensity to steer reported earnings toward consensus with accruals. Loss reserves are an actuarial estimate management sets itself: strengthening or releasing them moves the headline without touching cash. */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.60;

        public function getMoatSpread(): float { return 0.01; }

    // --- The Kenney Rule & Capacity Limits ---
    /** Standard Premium-to-Surplus capacity ratio required to maintain strong credit ratings. */
    public const KENNEY_CAPACITY_RATIO    = 1.50;
    /** Quarters of premium in the ANNUAL written book the Kenney ratio is defined over; sector physics is handed one quarter of it. */
    public const KENNEY_PREMIUM_QUARTERS  = 4.0;
    /** Implied runoff equity fraction of customer deposit float allowed for insolvent insurers. */
    public const IMPLIED_RUNOFF_EQUITY    = 0.10;
    /** Minimum fraction of prior revenue retained during hard market pricing (post-catastrophe capacity support). */
    public const HARD_MARKET_REVENUE_FLOOR = 0.85;

    // --- Solvency & Regulatory Capital Thresholds ---
    /** Minimum capital ratio (Equity / Assets) before regulatory balance sheet insolvency (Equity <= 0). */
    public const BANKRUPT_EQUITY_THRESHOLD = 0.0;
    /** Statutory capital ratio threshold below which insurer enters regulatory capital distress. */
    public const DISTRESS_EQUITY_THRESHOLD = 2.0;
    /** Statutory capital ratio threshold for regulatory grey/early warning watch. */
    public const WARNING_EQUITY_THRESHOLD  = 4.0;

    // --- ROIC & ROE Target Architecture ---
    /** Weight given to historical baseline ROIC when blending with TTM ROE. */
    public const BASELINE_ROIC_WEIGHT     = 0.50;
    /** Weight given to TTM ROE when blending with historical baseline ROIC. */
    public const TTM_ROIC_WEIGHT          = 0.50;
    /** Minimum structural through-the-cycle ROE floor for TTM valuation to prevent catastrophe whipsaw. */
    public const MIN_STRUCTURAL_ROE_FLOOR = 0.03;

    // --- Underwriting & Catastrophe Shock Physics ---
    /** Macroeconomic demand shift sensitivity to output gap. */
    public const MACRO_DEMAND_SCALAR      = 0.25;
    /** Underwriting operating margin mean reversion speed (quarters). Insurance policies renew annually with rapid competitive repricing. */
    public const INSURANCE_REVERSION_SPEED = 8.0;
    /** Volatility multiplier for top-line premium revenue shocks in sticky insurance markets. */
    public const REVENUE_VARIANCE_SCALAR  = 0.05;
    /** Baseline fraction of variable underwriting expenses attributed to operating and policy acquisition expenses (Expense Ratio share). */
    public const BASE_EXPENSE_RATIO_SHARE = 0.35;
    /** Firm claim z-score below which the large-loss layer starts paying (one quarter in fifteen): fires, liability verdicts, single risks no peer shares. */
    public const CATASTROPHE_Z_THRESHOLD  = -1.50;
    /** Large-loss claims, as a share of the book's premium, per standard deviation of the firm's claim draw below the threshold. */
    public const CATASTROPHE_LOSS_SCALAR  = 0.15;

    // --- Underwriting Cycle (Winter 1994 / Gron 1994 capacity constraint) ---
    /** Regime key for the hard market: the multi-year stretch of rate increases and tightened terms that follows a capital shock. */
    public const REGIME_HARD_MARKET = 'hard_market';
    /** Surplus shortfall against the Kenney target at which capacity withdraws and the market turns hard. */
    public const HARD_MARKET_ONSET_SURPLUS_DEFICIT = 0.10;
    /** Quarterly probability the hard market breaks as capital returns and price competition resumes (~10 quarter expected duration). */
    public const HARD_MARKET_EXIT_HAZARD = 0.10;
    /** Peak premium rate uplift in the opening quarters of a hard market, before returning capacity erodes it. */
    public const HARD_MARKET_PRICING_UPLIFT = 0.25;
    /** Quarters over which the rate uplift decays as capital rebuilds, even while the regime itself persists. */
    public const HARD_MARKET_UPLIFT_DECAY_QUARTERS = 8.0;
    /** Premium rate cut per unit of capital held beyond what the market it serves can absorb (the soft half of the same capacity cycle). */
    public const SOFT_MARKET_CAPACITY_BETA = 0.40;
    /** Deepest rate cut price competition inflicts, however overcapitalized the industry becomes. */
    public const SOFT_MARKET_MAX_DISCOUNT = 0.30;
    /** Smallest share of Kenney capacity a going concern still writes at any price: obligatory renewals, treaty shares it cannot walk away from mid-term, and the distribution it must keep alive to have a book to write when rates recover. */
    public const MIN_WRITTEN_CAPACITY = 0.35;
    /** State key holding the capital position the market has actually observed, which is what renewal rates are struck against. */
    public const STATE_OBSERVED_CAPACITY = 'state:capacity:observed_share';
    /** Years for a change in an underwriter's capital to reach the rates it is quoted: statutory filings, rating reviews and annual renewal dates all sit between the two, and that delay is what turns the capacity cycle into a cycle rather than a level. */
    public const CAPACITY_OBSERVATION_LAG_YEARS = 1.50;

    // --- District Catastrophe Losses (industry loss plus basis) ---
    /** Expected catastrophe claims as a share of a property book's premium in an average district year: Verisk's US modelled average annual loss of $117B against $895B of 2024 US P&C premiums earned (~13%). */
    public const DISTRICT_CATASTROPHE_LOAD = 0.13;
    /** District loss burden (average-year units, smoothed) at which the market hardens for every carrier, capital shock or not (the 1992, 2005 and 2017 seasons). */
    public const CAT_HARD_MARKET_THRESHOLD = 3.0;
    /** Cummins & Danzon (1997) soft-market underwriting combined ratio compression sensitivity to high float yields. */
    public const SOFT_MARKET_CYCLE_BETA   = 1.50;

    // --- Event Lore Thresholds ---
    /** Excess claims, in average years of the book's catastrophe load, at which a quarter reads as a systemic disaster (~1.5% of quarters). */
    public const LORE_SYSTEMIC_DISASTER_LOADS = 3.0;
    /** Excess claims, in average years of the book's catastrophe load, at which a quarter's payouts make the news (~one quarter in eleven). */
    public const LORE_ELEVATED_CLAIMS_LOADS   = 1.0;

    // --- Catastrophe Excess-of-Loss Cover ---
    /** Excess claims, as a share of the covered book's premium, the carrier retains before its catastrophe cover attaches (one year in twenty for a primary book). */
    public const MAX_REINSURED_LOSS_SHOCK = 0.375;
    /** Reinstatement premium, as a share of the covered book's premium, paid in the quarter the cover pays out to restore the exhausted limit. */
    public const REINSTATEMENT_PREMIUM_RATE = 0.04;

    // --- Loss Reserve & Investment Portfolio Physics ---
    /** Target operating cash reserve ratio applied to corporate operating base. */
    public const TARGET_OPERATING_BUFFER  = 0.05;
    /** Target operating cash reserve ratio applied to customer deposit float. */
    public const TARGET_FLOAT_BUFFER      = 1.00;
    /** Minimum emergency operating cash reserve ratio applied to corporate operating base. */
    public const MIN_OPERATING_BUFFER     = 0.03;
    /** Minimum emergency operating cash reserve ratio applied to customer deposit float. */
    public const MIN_FLOAT_BUFFER         = 0.15;
    /** Threshold ratio of excess cash over total debt triggering hoarder status. */
    public const HOARDER_THRESHOLD        = 0.20;
    /** Threshold ratio of excess cash over total debt triggering mega-hoarder status. */
    public const MEGA_HOARDER_THRESHOLD   = 0.50;

    // --- Buybacks & Capital Deployment ---
    /** Fraction of excess cash allocated to buybacks for mega-hoarder insurers. */
    public const MEGA_BUYBACK_CASH_SHARE  = 0.30;
    /** Fraction of excess cash allocated to buybacks for standard insurers. */
    public const STANDARD_BUYBACK_SHARE   = 0.15;
    /** Maximum buyback spend multiplier relative to quarterly retained earnings. */
    public const MAX_RETAINED_BUYBACK_MULT = 0.40;
    /** Infinite interest coverage fallback for insurance companies without operating debt. */
    public const INFINITE_ICR_FALLBACK    = 999.0;
    /** Baseline probability of initiating debt expansion when spreads are neutral. */
    public const DEBT_EXPANSION_BASE_PROB = 0.40;
    /** Multiplier scaling debt expansion probability with spread attractiveness. */
    public const DEBT_EXPANSION_PROB_MULT = 0.30;
    /** Baseline aggressiveness fraction for new debt issuance. */
    public const DEBT_EXPANSION_BASE_AGGR = 0.02;
    /** Multiplier scaling debt issuance aggressiveness with spread attractiveness. */
    public const DEBT_EXPANSION_AGGR_MULT = 0.08;

    // --- Float Portfolio Yield (15/75/10 Allocation) ---
    /** Default policy rate fallback when macroeconomic state data is missing. */
    public const DEFAULT_POLICY_RATE_FALLBACK = 0.02;
    /** Equity market return sensitivity to macroeconomic output gaps. */
    public const EQUITY_RETURN_GAP_MULT   = 1.50;
    /** Weight allocated to short-term T-Bills and liquid cash in float portfolios. */
    public const FLOAT_LIQUIDITY_WEIGHT   = 0.15;
    /** Weight allocated to core long-duration fixed income bonds in float portfolios. */
    public const FLOAT_BOND_WEIGHT        = 0.75;
    /** Weight allocated to public growth equities in float portfolios. */
    public const FLOAT_EQUITY_WEIGHT      = 0.10;
    /** VIX threshold above which catastrophe-correlated equity portfolio losses begin. Market panic = equity crashes. */
    public const CATASTROPHE_VIX_THRESHOLD = 0.25;
    /** Sensitivity of equity portfolio drag to VIX above threshold. A VIX of 0.85 (COVID) causes ~18% tranche drag. */
    public const CATASTROPHE_EQUITY_CORRELATION = 0.30;

    // --- Valuation & Fair Value Weights ---
    /** Weight given to book value in profitable quarters (50% Book / 35% Earnings / 15% DDM). */
    public const FAIR_VALUE_BOOK_POS_EPS        = 0.50;
    /** Franchise floor multiplier applied to revenue floor value for sticky premium & float franchise. */
    public const PREMIUM_FRANCHISE_FLOOR_MULT   = 0.70;

    // --- Loss Reserves (the float as a stock) ---
    /** Float per unit of annual net premium earned on a US P&C book: NAIC 2024 loss and LAE reserves $977B on NPE $905B (1.08), plus half a year's written premium unearned on annual policies (0.52). */
    public const RESERVE_TO_PREMIUM_RATIO = 1.60;
    /** Years of renewals an underwriter weighs the float a new book brings against its underwriting result: the annual policy term. */
    public const FLOAT_DECISION_HORIZON_YEARS = 1.0;
    /** State key holding the quarter's net incurred claims, on which the reserve stock is rolled forward. */
    public const STATE_INCURRED_CLAIMS = 'state:reserves:incurred_claims';

    // --- Investment Portfolio Duration ---
    /** Macaulay duration of the long bond tranche of float, matched against liabilities an insurer pays out over decades. */
    public const DEFAULT_FLOAT_BOND_DURATION_YEARS = 7.0;

    /**
     * Reverse engineers the required operating metrics based on Balance Sheet Capacity.
     * Insurance revenue (Premiums) is strictly constrained by Surplus Equity (The Kenney Rule).
     *
     * @param Stock       $stock       The insurance stock entity being evaluated.
     * @param MacroStateDTO $macroState  The current macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility for engine operations.
     * @return array{invested_capital: float, baseline_roic: float} Target operating metrics.
     */
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        // Underwriting capacity is rationed against statutory surplus: bonds at amortized cost, goodwill at nil.
        $equity = $stock->getStatutorySurplus();
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // 1. Capacity Constraint: clamp revenue to physical capital capacity (Kenney ratio = 1.5x).
        $capacityRatio = self::KENNEY_CAPACITY_RATIO;
        // Prevent zombie state: Regulators allow insolvent insurers to operate in runoff using a fraction of their float as implied equity
        $impliedRunoffEquity = (float) $stock->getCustomerDeposits() * self::IMPLIED_RUNOFF_EQUITY;
        $operatingEquity = max(max(1.0, $impliedRunoffEquity), $equity);

        $taxRate = $macroState->corporateTaxRate;

        // 2. Structural Revenue anchored to written capacity and solvency limits.
        $targetRevenue = $operatingEquity * $capacityRatio
            * $this->resolveWrittenCapacity($stock, $macroState, $mathUtility, $operatingEquity, $capacityRatio * $stableMargin * (1.0 - $taxRate));
        // Hard market floor prevents abrupt revenue collapse following catastrophe-driven surplus drawdown.
        $priorRevenue = (float) $stock->getTotalRevenue();
        $targetSurplusForPriorRevenue = $priorRevenue / $capacityRatio;
        $surplusAdequacy = $targetSurplusForPriorRevenue > 0.0 ? min(1.0, $operatingEquity / $targetSurplusForPriorRevenue) : 1.0;
        $targetRevenue = max($targetRevenue, $priorRevenue * self::HARD_MARKET_REVENUE_FLOOR * $surplusAdequacy);

        // 3. The engine requires Baseline ROIC, which implies a specific Asset Turnover.
        // Turnover = Revenue / Invested Capital
        $impliedTurnover = $targetRevenue / max(1.0, $operatingEquity);
        $capacityRoic = ($impliedTurnover * $stableMargin) * (1.0 - $taxRate);
        $baselineRoic = $capacityRoic;

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            // Apply structural floor locally when deriving baseline capacity return so catastrophe losses do not collapse required underwriting turnover
            $structuralRoe = max(self::MIN_STRUCTURAL_ROE_FLOOR, $ttmRoe);
            // Clamp baseline return to capacity ROIC to prevent equity float leverage from distorting premium turnover.
            $baselineRoic = min($capacityRoic, ($capacityRoic * self::BASELINE_ROIC_WEIGHT) + ($structuralRoe * self::TTM_ROIC_WEIGHT));
        }

        // Saturation measures the firm's size in its market, so it reads the whole book, goodwill included.
        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $stock->getAmortizedCostEquity()), $macroState);
        $waccBase = $macroState->yield10yEma + $macroState->equityRiskPremium;
        // Cap baseline return at capacity ROIC to prevent margin compression from inflating implied turnover.
        $baselineRoic = min($capacityRoic, max($waccBase, $baselineRoic - $saturationPenalty));

        return [
            'invested_capital' => $operatingEquity,
            'baseline_roic'    => $baselineRoic
        ];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);

        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR, // Highly immune to macro demand
            'pricing_power_multiplier' => $this->resolvePremiumRateLevel($stock, $macroState),
            // Neutral input cost multiplier; property/claim inflation is handled directly in loss ratio physics.
            'input_cost_multiplier' => 1.0,
        ];
    }

    /**
     * The premium rate level the market is offering this firm, as a multiple of the rate its structural
     * margin was struck at: the hard market's uplift, the Cummins & Danzon (1997) float-yield discount, and
     * the soft market's capacity discount, composed.
     *
     * Separate from getMacroPhysics() because the underwriting decision needs the rate BEFORE it decides
     * how much to write, and getMacroPhysics() advances the firm's demand lag as a side effect — reading
     * the rate through it twice in a quarter would age the lag twice.
     */
    protected function resolvePremiumRateLevel(Stock $stock, MacroStateDTO $macroState): float
    {
        // Cash-flow underwriting (Cummins & Danzon 1997): premium is the discounted value of losses, so a policy
        // rate above the neutral rate softens rates and one below it hardens them. At neutral the book prices at par.
        $softMarketRateDiscount = ($macroState->policyRateEma - $macroState->perceivedNeutralRate) * self::SOFT_MARKET_CYCLE_BETA;

        // The capacity cycle sits on top of it and dominates. The regime clock is advanced by this model's
        // own physics (see calculateSectorPhysics) and read back here a quarter later, which is right:
        // rates reset at renewal, after the loss that withdrew the capacity.
        return 1.0 + $this->resolveHardMarketUplift($stock) - min(0.50, $softMarketRateDiscount)
            - $this->resolveSoftMarketCapacityDiscount($stock, $macroState);
    }

    /**
     * Winter (1994) / Gron (1994): the underwriting cycle is a CAPACITY cycle, and it runs in both
     * directions. Capital destroyed by a catastrophe withdraws capacity and hardens rates — the half
     * resolveHardMarketUplift() carries. Capital ACCUMULATED past what the market can absorb does the
     * opposite: it competes for the same premium and rates soften until the industry's return falls back
     * to its cost of capital. Without this half an insurer that retains its earnings simply keeps them,
     * because nothing prices the glut it is creating, and book value compounds at ROE x retention forever.
     *
     * The glut is measured as capital against the market it serves rather than against the firm's own
     * book: premium is pinned at the Kenney ratio to surplus, so an insurer's own premium-to-surplus can
     * never show a surplus of capital — only its share of serviceable demand can. That is the same scale
     * ratio the saturation physics reads, against the same optimal-share threshold.
     */
    protected function resolveSoftMarketCapacityDiscount(Stock $stock, MacroStateDTO $macroState): float
    {
        $capacityShare = $this->resolveCapacityShare($stock, $macroState);

        // Rates are quoted against the capital the market has SEEN, which is where the cycle comes from:
        // capital built through a soft market is not priced until it has been filed, rated and renewed
        // against, so the industry overshoots in both directions instead of settling at a level.
        $observedShare = (float) ($stock->getEarningsMomentumZ()[self::STATE_OBSERVED_CAPACITY] ?? $capacityShare);

        $optimalShare = \App\Service\Math\FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        $excessCapacity = max(0.0, ($observedShare - $optimalShare) / max(0.01, 1.0 - $optimalShare));

        return min(self::SOFT_MARKET_MAX_DISCOUNT, $excessCapacity * self::SOFT_MARKET_CAPACITY_BETA);
    }

    /**
     * Underwriting discipline: the share of its Kenney capacity the firm chooses to write at the rate the
     * market is offering.
     *
     * Capacity says how much an underwriter MAY write; it does not say it should. A soft market prices
     * cover below what the exposure costs, and the answer to that is to write less of it — the capacity
     * withdrawal that is the other half of Winter's (1994) cycle, and the reason industry premium-to-surplus
     * is procyclical with rates. Without it a firm is forced to write its whole book into a loss and its
     * only lever is how much capital it hands back.
     *
     * The rule is the same one every other deployment gate in this engine applies: write while the capital
     * that business consumes still earns its hurdle. An insurer's return has two parts. The float it already
     * holds runs off whether or not another treaty is signed; the book it writes brings new float, and over
     * one policy term a share 1 − e^(−H/τ) of that book's settled reserves (total-return ratemaking, Myers &
     * Cohn 1987). Over that horizon:
     *
     *     u * (underwritingReturn + bookFloatReturn) + heldFloatReturn >= appliedHurdle
     *
     * Where the book's own return, float included, is positive the answer is the whole book. Below it the
     * equality gives the fraction directly, with no free parameter: a firm whose held float covers its hurdle
     * handsomely can absorb a soft market and keep writing (cash-flow underwriting), one whose float barely
     * covers it cannot. The manager's own hurdle bias applies, so an empire builder keeps writing into a
     * market a fortress has already withdrawn from, which is Jensen's agency cost in its underwriting form.
     */
    protected function resolveWrittenCapacity(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, float $operatingEquity, float $capacityReturnAtNeutralRates): float
    {
        $pricingPower = $this->resolvePremiumRateLevel($stock, $macroState);
        if ($pricingPower <= 0.0 || $operatingEquity <= 0.0) {
            return 1.0;
        }

        // What the book earns at the offered rate. Premium scales with the rate and claims do not, so the
        // margin on a 95.5% book written 10% below the rate it was priced at is negative, not 4.5% smaller.
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        $marginAtOfferedRate = 1.0 - ((1.0 - $stableMargin) / $pricingPower);
        $underwritingReturn = $capacityReturnAtNeutralRates * ($marginAtOfferedRate / $stableMargin);

        $afterTax = 1.0 - $macroState->corporateTaxRate;
        $floatYield = $this->resolveFloatYield($stock, $macroState);
        $heldAfterHorizon = exp(-self::FLOAT_DECISION_HORIZON_YEARS / $this->resolveReserveRunoffYears($stock));

        $bookFloatReturn = $floatYield * $afterTax * $this->resolveReserveToPremiumRatio($stock) * self::KENNEY_CAPACITY_RATIO * (1.0 - $heldAfterHorizon);
        $bookReturn = $underwritingReturn + $bookFloatReturn;
        if ($bookReturn >= 0.0) {
            return 1.0;
        }

        $floatIncome = min((float) $stock->getCorporateTreasury(), (float) $stock->getCustomerDeposits()) * $floatYield;
        $heldIncome = $this->calculateInterestIncome($stock, $macroState, $mathUtility) - ($floatIncome * (1.0 - $heldAfterHorizon));
        $heldFloatReturn = ($heldIncome * $afterTax) / $operatingEquity;
        $hurdle = $stock->getManagementProfile()->appliedHurdle($macroState->yield10yEma + $macroState->equityRiskPremium);

        return max(self::MIN_WRITTEN_CAPACITY, min(1.0, ($hurdle - $heldFloatReturn) / $bookReturn));
    }

    /**
     * The quarter's claims on a book above its structural loss ratio, as a share of that book's premium.
     *
     * Industry loss plus basis (Cummins, Lalonde & Phillips 2004): the book's share of the district's
     * catastrophe losses, which every carrier pays together, plus a large-loss layer on the firm's own
     * claim draw. The structural margin is the through-the-cycle margin, catastrophe load included, so both
     * terms are centred: the district burden averages one, and the layer's expected payout is deducted
     * (the unit layer's expectation is the normal lower partial moment). Seasonality arrives through the
     * district calendar alone.
     *
     * 'gross' is the uncentred loss the catastrophe cover measures its retention against; 'excess' is what
     * moves the loss ratio.
     *
     * @return array{excess: float, gross: float, claimZ: float}
     */
    protected function resolveExcessClaims(StreamContext $streams, MacroStateDTO $macroState, float $bookWeight, float $catThreshold, float $catScalar): array
    {
        $claimZ = $streams->generateExogenousZ('claim', 0.05);

        $layerScale = $bookWeight * $catScalar;
        $largeLoss = $layerScale * max(0.0, $catThreshold - $claimZ);
        $expectedLargeLoss = $layerScale * MathUtility::getInstance()->calculateNormalLowerPartialMoment($catThreshold);
        $districtLoss = $bookWeight * static::DISTRICT_CATASTROPHE_LOAD * ($macroState->catastropheLossIndexEma - 1.0);

        return [
            'excess' => $largeLoss + $districtLoss - $expectedLargeLoss,
            'gross'  => $largeLoss + $districtLoss,
            'claimZ' => $claimZ,
        ];
    }

    /** What the catastrophe excess-of-loss cover on a book pays: every claim above the retention. */
    protected function resolveCatastropheRecovery(float $grossExcessClaims, float $bookWeight): float
    {
        return max(0.0, $grossExcessClaims - (self::MAX_REINSURED_LOSS_SHOCK * $bookWeight));
    }

    /** The claim headline a quarter's losses make, sized against the book's own average catastrophe year. */
    protected function resolveClaimEvent(float $grossExcessClaims, float $bookWeight, bool $coverAttached): ?string
    {
        $averageYearLoad = $bookWeight * static::DISTRICT_CATASTROPHE_LOAD;

        return match (true) {
            $coverAttached => ShockEvent::REINSURANCE_ATTACHMENT_BREACH,
            $grossExcessClaims >= $averageYearLoad * self::LORE_SYSTEMIC_DISASTER_LOADS => ShockEvent::CATASTROPHIC_CLAIM_LOSSES,
            $grossExcessClaims >= $averageYearLoad * self::LORE_ELEVATED_CLAIMS_LOADS => ShockEvent::ELEVATED_CLAIM_PAYOUTS,
            default => null,
        };
    }

    /**
     * Advances the hard-market clock: the multi-year stretch of rate increases that follows a capital shock.
     *
     * The underwriting cycle is a CAPACITY cycle, not a rate cycle. A catastrophe destroys surplus, capacity
     * withdraws from the market, rates harden for years, and the returning capital the hard market attracts
     * is what eventually softens them again (Winter 1994, Gron 1994). Modelling it as a persistent regime
     * rather than a function of this quarter's surplus is the point: rates stay hard well after the capital
     * is back, which is the discipline lag the cycle is named for.
     *
     * Called by every insurance model's own physics, because the subclasses replace calculateSectorPhysics()
     * outright. The regime's rate uplift reaches the P&L only through the pricing-power multiplier.
     */
    protected function advanceUnderwritingCycle(StreamContext $streams, Stock $stock, MacroStateDTO $macroState, float $surplusDeficitRatio, bool $coverAttached): void
    {
        $streams->evolveRegime(self::REGIME_HARD_MARKET, 0.0, self::HARD_MARKET_EXIT_HAZARD);

        if (
            $surplusDeficitRatio >= self::HARD_MARKET_ONSET_SURPLUS_DEFICIT
            || $coverAttached
            || $macroState->catastropheLossIndexEma >= self::CAT_HARD_MARKET_THRESHOLD
        ) {
            $streams->startRegime(self::REGIME_HARD_MARKET);
        }

        // What the market will have observed of this firm's capital by the time it quotes again.
        $currentShare = $this->resolveCapacityShare($stock, $macroState);
        $streams->registerState(self::STATE_OBSERVED_CAPACITY, MathUtility::getInstance()->calculateDistributedLag(
            currentLaggedValue: $streams->getPersistedState(self::STATE_OBSERVED_CAPACITY, $currentShare),
            targetValue: $currentShare,
            dt: \App\Service\Corporate\EarningsEngine::QUARTERLY_TIME_STEP,
            lagTimeConstant: self::CAPACITY_OBSERVATION_LAG_YEARS
        ));
    }

    /** The firm's capital measured against the market it serves, on the base the saturation physics uses. */
    protected function resolveCapacityShare(Stock $stock, MacroStateDTO $macroState): float
    {
        return \App\Service\Math\CorporateMetrics::getInstance()->calculateScaleRatio(
            $this->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital()),
            $macroState->nominalGdpIndex,
            (float) $stock->getSamRatio()
        );
    }

    /**
     * How far an underwriter's surplus has fallen below the capital the book it is writing requires.
     *
     * The Kenney ratio is premium to surplus over a YEAR, and sector physics is handed one QUARTER's
     * premium: comparing equity against a quarter of the book asked whether the firm had lost three
     * quarters of its capital, so the capacity trigger only ever fired on the edge of insolvency. Measured
     * on the reinsurance book it fired in 0 of 240 quarters — the hard market could be reached by
     * catastrophe alone, and the capital channel this ratio exists to carry was dead.
     */
    protected function resolveSurplusDeficitRatio(float $quarterlyExpectedRevenue, float $equity): float
    {
        $targetSurplus = ($quarterlyExpectedRevenue * self::KENNEY_PREMIUM_QUARTERS) / self::KENNEY_CAPACITY_RATIO;

        return $targetSurplus > 0.0 ? max(0.0, ($targetSurplus - $equity) / $targetSurplus) : 0.0;
    }

    /**
     * Premium rate uplift from an active hard market, decaying over the quarters the regime has run.
     *
     * Read from the persisted regime clock rather than a stream context, because pricing is resolved
     * before this quarter's physics: what an insurer can charge at renewal is set by the capital position
     * the market was left in, not by a loss that has not happened yet.
     */
    private function resolveHardMarketUplift(Stock $stock): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $quarters = (int) round((float) ($momentum[StreamContext::REGIME_STATE_PREFIX . self::REGIME_HARD_MARKET] ?? 0.0));

        if ($quarters <= 0) {
            return 0.0;
        }

        $decay = max(0.0, 1.0 - (($quarters - 1) / self::HARD_MARKET_UPLIFT_DECAY_QUARTERS));

        return self::HARD_MARKET_PRICING_UPLIFT * $decay;
    }

    /**
     * Models the "Catastrophe Physics". Premium top-line revenue barely moves,
     * but cost margins can explode due to unpredictable massive claim payouts (Hurricanes, Mass Torts).
     *
     * @param Stock       $stock                  The insurance stock entity.
     * @param float       $expectedRevenue        The baseline expected revenue.
     * @param float       $realizedVariableMargin The expected variable cost margin.
     * @param float       $fixedCosts             The absolute fixed costs of operations.
     * @param float       $baselineVol            The stock's historical volatility.
     * @param MacroStateDTO $macroState             The current macroeconomic state.
     * @param MathUtility $mathUtility            Mathematical utility for Z-score generation.
     * @return SectorPhysicsResult
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Resolve company-specific tuned underwriting parameters
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CatastropheZThreshold->value => self::CATASTROPHE_Z_THRESHOLD,
            ModelParam::CatastropheLossScalar->value => self::CATASTROPHE_LOSS_SCALAR,
        ]);

        $catThreshold = $params[ModelParam::CatastropheZThreshold];
        $catScalar    = $params[ModelParam::CatastropheLossScalar];

        // 1. Premium Revenue Shock
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // Premium volume loads on the firm and sector demand factors; claims are the firm's own large losses
        // plus its share of the district's storm season, which every carrier pays together.
        $revenueZ = $streams->generateZ('revenue', 0.25);
        $claims   = $this->resolveExcessClaims($streams, $macroState, 1.0, $catThreshold, $catScalar);

        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // 2. Separation of Loss Ratio vs. Expense Ratio (The Combined Ratio)
        // Baseline decomposition: Total variable cost margin is composed of Loss Ratio (claims) + Expense Ratio (acquisition/admin).
        $baselineExpenseRatio = $realizedVariableMargin * self::BASE_EXPENSE_RATIO_SHARE;
        $baselineLossRatio    = $realizedVariableMargin * (1.0 - self::BASE_EXPENSE_RATIO_SHARE);

        // A. Expense Ratio Dynamics:
        // Policy acquisition and administrative overhead costs are sticky relative to expected baseline revenue.
        // If top-line revenue fluctuates, the realized expense ratio scales inversely with written premiums.
        $expenseScale = $expectedRevenue > 0.0 ? ($expectedRevenue / max(1.0, $actualRevenue)) : 1.0;
        $realizedExpenseRatio = $baselineExpenseRatio * $expenseScale;

        // B. Loss Ratio: the catastrophe cover takes everything above the retention and charges a reinstatement
        // premium to restore the limit it used.
        $recovery = $this->resolveCatastropheRecovery($claims['gross'], 1.0);
        $reinstatementPremium = $recovery > 0.0 ? self::REINSTATEMENT_PREMIUM_RATE : 0.0;
        $realizedLossRatio = max(0.0, $baselineLossRatio + $claims['excess'] - $recovery);

        // A capital shortfall hardens rates through the regime this advances, which reaches revenue and the cost
        // ratio through the pricing-power multiplier; it is not discounted from the combined ratio again here.
        $surplusDeficitRatio = $this->resolveSurplusDeficitRatio($expectedRevenue, (float) $stock->getTotalEquity());
        $this->advanceUnderwritingCycle($streams, $stock, $macroState, $surplusDeficitRatio, $recovery > 0.0);

        $combinedRatio = $realizedLossRatio + $realizedExpenseRatio + $reinstatementPremium;
        $clampedMargin = $this->clampMargin($combinedRatio);
        $this->registerIncurredClaims($streams, $actualRevenue, $clampedMargin - $reinstatementPremium);

        $eventType = $this->resolveClaimEvent($claims['gross'], 1.0, $recovery > 0.0);

        // Only a draw inside the large-loss layer is a structural shock to the firm.
        $structuralClaimShock = $claims['claimZ'] < $catThreshold ? $claims['claimZ'] : 0.0;
        $primaryShockZ = $streams->resolveDominantShockZ([$structuralClaimShock, $revenueZ]);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: 0.0,
            eventType: $eventType,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'premium_revenue' => $actualRevenue,
            ],
        );
    }

    public function getMarginReversionSpeed(): float
    {
        // 12-month annual policy contracts face rapid competitive repricing at each renewal cycle
        return self::INSURANCE_REVERSION_SPEED;
    }

    /**
     * Insurance companies invest their massive Float in long-duration bonds.
     * The 10% equity tranche introduces stochastic quarterly returns and correlates with
     * catastrophe events via VIX: major disasters cause concurrent market crashes (9\/11, COVID).
     *
     * @param Stock       $stock       The insurance stock entity.
     * @param MacroStateDTO $macroState  The current macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility.
     * @return float The total absolute interest income generated by the portfolio.
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $cash       = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState->policyRateEma;
        $floatYield = $this->resolveFloatYield($stock, $macroState);

        $policyholderFloat = (float) $stock->getCustomerDeposits();
        $investableFloat = min($cash, $policyholderFloat);
        $excessCash = max(0.0, $cash - $investableFloat);

        $moneyMarketYield = max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);

        return ($investableFloat * $floatYield) + ($excessCash * $moneyMarketYield);
    }

    /** The annual yield on a unit of investable float across the liquidity, bond and equity tranches. */
    protected function resolveFloatYield(Stock $stock, MacroStateDTO $macroState): float
    {
        // Resolve company-specific tuned float allocation parameters
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FloatEquityWeight->value => self::FLOAT_EQUITY_WEIGHT,
        ]);

        $floatEquityWeight = $params[ModelParam::FloatEquityWeight];
        $policyRate        = $macroState->policyRateEma;
        $yield10y          = $macroState->yield10yEma;

        // Normalized Base Fixed-Income Yield (Liquidity + Long Bonds scaled to sum to 1.0 - float_equity_weight)
        $fixedIncomeWeight = max(0.0, 1.0 - $floatEquityWeight);
        $totalFixedWeight  = max(0.01, self::FLOAT_LIQUIDITY_WEIGHT + self::FLOAT_BOND_WEIGHT);
        $liquidityShare    = self::FLOAT_LIQUIDITY_WEIGHT / $totalFixedWeight;
        $bondShare         = self::FLOAT_BOND_WEIGHT / $totalFixedWeight;

        $liquidityReturn   = $policyRate - MacroEngine::CASH_YIELD_SPREAD;
        // Net investment income accrues at book yield (SAP/GAAP amortized cost); the rate move is the AOCI mark
        // on the same tranche (resolveSecuritiesBook), so earning the live 10y here would count it twice.
        $bondReturn        = $stock->getSecuritiesCarryingYield() ?? $yield10y;
        $baseYield         = $fixedIncomeWeight * (($liquidityShare * $liquidityReturn) + ($bondShare * $bondReturn));

        $outputGap = $macroState->outputGapEma;
        $erp = $macroState->equityRiskPremium;

        // Expected equity tranche return during continuous tick valuation ($equityPortfolioZ = 0.0).
        // This prevents high-frequency distress penalty whipsaws when analyzeDebtHealth() evaluates float income.
        $stochasticEquityReturn = ($policyRate + $erp) + ($outputGap * self::EQUITY_RETURN_GAP_MULT);

        // Catastrophe-Equity Correlation:
        // Major disasters (9/11, COVID, GFC) simultaneously cause high claims AND equity market crashes.
        // VIX is a reliable real-time proxy: panic-level VIX (>25%) reliably accompanies both catastrophes
        // and broad equity drawdowns. This correlation is the channel the model exploits.
        $vixEma = $macroState->marketVolatilityEma;
        $catastropheEquityPenalty = max(0.0, ($vixEma - self::CATASTROPHE_VIX_THRESHOLD) * self::CATASTROPHE_EQUITY_CORRELATION);
        $stochasticEquityReturn -= $catastropheEquityPenalty;

        return $baseYield + ($floatEquityWeight * $stochasticEquityReturn);
    }

    /**
     * An underwriter that declines to write at the rate on offer is holding surplus behind a book it is not
     * writing, and that surplus is redundant until rates recover. Handing it back is what closes the
     * capacity cycle: the industry's capital falls, the glut it was pricing clears, rates harden, and the
     * capital that comes back is deployed into a book worth writing. A firm that keeps it instead simply
     * accumulates until it is a fund with an insurance licence.
     *
     * The written share is struck against the same hurdle the writing decision uses, so the two cannot
     * disagree: whatever fraction of capacity fails that test is the fraction of surplus this returns.
     */
    public function getUndeployableCapitalShare(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        $operatingEquity = max(1.0, (float) $stock->getTotalEquity());
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        $capacityReturn = self::KENNEY_CAPACITY_RATIO * $stableMargin * (1.0 - $macroState->corporateTaxRate);

        return max(0.0, min(1.0, 1.0 - $this->resolveWrittenCapacity($stock, $macroState, $mathUtility, $operatingEquity, $capacityReturn)));
    }

    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float
    {
        // Structural floor: float investment income provides a through-the-cycle baseline
        // that catastrophe underwriting losses should not erase entirely.
        $equity = (float) $stock->getTotalEquity();
        $roeTtm = (float) $stock->getRoeTtm();
        $shares = max(1.0, (float) $stock->getSharesOutstanding());
        $structuralEps = $equity > 0 ? (($equity * max(0.0, $roeTtm)) / 4.0) / $shares : 0.0;

        // If quarterly EPS is negative and capital surplus is impaired (equity below Kenney target surplus),
        // or if quarterly losses exceed structural float earnings, halt distributions to preserve solvency.
        $targetSurplus = (float) $stock->getTotalRevenue() / self::KENNEY_CAPACITY_RATIO;
        if ($quarterlyEps < 0.0 && ($equity < $targetSurplus || abs($quarterlyEps) > $structuralEps)) {
            return 0.0;
        }

        // Use the higher of actual EPS and structural through-cycle EPS,
        // but NEVER exceed actual EPS when actual is positive (don't overpay)
        return $quarterlyEps > 0 ? $quarterlyEps : max($quarterlyEps, $structuralEps * 0.50);
    }

    /**
     * Calculates the target operating cash required for the insurance business.
     *
     * @param float $operatingBase    The base operating expenses.
     * @param float $currentLiability The current liabilities (customer deposits / float).
     * @param float $wholesaleDebt    The wholesale debt balance.
     * @return float The target operating cash to maintain.
     */
    /** Float above the regulatory surplus buffer is invested, not held. */
    public function deploysFundingIntoEarningAssets(): bool
    {
        return true;
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        // Target 100% of Customer Deposits to maintain a strict regulatory surplus buffer.
        // This prevents the company from buying back shares until they have a solid safety net against catastrophes.
        return max($operatingBase * self::TARGET_OPERATING_BUFFER, $currentLiability * self::TARGET_FLOAT_BUFFER);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * self::MIN_OPERATING_BUFFER, $currentLiability * self::MIN_FLOAT_BUFFER);
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($totalDebt * self::HOARDER_THRESHOLD),
            'is_mega_hoarder' => $excessCash > ($totalDebt * self::MEGA_HOARDER_THRESHOLD),
        ];
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * self::MEGA_BUYBACK_CASH_SHARE : min($excessCash * self::STANDARD_BUYBACK_SHARE, $retainedEarningsThisQuarter * self::MAX_RETAINED_BUYBACK_MULT);
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt, ?\App\DTO\MacroStateDTO $macroState = null): InterestExpenseDTO
    {
        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $interestExpense = ($corporateDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($corporateDebt * $floatingRatio * $floatingInterestRate);
        return new InterestExpenseDTO(interestExpense: $interestExpense, wholesaleRate: $corporateDebt > 0 ? ($interestExpense / $corporateDebt) : $currentMarketFixedRate);
    }

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float
    {
        if ($interestExpense <= 0.0) {
            return self::INFINITE_ICR_FALLBACK;
        }

        // For an insurer, quarterly underwriting claim payouts (EBIT < 0) do not constitute corporate debt default
        // as long as investment/float yield and capital surplus service wholesale obligations.
        // When underwriting claim payouts turn total EBIT negative, evaluate coverage using depreciation and serviceable income.
        $serviceableIncome = $ebit < 0.0 ? max(0.0, $ebit + $interestExpense) : $ebit;
        return ($serviceableIncome + $depreciation) / max(0.01, $interestExpense);
    }

    /**
     * Returns the base float yield for the 15% liquidity + 75% long-duration bond tranches only.
     * The 10% equity tranche is handled stochastically in calculateInterestIncome().
     *
     * @param MacroStateDTO $macroState Current macroeconomic state.
     * @param float $policyRate Current policy interest rate.
     * @return float Blended base yield from the fixed-income portion of the float portfolio.
     */
    public function calculateCashYield(MacroStateDTO $macroState): float
    {
        $policyRate = $macroState->policyRateEma;
        $yield10y = $macroState->yield10yEma;

        // 1. Liquidity Reserve (15% T-Bills/Cash)
        $liquidityReturn = $policyRate - MacroEngine::CASH_YIELD_SPREAD;

        // 2. Core Fixed Income (75% Long-Duration Bonds)
        $bondReturn = $yield10y;

        // Normalized fixed-income yield (assuming baseline 10% equity tranche)
        $fixedIncomeWeight = max(0.0, 1.0 - self::FLOAT_EQUITY_WEIGHT);
        $totalFixedWeight  = max(0.01, self::FLOAT_LIQUIDITY_WEIGHT + self::FLOAT_BOND_WEIGHT);
        $liquidityShare    = self::FLOAT_LIQUIDITY_WEIGHT / $totalFixedWeight;
        $bondShare         = self::FLOAT_BOND_WEIGHT / $totalFixedWeight;

        return $fixedIncomeWeight * (($liquidityShare * $liquidityReturn) + ($bondShare * $bondReturn));
    }

    /**
     * The long-duration bond tranche of investable float.
     *
     * Struck on the same allocation calculateInterestIncome() earns the coupon on, so the book that is
     * marked and the book that pays are one book. The liquidity tranche is bills and the equity tranche is
     * already stochastic in the income function; neither belongs to the curve, so neither is marked here.
     */
    public function resolveSecuritiesBook(Stock $stock, ?float $currentTreasury = null): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FloatEquityWeight->value => self::FLOAT_EQUITY_WEIGHT,
        ]);

        $cash = $currentTreasury ?? (float) $stock->getCorporateTreasury();
        $investableFloat = min($cash, (float) $stock->getCustomerDeposits());

        if ($investableFloat <= 0.0) {
            return 0.0;
        }

        $fixedIncomeWeight = max(0.0, 1.0 - $params[ModelParam::FloatEquityWeight]);
        $totalFixedWeight  = max(0.01, self::FLOAT_LIQUIDITY_WEIGHT + self::FLOAT_BOND_WEIGHT);

        return $investableFloat * $fixedIncomeWeight * (self::FLOAT_BOND_WEIGHT / $totalFixedWeight);
    }

    /** A float portfolio is fixed-coupon paper. It owns every move in the curve. */
    public function getSecuritiesFloatingShare(Stock $stock): float
    {
        return 0.0;
    }

    /**
     * Long. An insurer matches assets to liabilities it will not pay for decades, so the bond tranche is
     * bought at the long end and is the most rate-sensitive book on the board.
     */
    public function getDefaultSecuritiesDuration(): float
    {
        return self::DEFAULT_FLOAT_BOND_DURATION_YEARS;
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): DebtExpansionAppetiteDTO
    {
        return new DebtExpansionAppetiteDTO(probability: self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), aggressiveness: self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier));
    }

    /**
     * Insurance companies do not deploy physical CapEx. Underwriting capacity is governed by Surplus Equity
     * (Kenney Rule) and float yield is earned on Corporate Treasury cash, so organic expansion spend is 0.0.
     */
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return 0.0;
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        // Insurance companies should fund expansion using their premium float (excess cash) first
        return max(0.0, $baseCapacity - $excessCash);
    }
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue): float
    {
        $franchiseFloor = $revenueFloorValue * self::PREMIUM_FRANCHISE_FLOOR_MULT;
        return max($franchiseFloor, $peFairValue);
    }

    /** An underwriter trades on earnings and book together while profitable, and wholly on book through a loss. */
    protected function getFairValueBookWeight(float $normalizedEps): float
    {
        return $normalizedEps > 0.0 ? self::FAIR_VALUE_BOOK_POS_EPS : 1.0;
    }

    /** The through-the-cycle capacity blend in calculateStructuralRoic() is this model's persistence; the trailing return reaches it unfaded. */
    public function getValuationReturn(float $trailingReturn, ?float $longRunReturn, float $discountRate): float
    {
        return $trailingReturn;
    }

    public function calculateStructuralRoic(float $roicTtm, float $baselineRoic, float $revenuePerShare, float $bookValuePerShare, float $baselineMargin): float
    {
        // DuPont Decomposition anchored by Kenney Rule capacity (Premium-to-Surplus ratio = 1.50)
        $actualTurnover = $bookValuePerShare > 0.0 ? ($revenuePerShare / $bookValuePerShare) : self::KENNEY_CAPACITY_RATIO;
        $effectiveTurnover = min(self::KENNEY_CAPACITY_RATIO, max(self::MIN_WRITTEN_CAPACITY * self::KENNEY_CAPACITY_RATIO, $actualTurnover));

        // Separate underwriting return from float investment return to preserve investment income capacity.
        $fullCapacityUnderwriting = self::KENNEY_CAPACITY_RATIO * $baselineMargin;
        $investmentLeg = max(0.0, $baselineRoic - $fullCapacityUnderwriting);

        $structuralRoe = ($effectiveTurnover * $baselineMargin) + $investmentLeg;

        // Blend through-the-cycle structural capacity with actual TTM ROE
        $blendedRoe = ($structuralRoe * 0.70) + ($roicTtm * 0.30);
        return max(self::MIN_STRUCTURAL_ROE_FLOOR, $blendedRoe);
    }

    /**
     * The float is the claims the firm has incurred and not yet paid: Δreserves = incurred − paid. The
     * P&L has already charged the quarter's incurred claims against the cash it booked, so moving cash with
     * the reserve change leaves the treasury debited when a claim is paid, not when it is incurred.
     */
    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void
    {
        $incurred = ($stock->getEarningsMomentumZ() ?? [])[self::STATE_INCURRED_CLAIMS] ?? null;
        if ($incurred === null) {
            return;
        }

        $reserves = max(0.0, (float) $state['customerDeposits']);
        $roll = $this->rollLossReserves($reserves, (float) $incurred, $this->resolveReserveRunoffYears($stock));
        $reserveChange = $roll['reserves'] - $reserves;

        $state['treasury'] += $reserveChange;
        $state['customerDeposits'] = $roll['reserves'];

        if ($state['treasury'] < 0.0) {
            $liquidityShortfall = abs($state['treasury']);
            $state['treasury'] = 0.0;
            $state['wholesaleDebt'] += $liquidityShortfall;
            $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
            $state['events'][] = ['description' => "Claim payouts exceeded cash reserves. Forced to borrow \${$amtB}B."];
        }

        $stock->setCustomerDeposits((string) $roll['reserves']);
    }

    /**
     * One quarter of a reserve stock paid out at rate 1/τ (exponential runoff, the continuous form of a
     * paid-loss development pattern): reserves move toward annualized incurred × τ, and what leaves is paid.
     *
     * @return array{reserves: float, paid: float}
     */
    public function rollLossReserves(float $reserves, float $incurred, float $runoffYears): array
    {
        $dt = \App\Service\Corporate\EarningsEngine::QUARTERLY_TIME_STEP;
        $settled = max(0.0, MathUtility::getInstance()->calculateDistributedLag(
            currentLaggedValue: $reserves,
            targetValue: ($incurred / $dt) * $runoffYears,
            dt: $dt,
            lagTimeConstant: $runoffYears
        ));

        return ['reserves' => $settled, 'paid' => $reserves + $incurred - $settled];
    }

    /** Float per unit of annual premium the firm's book carries once its reserves have settled. */
    public function resolveReserveToPremiumRatio(Stock $stock): float
    {
        return static::RESERVE_TO_PREMIUM_RATIO;
    }

    /**
     * Mean years from incurred to paid. Set so a steady book on its structural claims ratio (the variable
     * cost line at neutral rates, (1 − margin) × (1 − fixed share)) holds exactly the pinned reserve ratio.
     */
    public function resolveReserveRunoffYears(Stock $stock): float
    {
        $structuralClaimsRatio = (1.0 - (float) $stock->getOperatingMargin()) * (1.0 - (float) $stock->getFixedCostRatio());

        return $this->resolveReserveToPremiumRatio($stock) / max(0.01, $structuralClaimsRatio);
    }

    /** Books the quarter's incurred claims, net of the cover's recovery, for the reserve roll-forward. */
    protected function registerIncurredClaims(StreamContext $streams, float $actualRevenue, float $netClaimsRatio): void
    {
        $streams->registerState(self::STATE_INCURRED_CLAIMS, max(0.0, $actualRevenue * $netClaimsRatio));
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr): bool
    {
        // For Insurance companies, Customer Deposits represent policyholder reserves ("The Float").
        // Float scales with underwriting policy volume and claim payout schedules, not discretionary capital
        // structure financing. An insurer should never trigger forced "underleveraged" share buyback spirals
        // simply because its float-to-equity ratio fluctuates.
        return false;
    }

    public function getBankruptEquityThreshold(): float { return self::BANKRUPT_EQUITY_THRESHOLD; }
    public function getDistressEquityThreshold(): float { return self::DISTRESS_EQUITY_THRESHOLD; }
    public function getWarningEquityThreshold(): float { return self::WARNING_EQUITY_THRESHOLD; }

    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury, ?MacroStateDTO $macroState = null): ?float
    {
        // Solvency is measured on capital that can pay a claim: goodwill is valued at nil.
        $equity = $stock->getTangibleEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $totalAssets = max(1.0, $equity + $totalDebt);
        $capitalRatio = ($equity / $totalAssets) * 100.0;

        // Statutory NAIC / Solvency II capital distress lockout:
        // When capital ratio falls into regulatory distress (< 2.0%) or surplus is below 50% of Kenney target,
        // regulators mandate an immediate halt on all dividend distributions.
        $targetSurplus = (float) $stock->getTotalRevenue() / self::KENNEY_CAPACITY_RATIO;
        if ($capitalRatio < self::DISTRESS_EQUITY_THRESHOLD || $equity < ($targetSurplus * 0.50)) {
            return 0.0;
        }

        return parent::getRegulatoryDividendCap($stock, $currentTreasury, $macroState);
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'catastrophe_loss_index_ema',
            'market_volatility_ema',
            'nominal_gdp_index',
            'output_gap_ema',
            'output_gap_lag_6m',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'yield_10y_ema',
        ];
    }
}

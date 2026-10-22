<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\DebtExpansionAppetiteDTO;
use App\DTO\InterestExpenseDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;

use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;

use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FixedIncome;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

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
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Premium volume is
     * sticky through the cycle.
     */
    public const OPERATING_CYCLICALITY = 0.70;

    // --- Labor Intensity ---
    /**
     * Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Underwriting, claims and distribution
     * payroll is roughly half of an insurer's overhead.
     */
    public const FIXED_COST_LABOR_SHARE = 0.50;

    // --- Demand Transmission Lag ---
    /**
     * Years for a move in the output gap to reach the order book. Policies are annual: exposure only reprices as the
     * book comes up for renewal.
     */
    public const DEMAND_LAG_YEARS = 0.50;

    // --- Reporting Incentives ---
    /**
     * Propensity to steer reported earnings toward consensus with accruals. Loss reserves are an actuarial estimate
     * management sets itself: strengthening or releasing them moves the headline without touching cash.
     */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.60;

    public function getMoatSpread(): float
    {
        return 0.01;
    }

    // --- The Kenney Rule & Capacity Limits ---
    /** Standard Premium-to-Surplus capacity ratio required to maintain strong credit ratings. */
    public const KENNEY_CAPACITY_RATIO    = 1.50;
    /** Implied runoff equity fraction of customer deposit float allowed for insolvent insurers. */
    public const IMPLIED_RUNOFF_EQUITY    = 0.10;
    /** Minimum fraction of prior revenue retained during hard market pricing (post-catastrophe capacity support). */
    public const HARD_MARKET_REVENUE_FLOOR = 0.85;

    // --- Solvency & Regulatory Capital Thresholds ---
    /** Minimum capital ratio (Equity / Assets) before regulatory balance sheet insolvency (Equity <= 0). */
    public const BANKRUPT_EQUITY_THRESHOLD = 0.0;
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: Insurance (Prop/Cas.), 0.41% of revenue (Damodaran,
     * Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0041;
    /** Statutory capital ratio threshold below which insurer enters regulatory capital distress. */
    public const DISTRESS_EQUITY_THRESHOLD = 2.0;
    /** Share of the Kenney target surplus below which the regulator halts every distribution: half. */
    public const DISTRESS_TARGET_SURPLUS_SHARE = 0.50;
    /** Statutory capital ratio threshold for regulatory grey/early warning watch. */
    public const WARNING_EQUITY_THRESHOLD  = 4.0;

    // --- Return Reversion ---
    /** Divisor on the trailing ROE's reversion speed: it reverts toward cost of equity plus moat at kappa / this. */
    public const TTM_ROE_WEIGHT = 0.50;

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
    /**
     * Underwriting operating margin mean reversion speed (quarters). Insurance policies renew annually with rapid
     * competitive repricing.
     */
    public const INSURANCE_REVERSION_SPEED = 8.0;
    /** Volatility multiplier for top-line premium revenue shocks in sticky insurance markets. */
    public const REVENUE_VARIANCE_SCALAR  = 0.05;
    /**
     * Firm claim z-score below which the large-loss layer starts paying (one quarter in fifteen): fires, liability
     * verdicts, single risks no peer shares.
     */
    public const CATASTROPHE_Z_THRESHOLD  = -1.50;
    /**
     * Large-loss claims, as a share of the book's premium, per standard deviation of the firm's claim draw below the
     * threshold.
     */
    public const CATASTROPHE_LOSS_SCALAR  = 0.15;

    // --- Underwriting Cost Split (NAIC Insurance Expense Exhibit, US P&C industry 2024) ---
    /**
     * Commissions, brokerage and premium taxes in the premium-proportional cost line: (10.7 + 2.3) / (61.5 losses + 3.8
     * DCC + 10.7 + 2.3), the rest being losses and defence costs.
     */
    public const BASE_EXPENSE_RATIO_SHARE = 0.166;
    /**
     * Adjusting and other expense (claims-department overhead, unallocated LAE) in the sticky overhead: 5.5 / (5.5 A&O
     * + 5.9 other acquisition + 6.4 general).
     */
    public const FIXED_COST_LAE_SHARE = 0.309;

    // --- Claims Inflation (rates are filed on past loss costs) ---
    /**
     * Goods in personal auto and homeowners loss costs (parts, vehicles, building materials), the rest being repair
     * labour, medical and legal: ~0.40 from ISO 2024 auto coverages and Verisk reconstruction costs (constructed,
     * second-hand).
     */
    public const CLAIMS_COST_GOODS_SHARE = 0.40;
    /**
     * Years for premium rates to catch up with claims costs: AM Best personal auto combined ratio 112.2, 104.9 and 98.7
     * in 2022-24 against ~101 before, two-thirds of the excess gone each year.
     */
    public const PREMIUM_CATCH_UP_YEARS = 1.0;
    /** Persisted state key: claims-cost inflation not yet in filed rates, as a fraction of the loss cost. */
    public const STATE_UNRECOVERED_CLAIMS_COST = 'state:claims:unrecovered_cost';

    // --- Underwriting Cycle (Winter 1994 / Gron 1994 capacity constraint) ---
    /**
     * Regime key for the hard market: the multi-year stretch of rate increases and tightened terms that follows a
     * capital shock.
     */
    public const REGIME_HARD_MARKET = 'hard_market';
    /** Surplus shortfall against the Kenney target at which capacity withdraws and the market turns hard. */
    public const HARD_MARKET_ONSET_SURPLUS_DEFICIT = 0.10;
    /**
     * Quarterly probability the hard market breaks as capital returns and price competition resumes (~10 quarter
     * expected duration).
     */
    public const HARD_MARKET_EXIT_HAZARD = 0.10;
    /** Peak premium rate uplift in the opening quarters of a hard market, before returning capacity erodes it. */
    public const HARD_MARKET_PRICING_UPLIFT = 0.25;
    /** Quarters over which the rate uplift decays as capital rebuilds, even while the regime itself persists. */
    public const HARD_MARKET_UPLIFT_DECAY_QUARTERS = 8.0;
    /**
     * Premium rate cut per unit of Kenney capacity held beyond premium demand (soft-side price-to-capacity elasticity).
     * UNCITED: Gron (1994) and Weiss & Chung (2004) find the sign; Froot & O'Connell (1999) measure only the hard side
     * (+19-40% after a $10B loss).
     */
    public const SOFT_MARKET_CAPACITY_BETA = 0.40;
    /** Deepest rate cut price competition inflicts, however overcapitalized the industry becomes. */
    public const SOFT_MARKET_MAX_DISCOUNT = 0.30;
    /**
     * Smallest share of its premium demand a going concern still writes at any price: obligatory renewals, treaty
     * shares it cannot walk away from mid-term, and the distribution it must keep alive to have a book to write when
     * rates recover.
     */
    public const MIN_WRITTEN_CAPACITY = 0.35;
    /**
     * Standard deviation of the US P&C industry combined ratio about its mean, 2000-2023 (III / AM Best annual results,
     * ~5 points): how far a book's result strays from what was expected of it.
     */
    public const UNDERWRITING_RESULT_DISPERSION = 0.05;
    /**
     * Years for the in-force book to reach a newly chosen written share: each policy is re-underwritten only at its
     * renewal, once a term (the renewal lag of Cummins & Outreville 1987).
     */
    public const WRITTEN_SHARE_ADJUSTMENT_YEARS = self::POLICY_TERM_YEARS;
    /** State key holding the share of its premium demand the in-force book is written at. */
    public const STATE_WRITTEN_SHARE = 'state:capacity:written_share';
    /**
     * State key holding Kenney capacity over premium demand as the market has observed it, which is what renewal rates
     * are struck against.
     */
    public const STATE_OBSERVED_CAPACITY = 'state:capacity:observed_ratio';
    /**
     * State key holding the firm's franchise share of its serviceable market, fixed at its first report so its opening
     * capital exactly meets its demand.
     */
    public const STATE_PREMIUM_FRANCHISE_SHARE = 'state:capacity:franchise_share';
    /**
     * State key holding the premium-to-surplus ratio the firm steers to, its opening book over its opening surplus
     * (Gropp & Heider 2010 time-invariant target; Cheng & Weiss 2012 find insurers adjust NPW/surplus toward one).
     */
    public const STATE_TARGET_PREMIUM_TO_SURPLUS = 'state:capacity:target_premium_to_surplus';
    /**
     * Years for a change in an underwriter's capital to reach the rates it is quoted: statutory filings, rating reviews
     * and annual renewal dates all sit between the two, and that delay is what turns the capacity cycle into a cycle
     * rather than a level.
     */
    public const CAPACITY_OBSERVATION_LAG_YEARS = 1.50;

    // --- District Catastrophe Losses (industry loss plus basis) ---
    /**
     * Expected catastrophe claims as a share of a property book's premium in an average district year: Verisk's US
     * modelled average annual loss of $117B against $895B of 2024 US P&C premiums earned (~13%).
     */
    public const DISTRICT_CATASTROPHE_LOAD = 0.13;
    /**
     * District loss burden (average-year units, smoothed) at which the market hardens for every carrier, capital shock
     * or not (the 1992, 2005 and 2017 seasons).
     */
    public const CAT_HARD_MARKET_THRESHOLD = 3.0;
    /** Cummins & Danzon (1997) soft-market underwriting combined ratio compression sensitivity to high float yields. */
    public const SOFT_MARKET_CYCLE_BETA   = 1.50;

    // --- Event Lore Thresholds ---
    /**
     * Excess claims, in average years of the book's catastrophe load, at which a quarter reads as a systemic disaster
     * (~1.5% of quarters).
     */
    public const LORE_SYSTEMIC_DISASTER_LOADS = 3.0;
    /**
     * Excess claims, in average years of the book's catastrophe load, at which a quarter's payouts make the news (~one
     * quarter in eleven).
     */
    public const LORE_ELEVATED_CLAIMS_LOADS   = 1.0;

    // --- Catastrophe Excess-of-Loss Cover ---
    /**
     * Return period, in years, of the quarterly loss a book's catastrophe cover attaches at: one quarter in twenty, the
     * working layer a carrier's own lumpy seasons breach one year in five.
     */
    public const CATASTROPHE_RETENTION_RETURN_YEARS = 5.0;
    /** Return period of the quarterly loss the cover exhausts at: the one-in-two-hundred-year quarter. */
    public const CATASTROPHE_LIMIT_RETURN_YEARS = 200.0;
    /** Reinsurer's rate over the expected recovery (rate on line ~1.5x expected loss on a working cat layer). */
    public const CATASTROPHE_COVER_RATE_LOADING = 0.50;
    /** Bisection steps solving a layer's attachment and exhaustion points. */
    private const CATASTROPHE_LAYER_BISECTION_STEPS = 60;
    /**
     * Each loss layer's cover, struck once: a function of its threshold, scale and load and the district's process.
     *
     * @var array<string, array{retention: float, limit: float, expected_recovery: float}>
     */
    private static array $catastropheLayers = [];

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

    // --- Ordinary Dividend Limit (NAIC Model Act #440) ---
    /**
     * Share of policyholder surplus an insurer may distribute in twelve months without prior approval when the year's
     * net income is less: 10%, NAIC Insurance Holding Company System Regulatory Act section 5.
     */
    public const NAIC_ORDINARY_DIVIDEND_SURPLUS_SHARE = 0.10;

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

    // --- Life Reserve Crediting ---
    /**
     * What a life book earns on its reserves over what it credits its policyholders: 1.5pp, between the 1-2pp net
     * investment spreads life and annuity writers report (Athene ~1.6% in 2023). The rest of the yield is theirs.
     */
    public const LIFE_INVESTMENT_SPREAD = 0.015;
    /**
     * Floor on the rate credited on life reserves: 1%, the minimum nonforfeiture interest rate (NAIC Standard
     * Nonforfeiture Law for Individual Deferred Annuities, Model #805).
     */
    public const NONFORFEITURE_MIN_CREDITING_RATE = 0.01;

    // --- Float Portfolio Yield (15/75/10 Allocation) ---
    /** Default policy rate fallback when macroeconomic state data is missing. */
    public const DEFAULT_POLICY_RATE_FALLBACK = 0.02;
    /** Equity market return sensitivity to macroeconomic output gaps. */
    public const EQUITY_RETURN_GAP_MULT   = 1.50;
    /** Weight allocated to short-term T-Bills and liquid cash in float portfolios. */
    public const FLOAT_LIQUIDITY_WEIGHT   = 0.15;
    /** Weight allocated to core long-duration fixed income bonds in float portfolios. */
    public const FLOAT_BOND_WEIGHT        = 0.75;
    /** Weight allocated to public growth equities in property-casualty float portfolios. */
    public const FLOAT_EQUITY_WEIGHT      = 0.10;
    /**
     * Equity weight of the assets behind life reserves, whose long fixed obligations are matched with bonds: NAIC
     * year-end 2023, common stock is 4.0% of life insurers' cash and invested assets with subsidiaries included,
     * against 34% for P/C insurers.
     */
    public const LIFE_RESERVE_EQUITY_WEIGHT = 0.025;

    // --- Valuation & Fair Value Weights ---
    /** Weight given to book value in profitable quarters (50% Book / 35% Earnings / 15% DDM). */
    public const FAIR_VALUE_BOOK_POS_EPS        = 0.50;
    /** Franchise floor multiplier applied to revenue floor value for sticky premium & float franchise. */
    public const PREMIUM_FRANCHISE_FLOOR_MULT   = 0.70;

    // --- Loss Reserves and Unearned Premium (the float as two stocks) ---
    /** Loss and LAE reserves per unit of annual net premium earned on a US P&C book: NAIC 2024, $977B on NPE $905B. */
    public const LOSS_RESERVE_TO_PREMIUM_RATIO = 1.08;
    /**
     * Policy term of a P&C book in years; written premium is earned pro rata over it, so the unearned reserve holds
     * half a term of premium (SSAP 65, ASC 944-605; NAIC 2024 UPR ~0.52 of NPE).
     */
    public const POLICY_TERM_YEARS = 1.0;
    /**
     * Mean years from writing a rate to earning it: half a term, as annual policies renewed through the year earn their
     * premium pro rata over it.
     */
    public const EARNED_RATE_LAG_YEARS = self::POLICY_TERM_YEARS / 2.0;
    /** Annual nominal growth of a book that keeps pace with the economy at trend: real trend growth plus inflation. */
    public const TREND_BOOK_GROWTH = MacroConstants::TREND_REAL_GROWTH + MacroConstants::TARGET_INFLATION;
    /**
     * Ceiling on a reserve's runoff: a century, past which a reserve fed so slowly it cannot keep pace with trend
     * growth would never pay out at all.
     */
    public const MAX_RESERVE_RUNOFF_YEARS = 100.0;
    /** Persisted premium rate level the in-force book earns, as a multiple of the rate its margin was struck at. */
    public const STATE_EARNED_RATE_LEVEL = 'state:capacity:earned_rate_level';
    /**
     * Years of renewals an underwriter weighs the float a new book brings against its underwriting result: the annual
     * policy term.
     */
    public const FLOAT_DECISION_HORIZON_YEARS = 1.0;
    /**
     * State key holding the quarter's net incurred losses and LAE (life benefits included), on which the reserve stocks
     * are rolled forward.
     */
    public const STATE_INCURRED_CLAIMS = 'state:reserves:incurred_claims';
    /**
     * State key holding the life book's share of the quarter's incurred, rolled into the policy reserve rather than the
     * loss reserve.
     */
    public const STATE_INCURRED_LIFE_BENEFITS = 'state:reserves:incurred_life';
    /** State key holding the quarter's earned premium on policies that carry an unearned premium reserve. */
    public const STATE_UNEARNED_PREMIUM_BASE = 'state:reserves:unearned_base';
    /** State key holding the unearned premium reserve at the last quarter end. */
    public const STATE_UNEARNED_PREMIUM = 'state:reserves:unearned';
    /** State key holding the life policy reserve at the last quarter end. */
    public const STATE_LIFE_RESERVES = 'state:reserves:life';

    // --- Investment Portfolio Duration ---
    /**
     * Macaulay duration of the long bond tranche of float, matched against liabilities an insurer pays out over
     * decades.
     */
    public const DEFAULT_FLOAT_BOND_DURATION_YEARS = 7.0;

    /**
     * Reverse engineers the required operating metrics from the premium book. The book is the capacity-constrained
     * supply of Winter (1994): the share of premium demand the firm chooses to write, capped by the Kenney ratio to
     * its surplus. Capital beyond what demand can use writes nothing; capital short of it rations the book.
     *
     * @param Stock       $stock       The insurance stock entity being evaluated.
     * @param MacroStateDTO $macroState  The current macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility for engine operations.
     * @return array{invested_capital: float, baseline_roic: float} Target operating metrics.
     */
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $capacityRatio = self::KENNEY_CAPACITY_RATIO;
        $operatingEquity = $this->resolveOperatingEquity($stock);

        $taxRate = $macroState->corporateTaxRate;

        // Premium = min(demand x written share, Kenney capacity).
        $targetRevenue = min(
            $this->resolvePremiumDemand($stock, $macroState) * $this->resolveWrittenCapacity($stock, $macroState, $mathUtility),
            $this->resolveKenneyCapacity($stock),
        );
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
            // Apply structural floor locally when deriving baseline capacity return so catastrophe losses do not
            // collapse required underwriting turnover
            $structuralRoe = max(self::MIN_STRUCTURAL_ROE_FLOOR, $ttmRoe);
            // Clamp baseline return to capacity ROIC to prevent equity float leverage from distorting premium turnover.
            $baselineRoic = min($capacityRoic, ($capacityRoic * self::BASELINE_ROIC_WEIGHT) + ($structuralRoe * self::TTM_ROIC_WEIGHT));
        }

        // Saturation measures the firm's size in its market, so it reads the whole book, goodwill included.
        $saturationPenalty = CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $stock->getAmortizedCostEquity()), $macroState);
        $waccBase = $macroState->marketCostOfEquity();
        // Cap baseline return at capacity ROIC to prevent margin compression from inflating implied turnover.
        $baselineRoic = min($capacityRoic, max($waccBase, $baselineRoic - $saturationPenalty));

        return [
            'invested_capital' => $operatingEquity,
            'baseline_roic'    => $baselineRoic,
        ];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);

        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR, // Highly immune to macro demand
            'pricing_power_multiplier' => $this->resolveBookRateLevel($stock, $macroState),
            // Neutral input cost multiplier: claims inflation reaches the loss ratio through the rate-filing lag.
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
     * The premium rate level the in-force book earns: the rate on offer (resolvePremiumRateLevel()) earned through over
     * half a policy term, as the book renews through the year and earns its premium pro rata. A rate change reaches the
     * whole book only once it has been written and earned; struck at once, a hard market's uplift took a carrier's
     * combined ratio from 95 to 78 in the quarter it began. A read, like resolvePremiumRateLevel(): the physics
     * persists the same level (advanceUnderwritingCycle()).
     */
    protected function resolveEarnedRateLevel(Stock $stock, MacroStateDTO $macroState): float
    {
        $written = $this->resolvePremiumRateLevel($stock, $macroState);
        $earned = (float) (($stock->getEarningsMomentumZ() ?? [])[self::STATE_EARNED_RATE_LEVEL] ?? $written);

        return TimeSeries::calculateDistributedLag($earned, $written, FinancialConstants::QUARTER_YEARS, self::EARNED_RATE_LAG_YEARS);
    }

    /**
     * The rate level on the firm's whole book: the capacity cycle reaches its property-casualty premium, and life
     * rates, priced on mortality and the long rate, do not follow it.
     */
    protected function resolveBookRateLevel(Stock $stock, MacroStateDTO $macroState): float
    {
        return 1.0 + ((1.0 - $this->resolveLifePremiumShare($stock)) * ($this->resolveEarnedRateLevel($stock, $macroState) - 1.0));
    }

    /**
     * What a book's catastrophe terms, struck per unit of its premium at the base rate, come to per unit of revenue:
     * its premium at the base rate over revenue. A storm costs the same dollars whatever rate the book was renewed at,
     * so a hard market's uplift is price on the loss side, not exposure; struck on revenue, it raised the claims a
     * quarter's storms cost by the whole uplift.
     */
    protected function resolveBookVolumeShare(Stock $stock, MacroStateDTO $macroState, float $bookRevenue, float $actualRevenue): float
    {
        return $actualRevenue > 0.0 ? $bookRevenue / ($this->resolveBookRateLevel($stock, $macroState) * $actualRevenue) : 0.0;
    }

    /**
     * Winter (1994) / Gron (1994): the underwriting cycle is a CAPACITY cycle, and it runs in both directions.
     * Capital destroyed by a catastrophe withdraws capacity and hardens rates, the half resolveHardMarketUplift()
     * carries. Kenney capacity built past what premium demand can use competes for the same premium, and rates
     * soften in proportion to the glut, 1.5·S/D − 1, until the capital that earns nothing has been handed back.
     */
    protected function resolveSoftMarketCapacityDiscount(Stock $stock, MacroStateDTO $macroState): float
    {
        // Rates are quoted against the capital the market has SEEN, which is where the cycle comes from:
        // capital built through a soft market is not priced until it has been filed, rated and renewed
        // against, so the industry overshoots in both directions instead of settling at a level.
        $observedRatio = (float) ($stock->getEarningsMomentumZ()[self::STATE_OBSERVED_CAPACITY] ?? $this->resolveCapacityToDemandRatio($stock, $macroState));

        return min(self::SOFT_MARKET_MAX_DISCOUNT, max(0.0, $observedRatio - 1.0) * self::SOFT_MARKET_CAPACITY_BETA);
    }

    /**
     * Premium the firm's market would buy at the rate its margin was struck at: its franchise share of the
     * serviceable market, which rides trend nominal GDP. The demand side of Winter (1994); the cycle reaches
     * volume through the lagged demand shift in getMacroPhysics(), so it is not in the anchor as well. A firm
     * with no recorded franchise (a fresh seed's first report) has demand equal to its capacity, which is the
     * franchise advanceUnderwritingCycle() then records.
     */
    public function resolvePremiumDemand(Stock $stock, MacroStateDTO $macroState): float
    {
        $franchise = ($stock->getEarningsMomentumZ() ?? [])[self::STATE_PREMIUM_FRANCHISE_SHARE] ?? null;
        $market = $this->resolveServiceableMarket($stock, $macroState);

        return $franchise !== null && $market > 0.0
            ? (float) $franchise * $market
            : $this->resolveKenneyCapacity($stock);
    }

    /** The franchise share at which demand equals Kenney capacity: the opening book, in balance with its capital. */
    protected function resolveBalancedFranchiseShare(Stock $stock, MacroStateDTO $macroState): float
    {
        $market = $this->resolveServiceableMarket($stock, $macroState);

        return $market > 0.0 ? $this->resolveKenneyCapacity($stock) / $market : 0.0;
    }

    /**
     * The serviceable market on the base every share reading uses: sector TAM x trend nominal GDP x the firm's SAM
     * ratio.
     */
    private function resolveServiceableMarket(Stock $stock, MacroStateDTO $macroState): float
    {
        return CorporateMetrics::BASELINE_SECTOR_TAM * IndustryShareLedger::trendNominalGdp($macroState) * max(0.0, (float) $stock->getSamRatio());
    }

    /** Annual premium the surplus supports at the Kenney ratio. */
    protected function resolveKenneyCapacity(Stock $stock): float
    {
        return self::KENNEY_CAPACITY_RATIO * $this->resolveOperatingEquity($stock);
    }

    /**
     * Kenney capacity over premium demand: above one the firm holds capital its market cannot use, below one it
     * rations.
     */
    public function resolveCapacityToDemandRatio(Stock $stock, MacroStateDTO $macroState): float
    {
        $demand = $this->resolvePremiumDemand($stock, $macroState);

        return $demand > 0.0 ? $this->resolveKenneyCapacity($stock) / $demand : 1.0;
    }

    /**
     * Underwriting discipline: the share of its premium demand the in-force book is written at.
     *
     * Capacity says how much an underwriter MAY write; it does not say it should. The share is chosen against
     * the rate on offer (resolveTargetWrittenShare) but reaches the book only as policies come up for renewal
     * (adjustWrittenShare, advanced once a quarter by advanceUnderwritingCycle). A firm with no recorded
     * share, a fresh seed, writes at its target.
     */
    protected function resolveWrittenCapacity(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        $written = ($stock->getEarningsMomentumZ() ?? [])[self::STATE_WRITTEN_SHARE] ?? null;

        return $written !== null
            ? (float) $written
            : $this->resolveTargetWrittenShare($stock, $macroState, $mathUtility, $this->resolveOperatingEquity($stock));
    }

    /**
     * Surplus the book is rationed against: statutory, so bonds at amortized cost and goodwill at nil; an insolvent
     * carrier runs off on a fraction of its float.
     */
    protected function resolveOperatingEquity(Stock $stock): float
    {
        $impliedRunoffEquity = (float) $stock->getCustomerDeposits() * self::IMPLIED_RUNOFF_EQUITY;

        return max(max(1.0, $impliedRunoffEquity), $stock->getStatutorySurplus());
    }

    /**
     * The written share the firm would choose at the rate the market is offering: the capacity-constrained
     * supply of Winter (1994) and Gron (1994), in which capital is costly to replace, so it binds when rates
     * pay for it and is withdrawn when they do not.
     *
     * The test is the firm's annual after-tax return on surplus, writing the whole book, against its hurdle.
     * The float it already holds runs off whether or not another treaty is signed; the book it writes brings
     * new float, its unearned premium and a share 1 − e^(−H/τ) of each settled claim reserve over one policy
     * term (total-return ratemaking, Myers & Cohn 1987):
     *
     *     excess = underwritingReturn + bookFloatReturn + heldFloatReturn − appliedHurdle
     *
     * Each line in the book clears that test or not; summed over lines whose results stray from the book's
     * by the industry's combined-ratio dispersion, the share that clears it is the normal CDF of the excess
     * (writtenShareAtExcessReturn). The hurdle carries the manager's bias, so an empire builder keeps
     * writing into a market a fortress has already withdrawn from (Jensen's agency cost).
     */
    public function resolveTargetWrittenShare(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, float $operatingEquity): float
    {
        $pricingPower = $this->resolvePremiumRateLevel($stock, $macroState);
        if ($pricingPower <= 0.0 || $operatingEquity <= 0.0) {
            return 1.0;
        }

        $afterTax = 1.0 - $macroState->corporateTaxRate;

        // What the book earns at the offered rate. Premium scales with the rate and claims do not, so the
        // margin on a 95.5% book written 10% below the rate it was priced at is negative, not 4.5% smaller.
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        $marginAtOfferedRate = 1.0 - ((1.0 - $stableMargin) / $pricingPower);
        $underwritingReturn = self::KENNEY_CAPACITY_RATIO * $marginAtOfferedRate * $afterTax;

        $floatYield = $this->resolveFloatYield($stock, $macroState);
        $heldAfterHorizon = $this->resolveHeldFloatSurvival($stock, self::FLOAT_DECISION_HORIZON_YEARS);
        // The float a new book brings earns the portfolio yield, less what its life reserves credit their holders.
        $bookFloatReturn = (($floatYield * $this->resolveBookFloatRatio($stock, self::FLOAT_DECISION_HORIZON_YEARS))
            - ($this->resolveLifeCreditingRate($stock, $macroState) * $this->resolveBookLifeFloatRatio($stock, self::FLOAT_DECISION_HORIZON_YEARS)))
            * $afterTax * self::KENNEY_CAPACITY_RATIO;

        $floatIncome = min((float) $stock->getCorporateTreasury(), (float) $stock->getCustomerDeposits()) * $floatYield;
        $heldIncome = $this->calculateInterestIncome($stock, $macroState, $mathUtility) - ($floatIncome * (1.0 - $heldAfterHorizon));
        $heldFloatReturn = ($heldIncome * $afterTax) / $operatingEquity;
        $hurdle = $stock->getManagementProfile()->appliedHurdle($macroState->marketCostOfEquity());

        return self::writtenShareAtExcessReturn(
            $underwritingReturn + $bookFloatReturn + $heldFloatReturn - $hurdle,
            self::UNDERWRITING_RESULT_DISPERSION * self::KENNEY_CAPACITY_RATIO * $afterTax,
            $this->resolveLifePremiumShare($stock),
        );
    }

    /**
     * Share of premium demand written when the full book's return beats the hurdle by $excessReturn (annual,
     * on surplus): MIN + (1 − MIN)·Φ(excess / σ) of the discretionary book, σ the dispersion of a line's
     * result on surplus. The contractual share (in-force life policies, which the insurer cannot decline to
     * renew) is written whatever the rate.
     */
    public static function writtenShareAtExcessReturn(float $excessReturn, float $resultDispersion, float $contractualShare = 0.0): float
    {
        $contractual = max(0.0, min(1.0, $contractualShare));
        $clearing = $resultDispersion > 0.0
            ? Distributions::standardNormalCdf($excessReturn / $resultDispersion)
            : ($excessReturn >= 0.0 ? 1.0 : 0.0);
        $discretionary = self::MIN_WRITTEN_CAPACITY + ((1.0 - self::MIN_WRITTEN_CAPACITY) * $clearing);

        return $contractual + ((1.0 - $contractual) * $discretionary);
    }

    /**
     * The in-force written share after $dt years: renewals arrive at rate 1/T over a policy term T and each is
     * written at the target, so the book closes the gap as e^(−dt/T) (the first-order partial adjustment of
     * written premium in Cummins & Outreville 1987).
     */
    public static function adjustWrittenShare(float $writtenShare, float $targetShare, float $dt): float
    {
        return TimeSeries::calculateDistributedLag($writtenShare, $targetShare, $dt, self::WRITTEN_SHARE_ADJUSTMENT_YEARS);
    }

    /**
     * Claims-cost inflation the book has not yet priced: loss costs move with the prices of parts, materials and repair
     * labour as they happen, while rates are filed on past losses and catch up over PREMIUM_CATCH_UP_YEARS. The gap
     * grows with claims inflation over target and closes at the filing lag (dg/dt = excess - g/tau), so a steady
     * excess of x leaves the loss ratio x*tau above plan.
     *
     * @return float Unrecovered claims cost, as a fraction of the baseline loss cost.
     */
    protected function resolveUnrecoveredClaimsCost(StreamContext $streams, MacroStateDTO $macroState): float
    {
        $claimsInflation = (self::CLAIMS_COST_GOODS_SHARE * $macroState->coreGoodsInflationEma)
            + ((1.0 - self::CLAIMS_COST_GOODS_SHARE) * $macroState->supercoreInflationEma);
        $excess = $claimsInflation - MacroConstants::TARGET_INFLATION;
        $gap = TimeSeries::calculateDistributedLag(
            $streams->getPersistedState(self::STATE_UNRECOVERED_CLAIMS_COST, 0.0),
            $excess * self::PREMIUM_CATCH_UP_YEARS,
            FinancialConstants::QUARTER_YEARS,
            self::PREMIUM_CATCH_UP_YEARS,
        );
        $streams->registerState(self::STATE_UNRECOVERED_CLAIMS_COST, $gap);

        return $gap;
    }

    /**
     * The quarter's claims on a book above its structural loss ratio, as a share of that book's premium.
     *
     * Industry loss plus basis (Cummins, Lalonde & Phillips 2004): the book's share of the district's
     * catastrophe losses, which every carrier pays together, plus a large-loss layer on the firm's own
     * claim draw. The structural margin is the through-the-cycle margin, catastrophe load included, so both
     * terms are centred: the district's losses incurred since the last report are read against what that window
     * averages, and the layer's expected payout is deducted (the unit layer's expectation is the normal lower partial
     * moment). A storm is booked in the quarter it lands, as an incurred loss is; the burden index is the stock of
     * open claims, which reached the loss ratio over six quarters and more.
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
        $expectedLargeLoss = $layerScale * Distributions::calculateNormalLowerPartialMoment($catThreshold);
        $districtLoss = $bookWeight * static::DISTRICT_CATASTROPHE_LOAD * $this->resolveDistrictCatastropheSurprise($streams, $macroState);

        return [
            'excess' => $largeLoss + $districtLoss - $expectedLargeLoss,
            'gross'  => $largeLoss + $districtLoss,
            'claimZ' => $claimZ,
        ];
    }

    /**
     * District catastrophe losses incurred since the last report against what that window averages, as a fraction:
     * zero in an average window, and nothing on a first report, which has no earlier reading.
     */
    private function resolveDistrictCatastropheSurprise(StreamContext $streams, MacroStateDTO $macroState): float
    {
        $read = $this->resolveDistrictCatastropheIncurred($streams, $macroState);

        return $read !== null && $read['expected'] > 0.0 ? ($read['incurred'] / $read['expected']) - 1.0 : 0.0;
    }

    /**
     * The catastrophe cover a book buys, struck on its own loss: the district's quarterly losses at the book's load
     * (CommodityLogisticsSubsystem::catastropheQuarterDistribution()) plus its own large-loss layer at its threshold
     * and scale, all per unit of the book's quarterly premium. It attaches at the book's one-in-five-year quarter,
     * exhausts at its one-in-two-hundred-year quarter, and pays on average what the layer between them expects. Struck
     * on the primary book's layer for every carrier, a reinsurer paid 3.3 times its own expected recovery for a cover
     * that attached one year in ten.
     *
     * @return array{retention: float, limit: float, expected_recovery: float}
     */
    public static function catastropheLayer(float $threshold, float $scale, float $load): array
    {
        return self::$catastropheLayers[sprintf('%.9F|%.9F|%.9F', $threshold, $scale, $load)] ??= self::strikeCatastropheLayer($threshold, $scale, $load);
    }

    /** @return array{retention: float, limit: float, expected_recovery: float} */
    private static function strikeCatastropheLayer(float $threshold, float $scale, float $load): array
    {
        $distribution = CommodityLogisticsSubsystem::catastropheQuarterDistribution();
        $shifts = [];
        $weights = [];
        foreach ($distribution['ratios'] as $index => $ratio) {
            if ($distribution['weights'][$index] > 0.0) {
                $shifts[] = $load * ($ratio - 1.0);
                $weights[] = $distribution['weights'][$index];
            }
        }
        // The large-loss layer given the district's quarter is a normal shortfall below the threshold, so each lattice
        // point's exceedance and expected layer payout are closed forms.
        $exceedance = static function (float $level) use ($shifts, $weights, $scale, $threshold): float {
            $probability = 0.0;
            foreach ($shifts as $index => $shift) {
                $probability += $weights[$index] * Distributions::normalHingeLayerExceedance($scale, $threshold, $shift - $level);
            }

            return $probability;
        };
        $quantile = static function (float $probability) use ($exceedance, $shifts, $scale, $threshold, $load): float {
            $low = -$load;
            $high = max([0.0, ...$shifts]) + ($scale * max(0.0, $threshold - Distributions::standardNormalQuantile($probability / 2.0)));
            for ($step = 0; $step < self::CATASTROPHE_LAYER_BISECTION_STEPS; ++$step) {
                $mid = 0.5 * ($low + $high);
                if ($exceedance($mid) > $probability) {
                    $low = $mid;
                } else {
                    $high = $mid;
                }
            }

            return 0.5 * ($low + $high);
        };
        $retention = $quantile(1.0 / (FinancialConstants::QUARTERS_PER_YEAR * self::CATASTROPHE_RETENTION_RETURN_YEARS));
        $exhaustion = $quantile(1.0 / (FinancialConstants::QUARTERS_PER_YEAR * self::CATASTROPHE_LIMIT_RETURN_YEARS));

        $expectedRecovery = 0.0;
        foreach ($shifts as $index => $shift) {
            $expectedRecovery += $weights[$index] * (Distributions::normalHingeLayerExpectation($scale, $threshold, $shift - $retention)
                - Distributions::normalHingeLayerExpectation($scale, $threshold, $shift - $exhaustion));
        }

        return ['retention' => $retention, 'limit' => max(0.0, $exhaustion - $retention), 'expected_recovery' => $expectedRecovery];
    }

    /**
     * The catastrophe cover this book buys: struck on its tuned large-loss layer and this model's district load.
     *
     * @return array{retention: float, limit: float, expected_recovery: float}
     */
    protected function resolveCatastropheLayer(float $catThreshold, float $catScalar): array
    {
        return self::catastropheLayer($catThreshold, $catScalar, static::DISTRICT_CATASTROPHE_LOAD);
    }

    /**
     * What the catastrophe excess-of-loss cover on a book pays: the claims above the retention, up to its limit.
     *
     * @param array{retention: float, limit: float, expected_recovery: float} $layer
     */
    protected function resolveCatastropheRecovery(float $grossExcessClaims, array $layer): float
    {
        return min($layer['limit'], max(0.0, $grossExcessClaims - $layer['retention']));
    }

    /**
     * The quarter's premium for the catastrophe cover, as a share of the book's premium: what it pays on average plus
     * the reinsurer's loading. Unpriced, the cover took a sixth of the catastrophe load off the book for nothing.
     *
     * @param array{retention: float, limit: float, expected_recovery: float} $layer
     */
    protected function resolveCededPremium(array $layer): float
    {
        return $layer['expected_recovery'] * (1.0 + self::CATASTROPHE_COVER_RATE_LOADING);
    }

    /**
     * The premium that restores the limit a recovery used: one reinstatement at 100%, pro rata as to amount, at the
     * cover's own rate.
     *
     * @param array{retention: float, limit: float, expected_recovery: float} $layer
     */
    protected function resolveReinstatementPremium(array $layer, float $recovery): float
    {
        return $layer['limit'] > 0.0 ? $this->resolveCededPremium($layer) * $recovery / $layer['limit'] : 0.0;
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
    protected function advanceUnderwritingCycle(StreamContext $streams, Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, float $surplusDeficitRatio, bool $coverAttached): void
    {
        $streams->evolveRegime(self::REGIME_HARD_MARKET, 0.0, self::HARD_MARKET_EXIT_HAZARD);

        if (
            $surplusDeficitRatio >= self::HARD_MARKET_ONSET_SURPLUS_DEFICIT
            || $coverAttached
            || $macroState->catastropheLossIndexEma >= self::CAT_HARD_MARKET_THRESHOLD
        ) {
            $streams->startRegime(self::REGIME_HARD_MARKET);
        }

        // The franchise is struck once, at the first report, and carried: demand then grows with the economy,
        // not with the capital the firm retains.
        $franchise = $streams->getPersistedState(self::STATE_PREMIUM_FRANCHISE_SHARE, $this->resolveBalancedFranchiseShare($stock, $macroState));
        if ($franchise > 0.0) {
            $streams->registerState(self::STATE_PREMIUM_FRANCHISE_SHARE, $franchise);
        }

        // The premium-to-surplus the firm steers to, struck on the same opening book: the share it writes of
        // Kenney capacity when demand meets capacity.
        $targetPremiumToSurplus = $streams->getPersistedState(
            self::STATE_TARGET_PREMIUM_TO_SURPLUS,
            self::KENNEY_CAPACITY_RATIO * $this->resolveWrittenCapacity($stock, $macroState, $mathUtility),
        );
        if ($targetPremiumToSurplus > 0.0) {
            $streams->registerState(self::STATE_TARGET_PREMIUM_TO_SURPLUS, $targetPremiumToSurplus);
        }

        // What the market will have observed of this firm's capacity against its demand by the time it quotes again.
        $currentRatio = $this->resolveCapacityToDemandRatio($stock, $macroState);
        $streams->registerState(self::STATE_OBSERVED_CAPACITY, TimeSeries::calculateDistributedLag(
            currentLaggedValue: $streams->getPersistedState(self::STATE_OBSERVED_CAPACITY, $currentRatio),
            targetValue: $currentRatio,
            dt: FinancialConstants::QUARTER_YEARS,
            lagTimeConstant: self::CAPACITY_OBSERVATION_LAG_YEARS,
        ));

        // The rate the in-force book earns this quarter, carried so the next quarter's earns on from it.
        $streams->registerState(self::STATE_EARNED_RATE_LEVEL, $this->resolveEarnedRateLevel($stock, $macroState));

        // The quarter's renewals are written at the share the rate on offer now justifies.
        $targetShare = $this->resolveTargetWrittenShare($stock, $macroState, $mathUtility, $this->resolveOperatingEquity($stock));
        $streams->registerState(self::STATE_WRITTEN_SHARE, self::adjustWrittenShare(
            $streams->getPersistedState(self::STATE_WRITTEN_SHARE, $targetShare),
            $targetShare,
            FinancialConstants::QUARTER_YEARS,
        ));
    }

    /**
     * How far an underwriter's surplus has fallen below the Kenney capital for the book it wants to write: demand
     * times the share the rate on offer justifies, over 1.5. The capacity constraint of Winter (1994) binds when
     * capital cannot carry the quantity the firm would supply at the price, so a soft market in which it chooses
     * to write less, and hands the surplus back, is not a shortage; a capital loss against an unchanged book is.
     */
    protected function resolveSurplusDeficitRatio(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        $operatingEquity = $this->resolveOperatingEquity($stock);
        $desiredBook = $this->resolvePremiumDemand($stock, $macroState)
            * $this->resolveTargetWrittenShare($stock, $macroState, $mathUtility, $operatingEquity);
        $requiredSurplus = $desiredBook / self::KENNEY_CAPACITY_RATIO;

        return $requiredSurplus > 0.0 ? max(0.0, ($requiredSurplus - $operatingEquity) / $requiredSurplus) : 0.0;
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
        // Baseline decomposition: Total variable cost margin is composed of Loss Ratio (claims) + Expense Ratio
        // (acquisition/admin).
        $baselineExpenseRatio = $realizedVariableMargin * self::BASE_EXPENSE_RATIO_SHARE;
        $baselineLossRatio    = $realizedVariableMargin * (1.0 - self::BASE_EXPENSE_RATIO_SHARE);

        // A. Expense Ratio Dynamics:
        // Policy acquisition and administrative overhead costs are sticky relative to expected baseline revenue.
        // If top-line revenue fluctuates, the realized expense ratio scales inversely with written premiums.
        $expenseScale = $expectedRevenue > 0.0 ? ($expectedRevenue / max(1.0, $actualRevenue)) : 1.0;
        $realizedExpenseRatio = $baselineExpenseRatio * $expenseScale;

        // B. Loss Ratio: the catastrophe cover takes the claims above the retention up to its limit, for a premium
        // every quarter and a reinstatement premium to restore the limit it used, all on the book's volume.
        $volume = $this->resolveBookVolumeShare($stock, $macroState, $actualRevenue, $actualRevenue);
        $layer = $this->resolveCatastropheLayer($catThreshold, $catScalar);
        $recovery = $this->resolveCatastropheRecovery($claims['gross'], $layer);
        $reinstatementPremium = ($this->resolveCededPremium($layer) + $this->resolveReinstatementPremium($layer, $recovery)) * $volume;
        $realizedLossRatio = max(0.0, ($baselineLossRatio * (1.0 + $this->resolveUnrecoveredClaimsCost($streams, $macroState))) + (($claims['excess'] - $recovery) * $volume));

        // A capital shortfall hardens rates through the regime this advances, which reaches revenue and the cost
        // ratio through the pricing-power multiplier; it is not discounted from the combined ratio again here.
        $surplusDeficitRatio = $this->resolveSurplusDeficitRatio($stock, $macroState, $mathUtility);
        $this->advanceUnderwritingCycle($streams, $stock, $macroState, $mathUtility, $surplusDeficitRatio, $recovery > 0.0);

        $combinedRatio = $realizedLossRatio + $realizedExpenseRatio + $reinstatementPremium;
        $clampedMargin = $this->clampMargin($combinedRatio);
        $kpis = $this->bookUnderwritingResult($streams, $actualRevenue, $clampedMargin, $realizedExpenseRatio, $reinstatementPremium, $fixedCosts, $actualRevenue);

        $eventType = $this->resolveClaimEvent($claims['gross'], 1.0, $recovery > 0.0);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'premium_revenue' => $actualRevenue,
            ],
            kpis: $kpis,
        );
    }

    public function getMarginReversionSpeed(): float
    {
        // 12-month annual policy contracts face rapid competitive repricing at each renewal cycle
        return self::INSURANCE_REVERSION_SPEED;
    }

    /**
     * Insurance companies invest their massive Float in long-duration bonds, bills and an equity tranche, at the mix's
     * expected yield, less the interest the life reserves credit their holders. The equity tranche's realised return
     * replaces its expectation in the books (resolveEquitySleeve()), so a crash that hits claims and equities together
     * reaches the insurer through the market's own fall.
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

        $moneyMarketYield = FixedIncome::moneyMarketYield($policyRate);

        // Net investment income: what the float earns, less the interest its life reserves credit their holders.
        return ($investableFloat * $floatYield) + ($excessCash * $moneyMarketYield) - $this->resolveCreditedLifeInterest($stock, $macroState);
    }

    /**
     * The rate the life reserves credit their policyholders: the bond book's yield less the life investment spread,
     * never below the statutory floor. Uncredited, a life reserve was float that cost nothing, and a composite's life
     * reserve ratio had to be cut from ACLI's 9.9 times premium to 2.84 to keep its return in range.
     */
    protected function resolveLifeCreditingRate(Stock $stock, MacroStateDTO $macroState): float
    {
        $bookYield = $stock->getSecuritiesCarryingYield() ?? $macroState->yield10yEma;

        return max(self::NONFORFEITURE_MIN_CREDITING_RATE, $bookYield - self::LIFE_INVESTMENT_SPREAD);
    }

    /** Annual interest credited on the life reserves the firm holds. */
    protected function resolveCreditedLifeInterest(Stock $stock, MacroStateDTO $macroState): float
    {
        return $this->resolveHeldLifeReserves($stock) * $this->resolveLifeCreditingRate($stock, $macroState);
    }

    /** The life reserves the firm holds; a book that has not rolled them yet opens on its steady composition. */
    private function resolveHeldLifeReserves(Stock $stock): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];

        return max(0.0, (float) ($momentum[self::STATE_LIFE_RESERVES]
            ?? $this->resolveOpeningReserves($stock, max(0.0, (float) $stock->getCustomerDeposits()), $momentum)['life']));
    }

    /**
     * The equity share of the investable float: the property-casualty float at the firm's equity weight, the life
     * reserves at LIFE_RESERVE_EQUITY_WEIGHT. At one weight across the float, a composite whose life reserves were ten
     * times premium held equities worth three quarters of its capital.
     */
    protected function resolveFloatEquityWeight(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FloatEquityWeight->value => self::FLOAT_EQUITY_WEIGHT,
        ]);
        $float = (float) $stock->getCustomerDeposits();
        $lifeShare = $float > 0.0 ? min(1.0, $this->resolveHeldLifeReserves($stock) / $float) : 0.0;

        return ((1.0 - $lifeShare) * $params[ModelParam::FloatEquityWeight]) + ($lifeShare * self::LIFE_RESERVE_EQUITY_WEIGHT);
    }

    /** The annual yield on a unit of investable float across the liquidity, bond and equity tranches. */
    protected function resolveFloatYield(Stock $stock, MacroStateDTO $macroState): float
    {
        $floatEquityWeight = $this->resolveFloatEquityWeight($stock);
        $policyRate        = $macroState->policyRateEma;
        $yield10y          = $macroState->yield10yEma;

        // Normalized Base Fixed-Income Yield (Liquidity + Long Bonds scaled to sum to 1.0 - float_equity_weight)
        $fixedIncomeWeight = max(0.0, 1.0 - $floatEquityWeight);
        $totalFixedWeight  = max(0.01, self::FLOAT_LIQUIDITY_WEIGHT + self::FLOAT_BOND_WEIGHT);
        $liquidityShare    = self::FLOAT_LIQUIDITY_WEIGHT / $totalFixedWeight;
        $bondShare         = self::FLOAT_BOND_WEIGHT / $totalFixedWeight;

        $liquidityReturn   = FixedIncome::moneyMarketYield($policyRate);
        // Net investment income accrues at book yield (SAP/GAAP amortized cost); the rate move is the AOCI mark
        // on the same tranche (resolveSecuritiesBook), so earning the live 10y here would count it twice.
        $bondReturn        = $stock->getSecuritiesCarryingYield() ?? $yield10y;
        $baseYield         = $fixedIncomeWeight * (($liquidityShare * $liquidityReturn) + ($bondShare * $bondReturn));

        return $baseYield + ($floatEquityWeight * $this->resolveFloatEquityReturn($macroState));
    }

    /**
     * The equity tranche's expected return a year, what the float's yield and the decisions it informs carry; the
     * board's realised return replaces it in the books, where a crash that hits claims and equities together arrives
     * as the market's own fall.
     */
    protected function resolveFloatEquityReturn(MacroStateDTO $macroState): float
    {
        return $macroState->policyRateEma + $macroState->equityRiskPremium + ($macroState->outputGapEma * self::EQUITY_RETURN_GAP_MULT);
    }

    /** @return array{value: float, expected_return: float} */
    public function resolveEquitySleeve(Stock $stock, MacroStateDTO $macroState): array
    {
        $investableFloat = min((float) $stock->getCorporateTreasury(), (float) $stock->getCustomerDeposits());

        return [
            'value' => max(0.0, $investableFloat) * $this->resolveFloatEquityWeight($stock),
            'expected_return' => $this->resolveFloatEquityReturn($macroState),
        ];
    }

    /**
     * The book capital ratio at which the firm's statutory surplus carries its written premium at its own target
     * premium-to-surplus: an underwriter converges to its own leverage target (Gropp & Heider 2010; for insurers'
     * NPW/surplus, Cheng & Weiss 2012), and the engine returns the surplus above it by partial adjustment (Berger
     * et al. 2008, CapitalAllocationEngine::capitalTargetRepurchase()). Unrealised marks and goodwill sit outside
     * statutory surplus and pass through. None before the first report has struck the target.
     */
    public function getTargetCapitalRatio(Stock $stock, ?MacroStateDTO $macroState = null): ?float
    {
        $targetPremiumToSurplus = (float) (($stock->getEarningsMomentumZ() ?? [])[self::STATE_TARGET_PREMIUM_TO_SURPLUS] ?? 0.0);
        $premium = (float) $stock->getTotalRevenue();
        if ($targetPremiumToSurplus <= 0.0 || $premium <= 0.0) {
            return null;
        }

        $equity = (float) $stock->getTotalEquity();
        $targetEquity = ($premium / $targetPremiumToSurplus) + ($equity - $stock->getStatutorySurplus());
        $targetAssets = $stock->getTotalAssets() - $equity + $targetEquity;

        return $targetEquity > 0.0 && $targetAssets > $targetEquity ? $targetEquity / $targetAssets : null;
    }

    /**
     * An underwriter that declines to write at the rate on offer is holding surplus behind a book it is not
     * writing, and that surplus is redundant until rates recover. Handing it back is what closes the
     * capacity cycle: the industry's capital falls, the glut it was pricing clears, rates harden, and the
     * capital that comes back is deployed into a book worth writing. A firm that keeps it instead simply
     * accumulates until it is a fund with an insurance licence.
     *
     * It reads the book getTargetMetrics() writes, min(D·w, 1.5·S), so the two cannot disagree: whatever fraction
     * of Kenney capacity that book leaves unused, through declined lines or demand the capital has outgrown, is
     * the fraction of surplus this returns.
     */
    public function getUndeployableCapitalShare(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        $capacity = $this->resolveKenneyCapacity($stock);
        if ($capacity <= 0.0) {
            return 0.0;
        }

        $written = min($capacity, $this->resolvePremiumDemand($stock, $macroState) * $this->resolveWrittenCapacity($stock, $macroState, $mathUtility));

        return max(0.0, min(1.0, 1.0 - ($written / $capacity)));
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
        // The float backed in full, the claims it owes policyholders, plus the operating buffer a manager chooses
        // above it; only the buffer is the manager's to set (calculateLiabilityBackingCash()).
        return $this->calculateLiabilityBackingCash($currentLiability, $wholesaleDebt) + ($operatingBase * self::TARGET_OPERATING_BUFFER);
    }

    public function calculateLiabilityBackingCash(float $currentLiability, float $wholesaleDebt): float
    {
        return $currentLiability * self::TARGET_FLOAT_BUFFER;
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

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt, ?MacroStateDTO $macroState = null): InterestExpenseDTO
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

        // For an insurer, quarterly underwriting claim payouts (EBIT < 0) do not constitute corporate debt default as
        // long as investment/float yield and capital surplus service wholesale obligations. When underwriting claim
        // payouts turn total EBIT negative, evaluate coverage using depreciation and serviceable income.
        $serviceableIncome = $ebit < 0.0 ? max(0.0, $ebit + $interestExpense) : $ebit;
        return ($serviceableIncome + $depreciation) / max(0.01, $interestExpense);
    }

    /**
     * Returns the base float yield for the 15% liquidity + 75% long-duration bond tranches only.
     * The 10% equity tranche is the equity sleeve (resolveEquitySleeve()), marked to the board.
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
        $liquidityReturn = FixedIncome::moneyMarketYield($policyRate);

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
     * marked and the book that pays are one book. The liquidity tranche is bills and the equity tranche is marked to
     * the board (resolveEquitySleeve()); neither belongs to the curve, so neither is marked here.
     */
    public function resolveSecuritiesBook(Stock $stock, ?float $currentTreasury = null): float
    {
        $cash = $currentTreasury ?? (float) $stock->getCorporateTreasury();
        $investableFloat = min($cash, (float) $stock->getCustomerDeposits());

        if ($investableFloat <= 0.0) {
            return 0.0;
        }

        $fixedIncomeWeight = max(0.0, 1.0 - $this->resolveFloatEquityWeight($stock));
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

    /**
     * calculateStructuralRoic() fades the trailing return toward through-the-cycle capacity, so it reaches it unfaded
     * here.
     */
    public function getValuationReturn(float $trailingReturn, ?float $longRunReturn, float $discountRate): float
    {
        return $trailingReturn;
    }

    public function calculateStructuralRoic(float $roicTtm, float $baselineRoic, float $revenuePerShare, float $bookValuePerShare, float $baselineMargin, float $discountRate): float
    {
        // DuPont Decomposition anchored by Kenney Rule capacity (Premium-to-Surplus ratio = 1.50)
        $actualTurnover = $bookValuePerShare > 0.0 ? ($revenuePerShare / $bookValuePerShare) : self::KENNEY_CAPACITY_RATIO;
        $effectiveTurnover = min(self::KENNEY_CAPACITY_RATIO, max(self::MIN_WRITTEN_CAPACITY * self::KENNEY_CAPACITY_RATIO, $actualTurnover));

        // Separate underwriting return from float investment return to preserve investment income capacity.
        $fullCapacityUnderwriting = self::KENNEY_CAPACITY_RATIO * $baselineMargin;
        $investmentLeg = max(0.0, $baselineRoic - $fullCapacityUnderwriting);

        $structuralRoe = ($effectiveTurnover * $baselineMargin) + $investmentLeg;

        // The trailing ROE's gap to through-the-cycle capacity is valued at its persistent-equivalent share, the same
        // Ohlson / Fama-French fade every other firm's trailing return gets (TimeSeries::persistentEquivalentReturn).
        return max(self::MIN_STRUCTURAL_ROE_FLOOR, TimeSeries::persistentEquivalentReturn($roicTtm, $structuralRoe, $discountRate));
    }

    /**
     * The float is three stocks, each with its own clock: premium written and not yet earned, claims incurred and
     * not yet paid, and (for a life book) policy reserves. Δfloat = (written − earned) + (incurred − paid), and
     * cash moves with it: the P&L booked earned premium and incurred cost, so the treasury is credited when
     * premium is collected and debited when a claim is paid.
     */
    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $incurred = $momentum[self::STATE_INCURRED_CLAIMS] ?? null;
        if ($incurred === null) {
            return;
        }

        $float = max(0.0, (float) $state['customerDeposits']);
        $opening = $this->resolveOpeningReserves($stock, $float, $momentum);
        $lifeIncurred = min(max(0.0, (float) $incurred), (float) ($momentum[self::STATE_INCURRED_LIFE_BENEFITS] ?? 0.0));

        $unearnedBase = $momentum[self::STATE_UNEARNED_PREMIUM_BASE] ?? null;
        $unearned = $unearnedBase === null ? $opening['unearned'] : $this->resolveUnearnedPremiumReserve((float) $unearnedBase);
        $loss = $this->rollLossReserves($opening['loss'], max(0.0, (float) $incurred - $lifeIncurred), $this->resolveReserveRunoffYears($stock))['reserves'];
        $life = $opening['life'] > 0.0 || $lifeIncurred > 0.0
            ? $this->rollLossReserves($opening['life'], $lifeIncurred, $this->resolveLifeReserveRunoffYears($stock))['reserves']
            : 0.0;

        $closing = $unearned + $loss + $life;
        $state['treasury'] += $closing - $float;
        $state['customerDeposits'] = $closing;

        if ($state['treasury'] < 0.0) {
            $liquidityShortfall = abs($state['treasury']);
            $state['treasury'] = 0.0;
            $state['wholesaleDebt'] += $liquidityShortfall;
            $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
            $state['events'][] = ['description' => "Claim payouts exceeded cash reserves. Forced to borrow \${$amtB}B."];
        }

        $stock->setCustomerDeposits((string) $closing);
        $momentum[self::STATE_UNEARNED_PREMIUM] = $unearned;
        $momentum[self::STATE_LIFE_RESERVES] = $life;
        $stock->setEarningsMomentumZ($momentum);
    }

    /**
     * The float at the last quarter end split into its stocks. A book with no recorded split (a fresh seed, or one
     * carried over from before the split) opens on its steady composition; float that left or joined outside the
     * roll-forward (a merger, a run) is taken from or given to the loss reserve.
     *
     * @param array<string, float> $momentum
     * @return array{unearned: float, loss: float, life: float}
     */
    public function resolveOpeningReserves(Stock $stock, float $float, array $momentum): array
    {
        $ratios = $this->resolveReserveRatios($stock);
        $base = $momentum[self::STATE_UNEARNED_PREMIUM_BASE] ?? null;
        $unearned = max(0.0, (float) ($momentum[self::STATE_UNEARNED_PREMIUM]
            ?? ($base === null ? 0.0 : $this->resolveUnearnedPremiumReserve((float) $base))));

        $life = $momentum[self::STATE_LIFE_RESERVES] ?? null;
        if ($life === null) {
            $claimReserveRatio = $ratios['loss'] + $ratios['life'];
            $life = $claimReserveRatio > 0.0 ? max(0.0, $float - $unearned) * $ratios['life'] / $claimReserveRatio : 0.0;
        }
        $life = max(0.0, (float) $life);

        $loss = $float - $unearned - $life;
        if ($loss < 0.0) {
            $scale = ($unearned + $life) > 0.0 ? $float / ($unearned + $life) : 0.0;

            return ['unearned' => $unearned * $scale, 'loss' => 0.0, 'life' => $life * $scale];
        }

        return ['unearned' => $unearned, 'loss' => $loss, 'life' => $life];
    }

    /** Unearned premium on a book written evenly and earned pro rata over its term: half a term of annual premium. */
    public function resolveUnearnedPremiumReserve(float $quarterlyPremium): float
    {
        return max(0.0, $quarterlyPremium / FinancialConstants::QUARTER_YEARS) * self::POLICY_TERM_YEARS / 2.0;
    }

    /**
     * One quarter of a reserve stock paid out at rate 1/τ (exponential runoff, the continuous form of a
     * paid-loss development pattern): reserves move toward annualized incurred × τ, and what leaves is paid.
     *
     * @return array{reserves: float, paid: float}
     */
    public function rollLossReserves(float $reserves, float $incurred, float $runoffYears): array
    {
        $dt = FinancialConstants::QUARTER_YEARS;
        $settled = max(0.0, TimeSeries::calculateDistributedLag(
            currentLaggedValue: $reserves,
            targetValue: ($incurred / $dt) * $runoffYears,
            dt: $dt,
            lagTimeConstant: $runoffYears,
        ));

        return ['reserves' => $settled, 'paid' => $reserves + $incurred - $settled];
    }

    /**
     * Each reserve stock per unit of the firm's annual premium once settled. A P&C book carries the NAIC loss and
     * LAE reserve and half a term of unearned premium; subclasses weight them by the books that carry them.
     *
     * @return array{unearned: float, loss: float, life: float}
     */
    public function resolveReserveRatios(Stock $stock): array
    {
        return ['unearned' => self::POLICY_TERM_YEARS / 2.0, 'loss' => self::LOSS_RESERVE_TO_PREMIUM_RATIO, 'life' => 0.0];
    }

    /** Share of the firm's premium written on life policies, whose incurred benefits build the policy reserve. */
    public function resolveLifePremiumShare(Stock $stock): float
    {
        return 0.0;
    }

    /**
     * Float per unit of annual premium the firm's book carries once its reserves have settled: the sum of its stocks.
     */
    public function resolveReserveToPremiumRatio(Stock $stock): float
    {
        return array_sum($this->resolveReserveRatios($stock));
    }

    /**
     * Loss and LAE incurred per unit of premium on a steady book at neutral rates: the premium-proportional cost line
     * less commissions and taxes, plus the claims-department share of the sticky overhead.
     */
    public function resolveStructuralLossRatio(Stock $stock): float
    {
        $fixedCostRatio = (float) $stock->getFixedCostRatio();

        return (1.0 - (float) $stock->getOperatingMargin())
            * (((1.0 - $fixedCostRatio) * (1.0 - self::BASE_EXPENSE_RATIO_SHARE)) + ($fixedCostRatio * self::FIXED_COST_LAE_SHARE));
    }

    /**
     * Mean years from incurred to paid on the non-life book, set so a book growing with the economy at trend holds its
     * pinned loss reserve ratio (resolveRunoffYearsAtTrend()).
     */
    public function resolveReserveRunoffYears(Stock $stock): float
    {
        return self::resolveRunoffYearsAtTrend(
            $this->resolveReserveRatios($stock)['loss'],
            $this->resolveStructuralLossRatio($stock) * (1.0 - $this->resolveLifePremiumShare($stock)),
        );
    }

    /** Mean years a life book's policy reserve is held, set so a book growing at trend holds its pinned ratio. */
    public function resolveLifeReserveRunoffYears(Stock $stock): float
    {
        return self::resolveRunoffYearsAtTrend(
            $this->resolveReserveRatios($stock)['life'],
            $this->resolveStructuralLossRatio($stock) * $this->resolveLifePremiumShare($stock),
        );
    }

    /**
     * The runoff at which a reserve fed by incurred losses holds its ratio to a premium growing at the economy's
     * nominal trend: R/P = incurred / (1/τ + g), so 1/τ = incurred/R − g. The pinned ratios are measured on books
     * that grow (NAIC, ACLI, Swiss Re); struck for a book that never does, every reserve fell behind its ratio as the
     * economy grew, a life book's to two thirds of it in twenty years.
     */
    private static function resolveRunoffYearsAtTrend(float $reserveRatio, float $incurred): float
    {
        $ratio = max(0.01, $reserveRatio);

        return min(self::MAX_RESERVE_RUNOFF_YEARS, $ratio / max(1e-9, max(0.01, $incurred) - (self::TREND_BOOK_GROWTH * $ratio)));
    }

    /**
     * Float per unit of annual premium a NEW book brings within the horizon: its unearned premium at once, and a share
     * 1 − e^(−H/τ) of each claim reserve's settled level.
     */
    public function resolveBookFloatRatio(Stock $stock, float $horizonYears): float
    {
        $ratios = $this->resolveReserveRatios($stock);

        return $ratios['unearned']
            + ($ratios['loss'] * (1.0 - exp(-$horizonYears / $this->resolveReserveRunoffYears($stock))))
            + $this->resolveBookLifeFloatRatio($stock, $horizonYears);
    }

    /** The life reserves' part of that float: a share 1 − e^(−H/τ) of the policy reserve's settled level. */
    public function resolveBookLifeFloatRatio(Stock $stock, float $horizonYears): float
    {
        return $this->resolveReserveRatios($stock)['life'] * (1.0 - exp(-$horizonYears / $this->resolveLifeReserveRunoffYears($stock)));
    }

    /**
     * Share of the float now held that is still held after the horizon on a book that stops writing: claim reserves run
     * off, unearned premium is all earned.
     */
    public function resolveHeldFloatSurvival(Stock $stock, float $horizonYears): float
    {
        $ratios = $this->resolveReserveRatios($stock);
        $total = array_sum($ratios);
        if ($total <= 0.0) {
            return 0.0;
        }

        return (($ratios['loss'] * exp(-$horizonYears / $this->resolveReserveRunoffYears($stock)))
            + ($ratios['life'] * exp(-$horizonYears / $this->resolveLifeReserveRunoffYears($stock)))) / $total;
    }

    /**
     * Loss and LAE per unit of premium on the quarter's baseline cost line, before any excess claims: what a book the
     * catastrophe draw does not touch (a life book) incurs.
     */
    protected function resolveBaselineLossRatio(float $variableCostRatio, float $fixedCosts, float $premium): float
    {
        return ($variableCostRatio * (1.0 - self::BASE_EXPENSE_RATIO_SHARE)) + (self::FIXED_COST_LAE_SHARE * $fixedCosts / max(1.0, $premium));
    }

    /**
     * The combined ratio split as an insurer files it, each over premium (NAIC Insurance Expense Exhibit lines): loss
     * and LAE (claims net of the cover's recovery, with any reinstatement premium, plus the adjusting and other share
     * of the overhead) and underwriting expense (commissions and taxes, plus the rest of the overhead). Losses take
     * whatever the margin clamp removed, so the two add to the quarter's cost base over premium.
     *
     * @return array{loss_ratio: float, expense_ratio: float}
     */
    protected function underwritingRatioKpis(float $costRatio, float $variableExpenseRatio, float $fixedCosts, float $premium): array
    {
        $fixedCostRatio = $fixedCosts / max(1.0, $premium);
        $lossRatio = max(0.0, $costRatio - $variableExpenseRatio) + ($fixedCostRatio * self::FIXED_COST_LAE_SHARE);

        return [
            'loss_ratio' => $lossRatio,
            'expense_ratio' => $costRatio + $fixedCostRatio - $lossRatio,
        ];
    }

    /**
     * Files the quarter's loss and expense ratios and books what the reserve roll-forward needs, from one split. Only
     * losses and LAE are reserved (SSAP 55, ASC 944-40); acquisition and underwriting expenses are expensed or deferred
     * as incurred (SSAP 71, ASC 944-30), and a reinstatement premium is ceded premium. The premium on policies with a
     * term builds the unearned premium reserve (SSAP 65, ASC 944-605); a life book's benefits build its policy reserve.
     *
     * @return array{loss_ratio: float, expense_ratio: float}
     */
    protected function bookUnderwritingResult(StreamContext $streams, float $premium, float $costRatio, float $variableExpenseRatio, float $reinstatementRatio, float $fixedCosts, float $unearnedPremiumBase, float $lifeBenefits = 0.0): array
    {
        $kpis = $this->underwritingRatioKpis($costRatio, $variableExpenseRatio, $fixedCosts, $premium);
        $streams->registerState(self::STATE_INCURRED_CLAIMS, max(0.0, $premium * ($kpis['loss_ratio'] - $reinstatementRatio)));
        $streams->registerState(self::STATE_INCURRED_LIFE_BENEFITS, max(0.0, $lifeBenefits));
        $streams->registerState(self::STATE_UNEARNED_PREMIUM_BASE, max(0.0, $unearnedPremiumBase));

        // The reserve split is advanced outside this pass, so it is carried through the stream map explicitly.
        foreach ([self::STATE_UNEARNED_PREMIUM, self::STATE_LIFE_RESERVES] as $key) {
            $carried = $streams->getPersistedState($key, -1.0);
            if ($carried >= 0.0) {
                $streams->registerState($key, $carried);
            }
        }

        return $kpis;
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr): bool
    {
        // For Insurance companies, Customer Deposits represent policyholder reserves ("The Float").
        // Float scales with underwriting policy volume and claim payout schedules, not discretionary capital
        // structure financing. An insurer should never trigger forced "underleveraged" share buyback spirals
        // simply because its float-to-equity ratio fluctuates.
        return false;
    }

    public function getBankruptEquityThreshold(): float
    {
        return self::BANKRUPT_EQUITY_THRESHOLD;
    }
    public function getDistressEquityThreshold(): float
    {
        return self::DISTRESS_EQUITY_THRESHOLD;
    }
    public function getWarningEquityThreshold(): float
    {
        return self::WARNING_EQUITY_THRESHOLD;
    }

    /**
     * NAIC Model Act #440, section 5: without the commissioner's prior approval an insurer may pay out in any twelve
     * months the greater of 10% of its policyholder surplus or its net income for the year, statutory, so with the
     * gains its securities sales realised. A carrier whose earnings have gone holds its distributions to a tenth of
     * its surplus, however long its board would hold the dividend.
     */
    public function getDistributionCeiling(Stock $stock, float $trailingNetIncome): ?float
    {
        return max(self::NAIC_ORDINARY_DIVIDEND_SURPLUS_SHARE * max(0.0, $stock->getStatutorySurplus()), max(0.0, $trailingNetIncome));
    }

    /**
     * An insurer's regulator halts its distributions on solvency (NAIC RBC, Solvency II): a capital ratio in
     * distress, or surplus below half the Kenney target its premium needs. Its float is owed to policyholders, not
     * lenders, so the bank tiers that count it as debt do not apply; struck on them, a composite carrying ACLI's life
     * reserves was barred from buybacks at any capital.
     */
    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury, ?MacroStateDTO $macroState = null): ?float
    {
        // Solvency is measured on capital that can pay a claim: goodwill is valued at nil.
        $equity = $stock->getTangibleEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $totalAssets = max(1.0, $equity + $totalDebt);
        $capitalRatio = ($equity / $totalAssets) * 100.0;

        if ($capitalRatio < self::DISTRESS_EQUITY_THRESHOLD || $equity < ($this->resolveKenneyTargetSurplus($stock) * self::DISTRESS_TARGET_SURPLUS_SHARE)) {
            return 0.0;
        }

        return null;
    }

    /**
     * Repurchases stop while statutory surplus is short of the Kenney target: capital an insurer lacks is not handed
     * back.
     */
    public function checkBuybackRegulatoryLockout(Stock $stock, float $currentTreasury, ?MacroStateDTO $macroState = null): ?bool
    {
        $cap = $this->getRegulatoryDividendCap($stock, $currentTreasury, $macroState);

        return ($cap !== null && $cap <= 0.0) || $stock->getStatutorySurplus() < $this->resolveKenneyTargetSurplus($stock);
    }

    /** The surplus the firm's premium needs at the Kenney ratio. */
    private function resolveKenneyTargetSurplus(Stock $stock): float
    {
        return (float) $stock->getTotalRevenue() / self::KENNEY_CAPACITY_RATIO;
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
            'catastrophe_loss_index',
            'catastrophe_loss_index_ema',
            'core_goods_inflation_ema',
            'gdp_deflator',
            'output_gap_ema',
            'output_gap_lag_6m',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'potential_gdp_index',
            'supercore_inflation_ema',
            'yield_10y_ema',
        ];
    }

    /**
     * An underwriter's premium target reads this state (getTargetMetrics), so the state carries the reversion its
     * book's turnover follows: the quarter blended in, then pulled toward the cost of equity plus moat.
     */
    protected function resolveReturnState(Stock $stock, float $trailing, float $realised, float $costOfEquity, float $equity, ?MacroStateDTO $macroState): float
    {
        return $this->revertTrailingReturn($stock, $trailing, $realised, $costOfEquity, static::TTM_ROE_WEIGHT, $equity, $macroState);
    }
}

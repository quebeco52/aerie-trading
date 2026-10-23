<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\InterestExpenseDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Market\Bond\RatingMethodology;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FixedIncome;
use App\Service\Model\Trait\StandardBaseModelTrait;
use App\Service\Model\Trait\StandardCapitalAllocationTrait;
use App\Service\Model\Trait\StandardDebtPhysicsTrait;
use App\Service\Model\Trait\StandardMaTrait;
use App\Service\Model\Trait\StandardOperatingPhysicsTrait;
use App\Service\Model\Trait\StandardTreasuryTrait;
use App\Service\Model\Trait\StandardValuationTrait;

/**
 * The one base every business model extends: the standard physics, and every hook constant the standard physics reads
 * declared once with its default. A sector model changes a default by redeclaring the constant; a misspelt read is a
 * PHPStan error rather than a silent fallback. StandardCorporateBusinessModel and BaseFinancialBusinessModel are the
 * two roots below it.
 */
abstract class AbstractBusinessModel implements BusinessModelInterface
{
    use StandardBaseModelTrait;
    use StandardOperatingPhysicsTrait;
    use StandardTreasuryTrait;
    use StandardCapitalAllocationTrait;
    use StandardDebtPhysicsTrait;
    use StandardMaTrait;
    use StandardValuationTrait;

    // --- Firm-Level Common Factor ---
    /** One-factor loading of each revenue stream on the firm-wide demand innovation (rho^2 = 36% shared variance). */
    public const FIRM_FACTOR_LOADING = 0.60;
    /**
     * Two-factor loading of each revenue stream on the persistent macro-sector demand factor (rho_s^2 = 16% variance
     * shared with sector peers).
     */
    public const SECTOR_FACTOR_LOADING = 0.40;

    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle: 1.0 moves one for one with the output gap. */
    public const OPERATING_CYCLICALITY = 1.00;
    /** Pricing power index, from 0 for a pure price taker to 1 for a price setter: the median firm's 0.5. */
    public const PRICING_POWER_INDEX = 0.5;
    /** Own-price elasticity of demand, the volume lost per unit of real price increase (0.50). */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.50;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers, not won from a larger market (0.50). */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;
    /** Years for a move in the output gap to reach the order book: none, a business that sells as demand appears. */
    public const DEMAND_LAG_YEARS = 0.0;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate (0.05): a mostly domestic firm
     * meeting a little imported competition.
     */
    public const FX_REVENUE_EXPOSURE = 0.05;

    // --- Inflation Pass-Through ---
    /**
     * Macro inflation measure selling prices track: goods breakevens by default; services models price off supercore.
     */
    public const PRICING_INFLATION_BASIS = 'tips_breakeven_ema';
    /**
     * Macro inflation measure input costs track, for a model whose costs inflate on another basis than its prices; null
     * leaves costs on PRICING_INFLATION_BASIS.
     *
     * @var string|null
     */
    public const COST_INFLATION_BASIS = null;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base bought in each tracked input market, measured in InputOutputExposures; null buys
     * the all-private-industries basket and declares none of the firm's own.
     *
     * @var array<string, float>|null
     */
    public const INPUT_COST_EXPOSURES = null;
    /** Years for spot input moves to reach the cost base: none, a spot buyer; hedges and contracts lengthen it. */
    public const INPUT_COST_LAG_YEARS = 0.0;
    /** Years for the recoverable part of an input move to reach selling prices (0.75; menu costs, repricing). */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.75;
    /**
     * Payroll's share of the cost base the wage factor and severance scale; null takes the labor share measured into
     * the declared input basket, or 0.65 where it measures none.
     *
     * @var float|null
     */
    public const FIXED_COST_LABOR_SHARE = null;
    /** Payroll's share of the cost base for a model that declares neither its own share nor a labour input (0.65). */
    public const UNMEASURED_LABOR_SHARE = 0.65;

    // --- Analyst Coverage ---
    /** Analysts' visibility into a routine quarter before the systemic-importance uplift (20%). */
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    /** Standard deviation of the analysts' own forecast error (6%). */
    public const BASE_COVERAGE_ERROR = 0.06;
    /** Visibility floor a routine quarter never falls below before the systemic-importance uplift: none. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.0;
    /**
     * Visibility of a quarter carrying an announced event (a trial readout, a recall, a missed hurdle); null for a
     * model whose quarters carry none.
     *
     * @var float|null
     */
    public const EVENT_BASE_VISIBILITY = null;
    /**
     * Visibility floor of a quarter carrying an announced event; null for a model whose quarters carry none.
     *
     * @var float|null
     */
    public const EVENT_MIN_VISIBILITY = null;

    // --- Balance Sheet & Reporting ---
    /** Capitalised operating lease liability (IFRS 16 / ASC 842) as a share of annual revenue (5%). */
    public const LEASE_LIABILITY_INTENSITY = 0.05;
    /** Stock-based compensation as a share of revenue (1.57%, the US total market). */
    public const STOCK_COMPENSATION_INTENSITY = 0.0157;
    /** Probability management reaches for accruals to land a reachable quarter on consensus (0.50). */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.50;

    // --- Capital Structure ---
    /** Target leverage as a share of the sector's debt tolerance (0.75). */
    public const UNDERLEVERAGED_DEBT_RATIO = FinancialConstants::CORPORATE_UNDERLEVERAGED_RATIO;
    /**
     * Interest coverage a firm must hold before it levers up; null derives it from the minimum ICR (1.5x it, at least
     * 2x).
     *
     * @var float|null
     */
    public const MIN_RECAP_ICR_FLOOR = null;

    // --- Credit Rating ---
    /** The rating agency's methodology: S&P's corporate one, Altman's Z'' and the standard volatility table. */
    public const RATING_METHODOLOGY = RatingMethodology::Corporate;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.20 a year). */
    public const ROIC_REVERSION_SPEED = 0.20;
    /** Excess return over the required return the moat sustains: none. */
    public const MOAT_SPREAD = 0.000;
    /** Speed of the variable margin's reversion to its structural level (4.0 a year). */
    public const MARGIN_REVERSION_SPEED = 4.0;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (1.5). */
    public const CAPEX_CYCLICALITY = 1.5;
    /** Share of construction in progress completed into plant each quarter (33%, a build of about nine months). */
    public const CAPEX_COMPLETION_RATE = 0.33;
    /** Net working capital as a share of revenue for a model that declares no balances of its own (10%). */
    public const WORKING_CAPITAL_INTENSITY = 0.10;

    // --- Seasonality ---
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: flat by default.
     *
     * @var array<int, float>
     */
    public const SEASONALITY_FACTORS = [1.0, 1.0, 1.0, 1.0];

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (2.0x). */
    public const MIN_ICR = 2.00;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (1.5x). */
    public const DIVIDEND_CRISIS_ICR = 1.50;
    /** Interest coverage below which the firm stops repurchasing stock (2.0x). */
    public const BUYBACK_MIN_ICR = 2.00;
    /** Most wholesale debt the model carries per unit of equity (1.0x). */
    public const WHOLESALE_LEVERAGE_LIMIT = 1.0;
    /** Capital ratio, in percent of assets, below which an institution is bankrupt: unused by an operating firm. */
    public const BANKRUPT_EQUITY_THRESHOLD = 0.0;
    /** Capital ratio, in percent of assets, below which an institution is in distress: unused by an operating firm. */
    public const DISTRESS_EQUITY_THRESHOLD = 0.0;
    /**
     * Capital ratio, in percent of assets, below which an institution is in the grey zone: unused by an operating firm.
     */
    public const WARNING_EQUITY_THRESHOLD = 0.0;
    /** Share of fixed-rate debt that matures and reprices each quarter (5%, a five-year average tenor). */
    public const DEBT_MATURITY_ROLLOVER_RATE = 0.05;
    /** Years of expected loss the credit-loss allowance covers (one). */
    public const CREDIT_LOSS_HORIZON_YEARS = 1.0;

    // --- Cash Hoarding ---
    /**
     * What excess cash is measured against to call it a hoard: the operating base, or total debt for a lender whose
     * cash backs its funding.
     *
     * @var 'operating_base'|'total_debt'
     */
    public const HOARDING_BASIS = 'operating_base';
    /** Excess cash, as a share of the hoarding basis, above which the firm is a hoarder (25%). */
    public const HOARDER_THRESHOLD = FinancialConstants::HOARDER_THRESHOLD_RATIO;
    /** Excess cash, as a share of the hoarding basis, above which the firm is a mega-hoarder (40%). */
    public const MEGA_HOARDER_THRESHOLD = FinancialConstants::MEGA_HOARDER_THRESHOLD_RATIO;

    // --- Valuation ---
    /** Weight of book value against earnings value in the consensus fair value (10%). */
    public const FAIR_VALUE_BOOK_WEIGHT = FinancialConstants::FAIR_VALUE_BOOK_WEIGHT;

    /**
     * One-factor loading that every revenue stream drawn through createStreamContext() places on the firm-wide demand
     * innovation. A sector model changes it by redeclaring FIRM_FACTOR_LOADING alone.
     */
    public function getFirmFactorLoading(): float
    {
        return static::FIRM_FACTOR_LOADING;
    }

    /**
     * Loading every revenue stream places on the persistent demand factor of the firm's macro sector
     * (MacroStateDTO::$sectorDemandZ). Peers in one sector share customers and input markets, so a slump in industrial
     * orders reaches every machinery maker's book together; the firm factor carries the remainder. rho_f^2 + rho_s^2
     * must stay below one.
     */
    public function getSectorFactorLoading(): float
    {
        return static::SECTOR_FACTOR_LOADING;
    }

    /**
     * Market interest on all of the firm's debt: the fixed share at its blended coupon, the floating share at the
     * floating rate. A firm with no cheap customer deposits pays the market on every dollar it owes.
     */
    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $fundedDebt, ?MacroStateDTO $macroState = null): InterestExpenseDTO
    {
        $interestExpense = FixedIncome::fixedFloatingInterest($fundedDebt, (float) $stock->getFloatingDebtRatio(), $blendedFixedRate, $floatingInterestRate);

        return new InterestExpenseDTO(interestExpense: $interestExpense, wholesaleRate: $fundedDebt > 0 ? ($interestExpense / $fundedDebt) : $currentMarketFixedRate);
    }
}

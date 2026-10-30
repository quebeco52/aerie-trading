<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\SectorPhysicsResult;

use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Financial Data & Ratings (Rating Agencies, Market Data).
 *
 * Financial Physics:
 * - Asset-light, ultra-high margin monopolies.
 * - Revenue is incredibly sticky due to mandatory recurring subscriptions and licensing fees.
 * - Immune to supply chain inflation (they sell digital data, not physical goods).
 * - Exceptionally low idiosyncratic variance.
 */
class FinancialDataBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Subscriptions renew
     * through the cycle; issuance fees are cyclical.
     */
    public const OPERATING_CYCLICALITY = 0.90;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.20;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.30;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::FINANCIAL_DATA;

    // --- Pricing Power ---
    /** Mandatory terminal and ratings subscriptions reprice on renewal with little pushback. */
    public const PRICING_POWER_INDEX = 0.85;

    // --- Balance Sheet Realism ---
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: Information Services, 1.62% of revenue, 15 firms
     * (Damodaran, Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0162;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Secular Demand ---
    /**
     * Data processing, internet publishing and other information services value added as a share of US nominal GDP in
     * 1997 (BEA GDP by Industry, value added).
     */
    public const SECULAR_SHARE_1997 = 0.0037;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0127;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.1 a year). */
    public const ROIC_REVERSION_SPEED = 0.1;
    /** Excess return over the required return the moat sustains (2.5pp). */
    public const MOAT_SPREAD = 0.025;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.5). */
    public const CAPEX_CYCLICALITY = 0.5;
    /** Share of construction in progress completed into plant each quarter (100%). */
    public const CAPEX_COMPLETION_RATE = 1.0;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    // --- Dual-Stream Financial Data Architecture ---
    /** Baseline fraction of revenue derived from recurring multi-year terminal & rating subscriptions. */
    public const SUBSCRIPTION_REVENUE_WEIGHT = 0.85;
    /** Baseline fraction of revenue derived from capital markets issuance ratings & transaction feed volume. */
    public const TRANSACTION_REVENUE_WEIGHT  = 0.15;

    // --- Working Capital by Stream (shares of the stream's revenue) ---
    /**
     * Subscriptions: seats invoiced quarterly to yearly and paid in ~55 days, ~80 days of revenue held as deferred
     * revenue and payables.
     */
    public const SUBSCRIPTION_WORKING_CAPITAL = ['receivables' => 0.15, 'inventory' => 0.0, 'payables' => 0.22];
    /**
     * Transactions: rating and trading fees billed on issue or monthly, paid in ~44 days; ~11 days of sales owed out.
     */
    public const TRANSACTION_WORKING_CAPITAL = ['receivables' => 0.12, 'inventory' => 0.0, 'payables' => 0.03];

    /** Data holds no stock: subscribers pay ahead of the service and issuers after the rating. */
    protected function resolveWorkingCapitalShares(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionRevenueWeight->value => self::SUBSCRIPTION_REVENUE_WEIGHT,
            ModelParam::TransactionRevenueWeight->value  => self::TRANSACTION_REVENUE_WEIGHT,
        ]);

        return FirmEconomics::blendWorkingCapitalShares(
            [$params[ModelParam::SubscriptionRevenueWeight], $params[ModelParam::TransactionRevenueWeight]],
            [self::SUBSCRIPTION_WORKING_CAPITAL, self::TRANSACTION_WORKING_CAPITAL],
        );
    }

    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in subscription data models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.05;

    // --- Subscription & Transaction Revenue Streams ---
    /** Operating margin mean reversion speed: slower speed reflects high switching costs and data monopoly moat. */
    public const MARGIN_REVERSION_SPEED  = 2.0;

    // --- Capital Markets Deal Activity Transmission ---
    /** Sensitivity of credit rating issuance fees and market data feed volume to aggregate deal activity. */
    public const DEAL_ACTIVITY_RATING_SENSITIVITY = 0.25;

    // --- Transaction Stream Macro Channel ---
    /**
     * Rating-issuance revenue per unit of IG spread below MacroConstants::BASE_CREDIT_SPREAD (+2% per 100bp of
     * tightening).
     */
    public const DCM_SPREAD_ISSUANCE_SENSITIVITY = 2.0;
    /** Rating-issuance revenue per unit of output gap, before operating cyclicality (+1.5% per 1% gap). */
    public const DCM_OUTPUT_GAP_ISSUANCE_SENSITIVITY = 1.5;
    /**
     * Market-data feed revenue per unit of volatility over MacroConstants::MACRO_VOL_BASE_ANCHOR, both ways (+0.5% per
     * vol point).
     */
    public const MARKET_DATA_VOLATILITY_SENSITIVITY = 0.50;

    // --- Asset-Light Subscription Capital Structure Rails ---
    /** Minimum interest coverage ratio required to permit recapitalization for subscription monopolies. */
    public const MIN_RECAP_ICR_FLOOR          = 6.0;
    /** Target leverage as a share of the debt tolerance; below it the firm is under-levered. */
    public const UNDERLEVERAGED_DEBT_RATIO    = 0.60;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionRevenueWeight->value => self::SUBSCRIPTION_REVENUE_WEIGHT,
            ModelParam::TransactionRevenueWeight->value  => self::TRANSACTION_REVENUE_WEIGHT,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Revenue Mix ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'subscription' => $params[ModelParam::SubscriptionRevenueWeight],
            'transaction'  => $params[ModelParam::TransactionRevenueWeight],
        ]);

        $subscriptionWeight = $activeWeights['subscription'];
        $transactionWeight  = $activeWeights['transaction'];

        // Independent stream Z-scores with AR(1) persistence
        $subscriptionZ = $streams->generateZ('subscription', 0.50); // Recurring seat subscriptions & data licenses
        $transactionZ  = $streams->generateZ('transaction', 0.15); // Debt issuance credit rating mandates & API usage

        // DCM Debt Rating & Data Feed API Macro Channel: Rating issuance mandates (SHRK) surge when corporate debt
        // syndication booms (tight credit spreads + positive output gap). Market data API feeds (TICK) carry more
        // transaction volume when volatility runs above its anchor, less below.
        $transactionMacroBonus = $this->resolveTransactionMacroShift($stock, $macroState);

        $subscriptionRevenue = max(0.0, $expectedRevenue * $subscriptionWeight * (1.0 + ($subscriptionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))));
        $transactionRevenue  = max(0.0, $expectedRevenue * $transactionWeight * (1.0 + ($transactionZ * ($baselineVol * (self::REVENUE_VARIANCE_SCALAR * 5.0))) + $transactionMacroBonus));

        $streamRevenues = [
            'subscription' => $subscriptionRevenue,
            'transaction'  => $transactionRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Data and platform engineering payroll follows wage growth; subscription repricing recovers most of it.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    /**
     * Seat subscriptions renew through the cycle and the transaction stream carries its own cycle terms, so
     * the root shift keeps only the exchange-rate term.
     */
    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $physics['macro_demand_shift'] = $this->resolveFxDemandShift($macroState);

        return $physics;
    }

    /** The activity the cost base is staffed to: the transaction stream's macro shift at its target weight. */
    public function resolveSectorActivityShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionRevenueWeight->value => self::SUBSCRIPTION_REVENUE_WEIGHT,
            ModelParam::TransactionRevenueWeight->value  => self::TRANSACTION_REVENUE_WEIGHT,
        ]);
        $totalWeight = $params[ModelParam::SubscriptionRevenueWeight] + $params[ModelParam::TransactionRevenueWeight];
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        return ($params[ModelParam::TransactionRevenueWeight] / $totalWeight) * $this->resolveTransactionMacroShift($stock, $macroState);
    }

    /**
     * Rating issuance mandates surge when corporate debt syndication booms (tight credit spreads, positive output
     * gap) and deals close. Market data feed volume follows volatility: the volume-volatility relation (Karpoff
     * 1987) linearised at the sim's volatility anchor, so the term averages out there.
     */
    private function resolveTransactionMacroShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $creditSpreadGap = $macroState->macroCreditSpreadTrend - $macroState->macroCreditSpreadEma;
        $dcmIssuanceBoost = ($creditSpreadGap * self::DCM_SPREAD_ISSUANCE_SENSITIVITY)
            + ($macroState->outputGapEma * self::DCM_OUTPUT_GAP_ISSUANCE_SENSITIVITY * $this->getOperatingCyclicality($stock));
        $vixVolBoost = ($macroState->marketVolatilityEma - MacroConstants::MACRO_VOL_BASE_ANCHOR) * self::MARKET_DATA_VOLATILITY_SENSITIVITY;
        $dealActivityShift = $macroState->dealActivityDeviation();

        return $dcmIssuanceBoost + $vixVolBoost + ($dealActivityShift * self::DEAL_ACTIVITY_RATING_SENSITIVITY);
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
            'deal_activity_index_ema',
            'exchange_rate_index_ema',
            'import_price_level',
            'macro_credit_spread_ema',
            'market_volatility_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}

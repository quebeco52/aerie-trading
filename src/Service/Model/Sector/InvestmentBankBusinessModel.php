<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Pure-Play Investment Banks and M&A Advisory Syndicates.
 *
 * Financial Physics:
 * - Dual-Desk Architecture: Advisory (M&A/underwriting) and Sales & Trading are structurally decorrelated.
 * - Cost of Capital Deal Flow: M&A volumes scale with macro output gap and ERP; DCM scales with credit spreads and yield curve slope.
 * - Basel FRTB VaR Volatility Targeting: S&T desk captures volatility arbitrage up to regulatory capital limits.
 * - Options Gamma Hedging: High market volatility boosts top-line options premium but incurs localized gamma friction.
 * - Compensation-Driven Cost Structure: IB variable costs are dominated by discretionary bonuses (40–85%).
 */
class InvestmentBankBusinessModel extends BrokerageBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Deal flow is among the most cyclical fee pools. */
    public const OPERATING_CYCLICALITY = 1.40;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 deal-closing surge before year end; summer lull.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 1.00, 0.95, 1.10];
    }

    // --- Balance Sheet Realism ---
    /** Stock-based compensation as a fraction of revenue (ASC 718): non-cash, added back to FCF, settled in new shares. Deferred banker compensation is settled in restricted stock. */
    public const STOCK_COMPENSATION_INTENSITY = 0.06;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Banker and trader compensation is the dominant overhead line of an investment bank. */
    public const FIXED_COST_LABOR_SHARE = 0.70;

    // --- Dual-Desk Revenue Architecture ---
    /** Baseline revenue share allocated to advisory, M&A mandates, and capital markets underwriting. */
    public const ADVISORY_REVENUE_WEIGHT    = 0.40;
    /** Baseline revenue share allocated to institutional sales, trading, and market making. */
    public const TRADING_REVENUE_WEIGHT     = 0.60;
    /** Volatility scalar for trading desk revenue variance. */
    public const TRADING_VARIANCE_SCALAR    = 0.35;

    // --- Deal Flow Cost of Capital & Macro Elasticity ---
    /** Baseline macro credit spread (through-the-cycle IG). Spreads below this stimulate DCM debt syndication. */
    public const DEAL_BASELINE_CREDIT_SPREAD = MacroEngine::BASE_CREDIT_SPREAD;
    /** Neutral 5Y-over-policy term structure slope (~80bps: the five-year share of the term premium plus the usual policy discount). DCM stimulus is measured as deviation from this. */
    public const DCM_NEUTRAL_CURVE_SLOPE     = 0.008;
    /** Sensitivity of M&A deal flow to corporate expansion (output gap). */
    public const MNA_OUTPUT_GAP_ELASTICITY   = 3.00;
    /** Sensitivity of M&A deal flow to Equity Risk Premium (ERP) cost of capital changes. */
    public const MNA_ERP_ELASTICITY          = 5.00;
    /** Sensitivity of DCM bond issuance to corporate credit spread tightness. */
    public const DCM_CREDIT_SPREAD_ELASTICITY = 10.0;
    /** Sensitivity of DCM bond issuance to yield curve steepness. */
    public const DCM_CURVE_SLOPE_ELASTICITY  = 2.50;
    /** Sensitivity of advisory/underwriting deal flow to aggregate capital markets deal activity index (Jovanovic-Rousseau 2002). */
    public const DEAL_ACTIVITY_INDEX_ELASTICITY = 0.40;
    /** Sensitivity of DCM debt syndication and equity underwriting absorption to M2 money supply growth. */
    public const M2_SYNDICATION_LIQUIDITY_SENSITIVITY = 0.30;

    // --- Equity Capital Markets (ECM) & IPO Window Physics ---
    /** Baseline share of advisory deal flow attributed to ECM underwriting versus M&A advisory. */
    public const ADVISORY_ECM_SHARE             = 0.35;
    /** Sensitivity of ECM equity underwriting deal volume to Equity Risk Premium (ERP) cost of capital. */
    public const ECM_ERP_ELASTICITY             = 4.00;
    /** Sensitivity of ECM equity underwriting deal volume to macroeconomic output gap. */
    public const ECM_OUTPUT_GAP_ELASTICITY      = 2.50;
    /** VIX panic threshold (~35%) above which institutional IPO windows freeze. */
    public const IPO_WINDOW_FREEZE_VIX          = 0.35;
    /** Penalty scalar curtailing ECM underwriting revenue per point of VIX above the freeze threshold. */
    public const IPO_FREEZE_PENALTY             = 2.00;

    // --- Leveraged Finance (LevFin) & Hung Bridge Loan Write-Downs ---
    /** Baseline high-yield credit spread (macro IG baseline x HY multiple) above which underwriting bridge loans risk hung syndication. */
    public const HY_BRIDGE_SPREAD_BASELINE      = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
    /** Variable cost margin penalty scalar per unit of high-yield spread widening from hung debt markdowns. */
    public const HUNG_DEBT_WRITEDOWN_SCALAR     = 1.50;

    // --- FICC Macro Rates & Yield Curve Trading ---
    /** Sensitivity of FICC trading desk revenue to policy interest rate shifts. */
    public const FICC_RATE_VOLATILITY_SCALAR    = 3.50;
    /** Sensitivity of FICC trading desk revenue to yield curve steepening/inversion dynamics. */
    public const FICC_CURVE_VOLATILITY_SCALAR   = 1.50;
    /** Maximum revenue capture cap for FICC desk macro rates and yield curve arbitrage. */
    public const FICC_ARBITRAGE_CAP             = 0.15;
    /** Policy rate shock threshold (~200bps) above which fixed-income inventory marks down. */
    public const FICC_RATES_INVENTORY_SHOCK_THRESHOLD = 0.02;
    /** Variable inventory markdown scalar penalizing trading revenue for violent policy rate dislocations. */
    public const FICC_INVENTORY_MARKDOWN_SCALAR = 2.00;

    // --- Institutional Prime Financing & Matched-Book Repo ---
    /** Net financing spread (~150bps) earned by prime brokers on institutional hedge fund margin debits. */
    public const PRIME_FINANCING_SPREAD         = 0.0150;
    /** Proportion of wholesale balance sheet deployed to institutional prime brokerage and client financing. */
    public const PRIME_BROKERAGE_ALLOCATION     = 0.70;
    /** Haircut spread deduction on matched-book repo and trading inventory cash financing. */
    public const MATCHED_REPO_SPREAD_HAIRCUT    = 0.0025;
    /** Sensitivity of prime brokerage counterparty credit loss provisions to corporate default rate widening. */
    public const PRIME_BROKERAGE_DEFAULT_SCALAR = 0.50;
    /** Tail event z-score threshold triggering an Archegos-style single-counterparty prime brokerage collapse. */
    public const ARCHEGOS_LOSS_Z_SCORE          = -3.00;
    /** Variable cost margin penalty imposed during a catastrophic prime brokerage counterparty default. */
    public const ARCHEGOS_LOSS_PENALTY          = 0.12;

    // --- S&T Volatility Arbitrage & Basel FRTB VaR Limits ---
    /** Baseline VIX floor (~18%) above which volatility arbitrage opportunities expand. */
    public const VIX_ARBITRAGE_FLOOR        = 0.18;
    /** S&T desk gross trading revenue capture scalar per point of excess VIX. */
    public const VIX_ARBITRAGE_SCALAR       = 1.20;
    /** Maximum regulatory VIX threshold before FRTB VaR capital limits force aggressive balance sheet deleveraging. */
    public const BASEL_VAR_VOL_TARGET       = 0.30;
    /** Variable cost margin penalty scalar from forced inventory markdowns during crisis VaR deleveraging. */
    public const TRADING_INVENTORY_MARKDOWN_SCALAR = 0.20;

    // --- Options Desk Vega & Gamma Friction ---
    /** Top-line Vega premium capture scalar during elevated volatility. */
    public const OPTIONS_VEGA_SCALAR        = 1.50;
    /** Variable margin friction ratio per unit of excess VIX incurred from dynamic gamma delta-hedging. */
    public const OPTIONS_GAMMA_FRICTION     = 0.60;

    // --- Revenue Shock Physics ---
    /** Baseline standard deviation multiplier for firm-wide revenue variance. */
    public const REVENUE_VARIANCE_SCALAR    = 0.25;

    // --- Compensation Ratio Physics ---
    /** Discretionary bonus pool floor ratio to retain key dealmakers during severe downcycles. */
    public const MIN_COMPENSATION_RATIO     = 0.40;
    /** Maximum discretionary bonus pool compensation ratio during record windfall years. */
    public const MAX_COMPENSATION_RATIO     = 0.85;
    /** Operational leverage sensitivity scaling comp ratio down in windfall quarters and up in droughts. */
    public const COMP_OPERATING_LEVERAGE_SCALAR = 0.15;

    // --- M&A Mega-Deal Tail Events ---
    /** Upward tail z-score threshold for landmark M&A syndicate mandate wins. */
    public const MEGA_DEAL_WIN_Z_SCORE      = 2.80;
    /** Downward tail z-score threshold for catastrophic loss of flagship advisory mandates. */
    public const MEGA_DEAL_LOSS_Z_SCORE     = -2.80;
    /** Revenue multiplier applied on landmark mandate wins. */
    public const MEGA_DEAL_WIN_MULT         = 1.12;
    /** Revenue multiplier applied on major mandate losses. */
    public const MEGA_DEAL_LOSS_MULT        = 0.92;

    // --- Regulatory Tail Risk ---
    /** Probability of facing a major regulatory settlement or conduct fine in any given quarter. */
    public const REGULATORY_FINE_PROBABILITY = 0.04;
    /** Margin penalty imposed during regulatory settlement quarters. */
    public const REGULATORY_FINE_SCALAR     = 0.08;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for investment bank analysts. */
    public const BASE_COVERAGE_VISIBILITY   = 0.30;
    /** Base coverage error dispersion for investment bank analysts. */
    public const BASE_COVERAGE_ERROR        = 0.10;
    /** Analyst visibility fraction into advisory and M&A pipelines. */
    public const ADVISORY_ANALYST_VISIBILITY = 0.20;
    /** Analyst visibility fraction into sales and trading market-making flow. */
    public const TRADING_ANALYST_VISIBILITY  = 0.55;

    // --- Event Lore Thresholds ---
    /** Output gap threshold triggering M&A syndication boom lore. */
    public const LORE_BOOM_OUTPUT_GAP       = 0.015;
    /** Advisory z-score threshold triggering M&A syndication boom lore. */
    public const LORE_BOOM_Z_SCORE          = 1.20;
    /** Market VIX threshold triggering proprietary trading surge lore. */
    public const LORE_PANIC_VIX             = 0.32;
    /** Credit spread tightening threshold (~100bps) triggering DCM underwriting boom lore. */
    public const LORE_DCM_SPREAD_TIGHTENING = 0.010;
    /** Output gap contraction threshold triggering advisory drought lore. */
    public const LORE_DROUGHT_OUTPUT_GAP    = -0.020;
    /** Advisory z-score contraction threshold triggering advisory drought lore. */
    public const LORE_DROUGHT_Z_SCORE       = -1.50;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift to avoid double-dipping, as macro volume shocks
        // are handled discretely per stream in calculateSectorPhysics.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        // Effective funding benchmark: prime brokerage margin loans and matched-book repo reprice directly off
        // the firm's OWN wholesale borrowing cost, so the client-facing asset yield must be built on the same
        // realized rate DebtEngine charges on the liability side (DebtMetricsDTO::$wholesaleRate), dynamic
        // credit-spread widening included. Falling back to the static approximation here previously let a
        // distressed firm keep pricing prime brokerage assets at the calm-market rate while paying the live
        // blown-out rate on its liabilities -- an unhedgeable negative carry on the wholesale book that only
        // grew as spreads widened further. Fall back to the static approximation only when no live debt calc
        // has run yet (see calculateBlendedWholesaleRate()).
        $fundingBenchmark = max(0.0, $realizedWholesaleRate ?? $this->calculateBlendedWholesaleRate($stock, $macroState));

        // 1. Institutional Prime Brokerage & Secured Financing (Securities Lending & Margin Debits):
        // Investment banks lend wholesale funding to institutional hedge fund clients against liquid collateral.
        $primeFinancingRate = $fundingBenchmark + self::PRIME_FINANCING_SPREAD;
        $primeFinancingAssets = $wholesaleDebt * self::PRIME_BROKERAGE_ALLOCATION;
        $primeInterest = $primeFinancingAssets * $primeFinancingRate;

        // 2. Institutional Matched-Book Repo & Trading Inventory Financing:
        // The remainder of wholesale debt finances market-making inventory and reverse repo operations.
        $inventoryFinancingYield = max(0.0, $fundingBenchmark - self::MATCHED_REPO_SPREAD_HAIRCUT);
        $inventoryInterest = ($wholesaleDebt * (1.0 - self::PRIME_BROKERAGE_ALLOCATION)) * $inventoryFinancingYield;

        // 3. Excess Corporate Treasury Yield
        $operatingBase = $this->getOperatingBase($stock);
        $minCash = $this->calculateMinOperatingCash($operatingBase, 0.0, $wholesaleDebt);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $minCash);
        $cashYield = $this->calculateCashYield($macroState);
        $cashInterest = $excessCash * $cashYield;

        return $primeInterest + $inventoryInterest + $cashInterest;
    }

    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }

        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $baselineRoe = max($waccBase, $baselineRoe - $saturationPenalty);

        $taxRate = $macroState->corporateTaxRate;

        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $blendedWholesaleRate = $this->calculateBlendedWholesaleRate($stock, $macroState);

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? self::DEFAULT_EQUITY_LIMIT;

        $effectiveEquity = max(1.0, $equity);

        $actualLeverage = $effectiveEquity > 0 ? ($wholesaleDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(0.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalInterestExpense = $optimalDebt * $blendedWholesaleRate;

        // Investment banks deploy wholesale debt into institutional prime financing and trading inventories
        $fundingBenchmark = max(0.0, $blendedWholesaleRate);
        $primeFinancingRate = $fundingBenchmark + self::PRIME_FINANCING_SPREAD;
        $inventoryFinancingYield = max(0.0, $fundingBenchmark - self::MATCHED_REPO_SPREAD_HAIRCUT);
        $blendedAssetYield = (self::PRIME_BROKERAGE_ALLOCATION * $primeFinancingRate) + ((1.0 - self::PRIME_BROKERAGE_ALLOCATION) * $inventoryFinancingYield);
        $optimalInterestIncome = $optimalDebt * $blendedAssetYield;

        $optimalOperatingNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalOperatingNetIncome / (1.0 - $taxRate);

        $optimalEbit = $optimalEbt + $optimalInterestExpense - $optimalInterestIncome;
        $operatingBase = $this->getOperatingBase($stock);
        $targetCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, $optimalDebt);
        $optimalEarningAssets = $effectiveEquity + $optimalDebt - $targetCash;
        $structuralAssetYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        $earningAssets = max($effectiveEquity, $effectiveEquity + $wholesaleDebt - $treasury);

        $targetEbit = $earningAssets * $structuralAssetYield;
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $minOperatingEbit = $earningAssets * self::MIN_OPERATING_EBIT_YIELD;
        $targetEbit = max($minOperatingEbit, $targetEbit);

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * self::MAX_GROSS_ASSET_YIELD);

        $grossYield = $targetRevenue / max(1.0, $earningAssets);

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::AdvisoryRevenueWeight->value      => self::ADVISORY_REVENUE_WEIGHT,
            ModelParam::TradingRevenueWeight->value       => self::TRADING_REVENUE_WEIGHT,
            ModelParam::OptionsPremiumIncomeWeight->value => 0.00,
            ModelParam::VixArbitrageScalar->value         => self::VIX_ARBITRAGE_SCALAR,
        ]);

        $rawOptionsWeight = $params[ModelParam::OptionsPremiumIncomeWeight];
        $vixScalar        = $params[ModelParam::VixArbitrageScalar];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $targetWeights = [
            'advisory' => $params[ModelParam::AdvisoryRevenueWeight],
            'trading'  => $params[ModelParam::TradingRevenueWeight],
        ];
        if ($rawOptionsWeight > 0.0) {
            $targetWeights['options_premium_income'] = $rawOptionsWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $advisoryWeight = $activeWeights['advisory'];
        $tradingWeight  = $activeWeights['trading'];
        $optionsWeight  = $activeWeights['options_premium_income'] ?? 0.0;

        // Independent stream Z-scores with AR(1) persistence
        $advisoryZ = $streams->generateZ('advisory', 0.40);
        $tradingZ  = $streams->generateZ('trading', 0.15);
        $eventZ    = $streams->generateExogenousZ('event', 0.05);

        // --- M&A, DCM, and ECM Elasticity (Cost of Capital & Macro Channel) ---
        $erpGap = MacroEngine::BASE_EQUITY_RISK_PREMIUM - $macroState->equityRiskPremium;

        // 1. M&A Deal Flow: Scales with corporate expansion (output gap) and cheap equity cost of capital (ERP) on M&A share.
        $mnaStimulus = (($macroState->outputGapEma * self::MNA_OUTPUT_GAP_ELASTICITY) + ($erpGap * self::MNA_ERP_ELASTICITY)) * (1.0 - self::ADVISORY_ECM_SHARE);

        // 2. ECM Underwriting: Scales with output gap and ERP on ECM share.
        $ecmStimulus = (($macroState->outputGapEma * self::ECM_OUTPUT_GAP_ELASTICITY) + ($erpGap * self::ECM_ERP_ELASTICITY)) * self::ADVISORY_ECM_SHARE;

        // 3. DCM Issuance: Governed by credit spread tightness and yield curve slope relative to neutral slope.
        $creditSpreadGap = self::DEAL_BASELINE_CREDIT_SPREAD - $macroState->macroCreditSpreadEma;
        $curveSlope = $macroState->yield5yEma - $macroState->policyRateEma;
        $curveSlopeGap = $curveSlope - self::DCM_NEUTRAL_CURVE_SLOPE;
        $dcmStimulus = ($creditSpreadGap * self::DCM_CREDIT_SPREAD_ELASTICITY) + ($curveSlopeGap * self::DCM_CURVE_SLOPE_ELASTICITY);

        // 4. ECM Deal Activity & IPO Window Freeze:
        // Aggregate deal activity stimulates advisory fees, but acute market volatility (VIX > 35%) freezes the institutional IPO window.
        $dealActivityShift = ($macroState->dealActivityIndexEma - MacroEngine::DEAL_ACTIVITY_BASELINE) / MacroEngine::DEAL_ACTIVITY_BASELINE;
        $ecmDealActivity = $dealActivityShift * self::DEAL_ACTIVITY_INDEX_ELASTICITY;
        $vixEma = $macroState->marketVolatilityEma;
        $freezeDiscount = 0.0;
        if ($vixEma > self::IPO_WINDOW_FREEZE_VIX) {
            $freezeDiscount = min(0.30, ($vixEma - self::IPO_WINDOW_FREEZE_VIX) * self::IPO_FREEZE_PENALTY);
        }
        $ecmNetActivity = ($ecmDealActivity - $freezeDiscount) * self::ADVISORY_ECM_SHARE;

        $m2SyndicationBoost = MathUtility::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, sensitivity: self::M2_SYNDICATION_LIQUIDITY_SENSITIVITY);
        $advisoryMacroFactor = $mnaStimulus + $ecmStimulus + $dcmStimulus + $ecmNetActivity + $m2SyndicationBoost;

        // --- S&T Volatility Arbitrage & Basel FRTB VaR Limits ---
        $vixGap = $vixEma - self::VIX_ARBITRAGE_FLOOR;

        // Standard Basel VaR volatility targeting: position limits scale down when volatility exceeds target.
        $varDeleverageFactor = $vixEma > self::BASEL_VAR_VOL_TARGET
            ? self::BASEL_VAR_VOL_TARGET / $vixEma
            : 1.0;

        $volatilityArbitrage = $vixGap >= 0.0
            ? ($vixGap * $vixScalar) * $varDeleverageFactor
            : max(-0.15, $vixGap * ($vixScalar * 0.50));

        // Forced deleveraging into a falling market creates inventory liquidation markdowns and execution slippage.
        $tradingInventoryMarkdownCost = $vixEma > self::BASEL_VAR_VOL_TARGET
            ? ((1.0 - $varDeleverageFactor) * self::TRADING_INVENTORY_MARKDOWN_SCALAR) * $tradingWeight
            : 0.0;

        // --- FICC Macro Rates & Yield Curve Trading ---
        // S&T FICC desks capture client hedging volume and bid-ask spreads during un-trended rate volatility:
        // any dislocation between the instantaneous policy rate and its smoothed EMA is a live rate shock,
        // and curve dislocations (nsSlope vs nsSlopeEma) drive curve trading flow.
        $rateShock = abs($macroState->policyRate - $macroState->policyRateEma);
        $curveShock = abs($macroState->nsSlope - $macroState->nsSlopeEma);
        $grossFicc = ($rateShock * self::FICC_RATE_VOLATILITY_SCALAR) + ($curveShock * self::FICC_CURVE_VOLATILITY_SCALAR);

        // Inventory markdown on violent rate dislocations: violent shocks (> 200bps) mark down fixed-income inventory.
        $ratesInventoryMarkdown = max(0.0, $rateShock - self::FICC_RATES_INVENTORY_SHOCK_THRESHOLD) * self::FICC_INVENTORY_MARKDOWN_SCALAR;
        $ficcArbitrage = max(-0.10, min(self::FICC_ARBITRAGE_CAP, $grossFicc) - $ratesInventoryMarkdown);

        // --- Event Modifiers & Penalties ---
        $eventType = null;
        $advisoryEventMultiplier = 1.0;
        $regulatoryPenalty = 0.0;
        $counterpartyDefaultPenalty = 0.0;

        if ($eventZ > self::MEGA_DEAL_WIN_Z_SCORE) {
            $advisoryEventMultiplier = self::MEGA_DEAL_WIN_MULT;
            $eventType = ShockEvent::IB_MNA_LANDMARK_MANDATE;
        } elseif ($eventZ < self::MEGA_DEAL_LOSS_Z_SCORE) {
            $advisoryEventMultiplier = self::MEGA_DEAL_LOSS_MULT;
            $eventType = ShockEvent::IB_MNA_MANDATE_LOSS;
        }

        if ($eventZ < self::ARCHEGOS_LOSS_Z_SCORE) {
            $counterpartyDefaultPenalty = self::ARCHEGOS_LOSS_PENALTY;
            $eventType = ShockEvent::IB_COUNTERPARTY_DEFAULT;
        }

        if ($mathUtility->generateUniform() <= self::REGULATORY_FINE_PROBABILITY) {
            $eventType = ShockEvent::IB_REGULATORY_SETTLEMENT;
            $regulatoryPenalty = self::REGULATORY_FINE_SCALAR;
        }

        // --- Stream Revenue Calculation ---
        $advisoryRevenue = $advisoryWeight > 0.0
            ? max(0.0, $expectedRevenue * $advisoryWeight * (1.0 + ($advisoryZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR) + $advisoryMacroFactor) * $advisoryEventMultiplier)
            : 0.0;
        $tradingRevenue  = $tradingWeight > 0.0
            ? max(0.0, $expectedRevenue * $tradingWeight * (1.0 + ($tradingZ * $baselineVol * self::TRADING_VARIANCE_SCALAR) + $volatilityArbitrage + $ficcArbitrage))
            : 0.0;

        $streamRevenues = [];
        if ($advisoryWeight > 0.0) {
            $streamRevenues['advisory'] = $advisoryRevenue;
        }
        if ($tradingWeight > 0.0) {
            $streamRevenues['trading'] = $tradingRevenue;
        }

        // --- Black-Scholes Options Physics ---
        $gammaHedgingCost = 0.0;
        $optionsVegaBonus = 0.0;
        if ($optionsWeight > 0.0) {
            $optionsZ = $streams->generateZ('options_premium_income', 0.20);

            // 1. Top-Line Vega: Higher implied volatility expands options premium pricing.
            $optionsVegaBonus = $vixGap > 0.0 ? ($vixGap * self::OPTIONS_VEGA_SCALAR) : ($vixGap * 0.50);
            $optionsRevenue = max(0.0, $expectedRevenue * $optionsWeight * (1.0 + ($optionsZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR) + $optionsVegaBonus));
            $streamRevenues['options_premium_income'] = $optionsRevenue;

            // 2. Bottom-Line Gamma Friction: Scaled by options desk weight so it does not contaminate non-options desks.
            if ($vixGap > 0.0) {
                $gammaHedgingCost = ($vixGap * self::OPTIONS_GAMMA_FRICTION) * $optionsWeight;
            }
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Leveraged Finance (LevFin) Hung Bridge Debt Markdowns ---
        // Spreading high-yield debt forces mark-to-market write-downs on bridge loan commitments held on the balance sheet.
        $hySpreadStress = max(0.0, $macroState->highYieldCreditSpreadEma - self::HY_BRIDGE_SPREAD_BASELINE);
        $hungDebtCost = ($hySpreadStress * self::HUNG_DEBT_WRITEDOWN_SCALAR) * $advisoryWeight;

        // --- Prime Brokerage Counterparty Credit Risk ---
        // Systematic credit provision: Prime brokerage margin debt faces client default risk when corporate defaults rise.
        $corpDefaultExcess = MathUtility::excessOverBaseline($macroState->corporateDefaultRateEma, MacroEngine::CORPORATE_DEFAULT_BASELINE);
        $primeCreditProvisionCost = ($corpDefaultExcess * self::PRIME_BROKERAGE_DEFAULT_SCALAR) * self::PRIME_BROKERAGE_ALLOCATION;

        // --- Compensation Ratio Physics & Operating Leverage ---
        $rawMargin = $realizedVariableMargin
            + $regulatoryPenalty
            + $gammaHedgingCost
            + $hungDebtCost
            + $tradingInventoryMarkdownCost
            + $primeCreditProvisionCost
            + $counterpartyDefaultPenalty;

        $revenueSurplusRatio = ($actualRevenue - $expectedRevenue) / max(1.0, $expectedRevenue);
        $minCompRatio = max(0.01, self::MIN_COMPENSATION_RATIO - ($revenueSurplusRatio * self::COMP_OPERATING_LEVERAGE_SCALAR) - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedVariableMargin = $this->clampMargin($rawMargin, $minCompRatio, self::MAX_COMPENSATION_RATIO);

        // --- Event Lore Classification ---
        if ($eventType === null) {
            if ($advisoryWeight > 0.0 && $macroState->outputGapEma > self::LORE_BOOM_OUTPUT_GAP && $advisoryZ > self::LORE_BOOM_Z_SCORE) {
                $eventType = ShockEvent::IB_MNA_SYNDICATION_BOOM;
            } elseif ($advisoryWeight > 0.0 && $creditSpreadGap > self::LORE_DCM_SPREAD_TIGHTENING) {
                $eventType = ShockEvent::IB_DCM_UNDERWRITING_BOOM;
            } elseif ($vixEma > self::LORE_PANIC_VIX) {
                $eventType = ShockEvent::IB_PROP_TRADING_SURGE;
            } elseif ($advisoryWeight > 0.0 && $macroState->outputGapEma < self::LORE_DROUGHT_OUTPUT_GAP && $advisoryZ < self::LORE_DROUGHT_Z_SCORE) {
                $eventType = ShockEvent::ADVISORY_CRASH;
            }
        }

        $primaryShockZ = $streams->resolveDominantShockZ([$advisoryWeight > 0.0 ? $advisoryZ : 0.0, $tradingZ], $eventZ);

        $observableShockZ = ($advisoryMacroFactor * $advisoryWeight)
            + (($volatilityArbitrage + $ficcArbitrage) * $tradingWeight)
            + ($optionsVegaBonus * $optionsWeight)
            + ($advisoryZ * $advisoryWeight * self::ADVISORY_ANALYST_VISIBILITY
                + $tradingZ * $tradingWeight * self::TRADING_ANALYST_VISIBILITY)
                * $baselineVol * self::REVENUE_VARIANCE_SCALAR;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedVariableMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
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
            'corporate_default_rate_ema',
            'deal_activity_index_ema',
            'high_yield_credit_spread_ema',
            'interbank_liquidity_spread_ema',
            'macro_credit_spread_ema',
            'market_volatility_ema',
            'money_supply_growth_ema',
            'ns_slope',
            'ns_slope_ema',
            'output_gap_ema',
            'policy_rate_ema',
            'yield_5y_ema',
        ];
    }
}

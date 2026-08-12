<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\StockModelTuning;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Pure-Play Investment Banks and M&A Advisory Syndicates.
 *
 * Financial Physics:
 * - Dual-Desk Architecture: Advisory (M&A/underwriting) and Sales & Trading are structurally decorrelated.
 *   A frozen M&A market can coexist with record trading revenues during a volatility panic.
 * - Pro-Cyclical M&A and DCM Deal Flow: Advisory revenue and IPO/debt syndication fees explode during
 *   economic expansions and steep yield curves. A flat or inverted curve kills DCM volumes.
 * - Proprietary Trading Desk Volatility Arbitrage: During market panics or VIX spikes, market-making
 *   desks print massive trading revenue, providing counter-cyclical crash protection.
 * - Compensation-Driven Cost Structure: IB variable costs are dominated by discretionary bonuses,
 *   locked in a tight 40–85% of revenue band that cannot be arbitrarily compressed.
 * - Evaluated on Return on Equity (ROE) with institutional capital requirement rules.
 */
class InvestmentBankBusinessModel extends BrokerageBusinessModel
{
    public function getModelThresholds(): array
    {
        return ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => null, 'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15, 'reversion_speed' => 0.18, 'moat_spread' => 0.005, 'nwc_intensity' => 0.0, 'capex_completion_rate' => 1.0];
    }
    // --- Dual-Desk Revenue Architecture ---
    /** Fraction of IB revenue sourced from Advisory (M&A, ECM, DCM underwriting). ~40% for bulge-brackets. */
    public const ADVISORY_REVENUE_WEIGHT    = 0.40;
    /** Fraction of IB revenue sourced from Sales & Trading (FICC, equities, prime brokerage). ~60% for bulge-brackets. */
    public const TRADING_REVENUE_WEIGHT     = 0.60;
    /** Higher revenue variance scalar for the S&T desk — market-making is far more volatile than advisory. */
    public const TRADING_VARIANCE_SCALAR    = 0.35;

    // --- Deal Flow & Macro Sensitivity ---
    /** Deal flow expansion multiplier during economic booms when corporate M&A and IPO activity explodes. */
    public const DEAL_FLOW_BOOM_MULT        = 4.00;
    /** Deal flow contraction multiplier during recessions when credit markets freeze and M&A stalls. */
    public const DEAL_FLOW_BUST_MULT        = 2.50;

    // --- DCM Yield Curve Channel ---
    /** Carry floor (5Y minus policy rate) above which medium-term corporate bond issuance volumes surge. */
    public const DCM_STEEPNESS_FLOOR        = 0.005;
    /** Sensitivity of DCM underwriting revenue to the 5Y-policy carry. +100bps = +25% advisory boost. */
    public const DCM_STEEPNESS_SCALAR       = 2.50;
    /** Maximum DCM carry bonus clamp to prevent hyperinflation during extreme steep curves. */
    public const DCM_STEEPNESS_CLAMP        = 0.40;

    // --- Proprietary Volatility Arbitrage (S&T Desk) ---
    /** VIX threshold above which market-making desks generate surge trading revenues. */
    public const VIX_ARBITRAGE_FLOOR        = 0.18;
    /** Sensitivity scalar translating VIX spikes into massive counter-cyclical S&T gains. */
    public const VIX_ARBITRAGE_SCALAR       = 1.20;
    /** Default VIX fallback when macroeconomic state data is missing. */
    public const DEFAULT_VIX_FALLBACK       = 0.20;

    // --- Revenue Shock Physics ---
    /** Volatility multiplier for advisory top-line revenue shocks (M&A pipeline is lumpy). */
    public const REVENUE_VARIANCE_SCALAR    = 0.25;

    // --- Compensation Ratio Physics ---
    /** IB compensation floor: bonuses cannot be compressed below 40% of revenue without triggering talent flight. */
    public const MIN_COMPENSATION_RATIO     = 0.40;
    /** IB compensation ceiling: discretionary bonuses rarely push variable costs above 85% before insolvency. */
    public const MAX_COMPENSATION_RATIO     = 0.85;

    // --- M&A Mega-Deal Tail Events ---
    /** Z-score threshold triggering a rare landmark M&A mandate win (~0.5% probability per quarter). */
    public const MEGA_DEAL_WIN_Z_SCORE      = 2.80;
    /** Z-score threshold triggering a headline advisory mandate loss to a rival (~0.5% probability). */
    public const MEGA_DEAL_LOSS_Z_SCORE     = -2.80;
    /** Revenue multiplier applied when a transformative M&A mandate is captured. */
    public const MEGA_DEAL_WIN_MULT         = 1.12;
    /** Revenue penalty multiplier applied when a high-profile advisory mandate is lost to a rival. */
    public const MEGA_DEAL_LOSS_MULT        = 0.92;

    // --- Regulatory Tail Risk ---
    /** Quarterly probability of a major regulatory fine or litigation settlement (~once per 6-7 years). */
    public const REGULATORY_FINE_PROBABILITY = 0.04;
    /** Variable cost increase as a fraction of revenue when a regulatory settlement triggers. */
    public const REGULATORY_FINE_SCALAR     = 0.08;

    // --- Analyst Visibility Calibration ---
    /** Fraction of advisory shock visible to analysts: M&A pipeline is strictly confidential until close. */
    public const ADVISORY_ANALYST_VISIBILITY = 0.20;
    /** Fraction of S&T shock visible to analysts: trading volumes are semi-public via FINRA/industry data. */
    public const TRADING_ANALYST_VISIBILITY  = 0.55;
    // Analyst error std dev moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Event Lore Thresholds ---
    /** Positive output gap required to trigger M&A boom event lore. */
    public const LORE_BOOM_OUTPUT_GAP       = 0.015;
    /** Positive advisory z-score required to trigger M&A boom event lore. */
    public const LORE_BOOM_Z_SCORE          = 1.20;
    /** Severe VIX threshold triggering proprietary trading panic event lore. */
    public const LORE_PANIC_VIX             = 0.32;
    /** Yield curve steepness threshold triggering DCM boom event lore. */
    public const LORE_DCM_BOOM_CURVE        = 0.015;
    /** Negative output gap required to trigger credit freeze drought event lore. */
    public const LORE_DROUGHT_OUTPUT_GAP    = -0.020;
    /** Negative advisory z-score required to trigger credit freeze drought event lore. */
    public const LORE_DROUGHT_Z_SCORE       = -1.50;

    /**
     * Dual-desk idiosyncratic shock for Investment Banks.
     *
     * Advisory (M&A, ECM/DCM) and Sales & Trading are modelled with independent Z-scores,
     * reflecting their structural decorrelation. The yield curve drives DCM volumes;
     * VIX spikes drive S&T revenues counter-cyclically.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Resolve company-specific tuned parameters (or fallback to sector defaults)
        $params = $this->resolveModelParameters($stock, [
            'advisory_revenue_weight' => self::ADVISORY_REVENUE_WEIGHT,
            'trading_revenue_weight'  => self::TRADING_REVENUE_WEIGHT,
            'vix_arbitrage_scalar'    => self::VIX_ARBITRAGE_SCALAR,
        ]);

        $advisoryWeight = $params['advisory_revenue_weight'];
        $tradingWeight  = $params['trading_revenue_weight'];
        $vixScalar      = $params['vix_arbitrage_scalar'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Independent stream Z-scores with AR(1) persistence
        $advisoryZ = $mathUtility->generatePersistentZ($momentum['advisory'] ?? 0.0, 0.40); // M&A + ECM/DCM underwriting (strong pipeline memory)
        $tradingZ  = $mathUtility->generatePersistentZ($momentum['trading'] ?? 0.0, 0.15); // S&T: FICC, equities, prime brokerage
        $eventZ    = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.05); // Mega-deal / regulatory tail

        // Pro-Cyclical M&A Deal Flow
        $outputGap = $macroState->outputGapEma;
        $dealFlowMultiplier = $outputGap > 0.0
            ? ($outputGap * self::DEAL_FLOW_BOOM_MULT)
            : ($outputGap * self::DEAL_FLOW_BUST_MULT);

        // DCM Yield Curve Channel
        // Corporate bond issuance concentrates in the 3-7Y belly of the curve. When the 5Y Treasury
        // yields significantly more than overnight (policy rate), corporates rush to lock in cheap
        // medium-term debt — generating record underwriting fees for IB DCM desks.
        $policyRate = $macroState->policyRateEma;
        $yield5y    = $macroState->yield5yEma;
        $curveSlope = $yield5y - $policyRate;
        // DCM underwriting expands during steep yield curves (+40% clamp) and contracts during
        // inverted/flat yield curves (-20% drought floor) when corporate debt refinancing dries up.
        $dcmBonus   = max(
            -0.20,
            min(self::DCM_STEEPNESS_CLAMP, ($curveSlope - self::DCM_STEEPNESS_FLOOR) * self::DCM_STEEPNESS_SCALAR)
        );

        //  S&T Volatility Arbitrage (VIX spike revenue on trading desk)
        $vixEma = $macroState->marketVolatilityEma;
        $vixGap = $vixEma - self::VIX_ARBITRAGE_FLOOR;
        // High VIX (>0.18) = Surge market-making arbitrage profits.
        // Low VIX (<0.18)  = Trading volume famine & infrastructure carry drag (-15% max drag).
        $volatilityArbitrage = $vixGap >= 0.0
            ? $vixGap * $vixScalar
            : max(-0.15, $vixGap * ($vixScalar * 0.5));

        //  Blended dual-desk revenue (using resolved company model parameters)
        $advisoryRevenue = $expectedRevenue * $advisoryWeight
            * (1.0 + ($advisoryZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR) + $dealFlowMultiplier + $dcmBonus);
        $tradingRevenue  = $expectedRevenue * $tradingWeight
            * (1.0 + ($tradingZ * $baselineVol * self::TRADING_VARIANCE_SCALAR) + $volatilityArbitrage);
        $actualRevenue = max(0.0, $advisoryRevenue + $tradingRevenue);

        //  Compensation ratio floor: Total Operating Costs (Fixed + Variable) / Revenue >= MIN_COMPENSATION_RATIO.
        $minCompRatio = max(0.01, self::MIN_COMPENSATION_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedVariableMargin = $this->clampMargin($realizedVariableMargin, $minCompRatio, self::MAX_COMPENSATION_RATIO);

        // Regulatory tail risk (independent of market cycle)
        $eventType = null;
        if ($mathUtility->generateUniform() <= self::REGULATORY_FINE_PROBABILITY) {
            $eventType = ShockEvent::IB_REGULATORY_SETTLEMENT;
        }

        // Mega-deal tail events (eventZ overrides regulatory lore if triggered)
        if ($eventZ > self::MEGA_DEAL_WIN_Z_SCORE) {
            $actualRevenue *= self::MEGA_DEAL_WIN_MULT;
            $eventType = ShockEvent::IB_MNA_LANDMARK_MANDATE;
        } elseif ($eventZ < self::MEGA_DEAL_LOSS_Z_SCORE) {
            $actualRevenue *= self::MEGA_DEAL_LOSS_MULT;
            $eventType = ShockEvent::IB_MNA_MANDATE_LOSS;
        }

        //  Standard macro event lore (only if no higher-priority event) ---
        if ($eventType === null) {
            if ($outputGap > self::LORE_BOOM_OUTPUT_GAP && $advisoryZ > self::LORE_BOOM_Z_SCORE) {
                $eventType = ShockEvent::IB_MNA_SYNDICATION_BOOM;
            } elseif ($curveSlope > self::LORE_DCM_BOOM_CURVE && $dcmBonus > 0.05) {
                $eventType = ShockEvent::IB_DCM_UNDERWRITING_BOOM;
            } elseif ($vixEma > self::LORE_PANIC_VIX) {
                $eventType = ShockEvent::IB_PROP_TRADING_SURGE;
            } elseif ($outputGap < self::LORE_DROUGHT_OUTPUT_GAP && $advisoryZ < self::LORE_DROUGHT_Z_SCORE) {
                $eventType = ShockEvent::ADVISORY_CRASH;
            }
        }

        // Primary shock Z: whichever desk or tail event produced the largest absolute deviation
        $primaryShockZ = $advisoryZ;
        if (abs($tradingZ) > abs($primaryShockZ)) $primaryShockZ = $tradingZ;
        if (abs($eventZ)   > abs($primaryShockZ)) $primaryShockZ = $eventZ;

        // observableShockZ: scalar approximation — weighted per-desk visibility blend
        $observableShockZ = ($advisoryZ * $advisoryWeight * self::ADVISORY_ANALYST_VISIBILITY
            + $tradingZ * $tradingWeight * self::TRADING_ANALYST_VISIBILITY)
            * $baselineVol * self::REVENUE_VARIANCE_SCALAR;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedVariableMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'advisory' => $advisoryZ,
                'trading'  => $tradingZ,
                'event'    => $eventZ,
            ],
            streamRevenue: [
                'advisory' => $advisoryRevenue,
                'trading'  => $tradingRevenue,
            ],
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // All per-desk visibility is pre-embedded in observableShockZ, so baseVisibility=1.0 (pass-through).
        return new \App\DTO\SectorCoverageProfile(baseVisibility: 1.0, errorStdDev: 0.08);
    }
}

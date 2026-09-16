<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Entity\Stock;

/**
 * Earnings strategy for Physical Merchant Houses & Mercantile Trading Groups.
 *
 * A merchant house is filed as a conglomerate and is not one. It manufactures nothing, so the parent's
 * industrial stream does not describe it, and the single fact that governs its accounts has no analogue in
 * the parent at all: its top line is invoiced TURNOVER, the market value of the tonnage that crossed its
 * wharves. A commodity bull market doubles the revenue line with no extra barrel handled, and a price
 * collapse halves it while the desk earns exactly the same spread.
 *
 * Financial Physics:
 * - Merchant Trading: turnover is price x volume. Only the PRICE leg dilutes the cost ratio, by the exact
 *   identity p(1 - c) / (1 + p) — a per-unit spread on a bigger invoice is a smaller percentage. Volume is
 *   excluded because shipping more tonnage scales revenue and cost of goods together.
 * - Dislocation, not direction, is the margin. Backwardation (Kaldor-Working: the convenience yield of
 *   holding the physical when inventories are short) and congested corridors widen the spread, because the
 *   party holding the berth and the bonded warehouse is the only one who can deliver today.
 * - Working capital is the risk. Cargo and bills of lading are financed on short wholesale credit for about
 *   a quarter, so the funding rate is a direct charge on turnover. On a thin trading spread this is the leg
 *   that ends merchant houses.
 * - Terminal Tariffs: the berths, silos and bonded warehouses are a tollbooth, and inherit the parent's
 *   defensive staples physics unchanged.
 * - Trade Credit: secured inventory financing and treasury buffers run the parent's contrarian float book.
 */
class MerchantHouseBusinessModel extends ConglomerateBusinessModel
{
    // --- Portfolio Composition ---
    /** Baseline fraction of revenue from the physical trading desks that buy, blend, transport and distribute. */
    public const MERCHANT_TRADING_WEIGHT = 0.55;

    /** Baseline fraction of revenue from terminal, storage and handling tariffs on irreplaceable waterfront. */
    public const DEFENSIVE_STAPLES_WEIGHT = 0.30;

    /** Baseline fraction of revenue from secured inventory financing, trade credit and treasury buffers. */
    public const CONTRARIAN_FLOAT_WEIGHT = 0.15;

    /** A merchant house owns no factories; the parent's industrial stream is switched off entirely. */
    public const INDUSTRIAL_CONGLOMERATE_WEIGHT = 0.00;

    /** A price taker on the cargo, a monopolist on the berth. */
    public const PRICING_POWER_INDEX = 0.55;

    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes to the macro cycle. Staple grains, energy feedstocks and basic metals keep moving through a slump, which is what a merchant's diversification actually buys. */
    public const OPERATING_CYCLICALITY = 0.55;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for merchant turnover: cargo timing, counterparty failures and blending yields swing a trading book harder than a factory. */
    public const MERCHANT_VARIANCE_SCALAR = 0.45;

    // --- Merchant Book Composition ---
    /** Shares of the merchant book's invoiced tonnage priced off each tracked commodity market; the remainder is fixed storage and handling tariff. */
    public const MERCHANT_BOOK_EXPOSURES = ['metals' => 0.40, 'agri' => 0.35, 'energy' => 0.25];

    /** Share of a commodity price move that reaches invoiced turnover. A merchant sells at market; the fixed-tariff terminal revenue that does not move with the cargo's price sits in the tollbooth stream instead. */
    public const MERCHANT_PRICE_PASS_THROUGH = 0.90;

    // --- Physical Throughput ---
    /** Sensitivity of physical throughput volume to manufacturing PMI; bulk inputs move before finished goods do. */
    public const MERCHANT_PMI_SENSITIVITY = 0.25;

    /** Sensitivity of throughput to the trade balance: an economy importing more moves more tonnage across the merchant's wharves. */
    public const MERCHANT_TRADE_SENSITIVITY = 0.80;

    // --- Trading Spread ---
    /** Gross spread widening per unit of physical backwardation (Kaldor-Working convenience yield). Scarcity is the merchant's margin: whoever holds the barrel when nobody else can deliver names the price. */
    public const MERCHANT_BACKWARDATION_SCALAR = 0.35;

    /** Gross spread widening per unit of the supply-chain pressure index. Congested corridors price optionality on physical delivery, which only an owner of berths and bonded warehouses can sell. */
    public const MERCHANT_DISRUPTION_SCALAR = 0.012;

    /** Years of turnover carried as financed inventory and bills of lading (~3 months). Multiplied by the funding rate it gives the carry cost as a fraction of turnover. */
    public const MERCHANT_INVENTORY_HOLDING_YEARS = 0.25;

    /** Ceiling on the desk's net cost-ratio swing in either direction, so one quarter's dislocation cannot invert the group's cost base. */
    public const MAX_MERCHANT_COST_SWING = 0.12;

    // --- Analyst Visibility & Error ---
    /** Commodity prices are public and throughput is broadly known, so a merchant house is read more easily than a holding company with unlisted subsidiaries. */
    public const BASE_COVERAGE_VISIBILITY = 0.50;

    /** Fraction of desk performance visible to consensus ahead of the filing. */
    public const MERCHANT_OBSERVABLE_DISCOUNT = 0.75;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: harvest shipments and the
     * pre-winter energy build move more tonnage across the back half of the year.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 0.99, 1.03, 1.03];
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        // The portfolio IS the archetype here, so the mix is class constants rather than tuning dials —
        // a firm that runs a different one is a different sub-model, the way reinsurance is. Pricing power
        // stays resolvable, because that genuinely varies between two merchant houses on the same book.
        $pricingPower = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $activeWeights = $streams->resolveActiveStreamWeights([
            'merchant_trading'      => self::MERCHANT_TRADING_WEIGHT,
            'defensive_staples'     => self::DEFENSIVE_STAPLES_WEIGHT,
            'financial_investments' => self::CONTRARIAN_FLOAT_WEIGHT,
        ]);

        $merchantWeight  = $activeWeights['merchant_trading'];
        $tollboothWeight = $activeWeights['defensive_staples'];
        $floatWeight     = $activeWeights['financial_investments'];

        $merchantZ  = $streams->generateZ('merchant_trading', 0.20);
        $tollboothZ = $streams->generateZ('defensive_staples', 0.45);
        $floatZ     = $streams->generateZ('financial_investments', 0.15);
        $eventZ     = $streams->generateExogenousZ('event', 0.10);

        $outputGap = $macroState->outputGapEma;

        // --- Merchant Turnover: price x volume, kept apart because only price dilutes the spread ---
        $priceLift = $this->resolveMerchantPriceLift($macroState);
        $volumeShift = MathUtility::calculatePmiDemandShift(
            $macroState->manufacturingPmiEma,
            MacroEngine::PMI_BASELINE,
            self::MERCHANT_PMI_SENSITIVITY
        ) + MathUtility::calculateTradeBalanceShift(
            $macroState->tradeBalanceToGdpEma,
            MacroEngine::TRADE_BALANCE_BASELINE,
            self::MERCHANT_TRADE_SENSITIVITY
        );
        $merchantSurge = $priceLift + $volumeShift;

        // --- Tollbooth & Float: the parent's physics, unchanged ---
        $tollboothMacroBoost = $outputGap * self::DEFENSIVE_MACRO_SCALAR;

        $dealActivityShift = ($macroState->dealActivityIndexEma - MacroEngine::DEAL_ACTIVITY_BASELINE) / MacroEngine::DEAL_ACTIVITY_BASELINE;
        $contrarianSurge = $this->resolveContrarianFloatSurge(
            $macroState,
            $mathUtility,
            $outputGap,
            $macroState->macroCreditSpreadEma,
            $macroState->macroCreditSpread - $macroState->macroCreditSpreadEma,
            max(0.0, $dealActivityShift) * self::DEAL_ACTIVITY_DIVESTITURE_SCALAR
        );

        // --- Tail Risk: a counterparty failure on a cargo book, not a deal ---
        $eventType = null;
        $restructuringPenalty = 0.0;
        if ($eventZ < self::SUBSIDIARY_WRITEDOWN_Z_SCORE) {
            $restructuringPenalty = self::RESTRUCTURING_DRAG_PENALTY * (1.0 - ($pricingPower * 0.50));
            $eventType = ShockEvent::CONGLOMERATE_SUBSIDIARY_WRITEDOWN;
        }

        $merchantShock  = $merchantZ  * ($baselineVol * self::MERCHANT_VARIANCE_SCALAR);
        $tollboothShock = $tollboothZ * ($baselineVol * self::DEFENSIVE_VARIANCE_SCALAR);
        $floatShock     = $floatZ     * ($baselineVol * self::FLOAT_VARIANCE_SCALAR);

        $streamRevenues = [
            'merchant_trading'      => max(0.0, $expectedRevenue * $merchantWeight * (1.0 + $merchantShock + $merchantSurge)),
            'defensive_staples'     => max(0.0, $expectedRevenue * $tollboothWeight * (1.0 + $tollboothShock + $tollboothMacroBoost)),
            'financial_investments' => max(0.0, $expectedRevenue * $floatWeight * (1.0 + $floatShock + $contrarianSurge)),
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        $merchantShare = $actualRevenue > 0.0 ? $streamRevenues['merchant_trading'] / $actualRevenue : 0.0;
        $floatShare    = $actualRevenue > 0.0 ? $streamRevenues['financial_investments'] / $actualRevenue : 0.0;

        // The terminal estate buys an overhead basket; the desk's cost base is its cargo, priced against the
        // same commodity markets below, and the float buys nothing at all.
        $ppiCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin)
            * max(0.0, 1.0 - $floatShare - $merchantShare);

        $merchantCostShift = $this->resolveMerchantCostShift($macroState, $mathUtility, $realizedVariableMargin, $priceLift);

        $clampedMargin = $this->clampMargin(
            $realizedVariableMargin + $restructuringPenalty + $ppiCostDrag + ($merchantCostShift * $merchantShare)
        );

        $observableShockZ = ($merchantShock * $merchantWeight * self::MERCHANT_OBSERVABLE_DISCOUNT) +
            ($tollboothShock * $tollboothWeight) +
            ($floatShock * $floatWeight * self::FLOAT_OBSERVABLE_DISCOUNT) +
            ($merchantSurge * $merchantWeight * self::MERCHANT_OBSERVABLE_DISCOUNT) +
            ($tollboothMacroBoost * $tollboothWeight) +
            ($contrarianSurge * $floatWeight * self::CONTRARIAN_OBSERVABLE_DISCOUNT);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $streams->resolveDominantShockZ([$merchantZ, $tollboothZ, $floatZ], $eventZ),
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    /**
     * The share of turnover that is pure commodity price: the basket the book is invoiced against, less the
     * part of the business billed on a fixed tariff.
     */
    private function resolveMerchantPriceLift(MacroStateDTO $macroState): float
    {
        $basketDeviation = (self::MERCHANT_BOOK_EXPOSURES['metals'] * (($macroState->industrialMetalsIndexEma - 100.0) / 100.0))
            + (self::MERCHANT_BOOK_EXPOSURES['agri'] * (($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0))
            + (self::MERCHANT_BOOK_EXPOSURES['energy'] * (($macroState->energyPriceIndexEma - 100.0) / 100.0));

        return $basketDeviation * self::MERCHANT_PRICE_PASS_THROUGH;
    }

    /**
     * Merchant cost ratio: spread dilution, dislocation rent, and the funding cost of physical inventory.
     *
     * Three exact pieces, none of them a fitted curve:
     *  1. Dilution. The desk earns a per-unit spread, so if the PRICE of the cargo rises by p with the dollar
     *     gross profit unchanged, the cost ratio moves from c to (p + c) / (1 + p) — a rise of
     *     p(1 - c) / (1 + p). This is why a merchant's reported margin percentage collapses in a commodity
     *     boom while its profits rise. Volume is deliberately excluded: shipping more tonnage scales revenue
     *     and cost of goods together, which leaves the ratio untouched.
     *  2. Dislocation rent (Kaldor-Working convenience yield plus corridor congestion).
     *  3. Funding. Cargo carried on short wholesale credit for about a quarter, charged at the policy rate
     *     plus the credit spread above where they sit at trend.
     */
    private function resolveMerchantCostShift(
        MacroStateDTO $macroState,
        MathUtility $mathUtility,
        float $realizedVariableMargin,
        float $merchantPriceLift
    ): float {
        $priceLift = max(-0.90, $merchantPriceLift);
        $costRatio = max(0.0, min(1.0, $realizedVariableMargin));
        $spreadDilution = ($priceLift * (1.0 - $costRatio)) / (1.0 + $priceLift);

        $convenienceYield = $mathUtility->calculateConvenienceYield($macroState->energyInventoryIndexEma);
        $dislocationRent = ($convenienceYield * self::MERCHANT_BACKWARDATION_SCALAR)
            + (max(0.0, $macroState->supplyChainPressureIndexEma) * self::MERCHANT_DISRUPTION_SCALAR);

        $fundingGap = ($macroState->policyRateEma + $macroState->macroCreditSpreadEma)
            - ($macroState->perceivedNeutralRate + MacroEngine::BASE_CREDIT_SPREAD);
        $carryCost = $fundingGap * self::MERCHANT_INVENTORY_HOLDING_YEARS;

        $shift = $spreadDilution + $carryCost - $dislocationRent;

        return max(-self::MAX_MERCHANT_COST_SWING, min(self::MAX_MERCHANT_COST_SWING, $shift));
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
            'agricultural_commodity_index_ema',
            'corporate_default_rate_ema',
            'deal_activity_index_ema',
            'energy_cost_push_lag',
            'energy_inventory_index_ema',
            'energy_price_index_ema',
            'exchange_rate_index_ema',
            'industrial_metals_index_ema',
            'macro_credit_spread',
            'macro_credit_spread_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'producer_price_inflation_ema',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'trade_balance_to_gdp_ema',
            'wage_growth_ema',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Math;

use App\Service\Macro\MacroEngine;

/**
 * How macro aggregates and policy levers reach firms: demand shifts from the PMI, housing starts, foreign demand,
 * the trade balance, broad money and capacity utilisation; PPI stages and cost drag; supply-chain, deal-flow and
 * diffusion indices; GDP share drift; and the bank levy and stamp duty. Pure functions of their arguments.
 */
final class MacroTransmission
{
    /**
     * A sector's demand drift relative to the economy: the annual log change in its share of nominal GDP between
     * two benchmark years. Added to trend real growth it is the sector's secular real growth, and shares that sum
     * to GDP drift to zero on average, so the sectors together grow with the economy.
     */
    public static function gdpShareDrift(float $shareStart, float $shareEnd, float $years): float
    {
        if ($shareStart <= 0.0 || $shareEnd <= 0.0 || $years <= 0.0) {
            return 0.0;
        }

        return log($shareEnd / $shareStart) / $years;
    }

    /**
     * A bank levy for a year: the full rate on short-term funding, half of it on long-term funding and on uninsured
     * deposits from customers outside finance; insured deposits and Tier 1 equity are left out (UK Finance Act 2011,
     * Schedule 19).
     *
     * @param float $shortTermFunding Funding repayable within a year, in currency.
     * @param float $longTermFunding  Longer funding and uninsured deposits, in currency.
     * @param float $shortTermRate    The levy on short-term funding, a year.
     */
    public static function calculateAnnualBankLevy(float $shortTermFunding, float $longTermFunding, float $shortTermRate): float
    {
        return $shortTermRate * (max(0.0, $shortTermFunding) + (FinancialConstants::BANK_LEVY_LONG_TERM_RATE_SHARE * max(0.0, $longTermFunding)));
    }

    /**
     * Share turnover under a stamp duty, as a share of turnover at the founding rate: a duty paid on each side of a
     * trade costs a round trip twice the rate, and turnover falls with the round trip's cost above the founding one at
     * the semi-elasticity France's 2012 tax showed.
     *
     * @param float $stampDutyRate Duty on each side of a share trade.
     */
    public static function calculateStampDutyVolumeFactor(float $stampDutyRate): float
    {
        return exp(-FinancialConstants::STAMP_DUTY_VOLUME_SEMI_ELASTICITY * 2.0 * ($stampDutyRate - FinancialConstants::STAMP_DUTY_RATE));
    }

    /**
     * Calculates the NY Fed Global Supply Chain Pressure Index (GSCPI) (Benigno et al. 2022).
     *
     * Constructs a normalized composite Z-score measuring cross-border logistics friction,
     * combining container freight rates, inventory stock gaps, and raw material price pressures:
     *   GSCPI = wFreight * ((Freight - Base) / sigmaFreight) + wInv * (InventoryGap / sigmaInv) + wMetals * ((Metals - Base) / sigmaMetals)
     *
     * @param float $freightRateIndex       Ocean freight index.
     * @param float $inventoryStockGap      Metzler inventory cycle gap.
     * @param float $industrialMetalsIndex  Industrial metals price index.
     * @param float $freightBase            Neutral freight baseline (100.0).
     * @param float $metalsBase             Neutral metals baseline (100.0).
     * @return float Normalized GSCPI Z-score clamped between -2.0 and +4.0.
     */
    public static function calculateGscpiComposite(
        float $freightRateIndex,
        float $inventoryStockGap,
        float $industrialMetalsIndex,
        float $freightBase = 100.0,
        float $metalsBase = 100.0
    ): float {
        $freightZ = ($freightRateIndex - $freightBase) / 25.0;
        $inventoryZ = -$inventoryStockGap / 0.05; // Inventory shortages create supply chain stress
        $metalsZ = ($industrialMetalsIndex - $metalsBase) / 25.0;

        $composite = (0.50 * $freightZ) + (0.30 * $inventoryZ) + (0.20 * $metalsZ);
        return max(-2.0, min(4.0, $composite));
    }

    /**
     * Models the Capital Markets & M&A Deal Flow Index (Jovanovic & Rousseau 2002).
     *
     * Simulates global investment banking, private equity LBO, and IPO advisory volume
     * as an exponential function of valuation liquidity (ERP compression, tight high-yield spreads, and low volatility):
     *   Target = 100 * exp(-betaErp * erpExcess - betaHy * hySpreadExcess - betaVol * volExcess)
     * The log deviation is capped at ~1.7x either way: global M&A volume ran 2.2x from the 2007 peak to the 2009
     * trough, so a target range much wider than that would not be a cycle any market has produced.
     *
     * @param float $currentDealIndex   Current deal flow index.
     * @param float $equityRiskPremium  Current equity risk premium.
     * @param float $hyCreditSpread     Current speculative high-yield credit spread.
     * @param float $marketVolatility   Current equity market volatility.
     * @param float $dt                 Time increment in years.
     * @param float $dW                 Standard normal random shock.
     * @param float $kappa              Speed of adjustment toward fundamental deal capacity.
     * @param float $sigma              Stochastic deal volatility.
     * @param float $policyUncertaintyIndex Policy-uncertainty index (BBD scale, 100 neutral); boards defer deals while the regime is in question.
     * @return float Updated deal activity index clamped between 20.0 and 250.0.
     */
    public static function calculateCapitalMarketsDealIndexStep(
        float $currentDealIndex,
        float $equityRiskPremium,
        float $hyCreditSpread,
        float $marketVolatility,
        float $dt,
        float $dW,
        float $kappa = 1.60,
        float $sigma = 0.15,
        float $policyUncertaintyIndex = MacroEngine::EPU_BASELINE
    ): float {
        $erpExcess = ($equityRiskPremium - MacroEngine::BASE_EQUITY_RISK_PREMIUM) / 0.015;
        // 500 bps is one cycle-standard-deviation of HY OAS (2000-2024), so a 2008-type +1,500 bps reads as three units of stress.
        $hyExcess = ($hyCreditSpread - (MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER)) / 0.050;
        $volExcess = ($marketVolatility - MacroEngine::MACRO_VOL_BASE_ANCHOR) / 0.06;
        $epuExcess = log(max(1.0, $policyUncertaintyIndex) / MacroEngine::EPU_BASELINE) / MacroEngine::EPU_CYCLE_LOG_SD;

        $stressExponent = - (MacroEngine::DEAL_ACTIVITY_ERP_BETA * $erpExcess)
            - (MacroEngine::DEAL_ACTIVITY_HY_BETA * $hyExcess)
            - (MacroEngine::DEAL_ACTIVITY_VOL_BETA * $volExcess)
            - (MacroEngine::DEAL_ACTIVITY_EPU_BETA * $epuExcess);
        $targetIndex = MacroEngine::DEAL_ACTIVITY_BASELINE * exp(max(-MacroEngine::DEAL_ACTIVITY_LOG_RANGE, min(MacroEngine::DEAL_ACTIVITY_LOG_RANGE, $stressExponent)));

        $drift = $kappa * ($targetIndex - $currentDealIndex) * $dt;
        $diffusion = $sigma * $currentDealIndex * sqrt($dt) * $dW;
        $newIndex = $currentDealIndex + $drift + $diffusion;

        return max(20.0, min(250.0, $newIndex));
    }

    /**
     * Calculates a standard diffusion index (e.g. ISM Purchasing Managers' Index) centered around a neutral baseline.
     *
     * In standard macroeconomic surveys (ISM, S&P Global), values above 50 indicate expansion while below 50 indicate contraction.
     *
     * @param float $baseline Neutral survey baseline (canonical 50.0).
     * @param array<int, array{deviation: float, sensitivity: float}> $drivers Component factors with their respective sensitivities.
     * @param float $min      Asymptotic floor.
     * @param float $max      Asymptotic ceiling.
     * @return float Calculated diffusion index score.
     */
    public static function calculateDiffusionIndex(
        float $baseline,
        array $drivers,
        float $min = 30.0,
        float $max = 70.0
    ): float {
        $netAdjustment = 0.0;
        foreach ($drivers as $driver) {
            $netAdjustment += $driver['deviation'] * $driver['sensitivity'];
        }

        return max($min, min($max, $baseline + $netAdjustment));
    }

    /**
     * Stage-of-Processing Producer Price Index (PPI) wholesale pipeline inflation (Clark 1995).
     *
     * Evaluates intermediate wholesale inflation as a cost-push transmission of raw commodity inputs
     * (energy, metals, agriculture), freight/logistics bottlenecks (GSCPI), Unit Labor Costs (ULC),
     * and aggregate cyclical output gap demand pressure.
     *
     * @param float $metalsInflation   Annualized industrial metals price inflation.
     * @param float $energyInflation   Annualized energy price inflation.
     * @param float $agriInflation     Annualized agricultural commodity price inflation.
     * @param float $gscpiZ            Global supply chain pressure index (Z-score).
     * @param float $unitLaborCost     Unit labor cost growth (Wage Growth - TFP Trend Growth).
     * @param float $outputGap         Macroeconomic cyclical output gap.
     * @param array{metals: float, energy: float, agri: float, gscpi: float, ulc: float, demand: float} $weights Parameter weights.
     * @param float $min               Minimum annual PPI rate.
     * @param float $max               Maximum annual PPI rate.
     * @return float Producer price index inflation rate.
     */
    public static function calculateStageOfProcessingPpi(
        float $metalsInflation,
        float $energyInflation,
        float $agriInflation,
        float $gscpiZ,
        float $unitLaborCost,
        float $outputGap,
        array $weights,
        float $min = -0.06,
        float $max = 0.25
    ): float {
        $commodityComponent = ($weights['metals'] * $metalsInflation)
            + ($weights['energy'] * $energyInflation)
            + ($weights['agri'] * $agriInflation);

        $supplyChainComponent = $weights['gscpi'] * $gscpiZ;
        $laborComponent = $weights['ulc'] * $unitLaborCost;
        $demandComponent = $weights['demand'] * $outputGap;

        $ppi = $commodityComponent + $supplyChainComponent + $laborComponent + $demandComponent;
        return max($min, min($max, $ppi));
    }

    /**
     * Poterba (1984) / Topel & Rosen (1988) Tobin's Q Housing Investment Dynamics.
     *
     * Models residential construction volume (Housing Starts) driven by the ratio of asset market home prices
     * to physical replacement costs, discounted by mortgage user costs and bank lending standards:
     *   q = P_residential / Cost_replacement
     *
     * @param float $currentStarts        Current housing starts index.
     * @param float $residentialPriceRatio Current home price relative to neutral baseline.
     * @param float $replacementCostRatio Current construction replacement cost relative to baseline.
     * @param float $userCost             User cost of residential capital (mortgage rate + taxes - inflation).
     * @param float $neutralUserCost      Structural equilibrium user cost of housing.
     * @param float $sloosTightening      SLOOS bank mortgage credit tightening percentage.
     * @param float $dt                   Time step in years.
     * @param float $dW                   Standard normal random shock.
     * @param array{baseline: float, qSens: float, costSens: float, sloosSens: float, kappa: float, sigma: float, min: float, max: float, creditGapSens?: float} $params Calibration parameters.
     * @param float $creditGap            Excess credit-to-GDP gap relative to trend.
     * @return float Updated housing starts index.
     */
    public static function calculateTobinsQHousingStarts(
        float $currentStarts,
        float $residentialPriceRatio,
        float $replacementCostRatio,
        float $userCost,
        float $neutralUserCost,
        float $sloosTightening,
        float $dt,
        float $dW,
        array $params,
        float $creditGap = 0.0
    ): float {
        $effectiveCost = max(0.20, $replacementCostRatio);
        $tobinsQ = $residentialPriceRatio / $effectiveCost;
        $qExcess = $tobinsQ - 1.0;

        $userCostExcess = $userCost - $neutralUserCost;
        $creditDrag = max(0.0, $sloosTightening);
        $creditGapSens = $params['creditGapSens'] ?? 0.0;

        $targetStarts = $params['baseline']
            + ($params['qSens'] * $qExcess)
            - ($params['costSens'] * $userCostExcess)
            - ($params['sloosSens'] * $creditDrag)
            + ($creditGapSens * $creditGap);

        $clampedTarget = max($params['min'], min($params['max'], $targetStarts));

        $drift = $params['kappa'] * ($clampedTarget - $currentStarts) * $dt;
        $diffusion = $params['sigma'] * $currentStarts * sqrt($dt) * $dW;
        $newStarts = $currentStarts + $drift + $diffusion;

        return max($params['min'], min($params['max'], $newStarts));
    }

    /**
     * Brunner-Meltzer / Friedman-Schwartz M2 Broad Money Supply & Credit Channel Dynamics.
     *
     * Derives annual M2 broad money supply growth from central bank balance sheet liquidity creation (QE/QT),
     * commercial banking credit multipliers (SLOOS underwriting standards), and output gap credit demand:
     *   Target = BaseGrowth + betaQE * BalanceSheet - betaSLOOS * SLOOS + betaY * OutputGap
     * Standards enter signed: easing lends deposits into being as tightening withholds them (Lown & Morgan 2006 read
     * the signed net-tightening series).
     *
     * @param float $currentM2Growth  Current annual M2 money supply growth rate.
     * @param float $baseGrowth       Long-run neutral M2 growth matching potential output and inflation target.
     * @param float $balanceSheetIntensity Central bank balance sheet intensity (+ for QE, - for QT).
     * @param float $sloosTightening  Net percentage of banks tightening credit standards.
     * @param float $outputGap        Cyclical GDP output gap.
     * @param float $dt               Time step in years.
     * @param float $dW               Standard normal random shock.
     * @param array{qeSens: float, sloosSens: float, gapSens: float, kappa: float, sigma: float, min: float, max: float} $params Calibration parameters.
     * @return float Updated annual M2 money supply growth rate.
     */
    public static function calculateBroadMoneyGrowth(
        float $currentM2Growth,
        float $baseGrowth,
        float $balanceSheetIntensity,
        float $sloosTightening,
        float $outputGap,
        float $dt,
        float $dW,
        array $params
    ): float {
        $targetM2Growth = $baseGrowth
            + ($params['qeSens'] * $balanceSheetIntensity)
            - ($params['sloosSens'] * $sloosTightening)
            + ($params['gapSens'] * $outputGap);

        $clampedTarget = max($params['min'], min($params['max'], $targetM2Growth));

        $drift = $params['kappa'] * ($clampedTarget - $currentM2Growth) * $dt;
        $diffusion = $params['sigma'] * sqrt($dt) * $dW;
        $newGrowth = $currentM2Growth + $drift + $diffusion;

        return max($params['min'], min($params['max'], $newGrowth));
    }

    /**
     * Calculates cyclical demand shift from manufacturing diffusion survey indices (ISM / S&P PMI).
     *
     * In empirical macroeconomics, a diffusion index above 50 indicates expansion while below 50 indicates contraction.
     * Normalized as percentage deviation from neutral baseline (50.0).
     *
     * @param float $pmi         Manufacturing purchasing managers' index.
     * @param float $baseline    Neutral survey baseline (canonical 50.0).
     * @param float $sensitivity Sector demand sensitivity multiplier.
     * @return float Cyclical demand shift bounded in [-0.30, 0.30].
     */
    public static function calculatePmiDemandShift(
        float $pmi,
        float $baseline = MacroEngine::PMI_BASELINE,
        float $sensitivity = 0.50
    ): float {
        $base = max(1.0, $baseline);
        $deviation = ($pmi - $base) / $base;
        return max(-0.30, min(0.30, $deviation * $sensitivity));
    }

    /**
     * Stage-of-Processing Producer Price Index (PPI) variable cost drag (Clark 1995).
     *
     * Evaluates wholesale input material cost pressure on gross variable margins,
     * mitigated by the firm's structural pricing power.
     *
     * @param float $ppi             Producer price wholesale inflation rate.
     * @param float $targetInflation Central bank target inflation benchmark (~2%).
     * @param float $pricingPower    Firm pricing power index [0.0, 1.0].
     * @param float $sensitivity     Sector gross cost sensitivity multiplier.
     * @return float Margin cost penalty bounded in [0.0, 0.25].
     */
    public static function calculatePpiCostDrag(
        float $ppi,
        float $targetInflation = MacroEngine::TARGET_INFLATION,
        float $pricingPower = 0.50,
        float $sensitivity = 0.50
    ): float {
        $excessPpi = max(0.0, $ppi - $targetInflation);
        $effectivePassThrough = 1.0 - min(1.0, max(0.0, $pricingPower));
        return min(0.25, $excessPpi * $effectivePassThrough * $sensitivity);
    }

    /**
     * Calculates construction and building material volume shifts from residential housing starts (Tobin's q).
     *
     * Normalized as percentage deviation from neutral housing starts baseline (100.0).
     *
     * @param float $starts      Residential housing starts index.
     * @param float $baseline    Neutral activity baseline (canonical 100.0).
     * @param float $sensitivity Sector volume sensitivity multiplier.
     * @return float Volume shift bounded in [-0.25, 0.25].
     */
    public static function calculateHousingStartsShift(
        float $starts,
        float $baseline = MacroEngine::HOUSING_STARTS_BASELINE,
        float $sensitivity = 0.30
    ): float {
        $base = max(1.0, $baseline);
        $deviation = ($starts - $base) / $base;
        return max(-0.25, min(0.25, $deviation * $sensitivity));
    }

    /**
     * Export demand shift from the foreign bloc's cycle: the mirror of the trade-balance shift, for the firms
     * whose customers are abroad. Bounded like it.
     *
     * @param float $foreignOutputGap Foreign output gap (fraction).
     * @param float $sensitivity      Export volume elasticity to the foreign gap.
     * @return float Volume shift bounded in [-0.20, 0.20].
     */
    public static function calculateForeignDemandShift(float $foreignOutputGap, float $sensitivity = 2.0): float
    {
        return max(-0.20, min(0.20, $foreignOutputGap * $sensitivity));
    }

    /**
     * Calculates international merchandise trade flow volume shifts (Mundell-Fleming).
     *
     * Evaluates net exports as a fraction of GDP relative to baseline structural trade balance (-2.8%).
     *
     * @param float $tradeBalance Current trade balance to GDP ratio.
     * @param float $baseline     Neutral structural trade balance baseline (-0.028).
     * @param float $sensitivity  Trade volume elasticity multiplier.
     * @return float Trade volume shift bounded in [-0.20, 0.20].
     */
    public static function calculateTradeBalanceShift(
        float $tradeBalance,
        float $baseline = MacroEngine::TRADE_BALANCE_BASELINE,
        float $sensitivity = 2.0
    ): float {
        $deviation = $tradeBalance - $baseline;
        return max(-0.20, min(0.20, $deviation * $sensitivity));
    }

    /**
     * Calculates broad liquidity expansion/contraction shifts from M2 money supply growth (Friedman-Schwartz).
     *
     * Evaluates systemic financial liquidity driving deposit growth, AUM fund inflows, and retail market participation.
     * A cyclical reading: pass the measured trend (MacroStateDTO::$moneySupplyGrowthTrend) as the baseline, since
     * M2 growth settles wherever its QE, standards and gap legs put it, not at M2_BASE_GROWTH.
     *
     * @param float $m2Growth    Annual broad money supply M2 growth rate.
     * @param float $baseline    M2 growth's measured trend.
     * @param float $sensitivity Liquidity sensitivity multiplier.
     * @return float Liquidity shift bounded in [-0.15, 0.15].
     */
    public static function calculateBroadMoneyLiquidityShift(
        float $m2Growth,
        float $baseline,
        float $sensitivity = 0.50
    ): float {
        $deviation = $m2Growth - $baseline;
        return max(-0.15, min(0.15, $deviation * $sensitivity));
    }

    /**
     * Calculates factory and industrial throughput shifts from Federal Reserve G.17 capacity utilization.
     *
     * The shift is the utilization gap -- the deviation from the neutral rate -- carried into demand or
     * overhead absorption at the sector's sensitivity. Both rate and baseline are FRACTIONS as G.17 and
     * MacroEngine::CU_BASELINE express them (0.785, not 78.5), so their difference is already the gap in
     * unit terms: 80.5% against a 78.5% neutral is 0.020, or two percentage points of slack absorbed.
     *
     * @param float $cuRate      Industrial capacity utilization rate as a fraction (e.g. 0.785).
     * @param float $baseline    Neutral capacity utilization baseline as a fraction (~0.785).
     * @param float $sensitivity Sector throughput sensitivity multiplier.
     * @return float Throughput shift bounded in [-0.15, 0.15].
     */
    public static function calculateCapacityUtilizationShift(
        float $cuRate,
        float $baseline = MacroEngine::CU_BASELINE,
        float $sensitivity = 0.40
    ): float {
        $deviation = $cuRate - $baseline;
        return max(-0.15, min(0.15, $deviation * $sensitivity));
    }

    /**
     * Calculates the non-linear capacity-constrained output gap effect on inflation
     * using the Benigno & Eggertsson (2023) / Harding, Lindé, & Trabandt (2022) convex Phillips curve.
     *
     * During economic expansions, as output gap y approaches structural capacity ceiling y_max, supply bottlenecks
     * bind asymptotically, causing non-linear inflation acceleration. During contractions (y < 0),
     * downward nominal wage and price rigidity flattens the curve toward a sticky minimum slope.
     *
     * Formula:
     *   For y >= 0: f(y) = kappa * (y / max(0.001, y_max - y))
     *   For y < 0:  f(y) = kappa * downwardRigidityFactor * y
     *
     * @param float $outputGap              Current macroeconomic output gap (e.g., 0.02 for +2%).
     * @param float $maxCapacity            Asymptotic output gap ceiling where capacity binds (e.g., 0.08).
     * @param float $kappa                  Baseline slope sensitivity parameter.
     * @param float $downwardRigidityFactor Downward nominal rigidity slope multiplier (0 < factor < 1).
     * @return float Non-linear demand-pull Phillips curve inflation pressure.
     */
    public static function calculateConvexPhillipsCurve(
        float $outputGap,
        float $maxCapacity = 0.08,
        float $kappa = 0.020,
        float $downwardRigidityFactor = 0.35
    ): float {
        if ($outputGap >= 0.0) {
            $effectiveCeiling = max(0.005, $maxCapacity - $outputGap);
            return $kappa * ($outputGap / $effectiveCeiling);
        }

        $baseSlope = $maxCapacity > 0 ? ($kappa / $maxCapacity) : 0.25;
        return $baseSlope * $downwardRigidityFactor * $outputGap;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * How macro aggregates and policy levers reach firms: demand shifts from the PMI, housing starts, foreign demand,
 * the trade balance, broad money and capacity utilisation; PPI stages and cost drag; supply-chain, deal-flow and
 * diffusion indices; GDP share drift; and the bank levy and stamp duty. Pure functions of their arguments.
 */
final class MacroTransmission
{
    // --- Stamp Duty (Colliard & Hoffmann 2017) ---
    /**
     * Log fall in share turnover per unit of round-trip duty above the founding rate: France's 0.2% tax on purchases in
     * August 2012 cut trading volume by about 10% (Colliard & Hoffmann 2017, JF), ln(1 / 0.9) / 0.002.
     */
    public const STAMP_DUTY_VOLUME_SEMI_ELASTICITY = 52.68;

    // --- Queueing (Kingman 1961) ---
    /** Smallest idle share the congestion term divides by, so a queue at full utilization stays finite. */
    public const KINGMAN_MIN_IDLE_SHARE = 0.01;

    // --- Bank Levy (UK Finance Act 2011, Schedule 19; FDIC) ---
    /**
     * The levy on long-term funding and on deposits from customers outside finance, as a share of the rate on
     * short-term funding: half (the UK's long-term rate, 0.105% against 0.21% in 2015).
     */
    public const BANK_LEVY_LONG_TERM_RATE_SHARE = 0.5;

    /**
     * A sector's demand drift relative to the economy: the annual log change in its share of nominal GDP between
     * two benchmark years. Added to trend real growth it is the sector's secular real growth, and shares that sum
     * to GDP drift to zero on average, so the sectors together grow with the economy.
     */

    // --- Jovanovic-Rousseau (2002) Capital Markets & M&A Deal Flow ---
    /** Log-elasticity of deal flow to one cycle-standard-deviation (150bps) of equity risk premium. */
    public const DEAL_ACTIVITY_ERP_BETA = 0.175;
    /** Log-elasticity of deal flow to one cycle-standard-deviation (500bps) of high-yield OAS. */
    public const DEAL_ACTIVITY_HY_BETA = 0.225;
    /** Log-elasticity of deal flow to one cycle-standard-deviation (6 vol points) of equity volatility. */
    public const DEAL_ACTIVITY_VOL_BETA = 0.10;
    /**
     * Cap on the log deviation of the deal flow target (~1.7x either way): global M&A volume ran 2.2x from the 2007
     * peak to the 2009 trough, the widest cycle on record, so a 2008 freeze against a 2007 boom stays under 3x.
     */
    public const DEAL_ACTIVITY_LOG_RANGE = 0.55;

    // --- Economic Policy Uncertainty (Baker, Bloom & Davis 2016) ---
    /** One cycle-standard-deviation of the log index (~0.35): the unit the deal-flow elasticity is quoted in. */
    public const EPU_CYCLE_LOG_SD = 0.35;
    /**
     * Log-elasticity of deal flow to one standard deviation of policy uncertainty: acquisition activity falls by
     * single-digit percents per sd (Bonaime, Gulen & Ion 2018).
     */
    public const DEAL_ACTIVITY_EPU_BETA = 0.10;
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
        return $shortTermRate * (max(0.0, $shortTermFunding) + (self::BANK_LEVY_LONG_TERM_RATE_SHARE * max(0.0, $longTermFunding)));
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
        return exp(-self::STAMP_DUTY_VOLUME_SEMI_ELASTICITY * 2.0 * ($stampDutyRate - FinancialConstants::STAMP_DUTY_RATE));
    }

    /**
     * Kingman's (1961) heavy-traffic approximation for a G/G/1 queue: the expected wait scales with rho / (1 - rho),
     * so lead times stretch convexly as a supplier's utilization nears capacity.
     */
    public static function kingmanCongestion(float $utilization): float
    {
        return $utilization / max(self::KINGMAN_MIN_IDLE_SHARE, 1.0 - $utilization);
    }

    /**
     * Models the Capital Markets & M&A Deal Flow Index (Jovanovic & Rousseau 2002).
     *
     * Simulates global investment banking, private equity LBO, and IPO advisory volume as an exponential function of
     * valuation liquidity (ERP compression, tight high-yield spreads, and low volatility):
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
     * @param float $policyUncertaintyIndex Policy-uncertainty index (BBD scale, 100 neutral); boards defer deals while
     *                                      the regime is in question.
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
        float $policyUncertaintyIndex = MacroConstants::EPU_BASELINE,
    ): float {
        $erpExcess = ($equityRiskPremium - MacroConstants::BASE_EQUITY_RISK_PREMIUM) / 0.015;
        // 500 bps is one cycle-standard-deviation of HY OAS (2000-2024), so a 2008-type +1,500 bps reads as three units
        // of stress.
        $hyExcess = ($hyCreditSpread - (MacroConstants::BASE_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER)) / 0.050;
        $volExcess = ($marketVolatility - MacroConstants::MACRO_VOL_BASE_ANCHOR) / 0.06;
        $epuExcess = log(max(1.0, $policyUncertaintyIndex) / MacroConstants::EPU_BASELINE) / self::EPU_CYCLE_LOG_SD;

        $stressExponent = - (self::DEAL_ACTIVITY_ERP_BETA * $erpExcess)
            - (self::DEAL_ACTIVITY_HY_BETA * $hyExcess)
            - (self::DEAL_ACTIVITY_VOL_BETA * $volExcess)
            - (self::DEAL_ACTIVITY_EPU_BETA * $epuExcess);
        $targetIndex = MacroConstants::DEAL_ACTIVITY_BASELINE * exp(max(-self::DEAL_ACTIVITY_LOG_RANGE, min(self::DEAL_ACTIVITY_LOG_RANGE, $stressExponent)));

        $drift = $kappa * ($targetIndex - $currentDealIndex) * $dt;
        $diffusion = $sigma * $currentDealIndex * sqrt($dt) * $dW;
        $newIndex = $currentDealIndex + $drift + $diffusion;

        return max(20.0, min(250.0, $newIndex));
    }

    /**
     * Calculates a standard diffusion index (e.g. ISM Purchasing Managers' Index) centered around a neutral baseline.
     *
     * In standard macroeconomic surveys (ISM, S&P Global), values above 50 indicate expansion while below 50 indicate
     * contraction.
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
        float $max = 70.0,
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
     * the rest of the cost base (purchased services, rents, margins) and aggregate cyclical output gap
     * demand pressure. The cost shares sum to one, so with every cost at trend the index runs at trend.
     *
     * @param float $metalsInflation   Annualized industrial metals price inflation.
     * @param float $energyInflation   Annualized energy price inflation.
     * @param float $agriInflation     Annualized agricultural commodity price inflation.
     * @param float $gscpiZ            Global supply chain pressure index (Z-score).
     * @param float $unitLaborCost     Unit labor cost growth (Wage Growth - TFP Trend Growth).
     * @param float $otherCostInflation Inflation of the rest of the cost base.
     * @param float $outputGap         Macroeconomic cyclical output gap.
     * @param array{metals: float, energy: float, agri: float, gscpi: float, ulc: float, other: float, demand: float} $weights Parameter weights.
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
        float $otherCostInflation,
        float $outputGap,
        array $weights,
        float $min = -0.06,
        float $max = 0.25,
    ): float {
        $commodityComponent = ($weights['metals'] * $metalsInflation)
            + ($weights['energy'] * $energyInflation)
            + ($weights['agri'] * $agriInflation);

        $supplyChainComponent = $weights['gscpi'] * $gscpiZ;
        $laborComponent = $weights['ulc'] * $unitLaborCost;
        $otherCostComponent = $weights['other'] * $otherCostInflation;
        $demandComponent = $weights['demand'] * $outputGap;

        $ppi = $commodityComponent + $supplyChainComponent + $laborComponent + $otherCostComponent + $demandComponent;
        return max($min, min($max, $ppi));
    }

    /**
     * Poterba (1984) / Topel & Rosen (1988) Tobin's Q Housing Investment Dynamics.
     *
     * Residential construction volume (Housing Starts) closes on a target set by the households forming, the ratio
     * of home prices to physical replacement cost, q = P_residential / Cost_replacement, and bank lending standards.
     *
     * @param float $currentStarts         Current housing starts index.
     * @param float $residentialPriceRatio Current home price relative to its trend.
     * @param float $replacementCostRatio  Current construction replacement cost relative to its trend.
     * @param float $sloosTightening       SLOOS bank mortgage credit tightening percentage.
     * @param float $dt                    Time step in years.
     * @param float $dW                    Standard normal random shock.
     * @param array{baseline: float, qSens: float, sloosSens: float, kappa: float, sigma: float, min: float, max: float, creditGapSens?: float} $params Calibration parameters.
     * @param float $creditGap             Excess credit-to-GDP gap relative to trend.
     * @param float $householdFormation    Households forming beyond the steady rate, a share of baseline starts.
     * @return float Updated housing starts index.
     */
    public static function calculateTobinsQHousingStarts(
        float $currentStarts,
        float $residentialPriceRatio,
        float $replacementCostRatio,
        float $sloosTightening,
        float $dt,
        float $dW,
        array $params,
        float $creditGap = 0.0,
        float $householdFormation = 0.0,
    ): float {
        $qExcess = ($residentialPriceRatio / $replacementCostRatio) - 1.0;

        // Both ways: standards tighter than neutral hold starts back and looser ones let more through.
        $creditDrag = $sloosTightening;
        $creditGapSens = $params['creditGapSens'] ?? 0.0;

        $targetStarts = ($params['baseline'] * (1.0 + $householdFormation))
            + ($params['qSens'] * $qExcess)
            - ($params['sloosSens'] * $creditDrag)
            + ($creditGapSens * $creditGap);

        $clampedTarget = max($params['min'], min($params['max'], $targetStarts));

        $drift = $params['kappa'] * ($clampedTarget - $currentStarts) * $dt;
        $diffusion = $params['sigma'] * $currentStarts * sqrt($dt) * $dW;
        $newStarts = $currentStarts + $drift + $diffusion;

        return max($params['min'], min($params['max'], $newStarts));
    }

    /**
     * Broad money growth from what creates deposits (McLeay, Radia & Thomas 2014): nominal income, plus the deposits
     * household credit growing beyond it and the central bank's purchases add, each as a share of the money stock. A
     * loan creates its deposit, so a unit of new credit is a unit of new money, as a unit of purchases is.
     *
     * @param float $nominalIncomeGrowth     Nominal income growth, a year.
     * @param float $householdNewBorrowing   Change in household debt to income, a year.
     * @param float $authorityPurchasesToGdp Bonds the central bank buys over GDP, a year.
     * @param float $broadMoneyToGdp         Broad money over GDP.
     */
    public static function broadMoneyGrowth(
        float $nominalIncomeGrowth,
        float $householdNewBorrowing,
        float $authorityPurchasesToGdp,
        float $broadMoneyToGdp,
    ): float {
        return $nominalIncomeGrowth + (($householdNewBorrowing + $authorityPurchasesToGdp) / $broadMoneyToGdp);
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
        float $baseline = MacroConstants::PMI_BASELINE,
        float $sensitivity = 0.50,
    ): float {
        $base = max(1.0, $baseline);
        $deviation = ($pmi - $base) / $base;
        return max(-0.30, min(0.30, $deviation * $sensitivity));
    }

    /**
     * Spreads a firm-wide demand shift across its revenue streams by each stream's cycle loading. The engine lands the
     * shift on the whole of expected revenue; this returns what each stream adds on top so that it carries the shift
     * times its loading over the firm's weighted mean loading. The adjustments weight to zero, so the firm's total
     * still moves by the shift: a defensive stream gives its share back to the cyclical ones.
     *
     * @param array<string, float> $weights  Stream revenue weights.
     * @param array<string, float> $loadings Each stream's exposure to the cycle, relative to the others.
     * @param float                $shift    The firm-wide demand shift the engine applied.
     * @return array<string, float> Additive shift per stream.
     */
    public static function allocateDemandShift(array $weights, array $loadings, float $shift): array
    {
        $totalWeight = array_sum($weights);
        $weightedLoading = 0.0;
        foreach ($weights as $key => $weight) {
            $weightedLoading += $weight * $loadings[$key];
        }
        $meanLoading = $totalWeight > 0.0 ? $weightedLoading / $totalWeight : 0.0;

        $adjustments = [];
        foreach ($weights as $key => $weight) {
            $adjustments[$key] = $meanLoading > 0.0 ? $shift * (($loadings[$key] / $meanLoading) - 1.0) : 0.0;
        }

        return $adjustments;
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
        float $targetInflation = MacroConstants::TARGET_INFLATION,
        float $pricingPower = 0.50,
        float $sensitivity = 0.50,
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
        float $baseline = MacroConstants::HOUSING_STARTS_BASELINE,
        float $sensitivity = 0.30,
    ): float {
        $base = max(1.0, $baseline);
        $deviation = ($starts - $base) / $base;
        return max(-0.25, min(0.25, $deviation * $sensitivity));
    }

    /**
     * Export demand shift from the foreign bloc's cycle, for the firms whose customers are abroad.
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
        float $sensitivity = 0.50,
    ): float {
        $deviation = $m2Growth - $baseline;
        return max(-0.15, min(0.15, $deviation * $sensitivity));
    }

    /**
     * Calculates factory and industrial throughput shifts from Federal Reserve G.17 capacity utilization.
     *
     * The shift is the utilization gap -- the deviation from the neutral rate -- carried into demand or
     * overhead absorption at the sector's sensitivity. Both rate and baseline are FRACTIONS as G.17 and
     * MacroConstants::CU_BASELINE express them (0.785, not 78.5), so their difference is already the gap in
     * unit terms: 80.5% against a 78.5% neutral is 0.020, or two percentage points of slack absorbed.
     *
     * @param float $cuRate      Industrial capacity utilization rate as a fraction (e.g. 0.785).
     * @param float $baseline    Neutral capacity utilization baseline as a fraction (~0.785).
     * @param float $sensitivity Sector throughput sensitivity multiplier.
     * @return float Throughput shift bounded in [-0.15, 0.15].
     */
    public static function calculateCapacityUtilizationShift(
        float $cuRate,
        float $baseline = MacroConstants::CU_BASELINE,
        float $sensitivity = 0.40,
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
        float $downwardRigidityFactor = 0.35,
    ): float {
        if ($outputGap >= 0.0) {
            $effectiveCeiling = max(0.005, $maxCapacity - $outputGap);
            return $kappa * ($outputGap / $effectiveCeiling);
        }

        $baseSlope = $maxCapacity > 0 ? ($kappa / $maxCapacity) : 0.25;
        return $baseSlope * $downwardRigidityFactor * $outputGap;
    }
}

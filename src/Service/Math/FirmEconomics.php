<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Industrial organisation and the cost side of a firm: Cournot and fringe price levels, concentration, segment
 * counts, per-unit and productivity-loss cost factors, the REIT lease ladder, the inventory cycle, capacity
 * utilisation, the refining crack spread, power-price and hedge-ladder terms, working-capital days, the income tax
 * with its loss carryforward, and growth contributions. Pure functions of their arguments.
 */
final class FirmEconomics
{
    // --- Corporate Taxation ---
    /** Max % of taxable income that can be shielded by NOLs (e.g. 80% post-TCJA). */
    public const NOL_MAX_SHIELD_RATIO = 0.80;

    // --- Dynamic Cash Conversion Cycle & Working Capital (CCC) ---
    /** Sensitivity of Days Sales Outstanding (DSO) to corporate credit spread widening. */
    public const CCC_DSO_CREDIT_SPREAD_SENSITIVITY = 250.0;
    /** Sensitivity of Days Inventory Outstanding (DIO) to stranded capacity / inventory overhang. */
    public const CCC_DIO_CAPACITY_SENSITIVITY = 20.0;
    /** Days Payable Outstanding (DPO) given up per unit of interbank spread over its trend (5 days per 100bp). */
    public const CCC_DPO_LIQUIDITY_SENSITIVITY = 500.0;

    // --- Structural Turnover ---
    /** Floor on the operating margin a turnover is struck at: 1%, so a firm at a zero margin keeps a finite one. */
    public const MIN_TURNOVER_MARGIN = 0.01;

    // --- Regulated Tariffs: the Rate Case Cycle (regulatory lag and earnings attrition) ---
    /** Unrecovered non-fuel cost at which management files a general rate case rather than absorb further attrition. */
    public const RATE_CASE_FILING_THRESHOLD = 0.02;
    /** Quarterly probability a pending case is decided (~5 quarters, the 12 to 18 month regulatory lag). */
    public const RATE_CASE_EXIT_HAZARD = 0.20;
    /**
     * The same hazard as a rate a year, -4 ln(1 - 0.20): the speed a change in what commissions grant reaches the
     * tariff.
     */
    public const RATE_CASE_DECISION_RATE = 0.8926;
    /**
     * The average tariff shortfall the founding commission's grant leaves (steadyTariffShortfall() at it, 3.7%), where
     * the District's trailing earnings open.
     */
    public const FOUNDING_TARIFF_SHORTFALL = 0.0372;
    /**
     * What an elected commission grants of a request, as a share of what an appointed one grants: their pass-through of
     * fuel-cost increases into residential prices, 0.22 against 0.62 (Besley & Coate 2003, Table 4, with controls,
     * 1960-97), read across from cost pass-through to rate requests.
     */
    public const ELECTED_COMMISSION_GRANT_RATIO = 0.22 / 0.62;
    /**
     * Share of a request an appointed commission grants, the District's at its founding; the disallowed remainder is
     * still a real cost, so it rolls into the next filing. The US average, 58% of the net electric increases requested
     * January 2023 to August 2024 (S&P Global data via EIA, Today in Energy, August 2024), is held by 40 appointed
     * commissions and 10 elected ones.
     */
    public const APPOINTED_COMMISSION_GRANTED_SHARE = 0.58 / (0.8 + (0.2 * self::ELECTED_COMMISSION_GRANT_RATIO));
    /** Lag at which interim relief is granted rather than leave the utility earning below its cost of service. */
    public const MAX_TARIFF_SHORTFALL = 0.10;

    /**
     * Revenue per unit of invested capital at which a firm earns an after-tax return at an operating margin: the
     * return grossed up for tax, over the margin. A firm whose structure earns nothing turns over nothing.
     */
    public static function structuralAssetTurnover(float $afterTaxReturn, float $operatingMargin, float $taxRate): float
    {
        return max(0.0, $afterTaxReturn) / (max(self::MIN_TURNOVER_MARGIN, $operatingMargin) * (1.0 - $taxRate));
    }

    /**
     * One step of a staggered lease roll: the slice of the rent roll expiring over dt (dt / WALT) reprices to
     * market, the rest stays contracted. The mark-to-market gap is bounded, past which tenants renegotiate or
     * hand back space.
     *
     * @param float $inPlaceRent   In-place rent level, as a deviation from the trend rent.
     * @param float $marketRent    Market rent on the same scale.
     * @param float $waltYears     Weighted average lease term of the roll.
     * @param float $maxSpread     Bound on |market - in-place|.
     * @param float $dt            Step in years.
     * @return array{0: float, 1: float} Rolled in-place rent, re-leasing spread.
     */
    public static function rollLeaseLadder(float $inPlaceRent, float $marketRent, float $waltYears, float $maxSpread, float $dt): array
    {
        $releasingSpread = max(-$maxSpread, min($maxSpread, $marketRent - $inPlaceRent));
        $rollover = $dt / max($dt, $waltYears);

        return [$inPlaceRent + ($releasingSpread * $rollover), $releasingSpread];
    }

    /**
     * The tariff shortfall a commission granting this share of each request leaves on average, as a share of the
     * tariff: the cycle UtilityBusinessModel runs, in expectation. Costs outrun the frozen tariff at the inflation
     * rate; a case is filed at the threshold, or at once if the last order left the shortfall above it, and decided at
     * the decision rate; the order removes the granted share. With D the case's exponential length, the time-weighted
     * shortfall while it is pending is its starting level plus inflation over the decision rate, and the start settles
     * where an order leaves it (the expectation of D in place of D, so the spread of case lengths is not carried).
     *
     * @param float $grantShare Share of each request the commission grants.
     * @param float $inflation  Inflation the cost base rises at, a year.
     */
    public static function steadyTariffShortfall(float $grantShare, float $inflation = MacroConstants::TARGET_INFLATION): float
    {
        $grant = max(0.01, min(1.0, $grantShare));
        $drift = max(0.0, $inflation) / self::RATE_CASE_DECISION_RATE;
        $threshold = self::RATE_CASE_FILING_THRESHOLD;
        // Where a case starts: the threshold, or the shortfall an order leaves if that is above it (s = (1 - g)(s +
        // drift)).
        $start = max($threshold, (1.0 - $grant) * $drift / $grant);
        $pendingMean = $start + $drift;
        $afterOrder = (1.0 - $grant) * $pendingMean;
        $idleYears = $inflation > 0.0 ? max(0.0, $threshold - $afterOrder) / $inflation : 0.0;
        $pendingYears = 1.0 / self::RATE_CASE_DECISION_RATE;
        $mean = (($pendingMean * $pendingYears) + ((($threshold + $afterOrder) / 2.0) * $idleYears)) / ($pendingYears + $idleYears);

        return min(self::MAX_TARIFF_SHORTFALL, $mean);
    }

    /**
     * The price level an industry's installed capacity clears at, relative to the level at which capacity
     * equals trend demand: the constant-elasticity inverse demand curve P/P* = (Q/Q*)^(-1/e) that Cournot
     * quantity competition is played on. Ten percent of excess capacity at e = 1.25 clears seven percent
     * cheaper; a market short of capacity clears dear.
     *
     * @param float $capacityRatio Installed capacity over trend demand, already bounded by the caller.
     * @param float $elasticity    Industry price elasticity of demand (must be positive).
     */
    public static function calculateCournotPriceLevel(float $capacityRatio, float $elasticity): float
    {
        if ($capacityRatio <= 0.0 || $elasticity <= 0.0) {
            return 1.0;
        }

        return $capacityRatio ** (-1.0 / $elasticity);
    }

    /**
     * The price level an industry clears at once the competitive fringe has answered it (Forchheimer dominant
     * firm with a price-taking fringe). The modelled roster holds its plant fixed at rosterShare + excess of
     * trend demand; the fringe supplies (1 - rosterShare) * P^eta; demand is P^(-e). The clearing price solves
     *   rosterShare + excess + (1 - rosterShare) * P^eta = P^(-e),
     * which is the plain Cournot level when eta = 0 or the roster is the whole market. Overbuilding is then
     * partly absorbed by fringe exit rather than carried forever as a price cut.
     *
     * @param float $capacityRatio     Installed supply over trend demand at the trend price, already bounded.
     * @param float $rosterShare       The modelled roster's trend share of the market it sells into (0..1).
     * @param float $elasticity        Industry price elasticity of demand (must be positive).
     * @param float $fringeElasticity  Long-run supply elasticity of the fringe (0 = fixed at trend).
     */
    public static function calculateFringeAdjustedPriceLevel(float $capacityRatio, float $rosterShare, float $elasticity, float $fringeElasticity): float
    {
        if ($capacityRatio <= 0.0 || $elasticity <= 0.0) {
            return 1.0;
        }
        $rosterShare = max(0.0, min(1.0, $rosterShare));
        $fringeShare = 1.0 - $rosterShare;
        if ($fringeElasticity <= 0.0 || $fringeShare <= 0.0) {
            return self::calculateCournotPriceLevel($capacityRatio, $elasticity);
        }

        // f(P) = roster + excess + fringe * P^eta - P^(-e) rises monotonically in P; bracket and bisect.
        $rosterSupply = $capacityRatio - $fringeShare;
        $f = fn(float $p): float => $rosterSupply + $fringeShare * ($p ** $fringeElasticity) - ($p ** (-$elasticity));
        $low = 0.05;
        $high = 20.0;
        if ($f($low) >= 0.0) {
            return $low;
        }
        if ($f($high) <= 0.0) {
            return $high;
        }
        for ($i = 0; $i < 64; $i++) {
            $mid = 0.5 * ($low + $high);
            if ($f($mid) > 0.0) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        return 0.5 * ($low + $high);
    }

    /**
     * Where an industry clears when its roster sells no lower than a floor price: the fringe-adjusted level while that
     * stays at or above the floor; below it the price holds at the floor, buyers take Pf^(-e), the fringe supplies
     * (1 - rosterShare) * Pf^eta, and the roster sells the rest with its other plant idle. Load is the share of the
     * roster's plant that sells, one until the floor binds and continuous through it.
     *
     * @param float $capacityRatio Installed supply over trend demand at the trend price.
     * @param float $priceFloor    Lowest industry price the roster sells at; below it, it withholds output instead.
     * @return array{price: float, load: float}
     */
    public static function calculateIndustryClearing(float $capacityRatio, float $rosterShare, float $elasticity, float $fringeElasticity, float $priceFloor): array
    {
        $price = self::calculateFringeAdjustedPriceLevel($capacityRatio, $rosterShare, $elasticity, $fringeElasticity);
        $fringeShare = 1.0 - max(0.0, min(1.0, $rosterShare));
        $rosterPlant = $capacityRatio - $fringeShare;
        if ($price >= $priceFloor || $elasticity <= 0.0 || $rosterPlant <= 0.0) {
            return ['price' => $price, 'load' => 1.0];
        }

        $fringeSupply = $fringeShare * ($priceFloor ** max(0.0, $fringeElasticity));
        $rosterSales = ($priceFloor ** (-$elasticity)) - $fringeSupply;

        return ['price' => $priceFloor, 'load' => max(0.0, min(1.0, $rosterSales / $rosterPlant))];
    }

    /**
     * The elasticity of the demand a roster firm's output meets once the competitive fringe answers the price: buyers'
     * own e plus the fringe's supply response eta times its share of the market, since a price cut also pushes fringe
     * output out (from D(P) = Q + F(P)). A half-market fringe at unit elasticity lifts 1.25 to 1.75.
     */
    public static function fringeAdjustedDemandElasticity(float $elasticity, float $fringeElasticity, float $rosterShare): float
    {
        return $elasticity + (max(0.0, $fringeElasticity) * (1.0 - max(0.0, min(1.0, $rosterShare))));
    }

    /**
     * Marginal revenue over price for a quantity-setting firm that internalises its own price effect: the
     * Cournot Lerner condition MR/P = 1 - s/e, with s the firm's share of the market it sells into and e the
     * industry price elasticity of demand, scaled by how substitutable its output is (a firm whose output
     * is not substitutable does not move the industry price). Floored at zero: past that point another unit
     * of output earns nothing.
     */
    public static function calculateCournotMarginalRevenueFactor(float $share, float $elasticity, float $substitutability): float
    {
        if ($elasticity <= 0.0) {
            return 1.0;
        }

        return max(0.0, 1.0 - max(0.0, min(1.0, $share)) * max(0.0, $substitutability) / $elasticity);
    }

    /**
     * A price taker's variable cost ratio at the price it realized, given the ratio at the price its cost base
     * was sized for. The costs are paid per unit produced (lifting, haulage, processing), so the dollars per
     * unit do not move with the market price and the ratio moves inversely with it: revenue = P x Q while
     * variable cost = c x Q, so c x Q / (P x Q) = ratio at base price x P0 / P.
     *
     * @param float $costRatioAtBasePrice Variable cost as a share of revenue at the base price.
     * @param float $priceRelative        Realized price over the base price.
     */
    public static function calculatePerUnitCostRatio(float $costRatioAtBasePrice, float $priceRelative): float
    {
        return $costRatioAtBasePrice / max(0.01, $priceRelative);
    }

    /**
     * How much a loss of productivity raises the cost of each unit produced: output is TFP times the inputs, so the
     * inputs, and what they cost, per unit of output scale with 1 / TFP.
     *
     * @param float $tfpLoss Share of total factor productivity lost (0.048 is 4.8%).
     */
    public static function calculateProductivityLossCostFactor(float $tfpLoss): float
    {
        return 1.0 / max(0.01, 1.0 - $tfpLoss);
    }

    /**
     * How much the rules on extraction raise the cost of each unit a mine or field produces: the productivity the
     * strictest rules on record cost polluting plants (Greenstone, List & Syverson 2012), in proportion to how far the
     * rules stand toward them.
     *
     * @param float $extractionStringency How far the rules stand between the founding ones (0) and the strictest on
     *                                    record (1).
     */
    public static function calculateExtractionCostFactor(float $extractionStringency): float
    {
        return FirmEconomics::calculateProductivityLossCostFactor(FinancialConstants::ENVIRONMENTAL_REGULATION_TFP_LOSS * $extractionStringency);
    }

    /**
     * Decomposes a period's total revenue growth into each segment's contribution to it.
     *
     * Standard arithmetic revenue attribution, as used in segment reporting: a segment's
     * contribution is its own absolute change measured against the *prior total*, so the
     * contributions sum exactly to the total growth rate.
     *
     * Formula: c_i = (R_i,t - R_i,t-1) / SUM_j R_j,t-1,  and  SUM_i c_i = (R_t - R_t-1) / R_t-1
     *
     * This is what separates a segment's own growth rate from its importance: a segment holding
     * 5% of revenue that grows 40% contributes 2 percentage points, not 40.
     *
     * @param  array<string, float> $currentRevenues  Segment revenues this period
     * @param  array<string, float> $previousRevenues Segment revenues the prior period
     * @return array<string, float> Segment key => contribution to total growth (decimal fraction)
     */
    public static function calculateGrowthContributions(array $currentRevenues, array $previousRevenues): array
    {
        $previousTotal = array_sum($previousRevenues);
        if ($previousTotal <= 0.0) {
            return [];
        }

        $contributions = [];
        foreach (array_keys($currentRevenues + $previousRevenues) as $key) {
            $current = (float) ($currentRevenues[$key] ?? 0.0);
            $previous = (float) ($previousRevenues[$key] ?? 0.0);
            $contributions[$key] = ($current - $previous) / $previousTotal;
        }

        return $contributions;
    }

    /**
     * Herfindahl-Hirschman Index of a revenue mix — the standard concentration measure.
     *
     * Formula: HHI = SUM_i s_i^2, over shares expressed as decimal fractions, so the result runs
     * from 1/n (a perfectly even mix across n segments) to 1.0 (a single-segment business).
     * Applied to a revenue mix it reads as concentration risk: how much of the firm rests on one
     * line of business.
     *
     * @param  array<string, float> $shares Segment shares as decimal fractions
     * @return float Concentration index on [0, 1]
     */
    public static function calculateHerfindahlIndex(array $shares): float
    {
        $total = array_sum($shares);
        if ($total <= 0.0) {
            return 0.0;
        }

        $hhi = 0.0;
        foreach ($shares as $share) {
            $normalized = (float) $share / $total;
            $hhi += $normalized * $normalized;
        }

        return $hhi;
    }

    /**
     * Effective number of segments implied by a concentration index (the inverse-Simpson count).
     *
     * Formula: N_eff = 1 / HHI. A firm with shares 0.5/0.3/0.2 books three segments but behaves
     * like 2.6 of them; a 0.9/0.05/0.05 firm behaves like 1.2. This is the readable half of HHI.
     *
     * @param  float $herfindahlIndex Concentration index on (0, 1]
     * @return float Effective segment count, 0.0 for an empty mix
     */
    public static function calculateEffectiveSegmentCount(float $herfindahlIndex): float
    {
        return $herfindahlIndex > 0.0 ? 1.0 / $herfindahlIndex : 0.0;
    }

    /**
     * Metzler (1941) & Blinder (1982) inventory cycle: a demand miss builds stock involuntarily, and firms work the
     * stock back to the level they want at their adjustment speed; the overhang's change over the step is inventory
     * investment. Production run ahead of plan (Ramey & West 1999 cost shocks: output bunched into cheap quarters) goes
     * into stock the same way. Exact over the step for flows held through it, the target held too: where it follows
     * expected sales, the caller moves the overhang as it moves. The miss is a share of a year's output; over the
     * stock-to-sales ratio it is the overhang in that ratio.
     *
     * @param float $currentInventoryGap Current overhang in the inventory-to-sales ratio (+gap is excess stock, -gap is
     *                                   depleted).
     * @param float $outputGap           Current sales gap.
     * @param float $outputGapEma        Expected sales gap (its smoothed reading).
     * @param float $speed               Annual speed of inventory adjustment toward desired stock.
     * @param float $surpriseSens        Sensitivity of involuntary inventory build to growth slowdown.
     * @param float $dt                  Time step in years.
     * @param float $inventoryToSales    Inventories over a year's sales.
     * @param float $productionShock     Production over plan, a share of a year's sales a year.
     */
    public static function calculateInventoryCycleStep(
        float $currentInventoryGap,
        float $outputGap,
        float $outputGapEma,
        float $speed,
        float $surpriseSens,
        float $dt,
        float $inventoryToSales,
        float $productionShock = 0.0,
    ): float {
        $involuntaryFlow = (($surpriseSens * ($outputGapEma - $outputGap)) + $productionShock) / $inventoryToSales;
        $decay = exp(-$speed * $dt);

        return ($currentInventoryGap * $decay) + ($involuntaryFlow * (1.0 - $decay) / $speed);
    }

    /**
     * Calculates the Federal Reserve G.17 Industrial Capacity Utilization Rate.
     *
     * Models real physical factory and equipment load factor based on the macroeconomic output gap
     * and physical capital stock overhang:
     *   CU = baselineCu + gapSens * outputGap - overhangSens * capitalStockOverhang
     *
     * @param float $outputGap              Macroeconomic output gap.
     * @param float $capitalStockOverhang   Accumulated capital stock overhang.
     * @param float $baselineCu             Historical neutral capacity utilization (~78.5%).
     * @param float $gapSensitivity         Sensitivity to cyclical demand fluctuations.
     * @param float $overhangSensitivity    Sensitivity to excess installed capacity overhang.
     * @return float Realized capacity utilization rate clamped between 60% and 92%.
     */
    public static function calculateCapacityUtilization(
        float $outputGap,
        float $capitalStockOverhang,
        float $baselineCu = MacroConstants::CU_BASELINE,
        float $gapSensitivity = MacroConstants::CU_GAP_SENSITIVITY,
        float $overhangSensitivity = MacroConstants::CU_OVERHANG_SENSITIVITY,
    ): float {
        $rawCu = $baselineCu + ($gapSensitivity * $outputGap) - ($overhangSensitivity * $capitalStockOverhang);
        return max(0.60, min(0.92, $rawCu));
    }

    /**
     * One quarter of a rolling forward-sale ladder: each quarter the seller locks one tranche for each of the next n
     * quarters at that delivery date's forward, so the volume delivered now carries n tranches struck one to n quarters
     * ago. A ladder with no history opens as though it had always been struck on today's curve, so its first quarter
     * books no phantom hedge gain.
     *
     * @param list<float>          $strikeSums Opening sums of the strikes already locked for delivery 0..n-1 quarters
     *                                         ahead (zeros for a new ladder).
     * @param callable(int): float $forward    Forward price for delivery that many quarters ahead.
     * @return array{strike: float, sums: list<float>} The average strike delivered this quarter and the rolled sums.
     */
    public static function rollHedgeLadder(array $strikeSums, callable $forward): array
    {
        $tranches = count($strikeSums);
        if ($tranches === 0) {
            return ['strike' => $forward(0), 'sums' => []];
        }

        $slots = array_values($strikeSums);
        if ($slots[0] <= 0.0) {
            for ($d = 0; $d < $tranches; $d++) {
                $slots[$d] = ($tranches - $d) * $forward($d);
            }
        }

        $rolled = [];
        for ($d = 0; $d < $tranches; $d++) {
            $rolled[] = ($d + 1 < $tranches ? $slots[$d + 1] : 0.0) + $forward($d + 1);
        }

        return ['strike' => $slots[0] / $tranches, 'sums' => $rolled];
    }

    /**
     * Price of a merchant power book relative to the one its cost base was sized at: the wholesale price relative on
     * the share sold into the market, flat on contract work priced like the rest of the book.
     *
     * @param float $powerShare    Share of the book sold at the wholesale price.
     * @param float $powerRelative Wholesale price over its baseline.
     */
    public static function merchantPowerPriceRelative(float $powerShare, float $powerRelative): float
    {
        return (1.0 - $powerShare) + ($powerShare * $powerRelative);
    }

    /**
     * Gas a gas-fired MWh burns at baseline prices, as a share of what the MWh sells for: the fleet heat rate at the
     * reference gas price over the reference power price (~52%).
     */
    public static function gasFuelShareOfPowerPrice(): float
    {
        return MacroConstants::GAS_FLEET_HEAT_RATE * MacroConstants::REFERENCE_GAS_PRICE / MacroConstants::REFERENCE_POWER_PRICE;
    }

    /**
     * Change in the fuel and carbon bill per unit of gas-fired revenue at baseline prices: the fleet heat rate of gas
     * at the gas price relative to its baseline, plus the carbon price on the gas it burns.
     *
     * @param float $gasRelative Gas price paid over its baseline.
     * @param float $carbonPrice Dollars a tonne of CO2.
     */
    public static function gasFiredFuelCostChange(float $gasRelative, float $carbonPrice): float
    {
        $carbonShare = MacroConstants::GAS_FLEET_HEAT_RATE * MacroConstants::NATURAL_GAS_CO2_TONNES_PER_MMBTU * $carbonPrice / MacroConstants::REFERENCE_POWER_PRICE;

        return (self::gasFuelShareOfPowerPrice() * ($gasRelative - 1.0)) + $carbonShare;
    }

    /**
     * What the carbon price's power uplift adds to a merchant fleet's earnings per unit of it and of revenue sold at
     * the wholesale price: the uplift on every MWh, less the carbon the gas-fired share pays, which the uplift carries
     * at its pass-through. A zero-fuel fleet keeps it all; an all-gas fleet loses the fifth the market does not pass
     * on.
     *
     * @param float $gasFleetShare Share of the merchant output that burns gas.
     */
    public static function carbonUpliftEarningsPerUnit(float $gasFleetShare): float
    {
        return 1.0 - ($gasFleetShare / MacroConstants::CARBON_POWER_PASS_THROUGH);
    }

    /**
     * One step of the de-seasonalised 3:2:1 refining crack spread: a Schwartz (1997) log mean-reverting margin
     * around a target set by demand and physical inventory tightness. Margins are right-skewed and cannot go
     * negative across a sustained run, so the log is what reverts. The target is handed over through
     * schwartzThetaForMean(), so the stationary mean sits on it.
     *   Target = baseline * (1 + gapSens * outputGap) + inventory tightness add-on
     *
     * @param float $currentCrack          Current de-seasonalised crack spread in $/bbl.
     * @param float $outputGap             Current macroeconomic output gap.
     * @param float $energyInventoryIndex  Physical energy buffer inventory index.
     * @param float $dt                    Time increment in years.
     * @param float $dW                    Standard normal random shock.
     * @param float $baselineCrack         Neutral long-run crack spread (~$22/bbl).
     * @param float $kappa                 Speed of mean reversion of the log crack.
     * @param float $sigma                 Volatility of the log crack.
     * @return float Updated de-seasonalised crack spread in $/bbl clamped between $4.00 and $80.00.
     */
    public static function calculateRefiningCrackSpreadStep(
        float $currentCrack,
        float $outputGap,
        float $energyInventoryIndex,
        float $dt,
        float $dW,
        float $baselineCrack = 22.0,
        float $kappa = 1.41,
        float $sigma = 0.75,
    ): float {
        $demandFactor = 1.0 + (1.20 * $outputGap);
        $inventoryTightness = max(0.0, (100.0 - $energyInventoryIndex) / 100.0) * 15.0;
        $targetCrack = ($baselineCrack * max(0.30, $demandFactor)) + $inventoryTightness;
        $newCrack = StochasticProcesses::schwartzStepToMean(max(0.01, $currentCrack), $kappa, $targetCrack, $sigma, $dt, $dW);

        return max(4.0, min(80.0, $newCrack));
    }

    /**
     * The three separate day-count movements behind a cash conversion cycle shift.
     *
     * Kept as its own method because the components are not interchangeable once working capital is carried
     * as real balances: a receivable that ages is exposed to customer default, while inventory that piles up
     * is exposed to writedown. Only the aggregate is a single number; the risks attach to the parts.
     *
     * The declared days are the cycle's average, so receivables age against the credit spread's measured
     * through-the-cycle level and payables against the interbank spread's. Utilisation is centred on one already.
     *
     * @return array{dso: float, dio: float, dpo: float} Day-count shifts, signed as movements in each component.
     */
    public static function calculateWorkingCapitalDayShifts(
        float $creditSpread,
        float $creditSpreadTrend,
        float $capacityUtilization,
        float $interbankLiquiditySpread,
        float $interbankLiquiditySpreadTrend,
    ): array {
        return [
            // Customers stretch payment when credit is dearer than it usually is.
            'dso' => ($creditSpread - $creditSpreadTrend) * self::CCC_DSO_CREDIT_SPREAD_SENSITIVITY,
            // Unsold goods pile up when the plant runs below capacity.
            'dio' => (1.0 - $capacityUtilization) * self::CCC_DIO_CAPACITY_SENSITIVITY,
            // Vendors want paying sooner when their own funding is dearer than usual, and wait when it is cheaper.
            'dpo' => -($interbankLiquiditySpread - $interbankLiquiditySpreadTrend) * self::CCC_DPO_LIQUIDITY_SENSITIVITY,
        ];
    }

    /**
     * A firm-wide ratio to revenue built from each stream's own ratio, weighted by the stream's share of revenue.
     * The weights need not sum to one; with none positive there is no revenue to weight and the ratio is zero.
     *
     * @param list<float> $weights Each stream's revenue weight.
     * @param list<float> $ratios  Each stream's ratio to its own revenue, in the same order.
     */
    public static function revenueWeightedRatio(array $weights, array $ratios): float
    {
        $total = 0.0;
        $weighted = 0.0;
        foreach ($weights as $i => $weight) {
            $total += max(0.0, $weight);
            $weighted += max(0.0, $weight) * $ratios[$i];
        }

        return $total > 0.0 ? $weighted / $total : 0.0;
    }

    /**
     * Each stream's variable cost ratio from its cost intensity relative to the others, scaled so the revenue-weighted
     * blend is the firm's own ratio: the expected mix reproduces the engine's cost base, the streams keep their order,
     * and none goes negative however the firm's ratio moves.
     *
     * @template K of array-key
     *
     * @param array<K, float> $weights     Each stream's revenue weight.
     * @param array<K, float> $intensities Each stream's cost per unit of revenue relative to the others, same keys.
     * @return array<K, float>
     */
    public static function streamCostRatios(float $firmCostRatio, array $weights, array $intensities): array
    {
        $ordered = [];
        foreach (array_keys($weights) as $key) {
            $ordered[] = $intensities[$key];
        }
        $blend = self::revenueWeightedRatio(array_values($weights), $ordered);
        $ratios = [];
        foreach ($weights as $key => $weight) {
            $ratios[$key] = $blend > 0.0 ? $firmCostRatio * $intensities[$key] / $blend : $firmCostRatio;
        }

        return $ratios;
    }

    /**
     * The trade cycle of a firm made of streams, each balance blended by revenue weight. The balances stay apart
     * because their risks differ: a marketplace's sellers' float does not cancel the stock its own shop holds.
     *
     * @param list<float> $weights Each stream's revenue weight.
     * @param list<array{receivables: float, inventory: float, payables: float}> $streamShares Each stream's balances as shares of its own revenue.
     * @return array{receivables: float, inventory: float, payables: float}
     */
    public static function blendWorkingCapitalShares(array $weights, array $streamShares): array
    {
        $blend = [];
        foreach (['receivables', 'inventory', 'payables'] as $balance) {
            $blend[$balance] = self::revenueWeightedRatio($weights, array_map(static fn(array $shares): float => $shares[$balance], $streamShares));
        }

        return $blend;
    }

    /**
     * The ledger's day counts for balances given as shares of revenue. Receivables are billed sales, so their days
     * are the share of a year; inventory and payables are carried at cost, so their days are struck on the cost base
     * and the share is grossed up by revenue over cost.
     *
     * @param array{receivables: float, inventory: float, payables: float} $shares
     * @return array{dso: float, dio: float, dpo: float}
     */
    public static function workingCapitalDays(array $shares, float $costRatio): array
    {
        $costRatio = max(0.01, $costRatio);

        return [
            'dso' => $shares['receivables'] * FinancialConstants::DAYS_PER_YEAR,
            'dio' => $shares['inventory'] * FinancialConstants::DAYS_PER_YEAR / $costRatio,
            'dpo' => $shares['payables'] * FinancialConstants::DAYS_PER_YEAR / $costRatio,
        ];
    }

    /**
     * Income tax on a quarter's taxable income under ASC 740 with the TCJA loss rules: a profit is taxed after the
     * carryforward shields up to 80% of it, a loss adds to the carryforward, and the carryforward is carried as a
     * deferred tax asset at today's rate (so a rate change remeasures it) unless a valuation allowance is required.
     * The deferred charge is the asset's fall: the carryforward used, written down under an allowance, or a negative
     * charge (a benefit) as a loss builds it. A loss therefore prints at (1 - t) of itself, as it does for any firm
     * expected to earn it back, and a firm with a run of losses gets no benefit until it returns to profit.
     *
     * @return array{current: float, deferred: float, nol: float, dta: float}
     */
    public static function resolveIncomeTax(float $taxableIncome, float $nol, float $openingDta, bool $allowanceRequired, float $rate): array
    {
        $nol = max(0.0, $nol);
        if ($taxableIncome > 0.0) {
            $shielded = min($taxableIncome * self::NOL_MAX_SHIELD_RATIO, $nol);
            $current = ($taxableIncome - $shielded) * $rate;
            $nolAfter = $nol - $shielded;
        } else {
            $current = 0.0;
            $nolAfter = $nol - $taxableIncome;
        }

        $dta = $allowanceRequired ? 0.0 : $rate * $nolAfter;

        return ['current' => $current, 'deferred' => $openingDta - $dta, 'nol' => $nolAfter, 'dta' => $dta];
    }
}

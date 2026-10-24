<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;

/**
 * The output gap's step and its make-up: domestic demand on its weight in GDP beside the level parts that ride with it
 * (the productivity supply gap, net exports, the mainland's cycle, the banks' and deal-makers' output, inventory
 * investment and spending timing), the demand disturbance and its rare disasters, and the bounds on the whole.
 */
final class OutputGap
{
    // --- Financial Centre Output (GDP by industry; ESA 2010 §14.14: bank service volumes as deflated balances) ---
    /**
     * Finance and insurance value added over GDP: the District was founded as a financial centre, and its finance is
     * over 30% of it.
     */
    public const DISTRICT_FINANCE_SHARE = 0.30;
    /**
     * Credit intermediation's share of the District's finance (banks, credit services, mortgage finance), by the seeded
     * roster's book equity; its volume follows deflated loan balances.
     */
    public const FINANCE_CREDIT_SHARE = 0.322;
    /**
     * Deal-makers' share of the District's finance (investment banks and private equity, by the same book equity):
     * their volume is the world's deals they advise on, underwrite and buy.
     */
    public const FINANCE_DEAL_SHARE = 0.098;
    /**
     * Weight of the finance cycle in the gap, beyond the finance the demand equation already moves with the rest of the
     * economy (0.246 of output).
     */
    public const FINANCE_GAP_WEIGHT = 0.2457;
    /** Imports for use at home over GDP that the demand equation's own slopes already leak abroad (14.8%). */
    public const DEMAND_EQUATION_IMPORT_SHARE = 0.1477;
    /**
     * Domestic demand the District's imports carry abroad beyond that leak: imports move 1.4 times domestic demand (IMF
     * WEO 2015) on the District's imports for home use above it.
     */
    public const EXCESS_IMPORT_LEAKAGE = OpenEconomy::IMPORT_DEMAND_ELASTICITY * (OpenEconomy::DISTRICT_DOMESTIC_IMPORT_SHARE - self::DEMAND_EQUATION_IMPORT_SHARE);
    /**
     * Weight of domestic demand in the gap: everything but the bank credit and deal cycles, net of the excess imports
     * it draws in (0.573). Brokers, exchanges, insurance and asset management are in it: their output is not the value
     * of what they trade or hold.
     */
    public const DOMESTIC_GAP_WEIGHT = (1.0 - (self::FINANCE_GAP_WEIGHT * (self::FINANCE_CREDIT_SHARE + self::FINANCE_DEAL_SHARE))) * (1.0 - self::EXCESS_IMPORT_LEAKAGE);

    // --- Aggregate Demand Disturbance (Smets-Wouters 2007) ---
    /**
     * Mean reversion speed of the aggregate demand disturbance: -4*ln(0.86) per year, from the estimated quarterly
     * AR(1) coefficient.
     */
    public const DEMAND_SHOCK_REVERSION = 0.60;
    /**
     * Innovation volatility of the district's own aggregate demand disturbance, in annualized gap-drift units: 0.78pp.
     * The mainland's cycle reaches output beside it (MAINLAND_CYCLE_LOADING), so this is what the district's own news,
     * credit and confidence add.
     */
    public const DEMAND_SHOCK_SIGMA = 0.0078;
    /**
     * Share of the mainland's gap the district's output carries in the same quarter, one regional factor (Kose, Otrok &
     * Whiteman 2003): the same news, credit and confidence reach both, so a mainland slump is a district one at half
     * its depth, beside what it takes off exports.
     */
    public const MAINLAND_CYCLE_LOADING = 0.5;

    // --- Rare Demand Disasters (Barro 2006, Gourio 2012; Kou 2002 jump) ---
    /**
     * Disaster arrivals per year in either direction: the one-sided cause a slump needs, since the Gaussian innovation
     * above is symmetric. One in 25 years: most busts reach the district from the mainland's cycle and its
     * crashes, so the local ones are rare.
     */
    public const DEMAND_DISASTER_INTENSITY = 0.04;
    /**
     * Probability a disaster is an upside demand surprise. Barro's disaster set is one-sided; a quarter weight keeps
     * booms possible while leaving the left tail three times heavier.
     */
    public const DEMAND_DISASTER_UP_PROBABILITY = 0.25;
    /**
     * Exponential rate of the upside jump: mean 3.5pp/yr of demand, three quarters of the downside, so expansions build
     * gradually and slumps arrive whole; sized with DEMAND_SHOCK_SIGMA.
     */
    public const DEMAND_DISASTER_UP_RATE = 28.6;
    /**
     * Exponential rate of the downside jump: mean 4.7pp/yr of demand, a 10pp/yr shock once per ~57y; sized with
     * DEMAND_SHOCK_SIGMA.
     */
    public const DEMAND_DISASTER_DOWN_RATE = 21.4;
    /**
     * Cap on one disaster (17.5pp/yr of demand, about four mean down-jumps). Guards the tail of the exponential draw
     * without binding on the calibrated range.
     */
    public const DEMAND_DISASTER_CAP = 0.175;

    /**
     * Stochastic micro-diffusion volatility of final sales, which integrates into the gap; the quarter-to-quarter noise
     * in GDP comes from production over plan (InventoryAndTiming::INVENTORY_COST_SHOCK_SD).
     */
    public const OUTPUT_GAP_DIFFUSION_SIGMA = 0.0025;

    // --- Cyclical Output Gap Bounds ---
    /**
     * Deepest slump the cycle may reach; below the worst postwar CBO gap (-8.8%, 2020Q2; 2009Q2 is -5.0% on the current
     * vintage), so the bound guards runaway feedback rather than shaping the distribution.
     */
    public const OUTPUT_GAP_FLOOR = -0.12;
    /**
     * Hottest the economy may run: above the postwar CBO peak (+5.6%, 1966Q1), since the one-sided cubic is what holds
     * the upside.
     */
    public const OUTPUT_GAP_CEILING = 0.10;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /** Records what moved the gap. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?OutputGapProbe $gapProbe = null,
        /** Records the demand disasters drawn; null and off on the same terms. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null,
    ) {}

    /**
     * Kaldor (1940) Non-Linear Business Cycle with Modigliani Wealth Effect & Marshall-Lerner FX Drag.
     *
     * Solves continuous domestic final sales dynamics, weighted by their share of GDP, to which the productivity supply
     * part, net exports, the mainland's cycle, the banks' credit-driven output and inventory investment are added as
     * levels:
     *   dy = [Momentum - CapacityCeiling(y>0) - RealRateDrag - CreditPremium + FiscalStimulus + AutomaticStabilisers - CapitalOverhang + WealthEffect + NewBorrowing - DebtService - CrisisDeleveraging + DemandShock] * dt
     *
     * @param MacroState $state             Current macroeconomic state.
     * @param float      $yield5y           5-Year Treasury yield benchmark for business borrowing.
     * @param float      $naturalRate       Dynamic natural real rate of interest (r*).
     * @param float      $expectedInflation Short-horizon expected inflation, the Taylor rule's own measure
     *                                      (MonetaryPolicySubsystem::calculateExpectedInflation).
     * @param float      $dt                Time increment in years.
     * @param float      $stressMultiplier  Non-linear crisis volatility multiplier.
     * @return float Updated cyclical output gap bounded between -12% and +10%.
     */
    public function calculateOutputGap(MacroState $state, float $yield5y, float $naturalRate, float $expectedInflation, float $dt, float $stressMultiplier): float
    {
        // The gap is GDP by industry: domestic demand on its share of the economy, plus, as levels each on its own
        // measured path, the supply part a productivity shock opens (ProductivityGrowth::absorbProductivityShocks()),
        // net exports, the banks' credit-driven output and inventory investment. The demand equation below runs on
        // domestic final sales only: momentum, stabilisers and drags answer spending.
        $openingGap = $state->outputGap;
        $y = self::domesticDemandGap($state);
        $supplyGap = $state->tfpOutputStage2 - $state->tfpPotentialAbsorbed;
        $supplyChange = $supplyGap - $state->productivitySupplyGap;
        $state->productivitySupplyGap = $supplyGap;
        $netExportGap = OpenEconomy::updateNetExportGap($state, $dt);
        $tradeChange = $netExportGap - $state->netExportGap;
        $state->netExportGap = $netExportGap;
        $financeGap = self::financeOutputGapAt(
            self::creditBalanceGap($state->creditToGdpGap, $state->creditToGdpTrend),
            self::dealActivityGap($state->dealActivityIndexEma, $state->dealActivityIndexTrend),
        );
        $financeChange = $financeGap - $state->financeOutputGap;
        $state->financeOutputGap = $financeGap;
        // Kose, Otrok & Whiteman (2003) regional factor: the mainland's cycle is a level part of output, as net exports
        // are, so a mainland quarter reaches the gap in that quarter and leaves it as the mainland recovers.
        $mainlandCycleGap = self::mainlandCycleGapAt($state->foreignOutputGap);
        $mainlandCycleChange = $mainlandCycleGap - $state->mainlandCycleGap;
        $state->mainlandCycleGap = $mainlandCycleGap;
        // Smets & Wouters (2007) estimate the demand disturbance and the measurement-frequency
        // component as separate innovations; one draw serving both correlates them at unity.
        $demandZ = $this->mathUtility->generateStandardNormal();
        $outZ = $this->mathUtility->generateStandardNormal();
        $inventoryZ = $this->mathUtility->generateStandardNormal();
        $timingZ = $this->mathUtility->generateStandardNormal();

        $domesticDemand = DomesticDemand::driftChannels($state, $y, $yield5y, $naturalRate, $expectedInflation, $dt);
        $inventoryChange = InventoryAndTiming::stepInventoryInvestment($state, $y, $inventoryZ, $dt);
        $timingChange = InventoryAndTiming::stepSpendingTiming($state, $timingZ, $dt);

        // Smets & Wouters (2007) persistent AR(1) aggregate demand disturbance, the district's own: the mainland's
        // cycle reaches output as a level part instead.
        $state->demandShock += (-self::DEMAND_SHOCK_REVERSION * $state->demandShock * $dt)
            + (self::DEMAND_SHOCK_SIGMA * $stressMultiplier * sqrt($dt) * $demandZ);

        // Barro (2006) rare demand disaster: the one-sided cause a slump needs, since the Gaussian
        // innovation above is symmetric and too small to dig one. Compensated, so it bends the shape
        // without shifting the mean.
        $disasterIncrement = $this->mathUtility->calculateCompensatedKouJump(
            lambda: self::DEMAND_DISASTER_INTENSITY,
            pUp: self::DEMAND_DISASTER_UP_PROBABILITY,
            etaUp: self::DEMAND_DISASTER_UP_RATE,
            etaDown: self::DEMAND_DISASTER_DOWN_RATE,
            cap: self::DEMAND_DISASTER_CAP,
            dt: $dt,
        );
        $state->demandShock += $disasterIncrement;

        // The disasters' share of that level and their compensator's, each reverting as the whole does: the level is
        // linear, so noise, disasters and compensation are three processes summing to it exactly, and each can be
        // attributed without changing it.
        $disasterCompensator = StochasticProcesses::calculateKouCompensator(
            lambda: self::DEMAND_DISASTER_INTENSITY,
            pUp: self::DEMAND_DISASTER_UP_PROBABILITY,
            etaUp: self::DEMAND_DISASTER_UP_RATE,
            etaDown: self::DEMAND_DISASTER_DOWN_RATE,
            cap: self::DEMAND_DISASTER_CAP,
            dt: $dt,
        );
        $disasterJump = $disasterIncrement + $disasterCompensator;
        $state->demandDisasterShock += (-self::DEMAND_SHOCK_REVERSION * $state->demandDisasterShock * $dt) + $disasterJump;
        $state->demandDisasterCompensation += (-self::DEMAND_SHOCK_REVERSION * $state->demandDisasterCompensation * $dt) + $disasterCompensator;
        if ($disasterJump !== 0.0) {
            $this->diagnostics?->recordEvent('demandDisaster', $disasterJump);
        }

        // Domestic demand's channels and shocks reach GDP on its share; the level parts arrive as their change. This
        // array IS the drift: it is summed below and handed to the probe unchanged, so a channel cannot reach the
        // economy and miss the decomposition that explains it.
        $domesticDemand += [
            'demandShock' => $state->demandShock - $state->demandDisasterShock + $state->demandDisasterCompensation,
            'demandDisaster' => $state->demandDisasterShock,
            'disasterCompensator' => -$state->demandDisasterCompensation,
        ];
        $contributions = array_map(static fn(float $rate): float => self::DOMESTIC_GAP_WEIGHT * $rate, $domesticDemand) + [
            // Basu, Fernald & Kimball (2006): output catches up with a technology gain before potential does.
            'productivitySupply' => $supplyChange / $dt,
            // Obstfeld & Rogoff (1996): the mainland's demand and the real exchange rate move what the district sells
            // abroad.
            'netExports' => $tradeChange / $dt,
            // Kose, Otrok & Whiteman (2003): the mainland's cycle, the regional factor in output.
            'mainlandCycle' => $mainlandCycleChange / $dt,
            // Blanchard & Summers (1986): the same output over a potential the crisis scar has lowered is less slack.
            'crisisScar' => -$state->crisisScarStep / $dt,
            // Blanchard & Katz (1992): newcomers drawn by jobs add to potential before they add to output.
            'cyclicalMigration' => -$state->cyclicalMigrationRate,
            // ESA 2010 deflated balances and deal fees: the banks produce as their loans do, the deal-makers as the
            // world's deals do.
            'financeOutput' => $financeChange / $dt,
            // Blinder & Maccini (1991): output is final sales plus the change in inventories.
            'inventoryInvestment' => $inventoryChange / $dt,
            // Purchases moved between quarters, paid back by the quarters they were taken from.
            'spendingTiming' => $timingChange / $dt,
        ];

        $drift = array_sum($contributions) * $dt;
        $diffusion = self::DOMESTIC_GAP_WEIGHT * self::OUTPUT_GAP_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $outZ;
        $preClampGap = $openingGap + $drift + $diffusion;
        $newGap = max(self::OUTPUT_GAP_FLOOR, min(self::OUTPUT_GAP_CEILING, $preClampGap));

        $this->gapProbe?->record(
            terms: $contributions,
            diffusion: $diffusion,
            openingGap: $openingGap,
            preClampGap: $preClampGap,
            closingGap: $newGap,
            dt: $dt,
        );

        return $newGap;
    }

    /**
     * The domestic-demand part of the gap, at domestic demand's own scale: the gap less the level parts that ride
     * beside the demand equation (supply, net exports, the banks' credit cycle, inventory investment), over domestic
     * demand's weight.
     *
     * @param MacroState $state Current macroeconomic state.
     * @return float Domestic demand gap (fraction).
     */
    public static function domesticDemandGap(MacroState $state): float
    {
        return self::domesticDemandGapAt($state->outputGap, $state->productivitySupplyGap, $state->netExportGap, $state->financeOutputGap, $state->inventoryInvestmentGap, $state->spendingTimingGap, $state->mainlandCycleGap);
    }

    /**
     * The mainland's cycle as a level part of the district's gap, at MAINLAND_CYCLE_LOADING.
     *
     * @param float $mainlandGap The mainland's output gap (fraction).
     */
    public static function mainlandCycleGapAt(float $mainlandGap): float
    {
        return self::MAINLAND_CYCLE_LOADING * $mainlandGap;
    }

    /**
     * The domestic-demand part of a gap made of the given level parts, at domestic demand's own scale: the demand the
     * demand equation runs on, once inventory investment and the quarters' spending timing are out of it.
     *
     * @param float $outputGap              The whole output gap (fraction).
     * @param float $supplyGap              The productivity supply gap riding beside demand.
     * @param float $netExportGap           Net exports away from the structural balance (share of GDP).
     * @param float $financeOutputGap       The banks' credit-driven output (share of GDP).
     * @param float $inventoryInvestmentGap Inventory investment, a share of GDP a year.
     * @param float $spendingTimingGap      Purchases moved into the quarter from others, a share of GDP a year.
     * @param float $mainlandCycleGap       The mainland's cycle riding beside demand (MAINLAND_CYCLE_LOADING).
     * @return float Domestic demand gap (fraction).
     */
    public static function domesticDemandGapAt(float $outputGap, float $supplyGap, float $netExportGap, float $financeOutputGap, float $inventoryInvestmentGap, float $spendingTimingGap, float $mainlandCycleGap): float
    {
        return ($outputGap - $supplyGap - $netExportGap - $financeOutputGap - $inventoryInvestmentGap - $spendingTimingGap - $mainlandCycleGap) / self::DOMESTIC_GAP_WEIGHT;
    }

    /**
     * The part of the District's finance output with a volume cycle of its own, as a share of GDP: the banks, whose
     * service volumes follow loan balances deflated by a general price index (ESA 2010 §14.14), and the deal-makers,
     * whose fees follow the world's deal flow, each against its trend. Asset management is not here: its volume is not
     * the value of what it manages, so it moves with the domestic economy (DOMESTIC_GAP_WEIGHT).
     *
     * @param float $creditBalanceGap Log credit balances against their trend.
     * @param float $dealActivityGap  Log deal flow against its trend.
     * @return float Finance output gap (fraction of GDP).
     */
    public static function financeOutputGapAt(float $creditBalanceGap, float $dealActivityGap = 0.0): float
    {
        return self::FINANCE_GAP_WEIGHT * ((self::FINANCE_CREDIT_SHARE * $creditBalanceGap) + (self::FINANCE_DEAL_SHARE * $dealActivityGap));
    }

    /**
     * Deal flow against its decade trend, in logs: the volume the District's deal-makers sell.
     *
     * @param float $dealActivityIndexEma   The deal index, smoothed over a quarter.
     * @param float $dealActivityIndexTrend Its decade trend.
     */
    public static function dealActivityGap(float $dealActivityIndexEma, float $dealActivityIndexTrend): float
    {
        return ($dealActivityIndexEma > 0.0 && $dealActivityIndexTrend > 0.0) ? log($dealActivityIndexEma / $dealActivityIndexTrend) : 0.0;
    }

    /**
     * Credit balances against their Basel trend, in logs: the credit-to-GDP ratio over its one-sided HP trend.
     *
     * @param float $creditToGdpGap   Credit-to-GDP less its trend.
     * @param float $creditToGdpTrend The trend.
     * @return float Log credit balance gap.
     */
    public static function creditBalanceGap(float $creditToGdpGap, float $creditToGdpTrend): float
    {
        return $creditToGdpTrend > 0.0 ? log(max(0.01, 1.0 + ($creditToGdpGap / $creditToGdpTrend))) : 0.0;
    }
}

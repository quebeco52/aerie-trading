<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;

/**
 * Consumer prices: the hybrid Phillips curve's three baskets (services, core goods, energy and food) with their cost
 * pushes and import prices, services repricing on their own schedules, the GDP deflator's home-made part, core
 * inflation, and the ten-year breakeven the market prices off them.
 */
final class ConsumerPrices
{
    // --- Ten-Year Breakeven Inflation (Gurkaynak, Sack & Wright 2010; Kozicki & Tinsley 2001 endpoint) ---
    /**
     * Ten-year breakeven per point of learned long-run inflation over the anchor: US T10YIE 2003-2026 monthly on
     * headline CPI learned at a 0.10/yr constant gain, 0.441 (Newey-West se 0.076), holding core over that endpoint,
     * headline over core and the GZ excess bond premium; R2 0.62 to 0.75.
     */
    public const BREAKEVEN_ENDPOINT_LOADING = 0.441;
    /**
     * Ten-year breakeven per point of core inflation over the learned endpoint: the same regression, 0.085 (se 0.018).
     */
    public const BREAKEVEN_CORE_LOADING = 0.085;
    /**
     * Ten-year breakeven per point of headline over core, the energy and food pass-through: the same regression, 0.101
     * (se 0.016).
     */
    public const BREAKEVEN_NONCORE_LOADING = 0.101;
    /**
     * Quoted ten-year breakeven per point of the GZ excess bond premium, the TIPS liquidity squeeze of 2008 and March
     * 2020: the same regression, -0.356 (se 0.034).
     */
    public const BREAKEVEN_EBP_LOADING = -0.356;

    // --- Distributed Lag Transmission Constants ---
    /**
     * CPI level per unit farm-price deviation (~13% food CPI weight at ~15% pass-through), symmetric in both
     * directions.
     */
    public const AGRI_COST_PUSH_TRANSMISSION = 0.020;
    /** Characteristic half-life time constant in years for energy cost-push pass-through into core inflation. */
    public const ENERGY_COST_PUSH_LAG_YEARS = 0.50;
    /** Characteristic half-life in years for food cost-push pass-through into core inflation. */
    public const AGRI_COST_PUSH_LAG_YEARS = 0.75;

    // --- Carbon in the Electricity Bill (BLS CPI relative importance; EIA Electric Power Monthly) ---
    /**
     * Household electricity's weight in the consumer price index: 2.201% (CPI-U relative importance, December 2024).
     */
    public const CPI_ELECTRICITY_WEIGHT = 0.02201;
    /** What households pay for electricity, in $/MWh: 16.48 cents a kWh, the US residential average in 2024 (EIA). */
    public const RESIDENTIAL_ELECTRICITY_PRICE = 164.8;

    // --- New Keynesian Phillips Curve Dynamics ---
    /**
     * Output gap at which supply bottlenecks bind (Benigno & Eggertsson 2023, the v/u = 1 kink), inside the ±4% the gap
     * lives in so the convexity bites across the cycle.
     */
    public const PHILLIPS_MAX_CAPACITY = 0.035;
    /**
     * Convex Phillips slope scale; kappa / PHILLIPS_MAX_CAPACITY is the near-zero slope (~0.11 of inflation per unit
     * gap).
     */
    public const PHILLIPS_CONVEX_KAPPA = 0.0038;
    /** Downward nominal rigidity factor dampening deflationary pressure during recessions (Bewley 1999). */
    public const PHILLIPS_DOWNWARD_RIGIDITY_FACTOR = 0.50;
    /** Weight of supercore services inflation in headline PCE/CPI basket (Shapiro 2022). */
    public const INFLATION_WEIGHT_SUPERCORE = 0.55;
    /** Weight of core goods inflation in headline basket. */
    public const INFLATION_WEIGHT_GOODS = 0.25;
    /** Weight of energy and agricultural food commodities in headline basket. */
    public const INFLATION_WEIGHT_COMMODITY = 0.20;
    /** Adaptive unanchoring weight of inflation expectations to sustained trend deviations. */
    public const INFLATION_ADAPTIVE_EXPECTATIONS_WEIGHT = 0.25;
    /** Speed of inflation expectations mean-reverting toward central bank target (anchored expectations). */
    public const INFLATION_MEAN_REVERSION = 0.75;

    // --- Sectoral Inflation Sensitivities (Shapiro 2022) ---
    /** Wage-push transmission factor passing excess wage growth into supercore services inflation. */
    public const SUPERCORE_WAGE_TRANSMISSION = 0.25;
    /** Demand sensitivity factor scaling aggregate output gap pressure into core goods prices. */
    public const CORE_GOODS_DEMAND_SENSITIVITY = 0.80;

    // --- Services Repricing on Their Own Schedules (Ball & Mankiw 1995 relative-price shocks) ---
    /** Mean reversion (a year) of services repricing on its own: an insurance, rent or fee reset runs about a year. */
    public const SERVICES_PRICE_SHOCK_REVERSION = 1.0;
    /**
     * Stationary sd of the inflation services add by repricing outside the wage and demand pressure their sticky rate
     * follows: 0.5pp, so the yearly services rate swings about 0.5pp, the low end of rich economies.
     */
    public const SERVICES_PRICE_SHOCK_SD = 0.005;

    // --- Supply-Chain Costs in Core Goods (Carriere-Swallow, Deb, Furceri, Jimenez & Ostry 2023) ---
    /**
     * Headline price level a year after container freight rates double: ~0.7pp, peaking at twelve months (IMF blog,
     * March 2022, on the paper's local projections).
     */
    public const FREIGHT_DOUBLING_HEADLINE_EFFECT = 0.007;
    /**
     * Log core goods price level per log unit of freight (4.0%): the freight effect carried wholly by the goods basket.
     */
    public const CORE_GOODS_FREIGHT_LEVEL_LOADING = self::FREIGHT_DOUBLING_HEADLINE_EFFECT / self::INFLATION_WEIGHT_GOODS / M_LN2;

    // --- Import Prices in the Basket ---
    /** Time constant (years) of import-price pass-through: 0.580 one year out against 0.605 long-term, 96%. */
    public const IMPORT_PRICE_ADJUSTMENT_YEARS = 0.31;
    /**
     * Share of an imported consumer good's retail price that is local distribution, which the exchange rate does not
     * move (Burstein, Neves & Rebelo 2003: ~40% in the US).
     */
    public const IMPORT_DISTRIBUTION_SHARE = 0.40;
    /**
     * Share of the goods, fuel and food households buy that is imported: half in Switzerland, and almost none of the
     * services (Auer, SNB Economic Note 2026-06); imported goods weigh ~22% of the Swiss CPI (ING, second-hand). The
     * rest the District makes itself.
     */
    public const IMPORTED_SHARE_OF_GOODS = 0.50;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /** Records what set inflation. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null,
    ) {}

    /**
     * Insurance, rents, medical and regulated fees reprice on their own schedules (Ball & Mankiw 1995 relative-price
     * shocks): an OU rate the measured services rate carries over its sticky part, which follows wages and demand in
     * calculateInflation().
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateServicesRepricing(MacroState $state, float $dt): void
    {
        $prior = $state->servicesPriceShock;
        $state->servicesPriceShock = StochasticProcesses::calculateOrnsteinUhlenbeckStep($prior, self::SERVICES_PRICE_SHOCK_REVERSION, self::SERVICES_PRICE_SHOCK_SD, $dt, $this->mathUtility->generateStandardNormal());
        $state->supercoreInflation += $state->servicesPriceShock - $prior;
    }

    /**
     * Hybrid New Keynesian Phillips Curve with Benigno & Eggertsson (2023) Convexity
     * and Shapiro (2022) Sectoral Disaggregation (Supercore Services vs Core Goods vs Commodity).
     *
     * Models non-linear capacity-constrained inflation where output gaps approaching capacity accelerate inflation
     * non-linearly, while downward nominal wage/price rigidity flattens the curve during recessions. Decomposes
     * inflation into wage-push supercore services (with services repricing on their own schedules), freight/materials
     * core goods, and energy/food pass-through.
     *
     * @param MacroState $state                  Current macroeconomic state.
     * @param float      $targetInflation        Central bank inflation target.
     * @param float      $productivityGrowthRate Productivity growth potential output is built on
     *                                           (ProductivityGrowth::absorbProductivityShocks()), the growth wage bargains
     *                                           index.
     * @param float      $dt                     Time increment in years.
     * @return float Updated headline inflation rate.
     */
    public function calculateInflation(MacroState $state, float $targetInflation, float $productivityGrowthRate, float $dt): float
    {
        // Mankiw, Reis & Wolfers (2004) adaptive inflation expectations unanchoring.
        $anchorSlip = ($state->inflationEma - $targetInflation) * self::INFLATION_ADAPTIVE_EXPECTATIONS_WEIGHT;

        // Benigno & Eggertsson (2023) non-linear convex demand-pull Phillips curve, on the demand part of the gap:
        // output a technology gain lifts is not excess demand, and the gain is disinflationary (Gali 1999; Basu,
        // Fernald & Kimball 2006).
        $convexDemandPressure = MacroTransmission::calculateConvexPhillipsCurve(
            outputGap: $state->outputGap - $state->productivitySupplyGap,
            maxCapacity: self::PHILLIPS_MAX_CAPACITY,
            kappa: self::PHILLIPS_CONVEX_KAPPA,
            downwardRigidityFactor: self::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR,
        );

        // Shapiro (2022) Sector 1: supercore services, pushed by unit labour cost (wage growth less the productivity
        // growth it buys) over target, the same ULC the PPI reads.
        $wageGap = $state->wageGrowth - $productivityGrowthRate - $targetInflation;
        $wageCostPush = $wageGap * self::SUPERCORE_WAGE_TRANSMISSION;
        $targetSupercore = $targetInflation + $anchorSlip + $convexDemandPressure + $wageCostPush;

        // Import prices (IMF WEO October 2015, Ch. 3): the real exchange rate passes into import prices relative to
        // domestic ones within about a year. Half the goods, fuel and food in the basket are imported (Auer 2026), and
        // of those all of the retail price but the local distribution margin reprices; the half the District makes does
        // not.
        $priorImportPriceLevel = $state->importPriceLevel;
        $state->importPriceLevel = TimeSeries::calculateDistributedLag($priorImportPriceLevel, OpenEconomy::importPriceLevelTarget($state->exchangeRateIndexEma, $state->importTariffRate), $dt, self::IMPORT_PRICE_ADJUSTMENT_YEARS);
        $importPriceInflation = self::IMPORTED_SHARE_OF_GOODS * (1.0 - self::IMPORT_DISTRIBUTION_SHARE) * ($state->importPriceLevel - $priorImportPriceLevel) / $dt;

        // Shapiro (2022) Sector 2: core goods. Freight sets a cost LEVEL of the landed goods (Carriere-Swallow et al.
        // 2023); inflation is its rate of change, as its quarter EMA moves this tick, so freight that stays high adds a
        // step to the level and then no more inflation.
        $freightEmaNext = TimeSeries::calculateDistributedLag($state->freightRateIndexEma, $state->freightRateIndex, $dt, MovingAverages::STANDARD_EMA_HORIZON_YEARS);
        $freightStep = ($state->freightRateIndexEma > 0.0 && $freightEmaNext > 0.0) ? log($freightEmaNext / $state->freightRateIndexEma) : 0.0;
        $goodsSupplyFriction = self::CORE_GOODS_FREIGHT_LEVEL_LOADING * $freightStep / $dt;
        $targetCoreGoods = $targetInflation + $anchorSlip + (self::CORE_GOODS_DEMAND_SENSITIVITY * $convexDemandPressure) + $goodsSupplyFriction + $importPriceInflation;

        // Calvo (1983) sticky price dynamics via continuous AR(1) state adjustment.
        $reversionWeight = 1.0 - exp(-self::INFLATION_MEAN_REVERSION * $dt);
        // The services repricing (calculateServicesRepricing()) rides on the measured rate outside its sticky part.
        $stickySupercore = $state->supercoreInflation - $state->servicesPriceShock;
        $stickySupercore += $reversionWeight * ($targetSupercore - $stickySupercore);
        $state->supercoreInflation = max(-0.01, min(0.20, $stickySupercore + $state->servicesPriceShock));

        $state->coreGoodsInflation += $reversionWeight * ($targetCoreGoods - $state->coreGoodsInflation);
        $state->coreGoodsInflation = max(-0.02, min(0.20, $state->coreGoodsInflation));
        // The import-price part of that sticky path, so the goods the District makes can be priced without it.
        $state->coreGoodsImportInflation += $reversionWeight * ($importPriceInflation - $state->coreGoodsImportInflation);

        // Shapiro (2022) Sector 3: the energy and food lags are the share of the CPI LEVEL their commodity has reached
        // (sector models read them as cost levels); headline takes their rate of change, so a price that stays high
        // adds a step to the level and then no more inflation.
        $priorEnergyCostPushLag = $state->energyCostPushLag;
        $priorAgriCostPushLag = $state->agriCostPushLag;
        $rawEnergyCostPush = ($state->energyPriceShock / MacroConstants::ENERGY_BASELINE) * MacroConstants::ENERGY_COST_PUSH_TRANSMISSION;
        $state->energyCostPushLag = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->energyCostPushLag,
            targetValue: $rawEnergyCostPush,
            dt: $dt,
            lagTimeConstant: self::ENERGY_COST_PUSH_LAG_YEARS,
        );

        // Gelos & Ustyugova (2017) distributed lag agricultural pass-through to food CPI.
        $rawAgriCostPush = (($state->agriculturalCommodityIndex / MacroConstants::AGRI_BASELINE) - 1.0) * self::AGRI_COST_PUSH_TRANSMISSION;
        $state->agriCostPushLag = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->agriCostPushLag,
            targetValue: $rawAgriCostPush,
            dt: $dt,
            lagTimeConstant: self::AGRI_COST_PUSH_LAG_YEARS,
        );

        // A carbon price reaches the electricity bill as the wholesale price it adds, at the energy lag: a step in the
        // price level, its electricity weight's worth, not a lasting rate.
        $priorCarbonLevel = $state->electricityCarbonPriceLevel;
        $state->electricityCarbonPriceLevel = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $priorCarbonLevel,
            targetValue: log(1.0 + (CommodityLogisticsSubsystem::carbonPowerPriceAdder($state->carbonPrice) / self::RESIDENTIAL_ELECTRICITY_PRICE)),
            dt: $dt,
            lagTimeConstant: self::ENERGY_COST_PUSH_LAG_YEARS,
        );
        $carbonCostPush = self::CPI_ELECTRICITY_WEIGHT * ($state->electricityCarbonPriceLevel - $priorCarbonLevel) / $dt;
        $energyCostPush = ($state->energyCostPushLag - $priorEnergyCostPushLag) / $dt;
        $agriCostPush = ($state->agriCostPushLag - $priorAgriCostPushLag) / $dt;
        $noncorePassThrough = $energyCostPush + $agriCostPush + $carbonCostPush;
        $state->noncorePassThroughEma = TimeSeries::calculateDistributedLag($state->noncorePassThroughEma, $noncorePassThrough, $dt, MovingAverages::STANDARD_EMA_HORIZON_YEARS);

        // Shapiro (2022) commodity basket aggregation normalized by expenditure weight.
        $commodityBasketInflation = $targetInflation + $anchorSlip
            + ($noncorePassThrough / self::INFLATION_WEIGHT_COMMODITY)
            + $importPriceInflation;

        // Shapiro (2022) expenditure-weighted headline consumer price aggregation.
        $blendedInflation = (self::INFLATION_WEIGHT_SUPERCORE * $state->supercoreInflation)
            + (self::INFLATION_WEIGHT_GOODS * $state->coreGoodsInflation)
            + (self::INFLATION_WEIGHT_COMMODITY * $commodityBasketInflation);

        $boundedInflation = max(-0.02, min(0.25, $blendedInflation));

        // The GDP deflator prices what the District produces (SNA 2008, 15.180): its services, the local distribution
        // margin on imported goods, priced like services (Burstein, Neves & Rebelo 2003), and the goods, fuel and food
        // it makes itself, at their own prices less the import-price term. The carbon price is a domestic tax and
        // stays.
        $homeMade = 1.0 - self::IMPORTED_SHARE_OF_GOODS;
        $homeFoodAndFuel = $targetInflation + $anchorSlip + (($energyCostPush + $agriCostPush) / self::INFLATION_WEIGHT_COMMODITY);
        $state->domesticInflation = (
            ((self::INFLATION_WEIGHT_SUPERCORE + (self::IMPORT_DISTRIBUTION_SHARE * self::IMPORTED_SHARE_OF_GOODS * (self::INFLATION_WEIGHT_GOODS + self::INFLATION_WEIGHT_COMMODITY))) * $state->supercoreInflation)
            + ($homeMade * self::INFLATION_WEIGHT_GOODS * ($state->coreGoodsInflation - $state->coreGoodsImportInflation))
            + ($homeMade * self::INFLATION_WEIGHT_COMMODITY * $homeFoodAndFuel)
            + $carbonCostPush
        ) / self::domesticDeflatorWeight();

        // The blend is linear with weights summing to one, so headline splits exactly into what each channel put in
        // it, with the gap between a sector's rate and the rate it is moving toward booked as the sticky-price lag.
        if ($this->diagnostics?->isEnabled()) {
            $weightSum = self::INFLATION_WEIGHT_SUPERCORE + self::INFLATION_WEIGHT_GOODS + self::INFLATION_WEIGHT_COMMODITY;
            $this->diagnostics->recordInflation([
                'target' => $targetInflation * $weightSum,
                'expectationsSlip' => $anchorSlip * $weightSum,
                'demandPull' => $convexDemandPressure * (self::INFLATION_WEIGHT_SUPERCORE + (self::CORE_GOODS_DEMAND_SENSITIVITY * self::INFLATION_WEIGHT_GOODS)),
                'wagePush' => $wageCostPush * self::INFLATION_WEIGHT_SUPERCORE,
                'servicesRepricing' => $state->servicesPriceShock * self::INFLATION_WEIGHT_SUPERCORE,
                'goodsSupply' => $goodsSupplyFriction * self::INFLATION_WEIGHT_GOODS,
                'energyPassThrough' => $energyCostPush,
                'foodPassThrough' => $agriCostPush,
                'carbonPassThrough' => $carbonCostPush,
                'importPrices' => $importPriceInflation * (self::INFLATION_WEIGHT_GOODS + self::INFLATION_WEIGHT_COMMODITY),
                'stickyPriceLag' => (self::INFLATION_WEIGHT_SUPERCORE * ($state->supercoreInflation - $state->servicesPriceShock - $targetSupercore))
                    + (self::INFLATION_WEIGHT_GOODS * ($state->coreGoodsInflation - $targetCoreGoods)),
                'clamp' => $boundedInflation - $blendedInflation,
            ], $boundedInflation, $dt);
        }

        return $boundedInflation;
    }

    /**
     * Gurkaynak, Sack & Wright (2010) ten-year TIPS breakeven: expected inflation over the bond's life plus its risk
     * premium, less the TIPS liquidity premium, fitted as one reduced form on the inflation the market sees.
     *
     * Expected inflation rests on a shifting endpoint (Kozicki & Tinsley 2001): the long-run inflation the public has
     * learned from realized inflation (MonetaryPolicySubsystem::updateInflationEndpoint()), which the US ten-year takes
     * 0.44 of. Around it the ten-year moves 0.085 per point of core over the endpoint and 0.10 per point of headline
     * over core, so a commodity shock is priced as passing. The output gap adds nothing once inflation is held (CBO gap
     * 0.02, se 0.02). The fit also holds the excess bond premium, whose liquidity squeeze on TIPS moves the quote but
     * not the inflation the market expects (D'Amico, Kim & Wei 2018); quotedTipsBreakeven() adds it for the quote
     * alone, so the rule, wages and yields read expected inflation.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation The Authority's inflation target.
     * @return float Ten-year breakeven inflation.
     */
    public function calculateTipsBreakeven(MacroState $state, float $targetInflation): float
    {
        // Headline runs over core by the energy, food and carbon pass-through rate the commodity basket adds.
        $headlineOverCore = $state->noncorePassThroughEma;
        $learnedEndpoint = $targetInflation + $state->inflationEndpointGap;

        return $targetInflation
            + (self::BREAKEVEN_ENDPOINT_LOADING * $state->inflationEndpointGap)
            + (self::BREAKEVEN_CORE_LOADING * (self::coreInflationEma($state) - $learnedEndpoint))
            + (self::BREAKEVEN_NONCORE_LOADING * $headlineOverCore);
    }

    /**
     * The ten-year breakeven the market quotes: expected inflation less the TIPS liquidity premium, which widens with
     * the excess bond premium as lenders' balance sheets tighten (Gilchrist & Zakrajsek 2012; D'Amico, Kim & Wei 2018).
     */
    public static function quotedTipsBreakeven(float $breakeven, float $excessBondPremium): float
    {
        return $breakeven + (self::BREAKEVEN_EBP_LOADING * $excessBondPremium);
    }

    /**
     * The consumer basket's District value added as a share of it: the services, the goods, fuel and food made at home,
     * and the local distribution margin of the imported ones (Burstein, Neves & Rebelo 2003). The deflator's weights
     * renormalise on it.
     */
    public static function domesticDeflatorWeight(): float
    {
        $goodsWeight = self::INFLATION_WEIGHT_GOODS + self::INFLATION_WEIGHT_COMMODITY;

        return self::INFLATION_WEIGHT_SUPERCORE + ($goodsWeight * ((1.0 - self::IMPORTED_SHARE_OF_GOODS) + (self::IMPORTED_SHARE_OF_GOODS * self::IMPORT_DISTRIBUTION_SHARE)));
    }

    /**
     * Core inflation, less food and energy: supercore services and core goods at their basket weights, renormalised.
     *
     * @param MacroState $state Current macroeconomic state.
     * @return float Smoothed core inflation rate.
     */
    public static function coreInflationEma(MacroState $state): float
    {
        return ((self::INFLATION_WEIGHT_SUPERCORE * $state->supercoreInflationEma) + (self::INFLATION_WEIGHT_GOODS * $state->coreGoodsInflationEma))
            / (self::INFLATION_WEIGHT_SUPERCORE + self::INFLATION_WEIGHT_GOODS);
    }
}

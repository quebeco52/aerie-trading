<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;

/**
 * Immutable Data Transfer Object representing a snapshot of the macroeconomic state.
 * Replaces weakly-typed associative arrays ($macroState) across models and engines.
 */
readonly class MacroStateDTO
{
    public function __construct(
        public float $totalTime = 0.0,
        public float $outputGap = 0.0,
        public float $outputGapEma = 0.0,
        public float $capitalStockOverhang = 0.0,
        public float $capitalStockOverhangEma = 0.0,
        public float $unemploymentRate = 0.04,
        public float $unemploymentRateEma = 0.04,
        public float $jobVacanciesRate = 0.045,
        public float $jobVacanciesRateEma = 0.045,
        public float $laborTightness = 1.125,
        public float $laborTightnessEma = 1.125,
        public float $wageGrowth = 0.035,
        public float $wageGrowthEma = 0.035,
        public float $nairu = MacroEngine::NATURAL_UNEMPLOYMENT,
        public float $nairuEma = MacroEngine::NATURAL_UNEMPLOYMENT,
        public float $naturalRate = MacroEngine::BASE_NATURAL_RATE,
        public float $naturalRateEma = MacroEngine::BASE_NATURAL_RATE,
        public float $energyPriceIndex = 100.0,
        public float $energyPriceIndexEma = 100.0,
        public float $energyPriceShock = 0.0,
        public float $energyCostPushLag = 0.0,
        public float $agriCostPushLag = 0.0,
        public float $consumerSentimentIndex = 100.0,
        public float $consumerSentimentIndexEma = 100.0,
        public float $exchangeRateIndex = 100.0,
        public float $exchangeRateIndexEma = 100.0,
        public float $industrialMetalsIndex = 100.0,
        public float $industrialMetalsIndexEma = 100.0,
        public float $metalsChi = 0.0,
        public float $metalsXi = 4.60517,
        public float $governmentSpendingIndex = 100.0,
        public float $governmentSpendingIndexEma = 100.0,
        public float $commercialPropertyIndex = 100.0,
        public float $commercialPropertyIndexEma = 100.0,
        public float $residentialPropertyIndex = 100.0,
        public float $residentialPropertyIndexEma = 100.0,
        public float $retailDefaultRate = 0.0250,
        public float $retailDefaultRateEma = 0.0250,
        public float $agriculturalCommodityIndex = 100.0,
        public float $agriculturalCommodityIndexEma = 100.0,
        public float $agriChi = 0.0,
        public float $agriXi = 4.60517,
        public float $freightRateIndex = 100.0,
        public float $freightRateIndexEma = 100.0,
        public float $freightSupplyEma = 100.0,
        public float $inflation = 0.02,
        public float $inflationEma = 0.02,
        public float $tipsBreakeven = 0.02,
        public float $tipsBreakevenEma = 0.02,
        public float $policyRate = 0.02,
        public float $policyRateEma = 0.02,
        public float $targetRate = 0.02,
        public float $yield2y = 0.04,
        public float $yield2yEma = 0.04,
        public float $yield5y = 0.045,
        public float $yield5yEma = 0.045,
        public float $yield10y = 0.05,
        public float $yield10yEma = 0.05,
        public float $yield30y = 0.055,
        public float $yield30yEma = 0.055,
        public float $termPremium10y = 0.0125,
        public float $termPremium10yEma = 0.0125,
        public float $riskNeutral10y = 0.0250,
        public float $riskNeutral10yEma = 0.0250,
        public float $marketVolatility = 0.15,
        public float $marketVolatilityEma = 0.15,
        public float $marketZ = 0.0,
        public float $corporateTaxRate = MacroEngine::BASE_CORPORATE_TAX_RATE,
        public float $sovereignDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $sovereignDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $equityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        public float $macroCreditSpread = 0.02,
        public float $macroCreditSpreadEma = 0.02,
        public float $interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD,
        public float $interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD,
        public float $totalFactorProductivityIndex = MacroEngine::TFP_BASELINE,
        public float $totalFactorProductivityIndexEma = MacroEngine::TFP_BASELINE,
        public float $financialConditionsIndex = 0.0,
        public float $financialConditionsIndexEma = 0.0,
        public bool $qeActive = false,
        public float $qeIntensity = 0.0,
        public bool $qtActive = false,
        public float $qtIntensity = 0.0,
        public float $balanceSheetIntensity = 0.0,
        public float $balanceSheetHoldTimer = 0.0,
        public float $inversionDuration = 0.0,
        public float $nsLevel = 0.0,
        public float $nsSlope = 0.0,
        public float $nsSlopeEma = 0.0,
        public float $nsCurvature = 0.0,
        public float $nsCurvature2 = 0.0,
        public float $potentialGdpIndex = 1.0,
        public float $nominalGdpIndex = 1.0,
        public float $gdpDeflator = 1.0,
        public ?string $eventType = null,
    ) {}

    /**
     * Constructs a MacroStateDTO from an associative array payload with defaults.
     */
    public static function fromArray(array $data): self
    {
        $totalTime = (float) ($data['total_time'] ?? 0.0);
        $inflation = (float) ($data['inflation'] ?? MacroEngine::TARGET_INFLATION);
        $inflationEma = (float) ($data['inflation_ema'] ?? $inflation);
        $tipsBreakeven = (float) ($data['tips_breakeven'] ?? $inflation);
        $tipsBreakevenEma = (float) ($data['tips_breakeven_ema'] ?? $tipsBreakeven);
        $outputGap = (float) ($data['output_gap'] ?? 0.02);
        $outputGapEma = (float) ($data['output_gap_ema'] ?? $outputGap);
        $capitalStockOverhang = (float) ($data['capital_stock_overhang'] ?? 0.0);
        $capitalStockOverhangEma = (float) ($data['capital_stock_overhang_ema'] ?? $capitalStockOverhang);
        
        $unemploymentRate = (float) ($data['unemployment_rate'] ?? 0.04);
        $unemploymentRateEma = (float) ($data['unemployment_rate_ema'] ?? $unemploymentRate);
        $jobVacanciesRate = (float) ($data['job_vacancies_rate'] ?? 0.045);
        $jobVacanciesRateEma = (float) ($data['job_vacancies_rate_ema'] ?? $jobVacanciesRate);
        $laborTightness = (float) ($data['labor_tightness'] ?? 1.125);
        $laborTightnessEma = (float) ($data['labor_tightness_ema'] ?? $laborTightness);
        $wageGrowth = (float) ($data['wage_growth'] ?? 0.035);
        $wageGrowthEma = (float) ($data['wage_growth_ema'] ?? $wageGrowth);
        $nairu = (float) ($data['nairu'] ?? MacroEngine::NATURAL_UNEMPLOYMENT);
        $nairuEma = (float) ($data['nairu_ema'] ?? $nairu);

        $naturalRate = (float) ($data['natural_rate'] ?? MacroEngine::BASE_NATURAL_RATE);
        $naturalRateEma = (float) ($data['natural_rate_ema'] ?? $naturalRate);
        
        $energyPriceIndex = (float) ($data['energy_price_index'] ?? 100.0);
        $energyPriceIndexEma = (float) ($data['energy_price_index_ema'] ?? $energyPriceIndex);
        $energyPriceShock = (float) ($data['energy_price_shock'] ?? 0.0);
        $energyCostPushLag = (float) ($data['energy_cost_push_lag'] ?? 0.0);
        $agriCostPushLag = (float) ($data['agri_cost_push_lag'] ?? 0.0);
        $consumerSentimentIndex = (float) ($data['consumer_sentiment_index'] ?? 100.0);
        $consumerSentimentIndexEma = (float) ($data['consumer_sentiment_index_ema'] ?? $consumerSentimentIndex);

        $exchangeRateIndex = (float) ($data['exchange_rate_index'] ?? 100.0);
        $exchangeRateIndexEma = (float) ($data['exchange_rate_index_ema'] ?? $exchangeRateIndex);
        $industrialMetalsIndex = (float) ($data['industrial_metals_index'] ?? 100.0);
        $industrialMetalsIndexEma = (float) ($data['industrial_metals_index_ema'] ?? $industrialMetalsIndex);
        $metalsChi = (float) ($data['metals_chi'] ?? 0.0);
        $metalsXi = (float) ($data['metals_xi'] ?? 4.60517);
        $governmentSpendingIndex = (float) ($data['government_spending_index'] ?? 100.0);
        $governmentSpendingIndexEma = (float) ($data['government_spending_index_ema'] ?? $governmentSpendingIndex);
        $commercialPropertyIndex = (float) ($data['commercial_property_index'] ?? 100.0);
        $commercialPropertyIndexEma = (float) ($data['commercial_property_index_ema'] ?? $commercialPropertyIndex);
        $residentialPropertyIndex = (float) ($data['residential_property_index'] ?? 100.0);
        $residentialPropertyIndexEma = (float) ($data['residential_property_index_ema'] ?? $residentialPropertyIndex);
        $retailDefaultRate = (float) ($data['retail_default_rate'] ?? 0.0250);
        $retailDefaultRateEma = (float) ($data['retail_default_rate_ema'] ?? $retailDefaultRate);
        $agriculturalCommodityIndex = (float) ($data['agricultural_commodity_index'] ?? 100.0);
        $agriculturalCommodityIndexEma = (float) ($data['agricultural_commodity_index_ema'] ?? $agriculturalCommodityIndex);
        $agriChi = (float) ($data['agri_chi'] ?? 0.0);
        $agriXi = (float) ($data['agri_xi'] ?? 4.60517);
        $freightRateIndex = (float) ($data['freight_rate_index'] ?? 100.0);
        $freightRateIndexEma = (float) ($data['freight_rate_index_ema'] ?? $freightRateIndex);
        $freightSupplyEma = (float) ($data['freight_supply_ema'] ?? 100.0);
        
        $policyRate = (float) ($data['policy_rate'] ?? 0.02);
        $policyRateEma = (float) ($data['policy_rate_ema'] ?? $policyRate);
        $targetRate = (float) ($data['target_rate'] ?? 0.02);

        $yield2y = (float) ($data['yield_2y'] ?? $policyRate);
        $yield2yEma = (float) ($data['yield_2y_ema'] ?? $yield2y);
        $yield5y = (float) ($data['yield_5y'] ?? ($policyRate + 0.005));
        $yield5yEma = (float) ($data['yield5y_ema'] ?? ($data['yield_5y_ema'] ?? $yield5y));
        $yield10y = (float) ($data['yield_10y'] ?? ($policyRate + 0.01));
        $yield10yEma = (float) ($data['yield_10y_ema'] ?? $yield10y);
        $yield30y = (float) ($data['yield_30y'] ?? ($policyRate + 0.015));
        $yield30yEma = (float) ($data['yield_30y_ema'] ?? $yield30y);

        $termPremium10y = (float) ($data['term_premium_10y'] ?? 0.0125);
        $termPremium10yEma = (float) ($data['term_premium_10y_ema'] ?? $termPremium10y);
        $riskNeutral10y = (float) ($data['risk_neutral_10y'] ?? 0.0250);
        $riskNeutral10yEma = (float) ($data['risk_neutral_10y_ema'] ?? $riskNeutral10y);

        $marketVolatility = (float) ($data['market_volatility'] ?? 0.15);
        $marketVolatilityEma = (float) ($data['market_volatility_ema'] ?? $marketVolatility);
        $marketZ = (float) ($data['market_z'] ?? 0.0);

        $corporateTaxRate = (float) ($data['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE);
        $sovereignDebtToGdp = (float) ($data['sovereign_debt_to_gdp'] ?? MacroEngine::INITIAL_DEBT_TO_GDP);
        $sovereignDebtToGdpEma = (float) ($data['sovereign_debt_to_gdp_ema'] ?? $sovereignDebtToGdp);
        $equityRiskPremium = (float) ($data['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM);
        $macroCreditSpread = (float) ($data['macro_credit_spread'] ?? 0.02);
        $macroCreditSpreadEma = (float) ($data['macro_credit_spread_ema'] ?? $macroCreditSpread);
        $interbankLiquiditySpread = (float) ($data['interbank_liquidity_spread'] ?? MacroEngine::INTERBANK_BASELINE_SPREAD);
        $interbankLiquiditySpreadEma = (float) ($data['interbank_liquidity_spread_ema'] ?? $interbankLiquiditySpread);
        $totalFactorProductivityIndex = (float) ($data['total_factor_productivity_index'] ?? MacroEngine::TFP_BASELINE);
        $totalFactorProductivityIndexEma = (float) ($data['total_factor_productivity_index_ema'] ?? $totalFactorProductivityIndex);
        $financialConditionsIndex = (float) ($data['financial_conditions_index'] ?? 0.0);
        $financialConditionsIndexEma = (float) ($data['financial_conditions_index_ema'] ?? $financialConditionsIndex);

        $balanceSheetIntensity = (float) ($data['balance_sheet_intensity'] ?? ($data['qe_intensity'] ?? 0.0));
        $balanceSheetHoldTimer = (float) ($data['balance_sheet_hold_timer'] ?? 0.0);
        $qeActive = (bool) ($data['qe_active'] ?? ($balanceSheetIntensity > 0.0005));
        $qeIntensity = (float) ($data['qe_intensity'] ?? max(0.0, $balanceSheetIntensity));
        $qtActive = (bool) ($data['qt_active'] ?? ($balanceSheetIntensity < -0.0005));
        $qtIntensity = (float) ($data['qt_intensity'] ?? max(0.0, -$balanceSheetIntensity));

        $inversionDuration = (float) ($data['inversion_duration'] ?? 0.0);
        $nsLevel = (float) ($data['ns_level'] ?? 0.0);
        $nsSlope = (float) ($data['ns_slope'] ?? 0.0);
        $nsSlopeEma = (float) ($data['ns_slope_ema'] ?? $nsSlope);
        $nsCurvature = (float) ($data['ns_curvature'] ?? 0.0);
        $nsCurvature2 = (float) ($data['ns_curvature2'] ?? 0.0);

        $nominalGdpIndex = (float) ($data['nominal_gdp_index'] ?? 1.0);
        $potentialGdpIndex = (float) ($data['potential_gdp_index'] ?? ($nominalGdpIndex / (1.0 + $outputGap)));
        $gdpDeflator = (float) ($data['gdp_deflator'] ?? 1.0);
        $eventType = isset($data['event_type']) ? (string) $data['event_type'] : null;

        return new self(
            totalTime: $totalTime,
            outputGap: $outputGap,
            outputGapEma: $outputGapEma,
            capitalStockOverhang: $capitalStockOverhang,
            capitalStockOverhangEma: $capitalStockOverhangEma,
            unemploymentRate: $unemploymentRate,
            unemploymentRateEma: $unemploymentRateEma,
            jobVacanciesRate: $jobVacanciesRate,
            jobVacanciesRateEma: $jobVacanciesRateEma,
            laborTightness: $laborTightness,
            laborTightnessEma: $laborTightnessEma,
            wageGrowth: $wageGrowth,
            wageGrowthEma: $wageGrowthEma,
            nairu: $nairu,
            nairuEma: $nairuEma,
            naturalRate: $naturalRate,
            naturalRateEma: $naturalRateEma,
            energyPriceIndex: $energyPriceIndex,
            energyPriceIndexEma: $energyPriceIndexEma,
            energyPriceShock: $energyPriceShock,
            energyCostPushLag: $energyCostPushLag,
            agriCostPushLag: $agriCostPushLag,
            consumerSentimentIndex: $consumerSentimentIndex,
            consumerSentimentIndexEma: $consumerSentimentIndexEma,
            exchangeRateIndex: $exchangeRateIndex,
            exchangeRateIndexEma: $exchangeRateIndexEma,
            industrialMetalsIndex: $industrialMetalsIndex,
            industrialMetalsIndexEma: $industrialMetalsIndexEma,
            metalsChi: $metalsChi,
            metalsXi: $metalsXi,
            governmentSpendingIndex: $governmentSpendingIndex,
            governmentSpendingIndexEma: $governmentSpendingIndexEma,
            commercialPropertyIndex: $commercialPropertyIndex,
            commercialPropertyIndexEma: $commercialPropertyIndexEma,
            residentialPropertyIndex: $residentialPropertyIndex,
            residentialPropertyIndexEma: $residentialPropertyIndexEma,
            retailDefaultRate: $retailDefaultRate,
            retailDefaultRateEma: $retailDefaultRateEma,
            agriculturalCommodityIndex: $agriculturalCommodityIndex,
            agriculturalCommodityIndexEma: $agriculturalCommodityIndexEma,
            agriChi: $agriChi,
            agriXi: $agriXi,
            freightRateIndex: $freightRateIndex,
            freightRateIndexEma: $freightRateIndexEma,
            freightSupplyEma: $freightSupplyEma,
            inflation: $inflation,
            inflationEma: $inflationEma,
            tipsBreakeven: $tipsBreakeven,
            tipsBreakevenEma: $tipsBreakevenEma,
            policyRate: $policyRate,
            policyRateEma: $policyRateEma,
            targetRate: $targetRate,
            yield2y: $yield2y,
            yield2yEma: $yield2yEma,
            yield5y: $yield5y,
            yield5yEma: $yield5yEma,
            yield10y: $yield10y,
            yield10yEma: $yield10yEma,
            yield30y: $yield30y,
            yield30yEma: $yield30yEma,
            termPremium10y: $termPremium10y,
            termPremium10yEma: $termPremium10yEma,
            riskNeutral10y: $riskNeutral10y,
            riskNeutral10yEma: $riskNeutral10yEma,
            marketVolatility: $marketVolatility,
            marketVolatilityEma: $marketVolatilityEma,
            marketZ: $marketZ,
            corporateTaxRate: $corporateTaxRate,
            sovereignDebtToGdp: $sovereignDebtToGdp,
            sovereignDebtToGdpEma: $sovereignDebtToGdpEma,
            equityRiskPremium: $equityRiskPremium,
            macroCreditSpread: $macroCreditSpread,
            macroCreditSpreadEma: $macroCreditSpreadEma,
            interbankLiquiditySpread: $interbankLiquiditySpread,
            interbankLiquiditySpreadEma: $interbankLiquiditySpreadEma,
            totalFactorProductivityIndex: $totalFactorProductivityIndex,
            totalFactorProductivityIndexEma: $totalFactorProductivityIndexEma,
            financialConditionsIndex: $financialConditionsIndex,
            financialConditionsIndexEma: $financialConditionsIndexEma,
            qeActive: $qeActive,
            qeIntensity: $qeIntensity,
            qtActive: $qtActive,
            qtIntensity: $qtIntensity,
            balanceSheetIntensity: $balanceSheetIntensity,
            balanceSheetHoldTimer: $balanceSheetHoldTimer,
            inversionDuration: $inversionDuration,
            nsLevel: $nsLevel,
            nsSlope: $nsSlope,
            nsSlopeEma: $nsSlopeEma,
            nsCurvature: $nsCurvature,
            nsCurvature2: $nsCurvature2,
            potentialGdpIndex: $potentialGdpIndex,
            nominalGdpIndex: $nominalGdpIndex,
            gdpDeflator: $gdpDeflator,
            eventType: $eventType,
        );
    }

    /**
     * Creates a MacroStateDTO from a MacroState entity/model object.
     */
    public static function fromMacroState(MacroState $state): self
    {
        return new self(
            totalTime: $state->totalTime,
            outputGap: $state->outputGap,
            outputGapEma: $state->outputGapEma,
            capitalStockOverhang: $state->capitalStockOverhang,
            capitalStockOverhangEma: $state->capitalStockOverhangEma,
            unemploymentRate: $state->unemploymentRate,
            unemploymentRateEma: $state->unemploymentRateEma,
            jobVacanciesRate: $state->jobVacanciesRate,
            jobVacanciesRateEma: $state->jobVacanciesRateEma,
            laborTightness: $state->laborTightness,
            laborTightnessEma: $state->laborTightnessEma,
            wageGrowth: $state->wageGrowth,
            wageGrowthEma: $state->wageGrowthEma,
            nairu: $state->nairu,
            nairuEma: $state->nairuEma,
            naturalRate: $state->naturalRate,
            naturalRateEma: $state->naturalRateEma,
            energyPriceIndex: $state->energyPriceIndex,
            energyPriceIndexEma: $state->energyPriceIndexEma,
            energyPriceShock: $state->energyPriceShock,
            energyCostPushLag: $state->energyCostPushLag,
            agriCostPushLag: $state->agriCostPushLag,
            consumerSentimentIndex: $state->consumerSentimentIndex,
            consumerSentimentIndexEma: $state->consumerSentimentIndexEma,
            exchangeRateIndex: $state->exchangeRateIndex,
            exchangeRateIndexEma: $state->exchangeRateIndexEma,
            industrialMetalsIndex: $state->industrialMetalsIndex,
            industrialMetalsIndexEma: $state->industrialMetalsIndexEma,
            metalsChi: $state->metalsChi,
            metalsXi: $state->metalsXi,
            governmentSpendingIndex: $state->governmentSpendingIndex,
            governmentSpendingIndexEma: $state->governmentSpendingIndexEma,
            commercialPropertyIndex: $state->commercialPropertyIndex,
            commercialPropertyIndexEma: $state->commercialPropertyIndexEma,
            residentialPropertyIndex: $state->residentialPropertyIndex,
            residentialPropertyIndexEma: $state->residentialPropertyIndexEma,
            retailDefaultRate: $state->retailDefaultRate,
            retailDefaultRateEma: $state->retailDefaultRateEma,
            agriculturalCommodityIndex: $state->agriculturalCommodityIndex,
            agriculturalCommodityIndexEma: $state->agriculturalCommodityIndexEma,
            agriChi: $state->agriChi,
            agriXi: $state->agriXi,
            freightRateIndex: $state->freightRateIndex,
            freightRateIndexEma: $state->freightRateIndexEma,
            freightSupplyEma: $state->freightSupplyEma,
            inflation: $state->inflation,
            inflationEma: $state->inflationEma,
            tipsBreakeven: $state->tipsBreakeven,
            tipsBreakevenEma: $state->tipsBreakevenEma,
            policyRate: $state->policyRate,
            policyRateEma: $state->policyRateEma,
            targetRate: $state->targetRate,
            yield2y: $state->yield2y,
            yield2yEma: $state->yield2yEma,
            yield5y: $state->yield5y,
            yield5yEma: $state->yield5yEma,
            yield10y: $state->yield10y,
            yield10yEma: $state->yield10yEma,
            yield30y: $state->yield30y,
            yield30yEma: $state->yield30yEma,
            termPremium10y: $state->termPremium10y,
            termPremium10yEma: $state->termPremium10yEma,
            riskNeutral10y: $state->riskNeutral10y,
            riskNeutral10yEma: $state->riskNeutral10yEma,
            marketVolatility: $state->marketVolatility,
            marketVolatilityEma: $state->marketVolatilityEma,
            marketZ: $state->marketZ,
            corporateTaxRate: $state->corporateTaxRate,
            sovereignDebtToGdp: $state->sovereignDebtToGdp,
            sovereignDebtToGdpEma: $state->sovereignDebtToGdpEma,
            equityRiskPremium: $state->equityRiskPremium,
            macroCreditSpread: $state->macroCreditSpread,
            macroCreditSpreadEma: $state->macroCreditSpreadEma,
            interbankLiquiditySpread: $state->interbankLiquiditySpread,
            interbankLiquiditySpreadEma: $state->interbankLiquiditySpreadEma,
            totalFactorProductivityIndex: $state->totalFactorProductivityIndex,
            totalFactorProductivityIndexEma: $state->totalFactorProductivityIndexEma,
            financialConditionsIndex: $state->financialConditionsIndex,
            financialConditionsIndexEma: $state->financialConditionsIndexEma,
            qeActive: $state->qeActive,
            qeIntensity: $state->qeIntensity,
            qtActive: $state->qtActive,
            qtIntensity: $state->qtIntensity,
            balanceSheetIntensity: $state->balanceSheetIntensity,
            balanceSheetHoldTimer: $state->balanceSheetHoldTimer,
            inversionDuration: $state->inversionDuration,
            nsLevel: $state->nsLevel,
            nsSlope: $state->nsSlope,
            nsSlopeEma: $state->nsSlopeEma,
            nsCurvature: $state->nsCurvature,
            nsCurvature2: $state->nsCurvature2,
            potentialGdpIndex: $state->potentialGdpIndex,
            nominalGdpIndex: $state->nominalGdpIndex,
            gdpDeflator: $state->gdpDeflator,
            eventType: $state->eventType,
        );
    }

    /**
     * Converts the DTO to an associative array for backward compatibility and serialization.
     */
    public function toArray(): array
    {
        return [
            'total_time' => $this->totalTime,
            'output_gap' => $this->outputGap,
            'output_gap_ema' => $this->outputGapEma,
            'capital_stock_overhang' => $this->capitalStockOverhang,
            'capital_stock_overhang_ema' => $this->capitalStockOverhangEma,
            'unemployment_rate' => $this->unemploymentRate,
            'unemployment_rate_ema' => $this->unemploymentRateEma,
            'job_vacancies_rate' => $this->jobVacanciesRate,
            'job_vacancies_rate_ema' => $this->jobVacanciesRateEma,
            'labor_tightness' => $this->laborTightness,
            'labor_tightness_ema' => $this->laborTightnessEma,
            'wage_growth' => $this->wageGrowth,
            'wage_growth_ema' => $this->wageGrowthEma,
            'nairu' => $this->nairu,
            'nairu_ema' => $this->nairuEma,
            'natural_rate' => $this->naturalRate,
            'natural_rate_ema' => $this->naturalRateEma,
            'energy_price_index' => $this->energyPriceIndex,
            'energy_price_index_ema' => $this->energyPriceIndexEma,
            'energy_price_shock' => $this->energyPriceShock,
            'energy_cost_push_lag' => $this->energyCostPushLag,
            'agri_cost_push_lag' => $this->agriCostPushLag,
            'consumer_sentiment_index' => $this->consumerSentimentIndex,
            'consumer_sentiment_index_ema' => $this->consumerSentimentIndexEma,
            'exchange_rate_index' => $this->exchangeRateIndex,
            'exchange_rate_index_ema' => $this->exchangeRateIndexEma,
            'industrial_metals_index' => $this->industrialMetalsIndex,
            'industrial_metals_index_ema' => $this->industrialMetalsIndexEma,
            'metals_chi' => $this->metalsChi,
            'metals_xi' => $this->metalsXi,
            'government_spending_index' => $this->governmentSpendingIndex,
            'government_spending_index_ema' => $this->governmentSpendingIndexEma,
            'commercial_property_index' => $this->commercialPropertyIndex,
            'commercial_property_index_ema' => $this->commercialPropertyIndexEma,
            'residential_property_index' => $this->residentialPropertyIndex,
            'residential_property_index_ema' => $this->residentialPropertyIndexEma,
            'retail_default_rate' => $this->retailDefaultRate,
            'retail_default_rate_ema' => $this->retailDefaultRateEma,
            'agricultural_commodity_index' => $this->agriculturalCommodityIndex,
            'agricultural_commodity_index_ema' => $this->agriculturalCommodityIndexEma,
            'agri_chi' => $this->agriChi,
            'agri_xi' => $this->agriXi,
            'freight_rate_index' => $this->freightRateIndex,
            'freight_rate_index_ema' => $this->freightRateIndexEma,
            'freight_supply_ema' => $this->freightSupplyEma,
            'inflation' => $this->inflation,
            'inflation_ema' => $this->inflationEma,
            'tips_breakeven' => $this->tipsBreakeven,
            'tips_breakeven_ema' => $this->tipsBreakevenEma,
            'policy_rate' => $this->policyRate,
            'policy_rate_ema' => $this->policyRateEma,
            'target_rate' => $this->targetRate,
            'yield_2y' => $this->yield2y,
            'yield_2y_ema' => $this->yield2yEma,
            'yield_5y' => $this->yield5y,
            'yield_5y_ema' => $this->yield5yEma,
            'yield_10y' => $this->yield10y,
            'yield_10y_ema' => $this->yield10yEma,
            'yield_30y' => $this->yield30y,
            'yield_30y_ema' => $this->yield30yEma,
            'term_premium_10y' => $this->termPremium10y,
            'term_premium_10y_ema' => $this->termPremium10yEma,
            'risk_neutral_10y' => $this->riskNeutral10y,
            'risk_neutral_10y_ema' => $this->riskNeutral10yEma,
            'market_volatility' => $this->marketVolatility,
            'market_volatility_ema' => $this->marketVolatilityEma,
            'market_z' => $this->marketZ,
            'corporate_tax_rate' => $this->corporateTaxRate,
            'sovereign_debt_to_gdp' => $this->sovereignDebtToGdp,
            'sovereign_debt_to_gdp_ema' => $this->sovereignDebtToGdpEma,
            'equity_risk_premium' => $this->equityRiskPremium,
            'macro_credit_spread' => $this->macroCreditSpread,
            'macro_credit_spread_ema' => $this->macroCreditSpreadEma,
            'interbank_liquidity_spread' => $this->interbankLiquiditySpread,
            'interbank_liquidity_spread_ema' => $this->interbankLiquiditySpreadEma,
            'total_factor_productivity_index' => $this->totalFactorProductivityIndex,
            'total_factor_productivity_index_ema' => $this->totalFactorProductivityIndexEma,
            'financial_conditions_index' => $this->financialConditionsIndex,
            'financial_conditions_index_ema' => $this->financialConditionsIndexEma,
            'balance_sheet_intensity' => $this->balanceSheetIntensity,
            'balance_sheet_hold_timer' => $this->balanceSheetHoldTimer,
            'qe_active' => $this->qeActive,
            'qe_intensity' => $this->qeIntensity,
            'qt_active' => $this->qtActive,
            'qt_intensity' => $this->qtIntensity,
            'inversion_duration' => $this->inversionDuration,
            'ns_level' => $this->nsLevel,
            'ns_slope' => $this->nsSlope,
            'ns_slope_ema' => $this->nsSlopeEma,
            'ns_curvature' => $this->nsCurvature,
            'ns_curvature2' => $this->nsCurvature2,
            'potential_gdp_index' => $this->potentialGdpIndex,
            'nominal_gdp_index' => $this->nominalGdpIndex,
            'gdp_deflator' => $this->gdpDeflator,
            'event_type' => $this->eventType,
        ];
    }
}

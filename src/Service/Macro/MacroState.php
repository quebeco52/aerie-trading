<?php

namespace App\Service\Macro;

use App\Data\MacroFieldRegistry;

class MacroState
{
    public float $totalTime = 0.0;
    public float $inflation = MacroEngine::TARGET_INFLATION;
    public float $inflationEma = MacroEngine::TARGET_INFLATION;
    public float $outputGap = 0.015;
    public float $outputGapEma = 0.015;
    public float $capitalStockOverhang = 0.0;
    public float $capitalStockOverhangEma = 0.0;

    public float $unemploymentRate = 0.038;
    public float $unemploymentRateEma = 0.038;
    public float $jobVacanciesRate = 0.045;
    public float $jobVacanciesRateEma = 0.045;
    public float $laborTightness = 1.125;
    public float $laborTightnessEma = 1.125;
    public float $wageGrowth = 0.035;
    public float $wageGrowthEma = 0.035;
    public float $nairu = MacroEngine::NATURAL_UNEMPLOYMENT;
    public float $nairuEma = MacroEngine::NATURAL_UNEMPLOYMENT;

    public float $naturalRate = MacroEngine::BASE_NATURAL_RATE;
    public float $naturalRateEma = MacroEngine::BASE_NATURAL_RATE;

    public float $energyPriceIndex = 90.0;
    public float $energyPriceIndexEma = 90.0;
    public float $energyPriceShock = 0.0;
    public float $energyBasePrice = 90.0;
    public float $consumerSentimentIndex = 108.0;
    public float $consumerSentimentIndexEma = 108.0;

    public float $exchangeRateIndex = 100.0;
    public float $exchangeRateIndexEma = 100.0;

    public float $industrialMetalsIndex = 100.0;
    public float $industrialMetalsIndexEma = 100.0;
    public float $metalsChi = 0.0;
    public float $metalsXi = 4.60517;

    public float $governmentSpendingIndex = 100.0;
    public float $governmentSpendingIndexEma = 100.0;

    public float $commercialPropertyIndex = 100.0;
    public float $commercialPropertyIndexEma = 100.0;

    public float $residentialPropertyIndex = 100.0;
    public float $residentialPropertyIndexEma = 100.0;

    public float $retailDefaultRate = 0.0250;
    public float $retailDefaultRateEma = 0.0250;

    public float $agriculturalCommodityIndex = 100.0;
    public float $agriculturalCommodityIndexEma = 100.0;
    public float $agriChi = 0.0;
    public float $agriXi = 4.60517;

    public float $freightRateIndex = 100.0;
    public float $freightRateIndexEma = 100.0;
    public float $freightSupplyEma = 100.0;

    public float $targetRate = 0.0250;
    public float $policyRate = 0.0250;
    public float $policyRateEma = 0.0250;

    public float $tipsBreakeven = MacroEngine::TARGET_INFLATION;
    public float $tipsBreakevenEma = MacroEngine::TARGET_INFLATION;
    public float $energyCostPushLag = 0.0;
    public float $agriCostPushLag = 0.0;

    public float $nsLevel = 0.0425;
    public float $nsSlope = -0.0150;
    public float $nsSlopeEma = -0.0150;
    public float $structuralSlope = -0.0150;
    public float $nsCurvature = 0.0;
    public float $nsCurvature2 = 0.0;
    public float $nsBeta1 = -0.0175;
    public float $nsBaseTermPremium = MacroEngine::NS_BASE_TERM_PREMIUM;
    public float $nsLongEndPremium = MacroEngine::NS_BASE_TERM_PREMIUM;

    public float $yield2y = 0.0275;
    public float $yield2yEma = 0.0275;
    public float $yield5y = 0.0325;
    public float $yield5yEma = 0.0325;
    public float $yield10y = 0.0375;
    public float $yield10yEma = 0.0375;
    public float $yield30y = 0.0425;
    public float $yield30yEma = 0.0425;

    public float $termPremium10y = 0.0125;
    public float $termPremium10yEma = 0.0125;
    public float $riskNeutral10y = 0.0250;
    public float $riskNeutral10yEma = 0.0250;
    public float $termPremiumShock = 0.0;
    public float $termPremiumRegime = MacroEngine::NS_BASE_TERM_PREMIUM;
    public float $perceivedNeutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
    public float $restrictiveDuration = 0.0;

    public bool $qeActive = false;
    public float $qeIntensity = 0.0;
    public bool $qtActive = false;
    public float $qtIntensity = 0.0;
    public float $balanceSheetIntensity = 0.0;
    public float $balanceSheetHoldTimer = 0.0;

    public float $inversionDuration = 0.0;
    public float $corporateTaxRate = MacroEngine::BASE_CORPORATE_TAX_RATE;
    public float $sovereignDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP;
    public float $sovereignDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP;
    public ?string $eventType = null;
    public float $eventCooldownTimer = 0.0;
    public float $equityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM;

    public float $potentialGdpIndex = 1.0;
    public float $nominalGdpIndex = 1.0;
    public float $gdpDeflator = 1.0;

    public float $marketVolatility = 0.13;
    public float $marketVolatilityEma = 0.13;
    public float $marketZ = 0.0;
    public float $marketZLatent = 0.0;
    public float $marketJumpMultiplier = 1.0;
    /** @var array<string, float> Per-macro-sector shock, keyed by Sectors::MACRO_SECTORS. */
    public array $sectorZ = [];
    /** @var array<string, float> Persistent per-macro-sector demand factor (OU process, unit variance), keyed by Sectors::MACRO_SECTORS. */
    public array $sectorDemandZ = [];
    public float $financialConditionsIndex = 0.0;
    public float $financialConditionsIndexEma = 0.0;

    public float $macroCreditSpread = MacroEngine::BASE_CREDIT_SPREAD;
    public float $macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD;

    public float $interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;
    public float $interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;

    public float $totalFactorProductivityIndex = MacroEngine::TFP_BASELINE;
    public float $totalFactorProductivityIndexEma = MacroEngine::TFP_BASELINE;

    public float $supercoreInflation = MacroEngine::TARGET_INFLATION;
    public float $supercoreInflationEma = MacroEngine::TARGET_INFLATION;
    public float $coreGoodsInflation = MacroEngine::TARGET_INFLATION;
    public float $coreGoodsInflationEma = MacroEngine::TARGET_INFLATION;

    public float $cumulativeInflationGap = 0.0;
    public float $cumulativeInflationGapEma = 0.0;

    public float $highYieldCreditSpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
    public float $highYieldCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;

    public float $inventoryStockGap = 0.0;
    public float $inventoryStockGapEma = 0.0;

    public float $energyInventoryIndex = MacroEngine::COMMODITY_INVENTORY_BASELINE;
    public float $energyInventoryIndexEma = MacroEngine::COMMODITY_INVENTORY_BASELINE;

    public float $capacityUtilizationRate = MacroEngine::CU_BASELINE;
    public float $capacityUtilizationRateEma = MacroEngine::CU_BASELINE;

    public float $recessionProbability = 0.15;
    public float $recessionProbabilityEma = 0.15;

    public float $corporateDefaultRate = MacroEngine::CORPORATE_DEFAULT_BASELINE;
    public float $corporateDefaultRateEma = MacroEngine::CORPORATE_DEFAULT_BASELINE;

    public float $sloosTighteningIndex = 0.0;
    public float $sloosTighteningIndexEma = 0.0;

    public float $supplyChainPressureIndex = 0.0;
    public float $supplyChainPressureIndexEma = 0.0;

    public float $refiningCrackSpread = MacroEngine::CRACK_SPREAD_BASELINE;
    public float $refiningCrackSpreadEma = MacroEngine::CRACK_SPREAD_BASELINE;

    public float $dealActivityIndex = MacroEngine::DEAL_ACTIVITY_BASELINE;
    public float $dealActivityIndexEma = MacroEngine::DEAL_ACTIVITY_BASELINE;

    public float $manufacturingPmi = MacroEngine::PMI_BASELINE;
    public float $manufacturingPmiEma = MacroEngine::PMI_BASELINE;

    public float $producerPriceInflation = MacroEngine::TARGET_INFLATION;
    public float $producerPriceInflationEma = MacroEngine::TARGET_INFLATION;

    public float $tradeBalanceToGdp = MacroEngine::TRADE_BALANCE_BASELINE;
    public float $tradeBalanceToGdpEma = MacroEngine::TRADE_BALANCE_BASELINE;

    public float $housingStartsIndex = MacroEngine::HOUSING_STARTS_BASELINE;
    public float $housingStartsIndexEma = MacroEngine::HOUSING_STARTS_BASELINE;

    public float $moneySupplyGrowth = MacroEngine::M2_BASE_GROWTH;
    public float $moneySupplyGrowthEma = MacroEngine::M2_BASE_GROWTH;

    /**
     * Initializes the MacroState from a decoded JSON array payload.
     *
     * The field list comes from App\Data\MacroFieldRegistry, so a newly declared observable is read
     * back off the wire the moment it exists. It used to be named here a second time, and a field
     * left out of this method was reset to its opening value on every load without anything failing
     * — the caller still received a plausible number.
     *
     * Three passes, in order: the values the payload carries, the openings that are computed from
     * those values, then the series that start level with the series they smooth.
     *
     * @param array<string, mixed> $data Decoded Redis payload keyed by wire key.
     */
    public static function fromArray(array $data): self
    {
        $state = new self();
        $fields = MacroFieldRegistry::wireKeys();

        // A field the payload omits keeps the opening value its property declaration gives it.
        $carried = [];
        foreach ($fields as $field => $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $carried[$field] = true;
            $state->$field = match ($field) {
                'sectorZ', 'sectorDemandZ' => is_array($data[$key]) ? array_map('floatval', $data[$key]) : [],
                'qeActive', 'qtActive' => (bool) $data[$key],
                'eventType' => (string) $data[$key],
                default => (float) $data[$key],
            };
        }

        // Openings derived from the values above, for the fields a payload predating them omits.
        if (!isset($carried['balanceSheetIntensity'])) {
            // Balance sheet intensity was recorded as a one-sided qe_intensity before QT existed.
            $state->balanceSheetIntensity = (float) ($data['qe_intensity'] ?? 0.0);
        }
        if (!isset($carried['qeActive'])) {
            $state->qeActive = $state->balanceSheetIntensity > MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD;
        }
        if (!isset($carried['qeIntensity'])) {
            $state->qeIntensity = max(0.0, $state->balanceSheetIntensity);
        }
        if (!isset($carried['qtActive'])) {
            $state->qtActive = $state->balanceSheetIntensity < -MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD;
        }
        if (!isset($carried['qtIntensity'])) {
            $state->qtIntensity = max(0.0, -$state->balanceSheetIntensity);
        }
        if (!isset($carried['nsBeta1'])) {
            // Nelson-Siegel beta1 is the short-end spread of the curve, not a free parameter.
            $state->nsBeta1 = $state->policyRate - $state->nsLevel;
        }
        if (!isset($carried['potentialGdpIndex'])) {
            $state->potentialGdpIndex = $state->nominalGdpIndex / (1.0 + $state->outputGap);
        }
        if (!isset($carried['highYieldCreditSpread'])) {
            $state->highYieldCreditSpread = $state->macroCreditSpread * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
        }

        // A smoothed series with no reading of its own opens level with the series it smooths.
        foreach (MacroFieldRegistry::seeds() as $field => $source) {
            if (!isset($carried[$field])) {
                $state->$field = $state->$source;
            }
        }

        return $state;
    }

    /**
     * Converts the MacroState to the snake_case payload persisted to Redis.
     *
     * The key list comes from App\Data\MacroFieldRegistry, which reads it off
     * App\DTO\MacroStateDTO's constructor, so the writer on this end of the wire and the
     * MacroStateDTO::fromArray() reader on the other end cannot drift apart over a key name.
     *
     * @return array<string, mixed> Wire key => value.
     */
    public function toArray(): array
    {
        $payload = [];
        foreach (MacroFieldRegistry::wireKeys() as $field => $key) {
            $payload[$key] = $this->$field;
        }

        return $payload;
    }
}

<?php

declare(strict_types=1);

namespace App\DTO;

use App\Data\MacroFieldRegistry;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;

/**
 * Immutable Data Transfer Object representing a snapshot of the macroeconomic state.
 * Replaces weakly-typed associative arrays ($macroState) across models and engines.
 */
readonly class MacroStateDTO
{
    // --- Hydration Openings ---

    /** Output gap a payload with no reading of its own opens at; the seeded market starts mid-expansion, not at trend. */
    private const HYDRATION_OUTPUT_GAP = 0.02;

    /** 5y-over-policy term spread used to rebuild a curve the payload does not carry (~50bps). */
    private const HYDRATION_5Y_SPREAD = 0.005;

    /** 10y-over-policy term spread used to rebuild a curve the payload does not carry (~100bps). */
    private const HYDRATION_10Y_SPREAD = 0.01;

    /** 30y-over-policy term spread used to rebuild a curve the payload does not carry (~150bps). */
    private const HYDRATION_30Y_SPREAD = 0.015;

    /**
     * Seeds this snapshot adds to the shared set, for openings only a snapshot needs.
     *
     * App\Data\MacroFieldRegistry::seeds() already carries the seeds both readers share — every
     * `*Ema` pair, plus the three declared in its SEED_OVERRIDES (energyBasePrice,
     * supercoreInflation, coreGoodsInflation) that the naming convention does not describe. These
     * three are on top of those, and are not seeded when the engine's own state is hydrated: a
     * snapshot is read by the pricing surfaces, which cannot be handed a zero for a breakeven, a
     * structural slope or a 2y yield just because the payload predates that field.
     *
     * @var array<string, string>
     */
    private const HYDRATION_SEEDS = [
        'tipsBreakeven' => 'inflation',
        'structuralSlope' => 'nsSlope',
        'yield2y' => 'policyRate',
    ];

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
        public float $energyBasePrice = 100.0,
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
        public float $termPremiumShock = 0.0,
        public float $termPremiumRegime = MacroEngine::NS_BASE_TERM_PREMIUM,
        public float $perceivedNeutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION,
        public float $restrictiveDuration = 0.0,
        public float $marketVolatility = 0.15,
        public float $marketVolatilityEma = 0.15,
        public float $marketZ = 0.0,
        public float $marketZLatent = 0.0,
        public float $marketJumpMultiplier = 1.0,
        /** @var array<string, float> Per-macro-sector shock, keyed by Sectors::MACRO_SECTORS. */
        public array $sectorZ = [],
        /** @var array<string, float> Persistent per-macro-sector demand factor shared by every firm's earnings physics in the sector. */
        public array $sectorDemandZ = [],
        public float $corporateTaxRate = MacroEngine::BASE_CORPORATE_TAX_RATE,
        public float $sovereignDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $sovereignDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $equityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        public float $macroCreditSpread = MacroEngine::BASE_CREDIT_SPREAD,
        public float $macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD,
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
        public float $structuralSlope = 0.0,
        public float $nsCurvature = 0.0,
        public float $nsCurvature2 = 0.0,
        public float $nsBeta1 = 0.0,
        public float $nsBaseTermPremium = MacroEngine::NS_BASE_TERM_PREMIUM,
        public float $nsLongEndPremium = MacroEngine::NS_BASE_TERM_PREMIUM,
        public float $potentialGdpIndex = 1.0,
        public float $nominalGdpIndex = 1.0,
        public float $gdpDeflator = 1.0,
        public ?string $eventType = null,
        public float $eventCooldownTimer = 0.0,
        public float $supercoreInflation = MacroEngine::TARGET_INFLATION,
        public float $supercoreInflationEma = MacroEngine::TARGET_INFLATION,
        public float $coreGoodsInflation = MacroEngine::TARGET_INFLATION,
        public float $coreGoodsInflationEma = MacroEngine::TARGET_INFLATION,
        public float $cumulativeInflationGap = 0.0,
        public float $cumulativeInflationGapEma = 0.0,
        public float $highYieldCreditSpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
        public float $highYieldCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
        public float $inventoryStockGap = 0.0,
        public float $inventoryStockGapEma = 0.0,
        public float $energyInventoryIndex = MacroEngine::COMMODITY_INVENTORY_BASELINE,
        public float $energyInventoryIndexEma = MacroEngine::COMMODITY_INVENTORY_BASELINE,
        public float $capacityUtilizationRate = MacroEngine::CU_BASELINE,
        public float $capacityUtilizationRateEma = MacroEngine::CU_BASELINE,
        public float $recessionProbability = 0.15,
        public float $recessionProbabilityEma = 0.15,
        public float $corporateDefaultRate = MacroEngine::CORPORATE_DEFAULT_BASELINE,
        public float $corporateDefaultRateEma = MacroEngine::CORPORATE_DEFAULT_BASELINE,
        public float $sloosTighteningIndex = 0.0,
        public float $sloosTighteningIndexEma = 0.0,
        public float $supplyChainPressureIndex = 0.0,
        public float $supplyChainPressureIndexEma = 0.0,
        public float $refiningCrackSpread = MacroEngine::CRACK_SPREAD_BASELINE,
        public float $refiningCrackSpreadEma = MacroEngine::CRACK_SPREAD_BASELINE,
        public float $dealActivityIndex = MacroEngine::DEAL_ACTIVITY_BASELINE,
        public float $dealActivityIndexEma = MacroEngine::DEAL_ACTIVITY_BASELINE,
        public float $manufacturingPmi = MacroEngine::PMI_BASELINE,
        public float $manufacturingPmiEma = MacroEngine::PMI_BASELINE,
        public float $producerPriceInflation = MacroEngine::TARGET_INFLATION,
        public float $producerPriceInflationEma = MacroEngine::TARGET_INFLATION,
        public float $tradeBalanceToGdp = MacroEngine::TRADE_BALANCE_BASELINE,
        public float $tradeBalanceToGdpEma = MacroEngine::TRADE_BALANCE_BASELINE,
        public float $housingStartsIndex = MacroEngine::HOUSING_STARTS_BASELINE,
        public float $housingStartsIndexEma = MacroEngine::HOUSING_STARTS_BASELINE,
        public float $moneySupplyGrowth = MacroEngine::M2_BASE_GROWTH,
        public float $moneySupplyGrowthEma = MacroEngine::M2_BASE_GROWTH,
    ) {}

    /**
     * Constructs a MacroStateDTO from the snake_case payload Redis carries.
     *
     * The field list comes from App\Data\MacroFieldRegistry rather than being named here a second
     * time, so an observable added to the constructor is read back off the wire immediately. A field
     * this method forgot used to hydrate its opening value on every load with nothing failing — the
     * caller still got a plausible number, just not the recorded one.
     *
     * Three passes, in order: the values the payload carries, the openings computed from those
     * values, then the series that start level with the series they smooth.
     *
     * @param array<string, mixed> $data Decoded payload keyed by wire key.
     */
    public static function fromArray(array $data): self
    {
        $fields = MacroFieldRegistry::wireKeys();
        $defaults = MacroFieldRegistry::defaults();

        // A field the payload omits keeps the opening value the constructor declares for it.
        $args = [];
        foreach ($fields as $field => $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $args[$field] = match ($field) {
                'sectorZ', 'sectorDemandZ' => is_array($data[$key]) ? array_map('floatval', $data[$key]) : [],
                'qeActive', 'qtActive' => (bool) $data[$key],
                'eventType' => (string) $data[$key],
                default => (float) $data[$key],
            };
        }

        // By reference: the passes below read openings the earlier passes have already computed.
        $resolve = static function (string $field) use (&$args, $defaults): mixed {
            return $args[$field] ?? $defaults[$field];
        };

        // A snapshot rehydrated cold sits at the top of the cycle rather than at trend, which is
        // where the seeded market opens; the constructor's own zero is for a DTO built field by field.
        $args['outputGap'] ??= self::HYDRATION_OUTPUT_GAP;

        // The smoothed 5y was recorded under the macro_report column spelling before the wire key
        // existed, and a payload carrying both is a database row, so the column spelling wins.
        if (isset($data['yield5y_ema'])) {
            $args['yield5yEma'] = (float) $data['yield5y_ema'];
        }

        // Balance sheet intensity was recorded as a one-sided qe_intensity before QT existed.
        $args['balanceSheetIntensity'] ??= (float) ($data['qe_intensity'] ?? 0.0);
        $balanceSheetIntensity = $args['balanceSheetIntensity'];

        $args['qeActive'] ??= $balanceSheetIntensity > MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD;
        $args['qeIntensity'] ??= max(0.0, $balanceSheetIntensity);
        $args['qtActive'] ??= $balanceSheetIntensity < -MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD;
        $args['qtIntensity'] ??= max(0.0, -$balanceSheetIntensity);

        // A curve absent from the payload is rebuilt off the policy rate at the standard term spreads,
        // so the bond desk never discounts against a flat zero curve.
        $args['yield5y'] ??= $resolve('policyRate') + self::HYDRATION_5Y_SPREAD;
        $args['yield10y'] ??= $resolve('policyRate') + self::HYDRATION_10Y_SPREAD;
        $args['yield30y'] ??= $resolve('policyRate') + self::HYDRATION_30Y_SPREAD;

        // Nelson-Siegel beta1 is the short-end spread of the curve, not a free parameter.
        $args['nsBeta1'] ??= $resolve('policyRate') - $resolve('nsLevel');
        $args['potentialGdpIndex'] ??= $resolve('nominalGdpIndex') / (1.0 + $resolve('outputGap'));
        $args['highYieldCreditSpread'] ??= $resolve('macroCreditSpread') * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;

        // A smoothed series with no reading of its own opens level with the series it smooths. Walked
        // in constructor order so a series seeded from one that is itself seeded resolves in one pass.
        $seeds = MacroFieldRegistry::seeds() + self::HYDRATION_SEEDS;
        foreach (array_keys($fields) as $field) {
            if (isset($args[$field]) || !isset($seeds[$field])) {
                continue;
            }

            $args[$field] = $resolve($seeds[$field]);
        }

        return new self(...$args);
    }
    /**
     * The fitted term structure, ready for the bond desk to discount an arbitrary maturity against.
     *
     * Assembled rather than stored so there is one authority on which slope belongs in the curve function:
     * $nsSlope on this DTO is the 10y-minus-policy reporting metric and would produce a curve that reprices
     * nothing, while $nsBeta1 is the beta1 the macro engine actually fitted with.
     */
    public function sovereignCurve(): SovereignCurveDTO
    {
        return new SovereignCurveDTO(
            level: $this->nsLevel,
            slope: $this->nsBeta1,
            curvature1: $this->nsCurvature,
            curvature2: $this->nsCurvature2,
            baseTermPremium: $this->nsBaseTermPremium,
            longEndPremium: $this->nsLongEndPremium,
            balanceSheetIntensity: $this->balanceSheetIntensity,
        );
    }

    /**
     * Calendar quarter index [0..3] implied by elapsed simulation time. Derived rather than published:
     * it is not a macro series any institution reports, so it draws no district conduit. Matches the
     * quarter EarningsEngine derives from the tick counter, both being elapsed time over a quarter.
     */
    public function calendarQuarter(): int
    {
        return ((int) floor($this->totalTime * 4.0) % 4 + 4) % 4;
    }

    // --- Economic Cycle Label ---
    /** Output gap above which the cycle reads as a boom to a player. */
    public const CYCLE_BOOM_GAP = 0.01;
    /** Output gap below which the cycle reads as a bust to a player. */
    public const CYCLE_BUST_GAP = -0.01;

    /**
     * The economy's phase as the interface names it.
     *
     * Defined once because it is read in two places that must agree: the ticker publishes it on every
     * live update, and the stock page renders it on load. The page used to read a Redis key
     * (`economy_state`) that nothing has ever written, so it fell back to a hardcoded "Expansion" — a
     * label the live feed does not even use — until the first WebSocket tick replaced it.
     */
    public function economicCycleLabel(): string
    {
        return match (true) {
            $this->outputGap > self::CYCLE_BOOM_GAP => 'Boom',
            $this->outputGap < self::CYCLE_BUST_GAP => 'Bust',
            default => 'Neutral',
        };
    }

    /**
     * Snapshots the engine's mutable state vector into this immutable DTO.
     *
     * Every field is carried across by name, so the field list comes from
     * App\Data\MacroFieldRegistry rather than being spelled out a second time; MacroState declares
     * an identically named property for each of this constructor's parameters, and
     * App\Tests\DTO\MacroFieldRegistryTest fails if that ever stops being true.
     */
    public static function fromMacroState(MacroState $state): self
    {
        $arguments = [];
        foreach (array_keys(MacroFieldRegistry::wireKeys()) as $field) {
            $arguments[$field] = $state->$field;
        }

        return new self(...$arguments);
    }

    /**
     * Converts the DTO to the snake_case payload used for Redis persistence and serialization.
     *
     * @return array<string, mixed> Wire key => value, in the order MacroFieldRegistry declares.
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

<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Security & Protection Services: guarding, private military work, cash carriers and vaults,
 * and investigations. One model for the whole industry; each firm is its mix of seven lines.
 *
 * Financial Physics:
 * - Government Posts: guarding and policing under District contracts, on the budget the Diet passes.
 * - Corporate Retainers: site guarding and close protection, with a mild pull from the cycle (new sites need guards).
 * - Expeditionary Work: overseas deployments and extraction, bought in panics and credit stress; conflicts surge it.
 * - Cash Rounds: collections and cash machine refills, on the notes households spend and draw out when they fear for
 *   their banks; cards take a little more of them each year.
 * - Vaults: bullion kept for a share of its value, so dearer gold lifts fees on the same staff and buildings.
 * - Screening: due diligence and client checks for banks and funds, which follow the deals they do.
 * - Investigations: fraud, recovery and crisis cases, which arrive with defaults and wide credit spreads.
 * - Tail Risk: each line carries its own failure (a breach at a guarded site, a heist, a leaked file); an event fires
 *   only for a firm that sells the line it hits.
 */
class SecurityProtectionBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Guarding contracts
     * are a survival expense.
     */
    public const OPERATING_CYCLICALITY = 0.60;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::SECURITY_PROTECTION;
    /** Cost-plus government work and annual retainer resets recover guard wage moves almost in full. */
    public const PRICING_POWER_INDEX = 0.85;

    // --- Services Pricing ---
    /**
     * Elasticity of fee and rate pricing to services (supercore) inflation. Contract guard rates pass through services
     * wage inflation.
     */
    public const PRICING_ELASTICITY = 0.85;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for security and private military contractors. */
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    /** Base analyst forecasting error scalar for obfuscated security operations. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Secular Demand ---
    /**
     * Administrative and support services value added as a share of US nominal GDP in 1997 (BEA GDP by Industry, value
     * added).
     */
    public const SECULAR_SHARE_1997 = 0.0226;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0284;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.15 a year). */
    public const ROIC_REVERSION_SPEED = 0.15;
    /** Excess return over the required return the moat sustains (2.5pp). */
    public const MOAT_SPREAD = 0.025;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.8). Fleet, armor, and tactical hardware. */
    public const CAPEX_CYCLICALITY = 0.8;
    /** Share of construction in progress completed into plant each quarter (20%). */
    public const CAPEX_COMPLETION_RATE = 0.2;
    /** Net working capital as a share of revenue (5%). */
    public const WORKING_CAPITAL_INTENSITY = 0.05;

    // --- Seasonality ---
    /** Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: summer event and site staffing peak. */
    public const SEASONALITY_FACTORS = [0.98, 1.00, 1.02, 1.00];

    // --- Lines of Business ---
    /** Baseline fraction of revenue from guarding and policing posts under District contracts. */
    public const GOVERNMENT_CONTRACT_WEIGHT = 0.40;
    /** Baseline fraction of revenue from corporate site guarding and close protection retainers. */
    public const RETAINER_WEIGHT            = 0.40;
    /** Baseline fraction of revenue from overseas deployments, extraction and conflict logistics. */
    public const EXPEDITIONARY_WEIGHT        = 0.20;
    /** Baseline fraction of revenue from cash rounds: collections, cash machine refills and the cash centres. */
    public const CASH_SERVICES_WEIGHT = 0.0;
    /** Baseline fraction of revenue from vaults: bullion, valuables and high-value shipments. */
    public const VAULTS_WEIGHT = 0.0;
    /** Baseline fraction of revenue from screening and due diligence on retainer for banks and funds. */
    public const SCREENING_WEIGHT = 0.0;
    /** Baseline fraction of revenue from fraud investigations, asset recovery and crisis cases. */
    public const INVESTIGATIONS_WEIGHT = 0.0;

    // --- Stream Variance Scalars ---
    /** Variance scalar for highly stable sovereign government contracts. */
    public const GOVERNMENT_VARIANCE_SCALAR  = 0.02;
    /** Variance scalar for corporate security retainers. */
    public const RETAINER_VARIANCE_SCALAR    = 0.05;
    /** Variance scalar for volatile, conflict-driven expeditionary contracts. */
    public const EXPEDITIONARY_VARIANCE_SCALAR = 0.60;
    /** Variance scalar on the cash rounds: a route won or lost at re-tender. */
    public const CASH_VARIANCE_SCALAR = 0.06;
    /** Variance scalar on the vaults: a large client moving its metal. */
    public const VAULT_VARIANCE_SCALAR = 0.12;
    /** Variance scalar on screening: a bank's client book moving to a rival screener. */
    public const SCREENING_VARIANCE_SCALAR = 0.05;
    /** Variance scalar on investigations: a few large cases make the quarter. */
    public const INVESTIGATIONS_VARIANCE_SCALAR = 0.30;

    // --- Government, Retainer & Expeditionary Demand ---
    /**
     * Elasticity of government guarding volume to the state spending index (0.30 = a 10% budget lift adds 3% contracted
     * posts).
     */
    public const APPROPRIATIONS_VOLUME_SCALAR = 0.30;
    /**
     * Elasticity of corporate retainer posts to the output gap at unit cyclicality, both ways (new facilities need
     * guards, and closed ones release them).
     */
    public const CORPORATE_EXPANSION_SCALAR = 0.40;

    /**
     * Expeditionary demand per unit of market volatility against its anchor, both ways: panic buys executive
     * protection, and calm markets let the contracts lapse.
     */
    public const FEAR_PREMIUM_SCALAR = 0.80;
    /** Expeditionary and investigation demand per unit of the credit spread against its measured trend, both ways. */
    public const CREDIT_STRESS_SCALAR = 4.00;

    // --- Cash Rounds ---
    /** Cash handled per unit of the domestic demand gap, both ways: the shops' takings. */
    public const CASH_SPENDING_SENSITIVITY = 0.50;
    /**
     * Cash handled per unit of the interbank spread over its trend, both ways: a 1pp funding scare adds 3% as
     * households draw their savings out in notes.
     */
    public const CASH_HOARDING_SENSITIVITY = 3.0;
    /**
     * Annual drift of the cash rounds against the rest of the industry (-2%): cards take a share of payments each
     * year, offset in part by banks handing their own cash work to carriers.
     */
    public const CASH_USE_DRIFT = -0.02;

    // --- Vaults ---
    /** Share of vault fees charged on the value of the bullion kept (50%); the rest is rent and shipments. */
    public const BULLION_FEE_SHARE = 0.50;

    // --- Screening & Investigations ---
    /**
     * Screening work per unit of deal activity over its trend, both ways (0.15): each deal and new client is checked.
     */
    public const SCREENING_DEAL_SCALAR = 0.15;
    /**
     * Investigation cases per unit of the corporate default rate over its trend, both ways: a 1pp rise adds 3%, as
     * busts uncover the frauds a boom hid.
     */
    public const FRAUD_DISCOVERY_SCALAR = 3.0;

    // --- Asymmetric Tail Risk Events ---
    /** Z-score threshold indicating a catastrophic, highly publicized tactical failure or VIP assassination. */
    public const TACTICAL_FAILURE_Z_SCORE = -2.50;
    /** Revenue haircut applied due to immediate contract terminations following a security breach. */
    public const TACTICAL_FAILURE_REV_MULT = 0.85;
    /** Variable cost penalty applied to fund legal settlements, fines, and crisis PR. */
    public const TACTICAL_FAILURE_PENALTY = 0.12;

    /** Z-score threshold indicating a massive sovereign collapse or supply chain conflict. */
    public const GEOPOLITICAL_CONFLICT_Z_SCORE = 2.40;
    /** Revenue multiplier applied to the expeditionary stream during active geopolitical conflicts. */
    public const GEOPOLITICAL_CONFLICT_MULT = 1.40;

    /** Z-score below which a carrier that does only cash and vault work is robbed (1% a quarter, once in 25 years). */
    public const HEIST_Z_SCORE = -2.33;
    /** Charge on the quarter's cash and vault revenue for the uninsured loss, the legal bill and dearer cover (3%). */
    public const HEIST_CHARGE = 0.03;

    /**
     * Z-score below which a firm that only investigates has a file leak or a case go wrong in public (0.6% a quarter).
     */
    public const LEAK_Z_SCORE = -2.50;
    /** Screening and investigation revenue kept in the quarter a leak sends clients to rivals (85%). */
    public const LEAK_REV_MULT = 0.85;
    /** Charge on the quarter's screening and investigation revenue for claims and the regulator's fine (12%). */
    public const LEAK_CHARGE = 0.12;

    /**
     * Trend real growth plus the sector's measured drift in its share of GDP, less the cards' drift on its cash rounds.
     */
    public function getSecularGrowthRate(Stock $stock): float
    {
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);

        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS)
            + ($total > 0.0 ? ($weights['cash_services'] / $total) * self::CASH_USE_DRIFT : 0.0);
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Security is a survival expense. We nullify the global demand shift to prevent double-dipping,
        // allowing each stream to pull directly from its assigned macro variables in calculateSectorPhysics.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /**
     * The posts, crews and caseload the firm is staffed for: each line's macro volume shift at its share of the plan.
     * Price effects stay out (dearer gold moves no staff); the expeditionary and investigation surges on stress are
     * counter-cyclical and keep their sign.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $shifts = $this->resolveStreamMacroShifts($stock, $macroState);
        $activity = 0.0;
        foreach ($this->resolveLineShares($stock, $macroState->totalTime) as $key => $share) {
            $activity += $share * $shifts[$key];
        }

        return $activity;
    }

    /**
     * Each line's macro volume shift, each against where its driver settles, both ways, because the average year is
     * already in the baseline.
     *
     * @return array<string, float>
     */
    private function resolveStreamMacroShifts(Stock $stock, MacroStateDTO $macroState): array
    {
        $govSpendShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;
        $fearPremium = ($macroState->marketVolatilityEma - MacroConstants::MACRO_VOL_BASE_ANCHOR) * self::FEAR_PREMIUM_SCALAR;
        $creditDistressPremium = ($macroState->macroCreditSpreadEma - $macroState->macroCreditSpreadTrend) * self::CREDIT_STRESS_SCALAR;
        $fundingScare = $macroState->interbankLiquiditySpreadEma - $macroState->interbankLiquiditySpreadTrend;
        $defaultSurge = $macroState->corporateDefaultRateEma - $macroState->corporateDefaultRateTrend;

        return [
            'government_contracts' => $govSpendShift * self::APPROPRIATIONS_VOLUME_SCALAR,
            'corporate_retainers'  => $macroState->outputGapEma * self::CORPORATE_EXPANSION_SCALAR * $this->getOperatingCyclicality($stock),
            'expeditionary_ops'    => $fearPremium + $creditDistressPremium,
            'cash_services'        => ($macroState->domesticDemandGapEma * self::CASH_SPENDING_SENSITIVITY) + ($fundingScare * self::CASH_HOARDING_SENSITIVITY),
            'vaults'               => 0.0,
            'screening'            => $macroState->dealActivityDeviation() * self::SCREENING_DEAL_SCALAR,
            'investigations'       => ($defaultSurge * self::FRAUD_DISCOVERY_SCALAR) + $creditDistressPremium,
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $sold = array_filter($this->resolveLineShares($stock, $macroState->totalTime), static fn(float $share): bool => $share > 0.0);
        $weights = $streams->resolveActiveStreamWeights($sold);
        $macroShifts = $this->resolveStreamMacroShifts($stock, $macroState);
        $exposure = static fn(string ...$keys): float => array_sum(array_map(static fn(string $key): float => $weights[$key] ?? 0.0, $keys));
        $has = static fn(string ...$keys): bool => $exposure(...$keys) > 0.0;

        // Each line's own draw, in a fixed order, for the lines the firm sells.
        $z = [];
        foreach (['government_contracts' => 0.60, 'corporate_retainers' => 0.40, 'expeditionary_ops' => 0.10] as $key => $phi) {
            $z[$key] = $has($key) ? $streams->generateZ($key, $phi) : 0.0;
        }
        $eventZ = $has('corporate_retainers', 'expeditionary_ops') ? $streams->generateExogenousZ('event', 0.05) : 0.0;
        $z['cash_services'] = $has('cash_services') ? $streams->generateZ('cash_services', 0.60) : 0.0;
        $z['vaults'] = $has('vaults') ? $streams->generateZ('vaults', 0.40) : 0.0;
        $heistZ = $has('cash_services', 'vaults') ? $streams->generateExogenousZ('heist', 0.0) : 0.0;
        $z['screening'] = $has('screening') ? $streams->generateZ('screening', 0.40) : 0.0;
        $z['investigations'] = $has('investigations') ? $streams->generateZ('investigations', 0.20) : 0.0;
        $leakZ = $has('screening', 'investigations') ? $streams->generateExogenousZ('leak', 0.0) : 0.0;

        // --- Asymmetric Tail Risk Events ---
        $multipliers = array_fill_keys(array_keys($weights), 1.0);
        $eventType = null;
        $isBreach = $has('corporate_retainers') && $eventZ < self::TACTICAL_FAILURE_Z_SCORE;
        if ($isBreach) {
            $eventType = ShockEvent::SECURITY_BREACH;
            // Publicized failure causes client flight
            $multipliers['corporate_retainers'] = self::TACTICAL_FAILURE_REV_MULT;
        } elseif ($has('expeditionary_ops') && $eventZ > self::GEOPOLITICAL_CONFLICT_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_CONFLICT;
            $multipliers['expeditionary_ops'] = self::GEOPOLITICAL_CONFLICT_MULT; // Conflicts explode PMC demand
        }
        // A leak or a heist is as likely as the firm is exposed: its hazard is the threshold's at the share of revenue
        // it sells in the lines the event hits.
        $isLeak = $has('screening', 'investigations') && $this->strikes($leakZ, self::LEAK_Z_SCORE, $exposure('screening', 'investigations'));
        if ($isLeak) {
            $eventType ??= ShockEvent::CLIENT_FILE_LEAK;
            foreach (array_intersect_key($multipliers, ['screening' => true, 'investigations' => true]) as $key => $multiplier) {
                $multipliers[$key] = self::LEAK_REV_MULT;
            }
        }
        $isHeist = $has('cash_services', 'vaults') && $this->strikes($heistZ, self::HEIST_Z_SCORE, $exposure('cash_services', 'vaults'));
        if ($isHeist) {
            $eventType ??= ShockEvent::CASH_HEIST;
        }

        $variance = [
            'government_contracts' => self::GOVERNMENT_VARIANCE_SCALAR,
            'corporate_retainers'  => self::RETAINER_VARIANCE_SCALAR,
            'expeditionary_ops'    => self::EXPEDITIONARY_VARIANCE_SCALAR,
            'cash_services'        => self::CASH_VARIANCE_SCALAR,
            'vaults'               => self::VAULT_VARIANCE_SCALAR,
            'screening'            => self::SCREENING_VARIANCE_SCALAR,
            'investigations'       => self::INVESTIGATIONS_VARIANCE_SCALAR,
        ];
        $volumes = [];
        foreach ($weights as $key => $weight) {
            $volumes[$key] = max(0.0, $expectedRevenue * $weight * (1.0 + ($z[$key] * $baselineVol * $variance[$key]) + $macroShifts[$key]) * $multipliers[$key]);
        }

        // The vaults charge part of their fee on the value of the metal kept; every other line sells at its volume.
        $vaultValue = $this->resolveVaultValue($macroState);
        $streamRevenues = $volumes;
        if (isset($streamRevenues['vaults'])) {
            $streamRevenues['vaults'] *= $vaultValue;
        }
        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Cost & Margin Physics --- Staff, vans and buildings cost the work done, so dearer gold in the vaults
        // costs nothing more to keep. Guard payroll follows wage growth; contract rates recover most of it (cost-plus
        // government work, annual retainer resets).
        $costRatio = $actualRevenue > 0.0 ? $realizedVariableMargin * array_sum($volumes) / $actualRevenue : $realizedVariableMargin;
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $clampedMargin = $this->clampMargin($costRatio + $inputCostDrag);
        $this->registerPublicSpendingExposure($streams, $expectedRevenue * ($weights['government_contracts'] ?? 0.0) * self::APPROPRIATIONS_VOLUME_SCALAR, $clampedMargin, $actualRevenue);

        $specialCharge = ($isBreach ? self::TACTICAL_FAILURE_PENALTY * $actualRevenue : 0.0)
            + ($isLeak ? self::LEAK_CHARGE * (($streamRevenues['screening'] ?? 0.0) + ($streamRevenues['investigations'] ?? 0.0)) : 0.0)
            + ($isHeist ? self::HEIST_CHARGE * (($streamRevenues['cash_services'] ?? 0.0) + ($streamRevenues['vaults'] ?? 0.0)) : 0.0);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            specialCharge: $specialCharge,
            kpis: ($has('cash_services') ? ['cash_volume_index' => 1.0 + $macroShifts['cash_services']] : [])
                + ($has('vaults') ? ['vault_value_index' => $vaultValue] : []),
        );
    }

    /**
     * Whether an event strikes: its draw falls in the threshold's tail, thinned to the firm's exposure to the lines.
     */
    private function strikes(float $z, float $threshold, float $exposure): bool
    {
        return Distributions::standardNormalCdf($z) < Distributions::standardNormalCdf($threshold) * $exposure;
    }

    /** Vault fees per unit of plan: the bullion share at the gold price, the rest at its contracted rent. */
    private function resolveVaultValue(MacroStateDTO $macroState): float
    {
        $gold = max(0.0, $macroState->goldPriceIndexEma) / MacroConstants::GOLD_BASELINE;

        return 1.0 + (self::BULLION_FEE_SHARE * ($gold - 1.0));
    }

    /**
     * Each line's share of the plan. The plan compounds the cards' drift on the cash rounds, faded as the firm's
     * secular growth is; every other line grows with the industry, so its share rises by however far that drift has
     * left the plan below the industry's path, and the cash rounds take the rest.
     *
     * @return array<string, float>
     */
    public function resolveLineShares(Stock $stock, float $simYears): array
    {
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return array_map(static fn(): float => 0.0, $weights);
        }

        $cashShare = $weights['cash_services'] / $total;
        $planOverIndustry = exp(TimeSeries::fadedExcessIntegral($cashShare * self::CASH_USE_DRIFT, 0.0, $simYears, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS));
        $others = min(1.0, (1.0 - $cashShare) / $planOverIndustry);

        $shares = [];
        foreach ($weights as $key => $weight) {
            $shares[$key] = $key === 'cash_services'
                ? 1.0 - $others
                : ($cashShare < 1.0 ? $others * ($weight / $total) / (1.0 - $cashShare) : 0.0);
        }

        return $shares;
    }

    /**
     * @return array{government_contracts: float, corporate_retainers: float, expeditionary_ops: float, cash_services: float, vaults: float, screening: float, investigations: float}
     */
    private function resolveLineWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::GovernmentContractWeight->value => self::GOVERNMENT_CONTRACT_WEIGHT,
            ModelParam::RetainerWeight->value           => self::RETAINER_WEIGHT,
            ModelParam::ExpeditionaryWeight->value      => self::EXPEDITIONARY_WEIGHT,
            ModelParam::CashServicesWeight->value       => self::CASH_SERVICES_WEIGHT,
            ModelParam::VaultsWeight->value             => self::VAULTS_WEIGHT,
            ModelParam::ScreeningWeight->value          => self::SCREENING_WEIGHT,
            ModelParam::InvestigationsWeight->value     => self::INVESTIGATIONS_WEIGHT,
        ]);

        return [
            'government_contracts' => max(0.0, (float) $params[ModelParam::GovernmentContractWeight]),
            'corporate_retainers'  => max(0.0, (float) $params[ModelParam::RetainerWeight]),
            'expeditionary_ops'    => max(0.0, (float) $params[ModelParam::ExpeditionaryWeight]),
            'cash_services'        => max(0.0, (float) $params[ModelParam::CashServicesWeight]),
            'vaults'               => max(0.0, (float) $params[ModelParam::VaultsWeight]),
            'screening'            => max(0.0, (float) $params[ModelParam::ScreeningWeight]),
            'investigations'       => max(0.0, (float) $params[ModelParam::InvestigationsWeight]),
        ];
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
            'corporate_default_rate_ema',
            'deal_activity_index_ema',
            'domestic_demand_gap_ema',
            'exchange_rate_index_ema',
            'gold_price_index_ema',
            'government_spending_index_ema',
            'import_price_level',
            'interbank_liquidity_spread_ema',
            'macro_credit_spread_ema',
            'market_volatility_ema',
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}

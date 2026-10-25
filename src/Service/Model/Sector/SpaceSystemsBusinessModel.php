<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\Aggregate\OpenEconomy;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\ResponseCurves;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for space companies that build and fly their own rockets and run a satellite internet service.
 *
 * Financial Physics:
 * - Launch Services: satellites booked onto the manifest fly over the following year. Reusable rockets opened a launch
 *   market that price had rationed, so launch demand outgrows the economy for years, and each launch flown teaches the
 *   firm to fly the next one cheaper (Wright's law); competition passes part of the saving on.
 * - Government Missions: cargo, crew and defence payloads for the mainland's and allied agencies, awarded into a
 *   backlog that follows their budgets.
 * - Satellite Internet: a constellation opens a few years after the listing, and subscribers sign up along a Bass
 *   diffusion curve until it is the larger business. Each extra subscriber costs little to serve.
 * - Growth: the firm's expected growth is the growth of the three lines along their expected paths, so its plant, its
 *   capital budget and the market's multiple all read the same S-curve; sign-ups ahead of or behind it are the
 *   surprise.
 * - Launch Failures: a rocket lost grounds the fleet while the cause is found; the manifest waits and flies off when
 *   the fleet returns.
 */
class SpaceSystemsBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (0.4): satellite operators and subscribers are worldwide. */
    public const OPERATING_CYCLICALITY = 0.40;
    /** Own-price elasticity of demand: a cheaper launch fills more of the manifest. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers: launches are won from foreign rockets. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.40;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::SPACE_SYSTEMS;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate (35%), at the opening mix: the
     * half of the launch manifest booked by customers abroad, who can fly on foreign rockets.
     */
    public const FX_REVENUE_EXPOSURE = 0.35;
    /** Share of the launch manifest booked by customers abroad (50%). */
    public const LAUNCH_EXPORT_SHARE = 0.50;

    // --- Pricing ---
    /** Pricing leverage of the cheapest rocket in the market. */
    public const PRICING_POWER_INDEX = 0.60;

    // --- Balance Sheet Realism ---
    /** Capitalized leases as a fraction of annual revenue (3%): the pads, factories and ground stations are owned. */
    public const LEASE_LIABILITY_INTENSITY = 0.03;
    /** Stock-based compensation as a fraction of revenue (3%): engineers are paid partly in shares. */
    public const STOCK_COMPENSATION_INTENSITY = 0.03;

    // --- Analyst Visibility & Error ---
    /** Share of routine shocks analysts see (40%): the manifest is public, the sign-ups are not. */
    public const BASE_COVERAGE_VISIBILITY = 0.40;
    /** Standard deviation of the consensus forecast error (0.10): a young business in a new market. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Lines of Business ---
    /** Fraction of revenue from launching other people's satellites at the listing. */
    public const LAUNCH_SERVICES_WEIGHT = 0.70;
    /** Fraction of revenue from government cargo, crew and defence missions at the listing. */
    public const GOVERNMENT_MISSIONS_WEIGHT = 0.30;

    // --- Launch Market ---
    /**
     * Real growth of launch demand above trend at the listing (6%): reusable rockets cut the price of reaching orbit,
     * and the satellites that price had kept on the ground are booked.
     */
    public const LAUNCH_EXCESS_GROWTH = 0.06;
    /** Half-life of the launch market's excess growth (6 years). */
    public const LAUNCH_EXCESS_HALF_LIFE_YEARS = 6.0;
    /** Fraction of the launch manifest flown each quarter (25%): a satellite is booked about a year ahead. */
    public const LAUNCH_BURN_RATE = 0.25;
    /** Launch bookings per unit of the world activity gap: satellite operators invest with the world economy. */
    public const LAUNCH_WORLD_DEMAND_SENSITIVITY = 1.0;

    // --- Reusable Rockets ---
    /** Cost of a launch per doubling of the launches flown (85%): aerospace's learning rate (Wright 1936). */
    public const LAUNCH_LEARNING_RATE = 0.85;
    /** Launches flown before the listing, in years of the listing's launch rate. */
    public const INITIAL_LAUNCH_EXPERIENCE_YEARS = 2.0;
    /** Floor on a launch's cost against the listing's (60%): engines, fuel and recovery do not learn away. */
    public const LAUNCH_COST_FLOOR = 0.60;
    /** Share of the saving on a launch competition passes to customers in lower prices (50%). */
    public const LAUNCH_PRICE_PASS_THROUGH = 0.50;

    // --- Government Missions ---
    /** Fraction of the government backlog executed each quarter (15%): multi-year mission contracts. */
    public const GOVERNMENT_BURN_RATE = 0.15;

    // --- Satellite Internet ---
    /** Years after the listing the constellation opens to subscribers. */
    public const BROADBAND_SERVICE_START_YEARS = 4.0;
    /** Bass coefficient of innovation (0.03 a year): Sultan, Farley & Lehmann (1990), mean of 213 applications. */
    public const BROADBAND_INNOVATION = 0.03;
    /** Bass coefficient of imitation (0.38 a year), from the same meta-analysis. */
    public const BROADBAND_IMITATION = 0.38;
    /**
     * Satellite internet revenue at saturation against the firm's revenue at the listing, in real terms (2x): it ends
     * the larger business, a little over half of revenue twenty years on.
     */
    public const BROADBAND_MATURE_SIZE = 2.0;
    /** Variable cost of a subscriber per dollar against the firm's average (0.75x): the satellites are already up. */
    public const BROADBAND_COST_MULTIPLE = 0.75;

    // --- Launch Failures ---
    /** Regime key for a fleet grounded after a launch failure (two-state Markov). */
    public const REGIME_GROUNDED = 'fleet_grounded';
    /** Quarterly probability a launch fails at the listing (4%): a young rocket, about one every six years. */
    public const LAUNCH_FAILURE_HAZARD = 0.04;
    /**
     * Failure rate per doubling of the launches flown (75%): reliability grows with experience as cost falls (Duane
     * 1964), so failures cluster in the early years.
     */
    public const RELIABILITY_LEARNING_RATE = 0.75;
    /** Quarterly probability the fleet returns to flight (67%): about a quarter and a half on the ground. */
    public const RETURN_TO_FLIGHT_HAZARD = 0.67;
    /** Share of the manifest that does not fly while the fleet is grounded (80%). */
    public const GROUNDED_LAUNCH_SHARE = 0.80;

    // --- Revenue Volatility ---
    /** Volatility multiplier on launch bookings: a constellation booked or cancelled. */
    public const LAUNCH_VARIANCE_SCALAR = 0.30;
    /** Volatility multiplier on government awards. */
    public const GOVERNMENT_VARIANCE_SCALAR = 0.15;
    /** Volatility multiplier on sign-ups against the expected curve: a rival constellation, a slow region. */
    public const BROADBAND_VARIANCE_SCALAR = 0.40;

    // --- Return Reversion ---
    /** Excess return over the required return the moat sustains (4pp): the only reusable rockets in the market. */
    public const MOAT_SPREAD = 0.04;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.3): the firm builds for its own growth. */
    public const CAPEX_CYCLICALITY = 0.30;
    /** Net working capital as a share of revenue (-5%): launch deposits and prepaid subscriptions. */
    public const WORKING_CAPITAL_INTENSITY = -0.05;

    // --- Payout ---
    /** Years ahead the firm looks for the fastest growth its earnings must still fund (10). */
    public const PAYOUT_LOOKAHEAD_YEARS = 10.0;

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (3x). */
    public const MIN_ICR = 3.0;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (2x). */
    public const DIVIDEND_CRISIS_ICR = 2.0;
    /** Interest coverage below which the firm stops repurchasing stock (3x). */
    public const BUYBACK_MIN_ICR = 3.0;

    // --- Persisted State ---
    /** Years since the listing, advanced a quarter per report. */
    public const STATE_YEARS_LISTED = 'state:years_listed';
    /** Launches flown since the listing, in years of the listing's launch rate. */
    public const STATE_LAUNCH_EXPERIENCE = 'state:launch_experience';

    /**
     * The secular rate the addressable market and the industry ledger read: they take it as the excess at the open and
     * fade it at SECULAR_EXCESS_HALF_LIFE_YEARS, so this is the opening excess that, faded so, grows the market to
     * where the three lines' expected paths stand now.
     */
    public function getSecularGrowthRate(Stock $stock): float
    {
        $years = self::yearsListed($stock);
        $levels = $this->resolveLineLevels($stock, $years);
        $opening = array_sum($this->resolveLineLevels($stock, 0.0));
        $unitFade = TimeSeries::fadedExcessIntegral(1.0, 0.0, $years, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS);
        if ($years <= 0.0 || $unitFade <= 0.0 || $opening <= 0.0) {
            return MacroConstants::TREND_REAL_GROWTH + $this->resolveExcessGrowth($stock, 0.0);
        }

        return MacroConstants::TREND_REAL_GROWTH + (log(array_sum($levels) / $opening) / $unitFade);
    }

    /**
     * The growth the plant, the capital budget and the market's multiple read: the lines' expected growth now. The
     * S-curve is its own fade, so no second fade is laid over it.
     */
    public function getFadedSecularGrowthRate(Stock $stock, float $simYears): float
    {
        return MacroConstants::TREND_REAL_GROWTH + $this->resolveExcessGrowth($stock, self::yearsListed($stock));
    }

    /**
     * The share of earnings the firm cannot put to work: what the growth identity g = reinvestment x return on capital
     * (Damodaran) leaves of them at the fastest growth still ahead. Nothing while the constellation is still to be
     * built; as the sign-ups slow, the life-cycle payout opens.
     */
    public function getUndeployableCapitalShare(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        $return = (float) $stock->getBaselineRoic();
        if ($return <= 0.0) {
            return 0.0;
        }
        $years = self::yearsListed($stock);
        $fastest = 0.0;
        for ($ahead = 0.0; $ahead <= self::PAYOUT_LOOKAHEAD_YEARS; $ahead += FinancialConstants::QUARTER_YEARS) {
            $fastest = max($fastest, $this->resolveExcessGrowth($stock, $years + $ahead));
        }
        $reinvestment = (MacroConstants::TREND_REAL_GROWTH + $fastest + max(0.0, $macroState->inflationEma)) / $return;

        return max(0.0, min(1.0, 1.0 - $reinvestment));
    }

    private static function yearsListed(Stock $stock): float
    {
        return (float) (($stock->getEarningsMomentumZ() ?? [])[self::STATE_YEARS_LISTED] ?? 0.0);
    }

    /** Quarterly chance a launch fails: the listing's rate, falling with each doubling of the launches flown. */
    public static function launchFailureHazard(float $experience): float
    {
        return self::LAUNCH_FAILURE_HAZARD * (self::RELIABILITY_LEARNING_RATE ** self::launchDoublings($experience));
    }

    /** Doublings of the launches flown since the listing's experience. */
    private static function launchDoublings(float $experience): float
    {
        return log(max(self::INITIAL_LAUNCH_EXPERIENCE_YEARS, $experience) / self::INITIAL_LAUNCH_EXPERIENCE_YEARS, 2);
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Launches and missions reach revenue through their backlogs and subscribers through the curve, in
        // calculateSectorPhysics(), where the exchange rate prices the launches sold abroad.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /** The work the firm is staffed for: the launches and missions it has in hand, at their weights. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $levels = $this->resolveLineLevels($stock, (float) ($momentum[self::STATE_YEARS_LISTED] ?? 0.0));
        $total = array_sum($levels);
        if ($total <= 0.0) {
            return 0.0;
        }

        return (($levels['launch_services'] * (StreamContext::openingWorkload($momentum, 'launch_services', self::LAUNCH_BURN_RATE) - 1.0))
            + ($levels['government_missions'] * (StreamContext::openingWorkload($momentum, 'government_missions', self::GOVERNMENT_BURN_RATE) - 1.0))) / $total;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $years = $streams->getPersistedState(self::STATE_YEARS_LISTED, 0.0);
        $streams->registerState(self::STATE_YEARS_LISTED, $years + FinancialConstants::QUARTER_YEARS);

        // The plan splits across the lines as their expected levels stand.
        $levels = $this->resolveLineLevels($stock, $years);
        $total = array_sum($levels);
        $plan = $total > 0.0 ? $expectedRevenue / $total : 0.0;

        $launchZ = $streams->generateZ('launch_services', 0.30);
        $governmentZ = $streams->generateZ('government_missions', 0.50);
        $broadbandZ = $streams->generateZ('satellite_broadband', 0.90);

        // --- Launch failure: likelier for a young rocket; the fleet stays down while the cause is found, and the
        // manifest waits for it ---
        $experience = $streams->getPersistedState(self::STATE_LAUNCH_EXPERIENCE, self::INITIAL_LAUNCH_EXPERIENCE_YEARS);
        $groundedElapsed = $streams->evolveRegime(self::REGIME_GROUNDED, self::launchFailureHazard($experience), self::RETURN_TO_FLIGHT_HAZARD);

        // --- Launch services: bookings follow the world's satellite operators and the price against foreign rockets
        // ---
        $launchBookings = max(0.0, 1.0
            + ($launchZ * $baselineVol * self::LAUNCH_VARIANCE_SCALAR)
            + (self::LAUNCH_WORLD_DEMAND_SENSITIVITY * $macroState->worldActivityGap)
            + $this->resolveFxDemandShift($macroState, self::LAUNCH_EXPORT_SHARE));
        $launch = $streams->recognizeBacklog(
            'launch_services',
            $plan * $levels['launch_services'],
            $launchBookings,
            self::LAUNCH_BURN_RATE,
            deferredShare: $groundedElapsed > 0 ? self::GROUNDED_LAUNCH_SHARE : 0.0,
        );

        // --- Government missions: awards out of the agencies' budgets, as the defence primes win theirs ---
        $awards = max(0.0, 1.0
            + ($governmentZ * $baselineVol * self::GOVERNMENT_VARIANCE_SCALAR)
            + (MacroConstants::ALLIED_PROCUREMENT_ELASTICITY * OpenEconomy::alliedDefenseGap($macroState->alliedDefenseSpendingIndexEma)));
        $government = $streams->recognizeBacklog('government_missions', $plan * $levels['government_missions'], $awards, self::GOVERNMENT_BURN_RATE);

        // --- Satellite internet: subscribers on the expected curve, ahead of it or behind ---
        $broadband = $plan * $levels['satellite_broadband'] * max(0.0, 1.0 + ($broadbandZ * $baselineVol * self::BROADBAND_VARIANCE_SCALAR));

        $streamRevenues = [
            'launch_services'     => $launch['revenue'],
            'government_missions' => $government['revenue'],
            'satellite_broadband' => $broadband,
        ];
        $actualRevenue = array_sum($streamRevenues);

        // --- Reusable rockets: each launch flown makes the next cheaper, and competition passes half the saving on ---
        $plannedLaunch = $plan * $levels['launch_services'];
        $flown = $plannedLaunch > 0.0 ? FinancialConstants::QUARTER_YEARS * ($levels['launch_services'] / self::LAUNCH_SERVICES_WEIGHT) * ($launch['revenue'] / $plannedLaunch) : 0.0;
        $streams->registerState(self::STATE_LAUNCH_EXPERIENCE, $experience + $flown);
        $launchCostRatio = self::launchCostRatio($experience);

        // --- Costs: launches at their learned cost, missions at the average, a subscriber for less ---
        $variableCosts = $realizedVariableMargin * (($launchCostRatio * $launch['revenue']) + $government['revenue'] + (self::BROADBAND_COST_MULTIPLE * $broadband));
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $this->clampMargin(($actualRevenue > 0.0 ? $variableCosts / $actualRevenue : $realizedVariableMargin) + $inputCostDrag),
            eventType: $groundedElapsed === 1 ? ShockEvent::LAUNCH_FAILURE : null,
            isPublicEvent: $groundedElapsed === 1 ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'broadband_penetration' => ResponseCurves::bassAdoption($years - self::BROADBAND_SERVICE_START_YEARS, self::BROADBAND_INNOVATION, self::BROADBAND_IMITATION),
                'launch_cost_index'     => self::learnedLaunchCost($experience),
            ],
        );
    }

    /**
     * A launch's cost against the listing's after the launches flown, on the learning curve to its floor:
     * (experience / initial)^log2(learning rate).
     */
    public static function learnedLaunchCost(float $experience): float
    {
        return max(self::LAUNCH_COST_FLOOR, self::LAUNCH_LEARNING_RATE ** self::launchDoublings($experience));
    }

    /** A launch's variable cost per dollar of launch revenue against the listing's: the cost learned over the price. */
    public static function launchCostRatio(float $experience): float
    {
        $cost = self::learnedLaunchCost($experience);

        return $cost / (1.0 - (self::LAUNCH_PRICE_PASS_THROUGH * (1.0 - $cost)));
    }

    /**
     * Each line's expected real level against the firm's revenue at the listing, before trend growth: launches on the
     * fading excess of their market, missions at trend, subscribers on the Bass curve.
     *
     * @return array{launch_services: float, government_missions: float, satellite_broadband: float}
     */
    private function resolveLineLevels(Stock $stock, float $years): array
    {
        $weights = $this->resolveLineWeights($stock);

        return [
            'launch_services'     => $weights['launch_services'] * exp(TimeSeries::fadedExcessIntegral(self::LAUNCH_EXCESS_GROWTH, 0.0, $years, self::LAUNCH_EXCESS_HALF_LIFE_YEARS)),
            'government_missions' => $weights['government_missions'],
            'satellite_broadband' => self::BROADBAND_MATURE_SIZE * ResponseCurves::bassAdoption($years - self::BROADBAND_SERVICE_START_YEARS, self::BROADBAND_INNOVATION, self::BROADBAND_IMITATION),
        ];
    }

    /** Growth above trend the three lines add along their expected paths: the log derivative of their sum. */
    private function resolveExcessGrowth(Stock $stock, float $years): float
    {
        $levels = $this->resolveLineLevels($stock, $years);
        $total = array_sum($levels);
        if ($total <= 0.0) {
            return 0.0;
        }
        $launchGrowth = $levels['launch_services'] * self::LAUNCH_EXCESS_GROWTH * (0.5 ** ($years / self::LAUNCH_EXCESS_HALF_LIFE_YEARS));
        $signUps = self::BROADBAND_MATURE_SIZE * ResponseCurves::bassAdoptionRate($years - self::BROADBAND_SERVICE_START_YEARS, self::BROADBAND_INNOVATION, self::BROADBAND_IMITATION);

        return ($launchGrowth + $signUps) / $total;
    }

    /**
     * @return array{launch_services: float, government_missions: float}
     */
    private function resolveLineWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::LaunchServicesWeight->value     => self::LAUNCH_SERVICES_WEIGHT,
            ModelParam::GovernmentMissionsWeight->value => self::GOVERNMENT_MISSIONS_WEIGHT,
        ]);

        return [
            'launch_services'     => (float) $params[ModelParam::LaunchServicesWeight],
            'government_missions' => (float) $params[ModelParam::GovernmentMissionsWeight],
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
            'allied_defense_spending_index_ema',
            'exchange_rate_index_ema',
            'import_price_level',
            'output_gap_ema',
            'real_wage_gap',
            'tips_breakeven_ema',
            'world_activity_gap',
        ];
    }
}

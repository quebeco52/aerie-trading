<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for an insurance broker: it places its clients' risks with insurers and is paid a share of the
 * premium, and it advises employers on their staff benefits.
 *
 * Financial Physics:
 * - Commissions are a share of the premium placed, so they follow the rate level the market's underwriters earn,
 *   read against its own multi-year trend: a hard market raises every renewal it places. Exposures (payrolls,
 *   property values, revenues insured) follow the economy at a low cyclicality.
 * - Benefits consulting and group placement follow employers' headcount.
 * - Fiduciary income: clients' premiums are held for a few months before they are passed to the insurer, earning the
 *   short rate, so this line follows the policy rate against the neutral rate and nearly vanishes at the zero bound.
 * - No underwriting risk and little capital: a people business with high margins and sticky renewals.
 */
class InsuranceBrokerBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Insured exposures and
     * headcounts move with the economy, but every business renews its cover in a recession.
     */
    public const OPERATING_CYCLICALITY = 0.40;
    /** Own-price elasticity of demand: cover is compulsory or contractual for most clients, so few drop it. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.20;

    // --- Input Cost Basket ---
    /** A broker buys what a professional services firm buys: its people and their offices. */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::CONSULTING;

    // --- Pricing Power ---
    /**
     * Commission rates are set by custom and negotiated on the largest accounts; the premium they ride is the lever.
     */
    public const PRICING_POWER_INDEX = 0.50;

    // --- Balance Sheet Realism ---
    /** Capitalized office leases as a fraction of annual revenue (8%): brokers sit in the financial centre. */
    public const LEASE_LIABILITY_INTENSITY = 0.08;

    // --- Analyst Visibility & Error ---
    /** Share of routine shocks analysts see (60%): renewals are known a year ahead and organic growth is guided. */
    public const BASE_COVERAGE_VISIBILITY = 0.60;
    public const BASE_COVERAGE_ERROR = 0.04;

    // --- Return Reversion ---
    /** Excess return over the required return that client relationships sustain (3pp): renewals run above 90%. */
    public const MOAT_SPREAD = 0.03;

    // --- Revenue Streams ---
    /** Commissions and fees on the property, casualty and reinsurance premium placed (70%). */
    public const COMMISSION_WEIGHT = 0.70;
    /** Employee benefits consulting and group placement (25%). */
    public const BENEFITS_WEIGHT = 0.25;
    /** Interest on the clients' premiums held before they are passed on, at the neutral rate (5%). */
    public const FIDUCIARY_WEIGHT = 0.05;

    // --- Revenue Noise ---
    /** Volatility multiplier for commission revenue: a few large accounts change hands each year. */
    public const COMMISSION_VARIANCE_SCALAR = 0.08;
    /** Volatility multiplier for benefits work, renewed with employers' plan years. */
    public const BENEFITS_VARIANCE_SCALAR = 0.06;

    // --- Commissions ---
    /**
     * Share of a move in premium rates that reaches commissions (70%): the largest clients pay a negotiated fee instead
     * of a share of their premium.
     */
    public const COMMISSION_RATE_PASS_THROUGH = 0.70;

    // --- Working Capital ---
    /** Commissions are collected with the premium, a quarter after the work; fiduciary balances are held apart. */
    public const BROKER_WORKING_CAPITAL = ['receivables' => 0.25, 'inventory' => 0.0, 'payables' => 0.08];

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $activeWeights = $streams->resolveActiveStreamWeights([
            'commissions' => self::COMMISSION_WEIGHT,
            'benefits'    => self::BENEFITS_WEIGHT,
            'fiduciary'   => self::FIDUCIARY_WEIGHT,
        ]);

        $commissionZ = $streams->generateZ('commissions', 0.30);
        $benefitsZ   = $streams->generateZ('benefits', 0.40);

        $streamRevenues = [
            'commissions' => max(0.0, $expectedRevenue * $activeWeights['commissions'] * (1.0 + ($commissionZ * $baselineVol * self::COMMISSION_VARIANCE_SCALAR) + (self::COMMISSION_RATE_PASS_THROUGH * $macroState->insuranceRateDeviation()))),
            'benefits'    => max(0.0, $expectedRevenue * $activeWeights['benefits'] * (1.0 + ($benefitsZ * $baselineVol * self::BENEFITS_VARIANCE_SCALAR))),
            'fiduciary'   => max(0.0, $expectedRevenue * $activeWeights['fiduciary'] * $this->resolveFiduciaryYieldRatio($macroState)),
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $this->clampMargin($realizedVariableMargin + $inputCostDrag),
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    /**
     * The policy rate the held premiums earn, over the neutral rate the line's weight was struck at; never negative.
     */
    private function resolveFiduciaryYieldRatio(MacroStateDTO $macroState): float
    {
        return $macroState->perceivedNeutralRate > 0.0 ? max(0.0, $macroState->policyRateEma / $macroState->perceivedNeutralRate) : 1.0;
    }

    /** Commissions are billed with the premium and collected about a quarter later. */
    protected function resolveWorkingCapitalShares(Stock $stock): array
    {
        return self::BROKER_WORKING_CAPITAL;
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
            'exchange_rate_index_ema',
            'import_price_level',
            'insurance_rate_level',
            'insurance_rate_trend',
            'output_gap_ema',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'real_wage_gap',
            'tips_breakeven_ema',
        ];
    }
}

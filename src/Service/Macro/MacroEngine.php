<?php

namespace App\Service\Macro;

use Psr\Log\LoggerInterface;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;

class MacroEngine
{
    public const REDIS_MACRO_STATE = 'macroeconomic_state';

    public const TARGET_INFLATION = 0.02;
    public const NATURAL_RATE = 0.02;
    public const BASE_CORPORATE_TAX_RATE = 0.21;
    public const BASE_EQUITY_RISK_PREMIUM = 0.045;
    public const CASH_YIELD_SPREAD = 0.0025;

    // KALDOR-KALECKI CONSTANTS
    public const KALDOR_MOMENTUM = 0.20;
    public const KALDOR_CAPACITY = 150.0;
    public const KALDOR_MONETARY_DRAG = 1.5;

    // SVJJ JUMP DIFFUSION CONSTANTS
    public const SVJJ_LAMBDA = 0.80;
    public const SVJJ_P_UP = 0.10;
    public const SVJJ_ETA_UP = 10.0;
    public const SVJJ_ETA_DOWN = 5.0;
    public const SVJJ_MU_V = 0.05;

    // MACRO SHOCK CONSTANTS
    public const SHOCK_BASE_PROBABILITY = 0.250;

    // Relative weights for different macro shocks (makes it easy to add new events)
    public const MACRO_SHOCK_WEIGHTS = [
        ShockEvent::GLOBAL_RECESSION => 1.0,
        ShockEvent::INFLATION_CRISIS => 1.0,
        ShockEvent::EMERGENCY_STIMULUS => 1.0,
        ShockEvent::SURPRISE_STRONG_ECONOMY => 2.0,
    ];

    public const SHOCK_RECESSION_IMPACT = 0.02;
    public const SHOCK_INFLATION_IMPACT = 0.015;
    public const SHOCK_STIMULUS_IMPACT = 0.015;
    public const SHOCK_STRONG_ECONOMY_GAP_IMPACT = 0.01;
    public const SHOCK_STRONG_ECONOMY_INFLATION_IMPACT = 0.01;

    // YIELD WEIGHTS
    public const BORROWING_POLICY_WEIGHT = 0.70;
    public const BORROWING_YIELD5Y_WEIGHT = 0.30;

    public function __construct(
        private MathUtility $mathUtility,
        private LoggerInterface $logger,
        private \Redis $redis
    ) {}

    public function getLiveState(): MacroState
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        return $rawState ? MacroState::fromArray(json_decode($rawState, true)) : new MacroState();
    }

    /**
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     */
    public function updateMacroState(float $dt): array
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        $state = $rawState ? MacroState::fromArray(json_decode($rawState, true)) : new MacroState();

        $stressMultiplier = 1.0 + (abs($state->outputGap) * 10.0);

        $state->targetRate = $this->calculateTargetRate($state, self::TARGET_INFLATION, self::NATURAL_RATE);
        $state->policyRate = $this->updatePolicyRate($state, $state->targetRate, $dt);

        $yieldData = $this->calculateYieldCurveAndQE($state, self::TARGET_INFLATION, self::NATURAL_RATE);

        $state->yield2y = $yieldData['yield_2y'];
        $state->yield5y = $yieldData['yield_5y'];
        $state->yield10y = $yieldData['yield_10y'];
        $state->yield30y = $yieldData['yield_30y'];
        $state->nsLevel = $yieldData['level'];
        $state->nsCurvature = $yieldData['curvature'];
        $state->qeActive = $yieldData['qe_suppression'] > 0;

        $state->nsSlope = $state->yield10y - $state->policyRate;

        $state->marketZ = $this->mathUtility->generateStandardNormal();

        $state->outputGap = $this->calculateOutputGap($state, $state->yield5y, self::NATURAL_RATE, $stressMultiplier, $dt);
        $state->inflation = $this->calculateInflation($state, self::TARGET_INFLATION, $stressMultiplier, $dt);
        $state->marketVolatility = $this->calculateMarketVolatility($state, $dt);

        $this->calculatePotentialAndNominalGdp($state, self::NATURAL_RATE, $dt);
        $this->updateExponentialMovingAverages($state, $dt);
        $this->calculateDynamicFiscalPolicy($state);
        $this->calculateEquityRiskPremium($state);
        $this->generateMacroShocks($state, $dt);

        $payload = $state->toArray();
        $this->redis->set(self::REDIS_MACRO_STATE, json_encode($payload));
        return $payload;
    }

    public function recordMacroSnapshot(array $macroState, \Doctrine\DBAL\Connection $conn): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "INSERT INTO macro_report (recorded_at, inflation, inflation_ema, output_gap, output_gap_ema, policy_rate, policy_rate_ema, yield2y, yield2y_ema, yield5y, yield5y_ema, yield10y, yield10y_ema, yield30y, yield30y_ema, corporate_tax_rate, equity_risk_premium, nominal_gdp_index, market_volatility) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $now,
                $macroState['inflation'],
                $macroState['inflation_ema'] ?? $macroState['inflation'],
                $macroState['output_gap'],
                $macroState['output_gap_ema'] ?? $macroState['output_gap'],
                $macroState['policy_rate'],
                $macroState['policy_rate_ema'] ?? $macroState['policy_rate'],
                $macroState['yield_2y'],
                $macroState['yield_2y_ema'] ?? $macroState['yield_2y'],
                $macroState['yield_5y'],
                $macroState['yield_5y_ema'] ?? $macroState['yield_5y'],
                $macroState['yield_10y'],
                $macroState['yield_10y_ema'] ?? $macroState['yield_10y'],
                $macroState['yield_30y'],
                $macroState['yield_30y_ema'] ?? $macroState['yield_30y'],
                $macroState['corporate_tax_rate'] ?? self::BASE_CORPORATE_TAX_RATE,
                $macroState['equity_risk_premium'],
                $macroState['nominal_gdp_index'],
                $macroState['market_volatility']
            ]
        );
    }

    private function calculateTargetRate(MacroState $state, float $targetInflation, float $naturalRate): float
    {
        $trendInflation = $state->inflationEma;

        if ($state->outputGap < 0.0) {
            $gapWeight = 0.5 + min(0.5, abs($state->outputGap) * 10.0);
        } else {
            $gapWeight = 0.15; // Benign neglect during a boom
        }

        $targetRate = $naturalRate + $trendInflation
            + 0.5 * ($trendInflation - $targetInflation)
            + $gapWeight * ($state->outputGap);

        return max(0.00, min(0.15, $targetRate));
    }

    private function updatePolicyRate(MacroState $state, float $targetRate, float $dt): float
    {
        $currentPolicyRate = $state->policyRate;
        $cbSpeed = 1.0;

        if ($targetRate > $currentPolicyRate) {
            $inflationExcess = max(0.0, $state->inflation - self::TARGET_INFLATION);
            $cbSpeed += min(4.0, $inflationExcess * 50.0);
        } else {
            $deflationPanic = max(0.0, self::TARGET_INFLATION - $state->inflation) * 50.0;
            $recessionPanic = max(0.0, -$state->outputGap) * 100.0;
            $cbSpeed += min(10.0, $deflationPanic + $recessionPanic);
        }

        $rawMove = $cbSpeed * ($targetRate - $currentPolicyRate);
        $clampedMove = max(-0.10, min(0.05, $rawMove));

        $newRate = $currentPolicyRate + $clampedMove * $dt;

        if ($targetRate > $currentPolicyRate) {
            return min($targetRate, $newRate);
        } else {
            return max($targetRate, $newRate);
        }
    }

    private function calculateYieldCurveAndQE(MacroState $state, float $targetInflation, float $naturalRate): array
    {
        $zlbProximity = min(1.0, max(0.0, (0.015 - $state->policyRate) / 0.015));
        $recessionSeverity = max(0.0, -$state->outputGap);
        $qeYieldSuppression = min(0.02, $zlbProximity * $recessionSeverity * 0.5);

        $expectedInflation = $state->inflationEma;
        $level = $naturalRate + (0.5 * $targetInflation) + (0.5 * $expectedInflation);
        $nsBeta1 = $state->policyRate - $level;
        $nsBeta2 = max(-0.01, 0.015 + ($state->outputGap * 0.25));

        $yield2y  = $this->calculateNelsonSiegelTenor(2.0, $level, $nsBeta1, $nsBeta2, $state, $qeYieldSuppression);
        $yield5y  = $this->calculateNelsonSiegelTenor(5.0, $level, $nsBeta1, $nsBeta2, $state, $qeYieldSuppression);
        $yield10y = $this->calculateNelsonSiegelTenor(10.0, $level, $nsBeta1, $nsBeta2, $state, $qeYieldSuppression);
        $yield30y = $this->calculateNelsonSiegelTenor(30.0, $level, $nsBeta1, $nsBeta2, $state, $qeYieldSuppression);

        return [
            'level' => $level,
            'curvature' => $nsBeta2,
            'qe_suppression' => $qeYieldSuppression,
            'yield_2y' => max(0.00, $yield2y),
            'yield_5y' => max(0.00, $yield5y),
            'yield_10y' => max(0.00, $yield10y),
            'yield_30y' => max(0.00, $yield30y)
        ];
    }

    private function calculateNelsonSiegelTenor(float $t, float $level, float $nsBeta1, float $nsBeta2, MacroState $state, float $qeYieldSuppression): float
    {
        $timeScale = sqrt($t / 10.0);
        $termPremium = (0.015 * $timeScale) + ($state->outputGap * 0.15 * $timeScale);

        $qeTimeScale = min(1.0, $timeScale);
        $qeTargetedSuppression = $qeYieldSuppression * $qeTimeScale;

        $pureYield = $this->mathUtility->calculateNelsonSiegelYield($level, $nsBeta1, $nsBeta2, $t);
        return $pureYield + $termPremium - $qeTargetedSuppression;
    }

    private function calculateOutputGap(MacroState $state, float $yield5y, float $naturalRate, float $stressMultiplier, float $dt): float
    {
        $y = $state->outputGap;
        $outZ = $this->mathUtility->generateStandardNormal();

        $borrowingCost = (self::BORROWING_POLICY_WEIGHT * $state->policyRate) + (self::BORROWING_YIELD5Y_WEIGHT * $yield5y);
        $realRate = $borrowingCost - $state->inflation;

        $momentum = self::KALDOR_MOMENTUM * $y;
        $cubicConstraint = self::KALDOR_CAPACITY * pow($y, 3);
        $monetaryDrag = self::KALDOR_MONETARY_DRAG * ($realRate - $naturalRate);

        $drift = ($momentum - $cubicConstraint - $monetaryDrag) * $dt;
        $volatility = 0.010 * $stressMultiplier * sqrt($dt) * $outZ;

        $newGap = $y + $drift + $volatility;

        return max(-0.12, min(0.10, $newGap));
    }

    private function calculateInflation(MacroState $state, float $targetInflation, float $stressMultiplier, float $dt): float
    {
        $infZ = $this->mathUtility->generateStandardNormal();
        $infDiff = $targetInflation - $state->inflation;
        $inflationDrift = 0.5 * $infDiff * $dt;

        $phillipsSlope = $state->outputGap * 0.50;

        if ($state->outputGap > 0.0) {
            $phillipsSlope += 0.5 * pow($state->outputGap, 2);
        }

        $phillipsEffect = $phillipsSlope * $dt;

        $newInflation = $state->inflation + $inflationDrift + $phillipsEffect + (0.005 * $stressMultiplier * sqrt($dt) * $infZ);
        return max(-0.02, min(0.25, $newInflation));
    }

    private function calculateMarketVolatility(MacroState $state, float $dt): float
    {
        $currentMarketVol = $state->marketVolatility;
        $longTermVol = 0.20;

        $currentVar = $currentMarketVol * $currentMarketVol;
        $longTermVar = $longTermVol * $longTermVol;

        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: self::SVJJ_LAMBDA,
            pUp: self::SVJJ_P_UP,
            etaUp: self::SVJJ_ETA_UP,
            etaDown: self::SVJJ_ETA_DOWN,
            muV: self::SVJJ_MU_V,
            dt: $dt
        );

        $expectedVarJump = (self::SVJJ_P_UP * self::SVJJ_MU_V * 0.5) + ((1.0 - self::SVJJ_P_UP) * self::SVJJ_MU_V);
        $jumpVarianceDrag = (self::SVJJ_LAMBDA * $expectedVarJump) / 3.0;
        $adjustedTheta = max(0.0001, $longTermVar - $jumpVarianceDrag);

        $nextVar = $this->mathUtility->calculateQEVarianceStep($currentVar, $adjustedTheta, 3.0, 0.30, $dt);
        $nextVar += $jumpData['var_jump'];

        return max(0.08, min(0.80, sqrt($nextVar)));
    }

    private function calculatePotentialAndNominalGdp(MacroState $state, float $naturalRate, float $dt): void
    {
        $nominalPotentialGrowth = $naturalRate + $state->inflation;
        $state->potentialGdpIndex = max(0.10, $state->potentialGdpIndex * exp($nominalPotentialGrowth * $dt));
        $state->nominalGdpIndex = $state->potentialGdpIndex * (1.0 + $state->outputGap);
    }

    private function updateExponentialMovingAverages(MacroState $state, float $dt): void
    {
        $emaWeight = min(1.0, $dt / 0.25);

        $state->outputGapEma += $emaWeight * ($state->outputGap - $state->outputGapEma);
        $state->policyRateEma += $emaWeight * ($state->policyRate - $state->policyRateEma);
        $state->inflationEma += $emaWeight * ($state->inflation - $state->inflationEma);
        $state->nsSlopeEma += $emaWeight * ($state->nsSlope - $state->nsSlopeEma);

        $state->yield2yEma += $emaWeight * ($state->yield2y - $state->yield2yEma);
        $state->yield5yEma += $emaWeight * ($state->yield5y - $state->yield5yEma);
        $state->yield10yEma += $emaWeight * ($state->yield10y - $state->yield10yEma);
        $state->yield30yEma += $emaWeight * ($state->yield30y - $state->yield30yEma);

        $state->marketVolatilityEma += $emaWeight * ($state->marketVolatility - $state->marketVolatilityEma);
    }

    private function calculateDynamicFiscalPolicy(MacroState $state): void
    {
        $fiscalPolicyTarget = self::BASE_CORPORATE_TAX_RATE + ($state->outputGapEma * 1.0);
        $state->corporateTaxRate = max(0.12, min(0.30, $fiscalPolicyTarget));
    }

    private function calculateEquityRiskPremium(MacroState $state): void
    {
        $erp = self::BASE_EQUITY_RISK_PREMIUM;

        if ($state->outputGapEma < 0.0) {
            $erp += abs($state->outputGapEma) * 0.5;
        } else {
            $erp -= $state->outputGapEma * 0.10;
        }

        $state->equityRiskPremium = max(0.02, $erp);
    }

    private function generateMacroShocks(MacroState $state, float $dt): void
    {
        $state->eventType = null;

        if ($this->mathUtility->checkProbability(self::SHOCK_BASE_PROBABILITY * $dt)) {
            $totalWeight = array_sum(self::MACRO_SHOCK_WEIGHTS);
            // $this->mathUtility->generateUniform() generates between 0.0 and 1.0, so multiply by totalWeight
            $roll = $this->mathUtility->generateUniform() * $totalWeight;

            $cumulativeWeight = 0.0;
            $selectedEvent = null;

            foreach (self::MACRO_SHOCK_WEIGHTS as $event => $weight) {
                $cumulativeWeight += $weight;
                if ($roll <= $cumulativeWeight) {
                    $selectedEvent = $event;
                    break;
                }
            }

            $state->eventType = $selectedEvent;

            switch ($selectedEvent) {
                case ShockEvent::GLOBAL_RECESSION:
                    $state->outputGap -= self::SHOCK_RECESSION_IMPACT;
                    break;
                case ShockEvent::INFLATION_CRISIS:
                    $state->inflation += self::SHOCK_INFLATION_IMPACT;
                    break;
                case ShockEvent::EMERGENCY_STIMULUS:
                    $state->policyRate = 0.0;
                    $state->outputGap += self::SHOCK_STIMULUS_IMPACT;
                    break;
                case ShockEvent::SURPRISE_STRONG_ECONOMY:
                    $state->outputGap += self::SHOCK_STRONG_ECONOMY_GAP_IMPACT;
                    $state->inflation -= self::SHOCK_STRONG_ECONOMY_INFLATION_IMPACT;
                    break;
            }
        }
    }
}

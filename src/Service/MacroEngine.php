<?php

namespace App\Service;

use App\Data\SectorPE;
use Psr\Log\LoggerInterface;

class MacroEngine
{
    private const REDIS_MACRO_STATE = 'macroeconomic_state';

    public function __construct(
        private MathUtility $mathUtility,
        private LoggerInterface $logger,
        private \Redis $redis
    ) {}

    /**
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     *
     * @return array{
     * inflation: float, output_gap: float, target_rate: float, policy_rate: float,
     * ns_level: float, ns_slope: float, ns_curvature: float, yield_10y: float
     * }
     */
    public function updateMacroState(float $dt): array
    {
        // Load Current State or Set Defaults
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        $state = $rawState ? json_decode($rawState, true) : [
            'inflation' => 0.02,
            'output_gap' => 0.00,
            'policy_rate' => 0.04,
        ];

        $targetInflation = 0.02;
        $naturalRate = 0.02;

        // DYNAMIC VOLATILITY (Heteroskedasticity)
        $stressMultiplier = 1.0 + (abs($state['output_gap']) * 10.0);


        $targetRate = $this->calculateTargetRate($state, $targetInflation, $naturalRate);
        $state['policy_rate'] = $this->updatePolicyRate($state['policy_rate'], $targetRate, $dt);

        $yieldData = $this->calculateYieldCurveAndQE($state, $targetInflation, $naturalRate);
        $yield10y = $yieldData['yield_10y'];

        $state['output_gap'] = $this->calculateOutputGap($state, $yield10y, $naturalRate, $stressMultiplier, $dt);
        $state['inflation'] = $this->calculateInflation($state, $targetInflation, $stressMultiplier, $dt);


        $payload = [
            'inflation' => $state['inflation'],
            'output_gap' => $state['output_gap'],
            'target_rate' => $targetRate,
            'policy_rate' => $state['policy_rate'],
            'ns_level' => $yieldData['level'],
            'ns_slope' => $yieldData['slope'],
            'ns_curvature' => $yieldData['curvature'],
            'yield_10y' => $yield10y,
            'qe_active' => $yieldData['qe_suppression'] > 0
        ];

        $this->redis->set(self::REDIS_MACRO_STATE, json_encode($payload));
        return $payload;
    }

    /**
     * Retrieves the live Sector P/E multiples from Redis or defaults if not set.
     *
     * @return array<string, float>
     */
    public function getLiveSectors(): array
    {
        $rawSectors = $this->redis->get('macro_sectors_live');
        if ($rawSectors) {
            return json_decode($rawSectors, true);
        }

        return SectorPE::MACRO_SECTORS;
    }

    /**
     * Updates Sector P/E Multiples based on the 10-Year Yield (Cost of Capital) and Output Gap (Sentiment).
     */
    public function updateSectorMultiples(float $dt, array $macroState): array
    {
        $liveSectors = $this->getLiveSectors();
        $updatedSectors = [];

        // The Yield Spread (Cost of Capital Shock)
        // Assume a baseline 10Y yield of 4% (0.04). 
        $yieldSpread = ($macroState['yield_10y'] ?? 0.04) - 0.04;

        // The Output Gap (Economic Sentiment / Risk Premium)
        $economicSentiment = $macroState['output_gap'] ?? 0.0;

        foreach ($liveSectors as $sectorName => $currentPE) {
            $baselinePE = \App\Data\SectorPE::MACRO_SECTORS[$sectorName] ?? 20.0;

            // Equity Duration (Sensitivity to Interest Rates)
            $durationRisk = match ($sectorName) {
                'Information Technology', 'Communication Services' => 25.0, // High growth, heavily penalized by rate hikes
                'Consumer Discretionary', 'Real Estate'            => 20.0, // Highly sensitive to consumer borrowing costs
                'Industrials', 'Materials', 'Consumer Staples'     => 15.0, // Standard market duration
                'Utilities', 'Energy'                              => 12.0, // Cash cows, lower duration
                'Financials'                                       => 8.0,  // Banks BENEFIT from higher rates (NIM), lowest penalty
                default                                            => 15.0,
            };

            // Calculate the theoretical Fair Value P/E based on the Macro Environment
            // Rate Shock: Higher Yields = Lower P/E.
            $rateShock = exp(-$durationRisk * $yieldSpread);

            // Sentiment Premium: Positive Output Gap = Higher P/E (Multiplier applied to baseline).
            $sentimentPremium = exp(2.0 * $economicSentiment);

            $targetPE = $baselinePE * $rateShock * $sentimentPremium;

            // Failsafe bounds
            $targetPE = max(5.0, min(60.0, $targetPE));

            // Mean Reversion in Log Space
            $logCurrent = log(max(0.01, $currentPE));
            $logPull = $this->mathUtility->calculateLogMeanReversion($currentPE, $targetPE, 2.0) * $dt;

            // Add a bit of random sector noise (0.30 volatility)
            $logDrift = 0.30 * sqrt($dt) * $this->mathUtility->generateStandardNormal();

            $updatedSectors[$sectorName] = exp($logCurrent + $logPull + $logDrift);
        }

        $this->redis->set('macro_sectors_live', json_encode($updatedSectors));
        return $updatedSectors;
    }

    /**
     * Calculates the Central Bank's target policy rate using the Taylor Rule.
     *
     * @param array $state            The current macroeconomic state.
     * @param float $targetInflation  The central bank's inflation target.
     * @param float $naturalRate      The natural rate of interest (R-star).
     * @return float The target policy rate, bounded by the zero lower bound and a realistic ceiling.
     */
    private function calculateTargetRate(array $state, float $targetInflation, float $naturalRate): float
    {
        $targetRate = $naturalRate + $state['inflation']
            + 0.5 * ($state['inflation'] - $targetInflation)
            + 0.5 * ($state['output_gap']);

        return max(0.00, min(0.15, $targetRate));
    }

    /**
     * Smoothly transitions the current policy rate toward the target rate.
     *
     * @param float $currentPolicyRate The current central bank policy rate.
     * @param float $targetRate        The desired target policy rate.
     * @param float $dt                The time step for the simulation (in years).
     * @return float The updated policy rate for the current tick.
     */
    private function updatePolicyRate(float $currentPolicyRate, float $targetRate, float $dt): float
    {
        $cbSpeed = 2.5;
        return $currentPolicyRate + $cbSpeed * ($targetRate - $currentPolicyRate) * $dt;
    }

    /**
     * Calculates the Yield Curve (Nelson-Siegel) and applies Quantitative Easing (QE) suppression.
     *
     * Models the 10-year yield based on level, slope, and curvature. If at the Zero Lower Bound
     * during a recession, artificially suppresses long-term yields via QE.
     *
     * @param array $state            The current macroeconomic state.
     * @param float $targetInflation  The inflation target.
     * @param float $naturalRate      The natural rate of interest.
     * @return array{level: float, slope: float, curvature: float, qe_suppression: float, yield_10y: float}
     */
    private function calculateYieldCurveAndQE(array $state, float $targetInflation, float $naturalRate): array
    {
        $qeYieldSuppression = 0.0;
        if ($state['policy_rate'] <= 0.005 && $state['output_gap'] < -0.02) {
            $qeYieldSuppression = min(0.02, abs($state['output_gap']) * 0.5);
        }

        $level = $naturalRate + (0.8 * $targetInflation) + (0.2 * $state['inflation']);
        $slope = $state['policy_rate'] - $level;
        // Let the curve naturally invert during recessions, but cap the extreme at -1%
        $curvature = max(-0.01, 0.02 + ($state['output_gap'] * 0.5));

        $lambda = 0.5;
        $tau = 10.0;
        $term1 = (1 - exp(-$lambda * $tau)) / ($lambda * $tau);
        $term2 = $term1 - exp(-$lambda * $tau);

        $yield10y = $level + ($slope * $term1) + ($curvature * $term2) - $qeYieldSuppression;

        return [
            'level' => $level,
            'slope' => $slope,
            'curvature' => $curvature,
            'qe_suppression' => $qeYieldSuppression,
            'yield_10y' => max(0.00, $yield10y)
        ];
    }

    /**
     * Calculates the next step of the Output Gap using the IS Curve model.
     *
     * @param array $state            The current macroeconomic state.
     * @param float $yield10y         The 10-year bond yield (borrowing cost basis).
     * @param float $naturalRate      The natural rate of interest.
     * @param float $stressMultiplier The dynamic volatility multiplier.
     * @param float $dt               The time step (in years).
     * @return float The updated output gap.
     */
    private function calculateOutputGap(array $state, float $yield10y, float $naturalRate, float $stressMultiplier, float $dt): float
    {
        $outZ = $this->mathUtility->generateStandardNormal();
        $gapDiff = 0.0 - $state['output_gap'];
        $gapDrift = 2.0 * $gapDiff * $dt;

        $borrowingCost = (0.3 * $state['policy_rate']) + (0.7 * $yield10y);
        $realRate = $borrowingCost - $state['inflation'];
        $rateDrag = 1.5 * ($realRate - $naturalRate) * $dt;

        $newGap = $state['output_gap'] + $gapDrift - $rateDrag + (0.02 * $stressMultiplier * sqrt($dt) * $outZ);
        return max(-0.07, min(0.07, $newGap));
    }

    /**
     * Calculates the next step of Inflation using the Phillips Curve model.
     *
     * @param array $state            The current macroeconomic state.
     * @param float $targetInflation  The inflation target.
     * @param float $stressMultiplier The dynamic volatility multiplier.
     * @param float $dt               The time step (in years).
     * @return float The updated inflation rate.
     */
    private function calculateInflation(array $state, float $targetInflation, float $stressMultiplier, float $dt): float
    {
        $infZ = $this->mathUtility->generateStandardNormal();
        $infDiff = $targetInflation - $state['inflation'];
        $inflationDrift = 1.5 * $infDiff * $dt;

        $phillipsEffect = 0.5 * $state['output_gap'] * $dt;

        $newInflation = $state['inflation'] + $inflationDrift + $phillipsEffect + (0.015 * $stressMultiplier * sqrt($dt) * $infZ);
        return max(-0.05, min(0.15, $newInflation));
    }
}

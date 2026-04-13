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
            'inflation' => 0.02,    // 2%
            'output_gap' => 0.00,   // 0% (Economy at potential)
            'policy_rate' => 0.04,  // Current Fed Funds Rate 4%
        ];

        // Core Economic Constants
        $targetInflation = 0.02;
        $naturalRate = 0.02; // r* (Real neutral rate)

        // Simulate Inflation (Mean-reverting to target, driven by output gap)
        $infZ = $this->mathUtility->generateStandardNormal();
        $inflationDrift = 1.0 * ($targetInflation - $state['inflation']) * $dt;
        // Phillips Curve effect: Positive output gap (hot economy) drives inflation up
        $phillipsEffect = 0.1 * $state['output_gap'] * $dt; 
        $state['inflation'] += $inflationDrift + $phillipsEffect + (0.02 * sqrt($dt) * $infZ);

        // Simulate Output Gap (Mean-reverting to 0, suppressed by high interest rates)
        $outZ = $this->mathUtility->generateStandardNormal();
        $gapDrift = 1.5 * (0.0 - $state['output_gap']) * $dt;
        // IS Curve effect: High real rates suppress economic output
        $realRate = $state['policy_rate'] - $state['inflation'];
        $rateDrag = 0.5 * ($realRate - $naturalRate) * $dt;
        $state['output_gap'] += $gapDrift - $rateDrag + (0.03 * sqrt($dt) * $outZ);

        // The Taylor Rule (Central Bank Target Rate)
        $targetRate = $naturalRate + $state['inflation'] 
            + 0.5 * ($state['inflation'] - $targetInflation) 
            + 0.5 * ($state['output_gap']);
            
        // Zero Lower Bound (ZLB) - Rates generally don't go below 0
        $targetRate = max(0.00, $targetRate);

        // Rate Smoothing (Central banks hate sudden shocks)
        $smoothing = 0.85; // High inertia
        $state['policy_rate'] = ($smoothing * $state['policy_rate']) + ((1 - $smoothing) * $targetRate);

        // Nelson-Siegel Yield Curve Factors
        // Level (Long-term rate): Anchored to long-term inflation expectations + natural rate
        $level = $naturalRate + (0.8 * $targetInflation) + (0.2 * $state['inflation']);
        
        // Slope: The difference between short rate and long rate.
        // If Policy Rate > Level, Slope is negative (Inverted Yield Curve!)
        $slope = $state['policy_rate'] - $level;
        
        // Curvature: Mid-term risk premium
        $curvature = 0.02; 

        // Calculate the critical 10-Year Yield using Nelson-Siegel formula (lambda = 0.5)
        $lambda = 0.5;
        $tau = 10.0;
        $term1 = (1 - exp(-$lambda * $tau)) / ($lambda * $tau);
        $term2 = $term1 - exp(-$lambda * $tau);
        $yield10y = $level + ($slope * $term1) + ($curvature * $term2);

        // Save State
        $payload = [
            'inflation' => $state['inflation'],
            'output_gap' => $state['output_gap'],
            'target_rate' => $targetRate,
            'policy_rate' => $state['policy_rate'],
            'ns_level' => $level,
            'ns_slope' => $slope,
            'ns_curvature' => $curvature,
            'yield_10y' => $yield10y
        ];
        
        $this->redis->set(self::REDIS_MACRO_STATE, json_encode($payload));
        return $payload;
    }

    /**
     * Updates Sector P/E Multiples based on the 10-Year Yield (Cost of Capital).
     */
    public function updateSectorMultiples(float $dt, array $macroState): array
    {
        $liveSectors = $this->getLiveSectors();
        $updatedSectors = [];

        // When the 10-Year Yield goes up, the cost of capital rises, compressing P/E multiples.
        // Assume a baseline 10Y yield of 4% (0.04). 
        $yieldSpread = $macroState['yield_10y'] - 0.04;
        
        foreach ($liveSectors as $sectorName => $currentPE) {
            $baselinePE = SectorPE::MACRO_SECTORS[$sectorName] ?? 20.0;
            
            // Apply the Discount Rate Shock (Higher yields = lower target PE)
            // Tech stocks (high duration) get crushed harder by rate hikes than utilities
            $durationRisk = ($sectorName === 'Information Technology') ? 25.0 : 15.0;
            $targetPE = $baselinePE * exp(-$durationRisk * $yieldSpread);
            
            // Mean Reversion in Log Space
            $logCurrent = log(max(0.01, $currentPE));
            $logPull = $this->mathUtility->calculateLogMeanReversion($currentPE, $targetPE, 2.0) * $dt;
            $logDrift = 0.15 * sqrt($dt) * $this->mathUtility->generateStandardNormal();

            $updatedSectors[$sectorName] = exp($logCurrent + $logPull + $logDrift);
        }

        $this->redis->set('macro_sectors_live', json_encode($updatedSectors));
        return $updatedSectors;
    }
    
    public function getLiveSectors(): array {
        $data = $this->redis->get('macro_sectors_live');
        return $data ? json_decode($data, true) : SectorPE::MACRO_SECTORS;
    }
}
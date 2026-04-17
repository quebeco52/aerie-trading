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


        // THE CENTRAL BANK (Taylor Rule & Policy Rate)

        $targetRate = $naturalRate + $state['inflation']
            + 0.5 * ($state['inflation'] - $targetInflation)
            + 0.5 * ($state['output_gap']);

        // Zero Lower Bound and Realistic Historical Ceiling (15%)
        $targetRate = max(0.00, min(0.15, $targetRate)); 

        // Fixed Continuous Smoothing using $dt
        $cbSpeed = 2.5;
        $state['policy_rate'] += $cbSpeed * ($targetRate - $state['policy_rate']) * $dt;


        // YIELD CURVE & QUANTITATIVE EASING (QE)

        $qeYieldSuppression = 0.0;

        // If we are at the ZLB and in a recession, the CB buys long-term bonds (QE)
        if ($state['policy_rate'] <= 0.005 && $state['output_gap'] < -0.02) {
            // QE suppresses long-term yields, maxing out at a 2% artificial discount
            $qeYieldSuppression = min(0.02, abs($state['output_gap']) * 0.5);
        }

        $level = $naturalRate + (0.8 * $targetInflation) + (0.2 * $state['inflation']);
        $slope = $state['policy_rate'] - $level;
        $curvature = 0.02;

        $lambda = 0.5;
        $tau = 10.0;
        $term1 = (1 - exp(-$lambda * $tau)) / ($lambda * $tau);
        $term2 = $term1 - exp(-$lambda * $tau);

        // Calculate the 10y Yield and apply the QE suppression!
        $yield10y = $level + ($slope * $term1) + ($curvature * $term2) - $qeYieldSuppression;
        $yield10y = max(0.00, $yield10y); // Yields shouldn't go negative in this sim


        // OUTPUT GAP (IS Curve)

        $outZ = $this->mathUtility->generateStandardNormal();

        $gapDiff = 0.0 - $state['output_gap'];
        
        // Strong, stable linear mean reversion (Kappa = 2.0 pulls it back safely)
        $gapDrift = 2.0 * $gapDiff * $dt;

        $borrowingCost = (0.3 * $state['policy_rate']) + (0.7 * $yield10y);
        $realRate = $borrowingCost - $state['inflation'];

        // Rate drag: High interest rates crush the economy
        $rateDrag = 1.5 * ($realRate - $naturalRate) * $dt;

        // Reduced the stochastic noise parameter from 0.07 to 0.02. 
        // We don't need massive noise if we aren't fighting a cubic wall.
        $state['output_gap'] += $gapDrift - $rateDrag + (0.02 * $stressMultiplier * sqrt($dt) * $outZ);

        // Failsafe bounds
        $state['output_gap'] = max(-0.07, min(0.07, $state['output_gap']));


        // INFLATION Phillips Curve

        $infZ = $this->mathUtility->generateStandardNormal();

        $infDiff = $targetInflation - $state['inflation'];
        
        // Linear reversion to the 2% target (Kappa = 1.5)
        $inflationDrift = 1.5 * $infDiff * $dt;

        // The Phillips Effect: Positive output gap (boom) drives inflation up
        $phillipsEffect = 0.5 * $state['output_gap'] * $dt;

        // Reduced stochastic noise parameter from 0.04 to 0.015 for stability
        $state['inflation'] += $inflationDrift + $phillipsEffect + (0.015 * $stressMultiplier * sqrt($dt) * $infZ);

        // Failsafe bounds
        $state['inflation'] = max(-0.05, min(0.15, $state['inflation']));


        $payload = [
            'inflation' => $state['inflation'],
            'output_gap' => $state['output_gap'],
            'target_rate' => $targetRate,
            'policy_rate' => $state['policy_rate'],
            'ns_level' => $level,
            'ns_slope' => $slope,
            'ns_curvature' => $curvature,
            'yield_10y' => $yield10y,
            'qe_active' => $qeYieldSuppression > 0
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
}

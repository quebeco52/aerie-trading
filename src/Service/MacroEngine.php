<?php

namespace App\Service;

use App\Data\EconomicCycle;
use App\Data\SectorPE;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for simulating the macroeconomic environment.
 *
 * This engine manages "Sector Rotation" by simulating how the Price-to-Earnings (P/E)
 * ratios of different industrial sectors drift over time. It uses an Ornstein-Uhlenbeck
 * process (mean-reverting stochastic process) to ensure sectors can experience
 * bubbles and crashes but eventually return to their historical averages.
 */
class MacroEngine
{
    private const REDIS_ECONOMY_STATE_KEY = 'economy_state';
    private const REDIS_ECONOMY_TIME_KEY = 'economy_time_in_state';

    public function __construct(
        private MathUtility $mathUtility,
        private LoggerInterface $logger,
        private \Redis $redis
    ) {
    }

    /**
     * Simulates one time step of sector rotation.
     *
     * This method applies a mean-reverting drift to the P/E ratio of every sector.
     * It calculates the new P/E based on:
     * 1. The distance from the historical baseline (Gravity).
     * 2. A random stochastic shock (Volatility).
     *
     * The calculation is performed in Log Space to ensure P/E ratios never become negative.
     *
     * @param float $dt The time step in years (e.g., 1/252 for a trading day).
     * @return array<string, float> The updated list of sector P/E ratios.
     */
    public function updateSectorMultiples(float $dt, EconomicCycle $economicCycle): array
    {
        $liveSectors = $this->getLiveSectors();
        $updatedSectors = [];

        // Macro factors: Sector P/Es slowly drift, reverting to their historical baseline
        $reversionSpeed = 0.40;
        $macroVol = 0.20;

        // shifts the target P/E up during booms and down during busts.
        $cycleModifier = match ($economicCycle) {
            EconomicCycle::RECESSION => 0.85,
            EconomicCycle::RECOVERY  => 1.00,
            EconomicCycle::EXPANSION => 1.15,
            EconomicCycle::PEAK      => 1.30,
        };

        foreach ($liveSectors as $sectorName => $currentPE) {
            $baselinePE = SectorPE::MACRO_SECTORS[$sectorName] ?? 20.0;

            $targetPE = $baselinePE * $cycleModifier;

            // Convert to Log Space
            $logCurrent = log($currentPE);
            $logBaseline = log($targetPE);

            // Calculate the Log-Gravity and Log-Drift
            $logPull = $reversionSpeed * ($logBaseline - $logCurrent) * $dt;
            $z = $this->mathUtility->generateStandardNormal();
            $logDrift = $macroVol * sqrt($dt) * $z;

            // Apply the changes in Log Space
            $newLogPE = $logCurrent + $logPull + $logDrift;

            // Convert back to Linear Space
            $newPE = exp($newLogPE);

            $updatedSectors[$sectorName] = $newPE;
        }

        // Save the new live multiples back to Redis
        $this->redis->set('macro_sectors_live', json_encode($updatedSectors));

        return $updatedSectors;
    }

    /**
     * Updates the state of the economy based on the Council of Thirteen's interest rates.
     */
    public function updateBoomBust(float $dt, float $councilRate): EconomicCycle
    {
        $currentStateStr = $this->redis->get(self::REDIS_ECONOMY_STATE_KEY) ?: EconomicCycle::EXPANSION->value;
        $currentState = EconomicCycle::from($currentStateStr);

        $transitionProbability = 0.0;

        switch ($currentState) {
            case EconomicCycle::RECOVERY:
                // Recovery naturally bleeds into an Expansion after the dust settles.
                $transitionProbability = $dt / 1.0; 
                $nextState = EconomicCycle::EXPANSION;
                break;

            case EconomicCycle::EXPANSION:
                // Below 3%, no chance of peaking. Above 3%, the pressure builds.
                if ($councilRate > 0.03) {
                    $pressure = ($councilRate - 0.03) / 0.03; // Scales from 0 to 1+
                    $transitionProbability = ($dt / 0.5) * $pressure; 
                }
                $nextState = EconomicCycle::PEAK;
                break;

            case EconomicCycle::PEAK:
                // The economy is suffocating under high rates. 
                // At 5%, it's struggling. At 7%, an instant crash is almost guaranteed.
                if ($councilRate > 0.04) {
                    $pressure = ($councilRate - 0.04) / 0.02; 
                    $transitionProbability = ($dt / 0.25) * $pressure;
                }
                $nextState = EconomicCycle::RECESSION;
                break;

            case EconomicCycle::RECESSION:
                // The economy only stops crashing when the Council has slashed rates 
                // back down to stimulate growth.
                if ($councilRate < 0.02) {
                    // Rates are cheap again The probability of recovery skyrockets.
                    $relief = (0.02 - $councilRate) / 0.02;
                    $transitionProbability = ($dt / 0.2) * $relief;
                }
                $nextState = EconomicCycle::RECOVERY;
                break;
        }

        // Roll the dice against the rate-driven probability
        if ((mt_rand() / mt_getrandmax()) < $transitionProbability) {
            $this->redis->set(self::REDIS_ECONOMY_STATE_KEY, $nextState->value);
            $this->logger->info("The Council's actions pushed the economy into: {$nextState->value} (Rate: " . round($councilRate * 100, 2) . "%)");
            return $nextState;
        }

        return $currentState;
    }

    private const REDIS_COUNCIL_RATE_KEY = 'council_interest_rate';

    /**
     * The Council of Thirteen adjusts the District's base interest rate.
     */
    public function updateCouncilRate(float $dt, EconomicCycle $currentState): float
    {
        // Default starting rate is 2% (0.02)
        $currentRate = (float) ($this->redis->get(self::REDIS_COUNCIL_RATE_KEY) ?: 0.02);

        // How much the Council adjusts the rate per year
        $rateChangePerYear = match ($currentState) {
            EconomicCycle::RECOVERY  =>  0.000, // Hold steady at the bottom
            EconomicCycle::EXPANSION =>  0.015, // Slow, steady rate hikes (+1.5% a year)
            EconomicCycle::PEAK      =>  0.005, // Final squeeze (+0.5% a year)
            EconomicCycle::RECESSION => -0.050, // PANIC CUTS! (-5.0% a year)
        };

        $newRate = $currentRate + ($rateChangePerYear * $dt);

        // The Council never lets rates go below 0% or above 10%
        $newRate = max(0.00, min(0.10, $newRate));

        $this->redis->set(self::REDIS_COUNCIL_RATE_KEY, (string) $newRate);

        return $newRate;
    }

    /**
     * Retrieves the current live P/E ratios for all sectors from Redis.
     *
     * If the simulation has just started and Redis is empty, this method
     * seeds the state with the historical defaults defined in SectorPE::MACRO_SECTORS.
     *
     * @return array<string, float> Associative array of 'Sector Name' => PE Ratio.
     */
    public function getLiveSectors(): array
    {
        $data = $this->redis->get('macro_sectors_live');

        if ($data) {
            return json_decode($data, true);
        }

        // If Redis is empty (first run), start with the historical baselines
        $this->redis->set('macro_sectors_live', json_encode(SectorPE::MACRO_SECTORS));
        return SectorPE::MACRO_SECTORS;
    }
}

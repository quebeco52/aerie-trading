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
     * Updates the state of the economy using a time-aware Hazard Function.
     *
     * Enforces a minimum time spent in each state, and ramps up the probability
     * of a transition as the state approaches its maximum allowed duration.
     *
     * @param float $dt The time step in years.
     * @return EconomicCycle The current (and possibly new) state of the economy.
     */
    public function updateBoomBust(float $dt): EconomicCycle
    {
        $currentStateStr = $this->redis->get(self::REDIS_ECONOMY_STATE_KEY) ?: EconomicCycle::EXPANSION->value;
        $currentState = EconomicCycle::from($currentStateStr);
        
        // Retrieve the time spent in the current state (default to 0.0)
        $timeInState = (float) ($this->redis->get(self::REDIS_ECONOMY_TIME_KEY) ?: 0.0);
        $timeInState += $dt;

        $targetDuration = $currentState->getTargetDuration();
        
        // Define our boundaries
        $minDuration = $targetDuration * 0.50; // Must spend at least 50% of target time
        $maxDuration = $targetDuration * 1.50; // Forced exit at 150% of target time

        $transitioned = false;

        // The Ceiling (Force a transition if it's been going on too long)
        if ($timeInState >= $maxDuration) {
            $transitioned = true;
        } 
        // The Hazard Zone (Roll the dice with increasing probability)
        elseif ($timeInState >= $minDuration) {
            // As timeInState approaches maxDuration, the remaining window shrinks to zero.
            // Dividing $dt by a shrinking window means the probability continuously rises.
            $remainingWindow = max(0.0001, $maxDuration - $timeInState);
            $transitionProbability = $dt / $remainingWindow;

            if ((mt_rand() / mt_getrandmax()) < $transitionProbability) {
                $transitioned = true;
            }
        }
        // The Floor (If timeInState < minDuration, do nothing. Transition is impossible.)

        if ($transitioned) {
            // Time to transition to the next state
            $newState = match ($currentState) {
                EconomicCycle::RECESSION => EconomicCycle::RECOVERY,
                EconomicCycle::RECOVERY  => EconomicCycle::EXPANSION,
                EconomicCycle::EXPANSION => EconomicCycle::PEAK,
                EconomicCycle::PEAK      => EconomicCycle::RECESSION,
            };
            
            // Save the new state and RESET the timer back to 0
            $this->redis->set(self::REDIS_ECONOMY_STATE_KEY, $newState->value);
            $this->redis->set(self::REDIS_ECONOMY_TIME_KEY, '0.0');

            $this->logger->info("New economy state: {$newState->value} ");
            
            return $newState;
        }

        // No transition occurred. Save the incremented time back to Redis.
        $this->redis->set(self::REDIS_ECONOMY_TIME_KEY, (string) $timeInState);

        return $currentState;
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

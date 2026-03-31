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
     * The Council of Thirteen meets periodically to adjust the District's base interest rate.
     */
    public function updateCouncilRate(float $dt, EconomicCycle $currentState): float
    {
        $currentRate = (float) ($this->redis->get(self::REDIS_COUNCIL_RATE_KEY) ?: 0.02);

        // The Meeting Schedule
        $meetingFrequency = ($currentState === EconomicCycle::RECESSION) ? 24.0 : 8.0;
        
        // Roll the dice to see if a meeting is happening
        if ((mt_rand() / mt_getrandmax()) > ($meetingFrequency * $dt)) {
            // No meeting. Rates stay perfectly flat.
            return $currentRate; 
        }

        // Determine the Council's Target Rate.
        $targetRate = match ($currentState) {
            EconomicCycle::RECOVERY  => 0.010, // Aim for 1.0% (Stimulative)
            EconomicCycle::EXPANSION => 0.045, // Aim for 4.5% (Neutral/Tightening)
            EconomicCycle::PEAK      => 0.065, // Aim for 6.5% (The Squeeze)
            EconomicCycle::RECESSION => 0.000, // Aim for 0.0% (Panic Mode)
        };

        // Are we already at the target? The Council issues a "Pause" and holds rates steady.
        if (abs($targetRate - $currentRate) < 0.001) {
            return $currentRate; 
        }

        // 3. Make the Move in Basis Points (bps)
        $moveDirection = ($targetRate > $currentRate) ? 1 : -1;
        $bpsMove = 0.0025; // Standard move is 25 basis points (0.25%)

        // Aggressive moves: If they are crashing, or way behind the curve at the peak
        if ($currentState === EconomicCycle::RECESSION) {
            $bpsMove = 0.0050; // 50 bps emergency cuts
        } elseif ($currentState === EconomicCycle::PEAK && abs($targetRate - $currentRate) > 0.015) {
            $bpsMove = 0.0050;
        }

        $newRate = $currentRate + ($bpsMove * $moveDirection);
        
        // The Council never lets rates go below 0% or above 10%
        $newRate = max(0.00, min(0.10, $newRate));

        $this->redis->set(self::REDIS_COUNCIL_RATE_KEY, (string) $newRate);

        // Optional: Log the meeting outcome to your server console
        $action = $moveDirection > 0 ? "hiked" : "slashed";
        $this->logger->info("BREAKING: The Council of Thirteen convened and {$action} rates to " . ($newRate * 100) . "%");

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

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
    private const REDIS_COUNCIL_RATE_KEY = 'council_interest_rate';
    private const REDIS_MARKET_HEAT_KEY = 'market_heat';

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
            $logCurrent = log(max(0.01, $currentPE));
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
     * Translates the raw Market Heat into a recognizable Economic Phase.
     */
    public function updateBoomBust(float $marketHeat): EconomicCycle
    {
        $currentStateStr = $this->redis->get(self::REDIS_ECONOMY_STATE_KEY) ?: EconomicCycle::EXPANSION->value;
        $currentState = EconomicCycle::from($currentStateStr);
        $newState = $currentState;

        if ($marketHeat > 80.0) {
            $newState = EconomicCycle::PEAK;
            
        } elseif ($marketHeat > 55.0 && $marketHeat <= 80.0) {
            $newState = EconomicCycle::EXPANSION;
            
        } elseif ($marketHeat >= 30.0 && $marketHeat <= 55.0) {
            // THE TRANSITION ZONE: Direction matters here
            if (in_array($currentState, [EconomicCycle::PEAK, EconomicCycle::EXPANSION])) {
                $newState = EconomicCycle::RECESSION;
            } elseif ($currentState === EconomicCycle::RECESSION) {
                $newState = EconomicCycle::RECOVERY;
            }
            
        } else { // $marketHeat < 30.0
            $newState = EconomicCycle::RECESSION;
        }

        if ($newState !== $currentState) {
            $this->redis->set(self::REDIS_ECONOMY_STATE_KEY, $newState->value);
            $this->logger->info("Economy transitioned to: {$newState->value}");
        }

        return $newState;
    }

    /**
     * The Council of Thirteen meets to try and tame the Market Heat.
     */
    public function updateCouncilRate(float $dt, float $marketHeat): float
    {
        $currentRate = (float) ($this->redis->get(self::REDIS_COUNCIL_RATE_KEY) ?: 0.02);

        // They meet roughly 8 times a year, or 24 times if the heat is dangerously low
        $meetingFrequency = ($marketHeat < 30.0) ? 24.0 : 8.0;
        
        if ((mt_rand() / mt_getrandmax()) > ($meetingFrequency * $dt)) {
            return $currentRate; // No meeting today.
        }

        // The Council assesses the Heat and determines a Target Rate
        if ($marketHeat > 85.0) {
            $targetRate = 0.07; // Squeeze the massive bubble
        } elseif ($marketHeat > 65.0) {
            $targetRate = 0.045; // Cool down the expansion
        } elseif ($marketHeat < 35.0) {
            $targetRate = 0.00; // Emergency stimulus
        } else {
            $targetRate = 0.03; // Neutral
        }

        if (abs($targetRate - $currentRate) < 0.001) {
            return $currentRate; // We are at the target. Pause.
        }

        // Execute the move in 25 basis point steps (0.25%)
        $moveDirection = ($targetRate > $currentRate) ? 1 : -1;
        $bpsMove = 0.0025; 

        // Jumbo 50bps moves if they are panicking
        if ($marketHeat < 25.0 || $marketHeat > 90.0) {
            $bpsMove = 0.0050; 
        }

        $newRate = $currentRate + ($bpsMove * $moveDirection);
        $newRate = max(0.00, min(0.10, $newRate));

        $this->redis->set(self::REDIS_COUNCIL_RATE_KEY, (string) $newRate);
        
        $action = $moveDirection > 0 ? "hiked" : "slashed";
        $this->logger->info("The Council of Thirteen {$action} rates to " . ($newRate * 100) . "% (Heat: {$marketHeat})");

        return $newRate;
    }

    /**
     * Calculates the internal "Market Heat" (0 to 100).
     * This is driven by the Council's interest rate vs the Neutral Rate (3.0%).
     */
    public function updateMarketHeat(float $dt, float $councilRate): float
    {
        $currentHeat = (float) ($this->redis->get(self::REDIS_MARKET_HEAT_KEY) ?: 50.0);

        // Calculate the Target Heat based on the Council Rate
        // If rate is 3% (0.03), Target = 50.
        $neutralRate = 0.03;
        $targetHeat = 50.0 + (($neutralRate - $councilRate) * 1500.0);
        
        // Cap the target between 0 and 100
        $targetHeat = max(0.0, min(100.0, $targetHeat));

        // Apply Mean Reversion (Gravity) and Stochastic Noise
        $reversionSpeed = 1.2; // How fast the economy reacts to the rate
        $heatVol = 8.0; // Random daily economic noise

        $pull = $reversionSpeed * ($targetHeat - $currentHeat) * $dt;
        $noise = $heatVol * sqrt($dt) * $this->mathUtility->generateStandardNormal();

        $newHeat = $currentHeat + $pull + $noise;

        if ($newHeat > 85.0) {
            // Calculate crash probability. 
            // At 85 heat, probability is 0%. At 100 heat, probability is extremely high.
           $annualCrashProb = ($newHeat - 85.0) / 15.0; // Scales from 0.0 to 1.0

            // crash
            if ((mt_rand() / mt_getrandmax()) < ($annualCrashProb * $dt)) {
                $crashSeverity = mt_rand(40, 60);
                $newHeat -= $crashSeverity;
                
                // Log this catastrophic event!
                $this->logger->warning("MINSKY MOMENT: The market bubble violently popped! Heat collapsed by {$crashSeverity} points.");
            }
        }
        // ==========================================
        
        // Hard physical bounds
        $newHeat = max(0.0, min(100.0, $newHeat));

        $this->redis->set(self::REDIS_MARKET_HEAT_KEY, (string) $newHeat);

        return $newHeat;
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

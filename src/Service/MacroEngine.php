<?php

namespace App\Service;

use App\Data\SectorPE;

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
    private \Redis $redis;

    public function __construct(
        private MathUtility $mathUtility
    ) {
        // Connect to the existing Redis instance
        $redisUrl = parse_url($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
        $this->redis = new \Redis();
        $this->redis->connect($redisUrl['host'], $redisUrl['port'] ?? 6379);
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
    public function updateSectorMultiples(float $dt): array
    {
        $liveSectors = $this->getLiveSectors();
        $updatedSectors = [];

        // Macro factors: Sector P/Es slowly drift, reverting to their historical baseline
        $reversionSpeed = 0.40;
        $macroVol = 0.20;

        foreach ($liveSectors as $sectorName => $currentPE) {
            $baselinePE = SectorPE::MACRO_SECTORS[$sectorName] ?? 20.0;

            // 1. Convert to Log Space
            $logCurrent = log($currentPE);
            $logBaseline = log($baselinePE);

            // 2. Calculate the Log-Gravity and Log-Drift
            $logPull = $reversionSpeed * ($logBaseline - $logCurrent) * $dt;
            $z = $this->mathUtility->generateStandardNormal();
            $logDrift = $macroVol * sqrt($dt) * $z;

            // 3. Apply the changes in Log Space
            $newLogPE = $logCurrent + $logPull + $logDrift;

            // 4. Convert back to Linear Space
            $newPE = exp($newLogPE);

            $updatedSectors[$sectorName] = $newPE;
        }

        // Save the new live multiples back to Redis
        $this->redis->set('macro_sectors_live', json_encode($updatedSectors));

        return $updatedSectors;
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

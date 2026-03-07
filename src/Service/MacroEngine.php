<?php

namespace App\Service;

use App\Data\SectorPE; // Wherever you store the MACRO_SECTORS array

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
     * Drifts the P/E multiples for all macro sectors.
     */
    public function updateSectorMultiples(float $dt): array
    {
        $liveSectors = $this->getLiveSectors();
        $updatedSectors = [];

        // Macro factors: Sector P/Es slowly drift, reverting to their historical baseline
        $reversionSpeed = 0.5; // Takes about 2 years to revert to normal
        $macroVol = 2.0;       // How much P/E expands/contracts per year (e.g., +/- 2 points)

        foreach ($liveSectors as $sectorName => $currentPE) {
            $baselinePE = SectorPE::MACRO_SECTORS[$sectorName] ?? 20.0;

            // 1. Mean Reversion Pull (Gravity)
            $pull = $reversionSpeed * ($baselinePE - $currentPE) * $dt;

            // 2. Random Macro Drift
            $z = $this->mathUtility->generateStandardNormal();
            $drift = $macroVol * sqrt($dt) * $z;

            // 3. Calculate new Live P/E
            $newPE = $currentPE + $pull + $drift;

            // Don't let P/E drop below a catastrophic 5.0 or inflate past a bubblicious 60.0
            $newPE = max(5.0, min(60.0, $newPE)); 

            $updatedSectors[$sectorName] = $newPE;
        }

        // Save the new live multiples back to Redis
        $this->redis->set('macro_sectors_live', json_encode($updatedSectors));

        return $updatedSectors;
    }

    /**
     * Fetches current Live P/E from Redis, or seeds it if empty.
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
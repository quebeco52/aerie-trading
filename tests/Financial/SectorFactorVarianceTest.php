<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\TestCase;

/**
 * A stream Z is a unit-variance score whatever its persistence. The sector demand factor is an Ornstein-Uhlenbeck
 * level (MacroEngine), sampled at each report; fed into a stream's AR(1) as if it were a fresh innovation, its own
 * persistence compounded with the stream's and a phi = 0.8 stream ran at variance ~1.5.
 */
class SectorFactorVarianceTest extends TestCase
{
    private const QUARTERS = 40_000;

    private const FIRM_LOADING = 0.60;

    private const SECTOR_LOADING = 0.40;

    public function testAPersistentStreamOnAPersistentSectorKeepsUnitVariance(): void
    {
        $sectorDraws = MathUtility::ownStream(17);
        $decay = exp(-FinancialConstants::QUARTER_YEARS / MacroEngine::SECTOR_DEMAND_PERSISTENCE_YEARS);
        $streams = MathUtility::ownStream(29);

        $sector = 0.0;
        $momentum = [];
        $sum = 0.0;
        $sumSq = 0.0;
        for ($quarter = 0; $quarter < self::QUARTERS; ++$quarter) {
            $sector = StochasticProcesses::calculateOrnsteinUhlenbeckStep($sector, 1.0 / MacroEngine::SECTOR_DEMAND_PERSISTENCE_YEARS, 1.0, FinancialConstants::QUARTER_YEARS, $sectorDraws->generateStandardNormal());
            $context = new StreamContext($momentum, $streams, self::FIRM_LOADING, $sector, self::SECTOR_LOADING);
            $z = $context->generateZ('revenue', 0.8);
            $momentum = $context->getStreamZ();
            $sum += $z;
            $sumSq += $z * $z;
        }

        $mean = $sum / self::QUARTERS;
        $variance = ($sumSq / self::QUARTERS) - ($mean * $mean);

        $this->assertGreaterThan(0.75, $decay, 'Control: the sector level carries most of itself into the next report.');
        $this->assertEqualsWithDelta(1.0, $variance, 0.08, 'The stream Z keeps unit variance on a persistent sector level.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
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

    /**
     * A sector's price shock loads on the innovation in its demand factor at SECTOR_FACTOR_NEWS_LOADING and keeps unit
     * variance, so its shares move with the demand news its earnings will carry.
     */
    public function testTheSectorsPriceShockCarriesItsDemandNews(): void
    {
        $engine = (new \ReflectionClass(MacroEngine::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(MacroEngine::class, 'mathUtility'))->setValue($engine, MathUtility::ownStream(41));
        $step = new \ReflectionMethod(MacroEngine::class, 'updateSectorFactors');
        $dt = 1.0 / 360.0;
        $decay = exp(-$dt / MacroEngine::SECTOR_DEMAND_PERSISTENCE_YEARS);
        $state = new MacroState();

        $sumZ = $sumZz = $sumN = $sumNn = $sumZn = 0.0;
        $n = 0;
        for ($tick = 0; $tick < 4_000; ++$tick) {
            $previous = $state->sectorDemandZ;
            $step->invoke($engine, $state, $dt);
            foreach ($state->sectorZ as $sector => $z) {
                $news = ($state->sectorDemandZ[$sector] - ($decay * ($previous[$sector] ?? 0.0))) / sqrt(1.0 - ($decay * $decay));
                $sumZ += $z;
                $sumZz += $z * $z;
                $sumN += $news;
                $sumNn += $news * $news;
                $sumZn += $z * $news;
                ++$n;
            }
        }

        $covariance = ($sumZn / $n) - (($sumZ / $n) * ($sumN / $n));
        $this->assertEqualsWithDelta(1.0, ($sumZz / $n) - (($sumZ / $n) ** 2), 0.02, 'The price shock keeps unit variance,');
        $this->assertEqualsWithDelta(1.0, ($sumNn / $n) - (($sumN / $n) ** 2), 0.02, 'as does the demand news,');
        $this->assertEqualsWithDelta(MacroEngine::SECTOR_FACTOR_NEWS_LOADING, $covariance, 0.02, 'and the price carries the news at its loading.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * A log-OU price stepped with MathUtility::calculateSchwartz1Factor() averages theta x e^(-sigma^2 / 4 kappa) in
 * Schwartz's (1997) own parameterisation, so a caller whose target is the level the price should average goes through
 * schwartzThetaForMean(), and one with Merton jumps also nets out logOuJumpLevelShift(). These pin both on long runs.
 */
class SchwartzMeanLevelTest extends TestCase
{
    private const SEED = 20261007;

    /** Mean of a long run of the helper at theta, sampled every step after a burn-in. */
    private static function sampleMean(float $theta, float $kappa, float $sigma, float $dt, int $steps): float
    {
        mt_srand(self::SEED);
        $math = new MathUtility();
        $price = $theta;
        $sum = 0.0;
        $burnIn = (int) (5.0 / ($kappa * $dt));
        for ($i = 0; $i < $burnIn + $steps; $i++) {
            $price = $math->calculateSchwartz1Factor($price, $kappa, $theta, $sigma, $dt, $math->generateStandardNormal());
            if ($i >= $burnIn) {
                $sum += $price;
            }
        }

        return $sum / $steps;
    }

    public function testSchwartzThetaForMeanPutsTheStationaryMeanOnTheTarget(): void
    {
        $kappa = 1.0;
        $sigma = 0.5;
        $mean = self::sampleMean(MathUtility::schwartzThetaForMean(100.0, $kappa, $sigma), $kappa, $sigma, 0.25, 80000);

        // Standard error ~0.4% here; the uncorrected theta sits 6.1% low.
        $this->assertEqualsWithDelta(100.0, $mean, 1.5, 'The mean-preserving theta must put the long-run average on the target.');
    }

    public function testTheRawSchwartzThetaIsNotTheMean(): void
    {
        $kappa = 1.0;
        $sigma = 0.5;
        $mean = self::sampleMean(100.0, $kappa, $sigma, 0.25, 80000);

        $this->assertEqualsWithDelta(100.0 * exp(-($sigma ** 2) / (4.0 * $kappa)), $mean, 1.5, 'Schwartz theta = e^mu averages theta x e^(-sigma^2 / 4 kappa).');
    }

    /** A swap ladder struck on the forward curve must price the long end at the level the spot averages. */
    public function testTheForwardCurveLongEndSitsOnTheMeanPreservingTarget(): void
    {
        $math = new MathUtility();
        $kappa = CommodityLogisticsSubsystem::ENERGY_MEAN_REVERSION;
        $sigma = CommodityLogisticsSubsystem::ENERGY_VOLATILITY;
        $theta = MathUtility::schwartzThetaForMean(100.0, $kappa, $sigma);

        $this->assertEqualsWithDelta(100.0, $math->calculateSchwartzForwardPrice(140.0, $kappa, $theta, $sigma, 60.0), 1e-6);
        $this->assertLessThan(100.0, $math->calculateSchwartzForwardPrice(140.0, $kappa, 100.0, $sigma, 60.0), 'The raw theta prices the long end below the spot mean.');
    }

    /** The Simpson integral against the closed series of the two one-parameter cases. */
    public function testJumpLevelShiftMatchesItsSeries(): void
    {
        $lambda = 0.8;
        $kappa = 0.6;
        $mu = -0.3;
        $s = 0.4;

        // Normal-free jumps: integral_0^1 (e^(mu w) - 1) / w dw = sum mu^n / (n n!).
        $meanOnly = 0.0;
        $sizeOnly = 0.0;
        $factorial = 1.0;
        for ($n = 1; $n <= 30; $n++) {
            $factorial *= $n;
            $meanOnly += ($mu ** $n) / ($n * $factorial);
            // Zero-mean jumps: integral_0^1 (e^(s^2 w^2 / 2) - 1) / w dw = sum (s^2 / 2)^n / (2n n!).
            $sizeOnly += ((($s ** 2) / 2.0) ** $n) / (2.0 * $n * $factorial);
        }

        $this->assertEqualsWithDelta(($lambda / $kappa) * $meanOnly, MathUtility::logOuJumpLevelShift($lambda, $kappa, $mu, 0.0), 1e-9);
        $this->assertEqualsWithDelta(($lambda / $kappa) * $sizeOnly, MathUtility::logOuJumpLevelShift($lambda, $kappa, 0.0, $s), 1e-9);
    }

    /**
     * Oil averages its cobweb equilibrium: the diffusion's Jensen term and the shocks' level drift both come out of the
     * target. Before, the base price stood 2.5% (diffusion) and 0.7% (jumps) below it.
     */
    public function testEnergyBasePriceAveragesItsEquilibrium(): void
    {
        mt_srand(self::SEED);
        $subsystem = new CommodityLogisticsSubsystem(new MathUtility());
        $state = new MacroState();
        $state->energyBasePrice = MacroEngine::ENERGY_BASELINE;
        $state->energyPriceIndex = MacroEngine::ENERGY_BASELINE;
        $state->energyInventoryIndex = MacroEngine::COMMODITY_INVENTORY_BASELINE;
        $state->globalDemandGapEma = 0.0;

        $dt = 0.1;
        $burnIn = 100;
        $steps = 100000;
        $sum = 0.0;
        for ($i = 0; $i < $burnIn + $steps; $i++) {
            // Hold the price capacity sees, so the equilibrium stays at the baseline.
            $state->energyPriceIndexEma = MacroEngine::ENERGY_BASELINE;
            $state->energySupplyEma = MacroEngine::ENERGY_BASELINE;
            $subsystem->calculateEnergyShock($state, $dt);
            if ($i >= $burnIn) {
                $sum += $state->energyBasePrice;
            }
        }

        $equilibrium = CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice(0.0, MacroEngine::ENERGY_BASELINE);
        // Standard error ~0.35%.
        $this->assertEqualsWithDelta(1.0, ($sum / $steps) / $equilibrium, 0.015, 'The base price must average the equilibrium it reverts to.');
    }

    public function testSchwartzThetaForLogMeanCentresTheLogOnTheLevel(): void
    {
        mt_srand(self::SEED);
        $math = new MathUtility();
        $kappa = 1.0;
        $sigma = 0.5;
        $theta = MathUtility::schwartzThetaForLogMean(100.0, $kappa, $sigma);
        $price = 100.0;
        $sum = 0.0;
        $steps = 80000;
        for ($i = 0; $i < 20 + $steps; $i++) {
            $price = $math->calculateSchwartz1Factor($price, $kappa, $theta, $sigma, 0.25, $math->generateStandardNormal());
            if ($i >= 20) {
                $sum += log($price / 100.0);
            }
        }

        // Standard error of the log mean ~0.004; the level-centred theta would sit at -0.0625.
        $this->assertEqualsWithDelta(0.0, $sum / $steps, 0.015);
    }

    /** Every reader takes log(EPU / baseline), so at a neutral calendar and cycle that log must average zero. */
    public function testPolicyUncertaintyLogAveragesTheBaseline(): void
    {
        mt_srand(self::SEED);
        $subsystem = new CreditFiscalSubsystem(new MathUtility());
        $state = new MacroState();
        $state->policyUncertaintyIndex = MacroEngine::EPU_BASELINE;
        $state->electionPulse = 0.0;
        $state->recessionProbabilityEma = 0.0;

        $dt = 0.05;
        $burnIn = 200;
        $steps = 200000;
        $sum = 0.0;
        for ($i = 0; $i < $burnIn + $steps; $i++) {
            $subsystem->calculatePolicyUncertainty($state, $dt);
            if ($i >= $burnIn) {
                $sum += log($state->policyUncertaintyIndex / MacroEngine::EPU_BASELINE);
            }
        }

        // Standard error ~0.007 over 10,000 years; Schwartz's raw theta leaves the log 0.10 low.
        $this->assertEqualsWithDelta(0.0, $sum / $steps, 0.03);
    }
}

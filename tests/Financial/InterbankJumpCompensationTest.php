<?php

namespace App\Tests\Financial;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Merton (1976) compensation of the interbank spread's panic jumps: they fatten the tail but leave the long-run mean
 * at the premium-coupled level. Uncompensated, a stressed spread's jumps (0.2 a year, mean size e^1.125 - 1) lifted
 * the mean by kappa / (kappa - lambda k) - 1, about 20%.
 */
class InterbankJumpCompensationTest extends TestCase
{
    private const SEEDS = [3, 17, 29, 41];

    private const YEARS = 1500;

    private const DT = 0.05;

    private const EXCESS_BOND_PREMIUM = 0.034;

    public function testPanicJumpsLeaveTheMeanAtThePremiumCoupledLevel(): void
    {
        $theta = MacroEngine::INTERBANK_BASELINE_SPREAD + (self::EXCESS_BOND_PREMIUM * CreditFiscalSubsystem::INTERBANK_PREMIUM_COUPLING);
        $means = [];
        $jumps = 0;
        foreach (self::SEEDS as $seed) {
            mt_srand($seed);
            $subsystem = new CreditFiscalSubsystem(new MathUtility());
            $state = new MacroState();
            $state->excessBondPremium = self::EXCESS_BOND_PREMIUM;
            $state->marketVolatilityEma = 2.0 * MacroEngine::MACRO_VOL_BASE_ANCHOR;
            $state->interbankLiquiditySpread = $theta;

            $sum = 0.0;
            $steps = (int) round(self::YEARS / self::DT);
            $previous = $theta;
            for ($i = 0; $i < $steps; ++$i) {
                $subsystem->calculateInterbankLiquiditySpread($state, self::DT);
                $sum += $state->interbankLiquiditySpread;
                $jumps += $state->interbankLiquiditySpread > 1.5 * $previous ? 1 : 0;
                $previous = $state->interbankLiquiditySpread;
            }
            $means[] = $sum / $steps;
        }

        $mean = array_sum($means) / count($means);
        $this->assertGreaterThan(0.1 * self::YEARS * count(self::SEEDS), $jumps, 'The stressed spread does jump.');
        $this->assertEqualsWithDelta($theta, $mean, 0.05 * $theta, 'Compensated jumps leave the mean where the premium puts it.');
    }
}

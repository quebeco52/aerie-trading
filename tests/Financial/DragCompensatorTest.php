<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\Aggregate\DomesticDemand;
use App\Service\Macro\Subsystem\Aggregate\MovingAverages;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Each one-sided drag hands back the average its input's own noise puts on it, so the noise bends the cycle without
 * lowering its average. The compensators are read off the input processes alone, the economy held still and no crisis
 * struck: the credit premium over its decade trend, the catastrophe burden over an average year, and log uncertainty
 * over its baseline with no slack and no election near.
 */
class DragCompensatorTest extends TestCase
{
    public function testEachCompensatorIsTheAverageItsInputsOwnNoisePutsOnTheDrag(): void
    {
        $premium = 0.0;
        $burden = 0.0;
        $uncertainty = 0.0;
        $samples = 0;
        foreach ([1, 2, 3, 4] as $seed) {
            mt_srand($seed);
            $math = new MathUtility();
            $credit = new CreditFiscalSubsystem($math);
            $catastrophes = new CommodityLogisticsSubsystem($math);
            $state = new MacroState();
            $state->unemploymentRate = MacroConstants::NATURAL_UNEMPLOYMENT;
            $state->nairu = MacroConstants::NATURAL_UNEMPLOYMENT;
            $state->electionPulse = 0.0;
            $state->excessBondPremiumTrend = $state->excessBondPremium;

            $dt = 1.0 / 360.0;
            $emaWeight = 1.0 - exp(-$dt / MovingAverages::STANDARD_EMA_HORIZON_YEARS);
            $trendWeight = 1.0 - exp(-$dt / MovingAverages::CREDIT_PREMIUM_TREND_YEARS);
            for ($tick = 1; $tick <= 1000 * 360; ++$tick) {
                $state->totalTime += $dt;
                $credit->updateExcessBondPremium($state, 0.0, $dt);
                $catastrophes->calculateCatastropheLosses($state, $dt);
                $credit->calculatePolicyUncertainty($state, $dt);
                $state->catastropheLossIndexEma += $emaWeight * ($state->catastropheLossIndex - $state->catastropheLossIndexEma);
                $state->policyUncertaintyIndexEma += $emaWeight * ($state->policyUncertaintyIndex - $state->policyUncertaintyIndexEma);
                $state->excessBondPremiumTrend += $trendWeight * ($state->excessBondPremium - $state->excessBondPremiumTrend);
                if ($tick <= 20 * 360) {
                    continue;
                }
                $premium += max(0.0, $state->excessBondPremium - $state->excessBondPremiumTrend);
                $burden += max(0.0, $state->catastropheLossIndexEma - 1.0);
                $uncertainty += max(0.0, log(max(1.0, $state->policyUncertaintyIndexEma) / MacroConstants::EPU_BASELINE));
                ++$samples;
            }
        }

        // Four thousand years: the premium's average to about 2%, uncertainty's to about 5%; the catastrophe burden's
        // heavy tail leaves it about 10%.
        $this->assertEqualsWithDelta(DomesticDemand::CREDIT_PREMIUM_NOISE_ADVERSE_MEAN, $premium / $samples, 0.05 * DomesticDemand::CREDIT_PREMIUM_NOISE_ADVERSE_MEAN);
        $this->assertEqualsWithDelta(DomesticDemand::EPU_NOISE_ADVERSE_LOG_MEAN, $uncertainty / $samples, 0.10 * DomesticDemand::EPU_NOISE_ADVERSE_LOG_MEAN);
        $this->assertEqualsWithDelta(DomesticDemand::CATASTROPHE_ADVERSE_BURDEN_MEAN, $burden / $samples, 0.20 * DomesticDemand::CATASTROPHE_ADVERSE_BURDEN_MEAN);
    }
}

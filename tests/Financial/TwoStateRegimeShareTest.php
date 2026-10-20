<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Model\Sector\CommunicationEquipmentBusinessModel;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\TestCase;

/**
 * A regime StreamContext::evolveRegime runs spends onset / (onset + exit) of the quarters active. A premium paid while
 * it runs is compensated by that share; paid in full, the rollout wave lifted the carrier book 5.5% on average.
 */
class TwoStateRegimeShareTest extends TestCase
{
    private const QUARTERS = 400_000;

    public function testTheRegimeSpendsItsStationaryShareActive(): void
    {
        $onset = CommunicationEquipmentBusinessModel::ROLLOUT_WAVE_ONSET_HAZARD;
        $exit = CommunicationEquipmentBusinessModel::ROLLOUT_WAVE_EXIT_HAZARD;
        $share = StochasticProcesses::twoStateStationaryShare($onset, $exit);

        $math = MathUtility::ownStream(2026);
        $momentum = [];
        $active = 0;
        for ($quarter = 0; $quarter < self::QUARTERS; ++$quarter) {
            $context = new StreamContext($momentum, $math);
            $active += $context->evolveRegime('wave', $onset, $exit) > 0 ? 1 : 0;
            $momentum = $context->getStreamZ();
        }

        // Runs of ~12 quarters on and ~33 off: the effective sample is the quarters over the mean cycle length.
        $cycles = self::QUARTERS * $onset * $exit / ($onset + $exit);
        $standardError = sqrt($share * (1.0 - $share) / $cycles);
        $this->assertEqualsWithDelta($share, $active / self::QUARTERS, 4.0 * $standardError);
        $this->assertEqualsWithDelta(0.273, $share, 0.001, 'Control: a wave runs about a quarter of the time.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Diet;

use App\DTO\MacroStateDTO;
use App\Service\Math\MacroConstants;

/** The economy ElectionForecastTest and ElectionForecastRealismTest share. */
trait ElectionForecastFixtures
{
    /** The economy on trend, debt below the Council's line. */
    private function economy(float $totalTime): MacroStateDTO
    {
        return new MacroStateDTO(
            totalTime: $totalTime,
            sovereignDebtToGdp: 0.6,
            potentialGdpIndex: exp((MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + MacroConstants::TFP_DRIFT) * $totalTime),
            consumerPriceLevel: exp(MacroConstants::TARGET_INFLATION * $totalTime),
        );
    }
}

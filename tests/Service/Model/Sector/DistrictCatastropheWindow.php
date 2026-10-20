<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Entity\Stock;

use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;

/**
 * Sets an insurer up for a quarter whose district catastrophe losses run at a given multiple of the window's average:
 * the last report read an average burden a quarter ago, and the burden now holds that report's decay plus the window's
 * losses.
 */
final class DistrictCatastropheWindow
{
    /** Report clock: a winter quarter, so no test reads the storm season by accident. */
    public const TOTAL_TIME = 10.25;

    /**
     * @return array{catastropheLossIndex: float, totalTime: float} Named MacroStateDTO arguments for the quarter.
     */
    public static function prime(Stock $stock, float $lossMultiple, float $totalTime = self::TOTAL_TIME): array
    {
        $window = 0.25;
        $then = 1.0;
        $stock->setEarningsMomentumZ([
            ...($stock->getEarningsMomentumZ() ?? []),
            FinancialConstants::STATE_CATASTROPHE_BURDEN_AT_REPORT => $then,
            FinancialConstants::STATE_CATASTROPHE_BURDEN_READ_AT => $totalTime - $window,
        ]);
        $expected = CommodityLogisticsSubsystem::expectedCatastropheIncurred($totalTime, $window);

        return [
            'catastropheLossIndex' => ($then * exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * $window)) + ($lossMultiple * $expected),
            'totalTime' => $totalTime,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Under the real claim draws, a carrier's float settles around its pinned reserve-to-premium ratio and never
 * drains: the claim shock is a pure risk around the structural cost ratio, and the reserve stock is its
 * running sum, paid out over the runoff lag.
 */
final class InsuranceFloatReserveInvariantTest extends TestCase
{
    private const QUARTERS = 4_000;
    private const QUARTERLY_PREMIUM = 10_000_000_000.0;
    private const MARGIN = 0.10;
    /** Fixed share that puts the structural claims ratio (1 − margin)(1 − fixed) on the cost ratio the physics is handed. */
    private const FIXED_COST_RATIO = 1.0 / 3.0;
    private const STRUCTURAL_COST_RATIO = 0.60;

    /** @return iterable<string, array{InsuranceBusinessModel}> */
    public static function carriers(): iterable
    {
        yield 'specialty' => [new InsuranceBusinessModel()];
        yield 'retail' => [new RetailInsuranceBusinessModel()];
        yield 'reinsurance' => [new ReinsuranceBusinessModel()];
    }

    #[DataProvider('carriers')]
    public function testFloatSettlesAroundItsReserveRatio(InsuranceBusinessModel $model): void
    {
        $math = MathUtility::ownStream(20261007);
        $macro = new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04);
        $momentum = [];
        $stock = $this->carrier($momentum);
        $ratio = $model->resolveReserveToPremiumRatio($stock);
        $runoffYears = $model->resolveReserveRunoffYears($stock);
        $reserves = $ratio * self::QUARTERLY_PREMIUM * 4.0;

        $sum = 0.0;
        $lowest = INF;
        for ($i = 0; $i < self::QUARTERS; $i++) {
            $stock = $this->carrier($momentum);
            $result = $model->computeActualFinancials($stock, self::QUARTERLY_PREMIUM, self::STRUCTURAL_COST_RATIO, 1.0e9, 0.0, $macro, $math);
            $momentum = $result->streamZ;

            $reserves = $model->rollLossReserves($reserves, (float) $momentum[InsuranceBusinessModel::STATE_INCURRED_CLAIMS], $runoffYears)['reserves'];
            $floatToPremium = $reserves / (self::QUARTERLY_PREMIUM * 4.0);
            $sum += $floatToPremium;
            $lowest = min($lowest, $floatToPremium);
        }

        $mean = $sum / self::QUARTERS;
        $this->assertEqualsWithDelta($ratio, $mean, 0.02 * $ratio, sprintf('%s: mean float %.3f× premium against %.3f×.', $model::class, $mean, $ratio));
        $this->assertGreaterThan(0.75 * $ratio, $lowest, 'A benign run of quarters thins the float; it does not drain it.');
    }

    /** @param array<string, float> $momentum */
    private function carrier(array $momentum): Stock
    {
        $stock = new Stock();
        $stock->setTicker('MEAN');
        $stock->setBeta('1.0');
        $stock->setOperatingMargin((string) self::MARGIN);
        $stock->setFixedCostRatio(self::FIXED_COST_RATIO);
        $stock->setTotalEquity('200000000000');
        $stock->setEarningsMomentumZ($momentum);

        return $stock;
    }
}

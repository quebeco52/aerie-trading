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
    /** Fixed share that puts the structural variable cost ratio (1 − margin)(1 − fixed) on the cost ratio the physics is handed. */
    private const FIXED_COST_RATIO = 1.0 / 3.0;
    private const STRUCTURAL_COST_RATIO = 0.60;
    /** The fixed costs that go with it: (1 − margin) × fixed share of a quarter's premium. */
    private const FIXED_COSTS = 3.0e9;

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
        $stock = $this->carrier();
        $ratio = $model->resolveReserveToPremiumRatio($stock);
        $stock->setCustomerDeposits((string) ($ratio * self::QUARTERLY_PREMIUM * 4.0));

        $sum = 0.0;
        $lowest = INF;
        for ($i = 0; $i < self::QUARTERS; $i++) {
            $result = $model->computeActualFinancials($stock, self::QUARTERLY_PREMIUM, self::STRUCTURAL_COST_RATIO, self::FIXED_COSTS, 0.0, $macro, $math);
            $stock->setEarningsMomentumZ($result->streamZ);

            $state = ['treasury' => 0.0, 'customerDeposits' => (float) $stock->getCustomerDeposits(), 'wholesaleDebt' => 0.0, 'events' => []];
            $model->processPassiveLiabilityGrowth($stock, $macro, $state, $math);
            $floatToPremium = $state['customerDeposits'] / (self::QUARTERLY_PREMIUM * 4.0);
            $sum += $floatToPremium;
            $lowest = min($lowest, $floatToPremium);
        }

        $mean = $sum / self::QUARTERS;
        $this->assertEqualsWithDelta($ratio, $mean, 0.02 * $ratio, sprintf('%s: mean float %.3f× premium against %.3f×.', $model::class, $mean, $ratio));
        $this->assertGreaterThan(0.75 * $ratio, $lowest, 'A benign run of quarters thins the float; it does not drain it.');
    }

    private function carrier(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('MEAN');
        $stock->setBeta('1.0');
        $stock->setOperatingMargin((string) self::MARGIN);
        $stock->setFixedCostRatio(self::FIXED_COST_RATIO);
        $stock->setTotalEquity('200000000000');

        return $stock;
    }
}

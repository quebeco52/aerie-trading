<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A stream's own demand draw moves its revenue, and the fixed cost base turns that into operating leverage. Variable
 * cost is per unit, so it stays linear in the draw: a margin term that also lowered the cost ratio with the same draw
 * (scale, attach, learning-curve or cachet elasticities) counted the leverage twice and bent costs quadratic.
 */
#[AllowMockObjectsWithoutExpectations]
final class DemandDrawLeavesCostRatioTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<OperatingStrategyInterface>, string, float}> Model, stream, its AR(1) phi.
     */
    public static function streams(): iterable
    {
        yield 'tools commercial' => [Sector\ToolsAndAccessoriesBusinessModel::class, 'commercial', 0.02];
        yield 'hardware enterprise' => [Sector\ComputerHardwareBusinessModel::class, 'enterprise_hardware', 0.15];
        yield 'telecom equipment networks' => [Sector\CommunicationEquipmentBusinessModel::class, 'carrier_networks', 0.25];
        yield 'defence export sales' => [Sector\DefenseContractorBusinessModel::class, 'foreign_military_sales', 0.20];
        yield 'software subscriptions' => [Sector\TechBusinessModel::class, 'subscription', 0.45];
        yield 'rating transactions' => [Sector\FinancialDataBusinessModel::class, 'transaction', 0.15];
        yield 'biotech pipeline' => [Sector\BiotechBusinessModel::class, 'pipeline_licensing_milestones', Sector\BiotechBusinessModel::PIPELINE_PERSISTENCE_PHI];
        yield 'restaurant royalties' => [Sector\RestaurantBusinessModel::class, 'franchise_royalties', 0.20];
        yield 'luxury couture' => [Sector\LuxuryBusinessModel::class, 'haute_couture', 0.40];
    }

    /** @param class-string<OperatingStrategyInterface> $model */
    #[DataProvider('streams')]
    public function testVariableCostIsLinearInTheStreamsOwnDraw(string $model, string $stream, float $phi): void
    {
        $calm = $this->quarter($model, $stream, $phi, 0.0);
        $up = $this->quarter($model, $stream, $phi, 1.0);
        $further = $this->quarter($model, $stream, $phi, 2.0);

        $this->assertGreaterThan($calm['revenue'] * 1.0001, $up['revenue'], 'The draw must move revenue for the test to have power.');
        $this->assertEqualsWithDelta($up['costs'] - $calm['costs'], $further['costs'] - $up['costs'], 1e-6 * $calm['costs']);
    }

    /**
     * One quarter with the stream's draw at $z and every other draw at zero: with no fresh innovation, an AR(1)
     * stream reads phi times its carried score.
     *
     * @param class-string<OperatingStrategyInterface> $model
     * @return array{revenue: float, costs: float}
     */
    private function quarter(string $model, string $stream, float $phi, float $z): array
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal', 'generateUniform', 'checkProbability'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);
        $math->method('generateUniform')->willReturn(0.5);
        $math->method('checkProbability')->willReturn(false);

        $stock = (new Stock())->setTicker('DRAW')->setBeta('1.0')->setTotalEquity('500000000');
        $stock->setEarningsMomentumZ([$stream => $z / $phi]);
        $result = (new $model())->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, new MacroStateDTO(), $math);

        return ['revenue' => $result->actualRevenue, 'costs' => $result->actualVariableCosts];
    }
}

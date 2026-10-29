<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector\InsuranceBrokerBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class InsuranceBrokerBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;

    private InsuranceBrokerBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new InsuranceBrokerBusinessModel();
    }

    /** One quarter with every draw at zero, at the neutral rate unless the macro says otherwise. */
    private function runQuarter(MacroStateDTO $macro): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('BROKER_TEST')->setBeta('1.00');

        return $this->model->computeActualFinancials($stock, self::EXPECTED_REVENUE, 0.50, 30_000_000.0, 0.0, $macro, new ScriptedDraws());
    }

    private static function macro(float $policyRate = 0.03, float $rateLevel = 0.0, float $rateTrend = 0.0): MacroStateDTO
    {
        return new MacroStateDTO(policyRateEma: $policyRate, perceivedNeutralRate: 0.03, insuranceRateLevel: $rateLevel, insuranceRateTrend: $rateTrend);
    }

    public function testHoneyguideIsTheListedBroker(): void
    {
        $rows = array_values(array_filter(InitialMarket::STOCKS, static fn(array $row): bool => $row['ticker'] === 'HONY'));
        $this->assertCount(1, $rows);
        $this->assertSame('Honeyguide Partners', $rows[0]['name']);
        $this->assertInstanceOf(InsuranceBrokerBusinessModel::class, Sectors::strategyFor($rows[0]['industry']));
    }

    public function testACalmQuarterEarnsThePlanMix(): void
    {
        $result = $this->runQuarter(self::macro());

        $this->assertEqualsWithDelta(70_000_000.0, $result->streamRevenue['commissions'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['benefits'], 1.0);
        $this->assertEqualsWithDelta(5_000_000.0, $result->streamRevenue['fiduciary'], 1.0);
    }

    /** A market a fifth over its rate trend lifts commissions by the pass-through share of that fifth. */
    public function testCommissionsFollowTheUnderwritersRateLevelOverItsTrend(): void
    {
        $calm = $this->runQuarter(self::macro(rateLevel: 1.0, rateTrend: 1.0));
        $hard = $this->runQuarter(self::macro(rateLevel: 1.2, rateTrend: 1.0));

        $this->assertEqualsWithDelta(1.0 + (0.2 * InsuranceBrokerBusinessModel::COMMISSION_RATE_PASS_THROUGH), $hard->streamRevenue['commissions'] / $calm->streamRevenue['commissions'], 1e-9);
        $this->assertEqualsWithDelta($calm->streamRevenue['benefits'], $hard->streamRevenue['benefits'], 1e-6);
    }

    /** Held premiums earn the policy rate: half the neutral rate halves the line, and the zero bound empties it. */
    public function testFiduciaryIncomeFollowsThePolicyRate(): void
    {
        $this->assertEqualsWithDelta(2_500_000.0, $this->runQuarter(self::macro(policyRate: 0.015))->streamRevenue['fiduciary'], 1.0);
        $this->assertSame(0.0, $this->runQuarter(self::macro(policyRate: 0.0))->streamRevenue['fiduciary']);
    }

    /**
     * A persistent market term moves each quarter's revenue but not the mix the weights drift with: carried into them,
     * fiduciary interest at twice the neutral rate compounded until the line took over.
     */
    public function testAPersistentMarketTermLeavesTheMixWeightsWhereTheyWere(): void
    {
        $stock = (new Stock())->setTicker('BROKER_TEST')->setBeta('1.00');
        $macro = self::macro(policyRate: 0.06, rateLevel: 1.3, rateTrend: 1.0);
        for ($quarter = 0; $quarter < 12; $quarter++) {
            $result = $this->model->computeActualFinancials($stock, self::EXPECTED_REVENUE, 0.50, 30_000_000.0, 0.0, $macro, new ScriptedDraws());
            $stock->setEarningsMomentumZ($result->streamZ);
        }

        $this->assertEqualsWithDelta(InsuranceBrokerBusinessModel::FIDUCIARY_WEIGHT, $result->streamZ['weight:fiduciary'], 1e-9);
        $this->assertEqualsWithDelta(InsuranceBrokerBusinessModel::COMMISSION_WEIGHT, $result->streamZ['weight:commissions'], 1e-9);
        $this->assertEqualsWithDelta(10_000_000.0, $result->streamRevenue['fiduciary'], 1.0);
    }

    /**
     * The market's rate reading is each underwriter's earned rate on its non-life premium: a composite reports the
     * property and casualty share of its revenue, and a book that has not filed reports nothing.
     */
    public function testUnderwritersReportTheirNonLifePremiumAtTheRateTheyEarn(): void
    {
        $composite = new RetailInsuranceBusinessModel();
        $stock = (new Stock())->setTicker('DOVE');
        $stock->setTotalRevenue('10000000000');
        $this->assertSame(0.0, $composite->propertyCasualtyPremiumReading($stock)['premium']);

        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_EARNED_RATE_LEVEL => 1.15]);
        $reading = $composite->propertyCasualtyPremiumReading($stock);
        $this->assertEqualsWithDelta(10_000_000_000.0 * (1.0 - $composite->resolveLifePremiumShare($stock)), $reading['premium'], 1.0);
        $this->assertSame(1.15, $reading['rate_level']);
    }
}

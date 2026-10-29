<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\LifeInsurerBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class LifeInsurerBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 10_000_000_000.0;
    private const VARIABLE_COST_RATIO = 0.80;
    private const FIXED_COSTS = 500_000_000.0;

    private LifeInsurerBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new LifeInsurerBusinessModel();
    }

    private function stock(): Stock
    {
        $stock = (new Stock())->setTicker('WELL')->setBeta('1.00');
        $stock->setTotalEquity('60000000000');
        $stock->setCustomerDeposits('432000000000');
        $stock->setCorporateTreasury('432000000000');

        return $stock;
    }

    /**
     * One quarter with every draw at zero.
     *
     * @param array<string, float> $momentum
     */
    private function runQuarter(MacroStateDTO $macro, array $momentum = [], ?Stock $stock = null): ActualFinancialsDTO
    {
        $stock ??= $this->stock();
        $stock->setEarningsMomentumZ($momentum);

        return $this->model->computeActualFinancials($stock, self::EXPECTED_REVENUE, self::VARIABLE_COST_RATIO, self::FIXED_COSTS, 0.0, $macro, new ScriptedDraws());
    }

    public function testWellspringIsTheListedLifeInsurer(): void
    {
        $rows = array_values(array_filter(InitialMarket::STOCKS, static fn(array $row): bool => $row['ticker'] === 'WELL'));
        $this->assertCount(1, $rows);
        $this->assertSame('Wellspring Life', $rows[0]['name']);
        $this->assertInstanceOf(LifeInsurerBusinessModel::class, Sectors::strategyFor($rows[0]['industry']));
    }

    public function testACalmQuarterEarnsThePlanMix(): void
    {
        $result = $this->runQuarter(new MacroStateDTO());

        $this->assertEqualsWithDelta(3_500_000_000.0, $result->streamRevenue['protection'], 1.0);
        $this->assertEqualsWithDelta(4_500_000_000.0, $result->streamRevenue['annuities'], 1.0);
        $this->assertEqualsWithDelta(2_000_000_000.0, $result->streamRevenue['savings_fees'], 1.0);
    }

    /** Single premiums rise by the semi-elasticity per point the thirty-year yield stands over its trend. */
    public function testAnnuitySalesFollowTheLongYieldOverItsTrend(): void
    {
        $macro = new MacroStateDTO(yield30y: 0.05, yield30yEma: 0.05);
        $first = $this->runQuarter($macro);
        $this->assertEqualsWithDelta(4_500_000_000.0, $first->streamRevenue['annuities'], 1.0, 'The trend opens at the first reading.');

        $momentum = $first->streamZ;
        $momentum[LifeInsurerBusinessModel::STATE_ANNUITY_YIELD_TREND] = 0.04;
        $high = $this->runQuarter($macro, $momentum);

        $this->assertEqualsWithDelta(exp(LifeInsurerBusinessModel::ANNUITY_YIELD_SEMI_ELASTICITY * 0.01), $high->streamRevenue['annuities'] / $first->streamRevenue['annuities'], 1e-9);
        $this->assertEqualsWithDelta($first->streamRevenue['protection'], $high->streamRevenue['protection'], 1e-6);
    }

    /** Savings fees move with the market at the funds' equity share. */
    public function testSavingsFeesFollowTheMarketsAtTheFundsEquityShare(): void
    {
        $calm = $this->runQuarter(new MacroStateDTO());
        $slump = $this->runQuarter(new MacroStateDTO(equityWealthRatio: 0.8, equityWealthTrend: 1.0));

        $this->assertEqualsWithDelta(1.0 - (0.2 * LifeInsurerBusinessModel::SAVINGS_EQUITY_SHARE), $slump->streamRevenue['savings_fees'] / $calm->streamRevenue['savings_fees'], 1e-9);
    }

    /** Premiums buy benefits that build the policy reserve; savings fees build none, and nothing is a non-life loss. */
    public function testOnlyThePremiumsBuildThePolicyReserve(): void
    {
        $result = $this->runQuarter(new MacroStateDTO());
        $life = $result->streamZ[InsuranceBusinessModel::STATE_INCURRED_LIFE_BENEFITS];

        $this->assertGreaterThan(0.0, $life);
        $this->assertSame($life, $result->streamZ[InsuranceBusinessModel::STATE_INCURRED_CLAIMS]);
        $this->assertSame(0.0, $result->streamZ[InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE]);

        $premiums = $result->streamRevenue['protection'] + $result->streamRevenue['annuities'];
        $benefitRatio = (self::VARIABLE_COST_RATIO * (1.0 - InsuranceBusinessModel::BASE_EXPENSE_RATIO_SHARE))
            + (InsuranceBusinessModel::FIXED_COST_LAE_SHARE * self::FIXED_COSTS / self::EXPECTED_REVENUE);
        $this->assertEqualsWithDelta($premiums * $benefitRatio, $life, 1.0);
    }

    /**
     * A falling book yield cuts what the current policies credit and leaves the guaranteed share at its guarantee, so
     * the spread on that share closes; with the yield well above the guarantee both earn the same spread.
     */
    public function testTheGuaranteeHoldsTheCreditedRateUpWhenTheBookYieldFalls(): void
    {
        $stock = $this->stock();
        $method = new \ReflectionMethod($this->model, 'resolveLifeCreditingRate');

        $stock->setSecuritiesCarryingYield(0.05);
        $this->assertEqualsWithDelta(0.05 - InsuranceBusinessModel::LIFE_INVESTMENT_SPREAD, $method->invoke($this->model, $stock, new MacroStateDTO()), 1e-12);

        $stock->setSecuritiesCarryingYield(0.03);
        $current = 0.03 - InsuranceBusinessModel::LIFE_INVESTMENT_SPREAD;
        $this->assertEqualsWithDelta((0.40 * 0.025) + (0.60 * $current), $method->invoke($this->model, $stock, new MacroStateDTO()), 1e-12);
    }

    public function testALifeBookWritesNoPropertyCasualtyPremiumAndEarnsNoUnderwritingCycle(): void
    {
        $stock = $this->stock();
        $stock->setTotalRevenue('40000000000');
        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_EARNED_RATE_LEVEL => 1.3]);

        $this->assertSame(0.0, $this->model->propertyCasualtyPremiumReading($stock)['premium']);
        $this->assertSame(1.0, $this->model->getMacroPhysics($stock, new MacroStateDTO())['pricing_power_multiplier']);
    }

    /** The settled reserve is six times the premium, which is four fifths of revenue. */
    public function testTheReserveRatioIsStruckOnPremiumNotFees(): void
    {
        $ratios = $this->model->resolveReserveRatios($this->stock());

        $this->assertSame(0.0, $ratios['unearned']);
        $this->assertSame(0.0, $ratios['loss']);
        $this->assertEqualsWithDelta(0.80 * 6.0, $ratios['life'], 1e-12);
    }
}

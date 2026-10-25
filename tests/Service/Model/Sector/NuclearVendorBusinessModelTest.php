<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Model\Sector\NuclearVendorBusinessModel;
use App\Service\Model\StreamContext;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class NuclearVendorBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_COST_RATIO = 0.40;
    private const BACKLOG_KEY = StreamContext::BACKLOG_STATE_PREFIX . 'new_build';
    /** Quarters of new-build revenue in the backlog at steady state: (1 - burn) / burn. */
    private const STEADY_BACKLOG = (1.0 - NuclearVendorBusinessModel::BUILD_BURN_RATE) / NuclearVendorBusinessModel::BUILD_BURN_RATE;

    private NuclearVendorBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new NuclearVendorBusinessModel();
    }

    /** Energy at its decade trend and the ten-year at its neutral level, unless a test moves them. */
    private static function macro(float $energy = 0.0, float $financing = 0.0, float $carbonPrice = 0.0): MacroStateDTO
    {
        return new MacroStateDTO(
            energyPriceIndexEma: MacroConstants::ENERGY_BASELINE * 1.20 * exp($energy),
            energyPriceIndexDecadeTrend: MacroConstants::ENERGY_BASELINE * 1.20,
            yield10yEma: MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION + MacroConstants::NS_BASE_TERM_PREMIUM + $financing,
            perceivedNeutralRate: MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION,
            carbonPrice: $carbonPrice,
        );
    }

    /**
     * One quarter with every normal at zero; checkProbability answers the accident onset, the overrun onset and the
     * order draw in that order, false unless scripted.
     *
     * @param array<string, float> $momentum
     * @param list<bool>|\Closure  $probabilities
     */
    private function runQuarter(array $momentum = [], array|\Closure $probabilities = []): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('NUCLEAR_TEST')->setBeta('1.00');
        $stock->setEarningsMomentumZ($momentum);

        return $this->model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            10_000_000.0,
            0.0,
            self::macro(),
            new ScriptedDraws(generatePersistentZ: fn(float $prev): float => 0.0, checkProbability: $probabilities),
        );
    }

    private static function accidentShare(): float
    {
        return StochasticProcesses::twoStateStationaryShare(NuclearVendorBusinessModel::ACCIDENT_ONSET_HAZARD, NuclearVendorBusinessModel::ACCIDENT_EXIT_HAZARD);
    }

    /** Contract size relative to a year of plan billing: the orders between accidents make up the work they cancel. */
    private static function compensator(): float
    {
        $cancelled = NuclearVendorBusinessModel::ACCIDENT_ONSET_HAZARD * (1.0 - self::accidentShare()) * NuclearVendorBusinessModel::ACCIDENT_BACKLOG_CANCELLATION;

        return 1.0 + ($cancelled * self::STEADY_BACKLOG);
    }

    private static function overrunShare(): float
    {
        return StochasticProcesses::twoStateStationaryShare(NuclearVendorBusinessModel::OVERRUN_ONSET_HAZARD, NuclearVendorBusinessModel::OVERRUN_EXIT_HAZARD);
    }

    public function testAQuietQuarterBillsTheFleetAndTheBacklogItOpenedWith(): void
    {
        $result = $this->runQuarter();

        // No accident: the years between them carry the reloads an accident idles and give back its retrofits.
        $this->assertEqualsWithDelta(40_000_000.0 * (1.0 + (NuclearVendorBusinessModel::ACCIDENT_FUEL_CUT * self::accidentShare())), $result->streamRevenue['fuel'], 1.0);
        $this->assertEqualsWithDelta(35_000_000.0 * (1.0 - (NuclearVendorBusinessModel::ACCIDENT_RETROFIT_LIFT * self::accidentShare())), $result->streamRevenue['plant_services'], 1.0);
        // No order this quarter: the steady backlog alone is built, a twentieth of it.
        $this->assertEqualsWithDelta(25_000_000.0 * NuclearVendorBusinessModel::BUILD_BURN_RATE * self::STEADY_BACKLOG, $result->streamRevenue['new_build'], 1.0);
        $this->assertNull($result->eventType);
    }

    public function testAPlantContractIsAYearOfBillingBookedWholeAndBuiltOverFiveYears(): void
    {
        $result = $this->runQuarter(probabilities: [false, false, true]);
        $order = self::compensator() / NuclearVendorBusinessModel::ORDER_PROBABILITY;
        $available = self::STEADY_BACKLOG + $order;

        $this->assertEqualsWithDelta(4.0, $order, 0.25);
        $this->assertEqualsWithDelta(25_000_000.0 * NuclearVendorBusinessModel::BUILD_BURN_RATE * $available, $result->streamRevenue['new_build'], 1.0);
        $this->assertEqualsWithDelta($available * (1.0 - NuclearVendorBusinessModel::BUILD_BURN_RATE), $result->streamZ[self::BACKLOG_KEY], 1e-9);
        $this->assertGreaterThan(1.0, $result->kpis['book_to_bill']);
    }

    public function testDearEnergyCheapFinanceAndACarbonPriceBringOrdersForward(): void
    {
        $neutral = $this->model->resolveOrderProbability(self::macro());
        $this->assertEqualsWithDelta(NuclearVendorBusinessModel::ORDER_PROBABILITY, $neutral, 1e-12);

        // Energy is read against its decade trend, not the base it is built from: the macro helper sits both 20% above.
        $dearEnergy = $this->model->resolveOrderProbability(self::macro(energy: 0.30));
        $this->assertEqualsWithDelta(1.0 + (NuclearVendorBusinessModel::ORDER_ENERGY_SENSITIVITY * 0.30), $dearEnergy / $neutral, 1e-12);

        // A point on long rates costs a fifth of the orders.
        $dearMoney = $this->model->resolveOrderProbability(self::macro(financing: 0.01));
        $this->assertEqualsWithDelta(0.80, $dearMoney / $neutral, 1e-12);

        // A carbon price lifts the District's own orders only, by what it adds to the District's power price.
        $carbon = $this->model->resolveOrderProbability(self::macro(carbonPrice: 50.0));
        $uplift = CommodityLogisticsSubsystem::carbonPowerPriceUplift(50.0);
        $this->assertGreaterThan(0.0, $uplift);
        $this->assertEqualsWithDelta(1.0 + (NuclearVendorBusinessModel::HOME_ORDER_SHARE * $uplift), $carbon / $neutral, 1e-12);
    }

    public function testAnAccidentIdlesFuelFreezesOrdersAndOrdersRetrofits(): void
    {
        $orderChance = null;
        $result = $this->runQuarter(
            [StreamContext::REGIME_STATE_PREFIX . NuclearVendorBusinessModel::REGIME_ACCIDENT => 2.0],
            function (float $probability) use (&$orderChance): bool {
                $orderChance = $probability;

                return false;
            },
        );
        $share = self::accidentShare();

        $this->assertEqualsWithDelta(40_000_000.0 * (1.0 - (NuclearVendorBusinessModel::ACCIDENT_FUEL_CUT * (1.0 - $share))), $result->streamRevenue['fuel'], 1.0);
        $this->assertEqualsWithDelta(35_000_000.0 * (1.0 + (NuclearVendorBusinessModel::ACCIDENT_RETROFIT_LIFT * (1.0 - $share))), $result->streamRevenue['plant_services'], 1.0);
        // The last draw is the order: frozen to the share the reviews let through.
        $frozen = NuclearVendorBusinessModel::ORDER_PROBABILITY * (1.0 - (NuclearVendorBusinessModel::ACCIDENT_ORDER_CUT * (1.0 - $share)));
        $this->assertEqualsWithDelta($frozen, $orderChance, 1e-12);

        // Over the regime's stationary distribution orders, reloads and service work average the plan.
        $peace = NuclearVendorBusinessModel::ORDER_PROBABILITY * (1.0 + (NuclearVendorBusinessModel::ACCIDENT_ORDER_CUT * $share));
        $this->assertEqualsWithDelta(NuclearVendorBusinessModel::ORDER_PROBABILITY, ($share * $frozen) + ((1.0 - $share) * $peace), 1e-12);
    }

    public function testAnAccidentCancelsAFifthOfThePlantBookAtOnce(): void
    {
        $onset = $this->runQuarter(probabilities: [true, false, false]);
        $cancelled = self::STEADY_BACKLOG * (1.0 - NuclearVendorBusinessModel::ACCIDENT_BACKLOG_CANCELLATION);

        $this->assertSame(ShockEvent::REACTOR_ACCIDENT, $onset->eventType);
        $this->assertEqualsWithDelta(25_000_000.0 * NuclearVendorBusinessModel::BUILD_BURN_RATE * $cancelled, $onset->streamRevenue['new_build'], 1.0);
        $this->assertEqualsWithDelta($cancelled * (1.0 - NuclearVendorBusinessModel::BUILD_BURN_RATE), $onset->streamZ[self::BACKLOG_KEY], 1e-9);

        // Once the accident is under way the book is not cut again.
        $ongoing = $this->runQuarter([StreamContext::REGIME_STATE_PREFIX . NuclearVendorBusinessModel::REGIME_ACCIDENT => 2.0]);
        $this->assertEqualsWithDelta(25_000_000.0 * NuclearVendorBusinessModel::BUILD_BURN_RATE * self::STEADY_BACKLOG, $ongoing->streamRevenue['new_build'], 1.0);
    }

    /** At the expected cancellation and the compensated intake the book bills its plan, quarter after quarter. */
    public function testTheContractsWonBetweenAccidentsMakeUpTheWorkTheyCancel(): void
    {
        $cancelledPerQuarter = NuclearVendorBusinessModel::ACCIDENT_ONSET_HAZARD * (1.0 - self::accidentShare()) * NuclearVendorBusinessModel::ACCIDENT_BACKLOG_CANCELLATION;
        $momentum = [];
        $book = [];
        for ($quarter = 0; $quarter < 400; $quarter++) {
            $context = new StreamContext($momentum, new MathUtility());
            $book = $context->recognizeBacklog('new_build', 1000.0, self::compensator(), NuclearVendorBusinessModel::BUILD_BURN_RATE, $cancelledPerQuarter);
            $momentum = $context->getStreamZ();
        }

        $this->assertEqualsWithDelta(1000.0, $book['revenue'], 1e-6);
        $this->assertGreaterThan(1.0, self::compensator());
    }

    public function testAnOverrunRunsThePlantsAtALossUntilTheyAreDelivered(): void
    {
        $calm = $this->runQuarter();
        $overrun = $this->runQuarter([StreamContext::REGIME_STATE_PREFIX . NuclearVendorBusinessModel::REGIME_PLANT_OVERRUN => 3.0]);

        $this->assertEqualsWithDelta($calm->actualRevenue, $overrun->actualRevenue, 1.0);
        $this->assertEqualsWithDelta(NuclearVendorBusinessModel::OVERRUN_COST * $overrun->streamRevenue['new_build'], $overrun->actualVariableCosts - $calm->actualVariableCosts, 1.0);

        // The contract prices carry the average overrun: over the regime's stationary distribution construction costs
        // its planned multiple.
        $fleetCosts = self::VARIABLE_COST_RATIO * (1.0 - (0.25 * NuclearVendorBusinessModel::NEW_BUILD_COST_MULTIPLE)) / 0.75 * ($calm->streamRevenue['fuel'] + $calm->streamRevenue['plant_services']);
        $calmRatio = ($calm->actualVariableCosts - $fleetCosts) / $calm->streamRevenue['new_build'];
        $overrunRatio = ($overrun->actualVariableCosts - $fleetCosts) / $overrun->streamRevenue['new_build'];
        $share = self::overrunShare();
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO * NuclearVendorBusinessModel::NEW_BUILD_COST_MULTIPLE, ($share * $overrunRatio) + ((1.0 - $share) * $calmRatio), 1e-9);
    }

    public function testConstructionCostsMoreThanTheFleetAndThePlannedMixCostsThePlannedRatio(): void
    {
        $result = $this->runQuarter();
        $newBuild = $result->streamRevenue['new_build'];
        $fleet = $result->streamRevenue['fuel'] + $result->streamRevenue['plant_services'];
        $fleetRatio = self::VARIABLE_COST_RATIO * (1.0 - (0.25 * NuclearVendorBusinessModel::NEW_BUILD_COST_MULTIPLE)) / 0.75;
        $newBuildRatio = (self::VARIABLE_COST_RATIO * NuclearVendorBusinessModel::NEW_BUILD_COST_MULTIPLE) - (NuclearVendorBusinessModel::OVERRUN_COST * self::overrunShare());

        $this->assertEqualsWithDelta(($fleetRatio * $fleet) + ($newBuildRatio * $newBuild), $result->actualVariableCosts, 1.0);
        $this->assertGreaterThan($fleetRatio, $newBuildRatio);
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO, (0.75 * $fleetRatio) + (0.25 * self::VARIABLE_COST_RATIO * NuclearVendorBusinessModel::NEW_BUILD_COST_MULTIPLE), 1e-12);
    }

    public function testTheVendorIsStaffedToThePlantsInHand(): void
    {
        $stock = (new Stock())->setTicker('NUCLEAR_TEST');
        $stock->setEarningsMomentumZ([self::BACKLOG_KEY => 2.0 * self::STEADY_BACKLOG]);
        $workload = NuclearVendorBusinessModel::BUILD_BURN_RATE * ((2.0 * self::STEADY_BACKLOG) + 1.0);

        $this->assertEqualsWithDelta(0.25 * ($workload - 1.0), $this->model->resolveSectorActivityShift($stock, self::macro()), 1e-12);
    }
}

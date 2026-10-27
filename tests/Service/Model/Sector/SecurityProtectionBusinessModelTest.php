<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\StockModelTuning;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;
use App\Service\Model\Sector\SecurityProtectionBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SecurityProtectionBusinessModelTest extends TestCase
{
    private SecurityProtectionBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new SecurityProtectionBusinessModel();
    }

    public function testTacticalFailureShockTriggersSecurityBreachEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('SEC');
        $stock->setBeta('1.0');

        // govZ = 0.0, retainerZ = 0.0, expeditionaryZ = 0.0, eventZ = -3.0 (triggers tactical failure < -2.50)
        $mathUtilityMock = new ScriptedDraws(generatePersistentZ: [0.0, 0.0, 0.0, -3.0]);

        $macroState = new MacroStateDTO(
            outputGap: 0.0,
            outputGapEma: 0.0,
            unemploymentRate: 0.04,
            unemploymentRateEma: 0.04,
            energyPriceIndex: 100.0,
            energyPriceIndexEma: 100.0,
            energyPriceShock: 0.0,
            consumerSentimentIndex: 100.0,
            consumerSentimentIndexEma: 100.0,
            inflation: 0.02,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            targetRate: 0.04,
            yield2y: 0.04,
            yield2yEma: 0.04,
            yield5y: 0.04,
            yield5yEma: 0.04,
            yield10y: 0.04,
            yield10yEma: 0.04,
            yield30y: 0.04,
            yield30yEma: 0.04,
            marketVolatility: 0.15,
            marketVolatilityEma: 0.15,
            marketZ: 0.0,
            corporateTaxRate: 0.21,
            equityRiskPremium: 0.05,
            macroCreditSpread: 0.015,
            macroCreditSpreadEma: 0.015,
            qeActive: false,
            inversionDuration: 0.0,
            nsLevel: 0.04,
            nsSlope: 0.0,
            nsSlopeEma: 0.0,
            nsCurvature: 0.0,
            potentialGdpIndex: 1.0,
            nominalGdpIndex: 1.0,
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertEquals(ShockEvent::SECURITY_BREACH, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testGeopoliticalConflictShockTriggersConflictEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('SEC');
        $stock->setBeta('1.0');

        // govZ = 0.0, retainerZ = 0.0, expeditionaryZ = 0.0, eventZ = 3.0 (triggers conflict > 2.40)
        $mathUtilityMock = new ScriptedDraws(generatePersistentZ: [0.0, 0.0, 0.0, 3.0]);

        $macroState = new MacroStateDTO(
            outputGap: 0.0,
            outputGapEma: 0.0,
            unemploymentRate: 0.04,
            unemploymentRateEma: 0.04,
            energyPriceIndex: 100.0,
            energyPriceIndexEma: 100.0,
            energyPriceShock: 0.0,
            consumerSentimentIndex: 100.0,
            consumerSentimentIndexEma: 100.0,
            inflation: 0.02,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            targetRate: 0.04,
            yield2y: 0.04,
            yield2yEma: 0.04,
            yield5y: 0.04,
            yield5yEma: 0.04,
            yield10y: 0.04,
            yield10yEma: 0.04,
            yield30y: 0.04,
            yield30yEma: 0.04,
            marketVolatility: 0.15,
            marketVolatilityEma: 0.15,
            marketZ: 0.0,
            corporateTaxRate: 0.21,
            equityRiskPremium: 0.05,
            macroCreditSpread: 0.015,
            macroCreditSpreadEma: 0.015,
            qeActive: false,
            inversionDuration: 0.0,
            nsLevel: 0.04,
            nsSlope: 0.0,
            nsSlopeEma: 0.0,
            nsCurvature: 0.0,
            potentialGdpIndex: 1.0,
            nominalGdpIndex: 1.0,
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertEquals(ShockEvent::GEOPOLITICAL_CONFLICT, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testEachLineCarriesItsOwnFailure(): void
    {
        // Nightjar sells no guarding and works nowhere abroad: it draws its posts, screening, cases, then a leak.
        $this->assertSame(0.0, StockModelTuning::get('NJAR', ModelParam::ExpeditionaryWeight, 1.0));
        $stock = (new Stock())->setTicker('NJAR')->setBeta('1.0');

        $leak = $this->model->computeActualFinancials($stock, 1000.0, 0.60, 50.0, 0.15, new MacroStateDTO(), new ScriptedDraws(generatePersistentZ: [0.0, 0.0, 0.0, -3.0]));
        $calm = $this->model->computeActualFinancials($stock, 1000.0, 0.60, 50.0, 0.15, new MacroStateDTO(), new ScriptedDraws(generatePersistentZ: [0.0, 0.0, 0.0, 3.0]));

        $this->assertSame(ShockEvent::CLIENT_FILE_LEAK, $leak->eventType);
        $hit = $leak->streamRevenue['screening'] + $leak->streamRevenue['investigations'];
        $this->assertEqualsWithDelta(SecurityProtectionBusinessModel::LEAK_CHARGE * $hit, $leak->specialCharge, 1e-9);
        $this->assertEqualsWithDelta($calm->streamRevenue['government_contracts'], $leak->streamRevenue['government_contracts'], 1e-9);
        $this->assertEqualsWithDelta(SecurityProtectionBusinessModel::LEAK_REV_MULT * $calm->streamRevenue['screening'], $leak->streamRevenue['screening'], 1e-9);

        // A draw that would be a conflict at Osprey is nothing at a firm with no deployments.
        $this->assertNull($calm->eventType);
        $this->assertArrayNotHasKey('expeditionary_ops', $calm->streamRevenue);
    }

    public function testScreeningFollowsDealsAndCasesFollowDefaults(): void
    {
        $stock = (new Stock())->setTicker('NJAR')->setBeta('1.0');
        $spread = MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN;
        $calm = $this->model->computeActualFinancials($stock, 1000.0, 0.60, 50.0, 0.0, new MacroStateDTO(macroCreditSpreadEma: $spread, macroCreditSpreadTrend: $spread), new ScriptedDraws());
        $busy = $this->model->computeActualFinancials($stock, 1000.0, 0.60, 50.0, 0.0, new MacroStateDTO(
            macroCreditSpreadEma: $spread,
            macroCreditSpreadTrend: $spread,
            dealActivityIndexEma: 1.2 * MacroConstants::DEAL_ACTIVITY_BASELINE,
            corporateDefaultRateEma: MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN + 0.02,
        ), new ScriptedDraws());

        $this->assertEqualsWithDelta(1.0 + (0.2 * SecurityProtectionBusinessModel::SCREENING_DEAL_SCALAR), $busy->streamRevenue['screening'] / $calm->streamRevenue['screening'], 1e-9);
        $this->assertEqualsWithDelta(1.0 + (0.02 * SecurityProtectionBusinessModel::FRAUD_DISCOVERY_SCALAR), $busy->streamRevenue['investigations'] / $calm->streamRevenue['investigations'], 1e-9);
        $this->assertEqualsWithDelta($calm->streamRevenue['government_contracts'], $busy->streamRevenue['government_contracts'], 1e-9);
    }

    // --- Cash rounds and vaults (Nutcracker's mix: 75% rounds, 25% vaults) ---

    private const TREND_SPREAD = 0.0024;

    private static function cashMacro(float $domesticGap = 0.0, float $fundingScare = 0.0, float $gold = MacroConstants::GOLD_BASELINE): MacroStateDTO
    {
        return new MacroStateDTO(
            interbankLiquiditySpreadEma: self::TREND_SPREAD + $fundingScare,
            interbankLiquiditySpreadTrend: self::TREND_SPREAD,
            domesticDemandGapEma: $domesticGap,
            goldPriceIndexEma: $gold,
        );
    }

    /**
     * One Nutcracker quarter with the cash, vault and heist draws scripted in that order.
     *
     * @param list<float> $draws
     */
    private function cashQuarter(MacroStateDTO $macro, array $draws = [0.0, 0.0, 0.0]): ActualFinancialsDTO
    {
        return $this->model->computeActualFinancials((new Stock())->setTicker('NCKR')->setBeta('1.00'), 100_000_000.0, 0.60, 10_000_000.0, 0.0, $macro, new ScriptedDraws(generatePersistentZ: $draws));
    }

    public function testACalmCashQuarterRunsThePlanAtThePlannedCostRatio(): void
    {
        $result = $this->cashQuarter(self::cashMacro());

        $this->assertEqualsWithDelta(75_000_000.0, $result->streamRevenue['cash_services'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['vaults'], 1.0);
        $this->assertEqualsWithDelta(60_000_000.0, $result->actualVariableCosts, 1.0);
        $this->assertSame(['cash_services', 'vaults'], array_keys($result->streamRevenue));
        $this->assertNull($result->eventType);
    }

    public function testTheCashRoundsFollowSpendingAndFearAndTheVaultsDoNot(): void
    {
        $gap = -0.03;
        $scare = 0.01;
        $result = $this->cashQuarter(self::cashMacro(domesticGap: $gap, fundingScare: $scare));
        $shift = ($gap * SecurityProtectionBusinessModel::CASH_SPENDING_SENSITIVITY) + ($scare * SecurityProtectionBusinessModel::CASH_HOARDING_SENSITIVITY);

        $this->assertEqualsWithDelta(75_000_000.0 * (1.0 + $shift), $result->streamRevenue['cash_services'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['vaults'], 1.0);
        $this->assertEqualsWithDelta(1.0 + $shift, $result->kpis['cash_volume_index'], 1e-12);

        // The crews are staffed to the cash rounds alone.
        $stock = (new Stock())->setTicker('NCKR');
        $this->assertEqualsWithDelta(0.75 * $shift, $this->model->resolveSectorActivityShift($stock, self::cashMacro(domesticGap: $gap, fundingScare: $scare)), 1e-12);
    }

    public function testDearerGoldLiftsVaultFeesStraightToProfit(): void
    {
        $calm = $this->cashQuarter(self::cashMacro());
        $dear = $this->cashQuarter(self::cashMacro(gold: 1.4 * MacroConstants::GOLD_BASELINE));
        $fees = 1.0 + (SecurityProtectionBusinessModel::BULLION_FEE_SHARE * 0.40);

        $this->assertEqualsWithDelta(25_000_000.0 * $fees, $dear->streamRevenue['vaults'], 1.0);
        $this->assertEqualsWithDelta($fees, $dear->kpis['vault_value_index'], 1e-12);
        // The same staff and buildings keep the dearer metal.
        $this->assertEqualsWithDelta($calm->actualVariableCosts, $dear->actualVariableCosts, 1.0);
    }

    public function testAHeistChargesTheUninsuredLossOnTheCashAndVaultLines(): void
    {
        $heist = $this->cashQuarter(self::cashMacro(), [0.0, 0.0, SecurityProtectionBusinessModel::HEIST_Z_SCORE - 0.1]);
        $near = $this->cashQuarter(self::cashMacro(), [0.0, 0.0, SecurityProtectionBusinessModel::HEIST_Z_SCORE + 0.1]);

        $this->assertSame(ShockEvent::CASH_HEIST, $heist->eventType);
        $this->assertTrue($heist->isPublicEvent);
        $this->assertEqualsWithDelta(SecurityProtectionBusinessModel::HEIST_CHARGE * $heist->actualRevenue, $heist->specialCharge, 1.0);
        $this->assertNull($near->eventType);
        $this->assertEqualsWithDelta(0.0, $near->specialCharge, 1e-9);
    }

    public function testAFirmIsRobbedAsOftenAsItCarriesCash(): void
    {
        // A draw deep enough to rob the carrier robs a guarding firm with a twentieth of its cash work only far deeper.
        $carrierTail = Distributions::standardNormalCdf(SecurityProtectionBusinessModel::HEIST_Z_SCORE);
        $watch = (new Stock())->setTicker('WATCH')->setBeta('1.00');
        $quarter = fn(float $z): ?string => $this->model->computeActualFinancials($watch, 1000.0, 0.60, 50.0, 0.0, new MacroStateDTO(), new ScriptedDraws(generatePersistentZ: [0.0, 0.0, 0.0, 0.0, 0.0, $z, 0.0, 0.0]))->eventType;

        $this->assertNull($quarter(SecurityProtectionBusinessModel::HEIST_Z_SCORE - 0.1));
        $this->assertSame(ShockEvent::CASH_HEIST, $quarter(Distributions::standardNormalQuantile(0.9 * 0.05 * $carrierTail)));
    }

    public function testCardsTakeTheCashRoundsShareOfSecularGrowth(): void
    {
        $industry = $this->model->getSecularGrowthRate((new Stock())->setTicker('SEC'));

        $this->assertEqualsWithDelta($industry + (0.75 * SecurityProtectionBusinessModel::CASH_USE_DRIFT), $this->model->getSecularGrowthRate((new Stock())->setTicker('NCKR')), 1e-12);
    }

    public function testTheOtherLinesKeepTheIndustryPathAsCardsShrinkTheRounds(): void
    {
        $stock = (new Stock())->setTicker('NCKR');
        $this->assertEqualsWithDelta(0.25, $this->model->resolveLineShares($stock, 0.0)['vaults'], 1e-12);

        // The plan compounds the faded drift; the vault line, its share of the plan, compounds none of it.
        $years = 20.0;
        $halfLife = FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS;
        $excess = 0.75 * SecurityProtectionBusinessModel::CASH_USE_DRIFT;
        $logPlan = 0.0;
        $steps = 20_000;
        for ($i = 0; $i < $steps; $i++) {
            $logPlan += TimeSeries::fadeTowardTrend($excess, 0.0, ($i + 0.5) * $years / $steps, $halfLife) * $years / $steps;
        }
        $shares = $this->model->resolveLineShares($stock, $years);

        $this->assertEqualsWithDelta(0.25, exp($logPlan) * $shares['vaults'], 1e-6);
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
        $this->assertLessThan(0.72, $shares['cash_services']);

        // A guarding firm with a little cash work keeps its other lines in their proportions.
        $watch = $this->model->resolveLineShares((new Stock())->setTicker('WATCH'), $years);
        $this->assertEqualsWithDelta(1.0, $watch['government_contracts'] / $watch['corporate_retainers'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($watch), 1e-12);
    }

    public function testGovernmentSpendingExpansionBoostsGovernmentContractRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setBeta('1.0');

        $mathUtility = MathUtility::ownStream(20261024);

        $baselineMacro = new MacroStateDTO(governmentSpendingIndexEma: 100.0);
        $expansionMacro = new MacroStateDTO(governmentSpendingIndexEma: 150.0);

        $baselineResult = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            50.0,
            0.0,
            $baselineMacro,
            $mathUtility,
        );

        $expansionResult = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            50.0,
            0.0,
            $expansionMacro,
            $mathUtility,
        );

        $this->assertGreaterThan(
            $baselineResult->streamRevenue['government_contracts'],
            $expansionResult->streamRevenue['government_contracts'],
            'Fiscal appropriations and government spending expansion must increase government contract revenue.',
        );
    }

    public function testTheCycleReachesTheCostBaseThroughTheStreamVolumes(): void
    {
        $stock = (new Stock())->setTicker('WATCH_ACTIVITY')->setBeta('1.0');
        // A stressed recession: VIX 30%, spreads 3.5%, appropriations up 2%, inflation 2 points over target.
        $recession = new MacroStateDTO(
            outputGapEma: -0.03,
            exchangeRateIndexEma: 100.0,
            inflationEma: 0.04,
            governmentSpendingIndexEma: 102.0,
            marketVolatilityEma: 0.30,
            macroCreditSpreadEma: 0.035,
        );

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // Government posts on appropriations, retainers on the gap, expeditionary work on volatility against its anchor
        // and spreads against their trend, all both ways; the cost-plus inflation escalator is gone.
        $expected = (SecurityProtectionBusinessModel::GOVERNMENT_CONTRACT_WEIGHT * 0.02 * SecurityProtectionBusinessModel::APPROPRIATIONS_VOLUME_SCALAR)
            + (SecurityProtectionBusinessModel::RETAINER_WEIGHT * -0.03 * SecurityProtectionBusinessModel::CORPORATE_EXPANSION_SCALAR * $this->model->getOperatingCyclicality($stock))
            + (SecurityProtectionBusinessModel::EXPEDITIONARY_WEIGHT * (
                ((0.30 - MacroConstants::MACRO_VOL_BASE_ANCHOR) * SecurityProtectionBusinessModel::FEAR_PREMIUM_SCALAR)
                + ((0.035 - MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN) * SecurityProtectionBusinessModel::CREDIT_STRESS_SCALAR)
            ));
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\Aggregate\InventoryAndTiming;
use App\Service\Macro\Subsystem\Aggregate\OpenEconomy;
use App\Service\Macro\Subsystem\Aggregate\OutputGap;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Covers the openings a snapshot computes rather than reads: the values a payload that predates a
 * field, or was written by an older engine, has to be given before the pricing surfaces see it.
 */
class MacroStateHydrationTest extends TestCase
{
    public function testAbsentCurveIsRebuiltOffThePolicyRate(): void
    {
        $dto = MacroStateDTO::fromArray(['policy_rate' => 0.06]);
        $opening = new MacroStateDTO();

        // The opening curve's term spreads, carried on top of the policy rate the payload does report.
        foreach (['yield2y', 'yield5y', 'yield10y', 'yield30y'] as $tenor) {
            $this->assertEqualsWithDelta(0.06 + ($opening->$tenor - $opening->policyRate), $dto->$tenor, 1e-12, $tenor);
        }
        $this->assertGreaterThan($dto->yield2y, $dto->yield30y, 'The rebuilt curve must slope upward.');
    }

    public function testRecordedCurveIsNeverOverwrittenByTheRebuiltOne(): void
    {
        $dto = MacroStateDTO::fromArray([
            'policy_rate' => 0.06,
            'yield_2y' => 0.011,
            'yield_5y' => 0.022,
            'yield_10y' => 0.033,
            'yield_30y' => 0.044,
        ]);

        $this->assertSame(0.011, $dto->yield2y);
        $this->assertSame(0.022, $dto->yield5y);
        $this->assertSame(0.033, $dto->yield10y);
        $this->assertSame(0.044, $dto->yield30y);
    }

    /**
     * The smoothed 5y was recorded under the macro_report column spelling before the wire key
     * existed. A payload carrying both is a database row, and the column spelling is what it means.
     */
    public function testDatabaseColumnSpellingWinsForTheSmoothedFiveYear(): void
    {
        $this->assertSame(0.0631, MacroStateDTO::fromArray(['yield5y_ema' => 0.0631])->yield5yEma);

        $both = MacroStateDTO::fromArray(['yield5y_ema' => 0.0631, 'yield_5y_ema' => 0.0777]);
        $this->assertSame(0.0631, $both->yield5yEma);
    }

    /** A payload without the flag reads easing off a balance sheet held above the threshold. */
    public function testABalanceSheetAboveTheThresholdIsEasing(): void
    {
        $easing = MacroStateDTO::fromArray(['balance_sheet_intensity' => 0.004]);
        $this->assertSame(0.004, $easing->balanceSheetIntensity);
        $this->assertTrue($easing->qeActive);
    }

    public function testBalanceSheetInsideTheThresholdIsNeitherEasingNorTightening(): void
    {
        $flat = MacroStateDTO::fromArray([
            'balance_sheet_intensity' => MacroConstants::BALANCE_SHEET_ACTIVE_THRESHOLD / 2,
        ]);

        $this->assertFalse($flat->qeActive);
    }

    public function testPotentialOutputIsBackedOutOfNominalGdpAndTheGap(): void
    {
        $dto = MacroStateDTO::fromArray(['nominal_gdp_index' => 1.2, 'output_gap' => 0.02]);

        $this->assertEqualsWithDelta(1.2 / 1.02, $dto->potentialGdpIndex, 1e-12);
    }

    public function testShortEndCurveFactorIsTheSpreadOfPolicyOverTheLevel(): void
    {
        $dto = MacroStateDTO::fromArray(['policy_rate' => 0.05, 'ns_level' => 0.03]);

        $this->assertEqualsWithDelta(0.02, $dto->nsBeta1, 1e-12);
    }

    public function testHighYieldSpreadOpensAsAMultipleOfTheInvestmentGradeSpread(): void
    {
        $dto = MacroStateDTO::fromArray(['macro_credit_spread' => 0.02]);

        $this->assertEqualsWithDelta(0.02 * MacroConstants::HY_BASE_SPREAD_MULTIPLIER, $dto->highYieldCreditSpread, 1e-12);
        $this->assertEqualsWithDelta(
            $dto->highYieldCreditSpread,
            $dto->highYieldCreditSpreadEma,
            1e-12,
            'A smoothed spread with no history opens level with the spread it smooths.',
        );
    }

    /**
     * Seeding runs in constructor order, so a series seeded from one that is itself seeded has to
     * resolve in the same pass rather than picking up an opening value of zero.
     */
    public function testChainedSeedsResolveInOnePass(): void
    {
        $dto = MacroStateDTO::fromArray(['inflation' => 0.055]);

        $this->assertSame(0.055, $dto->inflationEma);
        $this->assertSame(0.055, $dto->supercoreInflation);
        $this->assertSame(0.055, $dto->supercoreInflationEma, 'Seeded from a field that is itself seeded.');
        $this->assertSame(0.055, $dto->coreGoodsInflation);
        $this->assertSame(0.055, $dto->coreGoodsInflationEma);
        $this->assertSame(0.055, $dto->tipsBreakeven);
        $this->assertSame(0.055, $dto->tipsBreakevenEma);
    }

    public function testRecordedSmoothedSeriesIsNotOverwrittenByItsSeed(): void
    {
        $dto = MacroStateDTO::fromArray(['inflation' => 0.055, 'inflation_ema' => 0.021]);

        $this->assertSame(0.021, $dto->inflationEma);
    }

    public function testNonScalarFieldsSurviveTheWire(): void
    {
        $dto = MacroStateDTO::fromArray([
            'sector_z' => ['TECH' => 1.5, 'BANK' => '-0.25'],
            'sector_demand_z' => ['REIT' => 0.75],
            'event_type' => 'CRISIS',
        ]);

        $this->assertSame(['TECH' => 1.5, 'BANK' => -0.25], $dto->sectorZ, 'Sector draws are cast to floats.');
        $this->assertSame(['REIT' => 0.75], $dto->sectorDemandZ);
        $this->assertSame('CRISIS', $dto->eventType);
    }

    public function testMalformedSectorDrawsHydrateEmptyRatherThanFatal(): void
    {
        $dto = MacroStateDTO::fromArray(['sector_z' => 'not-an-array']);

        $this->assertSame([], $dto->sectorZ);
    }

    /**
     * A saved state carrying allied spending but no delivery lag opens the lag where allied spending stands, so the
     * arms exports are already in the net export level and the first tick reprices nothing. Both readers agree.
     */
    public function testAlliedArmsDeliveriesOpenWhereAlliedSpendingStands(): void
    {
        $payload = ['allied_defense_spending_index_ema' => 130.0, 'foreign_output_gap_ema' => 0.01, 'exchange_rate_index_ema' => 100.0, 'exchange_rate_trend' => 100.0];
        $state = MacroState::fromArray($payload);
        $dto = MacroStateDTO::fromArray($payload);

        $this->assertEqualsWithDelta(log(1.3), $state->alliedDefenseDeliveryLag, 1e-15);
        $this->assertEqualsWithDelta(OpenEconomy::netExportGapAt(0.01, 0.0, log(1.3)), $state->netExportGap, 1e-15);
        $this->assertEqualsWithDelta($state->alliedDefenseDeliveryLag, $dto->alliedDefenseDeliveryLag, 1e-15);
        $this->assertEqualsWithDelta($state->netExportGap, $dto->netExportGap, 1e-15);

        $legacy = MacroState::fromArray(['foreign_output_gap_ema' => 0.01]);
        $this->assertSame(MacroConstants::ALLIED_DEFENSE_BASELINE, $legacy->alliedDefenseSpendingIndex, 'A state predating allied spending opens it at trend.');
        $this->assertSame(0.0, $legacy->alliedDefenseDeliveryLag);
    }

    /**
     * A live state saved before the open-economy levels existed opens them where the currency and the mainland stand,
     * so the first tick after the upgrade reprices neither import prices nor trade volumes.
     */
    public function testAPayloadPredatingTheOpenEconomyOpensItAtRest(): void
    {
        $payload = ['exchange_rate_index_ema' => 92.0, 'exchange_rate_trend' => 100.0, 'foreign_output_gap_ema' => 0.012];
        $state = MacroState::fromArray($payload);

        $this->assertEqualsWithDelta(OpenEconomy::importPriceLevelTarget(92.0), $state->importPriceLevel, 1e-15);
        $this->assertEqualsWithDelta(log(0.92), $state->realExchangeRateTradeLag, 1e-15);
        $this->assertEqualsWithDelta(OpenEconomy::netExportGapAt(0.012, log(0.92), 0.0), $state->netExportGap, 1e-15);
        $this->assertGreaterThan(0.0, $state->netExportGap, 'A cheap currency and a mainland boom both sell exports.');

        // Finance output opens where the credit balances stand, so the upgrade reprices no output.
        $financed = ['credit_to_gdp_gap' => 0.05, 'credit_to_gdp_trend' => 1.0, 'equity_wealth_ratio' => 0.9, 'equity_wealth_trend' => 1.0];
        $expected = OutputGap::financeOutputGapAt(log(1.05));
        $this->assertEqualsWithDelta($expected, MacroState::fromArray($financed)->financeOutputGap, 1e-15);
        $this->assertEqualsWithDelta($expected, MacroStateDTO::fromArray($financed)->financeOutputGap, 1e-15);
        $this->assertSame(MacroStateDTO::fromMacroState($state)->toArray(), MacroStateDTO::fromArray($payload)->toArray(), 'The snapshot reader opens them the same way.');
    }

    /**
     * A live state saved while fund managers' output was valued at the market carries a finance level holding that leg
     * and the retired trend keys. It still loads: the retired keys are ignored, finance output is rebuilt on credit
     * alone, and domestic demand keeps what the market leg held, so GDP does not move. Once saved under the new keys it
     * is carried.
     */
    public function testAPayloadValuingFundManagersAtTheMarketLoadsWithoutMovingGdp(): void
    {
        $payload = [
            'output_gap' => 0.02, 'output_gap_ema' => 0.018, 'finance_output_gap' => 0.012, 'domestic_demand_gap_ema' => 0.01,
            'inventory_investment_gap' => 0.0, 'finance_market_trend' => 1.4, 'finance_market_trend_slope' => 0.005,
            'credit_to_gdp_gap' => 0.05, 'credit_to_gdp_trend' => 1.0, 'equity_wealth_ratio' => 1.6,
        ];
        $credit = OutputGap::financeOutputGapAt(log(1.05));
        $kept = 0.01 + ((0.012 - $credit) / OutputGap::DOMESTIC_GAP_WEIGHT);

        $state = MacroState::fromArray($payload);
        $this->assertSame(0.02, $state->outputGap, 'GDP is what it was.');
        $this->assertEqualsWithDelta($credit, $state->financeOutputGap, 1e-15);
        $this->assertEqualsWithDelta($kept, $state->domesticDemandGapEma, 1e-15);
        $this->assertEqualsWithDelta(OutputGap::domesticDemandGap($state), (0.02 - $credit) / OutputGap::DOMESTIC_GAP_WEIGHT, 1e-15, 'Domestic demand holds the old market leg.');
        $this->assertArrayNotHasKey('finance_market_trend', $state->toArray());

        $snapshot = MacroStateDTO::fromArray($payload);
        $this->assertEqualsWithDelta($credit, $snapshot->financeOutputGap, 1e-15, 'The snapshot reader opens it the same way.');
        $this->assertEqualsWithDelta($kept, $snapshot->domesticDemandGapEma, 1e-15);

        $saved = $state->toArray();
        $saved['credit_to_gdp_gap'] = 0.08;
        $this->assertSame($state->financeOutputGap, MacroState::fromArray($saved)->financeOutputGap, 'A payload under the new keys is carried, not rebuilt.');
        $this->assertSame($state->domesticDemandGapEma, MacroState::fromArray($saved)->domesticDemandGapEma);
    }

    /**
     * A payload predating inventory investment as a level part opens it at the flow its stock is already making, taken
     * out of final sales and their smoothed reading alike: GDP does not move and no sales surprise opens.
     */
    public function testAPayloadPredatingInventoryInvestmentLoadsWithoutMovingGdp(): void
    {
        $payload = ['output_gap' => -0.02, 'output_gap_ema' => -0.015, 'domestic_demand_gap_ema' => -0.02, 'inventory_stock_gap' => 0.05];
        $sales = -0.02 / OutputGap::DOMESTIC_GAP_WEIGHT;
        $miss = -0.02 - $sales;
        $flow = OutputGap::DOMESTIC_GAP_WEIGHT * ((InventoryAndTiming::INVENTORY_SURPRISE_SENSITIVITY * $miss)
            - (InventoryAndTiming::INVENTORY_ADJUSTMENT_SPEED * InventoryAndTiming::INVENTORY_TO_ANNUAL_SALES * 0.05));

        $state = MacroState::fromArray($payload);
        $this->assertSame(-0.02, $state->outputGap, 'GDP is what it was.');
        $this->assertEqualsWithDelta($flow, $state->inventoryInvestmentGap, 1e-15);
        $this->assertLessThan(0.0, $state->inventoryInvestmentGap, 'An overhang being worked off is investment below normal.');
        $this->assertEqualsWithDelta($miss, $state->domesticDemandGapEma - OutputGap::domesticDemandGap($state), 1e-15, 'No sales surprise opens.');
        $this->assertEqualsWithDelta($state->inventoryInvestmentGap, MacroStateDTO::fromArray($payload)->inventoryInvestmentGap, 1e-15, 'The snapshot reader opens it the same way.');

        $saved = $state->toArray();
        $saved['inventory_stock_gap'] = 0.0;
        $this->assertSame($state->inventoryInvestmentGap, MacroState::fromArray($saved)->inventoryInvestmentGap, 'A carried level is not rebuilt.');
    }

    /**
     * A payload older still, with no smoothed domestic reading either: both open, and the miss expected sales stand at
     * is the smoothed gap's own, over domestic demand's weight.
     */
    public function testAPayloadPredatingBothTheSalesReadingAndInventoryInvestmentLoadsWithoutMovingGdp(): void
    {
        $state = MacroState::fromArray(['output_gap' => -0.02, 'output_gap_ema' => -0.015, 'inventory_stock_gap' => 0.05]);

        $this->assertSame(-0.02, $state->outputGap, 'GDP is what it was.');
        $this->assertLessThan(0.0, $state->inventoryInvestmentGap);
        $this->assertEqualsWithDelta(0.005 / OutputGap::DOMESTIC_GAP_WEIGHT, $state->domesticDemandGapEma - OutputGap::domesticDemandGap($state), 1e-15);
    }

    /**
     * A payload whose capital built counted the requirement alone had the buffer in force held all the same: the
     * upgrade keeps the shortfall the banks had yet to build, adds none for the buffer, and sees no rise on its first
     * tick.
     */
    public function testAPayloadPredatingTheBufferInTheCapitalBuiltKeepsItsShortfall(): void
    {
        $payload = ['bank_capital_requirement' => 0.104, 'bank_capital_built' => 0.099, 'countercyclical_buffer_rate' => 0.01];
        $state = MacroState::fromArray($payload);

        $this->assertEqualsWithDelta(0.109, $state->bankCapitalBuilt, 1e-15);
        $this->assertEqualsWithDelta(0.114, $state->bankCapitalRequiredLast, 1e-15);
        $this->assertSame(MacroStateDTO::fromMacroState($state)->toArray(), MacroStateDTO::fromArray($payload)->toArray(), 'The snapshot reader opens them the same way.');

        $current = MacroState::fromArray(['bank_capital_built' => 0.099, 'bank_capital_required_last' => 0.104, 'countercyclical_buffer_rate' => 0.01]);
        $this->assertSame(0.099, $current->bankCapitalBuilt, 'A payload that already counts the buffer is read as it stands.');
    }

    /**
     * A saved currency keeps its level across the split into fundamental and deviation: the deviation takes up the
     * difference.
     */
    public function testASavedCurrencyKeepsItsLevel(): void
    {
        $payload = ['exchange_rate_index' => 104.0, 'policy_rate' => 0.05, 'foreign_policy_rate' => 0.03];
        $state = MacroState::fromArray($payload);

        $this->assertSame(104.0, $state->exchangeRateIndex);
        $this->assertEqualsWithDelta(104.0, AssetMarketSubsystem::exchangeRateFundamental($state) * $state->exchangeRateDeviation, 1e-9);
        $snapshot = MacroStateDTO::fromArray($payload);
        $this->assertSame($state->exchangeRateDeviation, $snapshot->exchangeRateDeviation, 'The snapshot reader agrees.');
        $this->assertSame(104.0, $snapshot->exchangeRateIndex);
        $this->assertSame(1.0, MacroState::fromArray(['policy_rate' => 0.05])->exchangeRateDeviation, 'With no saved currency the opening deviation stands.');
    }

    /** A fresh engine, an empty snapshot and a DTO built with no arguments are one economy. */
    public function testEveryReaderOpensOnTheSameEconomy(): void
    {
        $opening = (new MacroStateDTO())->toArray();

        $this->assertSame($opening, MacroStateDTO::fromArray([])->toArray());
        $this->assertSame($opening, (new MacroState())->toArray());
        $this->assertSame($opening, MacroState::fromArray([])->toArray());
    }

    /** The opening curve is the curve the engine fits at the opening state, so the first tick does not reprice it. */
    public function testTheOpeningCurveIsTheEnginesOwnCurveAtTheOpeningState(): void
    {
        $state = new MacroState();
        $curve = (new MonetaryPolicySubsystem(new MathUtility()))->calculateYieldCurve($state, MacroConstants::TARGET_INFLATION, $state->naturalRate);

        // Openings are declared to the basis point.
        foreach (['yield_2y' => 'yield2y', 'yield_5y' => 'yield5y', 'yield_10y' => 'yield10y', 'yield_30y' => 'yield30y',
            'level' => 'nsLevel', 'beta1' => 'nsBeta1', 'curvature' => 'nsCurvature', 'curvature2' => 'nsCurvature2',
            'base_term_premium' => 'nsBaseTermPremium', 'term_premium_10y' => 'termPremium10y', 'risk_neutral_10y' => 'riskNeutral10y'] as $key => $field) {
            $this->assertEqualsWithDelta($curve[$key], $state->$field, 0.00006, $field);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function legacyPayloadProvider(): array
    {
        return [
            'rates only' => [['policy_rate' => 0.055, 'inflation' => 0.031, 'macro_credit_spread' => 0.018, 'ns_level' => 0.041, 'nominal_gdp_index' => 1.35, 'balance_sheet_intensity' => 0.002]],
            'saved currency' => [['exchange_rate_index' => 104.0, 'policy_rate' => 0.05, 'foreign_policy_rate' => 0.03, 'tips_breakeven' => 0.025]],
            'database row' => [['yield5y_ema' => 0.041, 'yield_5y_ema' => 0.039, 'policy_rate' => 0.045]],
            'laws before their lags' => [['bank_levy_rate' => 0.004, 'corporate_tax_policy_shift' => 0.02, 'carbon_price' => 80.0, 'stamp_duty_rate' => 0.003]],
        ];
    }

    /**
     * The engine's state and a snapshot of the same payload are one economy in every field, an old payload included:
     * two readers that each upgraded some fields once disagreed on up to 17 of them.
     *
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('legacyPayloadProvider')]
    public function testEngineStateAndSnapshotAgreeOnTheSamePayload(array $payload): void
    {
        $this->assertSame(
            MacroStateDTO::fromMacroState(MacroState::fromArray($payload))->toArray(),
            MacroStateDTO::fromArray($payload)->toArray(),
        );
    }

    /**
     * Two identities tie the curve's stored factors to the tenors they generate, and a reader that
     * breaks one reports a curve that cannot exist.
     *
     * The snapshot broke all three on an empty payload: the rebuild above gave the tenors a policy-rate
     * anchor but never seeded the Nelson-Siegel level, so the long end decayed to zero -- the flat zero
     * curve the rebuild exists to prevent -- and beta1, which is derived from the level, came out at
     * +0.02 when the short end sits BELOW the long end and it has to be negative. The engine's own
     * openings broke the slope the other way, reporting a 150bp inversion over tenors sloping upward.
     *
     * @return array<string, array{callable(): object}>
     */
    public static function curveReaderProvider(): array
    {
        return [
            'snapshot opening' => [static fn(): object => MacroStateDTO::fromArray([])],
            'engine opening'   => [static fn(): object => new MacroState()],
        ];
    }

    /** Both readers open where the index sits with its fundamentals neutral, and with no mood in it. */
    #[\PHPUnit\Framework\Attributes\DataProvider('curveReaderProvider')]
    public function testSentimentOpensAtItsTrendLevel(callable $open): void
    {
        $reader = $open();

        $this->assertSame(MacroConstants::SENTIMENT_TREND_LEVEL, $reader->consumerSentimentIndex);
        $this->assertSame(MacroConstants::SENTIMENT_TREND_LEVEL, $reader->consumerSentimentIndexEma);
        $this->assertSame(0.0, $reader->consumerSentimentMood);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('curveReaderProvider')]
    public function testTheOpeningCurveSatisfiesItsOwnIdentities(callable $open): void
    {
        $curve = $open();

        $this->assertEqualsWithDelta(
            $curve->policyRate - $curve->nsLevel,
            $curve->nsBeta1,
            1e-12,
            'beta1 is the policy rate under the long-run level, not a free parameter.',
        );
        $this->assertEqualsWithDelta(
            $curve->yield10y - $curve->policyRate,
            $curve->nsSlope,
            1e-12,
            'The reported slope is the 10y-over-policy term spread.',
        );
        $this->assertGreaterThan(0.0, $curve->nsLevel, 'A long end at zero is a degenerate curve.');
        $this->assertLessThan(0.0, $curve->nsBeta1, 'An upward curve carries a negative beta1.');
        $this->assertGreaterThan($curve->policyRate, $curve->yield30y, 'The opening curve slopes upward.');
    }
}

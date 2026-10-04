<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\Data\MacroFieldRegistry;
use App\DTO\MacroStateDTO;
use App\Entity\MacroReport;
use App\Service\Macro\MacroState;
use PHPUnit\Framework\TestCase;

/**
 * Guards the macro state vector against the drift that used to be possible between the six places
 * its field list appeared. Each test here fails on one specific way a newly added observable can go
 * missing without any other test noticing.
 */
class MacroFieldRegistryTest extends TestCase
{
    /** Columns on macro_report that the macro vector does not own and must not try to fill. */
    private const RECORDER_OWNED_COLUMNS = ['id', 'recordedAt', 'gapChannels', 'quarterDiagnostics', 'configFingerprint', 'ticksPerYear'];

    /**
     * Macro fields deliberately NOT recorded, each with the reason it is not worth a column.
     *
     * The registry's other tests all run column -> field: they catch a column nothing fills. Nothing ran
     * field -> column, and 55 fields had quietly accumulated with no column at all — including the QE and
     * ELB regime flags and the equity wealth stock, which is to say the recorded history could not answer
     * the questions it was being read for. The absences here are the ones that survived that review, so a
     * new observable now has to be argued out of the table rather than fall out of it.
     *
     * @var array<string, string>
     */
    private const TRANSIENT_FIELDS = [
        'sectorZ' => 'Per-sector array; a scalar column cannot hold it.',
        'sectorDemandZ' => 'Per-sector array; a scalar column cannot hold it.',

        'marketZ' => 'Redrawn every tick, so a quarterly sample is one arbitrary tick of noise.',
        'marketZLatent' => 'Per-tick latent behind marketZ; same objection.',
        'marketJumpMultiplier' => 'Per-tick jump scale; marketVolatility carries the quarter-scale reading.',

        'metalsChi' => 'Latent OU state behind industrialMetalsIndex, which is recorded.',
        'metalsXi' => 'Latent OU state behind industrialMetalsIndex, which is recorded.',
        'agriChi' => 'Latent OU state behind agriculturalCommodityIndex, which is recorded.',
        'agriXi' => 'Latent OU state behind agriculturalCommodityIndex, which is recorded.',

        'energySupplyEma' => 'Internal to the energy process; energyPriceIndex is the observable and is recorded.',
        'energyInventoryIndex' => 'energyInventoryIndexEma is the quarter-scale reading and is recorded.',
        'freightSupplyEma' => 'Internal to the freight process; freightRateIndex is recorded.',

        'eventType' => 'Label of the event in flight, not a series.',
        'electionPulse' => 'Input the government hands the economy each tick; policy_uncertainty_index records its effect.',
        'tariffTradeLag' => 'Net-export response building toward the tariff, which import_tariff_rate records.',
        'immigrationPopulationShift' => 'Integral of labor_force_growth_rate over the structural rate, which is recorded.',
        'electricityCarbonPriceLevel' => 'Lagged pass-through of carbon_price into the electricity bill, which is recorded.',
        'lastQeLaunchAt' => 'Edge marker for the launch headline; qe_active and qe_intensity record the programme.',

        'sovereignFundDomesticEquity' => 'Currency amount that compounds forever; sovereign_fund_to_gdp and the domestic weight record it.',
        'sovereignFundForeignEquity' => 'Foreign-currency amount that compounds forever; sovereign_fund_equity_share and sovereign_fund_to_gdp record it.',
        'sovereignFundForeignBonds' => 'Foreign-currency amount that compounds forever; sovereign_fund_to_gdp records the fund.',
        'sovereignFundDollarsPerGdp' => 'Scale fixed at inception, not a series.',
        'sovereignFundAnnualDraw' => 'Currency amount; sovereign_fund_draw_to_gdp records it.',
        'sovereignFundRebalanceBacklog' => 'Currency amount; sovereign_fund_rebalance_share records it against the float.',
        'sovereignFundRebalanceRate' => 'Currency pace of the programme in flight; the share and months left record it.',
        'sovereignFundTrade' => 'Traded every tick, so a quarterly sample is one arbitrary tick.',
        'boardFloatCap' => 'Currency amount fed back by the ticker; the ownership share records the fund against it.',
        'boardPriceReturn' => 'One tick of the board, so a quarterly sample is one arbitrary tick of noise.',
        'boardDividendCash' => 'One tick of dividend cash; same objection.',
        'foreignEquityValuation' => 'A function of foreign_equity_risk_premium, which is recorded.',
        'foreignEquityValuationChange' => 'One tick of re-rating; foreign_equity_index records its effect.',
        'sovereignFundStampDutyYearToDate' => 'Currency accumulator for the budget year; sovereign_fund_stamp_duty_to_gdp records the year.',
        'boardStampDuty' => 'One tick of stamp duty; sovereign_fund_stamp_duty_to_gdp records the year.',
        'authorityCommitteeSeated' => 'A flag, 1 while politics hands the macro a rate committee; authority_majority records what the committee is.',
        'boardBankLevy' => 'The banks\' levy bill at the rate in force; bank_levy_rate records the rate and corporate_report.bank_levy what each bank paid.',
        'strategicStakeCash' => 'One tick of cash from the District\'s strategic stakes, credited to the fund at once.',
        'sovereignFundStabilisationYearStart' => 'The budget year\'s opening level of sovereign_fund_stabilisation_to_gdp, which is recorded.',
        'sovereignFundStabilisationLastChange' => 'Last budget year\'s change in sovereign_fund_stabilisation_to_gdp, which is recorded.',
        'sovereignFundGapTrend' => 'The budget\'s long-run average of output_gap, which is recorded.',
        'sovereignFundBudgetInflow' => 'One tick of the budget surplus below the debt floor, credited to the fund on the next tick; sovereign_fund_to_gdp records the fund.',
        'sovereignFundValueAtClose' => 'Currency amount that compounds forever; sovereign_fund_return_index records its performance.',
        'sovereignFundBondsToGdp' => 'The difference between sovereign_debt_to_gdp and sovereign_net_debt_to_gdp, both recorded.',
        'sovereignNetDebtToGdpEma' => 'Smoothing of sovereign_net_debt_to_gdp, which is recorded.',
        'boardNetIssuance' => 'One tick of the companies\' own issuance and buybacks; same objection.',
        'residentialPriceMomentum' => 'A year\'s average of the log change in residential_property_index, which is recorded.',
        'householdNewBorrowing' => 'A year\'s average of the change in household_debt_to_income, which is recorded.',
        'foreignOutputGapLag' => 'The mainland gap a quarter back, which the previous quarter\'s row records as foreign_output_gap.',
        'foreignCoreInflationLag1' => 'Mainland core inflation a quarter back, which the previous row records as foreign_core_inflation.',
        'foreignCoreInflationLag2' => 'Mainland core inflation two quarters back; same reason.',
        'foreignCoreInflationLag3' => 'Mainland core inflation three quarters back; same reason.',
        'realExchangeRateTradeLag' => 'Latent lag behind net_export_gap, which is recorded with exchange_rate_index_ema and exchange_rate_trend.',
        'domesticDemandGapEma' => 'Smoothed domestic part of the gap the inventory surprise reads; it opens from output_gap_ema and the recorded level parts.',
        'alliedDefenseDeliveryLag' => 'Latent lag behind net_export_gap, which is recorded with allied_defense_spending_index_ema.',
        'outputGapLag3m' => 'Distributed lag of output_gap_ema, which is recorded.',
        'outputGapLag6m' => 'Distributed lag of output_gap_ema, which is recorded.',
        'outputGapLag9m' => 'Distributed lag of output_gap_ema, which is recorded.',
        'outputGapLag12m' => 'Distributed lag of output_gap_ema, which is recorded.',
        'outputGapLag15m' => 'Distributed lag of output_gap_ema, which is recorded.',
        'outputGapLag18m' => 'Distributed lag of output_gap_ema, which is recorded.',
        'importPriceLevel' => 'Latent level behind the import-price term of the quarter\'s inflation diagnostics.',
        'exchangeRateDeviation' => 'exchange_rate_index over its fundamental; the index is recorded.',
        'financeMarketTrend' => 'Latent HP trend behind finance_output_gap, which is recorded with equity_wealth_ratio.',
        'financeMarketTrendSlope' => 'Slope of that latent trend.',
    ];

    /**
     * The engine's mutable state and the immutable snapshot must describe the same vector, because
     * MacroStateDTO::fromMacroState() carries every field across by name.
     */
    public function testMacroStateDeclaresEveryFieldTheDtoDoes(): void
    {
        $fields = array_keys(MacroFieldRegistry::wireKeys());

        $stateProperties = [];
        foreach ((new \ReflectionClass(MacroState::class))->getProperties() as $property) {
            $stateProperties[] = $property->getName();
        }

        $missing = array_diff($fields, $stateProperties);
        $this->assertSame(
            [],
            array_values($missing),
            'MacroStateDTO declares fields MacroState has no property for: ' . implode(', ', $missing)
        );

        $orphaned = array_diff($stateProperties, $fields);
        $this->assertSame(
            [],
            array_values($orphaned),
            'MacroState carries fields the DTO never snapshots: ' . implode(', ', $orphaned)
        );
    }

    /**
     * Every field must survive the Redis wire. A key MacroStateDTO::fromArray() forgets hydrates its
     * default instead of the recorded value, which is silent — the reader still gets a plausible
     * number. Writing a distinct value into every field and reading it back is what catches that.
     */
    public function testEveryFieldSurvivesTheRedisRoundTrip(): void
    {
        $state = new MacroState();
        $sentinels = [];

        $offset = 0;
        foreach ((new \ReflectionClass($state))->getProperties() as $property) {
            ++$offset;
            if ((string) $property->getType() !== 'float') {
                continue;
            }
            // Distinct, and far from every default, so a dropped field cannot coincidentally match.
            $value = 1000.0 + $offset;
            $property->setValue($state, $value);
            $sentinels[$property->getName()] = $value;
        }

        $this->assertNotEmpty($sentinels);

        $restored = MacroStateDTO::fromArray($state->toArray());

        $lost = [];
        foreach ($sentinels as $field => $value) {
            if (abs($restored->$field - $value) > 1e-9) {
                $lost[] = $field;
            }
        }

        $this->assertSame(
            [],
            $lost,
            'Fields did not survive MacroState::toArray() -> MacroStateDTO::fromArray(): ' . implode(', ', $lost)
        );
    }

    /**
     * fromMacroState() carries the vector across by name; a field it skips reaches every consumer as
     * a default.
     */
    public function testFromMacroStateCarriesEveryField(): void
    {
        $state = new MacroState();
        $sentinels = [];

        $offset = 0;
        foreach ((new \ReflectionClass($state))->getProperties() as $property) {
            ++$offset;
            if ((string) $property->getType() !== 'float') {
                continue;
            }
            $value = 500.0 + $offset;
            $property->setValue($state, $value);
            $sentinels[$property->getName()] = $value;
        }

        $dto = MacroStateDTO::fromMacroState($state);

        foreach ($sentinels as $field => $value) {
            $this->assertSame($value, $dto->$field, "fromMacroState() dropped {$field}");
        }
    }

    /**
     * A macro_report column with no matching DTO field can never be filled, so the recorder would
     * write NULL into it on every snapshot for the life of the table.
     */
    public function testEveryPersistedColumnHasAFieldToFillIt(): void
    {
        $fields = MacroFieldRegistry::wireKeys();

        $orphans = [];
        foreach (array_keys(MacroFieldRegistry::entityColumns()) as $property) {
            if (in_array($property, self::RECORDER_OWNED_COLUMNS, true)) {
                continue;
            }
            if (!isset($fields[$property])) {
                $orphans[] = $property;
            }
        }

        $this->assertSame(
            [],
            $orphans,
            'MacroReport columns with no MacroStateDTO field to fill them: ' . implode(', ', $orphans)
        );
    }

    /**
     * The recorder aligns columns to values by position, so the two lists must be the same length and
     * come from the same mapping.
     */
    public function testPersistedColumnsAreCompleteAndUnique(): void
    {
        $columns = MacroFieldRegistry::persistedColumns();

        $this->assertCount(
            count($columns),
            array_unique(array_values($columns)),
            'Two macro fields map to the same macro_report column.'
        );

        $entityColumns = MacroFieldRegistry::entityColumns();
        $this->assertCount(
            count($entityColumns) - count(self::RECORDER_OWNED_COLUMNS),
            $columns,
            'The persisted field list and the mapped entity columns disagree on how many columns the macro vector fills.'
        );
    }

    /**
     * A field with no column is recorded nowhere, and nothing else fails when that happens: the engine runs,
     * the wire carries it, the dashboards read it live, and only a later attempt to explain a past episode
     * discovers the series was never kept. Either the field is on the table or the exclusion is declared.
     */
    public function testEveryFieldIsPersistedOrDeclaredTransient(): void
    {
        $persisted = MacroFieldRegistry::persistedColumns();

        $unrecorded = [];
        foreach (array_keys(MacroFieldRegistry::wireKeys()) as $field) {
            if (!isset($persisted[$field]) && !isset(self::TRANSIENT_FIELDS[$field])) {
                $unrecorded[] = $field;
            }
        }

        $this->assertSame(
            [],
            $unrecorded,
            'Macro fields reach no macro_report column and are not declared transient. Add a column to '
            . 'App\Entity\MacroReport, or name the field in TRANSIENT_FIELDS with the reason: '
            . implode(', ', $unrecorded)
        );
    }

    /**
     * An exclusion that names a field which since gained a column is a stale claim, and the next reader of
     * this list would believe it.
     */
    public function testNoDeclaredExclusionNamesARecordedField(): void
    {
        $persisted = MacroFieldRegistry::persistedColumns();
        $fields = MacroFieldRegistry::wireKeys();

        foreach (self::TRANSIENT_FIELDS as $field => $reason) {
            $this->assertArrayHasKey($field, $fields, "TRANSIENT_FIELDS names '{$field}', which is not a macro field.");
            $this->assertArrayNotHasKey(
                $field,
                $persisted,
                "TRANSIENT_FIELDS says '{$field}' is not recorded, but macro_report has a column for it."
            );
            $this->assertNotSame('', trim($reason), "TRANSIENT_FIELDS gives no reason for '{$field}'.");
        }
    }

    /**
     * Wire keys reach Redis, the district readouts and the driver catalog by name, so a collision or
     * a stray capital letter would quietly point two fields at one key.
     */
    public function testWireKeysAreUniqueAndSnakeCase(): void
    {
        $keys = MacroFieldRegistry::wireKeys();

        $this->assertCount(count($keys), array_unique(array_values($keys)), 'Two macro fields share a wire key.');

        foreach ($keys as $field => $key) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/',
                $key,
                "Wire key for {$field} is not snake_case: {$key}"
            );
        }
    }

    /**
     * Fields the catalog names as printable driver readings must actually exist on the wire.
     */
    public function testDriverCatalogOnlyNamesRealFields(): void
    {
        $keys = array_flip(MacroFieldRegistry::wireKeys());

        foreach (array_keys(\App\Data\MacroFieldCatalog::FIELDS) as $field) {
            $this->assertArrayHasKey($field, $keys, "MacroFieldCatalog names '{$field}', which no macro field publishes.");
        }
    }
}

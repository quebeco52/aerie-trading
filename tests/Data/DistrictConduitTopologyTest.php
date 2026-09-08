<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\DistrictMap;
use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the district conduit topology rule from DistrictMap's class docblock: a conduit from an
 * institution to a business model may exist only where that model's own operating physics —
 * calculateSectorPhysics()/getMacroPhysics() in App\Service\Model\Sector\* — genuinely reads one
 * of the institution's fields. Valuation-only reads shared by nearly every model
 * (equityRiskPremium, corporateTaxRate, policyRate feeding WACC alone) are excluded by design.
 *
 * The field sets below are the curated ground truth read directly from each model's source during
 * review (see the plan this feature shipped under). They are kept here, in the test, rather than
 * in production code, specifically so a change to CONDUITS that drifts from what a model actually
 * reads fails a test instead of silently drawing a line to nothing.
 */
class DistrictConduitTopologyTest extends TestCase
{
    /**
     * Business model identifier => the MacroStateDTO fields (snake_case, matching
     * DistrictMap::INSTITUTIONS) its own operating physics genuinely reads.
     *
     * @var array<string, list<string>>
     */
    private const OPERATING_MACRO_FIELDS = [
        'commercial_bank' => ['policy_rate_ema', 'yield_2y_ema', 'yield_5y_ema', 'yield_10y_ema', 'interbank_liquidity_spread_ema', 'output_gap_ema', 'inflation_ema', 'sloos_tightening_index_ema', 'housing_starts_index_ema', 'money_supply_growth_ema', 'consumer_sentiment_index_ema', 'retail_default_rate_ema', 'corporate_default_rate_ema', 'commercial_property_index_ema', 'residential_property_index_ema', 'macro_credit_spread_ema', 'recession_probability_ema'],
        'credit_services' => ['inflation_ema', 'sloos_tightening_index_ema', 'consumer_sentiment_index_ema', 'retail_default_rate_ema', 'unemployment_rate_ema', 'macro_credit_spread_ema', 'recession_probability_ema', 'yield_2y_ema', 'yield_10y_ema', 'yield_5y_ema', 'interbank_liquidity_spread_ema', 'policy_rate_ema'],
        'shadow_bank' => ['yield_30y_ema', 'policy_rate_ema', 'yield_5y_ema', 'residential_property_index_ema', 'housing_starts_index_ema', 'sloos_tightening_index_ema', 'money_supply_growth_ema', 'output_gap_ema', 'retail_default_rate_ema', 'corporate_default_rate_ema', 'commercial_property_index_ema', 'macro_credit_spread_ema', 'recession_probability_ema', 'interbank_liquidity_spread_ema'],
        'investment_bank' => ['policy_rate_ema', 'output_gap_ema', 'macro_credit_spread_ema', 'yield_5y_ema', 'deal_activity_index_ema', 'market_volatility_ema', 'money_supply_growth_ema', 'ns_slope', 'ns_slope_ema', 'high_yield_credit_spread_ema', 'corporate_default_rate_ema'],
        'brokerage' => ['output_gap_ema', 'money_supply_growth_ema', 'market_volatility_ema', 'deal_activity_index_ema', 'policy_rate_ema', 'yield_5y_ema', 'interbank_liquidity_spread_ema'],
        'clearing_house' => ['policy_rate_ema', 'yield_5y_ema', 'market_volatility_ema', 'yield_10y_ema', 'yield_2y_ema', 'corporate_default_rate_ema', 'output_gap_ema', 'inflation_ema'],
        'asset_manager' => ['policy_rate_ema', 'yield_5y_ema', 'yield_10y_ema', 'output_gap_ema', 'money_supply_growth_ema', 'market_volatility_ema'],
        'private_equity' => ['policy_rate_ema', 'yield_5y_ema', 'yield_10y_ema', 'macro_credit_spread_ema', 'sloos_tightening_index_ema', 'output_gap_ema', 'deal_activity_index_ema', 'market_volatility_ema'],
        'hedge_fund' => ['policy_rate_ema', 'yield_5y_ema', 'yield_10y_ema', 'output_gap_ema', 'market_volatility_ema', 'macro_credit_spread_ema', 'money_supply_growth_ema'],
        // Deliberately narrow — see DistrictMap::CONDUITS docblock on 'distressed_debt'.
        'distressed_debt' => ['macro_credit_spread', 'macro_credit_spread_ema', 'output_gap_ema', 'high_yield_credit_spread_ema', 'corporate_default_rate_ema'],
        'insurance' => ['policy_rate_ema', 'output_gap_ema', 'commercial_property_index_ema', 'residential_property_index_ema', 'yield_10y_ema', 'market_volatility_ema', 'inflation_ema'],
        // Inherits Insurance::getMacroPhysics()/getTargetMetrics() in full (own calculateSectorPhysics is purely Z-driven).
        'reinsurance' => ['policy_rate_ema', 'output_gap_ema', 'commercial_property_index_ema', 'residential_property_index_ema', 'yield_10y_ema', 'market_volatility_ema', 'inflation_ema'],
        'retail_insurance' => ['policy_rate_ema', 'output_gap_ema', 'commercial_property_index_ema', 'residential_property_index_ema', 'yield_10y_ema', 'market_volatility_ema', 'inflation_ema'],
        'financial_data' => ['macro_credit_spread_ema', 'output_gap_ema', 'market_volatility_ema', 'deal_activity_index_ema'],
    ];

    public static function conduitProvider(): array
    {
        $cases = [];
        foreach (DistrictMap::CONDUITS as $businessModel => $institutionIds) {
            foreach ($institutionIds as $institutionId) {
                $cases["{$businessModel}:{$institutionId}"] = [$businessModel, $institutionId];
            }
        }

        return $cases;
    }

    #[DataProvider('conduitProvider')]
    public function testConduitBusinessModelGenuinelyReadsOneOfTheInstitutionsFields(string $businessModel, string $institutionId): void
    {
        $this->assertArrayHasKey(
            $institutionId,
            DistrictMap::INSTITUTIONS,
            "CONDUITS references undeclared institution \"{$institutionId}\"."
        );
        $this->assertArrayHasKey(
            $businessModel,
            self::OPERATING_MACRO_FIELDS,
            "No curated operating-field spec exists for business model \"{$businessModel}\" — add one before wiring its conduits."
        );

        $institutionFields = DistrictMap::INSTITUTIONS[$institutionId]['fields'];
        $modelFields = self::OPERATING_MACRO_FIELDS[$businessModel];

        $overlap = array_intersect($institutionFields, $modelFields);

        $this->assertNotEmpty(
            $overlap,
            "{$businessModel} is wired to {$institutionId} but reads none of its fields (" . implode(', ', $institutionFields) . ') in its operating physics.'
        );
    }

    public function testEveryInstitutionFieldExistsOnMacroStateDTO(): void
    {
        $dtoFields = array_keys(get_object_vars(new MacroStateDTO()));

        foreach (DistrictMap::INSTITUTIONS as $institutionId => $institution) {
            foreach ($institution['fields'] as $field) {
                $camelCase = lcfirst(str_replace('_', '', ucwords($field, '_')));
                $this->assertContains(
                    $camelCase,
                    $dtoFields,
                    "Institution \"{$institutionId}\" declares field \"{$field}\" ({$camelCase}), which does not exist on MacroStateDTO."
                );
            }
        }
    }

    public function testEveryInstitutionReadoutIsADeclaredFieldWithAValidUnit(): void
    {
        $dtoFields = array_keys(get_object_vars(new MacroStateDTO()));
        $validUnits = [DistrictMap::UNIT_PERCENT, DistrictMap::UNIT_INDEX];

        foreach (DistrictMap::INSTITUTIONS as $institutionId => $institution) {
            $this->assertNotEmpty(
                $institution['readouts'] ?? [],
                "Institution \"{$institutionId}\" prints no readouts — it would show only a name and a stress pulse."
            );

            foreach ($institution['readouts'] as $readout) {
                $this->assertContains(
                    $readout['field'],
                    $institution['fields'],
                    "Institution \"{$institutionId}\" prints readout field \"{$readout['field']}\", which it does not declare in its own \"fields\"."
                );

                $camelCase = lcfirst(str_replace('_', '', ucwords($readout['field'], '_')));
                $this->assertContains(
                    $camelCase,
                    $dtoFields,
                    "Institution \"{$institutionId}\" readout field \"{$readout['field']}\" ({$camelCase}) does not exist on MacroStateDTO."
                );

                $this->assertNotEmpty($readout['label'], "Institution \"{$institutionId}\" has a readout with no label.");
                $this->assertContains(
                    $readout['unit'],
                    $validUnits,
                    "Institution \"{$institutionId}\" readout for \"{$readout['field']}\" has unit \"{$readout['unit']}\", not one of " . implode(', ', $validUnits)
                );
            }
        }
    }

    public function testEveryConduitTenantOnGlasswaterRowIsAFinancialOrKnownModel(): void
    {
        $glasswaterTickers = DistrictMap::tickersForWard('glasswater-row');
        $this->assertNotEmpty($glasswaterTickers);

        foreach (array_keys(DistrictMap::CONDUITS) as $businessModel) {
            $this->assertTrue(
                Sectors::isFinancial($businessModel) || $businessModel === 'financial_data',
                "\"{$businessModel}\" has a conduit topology but is neither Sectors::isFinancial() nor the known financial_data exception."
            );
        }
    }

    public function testNoInstitutionIsPureDecoration(): void
    {
        $wiredInstitutions = [];
        foreach (DistrictMap::CONDUITS as $institutionIds) {
            foreach ($institutionIds as $institutionId) {
                $wiredInstitutions[$institutionId] = true;
            }
        }

        foreach (array_keys(DistrictMap::INSTITUTIONS) as $institutionId) {
            $this->assertArrayHasKey(
                $institutionId,
                $wiredInstitutions,
                "Institution \"{$institutionId}\" has no tenant wired to it anywhere in CONDUITS."
            );
        }
    }
}

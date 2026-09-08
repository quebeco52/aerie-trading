<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\DistrictMap;
use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Service\District\DistrictConduitResolver;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\BusinessModelRegistryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the district conduit topology rule from DistrictMap's class docblock: a conduit from an
 * institution to a business model may exist only where that model's own declared
 * getOperatingMacroFields() — App\Service\Model\Strategy\OperatingStrategyInterface — genuinely
 * intersects one of the institution's fields, once DistrictMap::UBIQUITOUS_MACRO_FIELDS is
 * excluded from the comparison.
 *
 * Unlike its predecessor, this test carries no curated field list of its own — every model's
 * declaration lives on the model itself (see each Sector/*.php class), and this file audits that
 * declaration against MacroStateDTO and against the dilution risk that motivated
 * UBIQUITOUS_MACRO_FIELDS in the first place, so a field drifting from rare to near-universal
 * fails a test instead of silently rewiring every tenant to the same institution.
 */
class DistrictConduitTopologyTest extends TestCase
{
    private DistrictConduitResolver $resolver;

    protected function setUp(): void
    {
        $registry = new class implements BusinessModelRegistryInterface {
            public function get(string $identifier): BusinessModelInterface
            {
                return Sectors::getBusinessModelStrategy($identifier);
            }

            public function has(string $identifier): bool
            {
                return true;
            }

            public function all(): array
            {
                return [];
            }
        };

        $this->resolver = new DistrictConduitResolver($registry);
    }

    /** @return array<string, list<string>> */
    public static function businessModelProvider(): array
    {
        $ids = [];
        foreach (Sectors::INDUSTRY_METRICS as $config) {
            $ids[$config['business_model']] = [$config['business_model']];
        }

        return $ids;
    }

    #[DataProvider('businessModelProvider')]
    public function testEveryDeclaredOperatingMacroFieldExistsOnMacroStateDTO(string $businessModel): void
    {
        $dtoFields = array_keys((new MacroStateDTO())->toArray());
        $model = Sectors::getBusinessModelStrategy($businessModel);
        $ownFields = $model->getOperatingMacroFields();

        // A model may genuinely declare no operating fields (see BiotechBusinessModel) — that is
        // a real signal, not a gap, so assert the declaration is a valid list either way.
        $this->assertIsArray($ownFields);

        foreach ($ownFields as $field) {
            $this->assertContains(
                $field,
                $dtoFields,
                sprintf('"%s" declares operating field "%s", which does not exist on MacroStateDTO::toArray()', $businessModel, $field)
            );
        }
    }

    #[DataProvider('businessModelProvider')]
    public function testEveryDerivedConduitGenuinelyIntersectsTheInstitutionsFields(string $businessModel): void
    {
        $model = Sectors::getBusinessModelStrategy($businessModel);
        $ownFields = array_diff($model->getOperatingMacroFields(), DistrictMap::UBIQUITOUS_MACRO_FIELDS);
        $conduits = $this->resolver->conduitsForBusinessModel($businessModel);

        // A model reading nothing non-ubiquitous (see BiotechBusinessModel) must derive zero
        // conduits — that is the assertion in the empty case, not a no-op.
        if ($ownFields === []) {
            $this->assertSame([], $conduits, sprintf('"%s" declares no operating fields but is still wired to a conduit', $businessModel));

            return;
        }

        foreach ($conduits as $institutionId) {
            $institutionFields = DistrictMap::INSTITUTIONS[$institutionId]['fields'];

            $this->assertNotEmpty(
                array_intersect($ownFields, $institutionFields),
                sprintf('"%s" is wired to "%s" but shares none of its non-ubiquitous fields', $businessModel, $institutionId)
            );
        }
    }

    public function testEveryInstitutionFieldExistsOnMacroStateDTO(): void
    {
        $dtoFields = array_keys((new MacroStateDTO())->toArray());

        foreach (DistrictMap::INSTITUTIONS as $institutionId => $institution) {
            foreach ($institution['fields'] as $field) {
                $this->assertContains(
                    $field,
                    $dtoFields,
                    sprintf('Institution "%s" declares field "%s", which does not exist on MacroStateDTO.', $institutionId, $field)
                );
            }
        }
    }

    public function testEveryInstitutionReadoutIsADeclaredFieldWithAValidUnit(): void
    {
        $dtoFields = array_keys((new MacroStateDTO())->toArray());
        $validUnits = [DistrictMap::UNIT_PERCENT, DistrictMap::UNIT_INDEX];

        foreach (DistrictMap::INSTITUTIONS as $institutionId => $institution) {
            $this->assertNotEmpty(
                $institution['readouts'] ?? [],
                sprintf('Institution "%s" prints no readouts — it would show only a name and a stress pulse.', $institutionId)
            );

            foreach ($institution['readouts'] as $readout) {
                $this->assertContains(
                    $readout['field'],
                    $institution['fields'],
                    sprintf('Institution "%s" prints readout field "%s", which it does not declare in its own "fields".', $institutionId, $readout['field'])
                );
                $this->assertContains(
                    $readout['field'],
                    $dtoFields,
                    sprintf('Institution "%s" readout field "%s" does not exist on MacroStateDTO.', $institutionId, $readout['field'])
                );
                $this->assertNotEmpty($readout['label'], sprintf('Institution "%s" has a readout with no label.', $institutionId));
                $this->assertContains(
                    $readout['unit'],
                    $validUnits,
                    sprintf('Institution "%s" readout for "%s" has an invalid unit.', $institutionId, $readout['field'])
                );
            }
        }
    }

    public function testEveryStressRuleReferencesARealFieldAndOperator(): void
    {
        $dtoFields = array_keys((new MacroStateDTO())->toArray());
        $validOps = [DistrictMap::OP_GTE, DistrictMap::OP_LTE, DistrictMap::OP_LT, DistrictMap::OP_INDEX_DEVIATION];

        foreach (DistrictMap::INSTITUTIONS as $institutionId => $institution) {
            foreach ($institution['stress_rules'] ?? [] as $rule) {
                $this->assertContains($rule['field'], $dtoFields, sprintf('Institution "%s" stress rule references unknown field "%s".', $institutionId, $rule['field']));
                $this->assertContains($rule['op'], $validOps, sprintf('Institution "%s" stress rule for "%s" has an invalid operator.', $institutionId, $rule['field']));
            }
        }
    }

    public function testNoInstitutionIsPureDecoration(): void
    {
        $wired = [];
        foreach (self::businessModelProvider() as [$businessModel]) {
            foreach ($this->resolver->conduitsForBusinessModel($businessModel) as $institutionId) {
                $wired[$institutionId] = true;
            }
        }

        foreach (array_keys(DistrictMap::INSTITUTIONS) as $institutionId) {
            $this->assertArrayHasKey(
                $institutionId,
                $wired,
                sprintf('Institution "%s" has no business model wired to it anywhere.', $institutionId)
            );
        }
    }

    /**
     * The tripwire this rework's design depends on: DistrictMap::UBIQUITOUS_MACRO_FIELDS exists
     * specifically to stop a near-universal field (output_gap_ema was 93% of models before
     * exclusion) from wiring every tenant to one institution. This asserts the exclusion is
     * actually holding — if a future model addition pushes any institution's fan-in back over the
     * threshold, this fails rather than the map quietly degrading into everything-to-everything.
     */
    public function testNoInstitutionExceedsSixtyPercentFanIn(): void
    {
        $businessModels = array_map(static fn (array $case) => $case[0], self::businessModelProvider());
        $total = count($businessModels);

        foreach (array_keys(DistrictMap::INSTITUTIONS) as $institutionId) {
            $wiredCount = 0;
            foreach ($businessModels as $businessModel) {
                if (in_array($institutionId, $this->resolver->conduitsForBusinessModel($businessModel), true)) {
                    $wiredCount++;
                }
            }

            $this->assertLessThanOrEqual(
                0.60,
                $wiredCount / $total,
                sprintf('Institution "%s" is wired to %d/%d (%.0f%%) of business models — likely a field needs to join UBIQUITOUS_MACRO_FIELDS.', $institutionId, $wiredCount, $total, 100 * $wiredCount / $total)
            );
        }
    }

    /**
     * The mirror image of the tripwire above: every excluded field must actually be common, or
     * the exclusion list could be hiding a real, meaningful dependency rather than diluting a
     * universal one.
     */
    public function testEveryUbiquitousFieldIsGenuinelyReadByAMajorityOfModels(): void
    {
        $businessModels = array_map(static fn (array $case) => $case[0], self::businessModelProvider());
        $total = count($businessModels);

        foreach (DistrictMap::UBIQUITOUS_MACRO_FIELDS as $field) {
            $readers = 0;
            foreach ($businessModels as $businessModel) {
                if (in_array($field, Sectors::getBusinessModelStrategy($businessModel)->getOperatingMacroFields(), true)) {
                    $readers++;
                }
            }

            $this->assertGreaterThan(
                $total / 2,
                $readers,
                sprintf('"%s" is listed as ubiquitous but only %d/%d models read it.', $field, $readers, $total)
            );
        }
    }

    /**
     * Regression guard for Glasswater Row: the 14 financial models' derived conduits, captured at
     * the moment CONDUITS (the old hand-maintained table) was replaced by this derivation, so any
     * future change to a financial model's declared fields that shifts its topology is visible in
     * a failing assertion rather than a silent diff.
     */
    public static function financialModelConduitProvider(): array
    {
        return [
            'commercial_bank' => ['commercial_bank', ['rate-council', 'credit-registry', 'statistical-office', 'land-registry']],
            'credit_services' => ['credit_services', ['rate-council', 'credit-registry', 'statistical-office']],
            'shadow_bank' => ['shadow_bank', ['rate-council', 'credit-registry', 'land-registry']],
            'investment_bank' => ['investment_bank', ['rate-council', 'credit-registry', 'exchange-floor']],
            'brokerage' => ['brokerage', ['rate-council', 'credit-registry', 'exchange-floor']],
            'clearing_house' => ['clearing_house', ['rate-council', 'credit-registry', 'exchange-floor']],
            'asset_manager' => ['asset_manager', ['rate-council', 'exchange-floor']],
            'private_equity' => ['private_equity', ['rate-council', 'credit-registry', 'exchange-floor']],
            'hedge_fund' => ['hedge_fund', ['rate-council', 'credit-registry', 'exchange-floor']],
            'distressed_debt' => ['distressed_debt', ['credit-registry']],
            'insurance' => ['insurance', ['rate-council', 'exchange-floor', 'land-registry']],
            'reinsurance' => ['reinsurance', ['rate-council', 'exchange-floor', 'land-registry']],
            'retail_insurance' => ['retail_insurance', ['rate-council', 'exchange-floor', 'land-registry']],
            'financial_data' => ['financial_data', ['credit-registry', 'exchange-floor']],
        ];
    }

    #[DataProvider('financialModelConduitProvider')]
    public function testFinancialModelConduitsMatchTheRegressionSnapshot(string $businessModel, array $expected): void
    {
        $actual = $this->resolver->conduitsForBusinessModel($businessModel);
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, sprintf('"%s" derived conduits drifted from the regression snapshot.', $businessModel));
    }
}

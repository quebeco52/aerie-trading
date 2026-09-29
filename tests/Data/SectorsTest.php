<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\Sectors;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SectorsTest extends TestCase
{
    public static function industryProvider(): array
    {
        $industries = [];
        foreach (Sectors::INDUSTRY_METRICS as $industry => $metrics) {
            $industries[$industry] = [$industry, $metrics];
        }
        return $industries;
    }

    public static function macroSectorProvider(): array
    {
        $sectors = [];
        foreach (Sectors::MACRO_SECTORS as $sector => $pe) {
            $sectors[$sector] = [$sector, $pe];
        }
        return $sectors;
    }

    #[DataProvider('macroSectorProvider')]
    public function testMacroSectorsHavePositiveValuationMultiples(string $sector, float $pe): void
    {
        $this->assertNotEmpty($sector, 'Macro sector name cannot be empty');
        $this->assertGreaterThan(0.0, $pe, "Sector {$sector} must have positive valuation multiple");
        $this->assertLessThan(100.0, $pe, "Sector {$sector} PE multiple is unreasonably high");
    }

    #[DataProvider('industryProvider')]
    public function testIndustryMetricsSchemaAndStrategyResolution(string $industry, array $metrics): void
    {
        $this->assertArrayHasKey('pe', $metrics, "Missing 'pe' for industry: {$industry}");
        $this->assertArrayHasKey('depreciation', $metrics, "Missing 'depreciation' for industry: {$industry}");
        $this->assertArrayHasKey('ebitda_limit', $metrics, "Missing 'ebitda_limit' for industry: {$industry}");
        $this->assertArrayHasKey('equity_limit', $metrics, "Missing 'equity_limit' for industry: {$industry}");
        $this->assertArrayHasKey('business_model', $metrics, "Missing 'business_model' for industry: {$industry}");

        $this->assertGreaterThan(0.0, $metrics['pe'], "PE ratio must be positive for {$industry}");
        $this->assertGreaterThanOrEqual(0.0, $metrics['depreciation'], "Depreciation must be non-negative for {$industry}");
        $this->assertLessThanOrEqual(0.50, $metrics['depreciation'], "Depreciation exceeds 50% for {$industry}");

        $this->assertGreaterThanOrEqual(0.0, $metrics['ebitda_limit'], "EBITDA limit must be non-negative for {$industry}");
        $this->assertGreaterThanOrEqual(0.0, $metrics['equity_limit'], "Equity limit must be non-negative for {$industry}");

        // Strategy resolution
        $modelKey = $metrics['business_model'];
        $strategy = Sectors::getBusinessModelStrategy($modelKey);
        $this->assertInstanceOf(BusinessModelInterface::class, $strategy, "Strategy for {$modelKey} must implement BusinessModelInterface");
        $this->assertGreaterThan(0.0, $strategy->getMinIcr(), "Model {$modelKey} min ICR must be positive");
        $this->assertGreaterThan(0.0, $strategy->getBuybackMinIcr(), "Model {$modelKey} buyback min ICR must be positive");
        $this->assertGreaterThan(0.0, $strategy->getDividendCrisisIcr(), "Model {$modelKey} dividend crisis ICR must be positive");
        $this->assertGreaterThan(0.0, $strategy->getReversionSpeed(), "Model {$modelKey} reversion speed must be positive");
    }

    public function testAllBusinessModelDescriptionsAreNonEmpty(): void
    {
        $this->assertNotEmpty(Sectors::BUSINESS_MODEL_DESCRIPTIONS);

        foreach (Sectors::BUSINESS_MODEL_DESCRIPTIONS as $key => $description) {
            $this->assertIsString($key);
            $this->assertNotEmpty($description, "Description for {$key} must not be empty");

            $strategy = Sectors::getBusinessModelStrategy($key);
            $this->assertInstanceOf(BusinessModelInterface::class, $strategy, "Model {$key} in descriptions must resolve to a valid strategy");
        }
    }


    /**
     * Catalog industries with no model of their own are aliased to the nearest real model rather than falling
     * through to the generic corporate physics, so a future ticker in one of them inherits sector behaviour.
     */
    #[DataProvider('aliasedIndustryProvider')]
    public function testCatalogIndustriesAliasToTheNearestRealModel(string $industry, string $model): void
    {
        $this->assertSame($model, Sectors::INDUSTRY_METRICS[$industry]['business_model']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function aliasedIndustryProvider(): iterable
    {
        yield 'trucking runs the logistics book' => ['Trucking', 'logistics'];
        yield 'footwear shares the apparel channel mix' => ['Footwear & Accessories', 'apparel_manufacturing'];
        yield 'lodging is hospitality' => ['Lodging', 'resorts_casinos'];
        yield 'hotel REITs carry the variable hospitality lease' => ['REIT - Hotel & Motel', 'reit'];
        yield 'homebuilders are construction' => ['Residential Construction', 'construction'];
        yield 'components ride the hardware cycle' => ['Electronic Components', 'computer_hardware'];
        yield 'pollution controls are waste management' => ['Pollution & Treatment Controls', 'waste_management'];
        yield 'consulting is a professional-services partnership' => ['Consulting Services', 'law_firm'];
    }

    /**
     * The registry is the only list of models, so a model class it does not name would never run: every industry
     * pointing at it would silently get standard corporate physics instead.
     */
    public function testEveryModelClassIsRegisteredExactlyOnce(): void
    {
        $registered = array_count_values(Sectors::BUSINESS_MODELS);

        foreach (glob(dirname(__DIR__, 2) . '/src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            $class = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $this->assertSame(1, $registered[$class] ?? 0, "{$class} must be registered under exactly one identifier");
        }
    }

    #[DataProvider('industryProvider')]
    public function testEveryIndustryRunsARegisteredModel(string $industry, array $metrics): void
    {
        $this->assertArrayHasKey($metrics['business_model'], Sectors::BUSINESS_MODELS, "{$industry} names a model the registry does not run");
        $this->assertInstanceOf(Sectors::BUSINESS_MODELS[$metrics['business_model']], Sectors::strategyFor($industry));
    }

    /** One fallback for every parameter: an industry the table does not list reads the General row, whichever field is asked for. */
    public function testAnUnlistedIndustryReadsTheGeneralRow(): void
    {
        $general = Sectors::INDUSTRY_METRICS['General'];

        foreach (['Not An Industry', '', null] as $industry) {
            $this->assertSame($general, Sectors::metricsFor($industry));
            $this->assertSame((float) $general['equity_limit'], Sectors::equityLimit($industry));
            $this->assertSame((float) $general['pe'], Sectors::baselineIndustryPe($industry));
            $this->assertSame('none', Sectors::businessModelFor($industry));
        }
    }

    public function testTheAccessorsReadTheIndustrysOwnRow(): void
    {
        $bank = Sectors::INDUSTRY_METRICS['Banks - Diversified'];

        $this->assertSame((float) $bank['equity_limit'], Sectors::equityLimit('Banks - Diversified'));
        $this->assertSame((float) $bank['pe'], Sectors::baselineIndustryPe('Banks - Diversified'));
        $this->assertSame(Sectors::getBusinessModelStrategy('commercial_bank'), Sectors::strategyFor('Banks - Diversified'));
    }

    public function testAnUnregisteredModelRunsStandardCorporatePhysics(): void
    {
        $this->assertInstanceOf(StandardCorporateBusinessModel::class, Sectors::getBusinessModelStrategy('not_a_model'));
        $this->assertSame(Sectors::getBusinessModelStrategy('none'), Sectors::getBusinessModelStrategy('not_a_model'));
    }

    /** The financial flag is each model's own; pinned so a model cannot change sides without the change being seen. */
    public function testTheFinancialInstitutionsAreTheLendersUnderwritersAndMarketInfrastructure(): void
    {
        $financial = array_keys(array_filter(Sectors::BUSINESS_MODELS, static fn (string $class, string $id): bool => Sectors::isFinancial($id), ARRAY_FILTER_USE_BOTH));
        sort($financial);

        $this->assertSame([
            'asset_manager', 'brokerage', 'clearing_house', 'commercial_bank', 'credit_services', 'distressed_debt',
            'hedge_fund', 'insurance', 'investment_bank', 'private_equity', 'reinsurance', 'retail_insurance', 'shadow_bank',
        ], $financial);
    }
}

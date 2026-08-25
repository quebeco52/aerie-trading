<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\Sectors;
use App\Service\Model\BusinessModelInterface;
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
        $thresholds = $strategy->getModelThresholds();
        $this->assertIsArray($thresholds, "Model {$modelKey} must return array of thresholds");
        $this->assertArrayHasKey('min_icr', $thresholds, "Model {$modelKey} thresholds must define min_icr");
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
}

<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\ModelParam;
use App\Data\StockModelTuning;
use App\DTO\ModelParameters;
use PHPUnit\Framework\TestCase;

class StockModelTuningTest extends TestCase
{
    public function testSilcTickerOverridesAreCorrectlyResolved(): void
    {
        $defaults = [
            ModelParam::FoundryRevenueWeight->value => 0.50,
            ModelParam::DesignRevenueWeight->value  => 0.50,
        ];

        $resolved = StockModelTuning::resolve('SILC', $defaults);

        $this->assertInstanceOf(ModelParameters::class, $resolved);
        $this->assertSame(0.85, $resolved->get(ModelParam::FoundryRevenueWeight));
        $this->assertSame(0.15, $resolved->get(ModelParam::DesignRevenueWeight));
    }

    public function testUnknownTickerFallsBackToDefaults(): void
    {
        $defaults = [
            ModelParam::FoundryRevenueWeight->value => 0.50,
            ModelParam::DesignRevenueWeight->value  => 0.50,
        ];

        $resolved = StockModelTuning::resolve('UNKNOWN_TICKER', $defaults);

        $this->assertSame(0.50, $resolved->get(ModelParam::FoundryRevenueWeight));
        $this->assertSame(0.50, $resolved->get(ModelParam::DesignRevenueWeight));
    }

    public function testGetWithModelParamAndDefault(): void
    {
        $this->assertSame(1.80, StockModelTuning::get('PERE', ModelParam::VixArbitrageScalar, 1.0));
        $this->assertSame(1.00, StockModelTuning::get('UNKNOWN', ModelParam::VixArbitrageScalar, 1.0));
    }

    public function testAllOverridesUseValidModelParamKeys(): void
    {
        foreach (StockModelTuning::OVERRIDES as $ticker => $overrides) {
            foreach (array_keys($overrides) as $key) {
                $enumCase = ModelParam::tryFrom((string) $key);
                $this->assertNotNull(
                    $enumCase,
                    sprintf("Ticker '%s' has an unknown parameter key: '%s'", $ticker, $key)
                );
            }
        }
    }

    public function testStreamWeightSumsAreStrictlyNormalized(): void
    {
        // Define stream weight groups to verify they sum to 1.0 when configured
        $streamGroups = [
            'reinsurance' => [ModelParam::TreatyReinsuranceWeight, ModelParam::CatBondSpreadWeight],
            'retail_insurance' => [ModelParam::PropertyCasualtyWeight, ModelParam::LifeAndAnnuityWeight],
            'steel' => [ModelParam::ContractOemWeight, ModelParam::SpotHrcWeight],
            'heavy_mfg' => [ModelParam::OemEquipmentWeight, ModelParam::AftermarketMroWeight],
            'biotech' => [ModelParam::CommercialTherapeuticsWeight, ModelParam::PipelineMilestonesWeight],
            'logistics' => [ModelParam::DedicatedFleetWeight, ModelParam::SpotBrokerageWeight, ModelParam::Warehousing3plWeight],
            'railroad' => [ModelParam::IntermodalFreightWeight, ModelParam::IndustrialCarloadsWeight, ModelParam::BulkCommoditiesWeight],
            'restaurant' => [ModelParam::CompanyStoresWeight, ModelParam::FranchiseRoyaltiesWeight, ModelParam::FranchiseLeaseWeight],
            'education' => [ModelParam::DegreeTuitionWeight, ModelParam::EnterpriseTrainingWeight, ModelParam::LmsLicensingWeight],
            'advertising' => [ModelParam::BrandRetainerWeight, ModelParam::MartechConsultingWeight, ModelParam::MediaBuyingWeight],
            'shadow_bank' => [ModelParam::MortgageOriginationWeight, ModelParam::DirectLendingWeight],
            'distressed_debt' => [ModelParam::RestructuringAdvisoryWeight, ModelParam::TurnaroundGainsWeight],
            'investment_bank' => [ModelParam::AdvisoryRevenueWeight, ModelParam::TradingRevenueWeight, ModelParam::OptionsPremiumIncomeWeight],
            'asset_manager' => [ModelParam::BaseFeeWeight, ModelParam::PerformanceFeeWeight],
            'semiconductor' => [ModelParam::FoundryRevenueWeight, ModelParam::DesignRevenueWeight],
            'luxury' => [ModelParam::HauteCoutureWeight, ModelParam::AccessibleLuxuryWeight],
            'construction' => [ModelParam::CivilInfrastructureWeight, ModelParam::CommercialEpcWeight, ModelParam::FacilitiesMaintenanceWeight],
            'medical_facility' => [ModelParam::InpatientCareWeight, ModelParam::ElectiveOutpatientWeight, ModelParam::InsuranceArbitrageWeight],
            'consumer_staples' => [ModelParam::BrandedStaplesWeight, ModelParam::VolumeCommodityWeight, ModelParam::CommodityTradingWeight, ModelParam::LandSpeculationWeight],
            'commodity' => [ModelParam::ExtractionRevenueWeight, ModelParam::SpotPriceWeight, ModelParam::RefiningSpreadWeight],
            'conglomerate' => [ModelParam::IndustrialConglomerateWeight, ModelParam::DefensiveStaplesWeight, ModelParam::ContrarianFloatWeight],
            'law_firm' => [ModelParam::CorporateRetainerWeight, ModelParam::LitigationContingencyWeight, ModelParam::RestructuringAdvisoryWeight],
            'auto_manufacturer' => [ModelParam::AutoSalesWeight, ModelParam::ApexLuxuryWeight, ModelParam::SoftwareServicesWeight],
            'hedge_fund' => [ModelParam::HfManagementFeeWeight, ModelParam::HfDirectionalBetsWeight, ModelParam::HfQuantAlphaWeight],
        ];

        foreach (StockModelTuning::OVERRIDES as $ticker => $overrides) {
            foreach ($streamGroups as $groupName => $params) {
                $hasAny = false;
                $sum = 0.0;
                $activeCount = 0;
                foreach ($params as $param) {
                    if (isset($overrides[$param->value])) {
                        $hasAny = true;
                        $sum += $overrides[$param->value];
                        $activeCount++;
                    }
                }

                // If any parameters from this stream group are present, verify that the configured streams sum to 1.0
                if ($hasAny && count(array_intersect(array_keys($overrides), array_map(fn($p) => $p->value, $params))) === $activeCount && $activeCount >= 2) {
                    $this->assertEqualsWithDelta(
                        1.0,
                        $sum,
                        1e-5,
                        sprintf("Ticker '%s' stream group '%s' does not sum to 1.0 (actual: %f)", $ticker, $groupName, $sum)
                    );
                }
            }
        }
    }

    public function testNewlyTunedArchetypesMatchLore(): void
    {
        // Black Swan Capital (SWAN)
        $this->assertSame(0.60, StockModelTuning::get('SWAN', ModelParam::HfManagementFeeWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('SWAN', ModelParam::HfDirectionalBetsWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('SWAN', ModelParam::HfQuantAlphaWeight, 0.0));

        // Three Rivers Manufacturing (TRIV)
        $this->assertSame(0.60, StockModelTuning::get('TRIV', ModelParam::IndustrialConglomerateWeight, 0.0));
        $this->assertSame(0.30, StockModelTuning::get('TRIV', ModelParam::DefensiveStaplesWeight, 0.0));
        $this->assertSame(0.10, StockModelTuning::get('TRIV', ModelParam::ContrarianFloatWeight, 0.0));

        // Breakwater Trust (BRKW)
        $this->assertSame(0.30, StockModelTuning::get('BRKW', ModelParam::IndustrialConglomerateWeight, 0.0));
        $this->assertSame(0.45, StockModelTuning::get('BRKW', ModelParam::DefensiveStaplesWeight, 0.0));
        $this->assertSame(0.25, StockModelTuning::get('BRKW', ModelParam::ContrarianFloatWeight, 0.0));

        // Clear Rivers Law / Claw & Talons Law (CLAW)
        $this->assertSame(0.40, StockModelTuning::get('CLAW', ModelParam::CorporateRetainerWeight, 0.0));
        $this->assertSame(0.35, StockModelTuning::get('CLAW', ModelParam::LitigationContingencyWeight, 0.0));
        $this->assertSame(0.25, StockModelTuning::get('CLAW', ModelParam::RestructuringAdvisoryWeight, 0.0));

        // Falconet Motor Group (FALC)
        $this->assertSame(0.55, StockModelTuning::get('FALC', ModelParam::AutoSalesWeight, 0.0));
        $this->assertSame(0.25, StockModelTuning::get('FALC', ModelParam::ApexLuxuryWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('FALC', ModelParam::SoftwareServicesWeight, 0.0));

        // Sinking Shore Extraction (SINK)
        $this->assertSame(0.50, StockModelTuning::get('SINK', ModelParam::ExtractionRevenueWeight, 0.0));
        $this->assertSame(0.50, StockModelTuning::get('SINK', ModelParam::SpotPriceWeight, 0.0));
        $this->assertSame(0.00, StockModelTuning::get('SINK', ModelParam::RefiningSpreadWeight, 0.0));
        $this->assertSame(0.85, StockModelTuning::get('SINK', ModelParam::SpotPriceSensitivity, 0.0));

        // Cascade Refining & Marketing (CASC)
        $this->assertSame(0.25, StockModelTuning::get('CASC', ModelParam::ExtractionRevenueWeight, 0.0));
        $this->assertSame(0.15, StockModelTuning::get('CASC', ModelParam::SpotPriceWeight, 0.0));
        $this->assertSame(0.60, StockModelTuning::get('CASC', ModelParam::RefiningSpreadWeight, 0.0));
        $this->assertSame(0.30, StockModelTuning::get('CASC', ModelParam::SpotPriceSensitivity, 0.0));

        // Condor Extraction (CNDR)
        $this->assertSame(0.60, StockModelTuning::get('CNDR', ModelParam::ExtractionRevenueWeight, 0.0));
        $this->assertSame(0.40, StockModelTuning::get('CNDR', ModelParam::SpotPriceWeight, 0.0));
        $this->assertSame(0.00, StockModelTuning::get('CNDR', ModelParam::RefiningSpreadWeight, 0.0));
        $this->assertSame(0.70, StockModelTuning::get('CNDR', ModelParam::SpotPriceSensitivity, 0.0));

        // Safe Harbor Reinsurance (SAFE)
        $this->assertSame(0.60, StockModelTuning::get('SAFE', ModelParam::TreatyReinsuranceWeight, 0.0));
        $this->assertSame(0.40, StockModelTuning::get('SAFE', ModelParam::CatBondSpreadWeight, 0.0));

        // White Dove Insurance (DOVE)
        $this->assertSame(0.55, StockModelTuning::get('DOVE', ModelParam::PropertyCasualtyWeight, 0.0));
        $this->assertSame(0.45, StockModelTuning::get('DOVE', ModelParam::LifeAndAnnuityWeight, 0.0));

        // Steel Wings (WING)
        $this->assertSame(0.70, StockModelTuning::get('WING', ModelParam::ContractOemWeight, 0.0));
        $this->assertSame(0.30, StockModelTuning::get('WING', ModelParam::SpotHrcWeight, 0.0));

        // Iron Beak Heavy Industries (IBHI)
        $this->assertSame(0.60, StockModelTuning::get('IBHI', ModelParam::CivilInfrastructureWeight, 0.0));
        $this->assertSame(0.25, StockModelTuning::get('IBHI', ModelParam::CommercialEpcWeight, 0.0));
        $this->assertSame(0.15, StockModelTuning::get('IBHI', ModelParam::FacilitiesMaintenanceWeight, 0.0));
        $this->assertSame(0.35, StockModelTuning::get('IBHI', ModelParam::PricingPowerIndex, 0.0));

        // Crane Medical Network (CRAN)
        $this->assertSame(0.50, StockModelTuning::get('CRAN', ModelParam::InpatientCareWeight, 0.0));
        $this->assertSame(0.30, StockModelTuning::get('CRAN', ModelParam::ElectiveOutpatientWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('CRAN', ModelParam::InsuranceArbitrageWeight, 0.0));
        $this->assertSame(0.80, StockModelTuning::get('CRAN', ModelParam::PricingPowerIndex, 0.0));

        // Sugarbird Confectionery (SGRB)
        $this->assertSame(0.55, StockModelTuning::get('SGRB', ModelParam::BrandedStaplesWeight, 0.0));
        $this->assertSame(0.10, StockModelTuning::get('SGRB', ModelParam::VolumeCommodityWeight, 0.0));
        $this->assertSame(0.35, StockModelTuning::get('SGRB', ModelParam::CommodityTradingWeight, 0.0));

        // Pintail Beverage Group (PINT)
        $this->assertSame(0.60, StockModelTuning::get('PINT', ModelParam::BrandedStaplesWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('PINT', ModelParam::VolumeCommodityWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('PINT', ModelParam::CommodityTradingWeight, 0.0));

        // Canvasback Logistics (CANV)
        $this->assertSame(0.55, StockModelTuning::get('CANV', ModelParam::DedicatedFleetWeight, 0.0));
        $this->assertSame(0.25, StockModelTuning::get('CANV', ModelParam::SpotBrokerageWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('CANV', ModelParam::Warehousing3plWeight, 0.0));

        // Kestrel Civic Lines (KSTL)
        $this->assertSame(0.50, StockModelTuning::get('KSTL', ModelParam::IntermodalFreightWeight, 0.0));
        $this->assertSame(0.30, StockModelTuning::get('KSTL', ModelParam::IndustrialCarloadsWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('KSTL', ModelParam::BulkCommoditiesWeight, 0.0));

        // Copperhead Coffee (BREW)
        $this->assertSame(0.70, StockModelTuning::get('BREW', ModelParam::CompanyStoresWeight, 0.0));
        $this->assertSame(0.10, StockModelTuning::get('BREW', ModelParam::FranchiseRoyaltiesWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('BREW', ModelParam::FranchiseLeaseWeight, 0.0));

        // Golden Swift (SWFT)
        $this->assertSame(0.05, StockModelTuning::get('SWFT', ModelParam::CompanyStoresWeight, 0.0));
        $this->assertSame(0.55, StockModelTuning::get('SWFT', ModelParam::FranchiseRoyaltiesWeight, 0.0));
        $this->assertSame(0.40, StockModelTuning::get('SWFT', ModelParam::FranchiseLeaseWeight, 0.0));

        // Vulture Capital Recovery (VULT)
        $this->assertSame(0.35, StockModelTuning::get('VULT', ModelParam::RestructuringAdvisoryWeight, 0.0));
        $this->assertSame(0.65, StockModelTuning::get('VULT', ModelParam::TurnaroundGainsWeight, 0.0));

        // Starling Academic Systems (STAR)
        $this->assertSame(0.40, StockModelTuning::get('STAR', ModelParam::DegreeTuitionWeight, 0.0));
        $this->assertSame(0.40, StockModelTuning::get('STAR', ModelParam::EnterpriseTrainingWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('STAR', ModelParam::LmsLicensingWeight, 0.0));

        // Lyrebird Media (LYRE)
        $this->assertSame(0.50, StockModelTuning::get('LYRE', ModelParam::BrandRetainerWeight, 0.0));
        $this->assertSame(0.30, StockModelTuning::get('LYRE', ModelParam::MartechConsultingWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('LYRE', ModelParam::MediaBuyingWeight, 0.0));

        // Brine Pool Capital (POOL)
        $this->assertSame(0.60, StockModelTuning::get('POOL', ModelParam::MortgageOriginationWeight, 0.0));
        $this->assertSame(0.40, StockModelTuning::get('POOL', ModelParam::DirectLendingWeight, 0.0));
    }

    public function testAllInitialStocksMapToValidSectorsAndBusinessModels(): void
    {
        $registry = new \App\Service\Model\BusinessModelRegistry([
            new \App\Service\Model\Sector\AdvertisingAgencyBusinessModel(),
            new \App\Service\Model\Sector\AssetManagementBusinessModel(),
            new \App\Service\Model\Sector\AutoManufacturerBusinessModel(),
            new \App\Service\Model\Sector\BiotechBusinessModel(),
            new \App\Service\Model\Sector\BrokerageBusinessModel(),
            new \App\Service\Model\Sector\ClearingHouseBusinessModel(),
            new \App\Service\Model\Sector\CommercialBankBusinessModel(),
            new \App\Service\Model\Sector\CommodityBusinessModel(),
            new \App\Service\Model\Sector\ComputerHardwareBusinessModel(),
            new \App\Service\Model\Sector\ConglomerateBusinessModel(),
            new \App\Service\Model\Sector\ConstructionBusinessModel(),
            new \App\Service\Model\Sector\ConsumerStaplesBusinessModel(),
            new \App\Service\Model\Sector\CreditServicesBusinessModel(),
            new \App\Service\Model\Sector\DefenseContractorBusinessModel(),
            new \App\Service\Model\Sector\DistressedDebtBusinessModel(),
            new \App\Service\Model\Sector\EducationBusinessModel(),
            new \App\Service\Model\Sector\FinancialDataBusinessModel(),
            new \App\Service\Model\Sector\HeavyManufacturingBusinessModel(),
            new \App\Service\Model\Sector\HedgeFundBusinessModel(),
            new \App\Service\Model\Sector\InsuranceBusinessModel(),
            new \App\Service\Model\Sector\InternetRetailBusinessModel(),
            new \App\Service\Model\Sector\InvestmentBankBusinessModel(),
            new \App\Service\Model\Sector\LawFirmBusinessModel(),
            new \App\Service\Model\Sector\LogisticsBusinessModel(),
            new \App\Service\Model\Sector\LuxuryBusinessModel(),
            new \App\Service\Model\Sector\MedicalCareFacilityBusinessModel(),
            new \App\Service\Model\Sector\PrivateEquityBusinessModel(),
            new \App\Service\Model\Sector\RailroadBusinessModel(),
            new \App\Service\Model\Sector\ReinsuranceBusinessModel(),
            new \App\Service\Model\Sector\ReitBusinessModel(),
            new \App\Service\Model\Sector\ResortsCasinosBusinessModel(),
            new \App\Service\Model\Sector\RestaurantBusinessModel(),
            new \App\Service\Model\Sector\RetailInsuranceBusinessModel(),
            new \App\Service\Model\Sector\SecurityProtectionBusinessModel(),
            new \App\Service\Model\Sector\SemiconductorBusinessModel(),
            new \App\Service\Model\Sector\ShadowBankBusinessModel(),
            new \App\Service\Model\Sector\ShippingBusinessModel(),
            new \App\Service\Model\Sector\SpecialtyIndustrialMachineryBusinessModel(),
            new \App\Service\Model\Sector\StandardCorporateBusinessModel(),
            new \App\Service\Model\Sector\SteelManufacturingBusinessModel(),
            new \App\Service\Model\Sector\TechBusinessModel(),
            new \App\Service\Model\Sector\TelecomBusinessModel(),
            new \App\Service\Model\Sector\ToolsAndAccessoriesBusinessModel(),
            new \App\Service\Model\Sector\UtilityBusinessModel(),
            new \App\Service\Model\Sector\WasteManagementBusinessModel(),
        ]);

        foreach (\App\Data\InitialMarket::STOCKS as $stockData) {
            $ticker = $stockData['ticker'];
            $industry = $stockData['industry'];

            $this->assertArrayHasKey(
                $industry,
                \App\Data\Sectors::INDUSTRY_METRICS,
                sprintf("Ticker '%s' industry '%s' not found in Sectors::INDUSTRY_METRICS", $ticker, $industry)
            );

            $modelKey = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'];
            $this->assertTrue(
                $registry->has($modelKey),
                sprintf("Ticker '%s' (industry: %s) references unregistered business model key '%s'", $ticker, $industry, $modelKey)
            );
        }
    }

    public function testAllBusinessModelsHaveLoreDescription(): void
    {
        foreach (\App\Data\Sectors::INDUSTRY_METRICS as $industry => $metrics) {
            $modelKey = $metrics['business_model'];
            $this->assertArrayHasKey(
                $modelKey,
                \App\Data\Sectors::BUSINESS_MODEL_DESCRIPTIONS,
                sprintf("Business model key '%s' (for industry '%s') is missing from Sectors::BUSINESS_MODEL_DESCRIPTIONS", $modelKey, $industry)
            );
        }
    }
}


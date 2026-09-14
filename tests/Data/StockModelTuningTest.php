<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\InitialMarket;
use App\Data\ModelParam;
use App\Data\Sectors;
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

    /**
     * A tuning override is only a tuning override if something reads it. Setting a parameter the ticker's
     * own business model never resolves is indistinguishable from a comment: it states an intent the
     * simulation does not act on, and nothing fails, so it survives indefinitely. Eight such overrides
     * accumulated before this guard existed (a brokerage given the investment bank's VIX dial, a shadow
     * bank given the auto maker's rate scalar, two insurers given a portfolio volatility nothing modelled).
     *
     * A parameter counts as read if the name appears anywhere the resolved model can reach — its own class,
     * any ancestor, any trait used along that chain — or in the corporate/market engines that call models
     * back. That is deliberately generous: the point is to catch a parameter with NO reader at all, not to
     * police which line reads it.
     */
    public function testEveryOverrideIsReadableByTheTickersOwnBusinessModel(): void
    {
        $engineSource = $this->concatenate([
            __DIR__ . '/../../src/Service/Corporate',
            __DIR__ . '/../../src/Service/Market',
            __DIR__ . '/../../src/Service/Model',
            __DIR__ . '/../../src/Service/Model/Strategy',
            __DIR__ . '/../../src/Service/Event',
        ]);

        $modelByTicker = [];
        foreach (InitialMarket::STOCKS as $stock) {
            $industry = $stock['industry'] ?? 'General';
            $modelByTicker[$stock['ticker']] = Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        }

        foreach (StockModelTuning::OVERRIDES as $ticker => $overrides) {
            $this->assertArrayHasKey(
                $ticker,
                $modelByTicker,
                sprintf("Ticker '%s' has tuning overrides but is not a listed company.", $ticker)
            );

            $strategy = Sectors::getBusinessModelStrategy($modelByTicker[$ticker]);
            $reachable = $this->sourceReachableFrom($strategy) . $engineSource;

            foreach (array_keys($overrides) as $key) {
                $case = ModelParam::tryFrom((string) $key);
                if ($case === null) {
                    continue; // Already covered by testAllOverridesUseValidModelParamKeys.
                }

                // Asserted as a bool rather than assertStringContainsString: the haystack is the whole
                // reachable source tree, and PHPUnit would print all 600KB of it on failure.
                $this->assertTrue(
                    str_contains($reachable, 'ModelParam::' . $case->name),
                    sprintf(
                        "Ticker '%s' sets %s, but its business model (%s) has no code path that reads it. "
                        . 'Either wire the parameter into the model or drop the override.',
                        $ticker,
                        $case->name,
                        (new \ReflectionClass($strategy))->getShortName()
                    )
                );
            }
        }
    }

    /** Source of a model's own class plus every ancestor and every trait mixed in along that chain. */
    private function sourceReachableFrom(object $model): string
    {
        $source = '';
        $seen = [];
        $class = new \ReflectionClass($model);

        while ($class instanceof \ReflectionClass) {
            foreach (array_merge([$class], array_values($class->getTraits())) as $unit) {
                foreach (array_merge([$unit], array_values($unit->getTraits())) as $file) {
                    $path = $file->getFileName();
                    if (is_string($path) && !isset($seen[$path])) {
                        $seen[$path] = true;
                        $source .= (string) file_get_contents($path);
                    }
                }
            }
            $class = $class->getParentClass() ?: null;
        }

        return $source;
    }

    /** @param list<string> $directories */
    private function concatenate(array $directories): string
    {
        $source = '';
        foreach ($directories as $directory) {
            foreach ((array) glob(rtrim($directory, '/') . '/*.php') as $file) {
                $source .= (string) file_get_contents((string) $file);
            }
        }

        return $source;
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
            'biotech_drug_mix' => [ModelParam::EstablishedDrugWeight, ModelParam::PipelineDrugWeight],
            'logistics' => [ModelParam::DedicatedFleetWeight, ModelParam::SpotBrokerageWeight, ModelParam::Warehousing3plWeight],
            // SubscriptionWeight carries commuter transit for a passenger operator; a pure freight hauler
            // leaves it at zero and the other three still have to account for the whole network.
            'railroad' => [ModelParam::IntermodalFreightWeight, ModelParam::IndustrialCarloadsWeight, ModelParam::BulkCommoditiesWeight, ModelParam::SubscriptionWeight],
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
            'chemical' => [ModelParam::BasePetrochemicalsWeight, ModelParam::SpecialtyChemicalsWeight, ModelParam::AgrochemicalsWeight],
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

        // Eider Motor Group (EIDR)
        $this->assertSame(0.70, StockModelTuning::get('EIDR', ModelParam::AutoSalesWeight, 0.0));
        $this->assertSame(0.15, StockModelTuning::get('EIDR', ModelParam::ApexLuxuryWeight, 0.0));
        $this->assertSame(0.15, StockModelTuning::get('EIDR', ModelParam::SoftwareServicesWeight, 0.0));
        $this->assertSame(0.75, StockModelTuning::get('EIDR', ModelParam::PricingPowerIndex, 0.0));
        $this->assertSame(0.85, StockModelTuning::get('EIDR', ModelParam::RateSensitivityScalar, 0.0));

        // Erne Network Systems (ERNE)
        $this->assertSame(0.75, StockModelTuning::get('ERNE', ModelParam::EnterpriseWeight, 0.0));
        $this->assertSame(0.15, StockModelTuning::get('ERNE', ModelParam::PatentLicensingWeight, 0.0));
        $this->assertSame(0.10, StockModelTuning::get('ERNE', ModelParam::ConsumerWeight, 0.0));
        $this->assertSame(0.75, StockModelTuning::get('ERNE', ModelParam::PricingPowerIndex, 0.0));

        // Sanderling Rock Dynamics (SNDR)
        $this->assertSame(0.55, StockModelTuning::get('SNDR', ModelParam::EquipmentWeight, 0.0));
        $this->assertSame(0.45, StockModelTuning::get('SNDR', ModelParam::ServicesWeight, 0.0));
        $this->assertSame(0.80, StockModelTuning::get('SNDR', ModelParam::PricingPowerIndex, 0.0));
        $this->assertSame(1.15, StockModelTuning::get('SNDR', ModelParam::OperatingCyclicality, 0.0));

        // Alca Compression Dynamics (ALCA)
        $this->assertSame(0.60, StockModelTuning::get('ALCA', ModelParam::EquipmentWeight, 0.0));
        $this->assertSame(0.40, StockModelTuning::get('ALCA', ModelParam::ServicesWeight, 0.0));
        $this->assertSame(0.85, StockModelTuning::get('ALCA', ModelParam::PricingPowerIndex, 0.0));
        $this->assertSame(1.05, StockModelTuning::get('ALCA', ModelParam::OperatingCyclicality, 0.0));

        // Nuthatch Climate Systems (NUTH)
        $this->assertSame(0.70, StockModelTuning::get('NUTH', ModelParam::OemEquipmentWeight, 0.0));
        $this->assertSame(0.30, StockModelTuning::get('NUTH', ModelParam::AftermarketMroWeight, 0.0));
        $this->assertSame(0.70, StockModelTuning::get('NUTH', ModelParam::PricingPowerIndex, 0.0));
        $this->assertSame(1.25, StockModelTuning::get('NUTH', ModelParam::OperatingCyclicality, 0.0));

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

        // Kestrel Civic Lines (KSTL) — a commuter monopoly that also hauls freight, not a freight hauler.
        $this->assertSame(0.50, StockModelTuning::get('KSTL', ModelParam::SubscriptionWeight, 0.0));
        $this->assertSame(0.25, StockModelTuning::get('KSTL', ModelParam::IntermodalFreightWeight, 0.0));
        $this->assertSame(0.15, StockModelTuning::get('KSTL', ModelParam::IndustrialCarloadsWeight, 0.0));
        $this->assertSame(0.10, StockModelTuning::get('KSTL', ModelParam::BulkCommoditiesWeight, 0.0));

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

        // Fulmar Chemical Group (FULM)
        $this->assertSame(0.50, StockModelTuning::get('FULM', ModelParam::BasePetrochemicalsWeight, 0.0));
        $this->assertSame(0.30, StockModelTuning::get('FULM', ModelParam::SpecialtyChemicalsWeight, 0.0));
        $this->assertSame(0.20, StockModelTuning::get('FULM', ModelParam::AgrochemicalsWeight, 0.0));
        $this->assertSame(0.55, StockModelTuning::get('FULM', ModelParam::PricingPowerIndex, 0.0));
    }

    public function testAllInitialStocksMapToValidSectorsAndBusinessModels(): void
    {
        $registry = new \App\Service\Model\BusinessModelRegistry([
            new \App\Service\Model\Sector\AdvertisingAgencyBusinessModel(),
            new \App\Service\Model\Sector\ApparelManufacturingBusinessModel(),
            new \App\Service\Model\Sector\AssetManagementBusinessModel(),
            new \App\Service\Model\Sector\AutoManufacturerBusinessModel(),
            new \App\Service\Model\Sector\BiotechBusinessModel(),
            new \App\Service\Model\Sector\BrokerageBusinessModel(),
            new \App\Service\Model\Sector\ClearingHouseBusinessModel(),
            new \App\Service\Model\Sector\CommercialBankBusinessModel(),
            new \App\Service\Model\Sector\CommodityBusinessModel(),
            new \App\Service\Model\Sector\ComputerHardwareBusinessModel(),
            new \App\Service\Model\Sector\CommunicationEquipmentBusinessModel(),
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
            new \App\Service\Model\Sector\ChemicalBusinessModel(),
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


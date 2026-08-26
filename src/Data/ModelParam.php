<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Strongly-typed enum representing company-specific business model tuning parameters.
 * Eliminates typo bugs and provides compile-time safety across business models and StockModelTuning.
 */
enum ModelParam: string
{
    // --- Pricing Power & Macro Scalars ---
    case PricingPowerIndex = 'pricing_power_index';
    case RateSensitivityScalar = 'rate_sensitivity_scalar';
    case VixArbitrageScalar = 'vix_arbitrage_scalar';
    case SpotPriceSensitivity = 'spot_price_sensitivity';
    case AdvertisingCyclicality = 'advertising_cyclicality';
    case MonopolyAggression = 'monopoly_aggression';
    case CeclSpreadSensitivity = 'cecl_spread_sensitivity';
    case NimInversionSensitivity = 'nim_inversion_sensitivity';
    case CreditRiskAppetite = 'credit_risk_appetite';
    case AumMarketBetaScalar = 'aum_market_beta_scalar';
    case PerformanceFeeZFloor = 'performance_fee_z_floor';
    case PerformanceFeeScalar = 'performance_fee_scalar';

    // --- Insurance & Underwriting ---
    case CatastropheZThreshold = 'catastrophe_z_threshold';
    case CatastropheLossScalar = 'catastrophe_loss_scalar';
    case FloatEquityWeight = 'float_equity_weight';
    case EquityPortfolioVol = 'equity_portfolio_vol';
    case PncWeight = 'pnc_weight';
    case LifeAnnuityWeight = 'life_annuity_weight';
    case PropertyCasualtyWeight = 'property_casualty_weight';
    case LifeAndAnnuityWeight = 'life_and_annuity_weight';
    case TreatyReinsuranceWeight = 'treaty_reinsurance_weight';
    case CatBondSpreadWeight = 'cat_bond_spread_weight';

    // --- Analyst Coverage Visibility ---
    case BaseVisibility = 'base_visibility';
    case CoverageError = 'coverage_error';
    case MinVisibility = 'min_visibility';

    // --- Investment Banking & Brokerage ---
    case AdvisoryRevenueWeight = 'advisory_revenue_weight';
    case TradingRevenueWeight = 'trading_revenue_weight';
    case OptionsPremiumIncomeWeight = 'options_premium_income_weight';

    // --- Asset Management & Private Equity ---
    case BaseFeeWeight = 'base_fee_weight';
    case PerformanceFeeWeight = 'performance_fee_weight';
    case ManagementFeeWeight = 'management_fee_weight';
    case CarriedInterestWeight = 'carried_interest_weight';
    case PrincipalInvestmentsWeight = 'principal_investments_weight';
    case AdvisoryFeeWeight = 'advisory_fee_weight';
    case AssetRecoveryWeight = 'asset_recovery_weight';
    case LoanToOwnGainsWeight = 'loan_to_own_gains_weight';
    case RestructuringAdvisoryWeight = 'restructuring_advisory_weight';
    case TurnaroundGainsWeight = 'turnaround_gains_weight';

    // --- Hedge Fund ---
    case HfManagementFeeWeight = 'hf_management_fee_weight';
    case HfDirectionalBetsWeight = 'hf_directional_bets_weight';
    case HfQuantAlphaWeight = 'hf_quant_alpha_weight';

    // --- Banking & Credit Services ---
    case NiiRevenueWeight = 'nii_revenue_weight';
    case FeeRevenueWeight = 'fee_revenue_weight';
    case ProprietaryDividendWeight = 'proprietary_dividend_weight';
    case LendingRevenueWeight = 'lending_revenue_weight';
    case NetworkRevenueWeight = 'network_revenue_weight';
    case MortgageOriginationWeight = 'mortgage_origination_weight';
    case DirectLendingWeight = 'direct_lending_weight';

    // --- Clearinghouse ---
    case ClearingFeeWeight = 'clearing_fee_weight';
    case CustodyFloatWeight = 'custody_float_weight';
    case DataSubscriptionWeight = 'data_subscription_weight';
    case MarginInterestWeight = 'margin_interest_weight';

    // --- Real Estate (REIT) & Resorts/Casinos ---
    case StickyLeaseWeight = 'sticky_lease_weight';
    case VariableHospitalityWeight = 'variable_hospitality_weight';
    case SecuritizationIncomeWeight = 'securitization_income_weight';
    case LongevityBondYieldWeight = 'longevity_bond_yield_weight';
    case GamingRevenueWeight = 'gaming_revenue_weight';
    case NonGamingRevenueWeight = 'non_gaming_revenue_weight';
    case CommercialRealEstateWeight = 'commercial_real_estate_weight';

    // --- Commodities, Energy & Heavy Industry ---
    case ExtractionRevenueWeight = 'extraction_revenue_weight';
    case SpotPriceWeight = 'spot_price_weight';
    case RefiningSpreadWeight = 'refining_spread_weight';
    case OemEquipmentWeight = 'oem_equipment_weight';
    case AftermarketMroWeight = 'aftermarket_mro_weight';
    case ContractOemWeight = 'contract_oem_weight';
    case SpotHrcWeight = 'spot_hrc_weight';

    // --- Construction & Engineering ---
    case CivilInfrastructureWeight = 'civil_infrastructure_weight';
    case CommercialEpcWeight = 'commercial_epc_weight';
    case FacilitiesMaintenanceWeight = 'facilities_maintenance_weight';

    // --- Technology, Hardware & Telecom ---
    case SubscriptionRevenueWeight = 'subscription_revenue_weight';
    case TransactionRevenueWeight = 'transaction_revenue_weight';
    case AdvertisingRevenueWeight = 'advertising_revenue_weight';
    case CloudInfrastructureWeight = 'cloud_infrastructure_weight';
    case EnterpriseWeight = 'enterprise_weight';
    case ConsumerWeight = 'consumer_weight';
    case SubscriptionWeight = 'subscription_weight';
    case EquipmentWeight = 'equipment_weight';

    // --- Semiconductor ---
    case FoundryRevenueWeight = 'foundry_revenue_weight';
    case DesignRevenueWeight = 'design_revenue_weight';

    // --- Defense & Security ---
    case CostPlusWeight = 'cost_plus_weight';
    case FixedPriceDevWeight = 'fixed_price_dev_weight';
    case DomesticProcurementWeight = 'domestic_procurement_weight';
    case ForeignMilitarySalesWeight = 'foreign_military_sales_weight';
    case GovernmentContractWeight = 'government_contract_weight';
    case RetainerWeight = 'retainer_weight';
    case ExpeditionaryWeight = 'expeditionary_weight';

    // --- Shipping, Logistics & Transportation ---
    case SpotCharterWeight = 'spot_charter_weight';
    case ContractCharterWeight = 'contract_charter_weight';
    case IntermodalFreightWeight = 'intermodal_freight_weight';
    case BulkCommoditiesWeight = 'bulk_commodities_weight';
    case IndustrialCarloadsWeight = 'industrial_carloads_weight';
    case DedicatedFleetWeight = 'dedicated_fleet_weight';
    case SpotBrokerageWeight = 'spot_brokerage_weight';
    case Warehousing3plWeight = 'warehousing_3pl_weight';

    // --- Healthcare, Biopharma & Medical Care Facilities ---
    case EstablishedDrugWeight = 'established_drug_weight';
    case PipelineDrugWeight = 'pipeline_drug_weight';
    case CommercialTherapeuticsWeight = 'commercial_therapeutics_weight';
    case PipelineMilestonesWeight = 'pipeline_milestones_weight';
    case InpatientCareWeight = 'inpatient_care_weight';
    case ElectiveOutpatientWeight = 'elective_outpatient_weight';
    case InsuranceArbitrageWeight = 'insurance_arbitrage_weight';

    // --- Consumer, Retail, Hospitality & Services ---
    case HauteCoutureWeight = 'haute_couture_weight';
    case AccessibleLuxuryWeight = 'accessible_luxury_weight';
    case BrandedStaplesWeight = 'branded_staples_weight';
    case VolumeCommodityWeight = 'volume_commodity_weight';
    case CommodityTradingWeight = 'commodity_trading_weight';
    case LandSpeculationWeight = 'land_speculation_weight';
    case CorporateWeight = 'corporate_weight';
    case FranchiseWeight = 'franchise_weight';
    case CommercialWeight = 'commercial_weight';
    case ApparelWeight = 'apparel_weight';
    case FootwearWeight = 'footwear_weight';
    case ThirdPartyWeight = 'third_party_weight';
    case FirstPartyWeight = 'first_party_weight';
    case DigitalAdsWeight = 'digital_ads_weight';
    case CompanyStoresWeight = 'company_stores_weight';
    case FranchiseRoyaltiesWeight = 'franchise_royalties_weight';
    case FranchiseLeaseWeight = 'franchise_lease_weight';
    case DegreeTuitionWeight = 'degree_tuition_weight';
    case EnterpriseTrainingWeight = 'enterprise_training_weight';
    case LmsLicensingWeight = 'lms_licensing_weight';
    case MediaBuyingWeight = 'media_buying_weight';
    case BrandRetainerWeight = 'brand_retainer_weight';
    case MartechConsultingWeight = 'martech_consulting_weight';
    case DtcRetailWeight = 'dtc_retail_weight';
    case WholesaleChannelWeight = 'wholesale_channel_weight';
    case ContractTextileSupplyWeight = 'contract_textile_supply_weight';

    // --- Automotive ---
    case AutoSalesWeight = 'auto_sales_weight';
    case AutoFinancingWeight = 'auto_financing_weight';
    case SoftwareServicesWeight = 'software_services_weight';
    case ApexLuxuryWeight = 'apex_luxury_weight';

    // --- Conglomerates ---
    case IndustrialConglomerateWeight = 'industrial_conglomerate_weight';
    case DefensiveStaplesWeight = 'defensive_staples_weight';
    case ContrarianFloatWeight = 'contrarian_float_weight';

    // --- Legal Services ---
    case CorporateRetainerWeight = 'corporate_retainer_weight';
    case LitigationContingencyWeight = 'litigation_contingency_weight';

    // --- Utilities & Waste Management ---
    case RegulatedBaseWeight = 'regulated_base_weight';
    case UnregulatedMerchantWeight = 'unregulated_merchant_weight';
    case ResidentialWeight = 'residential_weight';
    case RecyclingWeight = 'recycling_weight';
    case ServicesWeight = 'services_weight';
}

<?php

declare(strict_types=1);

namespace App\Data;

use App\DTO\ModelParameters;

/**
 * Centralized registry for company-specific business model tuning overrides.
 *
 * While industry sector models define universal economic physics, specific firms within
 * an industry often operate distinct business models (e.g., pure quantitative market-making vs.
 * M&A advisory syndicates). This class provides strictly typed, version-controlled overrides
 * grouped clearly by industry sector and business model archetype.
 */
class StockModelTuning
{
    // --- Model Parameter Overrides by Ticker ---
    /**
     * Ticker-keyed parameter tuning map grouped by industry sector and business model archetype.
     *
     * @var array<string, array<string, float>>
     */
    public const OVERRIDES = [
        // =====================================================================
        // INVESTMENT BANKING & BROKERAGE ARCHETYPES
        // =====================================================================

        // --- Peregrine Prime Securities (PERE) ---
        // Quantitative institutional market maker and derivatives options writer (Citadel / Jane Street archetype).
        // 100% Sales & Trading / Volatility Arbitrage, 0% M&A Advisory.
        'PERE' => [
            ModelParam::AdvisoryRevenueWeight->value       => 0.00,
            ModelParam::TradingRevenueWeight->value        => 0.40,
            ModelParam::OptionsPremiumIncomeWeight->value => 0.60,
            ModelParam::VixArbitrageScalar->value          => 1.80,
        ],

        // --- Kingfisher Capital (KING) ---
        // Pure-play M&A advisory syndicate and capital markets boutique (Lazard / Evercore archetype).
        // Hyper-sensitive to corporate deal pipelines and GDP booms.
        'KING' => [
            ModelParam::AdvisoryRevenueWeight->value => 0.75,
            ModelParam::TradingRevenueWeight->value  => 0.25,
            ModelParam::VixArbitrageScalar->value    => 1.20,
        ],

        // --- Rook Proprietary Trading (ROOK) ---
        // High-frequency proprietary trading & institutional execution desk. Pure volatility arbitrage focus.
        'ROOK' => [
            ModelParam::AdvisoryRevenueWeight->value => 0.15,
            ModelParam::TradingRevenueWeight->value  => 0.85,
        ],

        // --- Corvid Strategic Arbitrage (CORV) ---
        // Ultra-exclusive boutique investment bank and weaponized information arbitrage apparatus.
        // Uncorrelated to traditional cycles; thrives on high-frequency arbitrage and institutional volatility.
        'CORV' => [
            ModelParam::AdvisoryRevenueWeight->value => 0.25,
            ModelParam::TradingRevenueWeight->value  => 0.75,
            ModelParam::VixArbitrageScalar->value    => 2.00,
        ],

        // =====================================================================
        // COMMERCIAL BANKING & CREDIT SERVICES ARCHETYPES
        // =====================================================================

        // --- Lakebird Bank (LAKE) ---
        // Universal banking behemoth with captive corporate Lakebird Syndicate dividend streams.
        'LAKE' => [
            ModelParam::ProprietaryDividendWeight->value => 0.20,
            ModelParam::NiiRevenueWeight->value          => 0.60,
            ModelParam::FeeRevenueWeight->value          => 0.20,
            ModelParam::NimInversionSensitivity->value   => 8.0,
            ModelParam::CreditRiskAppetite->value        => 0.40,
        ],

        // --- Riverstone Financial (RIVR) ---
        // Agile regional lender poaching district SMEs. Pure NII model (90%) hyper-sensitive to NIM inversion.
        'RIVR' => [
            ModelParam::NiiRevenueWeight->value          => 0.90,
            ModelParam::FeeRevenueWeight->value          => 0.10,
            ModelParam::NimInversionSensitivity->value   => 12.0,
            ModelParam::CreditRiskAppetite->value        => 0.60,
        ],

        // --- Talon Credit (TALN) ---
        // Prime credit card & digital merchant payment rail network. Substantial swipe interchange fee tollbooth (45%).
        'TALN' => [
            ModelParam::LendingRevenueWeight->value  => 0.55,
            ModelParam::NetworkRevenueWeight->value  => 0.45,
            ModelParam::CeclSpreadSensitivity->value => 1.40,
        ],

        // --- Stork Consumer Credit (STRK) ---
        // Subprime consumer finance & installment loan originator. Highly exposed to credit spread widening.
        'STRK' => [
            ModelParam::LendingRevenueWeight->value  => 0.95,
            ModelParam::NetworkRevenueWeight->value  => 0.05,
            ModelParam::CeclSpreadSensitivity->value => 2.20,
        ],

        // =====================================================================
        // SHADOW BANKING ARCHETYPES
        // =====================================================================

        // --- Brine Pool Capital (POOL) ---
        // High-risk mezzanine financing, subprime bridge loans, RMBS/CMBS securitization engine.
        'POOL' => [
            ModelParam::MortgageOriginationWeight->value => 0.60,
            ModelParam::DirectLendingWeight->value       => 0.40,
        ],

        // =====================================================================
        // INSURANCE & REINSURANCE ARCHETYPES
        // =====================================================================

        // --- Safe Harbor Reinsurance (SAFE) ---
        // Institutional reinsurance titan that absorbs extreme systemic and catastrophe tail risk.
        // Blends quota-share/excess-of-loss treaties with catastrophe risk bond ("True 10-Year Yield") issuances.
        'SAFE' => [
            ModelParam::TreatyReinsuranceWeight->value   => 0.60,
            ModelParam::CatBondSpreadWeight->value       => 0.40,
            ModelParam::CatastropheZThreshold->value     => -1.55,
            ModelParam::CatastropheLossScalar->value     => 0.20,
            ModelParam::FloatEquityWeight->value         => 0.05,
        ],

        // --- White Dove Insurance (DOVE) ---
        // Retail multi-line P&C and Life insurer spun out of Safe Harbor. Sheds tail risks to reinsurers.
        'DOVE' => [
            ModelParam::PropertyCasualtyWeight->value    => 0.55,
            ModelParam::LifeAndAnnuityWeight->value      => 0.45,
            ModelParam::CatastropheZThreshold->value     => -1.80,
            ModelParam::CatastropheLossScalar->value     => 0.08,
            ModelParam::FloatEquityWeight->value         => 0.10,
        ],

        // =====================================================================
        // HEDGE FUND & QUANTITATIVE ARBITRAGE ARCHETYPES
        // =====================================================================

        // --- Black Swan Capital (SWAN) ---
        // Apex predator quantitative hedge fund and tactical alternative asset manager.
        // Tri-stream engine combining sticky AUM fees, leveraged directional bets, and black-box quant alpha.
        'SWAN' => [
            ModelParam::HfManagementFeeWeight->value   => 0.60,
            ModelParam::HfDirectionalBetsWeight->value => 0.20,
            ModelParam::HfQuantAlphaWeight->value      => 0.20,
        ],

        // =====================================================================
        // PRIVATE EQUITY & DISTRESSED DEBT ARCHETYPES
        // =====================================================================

        // --- Vulture Capital Recovery (VULT) ---
        // Specialist distressed debt restructuring and turnaround equity sponsor.
        // Counter-cyclical predator profiting from loan-to-own liquidations and restructuring advisory fees.
        'VULT' => [
            ModelParam::RestructuringAdvisoryWeight->value => 0.35,
            ModelParam::TurnaroundGainsWeight->value       => 0.65,
            ModelParam::AumMarketBetaScalar->value         => 0.30,
        ],

        // =====================================================================
        // CLEARINGHOUSE & MARKET INFRASTRUCTURE ARCHETYPES
        // =====================================================================

        // --- Aerie Central Clearing (ACC) ---
        // Systemically important central counterparty clearinghouse (CCP). 
        'ACC' => [
            ModelParam::ClearingFeeWeight->value      => 0.50,
            ModelParam::CustodyFloatWeight->value     => 0.30,
            ModelParam::DataSubscriptionWeight->value => 0.20,
        ],

        // =====================================================================
        // ASSET MANAGEMENT ARCHETYPES
        // =====================================================================

        // --- Owl Capital Partners (OWLS) ---
        // Permanent-capital value holding company. Earns the operating cash flow of wholly-owned
        // subsidiaries, not a management fee on third-party assets, so it runs the conglomerate physics
        // alongside BRKW and TRIV rather than the asset-manager fee model it used to be priced on.
        'OWLS' => [
            ModelParam::IndustrialConglomerateWeight->value => 0.45, // Heavy rail and industrial manufacturing
            ModelParam::DefensiveStaplesWeight->value       => 0.35, // Utility infrastructure and consumer goods
            ModelParam::ContrarianFloatWeight->value        => 0.20, // Cash and short-term sovereign paper awaiting a panic
            ModelParam::PricingPowerIndex->value            => 0.80, // Buys structural moats by mandate, never price takers
        ],

        // --- Crowfall Capital (CROW) ---
        // Activist forensic short-selling syndicate hunting bloated/overleveraged targets.
        'CROW' => [
            ModelParam::BaseFeeWeight->value         => 0.45,
            ModelParam::PerformanceFeeWeight->value  => 0.55,
            ModelParam::AumMarketBetaScalar->value  => 0.60,
            ModelParam::PerformanceFeeZFloor->value => 1.00,
            ModelParam::PerformanceFeeScalar->value  => 0.18,
        ],

        // =====================================================================
        // REAL ESTATE INVESTMENT TRUST (REIT) ARCHETYPES
        // =====================================================================

        // --- Lakeshore Living (SHOR) ---
        // Residential multi-family apartment REIT with ultra-stable annual leases and securitization packaging.
        'SHOR' => [
            ModelParam::StickyLeaseWeight->value          => 0.75,
            ModelParam::VariableHospitalityWeight->value  => 0.10,
            ModelParam::SecuritizationIncomeWeight->value => 0.15,
        ],

        // --- Plaza Civic River Trust (PLZA) ---
        // Commercial & Class-A office REIT with mix of corporate leases and amenity parking/retail.
        'PLZA' => [
            ModelParam::StickyLeaseWeight->value         => 0.80,
            ModelParam::VariableHospitalityWeight->value => 0.20,
        ],

        // --- Elderbird Retirement Services (ELDE) ---
        // Healthcare & assisted living property REIT with long-duration institutional leases and Longevity Yield Bonds.
        'ELDE' => [
            ModelParam::StickyLeaseWeight->value         => 0.80,
            ModelParam::VariableHospitalityWeight->value => 0.05,
            ModelParam::LongevityBondYieldWeight->value  => 0.15,
        ],

        // =====================================================================
        // TECHNOLOGY, DIGITAL PLATFORMS & HARDWARE ARCHETYPES
        // =====================================================================

        // --- Hummingbird Interactive (HUMM) ---
        // Consumer mobile OS & advertising giant. Heavily ad-supported platform usage (45%) & cloud (40%).
        'HUMM' => [
            ModelParam::SubscriptionRevenueWeight->value => 0.15,
            ModelParam::AdvertisingRevenueWeight->value  => 0.45,
            ModelParam::CloudInfrastructureWeight->value => 0.40,
            ModelParam::AdvertisingCyclicality->value    => 0.22,
            ModelParam::MonopolyAggression->value        => 0.90, // Ruthless data monopoly, high margins, existential regulatory risk
            ModelParam::PricingPowerIndex->value         => 0.60, // Auction-cleared ad inventory prices itself; only the subscription and cloud books are set by the seller
        ],

        // --- Silicon Creek Foundries (SILC) ---
        // Dedicated pure-play advanced silicon wafer foundry. Pure manufacturing capacity focus (85%).
        'SILC' => [
            ModelParam::FoundryRevenueWeight->value => 0.85,
            ModelParam::DesignRevenueWeight->value  => 0.15,
        ],

        // --- Penguin Computing (PENG) ---
        // High performance supercomputers and liquid cooling. High tech margins. Heavily weighted to enterprise.
        'PENG' => [
            ModelParam::PricingPowerIndex->value => 0.65,
            ModelParam::EnterpriseWeight->value  => 0.85,
            ModelParam::ConsumerWeight->value    => 0.15,
        ],

        // --- Erne Network Systems (ERNE) ---
        // Cellular radio access equipment, massive MIMO base stations & standard-essential patent licensing (Ericsson archetype).
        // Heavily weighted to long-term carrier infrastructure contracts with high SEP royalty tollbooths.
        'ERNE' => [
            ModelParam::EnterpriseWeight->value       => 0.75, // Carrier radio access, core network routing & optimization software
            ModelParam::PatentLicensingWeight->value  => 0.15, // Standard-essential patent royalties on every device shipped under the standard
            ModelParam::ConsumerWeight->value         => 0.10, // Broadband consumer terminal hardware & IoT micro-modules
            ModelParam::PricingPowerIndex->value      => 0.75, // Standard-essential patent (SEP) monopoly leverage
        ],

        // --- Weaver Marketplace (WEAV) ---
        // Massive third-party ecosystem (the profit engine) blended with volatile first-party retail (the scale engine).
        'WEAV' => [
            ModelParam::ThirdPartyWeight->value         => 0.45,
            ModelParam::FirstPartyWeight->value         => 0.30,
            ModelParam::DigitalAdsWeight->value         => 0.25,
        ],

        // =====================================================================
        // FINANCIAL DATA & ANALYTICS ARCHETYPES
        // =====================================================================

        // --- Shrike Standard Ratings (SHRK) ---
        // Credit rating agency & risk benchmarks. Skewed toward transaction-linked bond & debt rating mandates (40%).
        'SHRK' => [
            ModelParam::SubscriptionRevenueWeight->value => 0.60,
            ModelParam::TransactionRevenueWeight->value  => 0.40,
        ],

        // --- Tickbird Data Systems (TICK) ---
        // Terminal, financial analytics & data feed monopoly. Overwhelmingly recurring subscription seat contracts (90%).
        'TICK' => [
            ModelParam::SubscriptionRevenueWeight->value => 0.90,
            ModelParam::TransactionRevenueWeight->value  => 0.10,
            // Two different sensitivities, deliberately split. The EARNINGS are near-immune to the cycle:
            // a trading desk cancels its terminals last, whether the market is crashing or soaring. The
            // PRICE is not, because a 26x multiple de-rates hard in any technology selloff. Equity beta
            // stays at 1.45 to carry the second; this dial carries the first.
            ModelParam::OperatingCyclicality->value      => 0.35,
        ],

        // =====================================================================
        // COMMODITY MINING & EXTRACTION ARCHETYPES
        // =====================================================================

        // --- Sinking Shore Extraction (SINK) ---
        // --- Sinking Shore Extraction (SINK) ---
        // Pure-play upstream offshore deepwater oil & gas E&P. Violently leveraged to spot commodity prices & global energy cycles.
        'SINK' => [
            ModelParam::ExtractionRevenueWeight->value => 0.50, // Deepwater offshore drilling volume
            ModelParam::SpotPriceWeight->value         => 0.50, // Heavy unhedged spot oil & gas price exposure
            ModelParam::RefiningSpreadWeight->value    => 0.00,
            ModelParam::SpotPriceSensitivity->value    => 0.85, // Aggressive unhedged price-taker
            ModelParam::EnergyPriceExposure->value     => 1.00, // Pure crude & gas price exposure
            ModelParam::IndustrialMetalsExposure->value => 0.00,
            ModelParam::AgriculturalExposure->value    => 0.00,
        ],

        // --- Cascade Refining & Marketing (CASC) ---
        // Downstream high-conversion oil refinery & logistics network. Quant crack-spread arbitrage powerhouse.
        'CASC' => [
            ModelParam::ExtractionRevenueWeight->value => 0.25, // Physical refining throughput & logistics terminals
            ModelParam::SpotPriceWeight->value         => 0.15, // Strategic physical crude storage inventory
            ModelParam::RefiningSpreadWeight->value    => 0.60, // Algorithmic crack spread arbitrage (gasoline/diesel/jet fuel)
            ModelParam::SpotPriceSensitivity->value    => 0.30, // Heavily hedged physical inventory
            ModelParam::EnergyPriceExposure->value     => 1.00, // Crude storage inventory marked to the energy complex
            ModelParam::IndustrialMetalsExposure->value => 0.00,
            ModelParam::AgriculturalExposure->value    => 0.00,
        ],

        // --- Condor Extraction (CNDR) ---
        // Global base metals & rare earth open-pit strip mining titan. Ruthless physical anchor of the district.
        'CNDR' => [
            ModelParam::ExtractionRevenueWeight->value => 0.60, // Massive mechanized extraction volume
            ModelParam::SpotPriceWeight->value         => 0.40, // Base metal / rare earth spot price super-cycle exposure
            ModelParam::RefiningSpreadWeight->value    => 0.00,
            ModelParam::SpotPriceSensitivity->value    => 0.70, // Semi-hedged sovereign concessions
            ModelParam::EnergyPriceExposure->value     => 0.00,
            ModelParam::IndustrialMetalsExposure->value => 1.00, // Base metals & rare earths priced off the metals complex
            ModelParam::AgriculturalExposure->value    => 0.00,
        ],

        // =====================================================================
        // HEAVY INDUSTRY & MANUFACTURING ARCHETYPES
        // =====================================================================

        // --- Steel Wings Smelting & Corp (WING) ---
        // Industrial steel smelting & metallurgical production. Heavy contracted OEM supply (70%) with spot HRC spread (30%).
        'WING' => [
            ModelParam::ContractOemWeight->value  => 0.70,
            ModelParam::SpotHrcWeight->value      => 0.30,
            ModelParam::PricingPowerIndex->value  => 0.65,
        ],

        // --- Iron Beak Heavy Industries (IBHI) ---
        // Massive physical architect. Captive builder for district civic megaprojects, commercial dry docks, and port infrastructure.
        'IBHI' => [
            ModelParam::CivilInfrastructureWeight->value   => 0.60, // Captive builder for LAKE/SWAN civic projects
            ModelParam::CommercialEpcWeight->value         => 0.25, // Corporate commercial towers & fabrication yards
            ModelParam::FacilitiesMaintenanceWeight->value => 0.15, // Municipal maintenance & dry dock upkeep
            ModelParam::PricingPowerIndex->value           => 0.35, // High fixed-price contract exposure
        ],

        // --- River Stream Industries (RIVE) ---
        // Robotics and automation manufacturer. Long-term service contracts and essential margin-expanding tools.
        'RIVE' => [
            ModelParam::PricingPowerIndex->value => 0.85,
            ModelParam::EquipmentWeight->value   => 0.40,
            ModelParam::ServicesWeight->value    => 0.60,
        ],

        // --- Sanderling Rock Dynamics (SNDR) ---
        // Subterranean rock drill rigs, continuous hard-rock tunneling borers & cemented carbide tooling (Sandvik archetype).
        // Dual-stream razor-and-blade model: multi-quarter equipment backlogs paired with high-margin consumable wear-parts tollbooth.
        'SNDR' => [
            ModelParam::EquipmentWeight->value     => 0.55, // Heavy automated drilling rigs, subterranean continuous miners & crushing units
            ModelParam::ServicesWeight->value      => 0.45, // Cemented carbide rotary bits, wear-resistant liners & telemetry maintenance
            ModelParam::PricingPowerIndex->value   => 0.80, // Proprietary sintered carbide metallurgy; essential uptime tollbooth
            ModelParam::OperatingCyclicality->value => 1.15, // Buffered by consumable wear-part replacement cycles
        ],

        // --- Crossbill Precision Tooling (CBIL) ---
        // Operates as an industrial tollbooth with incredibly high margins and ROIC due to absolute quality control.
        // Extremely insulated from typical manufacturing boom/bust.
        'CBIL' => [
            ModelParam::ConsumerWeight->value   => 0.10, // Retail secondary market liquidations (volatile)
            ModelParam::CommercialWeight->value => 0.90, // Unbreakable industrial fortress / premium tooling (sticky)
            // A shattered drill bit halts an assembly line, so the tooling spend survives the downturn that
            // cancels the line's expansion. Volumes track maintenance, not the capital cycle the sector
            // default (1.00) assumes.
            ModelParam::OperatingCyclicality->value => 0.55,
        ],

        // --- Three Rivers Manufacturing (TRIV) ---
        // Unsinkable, diversified industrial conglomerate with ubiquitous products.
        'TRIV' => [
            ModelParam::IndustrialConglomerateWeight->value => 0.60, // Ubiquitous multi-industrial manufacturing
            ModelParam::DefensiveStaplesWeight->value       => 0.30, // Household & adhesive consumer staples
            ModelParam::ContrarianFloatWeight->value        => 0.10, // Operating cash float
            ModelParam::PricingPowerIndex->value            => 0.60,
        ],

        // --- Breakwater Trust (BRKW) ---
        // Deeply entrenched multi-generational industrial conglomerate and value anchor.
        'BRKW' => [
            ModelParam::IndustrialConglomerateWeight->value => 0.30, // Entrenched industrial subsidiaries
            ModelParam::DefensiveStaplesWeight->value       => 0.45, // Prime district real estate & infrastructure tollbooths
            ModelParam::ContrarianFloatWeight->value        => 0.25, // High-yield catastrophe bond shadow liquidity
            ModelParam::PricingPowerIndex->value            => 0.90,
        ],

        // =====================================================================
        // DEFENSE & SECURITY ARCHETYPES
        // =====================================================================

        // --- Gryphon Defense Systems (GRIP) ---
        // Tier-1 sovereign aerospace & defense contractor. Heavily cost-plus domestic defense mandates.
        'GRIP' => [
            ModelParam::CostPlusWeight->value             => 0.60,
            ModelParam::FixedPriceDevWeight->value        => 0.20,
            ModelParam::ForeignMilitarySalesWeight->value => 0.20,
        ],

        // --- Bird Watch Security (WATCH) ---
        // Premier domestic physical asset protection & municipal security retainers.
        'WATCH' => [
            ModelParam::GovernmentContractWeight->value => 0.40,
            ModelParam::RetainerWeight->value            => 0.50,
            ModelParam::ExpeditionaryWeight->value       => 0.10,
        ],

        // --- Osprey Global Vanguard (OSPR) ---
        // Extraterritorial private military contractor & black-ops extraction.
        'OSPR' => [
            ModelParam::GovernmentContractWeight->value => 0.10,
            ModelParam::RetainerWeight->value            => 0.10,
            ModelParam::ExpeditionaryWeight->value       => 0.80,
        ],

        // =====================================================================
        // MARINE SHIPPING, FREIGHT & RAILROAD ARCHETYPES
        // =====================================================================

        // --- Albatross Deepwaters (ALBT) ---
        // Marine shipping freight operator. Heavily exposed to short-term spot ocean freight rates.
        'ALBT' => [
            ModelParam::SpotCharterWeight->value     => 0.75,
            ModelParam::ContractCharterWeight->value => 0.25,
        ],

        // --- Canvasback Logistics (CANV) ---
        // Integrated freight & last-mile delivery provider. Skewed toward dedicated enterprise contracts & 3PL.
        'CANV' => [
            ModelParam::DedicatedFleetWeight->value => 0.55,
            ModelParam::SpotBrokerageWeight->value  => 0.25,
            ModelParam::Warehousing3plWeight->value => 0.20,
            ModelParam::PricingPowerIndex->value    => 0.70,
        ],

        // --- Kestrel Civic Lines (KSTL) ---
        // Class 1 freight & municipal rail transit operator. Preemptive track monopoly with intermodal & industrial carload focus.
        // Half the network is the commuter monopoly its description is actually about: punitive single
        // fares herding millions onto auto-renewing 'Kestrel Link' subscriptions. The other half is the
        // freight that shares the same preemptively laid track. It was tuned 100% freight, so none of the
        // inescapable baseline tax reached the physics.
        'KSTL' => [
            ModelParam::SubscriptionWeight->value       => 0.50, // Kestrel Link commuter subscriptions
            ModelParam::IntermodalFreightWeight->value  => 0.25,
            ModelParam::IndustrialCarloadsWeight->value => 0.15,
            ModelParam::BulkCommoditiesWeight->value    => 0.10,
            ModelParam::PricingPowerIndex->value        => 0.80,
        ],

        // =====================================================================
        // BIOTECHNOLOGY & HEALTHCARE ARCHETYPES
        // =====================================================================

        // --- Ibis Pharmaceuticals (IBIS) ---
        // Global biopharma giant (big pharma archetype). Skewed toward the established commercial
        // blockbuster portfolio (85%), with a biologic-heavy book facing a scheduled patent cliff on
        // roughly a third of marketed revenue.
        'IBIS' => [
            ModelParam::EstablishedDrugWeight->value       => 0.85,
            ModelParam::PipelineDrugWeight->value          => 0.15,
            ModelParam::PatentProtectedRevenueShare->value => 0.88, // Marketed book still under exclusivity
            ModelParam::LoeExposureShare->value            => 0.35, // Share of revenue exposed to the next cliff
            ModelParam::ExclusivityQuarters->value         => 26.0, // ~6.5 years until lead franchise LOE
            ModelParam::BiologicRevenueShare->value        => 0.55, // Biologics erode slowly under biosimilars
            ModelParam::PatentedMarginCeiling->value       => 0.50,
        ],

        // --- Crane Medical Network (CRAN) ---
        // Ubiquitous healthcare provider and hospital network. Masters of algorithmic billing, insurance arbitrage, and inelastic acute care.
        'CRAN' => [
            ModelParam::InpatientCareWeight->value        => 0.50, // Inelastic trauma and acute inpatient admissions
            ModelParam::ElectiveOutpatientWeight->value   => 0.30, // High-margin elective surgical & ambulatory procedures
            ModelParam::InsuranceArbitrageWeight->value   => 0.20, // Algorithmic DRG coding optimization & insurer arbitration
            ModelParam::PricingPowerIndex->value          => 0.80, // Regional hospital network monopoly leverage
        ],

        // =====================================================================
        // CONSUMER STAPLES, LUXURY & RESTAURANT ARCHETYPES
        // =====================================================================

        // --- Peacock Heritage Group (PEAC) ---
        // Elite ultra-luxury French house archetype. Heavily Haute Couture & Maison leather goods (70%).
        'PEAC' => [
            ModelParam::HauteCoutureWeight->value     => 0.70,
            ModelParam::AccessibleLuxuryWeight->value => 0.30,
        ],

        // --- Pheasant & Morris International (PHIL) ---
        // Global tobacco and nicotine conglomerate. Overwhelmingly branded packaged staples (85%).
        'PHIL' => [
            ModelParam::BrandedStaplesWeight->value  => 0.85,
            ModelParam::VolumeCommodityWeight->value => 0.15,
            ModelParam::PricingPowerIndex->value     => 0.90, // Addictive, habit-formed demand: decades of above-inflation list price increases with minimal volume response
        ],

        // --- Lark & Crest Brands (LARK) ---
        // Household goods & personal hygiene giant (P&G / Kimberly-Clark archetype).
        // Essential consumer staple with overwhelming brand dominance & pricing power (80% branded staples).
        'LARK' => [
            ModelParam::BrandedStaplesWeight->value  => 0.80,
            ModelParam::VolumeCommodityWeight->value => 0.20,
        ],

        // --- Sugarbird Confectionery (SGRB) ---
        // Confectionery giant & commodities cartel. Weaponizes raw cocoa/sugar physical storage to orchestrate short squeezes against hedge funds.
        'SGRB' => [
            ModelParam::BrandedStaplesWeight->value   => 0.55,
            ModelParam::VolumeCommodityWeight->value  => 0.10,
            ModelParam::CommodityTradingWeight->value => 0.35, // Raw cocoa/sugar physical storage short squeezes
            ModelParam::PricingPowerIndex->value      => 0.90,
        ],

        // --- Poultry Crop Operations (CROP) ---
        // Integrated poultry & agricultural producer. Skewed toward commodity volume agriculture (50%) and land speculation (15%).
        'CROP' => [
            ModelParam::BrandedStaplesWeight->value   => 0.20,
            ModelParam::VolumeCommodityWeight->value  => 0.50,
            ModelParam::CommodityTradingWeight->value => 0.15,
            ModelParam::LandSpeculationWeight->value  => 0.15,
            ModelParam::PricingPowerIndex->value      => 0.30, // Bulk agricultural output clears at the exchange price, not a list price
        ],

        // --- Pintail Beverage Group (PINT) ---
        // Industrial ethanol syndicate & heritage alcohol cartel. Deploys the 'Proof Desk' to corner agricultural futures and packaging supply.
        'PINT' => [
            ModelParam::BrandedStaplesWeight->value   => 0.60,
            ModelParam::VolumeCommodityWeight->value  => 0.20,
            ModelParam::CommodityTradingWeight->value => 0.20, // Internal 'Proof Desk' agricultural futures & silica hoarding
            ModelParam::PricingPowerIndex->value      => 0.85,
        ],

        // --- Copperhead Coffee Roasters (BREW) ---
        // Retail coffee cafes & prime real estate holdings. High company-owned store footprint (70%) and prime leases (20%).
        'BREW' => [
            ModelParam::CompanyStoresWeight->value      => 0.70,
            ModelParam::FranchiseRoyaltiesWeight->value => 0.10,
            ModelParam::FranchiseLeaseWeight->value     => 0.20,
            ModelParam::PricingPowerIndex->value        => 0.85,
        ],

        // --- Golden Swift Holdings (SWFT) ---
        // Massive global fast-food franchise network. Master-franchise model with royalties (55%) and property leases (40%).
        'SWFT' => [
            ModelParam::CompanyStoresWeight->value      => 0.05,
            ModelParam::FranchiseRoyaltiesWeight->value => 0.55,
            ModelParam::FranchiseLeaseWeight->value     => 0.40,
            ModelParam::PricingPowerIndex->value        => 0.60,
        ],

        // --- Silver Gull Resorts (GULL) ---
        // VIP casino and resorts catering to oligarchs. Very high pricing power against inflation.
        // VIP casino acting as bait for an apex commercial real estate and landlord empire.
        // Massive skew towards non-gaming (extortionate revenue-sharing leases) as the primary engine.
        'GULL' => [
            ModelParam::PricingPowerIndex->value           => 1.00, // Absolute monopoly pricing power over captive tenants
            ModelParam::GamingRevenueWeight->value         => 0.20, // Casino floors are just the bait for foot traffic
            ModelParam::NonGamingRevenueWeight->value     => 0.10,
            ModelParam::CommercialRealEstateWeight->value => 0.70, // The real engine: extortionate commercial real estate leases
        ],

        // --- Shearwater Mills (SHER) ---
        // Vertically integrated technical textile manufacturer. Premium bamboo/merino at commodity cost.
        // Strong DTC brand presence, massive wholesale distribution, and contract fiber supply.
        'SHER' => [
            ModelParam::DtcRetailWeight->value              => 0.30,
            ModelParam::WholesaleChannelWeight->value       => 0.40,
            ModelParam::ContractTextileSupplyWeight->value  => 0.30,
            ModelParam::PricingPowerIndex->value            => 0.60, // Moderate — cost leader, not luxury
        ],

        // =====================================================================
        // EDUCATION & PROFESSIONAL SERVICES ARCHETYPES
        // =====================================================================

        // --- Starling Academic Systems (STAR) ---
        // Elite corporate-subsidized education and talent placement engine with proprietary talent scoring algorithms.
        'STAR' => [
            ModelParam::DegreeTuitionWeight->value     => 0.40,
            ModelParam::EnterpriseTrainingWeight->value => 0.40,
            ModelParam::LmsLicensingWeight->value       => 0.20,
            ModelParam::PricingPowerIndex->value        => 0.70,
        ],

        // --- Lyrebird Media (LYRE) ---
        // Premier advertising and perception management conglomerate. Floods airwaves with corporate crisis retainers & martech data ops.
        'LYRE' => [
            ModelParam::BrandRetainerWeight->value     => 0.50,
            ModelParam::MartechConsultingWeight->value => 0.30,
            ModelParam::MediaBuyingWeight->value       => 0.20,
            ModelParam::PricingPowerIndex->value        => 0.75,
        ],

        // =====================================================================
        // LEGAL SERVICES ARCHETYPES
        // =====================================================================

        // --- Clear Rivers Law / Claw & Talons Law (CLAW) ---
        // Elite white-shoe litigation predator, corporate governance retainers & bankruptcy restructuring counsel.
        'CLAW' => [
            ModelParam::CorporateRetainerWeight->value     => 0.40, // Elite M&A and corporate governance retainers
            ModelParam::LitigationContingencyWeight->value  => 0.35, // High-stakes corporate warfare & predatory settlements
            ModelParam::RestructuringAdvisoryWeight->value  => 0.25, // Counter-cyclical corporate restructuring & workout fees
            ModelParam::PricingPowerIndex->value            => 0.90,
        ],

        // =====================================================================
        // AUTO MANUFACTURER ARCHETYPES
        // =====================================================================

        // --- Falconet Motor Group (FALC) ---
        // Vertically integrated tech-auto leviathan. Predatory base models cross-subsidized by 
        // ultra-luxury 'Apex Division' and inescapable software/telemetry tollbooths.
        // Extreme pricing power, but terrifyingly sensitive to macroeconomic liquidity crises.
        'FALC' => [
            ModelParam::AutoSalesWeight->value        => 0.55, // Predatory mass-market commuter fleet Trojan horse
            ModelParam::ApexLuxuryWeight->value       => 0.25, // Hyper-exclusive Veblen hypercars (Apex Division cross-subsidy)
            ModelParam::SoftwareServicesWeight->value => 0.20, // Inescapable telemetry, subscription tolls & captive finance
            ModelParam::PricingPowerIndex->value      => 0.65,
            ModelParam::RateSensitivityScalar->value  => 1.25,
        ],

        // --- Eider Motor Group (EIDR) ---
        // Fortified passenger vehicles, heavy commercial prime movers & industrial transport chassis (Volvo archetype).
        // Conservative fleet sales and executive armored wagons supported by mandatory insurance fleet standards.
        'EIDR' => [
            ModelParam::AutoSalesWeight->value        => 0.70, // Fortified commercial haulers & municipal fleet sales
            ModelParam::ApexLuxuryWeight->value       => 0.15, // Armored executive wagons & discrete VIP transports
            ModelParam::SoftwareServicesWeight->value => 0.15, // Certified telematics, collision avoidance & maintenance tolls
            ModelParam::PricingPowerIndex->value      => 0.75, // Backed by insurance underwriting standards
            ModelParam::RateSensitivityScalar->value  => 0.85, // Institutional fleet renewals less rate-sensitive than retail auto loans
        ],

        // =====================================================================
        // UTILITY & MUNICIPAL INFRASTRUCTURE ARCHETYPES
        // =====================================================================

        // --- Bird Power Inc (BIRD) ---
        // Integrated electric utility. Heavily regulated rate base transmission & distribution (80%), merchant renewables (20%).
        'BIRD' => [
            ModelParam::RegulatedBaseWeight->value       => 0.80,
            ModelParam::UnregulatedMerchantWeight->value => 0.20,
        ],

        // --- Heron Regional Water (WADE) ---
        // Regulated municipal water & wastewater utility. Pure regulated rate base monopoly (95%).
        'WADE' => [
            ModelParam::RegulatedBaseWeight->value       => 0.95,
            ModelParam::UnregulatedMerchantWeight->value => 0.05,
        ],

        // --- Loon Call Telecom (LOON) ---
        // Regional telecom & fiber carrier. Skewed toward recurring wireless/broadband subscriptions (80%) with equipment sales (20%).
        'LOON' => [
            ModelParam::SubscriptionWeight->value => 0.80,
            ModelParam::EquipmentWeight->value    => 0.20,
        ],

        // --- Cormorant Environmental (CORM) ---
        // Municipal waste management & environmental services operator.
        'CORM' => [
            ModelParam::ResidentialWeight->value => 0.60,
            ModelParam::CommercialWeight->value  => 0.30,
            ModelParam::RecyclingWeight->value   => 0.10,
        ],

        // =====================================================================
        // CHEMICAL & MATERIALS ARCHETYPES
        // =====================================================================

        // --- Fulmar Chemical Group (FULM) ---
        // Integrated chemical conglomerate spanning bulk base olefins/aromatics,
        // high-margin specialty electronic materials/catalysts, and fertilizer agrochemicals.
        'FULM' => [
            ModelParam::BasePetrochemicalsWeight->value => 0.50,
            ModelParam::SpecialtyChemicalsWeight->value => 0.30,
            ModelParam::AgrochemicalsWeight->value      => 0.20,
            ModelParam::PricingPowerIndex->value        => 0.55,
        ],
    ];

    /**
     * Retrieves a tuned parameter for a given stock ticker, or falls back to the baseline default.
     */
    public static function get(string $ticker, ModelParam|string $parameterKey, float $default): float
    {
        $key = $parameterKey instanceof ModelParam ? $parameterKey->value : (string) $parameterKey;
        return self::OVERRIDES[$ticker][$key] ?? $default;
    }

    /**
     * Checks if a stock ticker has any model tuning overrides.
     */
    public static function hasOverrides(string $ticker): bool
    {
        return isset(self::OVERRIDES[$ticker]);
    }

    /**
     * Returns all tuning overrides for a specific ticker.
     *
     * @return array<string, float>
     */
    public static function getOverridesForTicker(string $ticker): array
    {
        return self::OVERRIDES[$ticker] ?? [];
    }

    /**
     * Resolves a complete parameter DTO by merging baseline defaults with any company-specific tuning overrides.
     *
     * @param array<string, float> $defaults
     */
    public static function resolve(string $ticker, array $defaults): ModelParameters
    {
        $normalizedDefaults = [];
        foreach ($defaults as $key => $value) {
            $normalizedDefaults[(string) $key] = (float) $value;
        }

        if (!isset(self::OVERRIDES[$ticker])) {
            return new ModelParameters($normalizedDefaults);
        }

        $merged = array_merge($normalizedDefaults, self::OVERRIDES[$ticker]);
        return new ModelParameters($merged);
    }
}

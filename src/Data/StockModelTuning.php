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
            ModelParam::VixArbitrageScalar->value    => 1.80,
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
        // BANKING ARCHETYPES
        // =====================================================================



        // =====================================================================
        // INSURANCE & REINSURANCE ARCHETYPES
        // =====================================================================

        // --- Safe Harbor Reinsurance (SAFE) ---
        // Institutional reinsurance titan that absorbs extreme systemic and catastrophe tail risk.
        'SAFE' => [
            ModelParam::CatastropheZThreshold->value => -1.55,
            ModelParam::CatastropheLossScalar->value => 0.20,
            ModelParam::FloatEquityWeight->value     => 0.05,
            ModelParam::EquityPortfolioVol->value    => 0.08,
        ],

        // --- White Dove Insurance (DOVE) ---
        // Retail multi-line P&C and Life insurer spun out of Safe Harbor. Sheds tail risks to reinsurers.
        'DOVE' => [
            ModelParam::CatastropheZThreshold->value => -1.80,
            ModelParam::CatastropheLossScalar->value => 0.08,
            ModelParam::FloatEquityWeight->value     => 0.10,
            ModelParam::EquityPortfolioVol->value    => 0.10,
        ],

        // =====================================================================
        // PRIVATE EQUITY & DISTRESSED DEBT ARCHETYPES
        // =====================================================================

        // --- Black Swan Capital (SWAN) ---
        // Mega-cap alternative asset manager specializing in leveraged buyouts and carried interest.
        'SWAN' => [
            ModelParam::ManagementFeeWeight->value        => 0.60,
            ModelParam::CarriedInterestWeight->value      => 0.20,
            ModelParam::PrincipalInvestmentsWeight->value => 0.20,
        ],

        // --- Vulture Capital Recovery (VULT) ---
        // Specialist distressed debt restructuring and turnaround equity sponsor.
        'VULT' => [
            ModelParam::AdvisoryFeeWeight->value      => 0.40,
            ModelParam::AssetRecoveryWeight->value    => 0.30,
            ModelParam::LoanToOwnGainsWeight->value => 0.30,
        ],

        // =====================================================================
        // REAL ESTATE INVESTMENT TRUST (REIT) ARCHETYPES
        // =====================================================================

        // --- Lakeshore Living (SHOR) ---
        // Residential multi-family apartment REIT with ultra-stable annual leases.
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
        // Healthcare & assisted living property REIT with long-duration institutional leases.
        'ELDE' => [
            ModelParam::StickyLeaseWeight->value         => 0.80,
            ModelParam::VariableHospitalityWeight->value => 0.05,
            ModelParam::LongevityBondYieldWeight->value => 0.15,
        ],

        'STG' => [
            ModelParam::PncWeight->value          => 0.40,
            ModelParam::LifeAnnuityWeight->value => 0.60,
        ],

        // --- Aerie Central Clearing (ACC) ---
        // Systemically important central counterparty clearinghouse (CCP). 
        'ACC' => [
            ModelParam::ClearingFeeWeight->value      => 0.50,
            ModelParam::CustodyFloatWeight->value     => 0.15,
            ModelParam::DataSubscriptionWeight->value => 0.20,
            ModelParam::MarginInterestWeight->value   => 0.15,
        ],

        // =====================================================================
        // ASSET MANAGEMENT ARCHETYPES
        // =====================================================================

        // --- Owl Capital Partners (OWLS) ---
        // Disciplined value-investing conglomerate with sticky recurring management fees.
        'OWLS' => [
            ModelParam::BaseFeeWeight->value         => 0.85,
            ModelParam::PerformanceFeeWeight->value  => 0.15,
            ModelParam::AumMarketBetaScalar->value  => 0.20,
            ModelParam::PerformanceFeeZFloor->value => 1.60,
            ModelParam::PerformanceFeeScalar->value  => 0.06,
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
        // COMMERCIAL BANKING & CREDIT SERVICES ARCHETYPES
        // =====================================================================

        // --- Lakebird Bank (LAKE) ---
        // Universal banking behemoth.
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
        // TECHNOLOGY & DIGITAL PLATFORM ARCHETYPES
        // =====================================================================

        // --- Hummingbird Interactive (HUMM) ---
        // Consumer mobile OS & advertising giant. Heavily ad-supported platform usage (65%).
        'HUMM' => [
            ModelParam::SubscriptionRevenueWeight->value => 0.15,
            ModelParam::AdvertisingRevenueWeight->value  => 0.45,
            ModelParam::CloudInfrastructureWeight->value => 0.40,
            ModelParam::AdvertisingCyclicality->value     => 0.22,
            ModelParam::MonopolyAggression->value         => 0.90, // Ruthless data monopoly, high margins, existential regulatory risk
        ],

        // =====================================================================
        // COMMODITY MINING & EXTRACTION ARCHETYPES
        // =====================================================================

        // --- Sinking Shore Extraction (SINK) ---
        // Deep-sea minerals & rare metals extraction. Skewed toward production volume (70%).
        'SINK' => [
            ModelParam::ExtractionRevenueWeight->value => 0.70,
            ModelParam::SpotPriceWeight->value         => 0.30,
            ModelParam::SpotPriceSensitivity->value    => 0.30, // Highly hedged to secure deep-sea financing
        ],

        // --- Cascade Minerals & Energy (CASC) ---
        // Diversified global mining & metallurgical coal exporter. Balanced volume & spot exposure (50/50).
        'CASC' => [
            ModelParam::ExtractionRevenueWeight->value => 0.40,
            ModelParam::SpotPriceWeight->value         => 0.40,
            ModelParam::RefiningSpreadWeight->value    => 0.20,
            ModelParam::SpotPriceSensitivity->value    => 0.50, // Standard 50% hedged production book
        ],

        // --- Condor Rare Earths (CNDR) ---
        // Strategic lithium & rare earth refining operator. High spot commodity price sensitivity (60%).
        'CNDR' => [
            ModelParam::ExtractionRevenueWeight->value => 0.40,
            ModelParam::SpotPriceWeight->value         => 0.60,
            ModelParam::SpotPriceSensitivity->value    => 0.75, // Mostly unhedged wildcat, exposed to massive spot volatility
        ],

        // --- Steel Wings Smelting & Corp (WING) ---
        // Industrial steel smelting & metallurgical production. Heavy physical production volume focus (80%).
        'WING' => [
            ModelParam::ExtractionRevenueWeight->value => 0.80,
            ModelParam::SpotPriceWeight->value         => 0.20,
        ],

        // =====================================================================
        // SEMICONDUCTOR ARCHETYPES
        // =====================================================================

        // --- Silicon Creek Foundries (SILC) ---
        // Dedicated pure-play advanced silicon wafer foundry. Pure manufacturing capacity focus (85%).
        'SILC' => [
            ModelParam::FoundryRevenueWeight->value => 0.85,
            ModelParam::DesignRevenueWeight->value  => 0.15,
        ],

        // =====================================================================
        // DEFENSE & AEROSPACE ARCHETYPES
        // =====================================================================

        // --- Gryphon Defense Systems (GRIP) ---
        // Tier-1 sovereign aerospace & defense contractor. Heavily cost-plus domestic defense mandates.
        'GRIP' => [
            ModelParam::DomesticProcurementWeight->value   => 0.80,
            ModelParam::ForeignMilitarySalesWeight->value => 0.20,
        ],

        // =====================================================================
        // SECURITY & PROTECTION SERVICES ARCHETYPES
        // =====================================================================

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
        // MARINE SHIPPING & LOGISTICS ARCHETYPES
        // =====================================================================

        // --- Albatross Deepwaters (ALBT) ---
        // Marine shipping freight operator. Heavily exposed to short-term spot ocean freight rates.
        'ALBT' => [
            ModelParam::SpotCharterWeight->value     => 0.75,
            ModelParam::ContractCharterWeight->value => 0.25,
        ],

        // --- Canvasback Logistics (CANV) ---
        // Integrated freight & logistics provider. Skewed toward dedicated multi-year enterprise contracts.
        'CANV' => [
            ModelParam::SpotCharterWeight->value     => 0.40,
            ModelParam::ContractCharterWeight->value => 0.60,
        ],

        // --- Kestrel Civic Lines (KSTL) ---
        // Railroad & dedicated freight line operator. Overwhelmingly long-term contracted rail lines (75%).
        'KSTL' => [
            ModelParam::SpotCharterWeight->value     => 0.25,
            ModelParam::ContractCharterWeight->value => 0.75,
        ],

        // =====================================================================
        // BIOTECHNOLOGY & PHARMACEUTICAL ARCHETYPES
        // =====================================================================

        // --- Ibis Pharmaceuticals (IBIS) ---
        // Global biopharma giant. Skewed toward established commercial blockbuster portfolio (75%).
        'IBIS' => [
            ModelParam::EstablishedDrugWeight->value => 0.90,
            ModelParam::PipelineDrugWeight->value    => 0.10,
        ],

        // --- Crane Medical Network (CRAN) ---
        // Specialized clinical development and medical oncology network. Higher experimental R&D pipeline weighting (45%).
        'CRAN' => [
            ModelParam::EstablishedDrugWeight->value => 0.55,
            ModelParam::PipelineDrugWeight->value    => 0.45,
        ],

        // =====================================================================
        // LUXURY GOODS & FASHION ARCHETYPES
        // =====================================================================

        // --- Peacock Heritage Group (PEAC) ---
        // Elite ultra-luxury French house archetype. Heavily Haute Couture & Maison leather goods (70%).
        'PEAC' => [
            ModelParam::HauteCoutureWeight->value     => 0.70,
            ModelParam::AccessibleLuxuryWeight->value => 0.30,
        ],

        // =====================================================================
        // CONSUMER STAPLES & PACKAGED GOODS ARCHETYPES
        // =====================================================================

        // --- Pheasant & Morris International (PHIL) ---
        // Global tobacco and nicotine conglomerate. Overwhelmingly branded packaged staples (85%).
        'PHIL' => [
            ModelParam::BrandedStaplesWeight->value  => 0.85,
            ModelParam::VolumeCommodityWeight->value => 0.15,
        ],

        // --- Sugarbird Confectionery (SGRB) ---
        // Confectionery, snacks & packaged food leader. Skewed toward packaged branded staples (75%).
        'SGRB' => [
            ModelParam::BrandedStaplesWeight->value   => 0.65,
            ModelParam::VolumeCommodityWeight->value  => 0.15,
            ModelParam::CommodityTradingWeight->value => 0.10,
            ModelParam::LandSpeculationWeight->value  => 0.10,
        ],

        // --- Copperhead Coffee Roasters (BREW) ---
        // Retail coffee roasting & distribution. Heavy corporate footprint (90%) with minor franchise presence (10%).
        'BREW' => [
            ModelParam::CorporateWeight->value => 0.90,
            ModelParam::FranchiseWeight->value => 0.10,
        ],

        // --- Poultry Crop Operations (CROP) ---
        // Integrated poultry & agricultural producer. Skewed toward commodity volume agriculture (70%).
        'CROP' => [
            ModelParam::BrandedStaplesWeight->value   => 0.20,
            ModelParam::VolumeCommodityWeight->value  => 0.50,
            ModelParam::CommodityTradingWeight->value => 0.15,
            ModelParam::LandSpeculationWeight->value  => 0.15,
        ],

        // --- Lark & Crest Brands (LARK) ---
        // Household goods & personal hygiene giant (P&G / Kimberly-Clark archetype).
        // Essential consumer staple with overwhelming brand dominance & pricing power (80% branded staples).
        'LARK' => [
            ModelParam::BrandedStaplesWeight->value  => 0.80,
            ModelParam::VolumeCommodityWeight->value => 0.20,
        ],

        // --- Crossbill Precision Tooling (CBIL) ---
        // Operates as an industrial tollbooth with incredibly high margins and ROIC due to absolute quality control.
        // Extremely insulated from typical manufacturing boom/bust.
        'CBIL' => [
            ModelParam::ConsumerWeight->value   => 0.10, // Retail secondary market liquidations (volatile)
            ModelParam::CommercialWeight->value => 0.90, // Unbreakable industrial fortress / premium tooling (sticky)
        ],

        // --- Pintail Beverage Group (PINT) ---
        // Industrial ethanol syndicate & heritage alcohol cartel.
        // Balances branded artisanal spirits with aggressive bulk ethanol/commodity trading via the 'Proof Desk'.
        'PINT' => [
            ModelParam::BrandedStaplesWeight->value  => 0.60,
            ModelParam::VolumeCommodityWeight->value => 0.40,
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

        // =====================================================================
        // TELECOMMUNICATIONS ARCHETYPES
        // =====================================================================

        // --- Loon Call Telecom (LOON) ---
        // Regional telecom & fiber carrier. Skewed toward recurring wireless/broadband subscriptions (80%) with equipment sales (20%).
        'LOON' => [
            ModelParam::SubscriptionWeight->value => 0.80,
            ModelParam::EquipmentWeight->value    => 0.20,
        ],

        // =====================================================================
        // WASTE MANAGEMENT ARCHETYPES
        // =====================================================================

        // --- Cormorant Environmental (CORM) ---
        // Municipal waste management & environmental services operator.
        'CORM' => [
            ModelParam::ResidentialWeight->value => 0.60,
            ModelParam::CommercialWeight->value  => 0.30,
            ModelParam::RecyclingWeight->value   => 0.10,
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
        ],

        // =====================================================================
        // RESORTS & CASINOS ARCHETYPES
        // =====================================================================

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

        // =====================================================================
        // STANDARD CORPORATE ARCHETYPES
        // =====================================================================

        // --- River Stream Industries (RIVE) ---
        // Robotics and automation manufacturer. Long-term service contracts and essential margin-expanding tools.
        'RIVE' => [
            ModelParam::PricingPowerIndex->value => 0.85,
            ModelParam::EquipmentWeight->value    => 0.40,
            ModelParam::ServicesWeight->value     => 0.60,
        ],

        // --- Three Rivers Manufacturing (TRIV) ---
        // Unsinkable, diversified industrial conglomerate with ubiquitous products.
        'TRIV' => [
            ModelParam::PricingPowerIndex->value => 0.75,
        ],

        // --- Iron Beak Heavy Industries (IBHI) ---
        // Massive physical architect. Captive builder with highly cyclical revenue and poor pricing power against inflation.
        'IBHI' => [
            ModelParam::PricingPowerIndex->value => 0.30,
        ],


        // --- Golden Swift Holdings (SWFT) ---
        // Massive global fast-food franchise network. Almost entirely franchised (95%) for stable royalties.
        'SWFT' => [
            ModelParam::CorporateWeight->value => 0.05,
            ModelParam::FranchiseWeight->value => 0.95,
        ],

        'APE' => [
            ModelParam::ApparelWeight->value  => 0.45,
            ModelParam::FootwearWeight->value => 0.55,
        ],

        // --- Weaver Marketplace (WEAV) ---
        // Massive third-party ecosystem (the profit engine) blended with volatile first-party retail (the scale engine).
        'WEAV' => [
            ModelParam::ThirdPartyWeight->value         => 0.45,
            ModelParam::FirstPartyWeight->value         => 0.30,
            ModelParam::DigitalAdsWeight->value         => 0.25,
        ],

        // --- Penguin Computing (PENG) ---
        // High performance supercomputers and liquid cooling. High tech margins. Heavily weighted to enterprise.
        'PENG' => [
            ModelParam::PricingPowerIndex->value => 0.65,
            ModelParam::EnterpriseWeight->value   => 0.85,
            ModelParam::ConsumerWeight->value     => 0.15,
        ],

        // =====================================================================
        // AUTO MANUFACTURER ARCHETYPES
        // =====================================================================

        // --- Falconet Motor Group (FALC) ---
        // Vertically integrated tech-auto leviathan. Predatory base models cross-subsidized by 
        // ultra-luxury 'Apex Division' and inescapable software/telemetry tollbooths.
        // Extreme pricing power, but terrifyingly sensitive to macroeconomic liquidity crises.
        'FALC' => [
            ModelParam::AutoSalesWeight->value        => 0.60,
            ModelParam::AutoFinancingWeight->value    => 0.15,
            ModelParam::SoftwareServicesWeight->value => 0.25, // Represents telemetry and software lock-ins
            ModelParam::PricingPowerIndex->value      => 0.80, // Veblen good luxury pricing; immune to inflation but hyper-pro-cyclical
            ModelParam::RateSensitivityScalar->value  => 3.00, // Highly sensitive to liquidity panics pausing elite consumption
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
     * @param array<ModelParam|string, float> $defaults
     */
    public static function resolve(string $ticker, array $defaults): ModelParameters
    {
        $normalizedDefaults = [];
        foreach ($defaults as $key => $value) {
            $stringKey = $key instanceof ModelParam ? $key->value : (string) $key;
            $normalizedDefaults[$stringKey] = (float) $value;
        }

        if (!isset(self::OVERRIDES[$ticker])) {
            return new ModelParameters($normalizedDefaults);
        }

        $merged = array_merge($normalizedDefaults, self::OVERRIDES[$ticker]);
        return new ModelParameters($merged);
    }
}

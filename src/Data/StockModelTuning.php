<?php

declare(strict_types=1);

namespace App\Data;

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
            'advisory_revenue_weight' => 0.00,
            'trading_revenue_weight'  => 1.00,
            'vix_arbitrage_scalar'    => 1.80,
        ],

        // --- Kingfisher Capital (KING) ---
        // Pure-play M&A advisory syndicate and capital markets boutique (Lazard / Evercore archetype).
        // Hyper-sensitive to corporate deal pipelines and GDP booms.
        'KING' => [
            'advisory_revenue_weight' => 0.75,
            'trading_revenue_weight'  => 0.25,
            'vix_arbitrage_scalar'    => 1.20,
        ],

        // --- Rook Proprietary Trading (ROOK) ---
        // High-frequency proprietary trading & institutional execution desk. Pure volatility arbitrage focus.
        'ROOK' => [
            'advisory_revenue_weight' => 0.15,
            'trading_revenue_weight'  => 0.85,
            'vix_arbitrage_scalar'    => 1.80,
        ],

        // --- Corvid Strategic Arbitrage (CORV) ---
        // Ultra-exclusive boutique investment bank and weaponized information arbitrage apparatus.
        // Uncorrelated to traditional cycles; thrives on high-frequency arbitrage and institutional volatility.
        'CORV' => [
            'advisory_revenue_weight' => 0.25,
            'trading_revenue_weight'  => 0.75,
            'vix_arbitrage_scalar'    => 2.00,
        ],

        // =====================================================================
        // INSURANCE & REINSURANCE ARCHETYPES
        // =====================================================================

        // --- Safe Harbor Reinsurance (SAFE) ---
        // Institutional reinsurance titan that absorbs extreme systemic and catastrophe tail risk.
        'SAFE' => [
            'catastrophe_z_threshold' => -1.55,
            'catastrophe_loss_scalar' => 0.20,
            'float_equity_weight'     => 0.05,
            'equity_portfolio_vol'    => 0.08,
        ],

        // --- White Dove Insurance (DOVE) ---
        // Retail multi-line P&C and Life insurer spun out of Safe Harbor. Sheds tail risks to reinsurers.
        'DOVE' => [
            'catastrophe_z_threshold' => -1.80,
            'catastrophe_loss_scalar' => 0.08,
            'float_equity_weight'     => 0.10,
            'equity_portfolio_vol'    => 0.10,
        ],

        // =====================================================================
        // PRIVATE EQUITY & DISTRESSED DEBT ARCHETYPES
        // =====================================================================

        // --- Black Swan Capital (SWAN) ---
        // Mega-cap alternative asset manager specializing in leveraged buyouts and carried interest.
        'SWAN' => [
            'management_fee_weight'   => 0.80,
            'carried_interest_weight' => 0.20,
        ],

        // --- Vulture Capital Recovery (VULT) ---
        // Specialist distressed debt restructuring and turnaround equity sponsor.
        'VULT' => [
            'advisory_fee_weight'   => 0.40,
            'asset_recovery_weight' => 0.60,
        ],

        // =====================================================================
        // REAL ESTATE INVESTMENT TRUST (REIT) ARCHETYPES
        // =====================================================================

        // --- Lakeshore Living (SHOR) ---
        // Residential multi-family apartment REIT with ultra-stable annual leases.
        'SHOR' => [
            'sticky_lease_weight'         => 0.90,
            'variable_hospitality_weight' => 0.10,
        ],

        // --- Plaza Civic River Trust (PLZA) ---
        // Commercial & Class-A office REIT with mix of corporate leases and amenity parking/retail.
        'PLZA' => [
            'sticky_lease_weight'         => 0.80,
            'variable_hospitality_weight' => 0.20,
        ],

        // --- Elderbird Retirement Services (ELDE) ---
        // Healthcare & assisted living property REIT with long-duration institutional leases.
        'ELDE' => [
            'sticky_lease_weight'         => 0.95,
            'variable_hospitality_weight' => 0.05,
        ],

        // =====================================================================
        // ASSET MANAGEMENT ARCHETYPES
        // =====================================================================

        // --- Owl Capital Partners (OWLS) ---
        // Disciplined value-investing conglomerate with sticky recurring management fees.
        'OWLS' => [
            'base_fee_weight'         => 0.85,
            'performance_fee_weight'  => 0.15,
            'aum_market_beta_scalar'  => 0.20,
            'performance_fee_z_floor' => 1.60,
            'performance_fee_scalar'  => 0.06,
        ],

        // --- Crowfall Capital (CROW) ---
        // Activist forensic short-selling syndicate hunting bloated/overleveraged targets.
        'CROW' => [
            'base_fee_weight'         => 0.45,
            'performance_fee_weight'  => 0.55,
            'aum_market_beta_scalar'  => 0.60,
            'performance_fee_z_floor' => 1.00,
            'performance_fee_scalar'  => 0.18,
        ],

        // =====================================================================
        // COMMERCIAL BANKING & CREDIT SERVICES ARCHETYPES
        // =====================================================================

        // --- Lakebird Bank (LAKE) ---
        // Universal banking behemoth.
        'LAKE' => [
            'nii_revenue_weight'        => 0.65,
            'fee_revenue_weight'        => 0.35,
            'nim_inversion_sensitivity' => 8.0,
            'credit_risk_appetite'      => 0.40,
        ],

        // --- Riverstone Financial (RIVR) ---
        // Agile regional lender poaching district SMEs. Pure NII model (90%) hyper-sensitive to NIM inversion.
        'RIVR' => [
            'nii_revenue_weight'        => 0.90,
            'fee_revenue_weight'        => 0.10,
            'nim_inversion_sensitivity' => 12.0,
            'credit_risk_appetite'      => 0.60,
        ],

        // --- Talon Credit (TALN) ---
        // Prime credit card & digital merchant payment rail network. Substantial swipe interchange fee tollbooth (45%).
        'TALN' => [
            'lending_revenue_weight'  => 0.55,
            'network_revenue_weight'  => 0.45,
            'cecl_spread_sensitivity' => 1.40,
        ],

        // --- Stork Consumer Credit (STRK) ---
        // Subprime consumer finance & installment loan originator. Highly exposed to credit spread widening.
        'STRK' => [
            'lending_revenue_weight'  => 0.95,
            'network_revenue_weight'  => 0.05,
            'cecl_spread_sensitivity' => 2.20,
        ],

        // =====================================================================
        // TECHNOLOGY & DIGITAL PLATFORM ARCHETYPES
        // =====================================================================

        // --- Hummingbird Interactive (HUMM) ---
        // Consumer mobile OS & advertising giant. Heavily ad-supported platform usage (65%).
        'HUMM' => [
            'subscription_revenue_weight' => 0.35,
            'advertising_revenue_weight'  => 0.65,
            'advertising_cyclicality'     => 0.22,
            'monopoly_aggression'         => 0.90, // Ruthless data monopoly, high margins, existential regulatory risk
        ],

        // =====================================================================
        // COMMODITY MINING & EXTRACTION ARCHETYPES
        // =====================================================================

        // --- Sinking Shore Extraction (SINK) ---
        // Deep-sea minerals & rare metals extraction. Skewed toward production volume (70%).
        'SINK' => [
            'extraction_revenue_weight' => 0.70,
            'spot_price_weight'         => 0.30,
            'spot_price_sensitivity'    => 0.30, // Highly hedged to secure deep-sea financing
        ],

        // --- Cascade Minerals & Energy (CASC) ---
        // Diversified global mining & metallurgical coal exporter. Balanced volume & spot exposure (50/50).
        'CASC' => [
            'extraction_revenue_weight' => 0.50,
            'spot_price_weight'         => 0.50,
            'spot_price_sensitivity'    => 0.50, // Standard 50% hedged production book
        ],

        // --- Condor Rare Earths (CNDR) ---
        // Strategic lithium & rare earth refining operator. High spot commodity price sensitivity (60%).
        'CNDR' => [
            'extraction_revenue_weight' => 0.40,
            'spot_price_weight'         => 0.60,
            'spot_price_sensitivity'    => 0.75, // Mostly unhedged wildcat, exposed to massive spot volatility
        ],

        // --- Steel Wings Smelting & Corp (WING) ---
        // Industrial steel smelting & metallurgical production. Heavy physical production volume focus (80%).
        'WING' => [
            'extraction_revenue_weight' => 0.80,
            'spot_price_weight'         => 0.20,
        ],

        // =====================================================================
        // SEMICONDUCTOR ARCHETYPES
        // =====================================================================

        // --- Silicon Creek Foundries (SILC) ---
        // Dedicated pure-play advanced silicon wafer foundry. Pure manufacturing capacity focus (85%).
        'SILC' => [
            'foundry_manufacturing_weight' => 0.85,
            'fabless_design_weight'        => 0.15,
        ],

        // =====================================================================
        // DEFENSE & AEROSPACE ARCHETYPES
        // =====================================================================

        // --- Gryphon Defense Systems (GRIP) ---
        // Tier-1 sovereign aerospace & defense contractor. Heavily cost-plus government mandates (85%).
        'GRIP' => [
            'government_contract_weight' => 0.85,
            'commercial_services_weight' => 0.15,
        ],

        // --- Watchman Security Consulting (WATCH) ---
        // Balanced commercial security consulting & sovereign physical security contracts (50/50).
        'WATCH' => [
            'government_contract_weight' => 0.50,
            'commercial_services_weight' => 0.50,
        ],

        // --- Osprey Global Vanguard (OSPR) ---
        // Global security and protection systems provider. Skewed toward government security mandates (70%).
        'OSPR' => [
            'government_contract_weight' => 0.70,
            'commercial_services_weight' => 0.30,
        ],

        // =====================================================================
        // MARINE SHIPPING & LOGISTICS ARCHETYPES
        // =====================================================================

        // --- Albatross Deepwaters (ALBT) ---
        // Marine shipping freight operator. Heavily exposed to short-term spot ocean freight rates (75%).
        'ALBT' => [
            'spot_charter_weight'     => 0.75,
            'contract_charter_weight' => 0.25,
        ],

        // --- Canvasback Logistics (CANV) ---
        // Integrated freight & logistics provider. Skewed toward dedicated multi-year enterprise contracts (60%).
        'CANV' => [
            'spot_charter_weight'     => 0.40,
            'contract_charter_weight' => 0.60,
        ],

        // --- Kestrel Civic Lines (KSTL) ---
        // Railroad & dedicated freight line operator. Overwhelmingly long-term contracted rail lines (75%).
        'KSTL' => [
            'spot_charter_weight'     => 0.25,
            'contract_charter_weight' => 0.75,
        ],

        // =====================================================================
        // BIOTECHNOLOGY & PHARMACEUTICAL ARCHETYPES
        // =====================================================================

        // --- Ibis Pharmaceuticals (IBIS) ---
        // Global biopharma giant. Skewed toward established commercial blockbuster portfolio (75%).
        'IBIS' => [
            'established_drug_weight' => 0.90,
            'pipeline_drug_weight'    => 0.10,
        ],

        // --- Crane Medical Network (CRAN) ---
        // Specialized clinical development and medical oncology network. Higher experimental R&D pipeline weighting (45%).
        'CRAN' => [
            'established_drug_weight' => 0.55,
            'pipeline_drug_weight'    => 0.45,
        ],

        // =====================================================================
        // LUXURY GOODS & FASHION ARCHETYPES
        // =====================================================================

        // --- Peacock Heritage Group (PEAC) ---
        // Elite ultra-luxury French house archetype. Heavily Haute Couture & Maison leather goods (70%).
        'PEAC' => [
            'haute_couture_weight'     => 0.70,
            'accessible_luxury_weight' => 0.30,
        ],

        // =====================================================================
        // CONSUMER STAPLES & PACKAGED GOODS ARCHETYPES
        // =====================================================================

        // --- Pheasant & Morris International (PHIL) ---
        // Global tobacco and nicotine conglomerate. Overwhelmingly branded packaged staples (85%).
        'PHIL' => [
            'branded_staples_weight'  => 0.85,
            'volume_commodity_weight' => 0.15,
        ],

        // --- Sugarbird Confectionery (SGRB) ---
        // Confectionery, snacks & packaged food leader. Skewed toward packaged branded staples (75%).
        'SGRB' => [
            'branded_staples_weight'  => 0.75,
            'volume_commodity_weight' => 0.25,
        ],

        // --- Copperhead Coffee Roasters (BREW) ---
        // Retail coffee roasting & distribution. Heavy corporate footprint (90%) with minor franchise presence (10%).
        'BREW' => [
            'corporate_weight' => 0.90,
            'franchise_weight' => 0.10,
        ],

        // --- Poultry Crop Operations (CROP) ---
        // Integrated poultry & agricultural producer. Skewed toward commodity volume agriculture (70%).
        'CROP' => [
            'branded_staples_weight'  => 0.30,
            'volume_commodity_weight' => 0.70,
        ],

        // --- Lark & Crest Brands (LARK) ---
        // Household goods & personal hygiene giant (P&G / Kimberly-Clark archetype).
        // Essential consumer staple with overwhelming brand dominance & pricing power (80% branded staples).
        'LARK' => [
            'branded_staples_weight'  => 0.80,
            'volume_commodity_weight' => 0.20,
        ],

        // --- Crossbill Precision Tooling (CBIL) ---
        // Operates as an industrial tollbooth with incredibly high margins and ROIC due to absolute quality control.
        // Extremely insulated from typical manufacturing boom/bust.
        'CBIL' => [
            'consumer_weight'   => 0.10, // Retail secondary market liquidations (volatile)
            'commercial_weight' => 0.90, // Unbreakable industrial fortress / premium tooling (sticky)
        ],

        // --- Pintail Beverage Group (PINT) ---
        // Industrial ethanol syndicate & heritage alcohol cartel.
        // Balances branded artisanal spirits with aggressive bulk ethanol/commodity trading via the 'Proof Desk'.
        'PINT' => [
            'branded_staples_weight'  => 0.60,
            'volume_commodity_weight' => 0.40,
        ],

        // =====================================================================
        // UTILITY & MUNICIPAL INFRASTRUCTURE ARCHETYPES
        // =====================================================================

        // --- Bird Power Inc (BIRD) ---
        // Integrated electric utility. Heavily regulated rate base transmission & distribution (80%), merchant renewables (20%).
        'BIRD' => [
            'regulated_base_weight'       => 0.80,
            'unregulated_merchant_weight' => 0.20,
        ],

        // --- Heron Regional Water (WADE) ---
        // Regulated municipal water & wastewater utility. Pure regulated rate base monopoly (95%).
        'WADE' => [
            'regulated_base_weight'       => 0.95,
            'unregulated_merchant_weight' => 0.05,
        ],

        // --- Loon Call Telecom (LOON) ---
        // Regulated regional telecom & fiber carrier. Skewed toward regulated wireline infrastructure (75%).
        'LOON' => [
            'regulated_base_weight'       => 0.75,
            'unregulated_merchant_weight' => 0.25,
        ],

        // =====================================================================
        // FINANCIAL DATA & ANALYTICS ARCHETYPES
        // =====================================================================

        // --- Shrike Standard Ratings (SHRK) ---
        // Credit rating agency & risk benchmarks. Skewed toward transaction-linked bond & debt rating mandates (40%).
        'SHRK' => [
            'subscription_revenue_weight' => 0.60,
            'transaction_revenue_weight'  => 0.40,
        ],

        // --- Tickbird Data Systems (TICK) ---
        // Terminal, financial analytics & data feed monopoly. Overwhelmingly recurring subscription seat contracts (90%).
        'TICK' => [
            'subscription_revenue_weight' => 0.90,
            'transaction_revenue_weight'  => 0.10,
        ],

        // =====================================================================
        // STANDARD CORPORATE ARCHETYPES
        // =====================================================================

        // --- Silver Gull Resorts (GULL) ---
        // VIP casino and resorts catering to oligarchs. Very high pricing power against inflation.
        'GULL' => [
            'pricing_power_index' => 0.70,
        ],

        // --- River Stream Industries (RIVE) ---
        // Robotics and automation manufacturer. Long-term service contracts and essential margin-expanding tools.
        'RIVE' => [
            'pricing_power_index' => 0.85,
            'equipment_weight'    => 0.40,
            'services_weight'     => 0.60,
        ],

        // --- Three Rivers Manufacturing (TRIV) ---
        // Unsinkable, diversified industrial conglomerate with ubiquitous products.
        'TRIV' => [
            'pricing_power_index' => 0.75,
        ],

        // --- Iron Beak Heavy Industries (IBHI) ---
        // Massive physical architect. Captive builder with highly cyclical revenue and poor pricing power against inflation.
        'IBHI' => [
            'pricing_power_index' => 0.30,
        ],

        // --- Weaver Marketplace (WEAV) ---
        // Internet retail giant. Logistics-heavy with thin margins that get squeezed by inflation.
        'WEAV' => [
            'pricing_power_index' => 0.40,
        ],

        // --- Golden Swift Holdings (SWFT) ---
        // Massive global fast-food franchise network. Almost entirely franchised (95%) for stable royalties.
        'SWFT' => [
            'corporate_weight' => 0.05,
            'franchise_weight' => 0.95,
        ],

        'APE' => [
            'apparel_weight'  => 0.45,
            'footwear_weight' => 0.55,
        ],

        // --- Weaver Marketplace (WEAV) ---
        // Massive third-party ecosystem (the profit engine) blended with volatile first-party retail (the scale engine).
        'WEAV' => [
            'third_party_weight' => 0.60,
            'first_party_weight' => 0.40,
        ],

        // --- Penguin Computing (PENG) ---
        // High performance supercomputers and liquid cooling. High tech margins. Heavily weighted to enterprise.
        'PENG' => [
            'pricing_power_index' => 0.65,
            'enterprise_weight'   => 0.85,
            'consumer_weight'     => 0.15,
        ],

        // =====================================================================
        // AUTO MANUFACTURER ARCHETYPES
        // =====================================================================

        // --- Falconet Motor Group (FALC) ---
        // Vertically integrated tech-auto leviathan. Predatory base models cross-subsidized by 
        // ultra-luxury 'Apex Division' and inescapable software/telemetry tollbooths.
        // Extreme pricing power, but terrifyingly sensitive to macroeconomic liquidity crises.
        'FALC' => [
            'auto_sales_weight'       => 0.75,
            'auto_financing_weight'   => 0.25, // Represents telemetry, insurance, and software lock-ins
            'pricing_power_index'     => 0.80, // Veblen good luxury pricing; immune to inflation but hyper-pro-cyclical
            'rate_sensitivity_scalar' => 3.00, // Highly sensitive to liquidity panics pausing elite consumption
        ],
    ];

    /**
     * Retrieves a tuned parameter for a given stock ticker, or falls back to the baseline default.
     */
    public static function get(string $ticker, string $parameterKey, float $default): float
    {
        return self::OVERRIDES[$ticker][$parameterKey] ?? $default;
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
     * Resolves a complete parameter map by merging baseline defaults with any company-specific tuning overrides.
     *
     * @param array<string, float> $defaults
     * @return array<string, float>
     */
    public static function resolve(string $ticker, array $defaults): array
    {
        if (!isset(self::OVERRIDES[$ticker])) {
            return $defaults;
        }

        return array_merge($defaults, self::OVERRIDES[$ticker]);
    }
}

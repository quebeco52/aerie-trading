<?php

declare(strict_types=1);

namespace App\Data\Company;

use App\Service\Model\ModelParam;
use App\Service\Model\ModelParameters;

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
            ModelParam::TargetCapitalRatio->value          => 0.2105, // Its seeded book equity/assets
        ],

        // --- Storm Petrel Securities (PTRL) ---
        // Lead primary dealer in Exchequer debt: a bond, swap and repo house with a small debt underwriting desk.
        'PTRL' => [
            ModelParam::AdvisoryRevenueWeight->value => 0.15,
            ModelParam::TradingRevenueWeight->value  => 0.85,
            ModelParam::FiccTradingShare->value      => 0.85,
            ModelParam::TargetCapitalRatio->value    => 0.1111, // Its seeded book equity/assets
        ],

        // --- Kingfisher Capital (KING) ---
        // Pure-play M&A advisory syndicate and capital markets boutique (Lazard / Evercore archetype).
        // Hyper-sensitive to corporate deal pipelines and GDP booms.
        'KING' => [
            ModelParam::AdvisoryRevenueWeight->value => 0.75,
            ModelParam::TradingRevenueWeight->value  => 0.25,
            ModelParam::VixArbitrageScalar->value    => 1.20,
            ModelParam::TargetCapitalRatio->value    => 0.2941, // Its seeded book equity/assets
        ],

        // --- Rook Proprietary Trading (ROOK) ---
        // High-frequency proprietary trading & institutional execution desk. Pure volatility arbitrage focus.
        'ROOK' => [
            ModelParam::AdvisoryRevenueWeight->value => 0.15,
            ModelParam::TradingRevenueWeight->value  => 0.85,
            ModelParam::TargetCapitalRatio->value    => 0.4667, // Its seeded book equity/assets
        ],

        // --- Corvid Strategic Arbitrage (CORV) ---
        // Ultra-exclusive boutique investment bank and weaponized information arbitrage apparatus.
        // Uncorrelated to traditional cycles; thrives on high-frequency arbitrage and institutional volatility.
        'CORV' => [
            ModelParam::AdvisoryRevenueWeight->value => 0.25,
            ModelParam::TradingRevenueWeight->value  => 0.75,
            ModelParam::VixArbitrageScalar->value    => 2.00,
            ModelParam::TargetCapitalRatio->value    => 0.2360, // Its seeded book equity/assets
        ],

        // =====================================================================
        // COMMERCIAL BANKING & CREDIT SERVICES ARCHETYPES
        // =====================================================================

        // --- Lakebird Bank (LAKE) ---
        // Universal banking behemoth with captive corporate Lakebird Syndicate dividend streams.
        'LAKE' => [
            ModelParam::ProprietaryDividendWeight->value => 0.20,
            ModelParam::FeeRevenueWeight->value          => 0.20,
            ModelParam::CreditRiskAppetite->value        => 0.40,
            ModelParam::ResidentialMortgageShare->value  => 0.30, // The District's main mortgage lender
            ModelParam::ConsumerLoanShare->value         => 0.15,
            ModelParam::CommercialRealEstateShare->value => 0.15, // Leads the syndicated corporate book instead
            // Its seeded book equity/assets (JPMorgan 8.6% at end-2024)
            ModelParam::TargetCapitalRatio->value        => 0.084,
            // US international banks, 2019: 2.45% of assets, 87.9% earning (FDIC QBP)
            ModelParam::OperatingCostToEarningAssets->value => 0.0279,
            // US international banks, 2019: 1.82% of assets (FDIC QBP)
            ModelParam::NonInterestIncomeToEarningAssets->value => 0.0207,
        ],

        // --- Riverstone Financial (RIVR) ---
        // Agile regional lender poaching district SMEs. Pure NII model (90%) hyper-sensitive to NIM inversion.
        'RIVR' => [
            ModelParam::FeeRevenueWeight->value          => 0.10,
            ModelParam::CreditRiskAppetite->value        => 0.60,
            ModelParam::ResidentialMortgageShare->value  => 0.00, // Business lender: no household book
            ModelParam::ConsumerLoanShare->value         => 0.00,
            ModelParam::CommercialRealEstateShare->value => 0.45, // Commercial mortgages for contractors and developers
            ModelParam::TargetCapitalRatio->value        => 0.1031, // Its seeded book equity/assets
            // US commercial lenders, 2019: 2.56% of assets, 90.7% earning (FDIC QBP)
            ModelParam::OperatingCostToEarningAssets->value => 0.0282,
            // US commercial lenders, 2019: 1.06% of assets (FDIC QBP)
            ModelParam::NonInterestIncomeToEarningAssets->value => 0.0117,
        ],

        // --- Plover Savings Bank (PLVR) ---
        // Deposit-funded savings bank lending mostly fixed-rate mortgages: the board's longest duration gap.
        'PLVR' => [
            ModelParam::FeeRevenueWeight->value          => 0.15,
            // Its mortgages are fixed for five years and funded by covered bonds, against the District's floating loans
            ModelParam::ResidentialRepricingYears->value => 5.0,
            ModelParam::CreditRiskAppetite->value        => 0.45, // Prime owner-occupier lending
            ModelParam::ResidentialMortgageShare->value  => 0.65,
            ModelParam::ConsumerLoanShare->value         => 0.10,
            ModelParam::CommercialRealEstateShare->value => 0.10, // Small landlords; business loans take the last 15%
            ModelParam::TargetCapitalRatio->value        => 0.084, // Its seeded book equity/assets
            // US mortgage lenders, 2019: 2.54% of assets, 95.1% earning (FDIC QBP)
            ModelParam::OperatingCostToEarningAssets->value => 0.0267,
            // US mortgage lenders, 2019: 1.40% of assets (FDIC QBP)
            ModelParam::NonInterestIncomeToEarningAssets->value => 0.0147,
        ],

        // --- Talon Credit (TALN) ---
        // Prime credit card & digital merchant payment rail network. Substantial swipe interchange fee tollbooth (45%).
        'TALN' => [
            ModelParam::CeclSpreadSensitivity->value => 1.40,
            // Prime cardholders: three quarters of the industry's card losses, ~3.5% a year
            ModelParam::CreditRiskAppetite->value    => 0.375,
        ],

        // --- Stork Consumer Credit (STRK) ---
        // Subprime consumer finance & installment loan originator. Highly exposed to credit spread widening.
        'STRK' => [
            ModelParam::CeclSpreadSensitivity->value => 2.20,
            // Subprime instalment book: 1.6x the card industry, as OneMain's 6.02% against it in 2019 (~7.5% a year)
            ModelParam::CreditRiskAppetite->value    => 0.80,
            // OneMain consumer lending, 2019: $1,290M on $17,055M average receivables (7.6%), on the 78% loan share
            ModelParam::OperatingCostToEarningAssets->value => 0.059,
            // OneMain, 2019: $622M of insurance and other revenue on $17,055M average receivables (3.65%), on the 78%
            // loan share
            ModelParam::NonInterestIncomeToEarningAssets->value => 0.0285,
        ],

        // =====================================================================
        // SHADOW BANKING ARCHETYPES
        // =====================================================================

        // --- Brine Pool Capital (POOL) ---
        // High-risk mezzanine financing, subprime bridge loans, RMBS/CMBS securitization engine.
        'POOL' => [
            ModelParam::MortgageOriginationWeight->value => 0.60,
            ModelParam::DirectLendingWeight->value       => 0.40,
            // Its seeded book equity/assets: the haircuts its repo and warehouse lenders set hold it there, and what it
            // earns above that is handed back rather than lent into a book that outgrows its borrowers.
            ModelParam::TargetCapitalRatio->value        => 0.1964,
        ],

        // =====================================================================
        // INSURANCE & REINSURANCE ARCHETYPES
        // =====================================================================

        // --- Safe Harbor Reinsurance (SAFE) ---
        // Institutional reinsurance titan that absorbs extreme systemic and catastrophe tail risk. Writes
        // quota-share/excess-of-loss treaties, and passes part of its tail to investors through the catastrophe bonds
        // it sponsors ("True 10-Year Yield"); the fees it earns arranging them are the reinsurer's few percent.
        'SAFE' => [
            ModelParam::TreatyReinsuranceWeight->value   => 0.97,
            ModelParam::CatBondSpreadWeight->value       => 0.03,
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
        // Its funds take new money by invitation only and close at the share of its market it opens with (its seeded
        // equity over its market). The leverage is its funds', borrowed against its investors' capital, at the gross
        // it opens with.
        'SWAN' => [
            ModelParam::HfManagementFeeWeight->value   => 0.60,
            ModelParam::HfDirectionalBetsWeight->value => 0.20,
            ModelParam::HfQuantAlphaWeight->value      => 0.20,
            ModelParam::CapacityShare->value           => 0.4035,
            ModelParam::FundGrossLeverage->value       => 2.38,
        ],

        // =====================================================================
        // PRIVATE EQUITY & DISTRESSED DEBT ARCHETYPES
        // =====================================================================

        // --- Vulture Capital Recovery (VULT) ---
        // Specialist distressed debt restructuring and turnaround equity sponsor.
        // Counter-cyclical predator profiting from loan-to-own liquidations and restructuring advisory fees.
        // Its funds close at the share of its market it opens with.
        'VULT' => [
            ModelParam::RestructuringAdvisoryWeight->value => 0.35,
            ModelParam::TurnaroundGainsWeight->value       => 0.65,
            ModelParam::AumMarketBetaScalar->value         => 0.30,
            ModelParam::CapacityShare->value               => 0.32,
        ],

        // --- Tiercel Capital Partners (TIER) ---
        // Buyout sponsor whose funds raise what its franchise can deploy: they close at the share of its market it
        // opens with.
        'TIER' => [
            ModelParam::CapacityShare->value => 0.0357,
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
            ModelParam::TargetCapitalRatio->value     => 0.037, // Its seeded book equity/assets, member margin included
        ],

        // =====================================================================
        // ASSET MANAGEMENT ARCHETYPES
        // =====================================================================

        // --- Owl Capital Partners (OWLS) ---
        // Permanent-capital value holding company. Earns the operating cash flow of wholly-owned
        // subsidiaries, not a management fee on third-party assets, so it runs the conglomerate physics
        // alongside BRKW and TRIV rather than the asset-manager fee model.
        'OWLS' => [
            ModelParam::IndustrialConglomerateWeight->value => 0.56, // Heavy rail and industrial manufacturing
            // Utility infrastructure and consumer goods; its cash and Exchequer bills earn on the treasury
            ModelParam::DefensiveStaplesWeight->value       => 0.44,
            // Buys structural moats by mandate, never price takers
            ModelParam::PricingPowerIndex->value            => 0.80,
        ],

        // --- Crowfall Capital (CROW) ---
        // Activist forensic short-selling syndicate hunting bloated/overleveraged targets.
        'CROW' => [
            ModelParam::BaseFeeWeight->value         => 0.45,
            ModelParam::PerformanceFeeWeight->value  => 0.55,
            ModelParam::AumMarketBetaScalar->value  => 0.60,
            ModelParam::PerformanceFeeZFloor->value => 1.00,
            ModelParam::PerformanceFeeScalar->value  => 0.18,
            // Its syndicate closes at the share of its market it opens with.
            ModelParam::CapacityShare->value         => 0.30,
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
            // Performance ads on an open auction: budgets cut first and hardest
            ModelParam::AdvertisingCyclicality->value    => 1.20,
            // Ruthless data monopoly, high margins, existential regulatory risk
            ModelParam::MonopolyAggression->value        => 0.90,
            // Auction-cleared ad inventory prices itself; only the subscription and cloud books are set by the seller
            ModelParam::PricingPowerIndex->value         => 0.60,
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

        // --- Erne Network Systems (ERNE) --- Cellular radio access equipment, massive MIMO base stations &
        // standard-essential patent licensing (Ericsson archetype). Heavily weighted to long-term carrier
        // infrastructure contracts with high SEP royalty tollbooths.
        'ERNE' => [
            // Carrier radio access, core network routing & optimization software
            ModelParam::EnterpriseWeight->value       => 0.75,
            // Standard-essential patent royalties on every device shipped under the standard
            ModelParam::PatentLicensingWeight->value  => 0.15,
            // Broadband consumer terminal hardware & IoT micro-modules
            ModelParam::ConsumerWeight->value         => 0.10,
            ModelParam::PricingPowerIndex->value      => 0.75, // Standard-essential patent (SEP) monopoly leverage
        ],

        // --- Weaver Marketplace (WEAV) --- Massive third-party ecosystem (the profit engine) blended with volatile
        // first-party retail (the scale engine).
        'WEAV' => [
            ModelParam::ThirdPartyWeight->value         => 0.45,
            ModelParam::FirstPartyWeight->value         => 0.30,
            ModelParam::DigitalAdsWeight->value         => 0.25,
        ],

        // --- Jackdaw Bazaar (JDAW) ---
        // Marketplace only: seller commissions and listing fees, plus ads sold to its sellers; it holds no stock.
        'JDAW' => [
            ModelParam::ThirdPartyWeight->value         => 0.80,
            ModelParam::FirstPartyWeight->value         => 0.00,
            ModelParam::DigitalAdsWeight->value         => 0.20,
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

        // --- Tickbird Data Systems (TICK) --- Terminal, financial analytics & data feed monopoly. Overwhelmingly
        // recurring subscription seat contracts (90%).
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
        // OIL, GAS & MINING ARCHETYPES
        // =====================================================================

        // --- Sinking Shore Extraction (SINK) --- Pure-play upstream offshore deepwater oil & gas E&P. Violently
        // leveraged to spot commodity prices & global energy cycles.
        'SINK' => [
            // Deepwater fields are oil-weighted; associated gas is the remainder
            ModelParam::LiquidsRevenueShare->value => 0.85,
            ModelParam::OilHedgeRatio->value       => 0.15, // Aggressive unhedged price-taker: a token swap ladder
        ],

        // Cascade Refining (CASC) runs on RefiningBusinessModel's own yield and cost constants: no ticker overrides.

        // --- Condor Extraction (CNDR) ---
        // Diversified base-metals major: copper, iron ore and rare earths, with the by-products and side
        // businesses a real major carries (BHP: ~83% copper and iron ore, ~16% coal; Freeport: ~17% gold).
        'CNDR' => [
            ModelParam::BaseMetalsWeight->value         => 0.70, // Copper, iron ore, nickel and rare earths
            ModelParam::PreciousMetalsWeight->value     => 0.10, // Gold and silver recovered from its copper ore
            ModelParam::EnergyMineralsWeight->value     => 0.12, // Steelmaking and thermal coal seams
            ModelParam::FertilizerMineralsWeight->value => 0.08, // A potash leg, the way BHP is building Jansen
        ],

        // --- Goldfinch Mining (FNCH) ---
        // A gold major with a copper by-product: Barrick's 2019 sales were 95% gold and silver, 4% copper (40-F).
        'FNCH' => [
            ModelParam::PreciousMetalsWeight->value => 0.95, // Gold, and the silver recovered with it
            ModelParam::BaseMetalsWeight->value     => 0.05, // Copper concentrate from its porphyry mine
        ],

        // --- Benthos Fiduciary & Trust (BNTH) ---
        // Private bank for foreign wealth. Its loans are Lombard credit against client portfolios, plus mortgages.
        'BNTH' => [
            // Margin-called Lombard lending: under a third of the neutral segment loss rate
            ModelParam::CreditRiskAppetite->value        => 0.15,
            ModelParam::ResidentialMortgageShare->value  => 0.18, // Julius Baer 2019: CHF 8.9B of CHF 48.4B of loans
            ModelParam::ConsumerLoanShare->value         => 0.00,
            ModelParam::CommercialRealEstateShare->value => 0.00, // Lombard loans take the other 82%
            ModelParam::TargetCapitalRatio->value        => 0.0667, // Its seeded book equity/assets
        ],

        // --- Egret Trust (EGRT) --- Custodian of the Sovereign Reserve Fund. Its loans are lines to funds secured on
        // their investors' commitments.
        'EGRT' => [
            ModelParam::FundCustodyShare->value          => 0.20, // A fifth of servicing fees come from the Fund's book
            ModelParam::CreditRiskAppetite->value        => 0.25, // Fund finance: half the neutral segment loss rate
            ModelParam::ResidentialMortgageShare->value  => 0.15, // Private-client mortgages
            ModelParam::ConsumerLoanShare->value         => 0.00,
            ModelParam::CommercialRealEstateShare->value => 0.05, // Business lending to funds takes the last 80%
            ModelParam::TargetCapitalRatio->value        => 0.1143, // Its seeded book equity/assets
        ],

        // --- Bowerbird Homes (BOWR) ---
        // Owns three years of lots, as D.R. Horton did in FY2005 before the industry moved to optioned land.
        'BOWR' => [
            ModelParam::LandBankYears->value => 3.0,
        ],

        // =====================================================================
        // HEAVY INDUSTRY & MANUFACTURING ARCHETYPES
        // =====================================================================

        // --- Steel Wings Smelting & Corp (WING) --- Industrial steel smelting & metallurgical production. Heavy
        // contracted OEM supply (70%) with spot HRC spread (30%).
        'WING' => [
            ModelParam::ContractOemWeight->value  => 0.70,
            ModelParam::SpotHrcWeight->value      => 0.30,
            ModelParam::PricingPowerIndex->value  => 0.65,
        ],

        // --- Iron Beak Heavy Industries (IBHI) --- Massive physical architect. Captive builder for district civic
        // megaprojects, commercial dry docks, and port infrastructure.
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

        // --- Sanderling Rock Dynamics (SNDR) --- Subterranean rock drill rigs, continuous hard-rock tunneling borers &
        // cemented carbide tooling (Sandvik archetype). Dual-stream razor-and-blade model: multi-quarter equipment
        // backlogs paired with high-margin consumable wear-parts tollbooth.
        'SNDR' => [
            // Heavy automated drilling rigs, subterranean continuous miners & crushing units
            ModelParam::EquipmentWeight->value     => 0.55,
            // Cemented carbide rotary bits, wear-resistant liners & telemetry maintenance
            ModelParam::ServicesWeight->value      => 0.45,
            // Proprietary sintered carbide metallurgy; essential uptime tollbooth
            ModelParam::PricingPowerIndex->value   => 0.80,
            ModelParam::OperatingCyclicality->value => 1.15, // Buffered by consumable wear-part replacement cycles
        ],

        // --- Alca Compression Dynamics (ALCA) --- Industrial air compressors, turbomolecular vacuum pumps & precision
        // assembly systems (Atlas Copco archetype). Essential 'Fourth Utility' of manufacturing: catastrophic cost of
        // downtime creates immense pricing power and 40% high-margin service tollbooth.
        'ALCA' => [
            // Oil-free rotary screw compressors, cleanroom turbomolecular vacuum pumps & robotic tools
            ModelParam::EquipmentWeight->value     => 0.60,
            // Predictive maintenance telemetry, replacement rotor elements & clean-air filter consumables
            ModelParam::ServicesWeight->value      => 0.40,
            // Critical operational uptime necessity; shutting down a wafer fab or bottling line costs millions
            ModelParam::PricingPowerIndex->value   => 0.85,
            // Heavy aftermarket service revenue strongly insulates from industrial capex contractions
            ModelParam::OperatingCyclicality->value => 1.05,
        ],

        // --- Nuthatch Climate Systems (NUTH) --- Geothermal ground-source heat pumps, hydronic climate control &
        // industrial thermal elements (NIBE archetype). Highly exposed to building permit cycles and dealer inventory
        // destocking, buffered by aftermarket retrofit/service parts.
        'NUTH' => [
            // Heat pumps, geothermal ground loops, air handlers & ventilation systems
            ModelParam::OemEquipmentWeight->value  => 0.70,
            // Replacement compressors, heat exchangers, glycol loops & service contracts
            ModelParam::AftermarketMroWeight->value => 0.30,
            // Strong Nordic energy-efficiency standards and statutory decarbonization capture
            ModelParam::PricingPowerIndex->value   => 0.70,
            // Moderately high cyclicality driven by mortgage rates and building permits
            ModelParam::OperatingCyclicality->value => 1.25,
        ],

        // --- Wren Access Systems (WREN) ---
        // Locks, cylinders and automated entrances (Assa Abloy archetype), and the counterweight to NUTH in
        // the same industry: Nuthatch sells into the building permit, Wren sells into the door that is
        // already hung. A lock is replaced because it failed, was rekeyed or fell out of code, none of
        // which waits for a construction cycle, so the aftermarket carries the majority of the book and
        // the cyclicality sits below the sector rather than above it.
        'WREN' => [
            // Entrance automation, door hardware & access control fitted into new build
            ModelParam::OemEquipmentWeight->value   => 0.45,
            // Cylinders, credentials, rekeying & service on a century of installed doors
            ModelParam::AftermarketMroWeight->value => 0.55,
            // Specified into building codes and insurance terms; the hardware is a rounding error against the opening
            // it secures
            ModelParam::PricingPowerIndex->value    => 0.85,
            // Replacement-driven and non-discretionary: security spend survives the downturn that stops the build
            ModelParam::OperatingCyclicality->value => 0.85,
        ],

        // --- Chough Mining Systems (CHUF) ---
        // Underground drill rigs, loaders and rock excavation automation (Epiroc archetype). Sits against
        // BUZT, which moves earth in daylight, and against SNDR, which sells the carbide that Chough's rigs
        // consume. Its aftermarket is not a service contract but a consumption rate: a mine that is still
        // producing is still wearing out bits, hoses and booms whatever the capital budget says, which is
        // what keeps the stream running through a cycle that stops new rig orders outright.
        'CHUF' => [
            ModelParam::OemEquipmentWeight->value   => 0.35, // Teleremote drill rigs, underground loaders & haulage
            // Rock drilling consumables, rebuilds & automation service on the installed fleet
            ModelParam::AftermarketMroWeight->value => 0.65,
            // An idle stope costs more per shift than the rig; availability is priced, not iron
            ModelParam::PricingPowerIndex->value    => 0.80,
            // Consumption-led rather than capex-led, so it tracks ore output instead of mine investment
            ModelParam::OperatingCyclicality->value => 0.95,
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
            ModelParam::IndustrialConglomerateWeight->value => 0.67, // Ubiquitous multi-industrial manufacturing
            ModelParam::DefensiveStaplesWeight->value       => 0.33, // Household & adhesive consumer staples
            ModelParam::PricingPowerIndex->value            => 0.60,
        ],

        // --- Breakwater Trust (BRKW) ---
        // Dynastic industrial investment trust and generational anchor. No portfolio composition is
        // declared here: treasury off the balance sheet, stakes off the board via AnchorHoldings,
        // subsidiaries the residual. The revenue mix then inverts the asset mix on its own.
        'BRKW' => [
            ModelParam::PricingPowerIndex->value       => 0.90,
            ModelParam::OperatingCyclicality->value    => 0.45,
        ],

        // --- Harrier Industrial Holdings (HARR) ---
        'HARR' => [
            ModelParam::DefensiveStaplesWeight->value       => 0.70,
            ModelParam::IndustrialConglomerateWeight->value => 0.30,
            ModelParam::PricingPowerIndex->value            => 0.85,
            ModelParam::OperatingCyclicality->value         => 0.60,
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

        // --- Ptarmigan Land Systems (PTAR) ---
        // Tracked combat vehicles and artillery natures sold abroad (Hagglunds / Bofors archetype). The
        // mirror image of GRIP: where the aircraft prime lives on domestic cost-plus, Ptarmigan lives on
        // export campaigns, so its book is won and lost on foreign procurement rather than guaranteed by
        // the territorial budget. Ammunition is a consumable, which is why the fixed-price development
        // sleeve stays small: the money is in refilling magazines, not in designing new ones.
        'PTAR' => [
            ModelParam::CostPlusWeight->value             => 0.30,
            ModelParam::FixedPriceDevWeight->value        => 0.15,
            ModelParam::ForeignMilitarySalesWeight->value => 0.55,
        ],

        // --- Bird Watch Security (WATCH) ---
        // Premier domestic physical asset protection & municipal security retainers.
        'WATCH' => [
            ModelParam::GovernmentContractWeight->value => 0.40,
            ModelParam::RetainerWeight->value            => 0.40,
            ModelParam::ExpeditionaryWeight->value       => 0.10,
            ModelParam::CashServicesWeight->value        => 0.05,
            ModelParam::InvestigationsWeight->value      => 0.05,
        ],

        // --- Osprey Global Vanguard (OSPR) ---
        // Extraterritorial private military contractor & black-ops extraction.
        'OSPR' => [
            ModelParam::GovernmentContractWeight->value => 0.10,
            ModelParam::RetainerWeight->value            => 0.10,
            ModelParam::ExpeditionaryWeight->value       => 0.80,
        ],

        // --- Wallcreeper Custodial (WALL) ---
        // Prisons and remand under District concession, immigration detention, and court escort and tagging for bail
        // insurers.
        'WALL' => [
            ModelParam::CustodyWeight->value       => 0.72,
            ModelParam::DetentionWeight->value     => 0.18,
            ModelParam::EscortTaggingWeight->value => 0.10,
        ],

        // --- Nightjar Risk Partners (NJAR) ---
        // Due diligence and screening on retainer for the banks; fraud, recovery and crisis work when markets break.
        'NJAR' => [
            ModelParam::GovernmentContractWeight->value => 0.15,
            ModelParam::RetainerWeight->value            => 0.00,
            ModelParam::ExpeditionaryWeight->value       => 0.00,
            ModelParam::ScreeningWeight->value           => 0.45,
            ModelParam::InvestigationsWeight->value      => 0.40,
        ],

        // --- Nutcracker Cash & Vault (NCKR) ---
        // Cash rounds for the banks and the shops, and the bullion vaults behind Glasswater Row.
        // The banks buying routes are few and large, so crew pay is passed on less than at a guarding firm.
        'NCKR' => [
            ModelParam::GovernmentContractWeight->value => 0.00,
            ModelParam::RetainerWeight->value            => 0.00,
            ModelParam::ExpeditionaryWeight->value       => 0.00,
            ModelParam::CashServicesWeight->value        => 0.75,
            ModelParam::VaultsWeight->value              => 0.25,
            ModelParam::PricingPowerIndex->value         => 0.70,
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

        // --- Gannet Ro-Ro Lines (GANN) ---
        // Deep-sea vehicle carriers and ro-pax tonnage on multi-year contracts of affreightment (Wallenius
        // Wilhelmsen / Stena archetype). Deliberately the inverse of ALBT's spot book: the same freight
        // cycle reaches both, but Albatross takes it in the quarter it happens and Gannet takes it when a
        // contract is renewed. Without a name carrying cover, the spot cycle has nothing to be read against.
        'GANN' => [
            ModelParam::SpotCharterWeight->value     => 0.25,
            ModelParam::ContractCharterWeight->value => 0.75,
        ],

        // --- Kittiwake Harbour Terminals (KTWK) ---
        // The free port's container terminals. A third of the boxes are relayed between ships at discounted lifts,
        // a quarter of revenue; storage and yard services the rest beside District gateway handling.
        'KTWK' => [
            ModelParam::GatewayHandlingWeight->value => 0.60,
            ModelParam::TransshipmentWeight->value   => 0.25,
            ModelParam::StorageServicesWeight->value => 0.15,
        ],

        // --- Canvasback Logistics (CANV) ---
        // Integrated freight & last-mile delivery provider. Skewed toward dedicated enterprise contracts & 3PL.
        'CANV' => [
            ModelParam::DedicatedFleetWeight->value => 0.55,
            ModelParam::SpotBrokerageWeight->value  => 0.25,
            ModelParam::Warehousing3plWeight->value => 0.20,
            ModelParam::PricingPowerIndex->value    => 0.70,
        ],

        // --- Kestrel Civic Lines (KSTL) --- Class 1 freight & municipal rail transit operator. Preemptive track
        // monopoly with intermodal & industrial carload focus. Half the network is the commuter monopoly its
        // description is actually about: punitive single fares herding millions onto auto-renewing 'Kestrel Link'
        // subscriptions. The other half is the freight that shares the same preemptively laid track. It was tuned 100%
        // freight, so none of the inescapable baseline tax reached the physics.
        'KSTL' => [
            ModelParam::SubscriptionWeight->value       => 0.50, // Kestrel Link commuter subscriptions
            ModelParam::IntermodalFreightWeight->value  => 0.25,
            ModelParam::IndustrialCarloadsWeight->value => 0.15,
            ModelParam::BulkCommoditiesWeight->value    => 0.10,
            ModelParam::PricingPowerIndex->value        => 0.80,
        ],

        // --- Redwing Ore Line (REDW) ---
        // The heavy-haul line off the northern orefields to the ice-free quays: ore, then timber and
        // finished steel on the return working. A pure freight hauler, so the commuter sleeve KSTL runs at
        // half its network is zero here and the other three account for the whole line. That makes it the
        // board's one railway whose revenue answers to mining output rather than to employment, and the
        // rail leg of the same cycle WING, CNDR and FULM are already exposed to.
        'REDW' => [
            // No passenger franchise: the line exists for the orefields
            ModelParam::SubscriptionWeight->value       => 0.00,
            ModelParam::BulkCommoditiesWeight->value    => 0.60, // Unit ore trains under long-term tonnage agreements
            ModelParam::IndustrialCarloadsWeight->value => 0.30, // Timber, pellets and finished steel
            ModelParam::IntermodalFreightWeight->value  => 0.10, // Containerised backhaul on otherwise empty returns
            // A single-track monopoly on the corridor, bounded by what the mine can bear
            ModelParam::PricingPowerIndex->value        => 0.75,
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
            // Value-weighted Phase III programmes: ~1.6 readouts and ~0.8 launches a year
            ModelParam::LateStageAssetCount->value         => 4.0,
        ],

        // --- Crane Medical Network (CRAN) --- Ubiquitous healthcare provider and hospital network. Masters of
        // algorithmic billing, insurance arbitrage, and inelastic acute care.
        'CRAN' => [
            ModelParam::InpatientCareWeight->value        => 0.50, // Inelastic trauma and acute inpatient admissions
            // High-margin elective surgical & ambulatory procedures
            ModelParam::ElectiveOutpatientWeight->value   => 0.30,
            // Algorithmic DRG coding optimization & insurer arbitration
            ModelParam::InsuranceArbitrageWeight->value   => 0.20,
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
            // Addictive, habit-formed demand: decades of above-inflation list price increases with minimal volume
            // response
            ModelParam::PricingPowerIndex->value     => 0.90,
        ],

        // --- Lark & Crest Brands (LARK) ---
        // Household goods & personal hygiene giant (P&G / Kimberly-Clark archetype).
        // Essential consumer staple with overwhelming brand dominance & pricing power (80% branded staples).
        'LARK' => [
            ModelParam::BrandedStaplesWeight->value  => 0.80,
            ModelParam::VolumeCommodityWeight->value => 0.20,
        ],

        // --- Sugarbird Confectionery (SGRB) --- Confectionery giant & commodities cartel. Weaponizes raw cocoa/sugar
        // physical storage to orchestrate short squeezes against hedge funds.
        'SGRB' => [
            ModelParam::BrandedStaplesWeight->value   => 0.55,
            ModelParam::VolumeCommodityWeight->value  => 0.10,
            ModelParam::CommodityTradingWeight->value => 0.35, // Raw cocoa/sugar physical storage short squeezes
            ModelParam::PricingPowerIndex->value      => 0.90,
        ],

        // --- Poultry Crop Operations (CROP) --- Integrated poultry & agricultural producer. Skewed toward commodity
        // volume agriculture (65%, its farmland income included).
        'CROP' => [
            ModelParam::BrandedStaplesWeight->value    => 0.20,
            ModelParam::VolumeCommodityWeight->value   => 0.65,
            ModelParam::CommodityTradingWeight->value  => 0.15,
            // Bulk agricultural output clears at the exchange price, not a list price
            ModelParam::PricingPowerIndex->value       => 0.30,
            // Crops sell at the exchange; contract poultry at a list price that follows feed
            ModelParam::AgriculturalOutputShare->value => 0.40,
        ],

        // --- Pintail Beverage Group (PINT) --- Industrial ethanol syndicate & heritage alcohol cartel. Deploys the
        // 'Proof Desk' to corner agricultural futures and packaging supply.
        'PINT' => [
            ModelParam::BrandedStaplesWeight->value   => 0.60,
            ModelParam::VolumeCommodityWeight->value  => 0.20,
            // Internal 'Proof Desk' agricultural futures & silica hoarding
            ModelParam::CommodityTradingWeight->value => 0.20,
            ModelParam::PricingPowerIndex->value      => 0.85,
            // Heritage spirits age in cask: ~200 days of branded sales held maturing, ~45% of revenue in stock all told
            ModelParam::MaturingStockShare->value     => 0.55,
        ],

        // --- Copperhead Coffee Roasters (BREW) --- Retail coffee cafes & prime real estate holdings. High
        // company-owned store footprint (70%) and prime leases (20%).
        'BREW' => [
            ModelParam::CompanyStoresWeight->value      => 0.70,
            ModelParam::FranchiseRoyaltiesWeight->value => 0.10,
            ModelParam::FranchiseLeaseWeight->value     => 0.20,
            ModelParam::PricingPowerIndex->value        => 0.85,
        ],

        // --- Golden Swift Holdings (SWFT) --- Massive global fast-food franchise network. Master-franchise model with
        // royalties (55%) and property leases (40%).
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
            // Absolute monopoly pricing power over captive tenants
            ModelParam::PricingPowerIndex->value           => 1.00,
            ModelParam::GamingRevenueWeight->value         => 0.20, // Casino floors are just the bait for foot traffic
            ModelParam::NonGamingRevenueWeight->value     => 0.10,
            // The real engine: extortionate commercial real estate leases
            ModelParam::CommercialRealEstateWeight->value => 0.70,
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

        // --- Lyrebird Media (LYRE) --- Premier advertising and perception management conglomerate. Floods airwaves
        // with corporate crisis retainers & martech data ops.
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
            // High-stakes corporate warfare & predatory settlements
            ModelParam::LitigationContingencyWeight->value  => 0.35,
            // Counter-cyclical corporate restructuring & workout fees
            ModelParam::RestructuringAdvisoryWeight->value  => 0.25,
            ModelParam::PricingPowerIndex->value            => 0.90,
        ],

        // =====================================================================
        // CONSULTING ARCHETYPES
        // =====================================================================

        // --- Godwit Consulting Group (GDWT) ---
        // Strategy and technology house: board advice on top of multi-year systems programmes and managed services.
        'GDWT' => [
            ModelParam::StrategyAdvisoryWeight->value    => 0.35,
            ModelParam::TechnologyProgrammeWeight->value => 0.55,
            ModelParam::RiskRestructuringWeight->value   => 0.10,
        ],

        // --- Turnstone Advisory (TSTN) ---
        // Restructuring, regulatory remediation and forensic work, with economic and technology advice on the side.
        'TSTN' => [
            ModelParam::StrategyAdvisoryWeight->value    => 0.30,
            ModelParam::TechnologyProgrammeWeight->value => 0.10,
            ModelParam::RiskRestructuringWeight->value   => 0.60,
            ModelParam::PricingPowerIndex->value         => 0.75, // A crisis mandate is not shopped on price
        ],

        // --- Avocet & Curlew (AVCT) ---
        // Multidisciplinary accounting firm: statutory audit and tax beside consulting, deal and risk advice.
        'AVCT' => [
            ModelParam::StrategyAdvisoryWeight->value    => 0.15,
            ModelParam::TechnologyProgrammeWeight->value => 0.30,
            ModelParam::RiskRestructuringWeight->value   => 0.15,
            ModelParam::AuditTaxWeight->value            => 0.40,
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
            // Hyper-exclusive Veblen hypercars (Apex Division cross-subsidy)
            ModelParam::ApexLuxuryWeight->value       => 0.25,
            ModelParam::SoftwareServicesWeight->value => 0.08, // Inescapable telemetry & subscription tolls
            ModelParam::CaptiveFinanceWeight->value   => 0.12, // Captive finance on the fleet it sells
            ModelParam::PricingPowerIndex->value      => 0.65,
            ModelParam::RateSensitivityScalar->value  => 1.25,
        ],

        // --- Eider Motor Group (EIDR) ---
        // Fortified passenger vehicles, heavy commercial prime movers & industrial transport chassis (Volvo archetype).
        // Conservative fleet sales and executive armored wagons supported by mandatory insurance fleet standards.
        'EIDR' => [
            ModelParam::AutoSalesWeight->value        => 0.70, // Fortified commercial haulers & municipal fleet sales
            ModelParam::ApexLuxuryWeight->value       => 0.15, // Armored executive wagons & discrete VIP transports
            // Certified telematics, collision avoidance & maintenance tolls
            ModelParam::SoftwareServicesWeight->value => 0.15,
            ModelParam::CaptiveFinanceWeight->value   => 0.00, // Fleets finance through their own banks
            ModelParam::PricingPowerIndex->value      => 0.75, // Backed by insurance underwriting standards
            // Institutional fleet renewals less rate-sensitive than retail auto loans
            ModelParam::RateSensitivityScalar->value  => 0.85,
        ],

        // =====================================================================
        // UTILITY & MUNICIPAL INFRASTRUCTURE ARCHETYPES
        // =====================================================================

        // --- Bird Power Inc (BIRD) --- Integrated electric utility. Heavily regulated rate base transmission &
        // distribution (80%), merchant renewables (20%). Renewables burn no fuel, so the merchant fleet keeps the whole
        // wholesale price.
        'BIRD' => [
            ModelParam::RegulatedBaseWeight->value       => 0.80,
            ModelParam::UnregulatedMerchantWeight->value => 0.20,
            ModelParam::MerchantGasFleetShare->value     => 0.00,
        ],

        // --- Heron Regional Water (WADE) --- Regulated municipal water & wastewater utility. Pure regulated rate base
        // monopoly (95%); the market-based 5% is contract operations for municipal and industrial systems, not power
        // sales. Its mains are buried: a storm reaches its treatment plants and pumping power, not the network, so it
        // takes a quarter of an overhead grid's storm damage. A failure of its own is a main collapse or a
        // contamination, not a wildfire its lines start, and carries about a quarter of the liability and rebuild.
        'WADE' => [
            ModelParam::RegulatedBaseWeight->value       => 0.95,
            ModelParam::UnregulatedMerchantWeight->value => 0.05,
            ModelParam::MerchantPowerShare->value        => 0.00,
            ModelParam::StormDamageExposure->value       => 0.25,
            ModelParam::NetworkFailureExposure->value    => 0.25,
        ],

        // --- Dipper Generation (DIPR) ---
        // Independent generator: hydro and a tidal barrage burn no fuel, the coastal gas stations half the output.
        // Capacity contracts are a fifth of revenue; most of next year's output is sold forward.
        'DIPR' => [
            ModelParam::WholesaleGenerationWeight->value => 0.80,
            ModelParam::CapacityPaymentWeight->value     => 0.20,
            ModelParam::MerchantGasFleetShare->value     => 0.50,
            ModelParam::PowerHedgeRatio->value           => 0.60,
        ],

        // --- Loon Call Telecom (LOON) ---
        // The District's largest mobile and fibre carrier: service plans 83%, devices 17% (Verizon 2024 mix).
        'LOON' => [
            ModelParam::SubscriptionWeight->value => 0.83,
            ModelParam::EquipmentWeight->value    => 0.17,
        ],

        // --- Skua Mobile (SKUA) ---
        // Mobile-only challenger on prepaid and no-contract plans (T-Mobile US 2014 mix: equipment 24% of revenue).
        // Its base turns over faster than the incumbent's: 1.6% a month, T-Mobile's branded postpaid phone churn
        // in 2014, against the 1% Loon Call carries.
        'SKUA' => [
            ModelParam::SubscriptionWeight->value => 0.76,
            ModelParam::EquipmentWeight->value    => 0.24,
            ModelParam::BaseQuarterlyChurn->value => 0.048,
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

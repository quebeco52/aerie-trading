<?php

namespace App\Data;

class Sectors
{
    public const MACRO_SECTORS = [
        'Information Technology' => 24.0, // High growth, high premium
        'Financials' => 14.0,             // Banks, Capital Markets, Insurance
        'Health Care' => 19.0,            // Biotech, Medical
        'Consumer Discretionary' => 21.0, // Retail, Casinos, Luxury
        'Consumer Staples' => 18.0,       // Food, Beverages, Tobacco
        'Industrials' => 20.0,            // Aerospace, Defense, Freight
        'Real Estate' => 22.0,            // REITs, Development
        'Energy' => 14.0,                 // Oil, Gas (Typically trades at a discount)
        'Materials' => 15.0,              // Steel, Chemicals, Mining
        'Utilities' => 16.0,              // Regulated safe havens (Water, Power)
        'Communication Services' => 17.0, // Telecom, Media, Entertainment
    ];

    public const INDUSTRY_METRICS = [
        'Advertising Agencies' => ['pe' => 16.50, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'],
        'Aerospace & Defense' => ['pe' => 22.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Agricultural Inputs' => ['pe' => 15.00, 'depreciation' => 0.07, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Airlines' => ['pe' => 10.00, 'depreciation' => 0.07, 'ebitda_limit' => 3.5, 'equity_limit' => 2.0, 'leverage_type' => 'none'],
        'Aluminum' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Apparel Manufacturing' => ['pe' => 16.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Apparel Retail' => ['pe' => 18.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Asset Management' => ['pe' => 15.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'brokerage'], // Upgraded to brokerage
        'Auto Manufacturers' => ['pe' => 8.50, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 2.0, 'leverage_type' => 'none'],
        'Auto Parts' => ['pe' => 14.00, 'depreciation' => 0.07, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Auto & Truck Dealerships' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Floorplan debt allows high limits
        'Banks - Diversified' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 10.0, 'leverage_type' => 'commercial_bank'],
        'Banks - Regional' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 9.0, 'leverage_type' => 'commercial_bank'],
        'Beverages - Non-Alcoholic' => ['pe' => 24.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Biotechnology' => ['pe' => 18.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Highly volatile, bond markets hate lending to biotech
        'Building Materials' => ['pe' => 15.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Building Products & Equipment' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Business Equipment & Supplies' => ['pe' => 12.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Capital Markets' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'leverage_type' => 'brokerage'], // Investment banks / Brokerages
        'Chemicals' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Communication Equipment' => ['pe' => 18.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'],
        'Computer Hardware' => ['pe' => 15.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'],
        'Conglomerates' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Consulting Services' => ['pe' => 22.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Almost entirely human capital
        'Copper' => ['pe' => 12.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Asset heavy, cyclical mining
        'Credit Services' => ['pe' => 15.00, 'depreciation' => 0.05, 'ebitda_limit' => 999.0, 'equity_limit' => 7.0, 'leverage_type' => 'commercial_bank'], // Amex, Discover. Acts like a bank.
        'Diagnostics & Research' => ['pe' => 24.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Discount Stores' => ['pe' => 20.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Very safe, steady cash flows
        'Drug Manufacturers - General' => ['pe' => 16.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Big Pharma
        'Drug Manufacturers - Specialty & Generic' => ['pe' => 14.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Education & Training Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'],
        'Electrical Equipment & Parts' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Electronic Components' => ['pe' => 16.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Hardware gets obsolete fast
        'Electronic Gaming & Multimedia' => ['pe' => 22.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Hit-driven, no debt allowed
        'Electronics & Computer Distribution' => ['pe' => 14.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Engineering & Construction' => ['pe' => 14.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Highly cyclical
        'Entertainment' => ['pe' => 20.00, 'depreciation' => 0.10, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Media assets/parks support debt
        'Farm & Heavy Construction Machinery' => ['pe' => 15.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Farm Products' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Financial Data & Stock Exchanges' => ['pe' => 26.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Monopolies, high P/E
        'Food Distribution' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Footwear & Accessories' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'],
        'Furnishings, Fixtures & Appliances' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Gambling' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Collateralized by real estate
        'Gold' => ['pe' => 15.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Mines deplete, highly cyclical
        'Grocery Stores' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Very safe debt profile
        'Healthcare Plans' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Managed care
        'Health Information Services' => ['pe' => 24.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'],
        'Home Improvement Retail' => ['pe' => 20.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Household & Personal Products' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // P&G, Colgate. Premium P/E.
        'Industrial Distribution' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Information Technology Services' => ['pe' => 24.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Asset light
        'Insurance Brokers' => ['pe' => 22.00, 'depreciation' => 0.02, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'leverage_type' => 'brokerage'], // Asset light fee business
        'Insurance - Diversified' => ['pe' => 12.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 7.0, 'leverage_type' => 'insurance'],
        'Insurance - Life' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'leverage_type' => 'insurance'],
        'Insurance - Property & Casualty' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 6.0, 'leverage_type' => 'insurance'], // P&C is riskier, needs more equity buffer
        'Insurance - Reinsurance' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 5.0, 'leverage_type' => 'insurance'], // Taking the riskiest policies, highest capital requirements
        'Insurance - Specialty' => ['pe' => 13.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 6.0, 'leverage_type' => 'insurance'],
        'Integrated Freight & Logistics' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // UPS, FedEx. Heavy CapEx.
        'Internet Content & Information' => ['pe' => 25.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Alphabet, Meta. Asset light.
        'Internet Retail' => ['pe' => 28.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Amazon. Logistics heavy.
        'Leisure' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Theme parks, cruises. Collateralized debt.
        'Lodging' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Hotels. Real estate backed.
        'Luxury Goods' => ['pe' => 24.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // High margin, brand value.
        'Marine Shipping' => ['pe' => 9.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Extremely cyclical, rusts fast.
        'Medical Care Facilities' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Hospitals. Stable cash flow, heavy assets.
        'Medical Devices' => ['pe' => 25.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Medical Distribution' => ['pe' => 14.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Razor thin margins, high volume.
        'Medical Instruments & Supplies' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Metal Fabrication' => ['pe' => 13.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Mortgage Finance' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'leverage_type' => 'commercial_bank'], // Shadow banks / Fannie Mae. Bank Rule.
        'Oil & Gas E&P' => ['pe' => 11.00, 'depreciation' => 0.12, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Exploration. Wells deplete incredibly fast.
        'Oil & Gas Equipment & Services' => ['pe' => 14.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Cyclical services.
        'Oil & Gas Integrated' => ['pe' => 13.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Exxon/Chevron. Safer than E&P.
        'Oil & Gas Midstream' => ['pe' => 14.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Pipelines. "Toll roads", highly stable.
        'Oil & Gas Refining & Marketing' => ['pe' => 11.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Crack spreads are volatile.
        'Packaged Foods' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Extremely defensive.
        'Packaging & Containers' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Personal Services' => ['pe' => 18.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Pollution & Treatment Controls' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // ESG premium, stable.
        'Publishing' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Secular decline.
        'Railroads' => ['pe' => 19.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Wide moat monopoly pricing.
        'Real Estate - Development' => ['pe' => 13.00, 'depreciation' => 0.03, 'ebitda_limit' => 5.0, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Boom and bust, heavily levered.
        'Real Estate Services' => ['pe' => 18.00, 'depreciation' => 0.02, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Asset light brokerages! No huge debt allowed.
        'Recreational Vehicles' => ['pe' => 11.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Highly discretionary, first to drop in recession.
        'REIT - Diversified' => ['pe' => 16.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'],
        'REIT - Healthcare Facilities' => ['pe' => 15.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'], // Very stable
        'REIT - Hotel & Motel' => ['pe' => 13.00, 'depreciation' => 0.04, 'ebitda_limit' => 5.5, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Highly cyclical, less debt allowed
        'REIT - Industrial' => ['pe' => 18.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'], // Warehouses (Amazon effect), premium P/E
        'REIT - Mortgage' => ['pe' => 10.00, 'depreciation' => 0.01, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'leverage_type' => 'commercial_bank'], // Pure financial engineering (Bank Rule)
        'REIT - Office' => ['pe' => 12.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.0, 'equity_limit' => 2.5, 'leverage_type' => 'none'], // Work-from-home headwinds
        'REIT - Residential' => ['pe' => 17.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'], // Apartments, highly resilient
        'REIT - Retail' => ['pe' => 14.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.0, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Malls, e-commerce risk
        'REIT - Specialty' => ['pe' => 16.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'], // Data centers, cell towers
        'Rental & Leasing Services' => ['pe' => 15.00, 'depreciation' => 0.10, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Rental cars depreciate fast
        'Residential Construction' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Homebuilders. Highly cyclical.
        'Resorts & Casinos' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Collateralized by prime real estate
        'Restaurants' => ['pe' => 20.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Franchise models support decent debt
        'Scientific & Technical Instruments' => ['pe' => 26.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // High margin, IP heavy
        'Security & Protection Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Semiconductor Equipment & Materials' => ['pe' => 22.00, 'depreciation' => 0.12, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // ASML etc. Boom and bust.
        'Semiconductors' => ['pe' => 24.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Fab plants age like milk
        'Software - Application' => ['pe' => 28.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Asset light, pure IP, high growth
        'Software - Infrastructure' => ['pe' => 26.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Sticky revenues (Microsoft, Oracle)
        'Solar' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Capital intensive manufacturing
        'Specialty Business Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Specialty Chemicals' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'],
        'Specialty Industrial Machinery' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Specialty Retail' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Staffing & Employment Services' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'leverage_type' => 'none'], // Pure human capital. No hard assets.
        'Steel' => ['pe' => 10.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Brutally cyclical commodity
        'Telecom Services' => ['pe' => 14.00, 'depreciation' => 0.10, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'leverage_type' => 'none'], // Massive CAPEX, but incredibly stable utility-like cash flows
        'Tobacco' => ['pe' => 11.00, 'depreciation' => 0.04, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Secular volume decline, massive cash flows, huge debt capacity
        'Tools & Accessories' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
        'Travel Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'leverage_type' => 'none'], // Expedia, Booking.com. Asset light.
        'Trucking' => ['pe' => 14.00, 'depreciation' => 0.12, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Fixed the 45 P/E error. Trucks rust.
        'Utilities - Diversified' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'], // Regulated monopolies
        'Utilities - Regulated Electric' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'],
        'Utilities - Regulated Gas' => ['pe' => 15.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'],
        'Utilities - Regulated Water' => ['pe' => 18.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'leverage_type' => 'none'], // Safest asset class on earth
        'Waste Management' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'leverage_type' => 'none'], // Trash is cash. High P/E.
        'General' => ['pe' => 18.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'leverage_type' => 'none'],
    ];
}

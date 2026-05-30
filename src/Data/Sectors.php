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
        'Advertising Agencies' => ['pe' => 16.50, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Aerospace & Defense' => ['pe' => 22.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Agricultural Inputs' => ['pe' => 15.00, 'depreciation' => 0.07, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Airlines' => ['pe' => 10.00, 'depreciation' => 0.07, 'ebitda_limit' => 3.5, 'equity_limit' => 2.0, 'business_model' => 'none'],
        'Aluminum' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Apparel Manufacturing' => ['pe' => 16.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Apparel Retail' => ['pe' => 18.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Asset Management' => ['pe' => 15.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'asset_manager'],
        'Auto Manufacturers' => ['pe' => 8.50, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 2.0, 'business_model' => 'none'],
        'Auto Parts' => ['pe' => 14.00, 'depreciation' => 0.07, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Auto & Truck Dealerships' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Floorplan debt allows high limits
        'Banks - Diversified' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 10.0, 'business_model' => 'commercial_bank'],
        'Banks - Regional' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 9.0, 'business_model' => 'commercial_bank'],
        'Beverages - Non-Alcoholic' => ['pe' => 24.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Biotechnology' => ['pe' => 18.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Highly volatile, bond markets hate lending to biotech
        'Building Materials' => ['pe' => 15.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Building Products & Equipment' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Business Equipment & Supplies' => ['pe' => 12.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Capital Markets' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'brokerage'], // Investment banks / Brokerages
        'Chemicals' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Communication Equipment' => ['pe' => 18.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Computer Hardware' => ['pe' => 15.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Conglomerates' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Consulting Services' => ['pe' => 22.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Almost entirely human capital
        'Copper' => ['pe' => 12.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Asset heavy, cyclical mining
        'Credit Services' => ['pe' => 15.00, 'depreciation' => 0.05, 'ebitda_limit' => 999.0, 'equity_limit' => 7.0, 'business_model' => 'commercial_bank'], // Amex, Discover. Acts like a bank.
        'Diagnostics & Research' => ['pe' => 24.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Discount Stores' => ['pe' => 20.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Very safe, steady cash flows
        'Drug Manufacturers - General' => ['pe' => 16.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Big Pharma
        'Drug Manufacturers - Specialty & Generic' => ['pe' => 14.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Education & Training Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Electrical Equipment & Parts' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Electronic Components' => ['pe' => 16.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Hardware gets obsolete fast
        'Electronic Gaming & Multimedia' => ['pe' => 22.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Hit-driven, no debt allowed
        'Electronics & Computer Distribution' => ['pe' => 14.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Engineering & Construction' => ['pe' => 14.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Highly cyclical
        'Entertainment' => ['pe' => 20.00, 'depreciation' => 0.10, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Media assets/parks support debt
        'Farm & Heavy Construction Machinery' => ['pe' => 15.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Farm Products' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Financial Data & Stock Exchanges' => ['pe' => 26.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Monopolies, high P/E
        'Food Distribution' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Footwear & Accessories' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Furnishings, Fixtures & Appliances' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Gambling' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Collateralized by real estate
        'Gold' => ['pe' => 15.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Mines deplete, highly cyclical
        'Grocery Stores' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Very safe debt profile
        'Healthcare Plans' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Managed care
        'Health Information Services' => ['pe' => 24.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Home Improvement Retail' => ['pe' => 20.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Household & Personal Products' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // P&G, Colgate. Premium P/E.
        'Industrial Distribution' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Information Technology Services' => ['pe' => 24.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Asset light
        'Insurance Brokers' => ['pe' => 22.00, 'depreciation' => 0.02, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'brokerage'], // Asset light fee business
        'Insurance - Diversified' => ['pe' => 12.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 7.0, 'business_model' => 'insurance'],
        'Insurance - Life' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'insurance'],
        'Insurance - Property & Casualty' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 6.0, 'business_model' => 'insurance'], // P&C is riskier, needs more equity buffer
        'Insurance - Reinsurance' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 5.0, 'business_model' => 'insurance'], // Taking the riskiest policies, highest capital requirements
        'Insurance - Specialty' => ['pe' => 13.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 6.0, 'business_model' => 'insurance'],
        'Integrated Freight & Logistics' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // UPS, FedEx. Heavy CapEx.
        'Internet Content & Information' => ['pe' => 25.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Alphabet, Meta. Asset light.
        'Internet Retail' => ['pe' => 28.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Amazon. Logistics heavy.
        'Leisure' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Theme parks, cruises. Collateralized debt.
        'Lodging' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Hotels. Real estate backed.
        'Luxury Goods' => ['pe' => 24.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // High margin, brand value.
        'Marine Shipping' => ['pe' => 9.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Extremely cyclical, rusts fast.
        'Medical Care Facilities' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Hospitals. Stable cash flow, heavy assets.
        'Medical Devices' => ['pe' => 25.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Medical Distribution' => ['pe' => 14.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Razor thin margins, high volume.
        'Medical Instruments & Supplies' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Metal Fabrication' => ['pe' => 13.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Mortgage Finance' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'commercial_bank'], // Shadow banks / Fannie Mae. Bank Rule.
        'Oil & Gas E&P' => ['pe' => 11.00, 'depreciation' => 0.12, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Exploration. Wells deplete incredibly fast.
        'Oil & Gas Equipment & Services' => ['pe' => 14.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Cyclical services.
        'Oil & Gas Integrated' => ['pe' => 13.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Exxon/Chevron. Safer than E&P.
        'Oil & Gas Midstream' => ['pe' => 14.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Pipelines. "Toll roads", highly stable.
        'Oil & Gas Refining & Marketing' => ['pe' => 11.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Crack spreads are volatile.
        'Packaged Foods' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Extremely defensive.
        'Packaging & Containers' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Personal Services' => ['pe' => 18.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Pollution & Treatment Controls' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // ESG premium, stable.
        'Publishing' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Secular decline.
        'Railroads' => ['pe' => 19.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Wide moat monopoly pricing.
        'Real Estate - Development' => ['pe' => 13.00, 'depreciation' => 0.03, 'ebitda_limit' => 5.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Boom and bust, heavily levered.
        'Real Estate Services' => ['pe' => 18.00, 'depreciation' => 0.02, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Asset light brokerages! No huge debt allowed.
        'Recreational Vehicles' => ['pe' => 11.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Highly discretionary, first to drop in recession.
        'REIT - Diversified' => ['pe' => 16.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'],
        'REIT - Healthcare Facilities' => ['pe' => 15.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Very stable
        'REIT - Hotel & Motel' => ['pe' => 13.00, 'depreciation' => 0.04, 'ebitda_limit' => 5.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Highly cyclical, less debt allowed
        'REIT - Industrial' => ['pe' => 18.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Warehouses (Amazon effect), premium P/E
        'REIT - Mortgage' => ['pe' => 10.00, 'depreciation' => 0.01, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'commercial_bank'], // Pure financial engineering (Bank Rule)
        'REIT - Office' => ['pe' => 12.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.0, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Work-from-home headwinds
        'REIT - Residential' => ['pe' => 17.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Apartments, highly resilient
        'REIT - Retail' => ['pe' => 14.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.0, 'equity_limit' => 2.0, 'business_model' => 'reit'], // Malls, e-commerce risk
        'REIT - Specialty' => ['pe' => 16.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Data centers, cell towers
        'Rental & Leasing Services' => ['pe' => 15.00, 'depreciation' => 0.10, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Rental cars depreciate fast
        'Residential Construction' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Homebuilders. Highly cyclical.
        'Resorts & Casinos' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Collateralized by prime real estate
        'Restaurants' => ['pe' => 20.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Franchise models support decent debt
        'Scientific & Technical Instruments' => ['pe' => 26.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // High margin, IP heavy
        'Security & Protection Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Semiconductor Equipment & Materials' => ['pe' => 22.00, 'depreciation' => 0.12, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // ASML etc. Boom and bust.
        'Semiconductors' => ['pe' => 24.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Fab plants age like milk
        'Software - Application' => ['pe' => 28.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Asset light, pure IP, high growth
        'Software - Infrastructure' => ['pe' => 26.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Sticky revenues (Microsoft, Oracle)
        'Solar' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Capital intensive manufacturing
        'Specialty Business Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Specialty Chemicals' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Specialty Industrial Machinery' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Specialty Retail' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Staffing & Employment Services' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Pure human capital. No hard assets.
        'Steel' => ['pe' => 10.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Brutally cyclical commodity
        'Telecom Services' => ['pe' => 14.00, 'depreciation' => 0.10, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Massive CAPEX, but incredibly stable utility-like cash flows
        'Tobacco' => ['pe' => 11.00, 'depreciation' => 0.04, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Secular volume decline, massive cash flows, huge debt capacity
        'Tools & Accessories' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Travel Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Expedia, Booking.com. Asset light.
        'Trucking' => ['pe' => 14.00, 'depreciation' => 0.12, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Fixed the 45 P/E error. Trucks rust.
        'Utilities - Diversified' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'none'], // Regulated monopolies
        'Utilities - Regulated Electric' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'none'],
        'Utilities - Regulated Gas' => ['pe' => 15.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'none'],
        'Utilities - Regulated Water' => ['pe' => 18.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'none'], // Safest asset class on earth
        'Waste Management' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Trash is cash. High P/E.
        'General' => ['pe' => 18.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
    ];
}

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

    public const BUSINESS_MODEL_DESCRIPTIONS = [
        'commercial_bank' => 'Banks use fractional reserve lending. They take cheap customer deposits (managed via dynamic APY) and lend them out at higher interest rates. Evaluated on Return on Equity (ROE). Highly profitable during steep yield curves but squeezed by inversions. Suffers massive loan loss provisions during economic downturns.',
        'insurance'       => 'Collects highly sticky premiums upfront to form a massive cash "Float". Invests this float heavily into long-duration bonds for yield. Evaluated on ROE. Highly stable top-line revenue, but vulnerable to asymmetric catastrophic claim shocks.',
        'brokerage'       => 'Highly leveraged, transaction-driven capital markets and insurance brokers. Generates revenue through trading fees, advisory, and margin loans. Revenues are hyper-sensitive to systemic volatility (VIX), capturing massive fees during market panics or euphoria. Evaluated on ROE.',
        'asset_manager'   => 'Collects management fees based on total Assets Under Management (AUM). Highly scalable and asset-light. Idiosyncratic variance is exceptionally low due to sticky recurring fees, but vulnerable to broader market downturns reducing AUM. Evaluated on ROE.',
        'credit_services' => 'Operates like a bank but with unsecured loans. Revenue benefits directly from inflation as swipe fees scale with prices. Extremely vulnerable to economic downturns when unsecured consumer loan defaults violently spike. Evaluated on ROE.',
        'private_equity'  => 'Alternative asset managers operating with leverage. Base revenue is AUM fees, but massive performance fees (Carried Interest) are earned during economic expansions. Highly dependent on cheap credit and M&A volume. Evaluated on ROE.',
        'shadow_bank'     => 'Non-depository financial institutions (Mortgage Finance, Mortgage REITs). They fund massive loan books entirely through short-term wholesale debt. Hyper-vulnerable to yield curve inversions and mortgage default spikes during housing crashes. Evaluated on ROE.',
        'reit'            => 'Real Estate Investment Trusts hold physical property. Evaluated on Funds From Operations (FFO). Pays 0% corporate tax but must issue heavy debt to expand due to high dividend payouts. Features CPI rent escalators (inflation hedge) and tenant vacancy risks.',
        'utility'         => 'Regulated monopolies and essential infrastructure (Power, Water, Telecom, Waste Management) with heavily regulated Return on Invested Capital (ROIC). They grow absolute earnings by deploying massive CapEx. Revenues are hyper-stable, but rate hikes lag behind inflation, causing temporary margin compression during inflationary spikes.',
        'commodity'       => 'Heavy extractors and refiners (Metals, Mining, Oil & Gas) acting as ultimate "Price Takers." Revenues are violently driven by global supply and demand. Inflation is a blessing: as the base of the supply chain, their margins explode upwards during inflationary spikes.',
        'financial_data'  => 'Asset-light data monopolies. Characterized by incredibly sticky recurring subscription revenue, ultra-high margins, and complete immunity to physical supply chain inflation. Features exceptionally low idiosyncratic variance.',
        'tech'            => 'Asset-light platform businesses with near-zero marginal costs. Immune to physical supply chains but exposed to high wage inflation. Features higher baseline volatility and fat-tail risks like massive regulatory anti-trust fines or data breaches.',
        'consumer_staples' => 'Produces essential goods and non-cyclical services (Food, Tobacco, Household, Medical Care Facilities, Discount Stores). Features inelastic demand (very low volatility) and high pricing power, allowing them to completely ignore supply chain inflation penalties.',
        'defense_contractor' => 'Operates on government "Cost-Plus" contracts and municipal defense budgets (Aerospace, Security Services). Highly immune to recessions. Because their profit margin is a guaranteed percentage of total costs, inflation actually increases their absolute earnings.',
        'clearing_house'  => 'Acts as the ultimate guarantor of all market trades. Holds massive "Initial Margin" deposits from member firms, earning interest on the float. Revenue scales off transaction volume, thriving during market panics (high VIX). Carries extreme apocalyptic tail risk if member defaults exceed the margin pool. Evaluated on ROE.',
        'none'            => 'Standard corporate physics. Evaluated on Return on Invested Capital (ROIC). Subject to physical depreciation and supply chain inflation penalties when costs rise faster than pricing power. Idiosyncratic variance applies directly to sales volume.',
    ];

    public const INDUSTRY_METRICS = [
        'Advertising Agencies' => ['pe' => 16.50, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Aerospace & Defense' => ['pe' => 22.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'defense_contractor'],
        'Agricultural Inputs' => ['pe' => 15.00, 'depreciation' => 0.07, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Airlines' => ['pe' => 10.00, 'depreciation' => 0.07, 'ebitda_limit' => 3.5, 'equity_limit' => 2.0, 'business_model' => 'none'],
        'Aluminum' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'commodity'],
        'Apparel Manufacturing' => ['pe' => 16.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Apparel Retail' => ['pe' => 18.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Asset Management' => ['pe' => 15.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'asset_manager'],
        'Auto Manufacturers' => ['pe' => 8.50, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 2.0, 'business_model' => 'none'],
        'Auto Parts' => ['pe' => 14.00, 'depreciation' => 0.07, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Auto & Truck Dealerships' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Floorplan debt allows high limits
        'Banks - Diversified' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 10.0, 'business_model' => 'commercial_bank'],
        'Banks - Regional' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 9.0, 'business_model' => 'commercial_bank'],
        'Beverages - Non-Alcoholic' => ['pe' => 24.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'],
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
        'Copper' => ['pe' => 12.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'commodity'], // Asset heavy, cyclical mining
        'Credit Services' => ['pe' => 15.00, 'depreciation' => 0.05, 'ebitda_limit' => 999.0, 'equity_limit' => 7.0, 'business_model' => 'credit_services'], // Amex, Discover. Unsecured lending & swipe fees.
        'Diagnostics & Research' => ['pe' => 24.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Discount Stores' => ['pe' => 20.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'], // Very safe, steady cash flows
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
        'Farm Products' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'consumer_staples'],
        'Financial Data & Stock Exchanges' => ['pe' => 26.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'financial_data'], // Monopolies, high P/E
        'Financial Clearinghouses' => ['pe' => 18.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'clearing_house'],
        'Food Distribution' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Footwear & Accessories' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Furnishings, Fixtures & Appliances' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Gambling' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Collateralized by real estate
        'Gold' => ['pe' => 15.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'commodity'], // Mines deplete, highly cyclical
        'Grocery Stores' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'], // Very safe debt profile
        'Healthcare Plans' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Managed care
        'Health Information Services' => ['pe' => 24.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Home Improvement Retail' => ['pe' => 20.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Household & Personal Products' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'consumer_staples'], // P&G, Colgate. Premium P/E.
        'Industrial Distribution' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Information Technology Services' => ['pe' => 24.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'tech'], // Asset light
        'Insurance Brokers' => ['pe' => 22.00, 'depreciation' => 0.02, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'brokerage'], // Asset light fee business
        'Insurance - Diversified' => ['pe' => 12.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 7.0, 'business_model' => 'insurance'],
        'Insurance - Life' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'insurance'],
        'Insurance - Property & Casualty' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 6.0, 'business_model' => 'insurance'], // P&C is riskier, needs more equity buffer
        'Insurance - Reinsurance' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 5.0, 'business_model' => 'insurance'], // Taking the riskiest policies, highest capital requirements
        'Insurance - Specialty' => ['pe' => 13.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 6.0, 'business_model' => 'insurance'],
        'Integrated Freight & Logistics' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // UPS, FedEx. Heavy CapEx.
        'Internet Content & Information' => ['pe' => 25.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'tech'], // Alphabet, Meta. Asset light.
        'Internet Retail' => ['pe' => 28.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Amazon. Logistics heavy.
        'Leisure' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Theme parks, cruises. Collateralized debt.
        'Lodging' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Hotels. Real estate backed.
        'Luxury Goods' => ['pe' => 24.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // High margin, brand value.
        'Marine Shipping' => ['pe' => 9.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Extremely cyclical, rusts fast.
        'Medical Care Facilities' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'consumer_staples'], // Hospitals. Stable cash flow, heavy assets.
        'Medical Devices' => ['pe' => 25.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Medical Distribution' => ['pe' => 14.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Razor thin margins, high volume.
        'Medical Instruments & Supplies' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Metal Fabrication' => ['pe' => 13.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Mortgage Finance' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'shadow_bank'], // Shadow banks / Fannie Mae. Bank Rule.
        'Oil & Gas E&P' => ['pe' => 11.00, 'depreciation' => 0.12, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'commodity'], // Exploration. Wells deplete incredibly fast.
        'Oil & Gas Equipment & Services' => ['pe' => 14.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'commodity'], // Cyclical services.
        'Oil & Gas Integrated' => ['pe' => 13.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'commodity'], // Exxon/Chevron. Safer than E&P.
        'Oil & Gas Midstream' => ['pe' => 14.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'commodity'], // Pipelines. "Toll roads", highly stable.
        'Oil & Gas Refining & Marketing' => ['pe' => 11.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.5, 'business_model' => 'commodity'], // Crack spreads are volatile.
        'Packaged Foods' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'], // Extremely defensive.
        'Packaging & Containers' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Personal Services' => ['pe' => 18.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Pollution & Treatment Controls' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // ESG premium, stable.
        'Private Equity' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 3.0, 'business_model' => 'private_equity'], // Hostile takeovers, leveraged buyouts
        'Publishing' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Secular decline.
        'Railroads' => ['pe' => 19.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Wide moat monopoly pricing.
        'Real Estate - Development' => ['pe' => 13.00, 'depreciation' => 0.03, 'ebitda_limit' => 5.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Boom and bust, heavily levered.
        'Real Estate Services' => ['pe' => 18.00, 'depreciation' => 0.02, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Asset light brokerages! No huge debt allowed.
        'Recreational Vehicles' => ['pe' => 11.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Highly discretionary, first to drop in recession.
        'REIT - Diversified' => ['pe' => 16.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'],
        'REIT - Healthcare Facilities' => ['pe' => 15.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Very stable
        'REIT - Hotel & Motel' => ['pe' => 13.00, 'depreciation' => 0.04, 'ebitda_limit' => 5.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Highly cyclical, less debt allowed
        'REIT - Industrial' => ['pe' => 18.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Warehouses (Amazon effect), premium P/E
        'REIT - Mortgage' => ['pe' => 10.00, 'depreciation' => 0.01, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'shadow_bank'], // Pure financial engineering (Bank Rule)
        'REIT - Office' => ['pe' => 12.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.0, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Work-from-home headwinds
        'REIT - Residential' => ['pe' => 17.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Apartments, highly resilient
        'REIT - Retail' => ['pe' => 14.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.0, 'equity_limit' => 2.0, 'business_model' => 'reit'], // Malls, e-commerce risk
        'REIT - Specialty' => ['pe' => 16.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'reit'], // Data centers, cell towers
        'Rental & Leasing Services' => ['pe' => 15.00, 'depreciation' => 0.10, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Rental cars depreciate fast
        'Residential Construction' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Homebuilders. Highly cyclical.
        'Resorts & Casinos' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'none'], // Collateralized by prime real estate
        'Restaurants' => ['pe' => 20.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Franchise models support decent debt
        'Scientific & Technical Instruments' => ['pe' => 26.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // High margin, IP heavy
        'Security & Protection Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'defense_contractor'],
        'Semiconductor Equipment & Materials' => ['pe' => 22.00, 'depreciation' => 0.12, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // ASML etc. Boom and bust.
        'Semiconductors' => ['pe' => 24.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Fab plants age like milk
        'Software - Application' => ['pe' => 28.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'tech'], // Asset light, pure IP, high growth
        'Software - Infrastructure' => ['pe' => 26.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'tech'], // Sticky revenues (Microsoft, Oracle)
        'Solar' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Capital intensive manufacturing
        'Specialty Business Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Specialty Chemicals' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Specialty Industrial Machinery' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Specialty Retail' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Staffing & Employment Services' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Pure human capital. No hard assets.
        'Steel' => ['pe' => 10.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'commodity'], // Brutally cyclical commodity
        'Telecom Services' => ['pe' => 14.00, 'depreciation' => 0.10, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'utility'], // Massive CAPEX, but incredibly stable utility-like cash flows
        'Tobacco' => ['pe' => 11.00, 'depreciation' => 0.04, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'], // Secular volume decline, massive cash flows, huge debt capacity
        'Tools & Accessories' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Travel Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Expedia, Booking.com. Asset light.
        'Trucking' => ['pe' => 14.00, 'depreciation' => 0.12, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Fixed the 45 P/E error. Trucks rust.
        'Utilities - Diversified' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'utility'], // Regulated monopolies
        'Utilities - Regulated Electric' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'utility'],
        'Utilities - Regulated Gas' => ['pe' => 15.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'utility'],
        'Utilities - Regulated Water' => ['pe' => 18.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'utility'], // Safest asset class on earth
        'Waste Management' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'utility'], // Trash is cash. High P/E.
        'General' => ['pe' => 18.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
    ];

    public const BUSINESS_MODEL_THRESHOLDS = [
        'commercial_bank' => ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'insurance'       => ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'brokerage'       => ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => null, 'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'asset_manager'   => ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => 0.5,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'credit_services' => ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'private_equity'  => ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => null, 'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'shadow_bank'     => ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => null, 'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'reit'            => ['min_icr' => 1.05, 'bankrupt_equity' => 10.0, 'distress_equity' => 20.0, 'warning_equity' => 30.0, 'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'utility'         => ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00],
        'commodity'       => ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00],
        'financial_data'  => ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00],
        'tech'            => ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00],
        'consumer_staples' => ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00],
        'defense_contractor' => ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00],
        'clearing_house'  => ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15],
        'none'            => ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00],
    ];

    /**
     * Retrieves the structural thresholds for a specific business model.
     *
     * @param string $businessModel
     * @return array<string, mixed>
     */
    public static function getModelThresholds(string $businessModel): array
    {
        return self::BUSINESS_MODEL_THRESHOLDS[$businessModel] ?? self::BUSINESS_MODEL_THRESHOLDS['none'];
    }

    /**
     * Helper to determine if a business model belongs to a financial institution.
     *
     * @param string $businessModel
     * @return bool
     */
    public static function isFinancial(string $businessModel): bool
    {
        return in_array($businessModel, ['commercial_bank', 'insurance', 'brokerage', 'asset_manager', 'credit_services', 'shadow_bank', 'private_equity', 'clearing_house']);
    }

    /**
     * Factory method to retrieve the financial physics model for a given business type.
     *
     * @param string $businessModel
     * @return \App\Service\Model\BusinessModelInterface
     */
    public static function getBusinessModelStrategy(string $businessModel): \App\Service\Model\BusinessModelInterface
    {
        return match ($businessModel) {
            'commercial_bank' => new \App\Service\Model\CommercialBankBusinessModel(),
            'insurance'       => new \App\Service\Model\InsuranceBusinessModel(),
            'brokerage'       => new \App\Service\Model\BrokerageBusinessModel(),
            'asset_manager'   => new \App\Service\Model\AssetManagementBusinessModel(),
            'credit_services' => new \App\Service\Model\CreditServicesBusinessModel(),
            'private_equity'  => new \App\Service\Model\PrivateEquityBusinessModel(),
            'shadow_bank'     => new \App\Service\Model\ShadowBankBusinessModel(),
            'reit'            => new \App\Service\Model\ReitBusinessModel(),
            'clearing_house'  => new \App\Service\Model\ClearingHouseBusinessModel(),
            'utility'         => new \App\Service\Model\UtilityBusinessModel(),
            'commodity'       => new \App\Service\Model\CommodityBusinessModel(),
            'financial_data'  => new \App\Service\Model\FinancialDataBusinessModel(),
            'tech'            => new \App\Service\Model\TechBusinessModel(),
            'consumer_staples' => new \App\Service\Model\ConsumerStaplesBusinessModel(),
            'defense_contractor' => new \App\Service\Model\DefenseContractorBusinessModel(),
            default           => new \App\Service\Model\StandardCorporateBusinessModel(),
        };
    }
}

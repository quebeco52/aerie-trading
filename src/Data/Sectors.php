<?php

namespace App\Data;

class Sectors
{
    public const MACRO_SECTORS = [
        'Information Technology' => 24.0, // High growth, high premium
        'Financials' => 14.0,             // Banks, Brokerages, Investment Banks, Insurance
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
        'consumer_staples' => 'Produces essential goods and non-cyclical services (Food, Tobacco, Household, Discount Stores). Features inelastic demand (very low volatility) and high pricing power, allowing them to completely ignore supply chain inflation penalties.',
        'medical_care_facility' => 'Hospital networks and healthcare systems operating a tri-stream engine: inelastic inpatient care, high-margin elective outpatient surgeries, and algorithmic insurance billing arbitrage. Immune to recessions, but exposed to clinical wage inflation during high inflation regimes.',
        'defense_contractor' => 'Operates on sovereign government "Cost-Plus" procurement contracts (Aerospace & Defense). Highly immune to recessions with guaranteed margin cost escalators.',
        'security_protection' => 'Private military contractors and executive protection. Tri-stream revenue engine: sovereign cost-plus contracts, sticky corporate campus retainers, and counter-cyclical expeditionary black-ops that thrive on market panic (high VIX) and credit distress. Carries asymmetric tail risk from publicized tactical breaches.',
        'clearing_house'  => 'Acts as the ultimate guarantor of all market trades. Holds massive "Initial Margin" deposits from member firms, earning interest on the float. Revenue scales off transaction volume, thriving during market panics (high VIX). Carries extreme apocalyptic tail risk if member defaults exceed the margin pool. Evaluated on ROE.',
        'biotech'         => 'Biotechnology and specialty pharma. CapEx is intangible R&D IP. Thrives on blockbuster drug super-cycles when R&D reinvestment is strong, but suffers rapid patent cliff amortization and generic margin erosion when R&D lags. Inelastic demand during recessions.',
        'luxury'          => 'Luxury goods and elite brand conglomerates. Possesses Veblen pricing power: immune to inflation penalties, raising prices without volume loss. Very high operating leverage and brand equity, but sensitive to global liquidity freezes among ultra-wealthy buyers.',
        'shipping'        => 'Global marine shipping and freight logistics. Hyper-cyclical spot-rate operational leverage driven violently by global trade and the macro output gap. High fixed fleet maintenance costs cause massive free cash flow booms during expansions and capacity glut losses during contractions.',
        'semiconductor'   => 'Semiconductor foundries and photolithography equipment manufacturers ("Shovel Makers"). Extreme capital intensity and non-linear fab utilization leverage. Running cleanrooms at 100% capacity generates staggering ROIC, but underutilization creates severe margin drag.',
        'investment_bank' => 'Pure-play investment banks and M&A advisory syndicates. Pro-cyclical M&A and IPO deal flow explode during expansions, while proprietary market-making desks print billions in volatility arbitrage during market crashes or VIX spikes. Evaluated on ROE.',
        'distressed_debt' => 'Distressed debt and turnaround asset managers. Counter-cyclical special situations: hoards dry powder during bull markets, then deploys capital aggressively when macro credit spreads blow out or defaults spike, generating massive turnaround ROE.',
        'heavy_manufacturing' => 'Asset-heavy industrials with extreme operating leverage and cyclicality.',
        'auto_manufacturer' => 'Extremely capital-intensive with massive fixed costs. Highly sensitive to the macro output gap and consumer interest rates, as most vehicles are financed. Margins compress violently during recessions or rate hikes.',
        'specialty_industrial_machinery' => 'Specialized heavy machinery with sticky aftermarket services. Features a wider economic moat and less macro sensitivity than raw heavy manufacturing, producing both highly cyclical equipment sales and stable service revenue.',
        'tools_and_accessories' => 'Precision tooling and hardware. Premium B2B sales act as a high-margin, sticky industrial tollbooth. Rejects are liquidated into the retail secondary market as a volatile, cyclical stream.',
        'computer_hardware' => 'Physical technology manufacturing. Fast depreciation and inventory obsolescence create high baseline variance. Split between highly volatile B2C consumer electronics and stickier, high-margin B2B enterprise sales.',
        'internet_retail' => 'Digital retail apex predators. Extremely high macro sensitivity and inflation penalties due to massive fulfillment networks. Blends asset-light, high-margin third-party marketplace fees with volatile, logistics-heavy first-party retail.',
        'restaurant'      => 'Operates a mix of corporate-owned and franchised locations. Corporate stores have high revenue and high fixed costs. Franchise operations generate low revenue but near 100% margin royalty streams. Highly sensitive to consumer discretionary spending and input inflation.',
        'resorts_casinos' => 'Integrated resorts and casinos. Dual-stream engine: high-margin gaming (subject to volatile "Whale Luck") and sticky non-gaming conventions. Highly elastic to consumer sentiment, suffering promotional margin drag during downturns.',
        'law_firm'        => 'Asset-light, human-capital intensive corporate legal advisory and litigation practice. Dual-stream revenue engine blends defensive, high-margin corporate retainers and advisory fees with highly volatile, lumpy contingency payouts from blockbuster litigation settlements. Features minimal CapEx requirements, rapid free cash flow conversion, and strong macro cycle resilience.',
        'advertising_agency' => 'Asset light, human-capital intensive. Revenue driven by steady long-term corporate retainers and counter-cyclical crisis management mandates.',
        'education'       => 'Revenue is a mix of highly sticky, guaranteed corporate/government subsidies and highly cyclical talent placement fees.',
        'conglomerate'    => 'A mix of diverse, unrelated business lines (industrial manufacturing, consumer products, financial investments) providing incredibly low baseline variance.',
        'logistics'       => 'Extremely sensitive to global GDP. Mix of high-volume parcel shipping and highly lucrative algorithmic surge pricing.',
        'railroad'        => 'Highly capital intensive with monopoly pricing. Revenue is a mix of sticky commuter passes, volatile walk-up tickets, and cyclical real estate monetization.',
        'steel_manufacturing' => 'Brutally cyclical and capital-intensive. Mix of stable domestic supply contracts and volatile export dumping.',
        'construction'    => 'Operates on massive, multi-year timelines. Mix of sticky infrastructure contracts and highly cyclical private development.',
        'retail_insurance' => 'Insulates tail-risk via reinsurance. Split between short-tail Property & Casualty premiums and long-duration Life Insurance premiums.',
        'reinsurance'     => 'Extremely lumpy and catastrophic tail-risk. Revenue is split between core reinsurance premiums and high-yield catastrophe bonds.',
        'waste_management' => 'Operates localized oligopolies with immense pricing power due to landfill permitting moats. Revenues mix hyper-sticky residential collection with cyclical commercial streams. Inflation hedge via municipal contract CPI escalators.',
        'telecom'         => 'High barriers to entry with revenue dominated by sticky recurring subscriptions. Subject to margin-crushing price wars for market share. Astronomical debt loads create high sensitivity to 10-year Treasury yields.',
        'none'            => 'Standard corporate physics. Evaluated on Return on Invested Capital (ROIC). Subject to physical depreciation and supply chain inflation penalties when costs rise faster than pricing power. Idiosyncratic variance applies directly to sales volume.',
    ];

    public const INDUSTRY_METRICS = [
        'Advertising Agencies' => ['pe' => 16.50, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'advertising_agency'],
        'Aerospace & Defense' => ['pe' => 22.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'defense_contractor'],
        'Agricultural Inputs' => ['pe' => 15.00, 'depreciation' => 0.07, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Airlines' => ['pe' => 10.00, 'depreciation' => 0.07, 'ebitda_limit' => 3.5, 'equity_limit' => 2.0, 'business_model' => 'none'],
        'Aluminum' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'commodity'],
        'Apparel Manufacturing' => ['pe' => 16.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Apparel Retail' => ['pe' => 18.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Asset Management' => ['pe' => 15.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'asset_manager'],
        'Auto Manufacturers' => ['pe' => 8.50, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 2.0, 'business_model' => 'auto_manufacturer'],
        'Auto Parts' => ['pe' => 14.00, 'depreciation' => 0.07, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'heavy_manufacturing'],
        'Auto & Truck Dealerships' => ['pe' => 12.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Floorplan debt allows high limits
        'Banks - Diversified' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 10.0, 'business_model' => 'commercial_bank'],
        'Banks - Regional' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 9.0, 'business_model' => 'commercial_bank'], // Higher risk of localized deposit flight
        'Beverages - Brewers' => ['pe' => 18.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'],
        'Beverages - Non-Alcoholic' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'consumer_staples'], // Massive brand moat
        'Beverages - Wineries & Distilleries' => ['pe' => 20.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'],
        'Biotechnology' => ['pe' => 15.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'biotech'], // R&D driven
        'Brokerages' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'brokerage'], // Prime brokers, market makers, prop shops
        'Building Materials' => ['pe' => 15.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'heavy_manufacturing'],
        'Building Products & Equipment' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'heavy_manufacturing'],
        'Business Equipment & Supplies' => ['pe' => 12.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Chemicals' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'heavy_manufacturing'],
        'Communication Equipment' => ['pe' => 18.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'],
        'Computer Hardware' => ['pe' => 15.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'computer_hardware'],
        'Conglomerates' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'conglomerate'],
        'Consulting Services' => ['pe' => 22.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Almost entirely human capital
        'Copper' => ['pe' => 12.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'commodity'], // Asset heavy, cyclical mining
        'Credit Services' => ['pe' => 15.00, 'depreciation' => 0.05, 'ebitda_limit' => 999.0, 'equity_limit' => 7.0, 'business_model' => 'credit_services'], // Amex, Discover. Unsecured lending & swipe fees.
        'Diagnostics & Research' => ['pe' => 24.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'],
        'Discount Stores' => ['pe' => 20.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'], // Very safe, steady cash flows
        'Distressed Debt' => ['pe' => 12.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'distressed_debt'], // Turnaround funds & special situations
        'Drug Manufacturers - General' => ['pe' => 16.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'biotech'], // Big Pharma
        'Drug Manufacturers - Specialty & Generic' => ['pe' => 14.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'biotech'],
        'Education & Training Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'education'],
        'Electrical Equipment & Parts' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'heavy_manufacturing'],
        'Electronic Components' => ['pe' => 16.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Hardware gets obsolete fast
        'Electronic Gaming & Multimedia' => ['pe' => 22.00, 'depreciation' => 0.10, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Hit-driven, no debt allowed
        'Electronics & Computer Distribution' => ['pe' => 14.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Engineering & Construction' => ['pe' => 14.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'construction'], // Highly cyclical
        'Entertainment' => ['pe' => 20.00, 'depreciation' => 0.10, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'none'], // Media assets/parks support debt
        'Farm & Heavy Construction Machinery' => ['pe' => 15.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'heavy_manufacturing'],
        'Farm Products' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'consumer_staples'],
        'Financial Data & Stock Exchanges' => ['pe' => 26.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'financial_data'], // Monopolies, high P/E
        'Financial Clearinghouses' => ['pe' => 18.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 20.0, 'business_model' => 'clearing_house'],
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
        'Investment Banking' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'investment_bank'], // Pure-play investment banks and M&A syndicates
        'Insurance Brokers' => ['pe' => 22.00, 'depreciation' => 0.02, 'ebitda_limit' => 3.5, 'equity_limit' => 1.0, 'business_model' => 'brokerage'], // Asset light fee business
        'Insurance - Diversified' => ['pe' => 12.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 7.0, 'business_model' => 'insurance'],
        'Insurance - Life' => ['pe' => 10.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 8.0, 'business_model' => 'retail_insurance'],
        'Insurance - Property & Casualty' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 6.0, 'business_model' => 'retail_insurance'], // P&C is riskier, needs more equity buffer
        'Insurance - Reinsurance' => ['pe' => 11.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 5.0, 'business_model' => 'reinsurance'], // Taking the riskiest policies, highest capital requirements
        'Insurance - Specialty' => ['pe' => 13.00, 'depreciation' => 0.02, 'ebitda_limit' => 999.0, 'equity_limit' => 6.0, 'business_model' => 'insurance'],
        'Integrated Freight & Logistics' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'logistics'], // UPS, FedEx. Heavy CapEx.
        'Internet Content & Information' => ['pe' => 25.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'tech'], // Alphabet, Meta. Asset light.
        'Internet Retail' => ['pe' => 28.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'internet_retail'], // Amazon. Logistics heavy.
        'Legal Services' => ['pe' => 18.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'law_firm'], // Asset light human capital, corporate retainers & settlements
        'Leisure' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Theme parks, cruises. Collateralized debt.
        'Lodging' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Hotels. Real estate backed.
        'Luxury Goods' => ['pe' => 24.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'luxury'], // High margin, brand value.
        'Marine Shipping' => ['pe' => 9.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'shipping'], // Extremely cyclical, rusts fast.
        'Medical Care Facilities' => ['pe' => 15.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'medical_care_facility'], // Hospitals. Stable cash flow, heavy assets.
        'Medical Devices' => ['pe' => 25.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Medical Distribution' => ['pe' => 15.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Low margin volume business
        'Medical Instruments & Supplies' => ['pe' => 24.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Metal Fabrication' => ['pe' => 13.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'heavy_manufacturing'],
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
        'Railroads' => ['pe' => 19.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'railroad'], // Wide moat monopoly pricing.
        'Real Estate - Development' => ['pe' => 13.00, 'depreciation' => 0.03, 'ebitda_limit' => 5.0, 'equity_limit' => 2.0, 'business_model' => 'none'], // Boom and bust, heavily levered.
        'Real Estate Services' => ['pe' => 18.00, 'depreciation' => 0.02, 'ebitda_limit' => 2.5, 'equity_limit' => 0.5, 'business_model' => 'none'], // Asset light brokerages! No huge debt allowed.
        'Recreational Vehicles' => ['pe' => 11.00, 'depreciation' => 0.05, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'heavy_manufacturing'], // Highly discretionary, first to drop in recession.
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
        'Resorts & Casinos' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'resorts_casinos'], // Collateralized by prime real estate
        'Restaurants' => ['pe' => 20.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.5, 'equity_limit' => 1.5, 'business_model' => 'restaurant'], // Mix of corporate and franchise
        'Scientific & Technical Instruments' => ['pe' => 26.00, 'depreciation' => 0.08, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // High margin, IP heavy
        'Security & Protection Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'security_protection'],
        'Semiconductor Equipment & Materials' => ['pe' => 22.00, 'depreciation' => 0.12, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'semiconductor'], // ASML etc. Boom and bust.
        'Semiconductors' => ['pe' => 24.00, 'depreciation' => 0.15, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'semiconductor'], // Fab plants age like milk
        'Software - Application' => ['pe' => 28.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'tech'], // Asset light, pure IP, high growth
        'Software - Infrastructure' => ['pe' => 26.00, 'depreciation' => 0.03, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'tech'], // Sticky revenues (Microsoft, Oracle)
        'Solar' => ['pe' => 18.00, 'depreciation' => 0.08, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'], // Capital intensive manufacturing
        'Specialty Business Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Specialty Chemicals' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'heavy_manufacturing'],
        'Specialty Industrial Machinery' => ['pe' => 18.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'specialty_industrial_machinery'],
        'Specialty Retail' => ['pe' => 16.00, 'depreciation' => 0.06, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'],
        'Staffing & Employment Services' => ['pe' => 14.00, 'depreciation' => 0.02, 'ebitda_limit' => 2.0, 'equity_limit' => 0.5, 'business_model' => 'none'], // Pure human capital. No hard assets.
        'Steel' => ['pe' => 10.00, 'depreciation' => 0.06, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'steel_manufacturing'], // Brutally cyclical commodity
        'Telecom Services' => ['pe' => 14.00, 'depreciation' => 0.10, 'ebitda_limit' => 4.5, 'equity_limit' => 2.0, 'business_model' => 'telecom'], // Massive CAPEX, but incredibly stable utility-like cash flows
        'Tobacco' => ['pe' => 11.00, 'depreciation' => 0.04, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'consumer_staples'], // Secular volume decline, massive cash flows, huge debt capacity
        'Tools & Accessories' => ['pe' => 16.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'tools_and_accessories'],
        'Travel Services' => ['pe' => 18.00, 'depreciation' => 0.04, 'ebitda_limit' => 2.5, 'equity_limit' => 1.0, 'business_model' => 'none'], // Expedia, Booking.com. Asset light.
        'Trucking' => ['pe' => 14.00, 'depreciation' => 0.12, 'ebitda_limit' => 3.0, 'equity_limit' => 1.5, 'business_model' => 'none'], // Fixed the 45 P/E error. Trucks rust.
        'Utilities - Diversified' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'utility'], // Regulated monopolies
        'Utilities - Regulated Electric' => ['pe' => 16.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'utility'],
        'Utilities - Regulated Gas' => ['pe' => 15.00, 'depreciation' => 0.04, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'utility'],
        'Utilities - Regulated Water' => ['pe' => 18.00, 'depreciation' => 0.03, 'ebitda_limit' => 6.5, 'equity_limit' => 2.5, 'business_model' => 'utility'], // Safest asset class on earth
        'Waste Management' => ['pe' => 22.00, 'depreciation' => 0.05, 'ebitda_limit' => 4.0, 'equity_limit' => 1.5, 'business_model' => 'waste_management'], // Trash is cash. High P/E.
        'General' => ['pe' => 18.00, 'depreciation' => 0.05, 'ebitda_limit' => 3.0, 'equity_limit' => 1.0, 'business_model' => 'none'],
    ];

    /**
     * Helper to determine if a business model belongs to a financial institution.
     *
     * @param string $businessModel
     * @return bool
     */
    public static function isFinancial(string $businessModel): bool
    {
        return in_array($businessModel, ['commercial_bank', 'insurance', 'retail_insurance', 'reinsurance', 'brokerage', 'asset_manager', 'credit_services', 'shadow_bank', 'private_equity', 'clearing_house', 'investment_bank', 'distressed_debt']);
    }

    private static array $strategyInstances = [];
    private static ?\App\Service\Model\BusinessModelRegistryInterface $registry = null;

    public static function setBusinessModelRegistry(?\App\Service\Model\BusinessModelRegistryInterface $registry): void
    {
        self::$registry = $registry;
    }

    /**
     * Factory method to retrieve the financial physics model for a given business type.
     *
     * @param string $businessModel
     * @return \App\Service\Model\BusinessModelInterface
     */
    public static function getBusinessModelStrategy(string $businessModel): \App\Service\Model\BusinessModelInterface
    {
        if (self::$registry !== null) {
            return self::$registry->get($businessModel);
        }

        if (isset(self::$strategyInstances[$businessModel])) {
            return self::$strategyInstances[$businessModel];
        }

        $strategy = match ($businessModel) {
            'commercial_bank' => new \App\Service\Model\CommercialBankBusinessModel(),
            'insurance'       => new \App\Service\Model\InsuranceBusinessModel(),
            'brokerage'       => new \App\Service\Model\BrokerageBusinessModel(),
            'resorts_casinos' => new \App\Service\Model\ResortsCasinosBusinessModel(),
            'asset_manager'   => new \App\Service\Model\AssetManagementBusinessModel(),
            'credit_services' => new \App\Service\Model\CreditServicesBusinessModel(),
            'private_equity'  => new \App\Service\Model\PrivateEquityBusinessModel(),
            'shadow_bank'     => new \App\Service\Model\ShadowBankBusinessModel(),
            'reit'            => new \App\Service\Model\ReitBusinessModel(),
            'clearing_house'  => new \App\Service\Model\ClearingHouseBusinessModel(),
            'utility'         => new \App\Service\Model\UtilityBusinessModel(),
            'commodity'       => new \App\Service\Model\CommodityBusinessModel(),
            'financial_data'  => new \App\Service\Model\FinancialDataBusinessModel(),
            'medical_care_facility' => new \App\Service\Model\MedicalCareFacilityBusinessModel(),
            'tech'            => new \App\Service\Model\TechBusinessModel(),
            'consumer_staples' => new \App\Service\Model\ConsumerStaplesBusinessModel(),
            'defense_contractor' => new \App\Service\Model\DefenseContractorBusinessModel(),
            'security_protection' => new \App\Service\Model\SecurityProtectionBusinessModel(),
            'biotech'         => new \App\Service\Model\BiotechBusinessModel(),
            'luxury'          => new \App\Service\Model\LuxuryBusinessModel(),
            'shipping'        => new \App\Service\Model\ShippingBusinessModel(),
            'semiconductor'   => new \App\Service\Model\SemiconductorBusinessModel(),
            'investment_bank' => new \App\Service\Model\InvestmentBankBusinessModel(),
            'distressed_debt' => new \App\Service\Model\DistressedDebtBusinessModel(),
            'heavy_manufacturing' => new \App\Service\Model\HeavyManufacturingBusinessModel(),
            'auto_manufacturer' => new \App\Service\Model\AutoManufacturerBusinessModel(),
            'specialty_industrial_machinery' => new \App\Service\Model\SpecialtyIndustrialMachineryBusinessModel(),
            'tools_and_accessories' => new \App\Service\Model\ToolsAndAccessoriesBusinessModel(),
            'computer_hardware' => new \App\Service\Model\ComputerHardwareBusinessModel(),
            'internet_retail' => new \App\Service\Model\InternetRetailBusinessModel(),
            'restaurant'      => new \App\Service\Model\RestaurantBusinessModel(),
            'law_firm'        => new \App\Service\Model\LawFirmBusinessModel(),
            'advertising_agency' => new \App\Service\Model\AdvertisingAgencyBusinessModel(),
            'education'       => new \App\Service\Model\EducationBusinessModel(),
            'conglomerate'    => new \App\Service\Model\ConglomerateBusinessModel(),
            'logistics'       => new \App\Service\Model\LogisticsBusinessModel(),
            'railroad'        => new \App\Service\Model\RailroadBusinessModel(),
            'steel_manufacturing' => new \App\Service\Model\SteelManufacturingBusinessModel(),
            'construction'    => new \App\Service\Model\ConstructionBusinessModel(),
            'retail_insurance' => new \App\Service\Model\RetailInsuranceBusinessModel(),
            'reinsurance'     => new \App\Service\Model\ReinsuranceBusinessModel(),
            'waste_management' => new \App\Service\Model\WasteManagementBusinessModel(),
            'telecom'         => new \App\Service\Model\TelecomBusinessModel(),
            default           => new \App\Service\Model\StandardCorporateBusinessModel(),
        };

        $strategy->setModelIdentifier($businessModel);

        self::$strategyInstances[$businessModel] = $strategy;
        return $strategy;
    }
}

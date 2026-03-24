<?php

namespace App\Data;

class InitialMarket
{
    public const STOCKS = [
        [
            'ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'sector' => 'Financials',
            'price' => 1500.00, 'eps' => 37.42, 
            'shares_outstanding' => 1000000000,
            // Banks: Moderate vol, occasional negative shocks from credit fears
            'volatility' => 0.18, 'beta' => 1.15, 'jump_intensity' => 0.75, 'jump_mean' => -0.02, 'jump_vol' => 0.06
        ],
        [
            'ticker' => 'SWAN', 'name' => 'Black Swan Capital', 'sector' => 'Financials',
            'price' => 1350.00, 'eps' => 28.63,
            'shares_outstanding' => 1000000000,
            // Asset Management: Aggressive activist fund. High beta due to leveraged AUM, jumps frequently on hostile takeover news
            'volatility' => 0.24, 'beta' => 1.35, 'jump_intensity' => 1.25, 'jump_mean' => 0.02, 'jump_vol' => 0.10
        ],
        [
            'ticker' => 'HUMM', 'name' => 'Hummingbird Interactive', 'sector' => 'Information Technology',
            'price' => 550.00, 'eps' => 19.14, 
            'shares_outstanding' => 1000000000,
            // Mega Cap Tech: High beta because it drives the market, but structurally lower volatility than small cap tech due to its massive monopoly moat
            'volatility' => 0.22, 'beta' => 1.25, 'jump_intensity' => 0.75, 'jump_mean' => -0.02, 'jump_vol' => 0.06
        ],
        [
            'ticker' => 'OWLS', 'name' => 'Owl Capital Partners', 'sector' => 'Financials',
            'price' => 480.00, 'eps' => 20.15, 
            'shares_outstanding' => 1000000000,
            // Value Holding Company: Hoards cash, very low beta, buys the dip
            'volatility' => 0.15, 'beta' => 0.85, 'jump_intensity' => 0.20, 'jump_mean' => 0.01, 'jump_vol' => 0.04
        ],
        [
            'ticker' => 'KING', 'name' => 'Kingfisher Capital', 'sector' => 'Financials',
            'price' => 450.00, 'eps' => 14.61, 
            'shares_outstanding' => 1000000000,
            // Investment Banking: Highly leveraged. When the market bleeds banks hemorrhage. Highest beta
            'volatility' => 0.28, 'beta' => 1.55, 'jump_intensity' => 1.25, 'jump_mean' => -0.04, 'jump_vol' => 0.12
        ],
        [
            'ticker' => 'PERE', 'name' => 'Peregrine Prime Securities', 'sector' => 'Financials',
            'price' => 650.00, 'eps' => 14.02, 
            'shares_outstanding' => 1000000000,
            // The Volatility Harvester: negative beta, highly defensive, occasionally spikes during macroeconomic panics
            'volatility' => 0.18, 'beta' => -0.20, 'jump_intensity' => 1.25, 'jump_mean' => 0.05, 'jump_vol' => 0.12 
        ],
        [
            'ticker' => 'RIVR', 'name' => 'Riverstone Financial', 'sector' => 'Financials',
            'price' => 115.00, 'eps' => 3.16, 
            'shares_outstanding' => 1000000000,
            // Regional Bank: Less diversified than LAKE. Highly sensitive to local commercial real estate
            'volatility' => 0.22, 'beta' => 1.20, 'jump_intensity' => 0.90, 'jump_mean' => -0.03, 'jump_vol' => 0.08
        ],
        [
            'ticker' => 'SAFE', 'name' => 'Safe Harbor Reinsurance', 'sector' => 'Financials',
            'price' => 270.00, 'eps' => 8.91, 
            'shares_outstanding' => 1000000000,
            // Reinsurance: Almost entirely untethered to the stock market. Driven by natural disasters
            'volatility' => 0.10, 'beta' => 0.15, 'jump_intensity' => 0.10, 'jump_mean' => -0.15, 'jump_vol' => 0.20
        ],
        [
            'ticker' => 'DOVE', 'name' => 'White Dove Insurance', 'sector' => 'Financials',
            'price' => 160.00, 'eps' => 7.26, 
            'shares_outstanding' => 1000000000,
            // Standard Insurance: Very steady, defensive. Under reacts to tech booms and busts
            'volatility' => 0.17, 'beta' => 0.85, 'jump_intensity' => 0.50, 'jump_mean' => -0.03, 'jump_vol' => 0.06
        ],
        [
            'ticker' => 'SHRK', 'name' => 'Shrike Standard Ratings', 'sector' => 'Financials',
            'price' => 210.00, 'eps' => 3.87, 
            'shares_outstanding' => 1000000000,
            // Credit Agency: An untouchable monopoly. Causes volatility in others but experiences almost none itself
            'volatility' => 0.15, 'beta' => 0.60, 'jump_intensity' => 0.40, 'jump_mean' => 0.01, 'jump_vol' => 0.04
        ],
        [
            'ticker' => 'BIRD', 'name' => 'Bird Power Inc', 'sector' => 'Utilities',
            'price' => 150.00, 'eps' => 3.00, 
            'shares_outstanding' => 1000000000,
            // Utilities: Very low volatility, rare jumps. Defensive, low correlation to macro swings
            'volatility' => 0.10, 'beta' => 0.30, 'jump_intensity' => 0.30, 'jump_mean' => 0.00, 'jump_vol' => 0.04
        ],
        [
            'ticker' => 'WATCH', 'name' => 'Bird Watch Security', 'sector' => 'Industrials',
            'price' => 180.00, 'eps' => 1.09, 
            'shares_outstanding' => 1000000000,
            // Security: Stable, service based, sticky contracts. Slightly defensive
            'volatility' => 0.20, 'beta' => 0.90, 'jump_intensity' => 0.60, 'jump_mean' => -0.04, 'jump_vol' => 0.05
        ],
        [
            'ticker' => 'WING', 'name' => 'Steel Wings Smelting & Corp', 'sector' => 'Materials',
            'price' => 120.00, 'eps' => 1.68, 
            'shares_outstanding' => 1000000000,
            // Steel Commodities: Highly cyclical, sensitive to global trade
            'volatility' => 0.32, 'beta' => 1.10, 'jump_intensity' => 1.00, 'jump_mean' => -0.02, 'jump_vol' => 0.10
        ],
        [
            'ticker' => 'PENG', 'name' => 'Penguin Computing', 'sector' => 'Information Technology',
            'price' => 145.00, 'eps' => 3.82, 
            'shares_outstanding' => 1000000000,
            // Tech Hardware: High volatility, aggressive growth stock. Swings hard with market sentiment
            'volatility' => 0.32, 'beta' => 1.45, 'jump_intensity' => 1.25, 'jump_mean' => 0.04, 'jump_vol' => 0.15
        ],
        [
            'ticker' => 'SHOR', 'name' => 'Lakeshore Living', 'sector' => 'Real Estate',
            'price' => 125.00, 'eps' => 3.36, 
            'shares_outstanding' => 1000000000,
            // Real Estate: Sensitive to interest rates, moderate vol, slightly more aggressive than average market
            'volatility' => 0.24, 'beta' => 1.20, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.07
        ],
        [
            'ticker' => 'RIVE', 'name' => 'River Stream Industries', 'sector' => 'Industrials',
            'price' => 250.00, 'eps' => 1.79, 
            'shares_outstanding' => 1000000000,
            // Industrials: Standard cyclical behavior, slightly above market neutral
            'volatility' => 0.26, 'beta' => 1.15, 'jump_intensity' => 0.90, 'jump_mean' => 0.00, 'jump_vol' => 0.08
        ],
        [
            'ticker' => 'GRIP', 'name' => 'Gryphon Defense Systems', 'sector' => 'Industrials',
            'price' => 155.00, 'eps' => 2.62, 
            'shares_outstanding' => 1000000000,
            // Defense: Steady gov contracts mean they often ignore broader market slumps. Low beta
            'volatility' => 0.20, 'beta' => 0.80, 'jump_intensity' => 0.75, 'jump_mean' => 0.02, 'jump_vol' => 0.07
        ],
        [
            'ticker' => 'LOON', 'name' => 'Loon Call Telecom', 'sector' => 'Communication Services',
            'price' => 190.00, 'eps' => 9.18, 
            'shares_outstanding' => 1000000000,
            // Telecom: Classic boring defensive stock. People pay phone bills even in recessions
            'volatility' => 0.16, 'beta' => 0.70, 'jump_intensity' => 0.40, 'jump_mean' => -0.01, 'jump_vol' => 0.05
        ],
        [
            'ticker' => 'PHIL', 'name' => 'Pheasant & Morris International', 'sector' => 'Consumer Staples',
            'price' => 190.00, 'eps' => 6.53, 
            'shares_outstanding' => 1000000000,
            // Tobacco: Addiction is immune to recessions. Low beta, uncorrelated, idiosyncratic litigation risk
            'volatility' => 0.15, 'beta' => 0.45, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.07
        ],
        [
            'ticker' => 'TRIV', 'name' => 'Three Rivers Manufacturing', 'sector' => 'Industrials',
            'price' => 180.00, 'eps' => 8.70, 
            'shares_outstanding' => 1000000000,
            // Conglomerate: Being massively diversified inherently pushes its beta toward the market average
            'volatility' => 0.20, 'beta' => 1.05, 'jump_intensity' => 0.75, 'jump_mean' => -0.02, 'jump_vol' => 0.07 
        ],
        [
            'ticker' => 'IBHI', 'name' => 'Iron Beak Heavy Ind', 'sector' => 'Industrials',
            'price' => 270.00, 'eps' => 7.60,
            'shares_outstanding' => 1000000000,
            // The Physical Architect: Aggressively scales with the macro economy, highly cyclical based on government and corporate infrastructure spending
            'volatility' => 0.28, 'beta' => 1.25, 'jump_intensity' => 0.90, 'jump_mean' => -0.01, 'jump_vol' => 0.09
        ],
        [
            'ticker' => 'TICK', 'name' => 'Tickbird Data Systems', 'sector' => 'Information Technology',
            'price' => 200.00, 'eps' => 6.46, 
            'shares_outstanding' => 1000000000,
            // Financial Data SaaS: Sticky revenue, but slightly aggressive tech multiples
            'volatility' => 0.22, 'beta' => 1.10, 'jump_intensity' => 1.00, 'jump_mean' => 0.02, 'jump_vol' => 0.06
        ],
        [
            'ticker' => 'SINK', 'name' => 'Sinking Shore Extraction', 'sector' => 'Energy',
            'price' => 120.00, 'eps' => 4.02, 
            'shares_outstanding' => 1000000000,
            // Oil Upstream: Hyper exposed to commodity spot prices
            'volatility' => 0.35, 'beta' => 0.95, 'jump_intensity' => 0.75, 'jump_mean' => -0.05, 'jump_vol' => 0.08
        ],
        [
            'ticker' => 'CASC', 'name' => 'Cascade Refining', 'sector' => 'Energy',
            'price' => 110.00, 'eps' => 2.48, 
            'shares_outstanding' => 1000000000,
            // Downstream is usually less volatile than Upstream, but susceptible to refinery outage shocks
            'volatility' => 0.22, 'beta' => 1.20, 'jump_intensity' => 0.75, 'jump_mean' => -0.04, 'jump_vol' => 0.08
        ],
        [
            'ticker' => 'GULL', 'name' => 'Silver Gull Resorts', 'sector' => 'Consumer Discretionary',
            'price' => 110.00, 'eps' => 1.52, 
            'shares_outstanding' => 1000000000,
            // Physical Casinos: Highly discretionary and cyclical. When the economy is good people flock to resorts
            'volatility' => 0.30, 'beta' => 1.40, 'jump_intensity' => 0.75, 'jump_mean' => -0.01, 'jump_vol' => 0.08
        ],
        [
            'ticker' => 'WADE', 'name' => 'Heron Regional Water', 'sector' => 'Utilities',
            'price' => 130.00, 'eps' => 3.67, 
            'shares_outstanding' => 1000000000,
            // The Ultimate Dividend Aristocrat: Extreme low volatility, ignores the market, practically a bond
            'volatility' => 0.08, 'beta' => 0.25, 'jump_intensity' => 0.10, 'jump_mean' => 0.00, 'jump_vol' => 0.02
        ],
        [
            'ticker' => 'CORM', 'name' => 'Cormorant Environmental', 'sector' => 'Industrials',
            'price' => 125.00, 'eps' => 2.25, 
            'shares_outstanding' => 1000000000,
            // Waste Management: The ultimate recession proof monopoly. It steadily consumes the trash with zero drama
            'volatility' => 0.15, 'beta' => 0.60, 'jump_intensity' => 0.20, 'jump_mean' => 0.01, 'jump_vol' => 0.04
        ],
        [
            'ticker' => 'CRAN', 'name' => 'Crane Medical Network', 'sector' => 'Healthcare',
            'price' => 145.00, 'eps' => 4.01, 
            'shares_outstanding' => 1000000000,
            // Healthcare: Highly subsidized, defensive
            'volatility' => 0.16, 'beta' => 0.70, 'jump_intensity' => 0.75, 'jump_mean' => 0.01, 'jump_vol' => 0.05
        ],
        [
            'ticker' => 'TALN', 'name' => 'Talon Credit', 'sector' => 'Financials',
            'price' => 165.00, 'eps' => 10.13, 
            'shares_outstanding' => 1000000000,
            // Credit Cards: Prints money during booms, crashes violently when consumer defaults spike
            'volatility' => 0.28, 'beta' => 1.30, 'jump_intensity' => 1.00, 'jump_mean' => -0.04, 'jump_vol' => 0.10
        ],
        [
            'ticker' => 'CANV', 'name' => 'Canvasback Logistics', 'sector' => 'Industrials',
            'price' => 175.00, 'eps' => 5.02, 
            'shares_outstanding' => 1000000000,
            // Ground Freight: Fleet of delivery trucks. A pure gauge of global parcel volume
            'volatility' => 0.22, 'beta' => 1.05, 'jump_intensity' => 0.60, 'jump_mean' => 0.00, 'jump_vol' => 0.05
        ],
        [
            'ticker' => 'SGRB', 'name' => 'Sugarbird Confectionery', 'sector' => 'Consumer Staples',
            'price' => 110.00, 'eps' => 2.64, 
            'shares_outstanding' => 1000000000,
            // Confectionery: The affordable luxury effect. Highly defensive, resilient to recessions
            'volatility' => 0.16, 'beta' => 0.55, 'jump_intensity' => 0.40, 'jump_mean' => 0.01, 'jump_vol' => 0.04
        ],
        [
            'ticker' => 'STRK', 'name' => 'Stork Consumer Credit', 'sector' => 'Financials',
            'price' => 120.00, 'eps' => 5.08, 
            'shares_outstanding' => 1000000000,
            // Consumer Finance: Very high beta. Crashes violently if unemployment rises and people default
            'volatility' => 0.32, 'beta' => 1.35, 'jump_intensity' => 1.00, 'jump_mean' => -0.04, 'jump_vol' => 0.10
        ],
        [
            'ticker' => 'BREW', 'name' => 'Copperhead Coffee Roasters', 'sector' => 'Consumer Discretionary',
            'price' => 110.00, 'eps' => 2.25, 
            'shares_outstanding' => 1000000000,
            // Consumer Staples: Ubiquitous daily habit. Highly defensive, low beta, consistent cash flow
            'volatility' => 0.16, 'beta' => 0.80, 'jump_intensity' => 0.75, 'jump_mean' => 0.01, 'jump_vol' => 0.05
        ],
        [
            'ticker' => 'CLAW', 'name' => 'Clear Rivers Law', 'sector' => 'Industrials',
            'price' => 110.00, 'eps' => 1.49, 
            'shares_outstanding' => 1000000000,
            // Consulting Services: Unyielding legal monopoly. Defensive beta, steady retainer cash flow
            'volatility' => 0.18, 'beta' => 0.80, 'jump_intensity' => 1.00, 'jump_mean' => 0.02, 'jump_vol' => 0.08
        ],
        [
            'ticker' => 'ROOK', 'name' => 'Rook Proprietary Trading', 'sector' => 'Financials',
            'price' => 80.00, 'eps' => 0.83, 
            'shares_outstanding' => 1000000000,
            // Elite Prop Desk: Highly volatile, extreme beta tied directly to absolute market friction
            'volatility' => 0.38, 'beta' => 1.85, 'jump_intensity' => 1.50, 'jump_mean' => 0.00, 'jump_vol' => 0.25
        ],
        [
            'ticker' => 'PLZA', 'name' => 'Plaza Civic River Trust', 'sector' => 'Real Estate',
            'price' => 180.00, 'eps' => 4.88, 
            'shares_outstanding' => 1000000000,
            // Safe government backed civic buildings: Very low vol, low beta, rare and small shocks
            'volatility' => 0.10, 'beta' => 0.30, 'jump_intensity' => 0.20, 'jump_mean' => 0.01, 'jump_vol' => 0.02
        ],
        [
            'ticker' => 'LYRE', 'name' => 'Lyrebird Media', 'sector' => 'Consumer Discretionary',
            'price' => 105.00, 'eps' => 2.22, 
            'shares_outstanding' => 1000000000,
            // The Spin Doctors: Moderate volatility, features sudden positive jumps during district PR crises
            'volatility' => 0.22, 'beta' => 1.10, 'jump_intensity' => 0.90, 'jump_mean' => 0.04, 'jump_vol' => 0.08 
        ],
        [
            'ticker' => 'STAR', 'name' => 'Starling Academic Systems', 'sector' => 'Consumer Discretionary',
            'price' => 103.00, 'eps' => 2.11, 
            'shares_outstanding' => 1000000000,
            // The Talent Pipeline: Extremely stable revenue from corporate subsidies, acts as a defensive hedge
            'volatility' => 0.14, 'beta' => 0.55, 'jump_intensity' => 0.30, 'jump_mean' => -0.02, 'jump_vol' => 0.04 
        ],
        [
            'ticker' => 'WEAV', 'name' => 'Weaver Marketplace', 'sector' => 'Consumer Discretionary',
            'price' => 250.00, 'eps' => 13.68, 
            'shares_outstanding' => 1000000000,
            // The Aggregator: High beta tied directly to consumer spending, high volatility from retail trends
            'volatility' => 0.30, 'beta' => 1.30, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.10 
        ],
        [
            'ticker' => 'CROP', 'name' => 'Poultry Crop Operations', 'sector' => 'Consumer Staples',
            'price' => 180.00, 'eps' => 9.26, 
            'shares_outstanding' => 1000000000,
            // The Land Bank: Highly defensive baseline due to constant food demand and massive real estate holdings
            'volatility' => 0.16, 'beta' => 0.65, 'jump_intensity' => 0.50, 'jump_mean' => 0.02, 'jump_vol' => 0.05
        ],
        [
            'ticker' => 'BRKW', 'name' => 'Breakwater Trust', 'sector' => 'Financials',
            'price' => 850.00, 'eps' => 10.00,
            'shares_outstanding' => 1000000000,
            // The Leviathan: Massive holding company. Very low beta, low baseline volatility, 
            // but subject to sudden violent jumps if activist investors (SWAN) threaten to unlock its hidden NAV.
            'volatility' => 0.10, 'beta' => 0.40, 'jump_intensity' => 0.30, 'jump_mean' => 0.04, 'jump_vol' => 0.09
        ],
        [
            'ticker' => 'ELDE', 'name' => 'Elderbird Retirement Services', 'sector' => 'Health Care',
            'price' => 150.00, 'eps' => 0.10,
            'shares_outstanding' => 1000000000,
            // The Bond Proxy: A defensive yield-trap disguised as a healthcare provider. 
            'volatility' => 0.12, 'beta' => 0.40, 'jump_intensity' => 0.10, 'jump_mean' => -0.06, 'jump_vol' => 0.08
        ],
        [
            'ticker' => 'SWFT', 'name' => 'Golden Swift Holdings', 'sector' => 'Consumer Staples',
            'price' => 135.00, 'eps' => 4.25,
            'shares_outstanding' => 1000000000,
            // The Defensive Anchor: A master-franchise real estate holding company masquerading as fast food.
            'volatility' => 0.14, 'beta' => 0.50, 'jump_intensity' => 0.05, 'jump_mean' => -0.04, 'jump_vol' => 0.06
        ],
        [
            'ticker' => 'VULT', 'name' => 'Vulture Capital Recovery', 'sector' => 'Financials',
            'price' => 450.00, 'eps' => 14.02, 
            'shares_outstanding' => 1000000000,
            // The Volatility Harvester: negative beta, highly defensive, occasionally spikes during macroeconomic panics
            'volatility' => 0.24, 'beta' => -0.65, 'jump_intensity' => 1.25, 'jump_mean' => 0.05, 'jump_vol' => 0.12 
        ],
    ];

    public const ETFS = [
        [
            'ticker' => 'LBI',
            'name' => 'Lakebird Index', // Or whatever you want LBI to stand for!
            'price' => 100.00,           // The starting price before the engine takes over
        ]
    ];
}
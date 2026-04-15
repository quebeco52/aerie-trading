<?php

namespace App\Data;

class InitialMarket
{
    public const STOCKS = [
        [
            'ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'sector' => 'Financials',
            'systemic_importance' => 'titan',
            'price' => 1500.00, 'eps' => 37.42,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.18, 'beta' => 1.15, 'jump_intensity' => 0.75, 'jump_mean' => -0.02, 'jump_vol' => 0.06,
            'baseline_roic' => 0.30, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.07 // Steady bank, high dividend
        ],
        [
            'ticker' => 'SWAN', 'name' => 'Black Swan Capital', 'sector' => 'Financials',
            'systemic_importance' => 'titan',
            'price' => 1350.00, 'eps' => 28.63,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.24, 'beta' => 1.35, 'jump_intensity' => 1.25, 'jump_mean' => 0.02, 'jump_vol' => 0.10,
            'baseline_roic' => 0.25, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.20, 'dividendSpeed' => 0.25 // Capital-light predator, hoards cash for M&A
        ],
        [
            'ticker' => 'HUMM', 'name' => 'Hummingbird Interactive', 'sector' => 'Information Technology',
            'systemic_importance' => 'none',
            'price' => 550.00, 'eps' => 19.14,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.22, 'beta' => 1.25, 'jump_intensity' => 0.75, 'jump_mean' => -0.02, 'jump_vol' => 0.06,
            'baseline_roic' => 0.30, 'capex_ratio' => 0.25, 'target_payout_ratio' => 0.15, 'dividendSpeed' => 0.30 // High ROIC software monopoly, moderate data-center CapEx
        ],
        [
            'ticker' => 'OWLS', 'name' => 'Owl Capital Partners', 'sector' => 'Financials',
            'systemic_importance' => 'systemic',
            'price' => 480.00, 'eps' => 20.15,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.15, 'beta' => 0.85, 'jump_intensity' => 0.20, 'jump_mean' => 0.01, 'jump_vol' => 0.04,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.02, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15 // Value investor, no factories, pays out cash
        ],
        [
            'ticker' => 'KING', 'name' => 'Kingfisher Capital', 'sector' => 'Financials',
            'systemic_importance' => 'systemic',
            'price' => 450.00, 'eps' => 14.61,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.28, 'beta' => 1.55, 'jump_intensity' => 1.25, 'jump_mean' => -0.04, 'jump_vol' => 0.12,
            'baseline_roic' => 0.22, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'PERE', 'name' => 'Peregrine Prime Securities', 'sector' => 'Financials',
            'systemic_importance' => 'systemic',
            'price' => 650.00, 'eps' => 14.02,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.18, 'beta' => -0.20, 'jump_intensity' => 1.25, 'jump_mean' => 0.05, 'jump_vol' => 0.12,
            'baseline_roic' => 0.35, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.12 // High frequency options clearing, massive margins
        ],
        [
            'ticker' => 'RIVR', 'name' => 'Riverstone Financial', 'sector' => 'Financials',
            'systemic_importance' => 'none',
            'price' => 115.00, 'eps' => 3.16,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.22, 'beta' => 1.20, 'jump_intensity' => 0.90, 'jump_mean' => -0.03, 'jump_vol' => 0.08,
            'baseline_roic' => 0.14, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'SAFE', 'name' => 'Safe Harbor Reinsurance', 'sector' => 'Financials',
            'systemic_importance' => 'systemic',
            'price' => 270.00, 'eps' => 8.91,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.10, 'beta' => 0.15, 'jump_intensity' => 0.10, 'jump_mean' => -0.15, 'jump_vol' => 0.20,
            'baseline_roic' => 0.10, 'capex_ratio' => 0.02, 'target_payout_ratio' => 0.70, 'dividendSpeed' => 0.05 // Reinsurance pays huge dividends, almost zero CapEx
        ],
        [
            'ticker' => 'DOVE', 'name' => 'White Dove Insurance', 'sector' => 'Financials',
            'systemic_importance' => 'base',
            'price' => 160.00, 'eps' => 7.26,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.17, 'beta' => 0.85, 'jump_intensity' => 0.50, 'jump_mean' => -0.03, 'jump_vol' => 0.06,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.10
        ],
        [
            'ticker' => 'SHRK', 'name' => 'Shrike Standard Ratings', 'sector' => 'Financials',
            'systemic_importance' => 'systemic',
            'price' => 210.00, 'eps' => 3.87,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.15, 'beta' => 0.60, 'jump_intensity' => 0.40, 'jump_mean' => 0.01, 'jump_vol' => 0.04,
            'baseline_roic' => 0.40, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.80, 'dividendSpeed' => 0.05 // Untouchable monopoly, prints cash, no physical assets
        ],
        [
            'ticker' => 'BIRD', 'name' => 'Bird Power Inc', 'sector' => 'Utilities',
            'systemic_importance' => 'base',
            'price' => 150.00, 'eps' => 3.00,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.10, 'beta' => 0.30, 'jump_intensity' => 0.30, 'jump_mean' => 0.00, 'jump_vol' => 0.04,
            'baseline_roic' => 0.08, 'capex_ratio' => 0.75, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.08 // Regulated utility: low ROIC, massive CapEx (power plants), steady dividend
        ],
        [
            'ticker' => 'WATCH', 'name' => 'Bird Watch Security', 'sector' => 'Industrials',
            'systemic_importance' => 'systemic',
            'price' => 180.00, 'eps' => 1.09,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.20, 'beta' => 0.90, 'jump_intensity' => 0.60, 'jump_mean' => -0.04, 'jump_vol' => 0.05,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.20, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'WING', 'name' => 'Steel Wings Smelting & Corp', 'sector' => 'Materials',
            'systemic_importance' => 'base',
            'price' => 120.00, 'eps' => 1.68,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.32, 'beta' => 1.30, 'jump_intensity' => 1.00, 'jump_mean' => -0.02, 'jump_vol' => 0.10,
            'baseline_roic' => 0.10, 'capex_ratio' => 0.65, 'target_payout_ratio' => 0.20, 'dividendSpeed' => 0.30 // Blast furnaces require relentless CapEx
        ],
        [
            'ticker' => 'PENG', 'name' => 'Penguin Computing', 'sector' => 'Information Technology',
            'systemic_importance' => 'none',
            'price' => 145.00, 'eps' => 3.82,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.32, 'beta' => 1.45, 'jump_intensity' => 1.25, 'jump_mean' => 0.04, 'jump_vol' => 0.15,
            'baseline_roic' => 0.22, 'capex_ratio' => 0.50, 'target_payout_ratio' => 0.00, 'dividendSpeed' => 0.50 // Hyper-growth hardware. $0 dividend.
        ],
        [
            'ticker' => 'SHOR', 'name' => 'Lakeshore Living', 'sector' => 'Real Estate',
            'systemic_importance' => 'none',
            'price' => 125.00, 'eps' => 3.36,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.24, 'beta' => 1.20, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.07,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.60, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'RIVE', 'name' => 'River Stream Industries', 'sector' => 'Industrials',
            'systemic_importance' => 'none',
            'price' => 250.00, 'eps' => 1.79,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.26, 'beta' => 1.15, 'jump_intensity' => 0.90, 'jump_mean' => 0.00, 'jump_vol' => 0.08,
            'baseline_roic' => 0.20, 'capex_ratio' => 0.40, 'target_payout_ratio' => 0.15, 'dividendSpeed' => 0.25 // Robotics manufacturing
        ],
        [
            'ticker' => 'GRIP', 'name' => 'Gryphon Defense Systems', 'sector' => 'Industrials',
            'systemic_importance' => 'none',
            'price' => 155.00, 'eps' => 2.62,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.20, 'beta' => 0.80, 'jump_intensity' => 0.75, 'jump_mean' => 0.02, 'jump_vol' => 0.07,
            'baseline_roic' => 0.15, 'capex_ratio' => 0.25, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'LOON', 'name' => 'Loon Call Telecom', 'sector' => 'Communication Services',
            'systemic_importance' => 'none',
            'price' => 190.00, 'eps' => 9.18,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.16, 'beta' => 0.70, 'jump_intensity' => 0.40, 'jump_mean' => -0.01, 'jump_vol' => 0.05,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.55, 'target_payout_ratio' => 0.65, 'dividendSpeed' => 0.10 // Massive fiber network CapEx, reliable dividend
        ],
        [
            'ticker' => 'PHIL', 'name' => 'Pheasant & Morris International', 'sector' => 'Consumer Staples',
            'systemic_importance' => 'none',
            'price' => 190.00, 'eps' => 6.53,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.15, 'beta' => 0.45, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.07,
            'baseline_roic' => 0.35, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.80, 'dividendSpeed' => 0.05 // Tobacco cash cow. Huge margins, minimal capex.
        ],
        [
            'ticker' => 'TRIV', 'name' => 'Three Rivers Manufacturing', 'sector' => 'Industrials',
            'systemic_importance' => 'none',
            'price' => 180.00, 'eps' => 8.70,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.20, 'beta' => 1.05, 'jump_intensity' => 0.75, 'jump_mean' => -0.02, 'jump_vol' => 0.07,
            'baseline_roic' => 0.14, 'capex_ratio' => 0.35, 'target_payout_ratio' => 0.45, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'IBHI', 'name' => 'Iron Beak Heavy Ind', 'sector' => 'Industrials',
            'systemic_importance' => 'systemic',
            'price' => 270.00, 'eps' => 7.60,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.28, 'beta' => 1.25, 'jump_intensity' => 0.90, 'jump_mean' => -0.01, 'jump_vol' => 0.09,
            'baseline_roic' => 0.10, 'capex_ratio' => 0.70, 'target_payout_ratio' => 0.20, 'dividendSpeed' => 0.30 // Concrete and cranes burn massive cash
        ],
        [
            'ticker' => 'TICK', 'name' => 'Tickbird Data Systems', 'sector' => 'Information Technology',
            'systemic_importance' => 'systemic',
            'price' => 200.00, 'eps' => 6.46,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.22, 'beta' => 1.10, 'jump_intensity' => 1.00, 'jump_mean' => 0.02, 'jump_vol' => 0.06,
            'baseline_roic' => 0.45, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.25 // SaaS data monopoly. Incredible 45% ROIC
        ],
        [
            'ticker' => 'SINK', 'name' => 'Sinking Shore Extraction', 'sector' => 'Energy',
            'systemic_importance' => 'base',
            'price' => 120.00, 'eps' => 4.02,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.35, 'beta' => 0.95, 'jump_intensity' => 0.75, 'jump_mean' => -0.05, 'jump_vol' => 0.08,
            'baseline_roic' => 0.15, 'capex_ratio' => 0.80, 'target_payout_ratio' => 0.25, 'dividendSpeed' => 0.35 // Offshore drilling requires massive continuous CapEx
        ],
        [
            'ticker' => 'CASC', 'name' => 'Cascade Refining', 'sector' => 'Energy',
            'systemic_importance' => 'base',
            'price' => 110.00, 'eps' => 2.48,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.22, 'beta' => 1.20, 'jump_intensity' => 0.75, 'jump_mean' => -0.04, 'jump_vol' => 0.08,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.40, 'target_payout_ratio' => 0.35, 'dividendSpeed' => 0.25
        ],
        [
            'ticker' => 'GULL', 'name' => 'Silver Gull Resorts', 'sector' => 'Consumer Discretionary',
            'systemic_importance' => 'none',
            'price' => 110.00, 'eps' => 1.52,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.30, 'beta' => 1.40, 'jump_intensity' => 0.75, 'jump_mean' => -0.01, 'jump_vol' => 0.08,
            'baseline_roic' => 0.16, 'capex_ratio' => 0.30, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'WADE', 'name' => 'Heron Regional Water', 'sector' => 'Utilities',
            'systemic_importance' => 'base',
            'price' => 130.00, 'eps' => 3.67,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.08, 'beta' => 0.15, 'jump_intensity' => 0.10, 'jump_mean' => 0.00, 'jump_vol' => 0.02,
            'baseline_roic' => 0.14, 'capex_ratio' => 0.45, 'target_payout_ratio' => 0.75, 'dividendSpeed' => 0.05
        ],
        [
            'ticker' => 'CORM', 'name' => 'Cormorant Environmental', 'sector' => 'Industrials',
            'systemic_importance' => 'none',
            'price' => 125.00, 'eps' => 2.25,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.15, 'beta' => 0.40, 'jump_intensity' => 0.20, 'jump_mean' => 0.01, 'jump_vol' => 0.04,
            'baseline_roic' => 0.20, 'capex_ratio' => 0.35, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'CRAN', 'name' => 'Crane Medical Network', 'sector' => 'Healthcare',
            'systemic_importance' => 'base',
            'price' => 145.00, 'eps' => 4.01,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.16, 'beta' => 0.40, 'jump_intensity' => 0.75, 'jump_mean' => 0.01, 'jump_vol' => 0.05,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.25, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'TALN', 'name' => 'Talon Credit', 'sector' => 'Financials',
            'systemic_importance' => 'none',
            'price' => 165.00, 'eps' => 10.13,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.28, 'beta' => 1.30, 'jump_intensity' => 1.00, 'jump_mean' => -0.04, 'jump_vol' => 0.10,
            'baseline_roic' => 0.25, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.35, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'CANV', 'name' => 'Canvasback Logistics', 'sector' => 'Industrials',
            'systemic_importance' => 'none',
            'price' => 175.00, 'eps' => 5.02,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.22, 'beta' => 1.10, 'jump_intensity' => 0.60, 'jump_mean' => 0.00, 'jump_vol' => 0.05,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.55, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.25 // Large truck fleets require high CapEx
        ],
        [
            'ticker' => 'SGRB', 'name' => 'Sugarbird Confectionery', 'sector' => 'Consumer Staples',
            'systemic_importance' => 'none',
            'price' => 110.00, 'eps' => 2.64,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.16, 'beta' => 0.55, 'jump_intensity' => 0.40, 'jump_mean' => 0.01, 'jump_vol' => 0.04,
            'baseline_roic' => 0.22, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.10
        ],
        [
            'ticker' => 'STRK', 'name' => 'Stork Consumer Credit', 'sector' => 'Financials',
            'systemic_importance' => 'none',
            'price' => 120.00, 'eps' => 5.08,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.32, 'beta' => 1.35, 'jump_intensity' => 1.00, 'jump_mean' => -0.04, 'jump_vol' => 0.10,
            'baseline_roic' => 0.20, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'BREW', 'name' => 'Copperhead Coffee Roasters', 'sector' => 'Consumer Discretionary',
            'systemic_importance' => 'none',
            'price' => 110.00, 'eps' => 2.25,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.16, 'beta' => 0.80, 'jump_intensity' => 0.75, 'jump_mean' => 0.01, 'jump_vol' => 0.05,
            'baseline_roic' => 0.25, 'capex_ratio' => 0.35, 'target_payout_ratio' => 0.45, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'CLAW', 'name' => 'Clear Rivers Law', 'sector' => 'Industrials', // Often classed as Industrials/Services
            'systemic_importance' => 'none',
            'price' => 110.00, 'eps' => 1.49,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.18, 'beta' => 0.80, 'jump_intensity' => 1.00, 'jump_mean' => 0.02, 'jump_vol' => 0.08,
            'baseline_roic' => 0.50, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.80, 'dividendSpeed' => 0.08 // Law firms have no hard assets. Infinite ROIC.
        ],
        [
            'ticker' => 'ROOK', 'name' => 'Rook Proprietary Trading', 'sector' => 'Financials',
            'systemic_importance' => 'none',
            'price' => 80.00, 'eps' => 0.83,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.38, 'beta' => 1.70, 'jump_intensity' => 1.50, 'jump_mean' => 0.00, 'jump_vol' => 0.25,
            'baseline_roic' => 0.40, 'capex_ratio' => 0.20, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'PLZA', 'name' => 'Plaza Civic River Trust', 'sector' => 'Real Estate',
            'systemic_importance' => 'none',
            'price' => 180.00, 'eps' => 4.88,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.10, 'beta' => 0.30, 'jump_intensity' => 0.20, 'jump_mean' => 0.01, 'jump_vol' => 0.02,
            'baseline_roic' => 0.08, 'capex_ratio' => 0.20, 'target_payout_ratio' => 0.90, 'dividendSpeed' => 0.05 // REITs are legally required to distribute 90%
        ],
        [
            'ticker' => 'LYRE', 'name' => 'Lyrebird Media', 'sector' => 'Consumer Discretionary',
            'systemic_importance' => 'none',
            'price' => 105.00, 'eps' => 2.22,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.22, 'beta' => 1.10, 'jump_intensity' => 0.90, 'jump_mean' => 0.04, 'jump_vol' => 0.08,
            'baseline_roic' => 0.30, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'STAR', 'name' => 'Starling Academic Systems', 'sector' => 'Consumer Discretionary',
            'systemic_importance' => 'none',
            'price' => 103.00, 'eps' => 2.11,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.14, 'beta' => 0.55, 'jump_intensity' => 0.30, 'jump_mean' => -0.02, 'jump_vol' => 0.04,
            'baseline_roic' => 0.15, 'capex_ratio' => 0.30, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'WEAV', 'name' => 'Weaver Marketplace', 'sector' => 'Consumer Discretionary',
            'systemic_importance' => 'none',
            'price' => 250.00, 'eps' => 13.68,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.30, 'beta' => 1.30, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.10,
            'baseline_roic' => 0.22, 'capex_ratio' => 0.45, 'target_payout_ratio' => 0.00, 'dividendSpeed' => 0.50 // Reinvests every penny to maintain monopoly
        ],
        [
            'ticker' => 'CROP', 'name' => 'Poultry Crop Operations', 'sector' => 'Consumer Staples',
            'systemic_importance' => 'base',
            'price' => 180.00, 'eps' => 9.26,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.16, 'beta' => 0.65, 'jump_intensity' => 0.50, 'jump_mean' => 0.02, 'jump_vol' => 0.05,
            'baseline_roic' => 0.10, 'capex_ratio' => 0.60, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'BRKW', 'name' => 'Breakwater Trust', 'sector' => 'Financials',
            'systemic_importance' => 'titan',
            'price' => 850.00, 'eps' => 10.00,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.10, 'beta' => 0.40, 'jump_intensity' => 0.30, 'jump_mean' => 0.04, 'jump_vol' => 0.09,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.25, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'ELDE', 'name' => 'Elderbird Retirement Services', 'sector' => 'Health Care',
            'systemic_importance' => 'none',
            'price' => 150.00, 'eps' => 0.10,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.12, 'beta' => 0.40, 'jump_intensity' => 0.10, 'jump_mean' => -0.06, 'jump_vol' => 0.08,
            'baseline_roic' => 0.16, 'capex_ratio' => 0.40, 'target_payout_ratio' => 0.70, 'dividendSpeed' => 0.10
        ],
        [
            'ticker' => 'SWFT', 'name' => 'Golden Swift Holdings', 'sector' => 'Consumer Staples',
            'systemic_importance' => 'none',
            'price' => 135.00, 'eps' => 4.25,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.14, 'beta' => 0.50, 'jump_intensity' => 0.05, 'jump_mean' => -0.04, 'jump_vol' => 0.06,
            'baseline_roic' => 0.28, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.65, 'dividendSpeed' => 0.10 // Master franchise: franchisees pay the capex, SWFT just collects rent
        ],
        [
            'ticker' => 'VULT', 'name' => 'Vulture Capital Recovery', 'sector' => 'Financials',
            'systemic_importance' => 'systemic',
            'price' => 450.00, 'eps' => 14.02,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.24, 'beta' => -0.65, 'jump_intensity' => 1.25, 'jump_mean' => 0.05, 'jump_vol' => 0.12,
            'baseline_roic' => 0.30, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'FALC', 'name' => 'Falconet Motor Group', 'sector' => 'Consumer Discretionary',
            'systemic_importance' => 'none',
            'price' => 210.00, 'eps' => 2.15,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.35, 'beta' => 1.50, 'jump_intensity' => 1.40, 'jump_mean' => -0.06, 'jump_vol' => 0.15,
            'baseline_roic' => 0.08, 'capex_ratio' => 0.85, 'target_payout_ratio' => 0.00, 'dividendSpeed' => 0.50 // Gigafactories burn massive cash, $0 dividend
        ],
        [
            'ticker' => 'OSPR', 'name' => 'Osprey Global Vanguard', 'sector' => 'Industrials',
            'systemic_importance' => 'systemic',
            'price' => 160.00, 'eps' => 4.12,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.28, 'beta' => -0.15, 'jump_intensity' => 1.10, 'jump_mean' => 0.05, 'jump_vol' => 0.12,
            'baseline_roic' => 0.25, 'capex_ratio' => 0.20, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15
        ],
        [
            'ticker' => 'CROW', 'name' => 'Crowfall Capital', 'sector' => 'Financials',
            'systemic_importance' => 'systemic',
            'price' => 230.00, 'eps' => 14.02,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.24, 'beta' => -0.50, 'jump_intensity' => 1.25, 'jump_mean' => 0.05, 'jump_vol' => 0.12,
            'baseline_roic' => 0.35, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.20
        ],
        [
            'ticker' => 'CNDR', 'name' => 'Condor Extraction', 'sector' => 'Materials',
            'systemic_importance' => 'systemic',
            'price' => 250.00, 'eps' => 13.60,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.28, 'beta' => 1.35, 'jump_intensity' => 0.90, 'jump_mean' => -0.01, 'jump_vol' => 0.09,
            'baseline_roic' => 0.14, 'capex_ratio' => 0.75, 'target_payout_ratio' => 0.10, 'dividendSpeed' => 0.40 // Heavy machinery, extreme CapEx
        ],
        [
            'ticker' => 'SILC', 'name' => 'Silicon Creek Foundries', 'sector' => 'Information Technology',
            'systemic_importance' => 'systemic',
            'price' => 290.00, 'eps' => 15.82,
            'shares_outstanding' => 1000000000,
            'volatility' => 0.30, 'beta' => 1.50, 'jump_intensity' => 1.25, 'jump_mean' => 0.04, 'jump_vol' => 0.15,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.80, 'target_payout_ratio' => 0.15, 'dividendSpeed' => 0.35 // Fab plants are the most expensive buildings on earth
        ],
    ];

    public const ETFS = [
        [
            'ticker' => 'LBI',
            'name' => 'Lakebird Index', 
            'price' => 100.00,           
        ]
    ];
}
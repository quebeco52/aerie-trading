<?php

namespace App\Data;

class InitialMarket
{
    public const STOCKS = [
        [
            'ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'sector' => 'Financials', 'systemic_importance' => 'titan',
            'price' => 2000.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.15, 'beta' => 1.10, 'jump_intensity' => 0.40, 'jump_mean' => -0.15, 'jump_vol' => 0.05,
            'baseline_roic' => 0.16, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.07,
            'corporate_treasury' => 350_000_000_000.00, 'debt_to_equity' => 1.20, 'operating_margin' => 0.40, 'public_float' => 0.50,
            'total_net_income'  => 208_570_000_000.00,  
            'total_equity'      => 2_500_000_000_000.00, 
            'retained_earnings' => 500_000_000_000.00   
        ],
        [
            'ticker' => 'SWAN', 'name' => 'Black Swan Capital', 'sector' => 'Financials', 'systemic_importance' => 'titan',
            'price' => 1350.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.24, 'beta' => 1.35, 'jump_intensity' => 1.25, 'jump_mean' => 0.02, 'jump_vol' => 0.10,
            'baseline_roic' => 0.16, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.20, 'dividendSpeed' => 0.40,
            'corporate_treasury' => 85_000_000_000.00, 'debt_to_equity' => 0.20, 'operating_margin' => 0.45, 'public_float' => 0.50,
            'total_net_income'  => 120_430_000_000.00,  
            'total_equity'      => 800_000_000_000.00, 
            'retained_earnings' => 250_000_000_000.00
        ],
        [
            'ticker' => 'HUMM', 'name' => 'Hummingbird Interactive', 'sector' => 'Information Technology', 'systemic_importance' => 'none',
            'price' => 800.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.22, 'beta' => 1.25, 'jump_intensity' => 0.75, 'jump_mean' => -0.18, 'jump_vol' => 0.06,
            'baseline_roic' => 0.30, 'capex_ratio' => 0.25, 'target_payout_ratio' => 0.15, 'dividendSpeed' => 0.30,
            'corporate_treasury' => 120_000_000_000.00, 'debt_to_equity' => 0.10, 'operating_margin' => 0.65, 'public_float' => 0.90,
            'total_net_income'  => 25_920_000_000.00,
            'total_equity'      => 76_400_000_000.00,
            'retained_earnings' => 45_000_000_000.00
        ],
        [
            'ticker' => 'OWLS', 'name' => 'Owl Capital Partners', 'sector' => 'Financials', 'systemic_importance' => 'systemic',
            'price' => 600.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.15, 'beta' => 0.40, 'jump_intensity' => 0.20, 'jump_mean' => 0.00, 'jump_vol' => 0.04,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.02, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 140_000_000_000.00, 'debt_to_equity' => 0.50, 'operating_margin' => 0.40, 'public_float' => 0.80,
            'total_net_income'  => 38_290_000_000.00,
            'total_equity'      => 190_500_000_000.00,
            'retained_earnings' => 80_000_000_000.00
        ],
        [
            'ticker' => 'KING', 'name' => 'Kingfisher Capital', 'sector' => 'Financials', 'systemic_importance' => 'systemic',
            'price' => 550.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.28, 'beta' => 1.70, 'jump_intensity' => 1.25, 'jump_mean' => -0.04, 'jump_vol' => 0.12,
            'baseline_roic' => 0.22, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 5_000_000_000.00, 'debt_to_equity' => 2.50, 'operating_margin' => 0.35, 'public_float' => 0.95,
            'total_net_income'  => 34_140_000_000.00,
            'total_equity'      => 146_090_000_000.00,
            'retained_earnings' => 50_000_000_000.00
        ],
        [
            'ticker' => 'PERE', 'name' => 'Peregrine Prime Securities', 'sector' => 'Financials', 'systemic_importance' => 'systemic',
            'price' => 750.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.18, 'beta' => -0.20, 'jump_intensity' => 1.25, 'jump_mean' => 0.05, 'jump_vol' => 0.12,
            'baseline_roic' => 0.35, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.12,
            'corporate_treasury' => 12_000_000_000.00, 'debt_to_equity' => 1.10, 'operating_margin' => 0.50, 'public_float' => 0.88,
            'total_net_income'  => 50_430_000_000.00,
            'total_equity'      => 132_660_000_000.00,
            'retained_earnings' => 30_000_000_000.00
        ],
        [
            'ticker' => 'RIVR', 'name' => 'Riverstone Financial', 'sector' => 'Financials', 'systemic_importance' => 'none',
            'price' => 115.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.22, 'beta' => 1.20, 'jump_intensity' => 0.90, 'jump_mean' => -0.03, 'jump_vol' => 0.08,
            'baseline_roic' => 0.14, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 1_000_000_000.00, 'debt_to_equity' => 1.20, 'operating_margin' => 0.15, 'public_float' => 0.95,
            'total_net_income'  => 8_210_000_000.00,   
            'total_equity'      => 58_640_000_000.00,
            'retained_earnings' => 15_000_000_000.00
        ],
        [
            'ticker' => 'SAFE', 'name' => 'Safe Harbor Reinsurance', 'sector' => 'Financials', 'systemic_importance' => 'systemic',
            'price' => 270.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.10, 'beta' => 0.02, 'jump_intensity' => 0.35, 'jump_mean' => -0.25, 'jump_vol' => 0.15,
            'baseline_roic' => 0.10, 'capex_ratio' => 0.02, 'target_payout_ratio' => 0.25, 'dividendSpeed' => 0.05,
            'corporate_treasury' => 45_000_000_000.00, 'debt_to_equity' => 0.02, 'operating_margin' => 0.25, 'public_float' => 0.98,
            'total_net_income'  => 19_290_000_000.00,  
            'total_equity'      => 192_900_000_000.00,
            'retained_earnings' => 95_000_000_000.00
        ],
        [
            'ticker' => 'DOVE', 'name' => 'White Dove Insurance', 'sector' => 'Financials', 'systemic_importance' => 'base',
            'price' => 160.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.17, 'beta' => 0.85, 'jump_intensity' => 0.50, 'jump_mean' => -0.03, 'jump_vol' => 0.06,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.10,
            'corporate_treasury' => 4_000_000_000.00, 'debt_to_equity' => 0.60, 'operating_margin' => 0.18, 'public_float' => 0.90,
            'total_net_income'  => 11_430_000_000.00,  
            'total_equity'      => 95_250_000_000.00,
            'retained_earnings' => 45_000_000_000.00
        ],
        [
            'ticker' => 'SHRK', 'name' => 'Shrike Standard Ratings', 'sector' => 'Financials', 'systemic_importance' => 'systemic',
            'price' => 210.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.15, 'beta' => 0.60, 'jump_intensity' => 0.40, 'jump_mean' => 0.01, 'jump_vol' => 0.04,
            'baseline_roic' => 0.40, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.80, 'dividendSpeed' => 0.05,
            'corporate_treasury' => 3_500_000_000.00, 'debt_to_equity' => 0.15, 'operating_margin' => 0.55, 'public_float' => 0.92,
            'total_net_income'  => 15_000_000_000.00,  
            'total_equity'      => 37_500_000_000.00,
            'retained_earnings' => 7_000_000_000.00
        ],
        [
            'ticker' => 'BIRD', 'name' => 'Bird Power Inc', 'sector' => 'Utilities', 'systemic_importance' => 'base',
            'price' => 150.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.10, 'beta' => 0.30, 'jump_intensity' => 0.30, 'jump_mean' => 0.00, 'jump_vol' => 0.04,
            'baseline_roic' => 0.08, 'capex_ratio' => 0.75, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.08,
            'corporate_treasury' => 500_000_000.00, 'debt_to_equity' => 2.80, 'operating_margin' => 0.12, 'public_float' => 0.99,
            'total_net_income'  => 9_380_000_000.00,   
            'total_equity'      => 117_250_000_000.00,
            'retained_earnings' => 25_000_000_000.00
        ],
        [
            'ticker' => 'WATCH', 'name' => 'Bird Watch Security', 'sector' => 'Industrials', 'systemic_importance' => 'systemic',
            'price' => 180.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.20, 'beta' => 0.90, 'jump_intensity' => 0.60, 'jump_mean' => -0.04, 'jump_vol' => 0.05,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.20, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 1_200_000_000.00, 'debt_to_equity' => 1.10, 'operating_margin' => 0.15, 'public_float' => 0.85,
            'total_net_income'  => 9_000_000_000.00,   
            'total_equity'      => 50_000_000_000.00,
            'retained_earnings' => 4_000_000_000.00
        ],
        [
            'ticker' => 'WING', 'name' => 'Steel Wings Smelting & Corp', 'sector' => 'Materials', 'systemic_importance' => 'base',
            'price' => 120.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.32, 'beta' => 1.30, 'jump_intensity' => 1.00, 'jump_mean' => -0.02, 'jump_vol' => 0.10,
            'baseline_roic' => 0.10, 'capex_ratio' => 0.65, 'target_payout_ratio' => 0.20, 'dividendSpeed' => 0.30,
            'corporate_treasury' => 800_000_000.00, 'debt_to_equity' => 3.20, 'operating_margin' => 0.08, 'public_float' => 0.90,
            'total_net_income'  => 8_000_000_000.00,   
            'total_equity'      => 80_000_000_000.00,
            'retained_earnings' => 10_000_000_000.00
        ],
        [
            'ticker' => 'PENG', 'name' => 'Penguin Computing', 'sector' => 'Information Technology', 'systemic_importance' => 'none',
            'price' => 145.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.32, 'beta' => 1.45, 'jump_intensity' => 1.25, 'jump_mean' => 0.04, 'jump_vol' => 0.15,
            'baseline_roic' => 0.22, 'capex_ratio' => 0.50, 'target_payout_ratio' => 0.00, 'dividendSpeed' => 0.50,
            'corporate_treasury' => 4_500_000_000.00, 'debt_to_equity' => 0.40, 'operating_margin' => 0.28, 'public_float' => 0.75,
            'total_net_income'  => 6_040_000_000.00,   
            'total_equity'      => 27_450_000_000.00,
            'retained_earnings' => 10_000_000_000.00
        ],
        [
            'ticker' => 'SHOR', 'name' => 'Lakeshore Living', 'sector' => 'Real Estate', 'systemic_importance' => 'none',
            'price' => 125.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.24, 'beta' => 1.20, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.07,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.60, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 600_000_000.00, 'debt_to_equity' => 2.50, 'operating_margin' => 0.45, 'public_float' => 0.85,
            'total_net_income'  => 5_680_000_000.00,   
            'total_equity'      => 47_330_000_000.00,
            'retained_earnings' => 20_000_000_000.00
        ],
        [
            'ticker' => 'RIVE', 'name' => 'River Stream Industries', 'sector' => 'Industrials', 'systemic_importance' => 'none',
            'price' => 250.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.26, 'beta' => 1.15, 'jump_intensity' => 0.90, 'jump_mean' => 0.00, 'jump_vol' => 0.08,
            'baseline_roic' => 0.20, 'capex_ratio' => 0.40, 'target_payout_ratio' => 0.15, 'dividendSpeed' => 0.25,
            'corporate_treasury' => 2_200_000_000.00, 'debt_to_equity' => 1.40, 'operating_margin' => 0.18, 'public_float' => 0.90,
            'total_net_income'  => 12_500_000_000.00,  
            'total_equity'      => 62_500_000_000.00,
            'retained_earnings' => 5_000_000_000.00
        ],
        [
            'ticker' => 'GRIP', 'name' => 'Gryphon Defense Systems', 'sector' => 'Industrials', 'systemic_importance' => 'none',
            'price' => 155.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.20, 'beta' => 0.80, 'jump_intensity' => 0.75, 'jump_mean' => 0.02, 'jump_vol' => 0.07,
            'baseline_roic' => 0.15, 'capex_ratio' => 0.25, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 3_100_000_000.00, 'debt_to_equity' => 0.90, 'operating_margin' => 0.14, 'public_float' => 0.95,
            'total_net_income'  => 7_750_000_000.00,   
            'total_equity'      => 51_670_000_000.00,
            'retained_earnings' => 12_000_000_000.00
        ],
        [
            'ticker' => 'LOON', 'name' => 'Loon Call Telecom', 'sector' => 'Communication Services', 'systemic_importance' => 'none',
            'price' => 190.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.16, 'beta' => 0.70, 'jump_intensity' => 0.40, 'jump_mean' => -0.01, 'jump_vol' => 0.05,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.55, 'target_payout_ratio' => 0.65, 'dividendSpeed' => 0.10,
            'corporate_treasury' => 2_800_000_000.00, 'debt_to_equity' => 2.10, 'operating_margin' => 0.22, 'public_float' => 0.98,
            'total_net_income'  => 11_180_000_000.00,  
            'total_equity'      => 93_170_000_000.00,
            'retained_earnings' => 60_000_000_000.00
        ],
        [
            'ticker' => 'PHIL', 'name' => 'Pheasant & Morris International', 'sector' => 'Consumer Staples', 'systemic_importance' => 'none',
            'price' => 190.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.15, 'beta' => 0.45, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.07,
            'baseline_roic' => 0.35, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.80, 'dividendSpeed' => 0.05,
            'corporate_treasury' => 9_000_000_000.00, 'debt_to_equity' => 0.80, 'operating_margin' => 0.42, 'public_float' => 0.95,
            'total_net_income'  => 10_560_000_000.00,  
            'total_equity'      => 30_170_000_000.00,
            'retained_earnings' => 12_000_000_000.00
        ],
        [
            'ticker' => 'TRIV', 'name' => 'Three Rivers Manufacturing', 'sector' => 'Industrials', 'systemic_importance' => 'none',
            'price' => 180.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.20, 'beta' => 1.05, 'jump_intensity' => 0.75, 'jump_mean' => -0.02, 'jump_vol' => 0.07,
            'baseline_roic' => 0.14, 'capex_ratio' => 0.35, 'target_payout_ratio' => 0.45, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 1_500_000_000.00, 'debt_to_equity' => 1.30, 'operating_margin' => 0.16, 'public_float' => 0.88,
            'total_net_income'  => 9_000_000_000.00,   
            'total_equity'      => 64_290_000_000.00,
            'retained_earnings' => 45_000_000_000.00
        ],
        [
            'ticker' => 'IBHI', 'name' => 'Iron Beak Heavy Ind', 'sector' => 'Industrials', 'systemic_importance' => 'systemic',
            'price' => 380.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.28, 'beta' => 1.25, 'jump_intensity' => 0.90, 'jump_mean' => -0.01, 'jump_vol' => 0.09,
            'baseline_roic' => 0.10, 'capex_ratio' => 0.70, 'target_payout_ratio' => 0.20, 'dividendSpeed' => 0.30,
            'corporate_treasury' => 600_000_000.00, 'debt_to_equity' => 2.70, 'operating_margin' => 0.09, 'public_float' => 0.92,
            'total_net_income'  => 17_500_000_000.00,
            'total_equity'      => 135_000_000_000.00,
            'retained_earnings' => 50_000_000_000.00
        ],
        [
            'ticker' => 'TICK', 'name' => 'Tickbird Data Systems', 'sector' => 'Information Technology', 'systemic_importance' => 'systemic',
            'price' => 250.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.22, 'beta' => 1.10, 'jump_intensity' => 1.00, 'jump_mean' => 0.02, 'jump_vol' => 0.06,
            'baseline_roic' => 0.45, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.25,
            'corporate_treasury' => 12_000_000_000.00, 'debt_to_equity' => 0.05, 'operating_margin' => 0.75, 'public_float' => 0.85,
            'total_net_income'  => 10_330_000_000.00,   
            'total_equity'      => 18_510_000_000.00,
            'retained_earnings' => 10_000_000_000.00
        ],
        [
            'ticker' => 'SINK', 'name' => 'Sinking Shore Extraction', 'sector' => 'Energy', 'systemic_importance' => 'base',
            'price' => 170.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.35, 'beta' => 0.95, 'jump_intensity' => 0.75, 'jump_mean' => -0.05, 'jump_vol' => 0.08,
            'baseline_roic' => 0.15, 'capex_ratio' => 0.80, 'target_payout_ratio' => 0.25, 'dividendSpeed' => 0.35,
            'corporate_treasury' => 800_000_000.00, 'debt_to_equity' => 3.50, 'operating_margin' => 0.18, 'public_float' => 0.95,
            'total_net_income'  => 12_570_000_000.00,   
            'total_equity'      => 57_130_000_000.00,
            'retained_earnings' => 15_000_000_000.00
        ],
        [
            'ticker' => 'CASC', 'name' => 'Cascade Refining', 'sector' => 'Energy', 'systemic_importance' => 'base',
            'price' => 190.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.22, 'beta' => 1.20, 'jump_intensity' => 0.75, 'jump_mean' => -0.04, 'jump_vol' => 0.08,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.40, 'target_payout_ratio' => 0.35, 'dividendSpeed' => 0.25,
            'corporate_treasury' => 1_600_000_000.00, 'debt_to_equity' => 1.80, 'operating_margin' => 0.12, 'public_float' => 0.90,
            'total_net_income'  => 14_860_000_000.00,   
            'total_equity'      => 43_670_000_000.00,
            'retained_earnings' => 8_000_000_000.00
        ],
        [
            'ticker' => 'GULL', 'name' => 'Silver Gull Resorts', 'sector' => 'Consumer Discretionary', 'systemic_importance' => 'none',
            'price' => 110.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.30, 'beta' => 1.40, 'jump_intensity' => 0.75, 'jump_mean' => -0.01, 'jump_vol' => 0.08,
            'baseline_roic' => 0.16, 'capex_ratio' => 0.30, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 700_000_000.00, 'debt_to_equity' => 2.20, 'operating_margin' => 0.18, 'public_float' => 0.85,
            'total_net_income'  => 5_240_000_000.00,   
            'total_equity'      => 32_750_000_000.00,
            'retained_earnings' => 5_000_000_000.00
        ],
        [
            'ticker' => 'WADE', 'name' => 'Heron Regional Water', 'sector' => 'Utilities', 'systemic_importance' => 'base',
            'price' => 130.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.08, 'beta' => 0.15, 'jump_intensity' => 0.10, 'jump_mean' => 0.00, 'jump_vol' => 0.02,
            'baseline_roic' => 0.14, 'capex_ratio' => 0.45, 'target_payout_ratio' => 0.75, 'dividendSpeed' => 0.05,
            'corporate_treasury' => 400_000_000.00, 'debt_to_equity' => 2.40, 'operating_margin' => 0.20, 'public_float' => 0.98,
            'total_net_income'  => 8_130_000_000.00,   
            'total_equity'      => 58_070_000_000.00,
            'retained_earnings' => 18_000_000_000.00
        ],
        [
            'ticker' => 'CORM', 'name' => 'Cormorant Environmental', 'sector' => 'Industrials', 'systemic_importance' => 'none',
            'price' => 125.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.15, 'beta' => 0.40, 'jump_intensity' => 0.20, 'jump_mean' => 0.01, 'jump_vol' => 0.04,
            'baseline_roic' => 0.20, 'capex_ratio' => 0.35, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 1_100_000_000.00, 'debt_to_equity' => 1.30, 'operating_margin' => 0.15, 'public_float' => 0.88,
            'total_net_income'  => 6_250_000_000.00,   
            'total_equity'      => 31_250_000_000.00,
            'retained_earnings' => 8_000_000_000.00
        ],
        [
            'ticker' => 'CRAN', 'name' => 'Crane Medical Network', 'sector' => 'Healthcare', 'systemic_importance' => 'base',
            'price' => 145.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.16, 'beta' => 0.40, 'jump_intensity' => 0.75, 'jump_mean' => 0.01, 'jump_vol' => 0.05,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.25, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 4_200_000_000.00, 'debt_to_equity' => 0.90, 'operating_margin' => 0.24, 'public_float' => 0.92,
            'total_net_income'  => 7_630_000_000.00,   
            'total_equity'      => 42_390_000_000.00,
            'retained_earnings' => 15_000_000_000.00
        ],
        [
            'ticker' => 'TALN', 'name' => 'Talon Credit', 'sector' => 'Financials', 'systemic_importance' => 'none',
            'price' => 185.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.28, 'beta' => 1.30, 'jump_intensity' => 1.00, 'jump_mean' => -0.04, 'jump_vol' => 0.10,
            'baseline_roic' => 0.25, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.35, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 2_800_000_000.00, 'debt_to_equity' => 1.80, 'operating_margin' => 0.32, 'public_float' => 0.85,
            'total_net_income'  => 13_790_000_000.00,  
            'total_equity'      => 47_160_000_000.00,
            'retained_earnings' => 30_000_000_000.00
        ],
        [
            'ticker' => 'CANV', 'name' => 'Canvasback Logistics', 'sector' => 'Industrials', 'systemic_importance' => 'none',
            'price' => 175.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.22, 'beta' => 1.10, 'jump_intensity' => 0.60, 'jump_mean' => 0.00, 'jump_vol' => 0.05,
            'baseline_roic' => 0.12, 'capex_ratio' => 0.55, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.25,
            'corporate_treasury' => 1_500_000_000.00, 'debt_to_equity' => 1.60, 'operating_margin' => 0.11, 'public_float' => 0.90,
            'total_net_income'  => 8_750_000_000.00,   
            'total_equity'      => 72_920_000_000.00,
            'retained_earnings' => 30_000_000_000.00
        ],
        [
            'ticker' => 'SGRB', 'name' => 'Sugarbird Confectionery', 'sector' => 'Consumer Staples', 'systemic_importance' => 'none',
            'price' => 110.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.16, 'beta' => 0.55, 'jump_intensity' => 0.40, 'jump_mean' => 0.01, 'jump_vol' => 0.04,
            'baseline_roic' => 0.22, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.10,
            'corporate_treasury' => 2_100_000_000.00, 'debt_to_equity' => 0.85, 'operating_margin' => 0.28, 'public_float' => 0.88,
            'total_net_income'  => 6_110_000_000.00,   
            'total_equity'      => 27_770_000_000.00,
            'retained_earnings' => 8_000_000_000.00
        ],
        [
            'ticker' => 'STRK', 'name' => 'Stork Consumer Credit', 'sector' => 'Financials', 'systemic_importance' => 'none',
            'price' => 120.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.32, 'beta' => 1.35, 'jump_intensity' => 1.00, 'jump_mean' => -0.04, 'jump_vol' => 0.10,
            'baseline_roic' => 0.20, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 1_700_000_000.00, 'debt_to_equity' => 2.10, 'operating_margin' => 0.22, 'public_float' => 0.92,
            'total_net_income'  => 8_570_000_000.00,   
            'total_equity'      => 42_850_000_000.00,
            'retained_earnings' => 15_000_000_000.00
        ],
        [
            'ticker' => 'BREW', 'name' => 'Copperhead Coffee Roasters', 'sector' => 'Consumer Discretionary', 'systemic_importance' => 'none',
            'price' => 110.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.16, 'beta' => 0.80, 'jump_intensity' => 0.75, 'jump_mean' => 0.01, 'jump_vol' => 0.05,
            'baseline_roic' => 0.25, 'capex_ratio' => 0.35, 'target_payout_ratio' => 0.45, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 850_000_000.00, 'debt_to_equity' => 1.40, 'operating_margin' => 0.18, 'public_float' => 0.70,
            'total_net_income'  => 5_240_000_000.00,   
            'total_equity'      => 20_960_000_000.00,
            'retained_earnings' => 5_000_000_000.00
        ],
        [
            'ticker' => 'CLAW', 'name' => 'Clear Rivers Law', 'sector' => 'Industrials', 'systemic_importance' => 'none',
            'price' => 150.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.18, 'beta' => 0.80, 'jump_intensity' => 1.00, 'jump_mean' => 0.02, 'jump_vol' => 0.08,
            'baseline_roic' => 0.50, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.80, 'dividendSpeed' => 0.08,
            'corporate_treasury' => 500_000_000.00, 'debt_to_equity' => 0.10, 'operating_margin' => 0.45, 'public_float' => 0.60,
            'total_net_income'  => 8_500_000_000.00,   
            'total_equity'      => 11_000_000_000.00,
            'retained_earnings' => 2_000_000_000.00
        ],
        [
            'ticker' => 'ROOK', 'name' => 'Rook Proprietary Trading', 'sector' => 'Financials', 'systemic_importance' => 'none',
            'price' => 80.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.38, 'beta' => 1.70, 'jump_intensity' => 1.50, 'jump_mean' => 0.00, 'jump_vol' => 0.25,
            'baseline_roic' => 0.40, 'capex_ratio' => 0.20, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 2_000_000_000.00, 'debt_to_equity' => 2.50, 'operating_margin' => 0.38, 'public_float' => 0.80,
            'total_net_income'  => 5_710_000_000.00,   
            'total_equity'      => 14_280_000_000.00,
            'retained_earnings' => 1_500_000_000.00
        ],
        [
            'ticker' => 'PLZA', 'name' => 'Plaza Civic River Trust', 'sector' => 'Real Estate', 'systemic_importance' => 'none',
            'price' => 180.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.10, 'beta' => 0.30, 'jump_intensity' => 0.20, 'jump_mean' => 0.01, 'jump_vol' => 0.02,
            'baseline_roic' => 0.08, 'capex_ratio' => 0.20, 'target_payout_ratio' => 0.90, 'dividendSpeed' => 0.05,
            'corporate_treasury' => 800_000_000.00, 'debt_to_equity' => 2.80, 'operating_margin' => 0.55, 'public_float' => 0.95,
            'total_net_income'  => 8_180_000_000.00,   
            'total_equity'      => 102_250_000_000.00,
            'retained_earnings' => 45_000_000_000.00
        ],
        [
            'ticker' => 'LYRE', 'name' => 'Lyrebird Media', 'sector' => 'Consumer Discretionary', 'systemic_importance' => 'none',
            'price' => 105.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.22, 'beta' => 1.10, 'jump_intensity' => 0.90, 'jump_mean' => 0.04, 'jump_vol' => 0.08,
            'baseline_roic' => 0.30, 'capex_ratio' => 0.10, 'target_payout_ratio' => 0.60, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 3_200_000_000.00, 'debt_to_equity' => 1.10, 'operating_margin' => 0.24, 'public_float' => 0.85,
            'total_net_income'  => 5_000_000_000.00,   
            'total_equity'      => 16_670_000_000.00,
            'retained_earnings' => 5_000_000_000.00
        ],
        [
            'ticker' => 'STAR', 'name' => 'Starling Academic Systems', 'sector' => 'Consumer Discretionary', 'systemic_importance' => 'none',
            'price' => 103.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.14, 'beta' => 0.55, 'jump_intensity' => 0.30, 'jump_mean' => -0.02, 'jump_vol' => 0.04,
            'baseline_roic' => 0.15, 'capex_ratio' => 0.30, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 1_200_000_000.00, 'debt_to_equity' => 0.70, 'operating_margin' => 0.18, 'public_float' => 0.75,
            'total_net_income'  => 4_900_000_000.00,   
            'total_equity'      => 32_670_000_000.00,
            'retained_earnings' => 10_000_000_000.00
        ],
        [
            'ticker' => 'WEAV', 'name' => 'Weaver Marketplace', 'sector' => 'Consumer Discretionary', 'systemic_importance' => 'none',
            'price' => 350.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.30, 'beta' => 1.30, 'jump_intensity' => 0.75, 'jump_mean' => -0.03, 'jump_vol' => 0.10,
            'baseline_roic' => 0.22, 'capex_ratio' => 0.45, 'target_payout_ratio' => 0.00, 'dividendSpeed' => 0.50,
            'corporate_treasury' => 22_000_000_000.00, 'debt_to_equity' => 0.30, 'operating_margin' => 0.12, 'public_float' => 0.88,
            'total_net_income'  => 12_900_000_000.00,
            'total_equity'      => 54_090_000_000.00,
            'retained_earnings' => 45_000_000_000.00
        ],
        [
            'ticker' => 'CROP', 'name' => 'Poultry Crop Operations', 'sector' => 'Consumer Staples', 'systemic_importance' => 'base',
            'price' => 190.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.16, 'beta' => 0.65, 'jump_intensity' => 0.50, 'jump_mean' => 0.02, 'jump_vol' => 0.05,
            'baseline_roic' => 0.10, 'capex_ratio' => 0.60, 'target_payout_ratio' => 0.30, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 1_400_000_000.00, 'debt_to_equity' => 1.50, 'operating_margin' => 0.06, 'public_float' => 0.90,
            'total_net_income'  => 11_000_000_000.00,  
            'total_equity'      => 100_000_000_000.00,
            'retained_earnings' => 60_000_000_000.00
        ],
        [
            'ticker' => 'BRKW', 'name' => 'Breakwater Trust', 'sector' => 'Financials', 'systemic_importance' => 'titan',
            'price' => 950.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.10, 'beta' => 0.40, 'jump_intensity' => 0.30, 'jump_mean' => 0.04, 'jump_vol' => 0.09,
            'baseline_roic' => 0.0607, 
            'capex_ratio' => 0.10, 'target_payout_ratio' => 0.25, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 38_000_000_000.00, 'debt_to_equity' => 0.50, 'operating_margin' => 0.40, 'public_float' => 0.40, 
            'total_net_income'  => 70_710_000_000.00,   
            'total_equity'      => 1_000_000_000_000.00, 
            'retained_earnings' => 600_000_000_000.00   
        ],
        [
            'ticker' => 'ELDE', 'name' => 'Elderbird Retirement Services', 'sector' => 'Health Care', 'systemic_importance' => 'none',
            'price' => 150.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.12, 'beta' => 0.40, 'jump_intensity' => 0.10, 'jump_mean' => -0.06, 'jump_vol' => 0.08,
            'baseline_roic' => 0.16, 'capex_ratio' => 0.40, 'target_payout_ratio' => 0.70, 'dividendSpeed' => 0.10,
            'corporate_treasury' => 1_200_000_000.00, 'debt_to_equity' => 1.90, 'operating_margin' => 0.16, 'public_float' => 0.85,
            'total_net_income'  => 7_890_000_000.00,   
            'total_equity'      => 49_310_000_000.00,
            'retained_earnings' => 400_000_000.00
        ],
        [
            'ticker' => 'SWFT', 'name' => 'Golden Swift Holdings', 'sector' => 'Consumer Staples', 'systemic_importance' => 'none',
            'price' => 185.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.14, 'beta' => 0.50, 'jump_intensity' => 0.05, 'jump_mean' => -0.04, 'jump_vol' => 0.06,
            'baseline_roic' => 0.28, 'capex_ratio' => 0.15, 'target_payout_ratio' => 0.65, 'dividendSpeed' => 0.10,
            'corporate_treasury' => 4_800_000_000.00, 'debt_to_equity' => 1.20, 'operating_margin' => 0.32, 'public_float' => 0.90,
            'total_net_income'  => 12_500_000_000.00,   
            'total_equity'      => 26_790_000_000.00,
            'retained_earnings' => 10_000_000_000.00
        ],
        [
            'ticker' => 'VULT', 'name' => 'Vulture Capital Recovery', 'sector' => 'Financials', 'systemic_importance' => 'systemic',
            'price' => 500.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.24, 'beta' => -0.65, 'jump_intensity' => 1.25, 'jump_mean' => 0.05, 'jump_vol' => 0.12,
            'baseline_roic' => 0.30, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 9_000_000_000.00, 'debt_to_equity' => 0.40, 'operating_margin' => 0.45, 'public_float' => 0.80,
            'total_net_income'  => 32_140_000_000.00,
            'total_equity'      => 107_130_000_000.00,
            'retained_earnings' => 35_000_000_000.00
        ],
        [
            'ticker' => 'FALC', 'name' => 'Falconet Motor Group', 'sector' => 'Consumer Discretionary', 'systemic_importance' => 'none',
            'price' => 210.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.35, 'beta' => 1.50, 'jump_intensity' => 1.40, 'jump_mean' => -0.06, 'jump_vol' => 0.15,
            'baseline_roic' => 0.08, 'capex_ratio' => 0.85, 'target_payout_ratio' => 0.00, 'dividendSpeed' => 0.50,
            'corporate_treasury' => 6_000_000_000.00, 'debt_to_equity' => 2.80, 'operating_margin' => 0.14, 'public_float' => 0.75,
            'total_net_income'  => 10_000_000_000.00,  
            'total_equity'      => 125_000_000_000.00,
            'retained_earnings' => 15_000_000_000.00
        ],
        [
            'ticker' => 'OSPR', 'name' => 'Osprey Global Vanguard', 'sector' => 'Industrials', 'systemic_importance' => 'systemic',
            'price' => 210.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.28, 'beta' => -0.15, 'jump_intensity' => 1.10, 'jump_mean' => 0.05, 'jump_vol' => 0.12,
            'baseline_roic' => 0.25, 'capex_ratio' => 0.20, 'target_payout_ratio' => 0.40, 'dividendSpeed' => 0.15,
            'corporate_treasury' => 5_500_000_000.00, 'debt_to_equity' => 1.10, 'operating_margin' => 0.22, 'public_float' => 0.90,
            'total_net_income'  => 12_000_000_000.00,   
            'total_equity'      => 32_000_000_000.00,
            'retained_earnings' => 12_000_000_000.00
        ],
        [
            'ticker' => 'CROW', 'name' => 'Crowfall Capital', 'sector' => 'Financials', 'systemic_importance' => 'systemic',
            'price' => 300.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.24, 'beta' => -0.50, 'jump_intensity' => 1.25, 'jump_mean' => 0.05, 'jump_vol' => 0.12,
            'baseline_roic' => 0.35, 'capex_ratio' => 0.05, 'target_payout_ratio' => 0.50, 'dividendSpeed' => 0.20,
            'corporate_treasury' => 14_000_000_000.00, 'debt_to_equity' => 0.60, 'operating_margin' => 0.48, 'public_float' => 0.85,
            'total_net_income'  => 20_430_000_000.00,
            'total_equity'      => 46_940_000_000.00,
            'retained_earnings' => 30_000_000_000.00
        ],
        [
            'ticker' => 'CNDR', 'name' => 'Condor Extraction', 'sector' => 'Materials', 'systemic_importance' => 'systemic',
            'price' => 300.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.32, 'beta' => 1.45, 'jump_intensity' => 0.60, 'jump_mean' => -0.18, 'jump_vol' => 0.15,
            'baseline_roic' => 0.14, 'capex_ratio' => 0.85, 'target_payout_ratio' => 0.10, 'dividendSpeed' => 0.40,
            'corporate_treasury' => 1_200_000_000.00, 'debt_to_equity' => 2.80, 'operating_margin' => 0.16, 'public_float' => 0.92,
            'total_net_income'  => 18_670_000_000.00,
            'total_equity'      => 119_070_000_000.00,
            'retained_earnings' => 70_000_000_000.00
        ],
        [
            'ticker' => 'SILC', 'name' => 'Silicon Creek Foundries', 'sector' => 'Information Technology', 'systemic_importance' => 'systemic',
            'price' => 400.00, 'shares_outstanding' => 1_000_000_000,
            'volatility' => 0.30, 'beta' => 1.50, 'jump_intensity' => 1.25, 'jump_mean' => 0.04, 'jump_vol' => 0.15,
            'baseline_roic' => 0.18, 'capex_ratio' => 0.80, 'target_payout_ratio' => 0.15, 'dividendSpeed' => 0.35,
            'corporate_treasury' => 18_000_000_000.00, 'debt_to_equity' => 0.80, 'operating_margin' => 0.42, 'public_float' => 0.90,
            'total_net_income'  => 16_080_000_000.00,
            'total_equity'      => 67_110_000_000.00,
            'retained_earnings' => 60_000_000_000.00
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
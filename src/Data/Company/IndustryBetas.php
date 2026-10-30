<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * Industry betas, the level every listed firm's beta is built on (Damodaran's bottom-up beta): the industry's average
 * regression beta is far more precise than any one firm's, and is re-levered at the firm's own capital structure.
 *
 * Source: Damodaran, "Betas by Sector (US)", January 2026 (pages.stern.nyu.edu/~adamodar, 5,994 firms). The table
 * unlevers at a 25% marginal tax rate and corrects for cash, so its cash-corrected unlevered beta is the beta of the
 * operating assets, the one Hamada re-levers at NET debt.
 *
 * What the stock's beta column holds follows from what its model does with it. A model that re-levers through Hamada
 * every tick (StandardDebtPhysicsTrait) stores the asset beta; a financial institution, whose deposits and wholesale
 * book are operating liabilities rather than capital structure (BaseFinancialBusinessModel), stores its industry's
 * levered beta, as Damodaran prices banks and insurers.
 */
final class IndustryBetas
{
    // --- Source ---
    /** The marginal tax rate the table unlevers at: US federal 21% plus state (Damodaran, January 2026). */
    public const DAMODARAN_MARGINAL_TAX_RATE = 0.25;

    // --- Lore Band ---
    /**
     * Half-width of the band lore may place a firm's beta in around its industry's: 1.96 standard errors of a 60-month
     * regression beta, sqrt(0.22^2 - (0.91 x 0.15)^2) / (0.15 x sqrt(60)) = 0.148, at the board's median volatility
     * (0.22), the market's (0.15) and the US average beta (0.91).
     */
    public const LORE_BAND = 0.29;

    /**
     * Damodaran's US industries the board maps to: number of firms, average levered regression beta, market D/E,
     * cash over firm value, and the unlevered beta corrected for cash.
     *
     * @var array<string, array{firms: int, levered: float, de: float, cash: float, unlevered_cash: float}>
     */
    public const DAMODARAN = [
        'Advertising' => ['firms' => 52, 'levered' => 1.21, 'de' => 0.4020, 'cash' => 0.0773, 'unlevered_cash' => 1.01],
        'Aerospace/Defense' => ['firms' => 79, 'levered' => 0.95, 'de' => 0.1556, 'cash' => 0.0261, 'unlevered_cash' => 0.87],
        'Air Transport' => ['firms' => 23, 'levered' => 1.19, 'de' => 0.9117, 'cash' => 0.0711, 'unlevered_cash' => 0.76],
        'Apparel' => ['firms' => 35, 'levered' => 0.94, 'de' => 0.3129, 'cash' => 0.0460, 'unlevered_cash' => 0.79],
        'Auto & Truck' => ['firms' => 33, 'levered' => 1.46, 'de' => 0.1970, 'cash' => 0.0299, 'unlevered_cash' => 1.31],
        'Auto Parts' => ['firms' => 35, 'levered' => 1.34, 'de' => 0.4146, 'cash' => 0.0945, 'unlevered_cash' => 1.13],
        'Bank (Money Center)' => ['firms' => 15, 'levered' => 0.76, 'de' => 1.6419, 'cash' => 0.2317, 'unlevered_cash' => 0.44],
        'Banks (Regional)' => ['firms' => 568, 'levered' => 0.40, 'de' => 0.5210, 'cash' => 0.2348, 'unlevered_cash' => 0.37],
        'Beverage (Alcoholic)' => ['firms' => 14, 'levered' => 0.81, 'de' => 0.4334, 'cash' => 0.0237, 'unlevered_cash' => 0.63],
        'Beverage (Soft)' => ['firms' => 27, 'levered' => 0.64, 'de' => 0.2059, 'cash' => 0.0344, 'unlevered_cash' => 0.58],
        'Broadcasting' => ['firms' => 24, 'levered' => 0.47, 'de' => 0.8585, 'cash' => 0.0918, 'unlevered_cash' => 0.32],
        'Brokerage & Investment Banking' => ['firms' => 32, 'levered' => 1.17, 'de' => 1.3557, 'cash' => 0.1451, 'unlevered_cash' => 0.68],
        'Building Materials' => ['firms' => 41, 'levered' => 1.11, 'de' => 0.2600, 'cash' => 0.0316, 'unlevered_cash' => 0.96],
        'Business & Consumer Services' => ['firms' => 155, 'levered' => 0.89, 'de' => 0.1972, 'cash' => 0.0402, 'unlevered_cash' => 0.81],
        'Chemical (Basic)' => ['firms' => 29, 'levered' => 1.01, 'de' => 0.9935, 'cash' => 0.0891, 'unlevered_cash' => 0.64],
        'Chemical (Specialty)' => ['firms' => 59, 'levered' => 0.97, 'de' => 0.2988, 'cash' => 0.0391, 'unlevered_cash' => 0.82],
        'Computer Services' => ['firms' => 64, 'levered' => 1.09, 'de' => 0.2510, 'cash' => 0.0480, 'unlevered_cash' => 0.96],
        'Computers/Peripherals' => ['firms' => 36, 'levered' => 1.35, 'de' => 0.0462, 'cash' => 0.0147, 'unlevered_cash' => 1.32],
        'Diversified' => ['firms' => 20, 'levered' => 0.88, 'de' => 0.1555, 'cash' => 0.0642, 'unlevered_cash' => 0.84],
        'Drugs (Biotechnology)' => ['firms' => 496, 'levered' => 1.14, 'de' => 0.1304, 'cash' => 0.0420, 'unlevered_cash' => 1.08],
        'Drugs (Pharmaceutical)' => ['firms' => 228, 'levered' => 0.98, 'de' => 0.1454, 'cash' => 0.0316, 'unlevered_cash' => 0.92],
        'Education' => ['firms' => 32, 'levered' => 0.78, 'de' => 0.2438, 'cash' => 0.0826, 'unlevered_cash' => 0.72],
        'Electrical Equipment' => ['firms' => 112, 'levered' => 1.25, 'de' => 0.1200, 'cash' => 0.0353, 'unlevered_cash' => 1.19],
        'Electronics (General)' => ['firms' => 114, 'levered' => 0.97, 'de' => 0.1101, 'cash' => 0.0428, 'unlevered_cash' => 0.94],
        'Engineering/Construction' => ['firms' => 48, 'levered' => 1.21, 'de' => 0.1401, 'cash' => 0.0374, 'unlevered_cash' => 1.14],
        'Entertainment' => ['firms' => 92, 'levered' => 0.83, 'de' => 0.1591, 'cash' => 0.0336, 'unlevered_cash' => 0.76],
        'Environmental & Waste Services' => ['firms' => 53, 'levered' => 0.95, 'de' => 0.2145, 'cash' => 0.0125, 'unlevered_cash' => 0.82],
        'Farming/Agriculture' => ['firms' => 35, 'levered' => 1.13, 'de' => 0.5185, 'cash' => 0.0387, 'unlevered_cash' => 0.85],
        'Financial Svcs. (Non-bank & Insurance)' => ['firms' => 176, 'levered' => 0.97, 'de' => 2.7213, 'cash' => 0.0269, 'unlevered_cash' => 0.33],
        'Food Processing' => ['firms' => 78, 'levered' => 0.61, 'de' => 0.4373, 'cash' => 0.0256, 'unlevered_cash' => 0.47],
        'Food Wholesalers' => ['firms' => 13, 'levered' => 0.87, 'de' => 0.4697, 'cash' => 0.0106, 'unlevered_cash' => 0.65],
        'Furn/Home Furnishings' => ['firms' => 27, 'levered' => 0.82, 'de' => 0.4233, 'cash' => 0.0419, 'unlevered_cash' => 0.65],
        'Green & Renewable Energy' => ['firms' => 15, 'levered' => 0.86, 'de' => 1.1311, 'cash' => 0.0190, 'unlevered_cash' => 0.47],
        'Healthcare Products' => ['firms' => 204, 'levered' => 0.91, 'de' => 0.1279, 'cash' => 0.0325, 'unlevered_cash' => 0.86],
        'Healthcare Support Services' => ['firms' => 104, 'levered' => 0.87, 'de' => 0.3543, 'cash' => 0.0751, 'unlevered_cash' => 0.74],
        'Heathcare Information and Technology' => ['firms' => 115, 'levered' => 1.11, 'de' => 0.1574, 'cash' => 0.0246, 'unlevered_cash' => 1.02],
        'Homebuilding' => ['firms' => 30, 'levered' => 0.91, 'de' => 0.2134, 'cash' => 0.0800, 'unlevered_cash' => 0.85],
        'Hospitals/Healthcare Facilities' => ['firms' => 31, 'levered' => 0.80, 'de' => 0.5992, 'cash' => 0.0232, 'unlevered_cash' => 0.56],
        'Hotel/Gaming' => ['firms' => 63, 'levered' => 1.08, 'de' => 0.3975, 'cash' => 0.0496, 'unlevered_cash' => 0.88],
        'Household Products' => ['firms' => 110, 'levered' => 0.82, 'de' => 0.1815, 'cash' => 0.0314, 'unlevered_cash' => 0.74],
        'Information Services' => ['firms' => 15, 'levered' => 0.92, 'de' => 0.3317, 'cash' => 0.0254, 'unlevered_cash' => 0.76],
        'Insurance (General)' => ['firms' => 21, 'levered' => 0.67, 'de' => 0.2563, 'cash' => 0.0250, 'unlevered_cash' => 0.58],
        'Insurance (Life)' => ['firms' => 20, 'levered' => 0.64, 'de' => 0.6784, 'cash' => 0.1967, 'unlevered_cash' => 0.53],
        'Insurance (Prop/Cas.)' => ['firms' => 57, 'levered' => 0.48, 'de' => 0.1483, 'cash' => 0.0468, 'unlevered_cash' => 0.46],
        'Investments & Asset Management' => ['firms' => 283, 'levered' => 0.66, 'de' => 0.3269, 'cash' => 0.0992, 'unlevered_cash' => 0.59],
        'Machinery' => ['firms' => 105, 'levered' => 0.96, 'de' => 0.1469, 'cash' => 0.0291, 'unlevered_cash' => 0.89],
        'Metals & Mining' => ['firms' => 73, 'levered' => 1.04, 'de' => 0.1098, 'cash' => 0.0463, 'unlevered_cash' => 1.01],
        'Office Equipment & Services' => ['firms' => 14, 'levered' => 1.33, 'de' => 0.4810, 'cash' => 0.0555, 'unlevered_cash' => 1.04],
        'Oil/Gas (Integrated)' => ['firms' => 4, 'levered' => 0.30, 'de' => 0.1385, 'cash' => 0.0244, 'unlevered_cash' => 0.28],
        'Oil/Gas (Production and Exploration)' => ['firms' => 142, 'levered' => 0.72, 'de' => 0.3759, 'cash' => 0.0273, 'unlevered_cash' => 0.58],
        'Oil/Gas Distribution' => ['firms' => 23, 'levered' => 0.67, 'de' => 0.5853, 'cash' => 0.0089, 'unlevered_cash' => 0.47],
        'Oilfield Svcs/Equip.' => ['firms' => 97, 'levered' => 0.95, 'de' => 0.3736, 'cash' => 0.0559, 'unlevered_cash' => 0.79],
        'Packaging & Container' => ['firms' => 19, 'levered' => 1.02, 'de' => 0.5511, 'cash' => 0.0346, 'unlevered_cash' => 0.75],
        'Power' => ['firms' => 46, 'levered' => 0.48, 'de' => 0.7415, 'cash' => 0.0145, 'unlevered_cash' => 0.31],
        'Precious Metals' => ['firms' => 56, 'levered' => 0.84, 'de' => 0.0728, 'cash' => 0.0453, 'unlevered_cash' => 0.83],
        'Publishing & Newspapers' => ['firms' => 19, 'levered' => 0.56, 'de' => 0.2394, 'cash' => 0.0675, 'unlevered_cash' => 0.51],
        'R.E.I.T.' => ['firms' => 190, 'levered' => 0.64, 'de' => 0.8446, 'cash' => 0.0191, 'unlevered_cash' => 0.40],
        'Real Estate (Development)' => ['firms' => 14, 'levered' => 0.84, 'de' => 1.0183, 'cash' => 0.1521, 'unlevered_cash' => 0.56],
        'Real Estate (Operations & Services)' => ['firms' => 54, 'levered' => 0.97, 'de' => 0.2464, 'cash' => 0.0498, 'unlevered_cash' => 0.86],
        'Recreation' => ['firms' => 49, 'levered' => 1.02, 'de' => 0.6299, 'cash' => 0.0562, 'unlevered_cash' => 0.74],
        'Restaurant/Dining' => ['firms' => 64, 'levered' => 0.92, 'de' => 0.2722, 'cash' => 0.0199, 'unlevered_cash' => 0.78],
        'Retail (Automotive)' => ['firms' => 34, 'levered' => 0.94, 'de' => 0.4536, 'cash' => 0.0208, 'unlevered_cash' => 0.71],
        'Retail (Building Supply)' => ['firms' => 14, 'levered' => 1.54, 'de' => 0.2329, 'cash' => 0.0081, 'unlevered_cash' => 1.32],
        'Retail (Distributors)' => ['firms' => 62, 'levered' => 0.95, 'de' => 0.2824, 'cash' => 0.0236, 'unlevered_cash' => 0.80],
        'Retail (General)' => ['firms' => 23, 'levered' => 0.81, 'de' => 0.0794, 'cash' => 0.0268, 'unlevered_cash' => 0.78],
        'Retail (Grocery and Food)' => ['firms' => 15, 'levered' => 1.12, 'de' => 0.5195, 'cash' => 0.0514, 'unlevered_cash' => 0.85],
        'Retail (REITs)' => ['firms' => 26, 'levered' => 0.62, 'de' => 0.5642, 'cash' => 0.0148, 'unlevered_cash' => 0.44],
        'Retail (Special Lines)' => ['firms' => 94, 'levered' => 1.09, 'de' => 0.1976, 'cash' => 0.0530, 'unlevered_cash' => 1.00],
        'Semiconductor' => ['firms' => 66, 'levered' => 1.52, 'de' => 0.0259, 'cash' => 0.0102, 'unlevered_cash' => 1.50],
        'Semiconductor Equip' => ['firms' => 31, 'levered' => 1.40, 'de' => 0.0486, 'cash' => 0.0313, 'unlevered_cash' => 1.39],
        'Shipbuilding & Marine' => ['firms' => 8, 'levered' => 0.75, 'de' => 0.2255, 'cash' => 0.0254, 'unlevered_cash' => 0.66],
        'Shoe' => ['firms' => 11, 'levered' => 1.02, 'de' => 0.1194, 'cash' => 0.0665, 'unlevered_cash' => 1.00],
        'Software (Entertainment)' => ['firms' => 77, 'levered' => 1.03, 'de' => 0.0204, 'cash' => 0.0078, 'unlevered_cash' => 1.02],
        'Software (Internet)' => ['firms' => 29, 'levered' => 1.69, 'de' => 0.1230, 'cash' => 0.0280, 'unlevered_cash' => 1.59],
        'Software (System & Application)' => ['firms' => 309, 'levered' => 1.28, 'de' => 0.0558, 'cash' => 0.0183, 'unlevered_cash' => 1.25],
        'Steel' => ['firms' => 19, 'levered' => 1.06, 'de' => 0.2351, 'cash' => 0.0438, 'unlevered_cash' => 0.94],
        'Telecom. Equipment' => ['firms' => 57, 'levered' => 0.92, 'de' => 0.0922, 'cash' => 0.0268, 'unlevered_cash' => 0.89],
        'Telecom. Services' => ['firms' => 39, 'levered' => 0.63, 'de' => 0.9606, 'cash' => 0.0421, 'unlevered_cash' => 0.38],
        'Tobacco' => ['firms' => 10, 'levered' => 0.79, 'de' => 0.2297, 'cash' => 0.0184, 'unlevered_cash' => 0.69],
        'Transportation' => ['firms' => 19, 'levered' => 0.86, 'de' => 0.3645, 'cash' => 0.0509, 'unlevered_cash' => 0.71],
        'Transportation (Railroads)' => ['firms' => 4, 'levered' => 0.98, 'de' => 0.2779, 'cash' => 0.0083, 'unlevered_cash' => 0.81],
        'Trucking' => ['firms' => 26, 'levered' => 1.01, 'de' => 0.2523, 'cash' => 0.0212, 'unlevered_cash' => 0.87],
        'Utility (General)' => ['firms' => 14, 'levered' => 0.24, 'de' => 0.8148, 'cash' => 0.0033, 'unlevered_cash' => 0.15],
        'Utility (Water)' => ['firms' => 14, 'levered' => 0.41, 'de' => 0.6236, 'cash' => 0.0046, 'unlevered_cash' => 0.28],
        'Total Market (without financials)' => ['firms' => 4822, 'levered' => 0.99, 'de' => 0.1729, 'cash' => 0.0260, 'unlevered_cash' => 0.90],
    ];

    /**
     * Each industry in Sectors::INDUSTRY_METRICS against the Damodaran industry that holds its US peers.
     *
     * @var array<string, string>
     */
    public const INDUSTRY_MAP = [
        'Advertising Agencies' => 'Advertising',
        'Aerospace & Defense' => 'Aerospace/Defense',
        'Agricultural Inputs' => 'Chemical (Basic)',
        'Airlines' => 'Air Transport',
        'Aluminum' => 'Metals & Mining',
        'Apparel Manufacturing' => 'Apparel',
        'Apparel Retail' => 'Retail (Special Lines)',
        'Asset Management' => 'Investments & Asset Management',
        'Auto Manufacturers' => 'Auto & Truck',
        'Auto Parts' => 'Auto Parts',
        'Auto & Truck Dealerships' => 'Retail (Automotive)',
        'Banks - Diversified' => 'Bank (Money Center)',
        'Banks - Regional' => 'Banks (Regional)',
        'Beverages - Brewers' => 'Beverage (Alcoholic)',
        'Beverages - Non-Alcoholic' => 'Beverage (Soft)',
        'Beverages - Wineries & Distilleries' => 'Beverage (Alcoholic)',
        'Biotechnology' => 'Drugs (Biotechnology)',
        'Broadcasting' => 'Broadcasting',
        'Brokerages' => 'Brokerage & Investment Banking',
        'Building Materials' => 'Building Materials',
        'Building Products & Equipment' => 'Building Materials',
        'Business Equipment & Supplies' => 'Office Equipment & Services',
        'Chemicals' => 'Chemical (Basic)',
        'Communication Equipment' => 'Telecom. Equipment',
        'Computer Hardware' => 'Computers/Peripherals',
        'Conglomerates' => 'Diversified',
        'Consulting Services' => 'Business & Consumer Services',
        'Correctional Services' => 'Business & Consumer Services',
        'Copper' => 'Metals & Mining',
        'Credit Services' => 'Financial Svcs. (Non-bank & Insurance)',
        'Custody Banks' => 'Bank (Money Center)',
        'Diagnostics & Research' => 'Healthcare Support Services',
        'Discount Stores' => 'Retail (General)',
        'Distressed Debt' => 'Investments & Asset Management',
        'Drug Manufacturers - General' => 'Drugs (Pharmaceutical)',
        'Drug Manufacturers - Specialty & Generic' => 'Drugs (Pharmaceutical)',
        'Education & Training Services' => 'Education',
        'Electrical Equipment & Parts' => 'Electrical Equipment',
        'Electronic Components' => 'Electronics (General)',
        'Electronic Gaming & Multimedia' => 'Software (Entertainment)',
        'Electronics & Computer Distribution' => 'Retail (Distributors)',
        'Engineering & Construction' => 'Engineering/Construction',
        'Entertainment' => 'Entertainment',
        'Farm & Heavy Construction Machinery' => 'Machinery',
        'Farm Products' => 'Farming/Agriculture',
        'Financial Data & Ratings' => 'Information Services',
        'Financial Clearinghouses' => 'Financial Svcs. (Non-bank & Insurance)',
        'Securities & Commodity Exchanges' => 'Financial Svcs. (Non-bank & Insurance)',
        'Food Distribution' => 'Food Wholesalers',
        'Footwear & Accessories' => 'Shoe',
        'Furnishings, Fixtures & Appliances' => 'Furn/Home Furnishings',
        'Gambling' => 'Hotel/Gaming',
        'Gold' => 'Precious Metals',
        'Grocery Stores' => 'Retail (Grocery and Food)',
        'Hedge Fund' => 'Investments & Asset Management',
        'Healthcare Plans' => 'Healthcare Support Services',
        'Health Information Services' => 'Heathcare Information and Technology',
        'Home Improvement Retail' => 'Retail (Building Supply)',
        'Household & Personal Products' => 'Household Products',
        'Industrial Distribution' => 'Retail (Distributors)',
        'Information Technology Services' => 'Computer Services',
        'Investment Banking' => 'Brokerage & Investment Banking',
        'Investment Companies' => 'Diversified',
        'Insurance Brokers' => 'Insurance (General)',
        'Insurance - Diversified' => 'Insurance (General)',
        'Insurance - Life' => 'Insurance (Life)',
        'Insurance - Property & Casualty' => 'Insurance (Prop/Cas.)',
        // Damodaran's Reinsurance row is a single firm; the 57 property-casualty underwriters are the average.
        'Insurance - Reinsurance' => 'Insurance (Prop/Cas.)',
        'Insurance - Specialty' => 'Insurance (Prop/Cas.)',
        'Integrated Freight & Logistics' => 'Transportation',
        'Internet Content & Information' => 'Software (Internet)',
        // Damodaran files online retail inside Retail (General).
        'Internet Retail' => 'Retail (General)',
        'Legal Services' => 'Business & Consumer Services',
        'Leisure' => 'Recreation',
        'Lodging' => 'Hotel/Gaming',
        'Luxury Goods' => 'Apparel',
        'Marine Ports & Services' => 'Transportation',
        'Marine Shipping' => 'Shipbuilding & Marine',
        'Medical Care Facilities' => 'Hospitals/Healthcare Facilities',
        'Medical Devices' => 'Healthcare Products',
        'Medical Distribution' => 'Healthcare Support Services',
        'Medical Instruments & Supplies' => 'Healthcare Products',
        'Merchant Houses' => 'Diversified',
        'Metal Fabrication' => 'Steel',
        'Mortgage Finance' => 'Financial Svcs. (Non-bank & Insurance)',
        'Nuclear Power Equipment' => 'Machinery',
        'Oil & Gas E&P' => 'Oil/Gas (Production and Exploration)',
        'Oil & Gas Equipment & Services' => 'Oilfield Svcs/Equip.',
        'Oil & Gas Integrated' => 'Oil/Gas (Integrated)',
        'Oil & Gas Midstream' => 'Oil/Gas Distribution',
        'Oil & Gas Refining & Marketing' => 'Oil/Gas Distribution',
        'Packaged Foods' => 'Food Processing',
        'Packaging & Containers' => 'Packaging & Container',
        'Passenger Transit' => 'Transportation (Railroads)',
        'Personal Services' => 'Business & Consumer Services',
        'Pollution & Treatment Controls' => 'Environmental & Waste Services',
        'Private Banks' => 'Bank (Money Center)',
        'Private Equity' => 'Investments & Asset Management',
        'Publishing' => 'Publishing & Newspapers',
        'Railroads' => 'Transportation (Railroads)',
        'Real Estate - Development' => 'Real Estate (Development)',
        'Real Estate Services' => 'Real Estate (Operations & Services)',
        'Recreational Vehicles' => 'Recreation',
        'REIT - Diversified' => 'R.E.I.T.',
        'REIT - Healthcare Facilities' => 'R.E.I.T.',
        'REIT - Hotel & Motel' => 'R.E.I.T.',
        'REIT - Industrial' => 'R.E.I.T.',
        'REIT - Mortgage' => 'R.E.I.T.',
        'REIT - Office' => 'R.E.I.T.',
        'REIT - Residential' => 'R.E.I.T.',
        'REIT - Retail' => 'Retail (REITs)',
        'REIT - Specialty' => 'R.E.I.T.',
        'Rental & Leasing Services' => 'Business & Consumer Services',
        'Residential Construction' => 'Homebuilding',
        'Resorts & Casinos' => 'Hotel/Gaming',
        'Restaurants' => 'Restaurant/Dining',
        'Scientific & Technical Instruments' => 'Electronics (General)',
        'Security & Protection Services' => 'Business & Consumer Services',
        'Semiconductor Equipment & Materials' => 'Semiconductor Equip',
        'Semiconductors' => 'Semiconductor',
        'Software - Application' => 'Software (System & Application)',
        'Software - Infrastructure' => 'Software (System & Application)',
        'Solar' => 'Green & Renewable Energy',
        'Space Systems' => 'Aerospace/Defense',
        'Specialty Business Services' => 'Business & Consumer Services',
        'Specialty Chemicals' => 'Chemical (Specialty)',
        'Specialty Industrial Machinery' => 'Machinery',
        'Specialty Retail' => 'Retail (Special Lines)',
        'Staffing & Employment Services' => 'Business & Consumer Services',
        'Steel' => 'Steel',
        'Telecom Services' => 'Telecom. Services',
        'Tobacco' => 'Tobacco',
        'Tools & Accessories' => 'Machinery',
        'Travel Services' => 'Hotel/Gaming',
        'Trucking' => 'Trucking',
        'Utilities - Independent Power Producers' => 'Power',
        'Utilities - Diversified' => 'Utility (General)',
        'Utilities - Regulated Electric' => 'Power',
        'Utilities - Regulated Gas' => 'Utility (General)',
        'Utilities - Regulated Water' => 'Utility (Water)',
        'Waste Management' => 'Environmental & Waste Services',
        'General' => 'Total Market (without financials)',
    ];

    /** The Damodaran industry an industry's US peers sit in; an unlisted industry reads as General, as Sectors does. */
    public static function damodaranIndustryFor(?string $industry): string
    {
        return self::INDUSTRY_MAP[$industry ?: 'General'] ?? self::INDUSTRY_MAP['General'];
    }

    /**
     * The industry beta a firm's beta is built on, in the form its model consumes: the operating-asset beta for a
     * model that re-levers it through Hamada at the firm's own net debt, the levered beta for one that does not.
     */
    public static function anchorFor(?string $industry): float
    {
        $row = self::DAMODARAN[self::damodaranIndustryFor($industry)];

        return Sectors::isFinancial(Sectors::businessModelFor($industry)) ? $row['levered'] : $row['unlevered_cash'];
    }

    /** A lore position held to the band: lore picks where in it a firm sits, never how far outside. */
    public static function clampTilt(float $tilt): float
    {
        return max(-self::LORE_BAND, min(self::LORE_BAND, $tilt));
    }

    /** The beta a firm opens with: its industry's anchor plus its lore position within the band. */
    public static function seedBeta(?string $industry, float $tilt): float
    {
        return self::anchorFor($industry) + self::clampTilt($tilt);
    }

    /**
     * The beta a seed row opens with.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function seedBetaFor(array $row): float
    {
        return self::seedBeta($row['industry'] ?? null, (float) ($row['beta_tilt'] ?? 0.0));
    }
}

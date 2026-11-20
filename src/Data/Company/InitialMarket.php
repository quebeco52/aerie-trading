<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * The listed firms as they open. Every operating firm, lender, insurer and fee financial states its character instead
 * of hand-set books, and its business model's resolveOpeningBooks() builds the balance sheet, return and market share
 * that character implies (only spheres like BRKW still state their books directly). Read the resolved books through
 * App\Service\Market\Pricing\SeedRows. A `moat` names the firm's step on the ladder (App\Data\Company\Moat) where it
 * is not its industry's (IndustryMoats); `risk` and `events` shift the firm's opening business-risk and event-risk
 * steps by ±1 around its industry's (IndustryRisks).
 */
class InitialMarket
{
    public const STOCKS = [
        [
            'ticker' => 'LAKE',
            // Systemic balance-sheet supremacy is maintained by hoarding capital, not distributing it
            'management_style' => 'fortress',
            'name' => 'Lakebird Bank',
            'industry' => 'Banks - Diversified',
            'systemic_importance' => 'titan',
            'moat' => 'wide', // ROE 18% at the opening, two steps past a money-centre bank's
            'capex_ratio' => 0.08,
            'fixed_cost_ratio' => 0.40,
            'public_float' => 0.50,
            'addressable_share' => 0.43,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.025,
            // Its books are built on this character by its model (CommercialBankBusinessModel::resolveOpeningBooks()).
            'revenue' => 754_000_000_000.00,
            'wholesale_funding_share' => 0.018,
            'retained_share' => 0.68,
        ],
        [
            'ticker' => 'SWAN',
            'name' => 'Black Swan Capital',
            'industry' => 'Hedge Fund',
            'systemic_importance' => 'titan',
            'beta_tilt' => 0.25, // leveraged long/short books
            'moat' => 'wide',
            'capex_ratio' => 0.05,
            'fixed_cost_ratio' => 0.45,
            'operating_margin' => 0.40,
            'public_float' => 0.50,
            'floating_debt_ratio' => 0.50,
            'historical_fixed_rate' => 0.030,
            // Its books are built on this character by its model (AssetManagementBusinessModel::resolveOpeningBooks()).
            'revenue' => 383_000_000_000.00,
            'retained_share' => 0.25,
        ],
        [
            'ticker' => 'HUMM',
            // Ruthless expansion from search into every adjacent digital utility
            'management_style' => 'empire_builder',
            'name' => 'Hummingbird Interactive',
            'industry' => 'Internet Content & Information',
            'systemic_importance' => 'titan',
            'beta_tilt' => -0.25, // steadier cloud contracts, less volatile than smaller software names
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.85,
            'revenue' => 82_800_000_000.00,
            'addressable_share' => 0.293,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.035,
        ],
        [
            'ticker' => 'OWLS',
            'management_style' => 'fortress',
            'name' => 'Owl Capital Partners',
            'sector' => 'Financials', // a conglomerate run as an investment house, filed with them
            'industry' => 'Conglomerates',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // a contrarian holder that buys when prices fall
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.35,
            'revenue' => 61_400_000_000.00,
            'addressable_share' => 0.204,
            'public_float' => 0.80,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.030,
            'depreciation_rate' => 0.03,
        ],
        [
            'ticker' => 'KING',
            'name' => 'Kingfisher Capital',
            'industry' => 'Investment Banking',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.25, // among the most cyclical deal franchises
            'risk' => 1,
            'moat' => 'moderate',
            'capex_ratio' => 0.05,
            'payout' => 'low',
            'fixed_cost_ratio' => 0.45,
            'operating_margin' => 0.40,
            'public_float' => 0.95,
            'addressable_share' => 0.333,
            'floating_debt_ratio' => 0.65,
            'historical_fixed_rate' => 0.035,
            // Its books are built on this character by its model (BrokerageBusinessModel::resolveOpeningBooks()).
            'revenue' => 42_800_000_000.00,
            'retained_share' => 0.30,
        ],
        [
            'ticker' => 'PERE',
            'name' => 'Peregrine Prime Securities',
            'industry' => 'Investment Banking',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // a delta-neutral market maker that earns on volatility
            'risk' => -1,
            'moat' => 'moderate',
            'capex_ratio' => 0.15,
            'fixed_cost_ratio' => 0.60,
            'operating_margin' => 0.35,
            'public_float' => 0.88,
            'addressable_share' => 0.267,
            'floating_debt_ratio' => 0.80,
            'historical_fixed_rate' => 0.060,
            // Its books are built on this character by its model (BrokerageBusinessModel::resolveOpeningBooks()).
            'revenue' => 30_200_000_000.00,
            'retained_share' => 0.225,
        ],
        [
            'ticker' => 'CORV',
            'name' => 'Corvid Capital',
            'industry' => 'Investment Banking',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // restructuring fees run against the cycle
            'risk' => -1,
            'moat' => 'moderate',
            'capex_ratio' => 0.10,
            'fixed_cost_ratio' => 0.55,
            'operating_margin' => 0.45,
            'addressable_share' => 0.28,
            'floating_debt_ratio' => 0.85,
            'historical_fixed_rate' => 0.045,
            // Its books are built on this character by its model (BrokerageBusinessModel::resolveOpeningBooks()).
            'revenue' => 25_500_000_000.00,
            'retained_share' => 0.26,
        ],
        [
            'ticker' => 'RIVR',
            'name' => 'Riverstone Financial',
            'industry' => 'Banks - Regional',
            'systemic_importance' => 'none',
            'beta_tilt' => 0.25, // one region, smaller borrowers, commercial property
            'risk' => 1,
            'moat' => 'moderate', // ROE 13.6% at the opening: SME lending regional rivals do not reach
            'capex_ratio' => 0.10,
            'payout' => 'balanced',
            'fixed_cost_ratio' => 0.40,
            'public_float' => 0.95,
            // It opens at 45% of its market, inside the share past which a lender's returns fall.
            'addressable_share' => 0.45,
            'floating_debt_ratio' => 0.35,
            'historical_fixed_rate' => 0.035,
            // Its books are built on this character by its model (CommercialBankBusinessModel::resolveOpeningBooks()).
            'revenue' => 86_000_000_000.00,
            'wholesale_funding_share' => 0.012,
            'retained_share' => 0.07,
        ],
        [
            'ticker' => 'PLVR',
            // A savings bank's owners are its depositors' neighbours: steady dividends, no empire
            'management_style' => 'steward',
            'name' => 'Plover Savings Bank',
            'industry' => 'Banks - Regional',
            // The District's second home lender: a domestic systemically important bank
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.15, // long fixed-rate mortgages on short deposits
            'moat' => 'narrow', // ROE 10% at the opening: the second home lender's branch network
            'capex_ratio' => 0.08,
            'fixed_cost_ratio' => 0.45,
            'public_float' => 0.90,
            'addressable_share' => 0.35, // More headroom than RIVR and LAKE open with
            'floating_debt_ratio' => 0.15, // Covered bonds are fixed-rate
            'historical_fixed_rate' => 0.028,
            // Its books are built on this character by its model (CommercialBankBusinessModel::resolveOpeningBooks()).
            'revenue' => 137_000_000_000.00,
            'wholesale_funding_share' => 0.13, // Covered bonds
            'retained_share' => 0.33,
        ],
        [
            'ticker' => 'SAFE',
            'management_style' => 'fortress',
            'name' => 'Safe Harbor Reinsurance',
            'industry' => 'Insurance - Reinsurance',
            'systemic_importance' => 'titan',
            'beta_tilt' => -0.25, // losses follow catastrophes, not the business cycle
            'moat' => 'wide',
            'capex_ratio' => 0.02,
            'fixed_cost_ratio' => 0.20,
            // A 98.5% combined ratio through the cycle: large reinsurers' have averaged near 98-100% over the last
            // decade (86-95% in benign years, 105-120% in heavy catastrophe years), pricing their cover near break-even
            // and earning mainly on the float they invest.
            'operating_margin' => 0.015,
            'public_float' => 0.70,
            'addressable_share' => 0.431,
            'floating_debt_ratio' => 0.05,
            'historical_fixed_rate' => 0.025,
            // Its books are built on this character by its model (InsuranceBusinessModel::resolveOpeningBooks()).
            'revenue' => 590_000_000_000.00,
            'retained_share' => 0.20,
        ],
        [
            'ticker' => 'DOVE',
            'management_style' => 'fortress', // Strict underwriting and conservative float investment ahead of growth
            'name' => 'White Dove Insurance',
            'industry' => 'Insurance - Diversified',
            'systemic_importance' => 'base',
            'capex_ratio' => 0.05,
            'fixed_cost_ratio' => 0.35,
            // 95.5% combined ratio; European composites' P&C books run about 93-95%.
            'operating_margin' => 0.045,
            'public_float' => 0.90,
            'addressable_share' => 0.34,
            'floating_debt_ratio' => 0.10,
            'historical_fixed_rate' => 0.030,
            // Its liabilities are not marked to the curve, so it elects to keep the bond mark out of its capital ratio.
            'aoci_filtered' => true,
            // Its books are built on this character by its model (InsuranceBusinessModel::resolveOpeningBooks()).
            'revenue' => 121_000_000_000.00,
            'retained_share' => 0.40,
        ],
        [
            'ticker' => 'SHRK',
            // A statutory monopoly running near-zero capex has no use for retained capital
            'management_style' => 'steward',
            'name' => 'Shrike Standard Ratings',
            'industry' => 'Financial Data & Ratings',
            'systemic_importance' => 'systemic',
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.55,
            'revenue' => 8_670_000_000.00,
            'addressable_share' => 0.132,
            'public_float' => 0.92,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.035,
        ],
        [
            'ticker' => 'BIRD',
            'name' => 'Bird Power Inc',
            'industry' => 'Utilities - Regulated Electric',
            'systemic_importance' => 'base',
            // a regulated electric utility: 15.0% (Damodaran, Utility (General), US, January 2026)
            'moat' => 'narrow',
            'fixed_cost_ratio' => 0.80,
            'revenue' => 63_600_000_000.00,
            'addressable_share' => 0.281,
            'public_float' => 0.99,
            'floating_debt_ratio' => 0.05,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'WATCH',
            'name' => 'Bird Watch Security',
            'industry' => 'Security & Protection Services',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // multi-year retainers held through recessions
            'risk' => -1,
            'fixed_cost_ratio' => 0.45,
            'revenue' => 27_100_000_000.00,
            'addressable_share' => 0.288,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'WING',
            'name' => 'Steel Wings Smelting & Corp',
            'industry' => 'Steel',
            'systemic_importance' => 'base',
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.45,
            'revenue' => 48_800_000_000.00,
            'addressable_share' => 0.203,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.050,
            'depreciation_rate' => 0.08,
        ],
        [
            'ticker' => 'PENG',
            'name' => 'Penguin Computing',
            'industry' => 'Computer Hardware',
            'systemic_importance' => 'none',
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.55,
            'revenue' => 33_800_000_000.00,
            'addressable_share' => 0.232,
            'public_float' => 0.75,
            'floating_debt_ratio' => 0.40,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'SHOR',
            'name' => 'Lakeshore Living',
            'industry' => 'REIT - Residential',
            'systemic_importance' => 'none',
            'moat' => 'narrow',
            'fixed_cost_ratio' => 0.25,
            'operating_margin' => 0.55,
            'revenue' => 15_300_000_000.00,
            'addressable_share' => 0.280,
            'retained_share' => 0.62,
            'floating_debt_ratio' => 0.40,
            'historical_fixed_rate' => 0.050,
        ],
        [
            'ticker' => 'RIVE',
            'name' => 'River Stream Industries',
            'industry' => 'Specialty Industrial Machinery',
            'systemic_importance' => 'none',
            'moat' => 'very_wide',
            'payout' => 'low',
            'fixed_cost_ratio' => 0.60,
            // sixty percent long-term robotics service contracts over a lighter plant base
            'capital_intensity' => 'light',
            'revenue' => 52_300_000_000.00,
            'addressable_share' => 0.284,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.050,
        ],
        [
            'ticker' => 'GRIP',
            // Cost-plus programmes fund themselves, so the cash returns to shareholders
            'management_style' => 'steward',
            'name' => 'Gryphon Defense Systems',
            'industry' => 'Aerospace & Defense',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.25, // orders follow mainland and allied budgets, not the District cycle
            'events' => -1,
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.65,
            'revenue' => 85_600_000_000.00,
            'addressable_share' => 0.293,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'SKYL',
            // Every dollar goes into rockets and satellites while the market grows; a dividend waits for it to mature
            'management_style' => 'operator',
            'name' => 'Skylark Orbital',
            'industry' => 'Space Systems',
            'systemic_importance' => 'none',
            'beta_tilt' => 0.25, // a growth stock: its value is mostly years away, so it moves with risk appetite
            'moat' => 'narrow',
            // engineering, the factory and the launch pads; propellant, recovery and ground crews follow the launches
            'fixed_cost_ratio' => 0.45,
            'revenue' => 11_300_000_000.00,
            'addressable_share' => 0.3,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.055,
        ],
        [
            'ticker' => 'LOON',
            'name' => 'Loon Call Telecom',
            'industry' => 'Telecom Services',
            'systemic_importance' => 'systemic',
            'fixed_cost_ratio' => 0.75, // Verizon 2024: device cost is 25% of operating costs
            'revenue' => 68_200_000_000.00,
            'addressable_share' => 0.352,
            'public_float' => 0.98,
            'floating_debt_ratio' => 0.10,
            'historical_fixed_rate' => 0.040,
            'depreciation_rate' => 0.068, // Verizon 2024: D&A over net plant plus spectrum licences
        ],
        [
            'ticker' => 'PHIL',
            'management_style' => 'steward', // Declining legacy volumes run for cash and returned as massive dividends
            'name' => 'Pheasant & Morris International',
            'industry' => 'Tobacco',
            'systemic_importance' => 'none',
            'fixed_cost_ratio' => 0.25,
            'revenue' => 23_400_000_000.00,
            'addressable_share' => 0.284,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'TRIV',
            // Hundreds of disconnected divisions bolted together is empire building as a strategy
            'management_style' => 'empire_builder',
            'name' => 'Three Rivers Manufacturing',
            'industry' => 'Conglomerates',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.24, // no division large enough to move the group
            'risk' => -1,
            'moat' => 'moderate',
            'payout' => 'income',
            'fixed_cost_ratio' => 0.40,
            // one-third fast-turning household staples and adhesives, lighter than rail and utility holdings
            'capital_intensity' => 'light',
            'revenue' => 59_000_000_000.00,
            'addressable_share' => 0.28,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'IBHI',
            'name' => 'Iron Beak Heavy Ind',
            'industry' => 'Engineering & Construction',
            'systemic_importance' => 'titan',
            'beta_tilt' => 0.25, // heavy fixed costs on a thin margin
            'moat' => 'narrow',
            'fixed_cost_ratio' => 0.60,
            'revenue' => 233_000_000_000.00,
            'addressable_share' => 0.315,
            'public_float' => 0.45,
            'floating_debt_ratio' => 0.35,
            'historical_fixed_rate' => 0.055,
        ],
        [
            'ticker' => 'TICK',
            // Predatory acquisitions to swallow rivals before they become a legitimate threat
            'management_style' => 'empire_builder',
            'name' => 'Tickbird Data Systems',
            // Financials, not technology: SHRK runs the same industry and the same business model
            // and reads as a financial, and GICS files exchanges and data vendors under Financial
            // Exchanges & Data for the same reason — the terminal is sold to the market it reports
            // on, and its revenue moves with that market, not with the technology cycle. The sector
            // is not cosmetic: it picks which demand factor the stream draws on and which cohort
            // the P/E anchor shrinks toward.
            'industry' => 'Financial Data & Ratings',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.25, // priced with technology stocks though subscriptions hold
            'moat' => 'very_wide',
            'payout' => 'low',
            'fixed_cost_ratio' => 0.85,
            'revenue' => 8_870_000_000.00,
            'addressable_share' => 0.108,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.035,
        ],
        [
            'ticker' => 'SINK',
            'name' => 'Sinking Shore Extraction',
            'industry' => 'Oil & Gas E&P',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.10, // deepwater cost base, partly hedged
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.75,
            'revenue' => 62_300_000_000.00,
            'addressable_share' => 0.276,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.060,
        ],
        [
            'ticker' => 'CASC',
            'name' => 'Cascade Refining',
            'industry' => 'Oil & Gas Refining & Marketing',
            'systemic_importance' => 'base',
            'beta_tilt' => 0.25, // crack-spread earnings swing from losses to records
            'fixed_cost_ratio' => 0.025,
            // A merchant refiner at a $22 crack: Valero's margin (-$0.41 + 0.598 x crack) less its ~$6.5/bbl of costs,
            // over the refiner's own barrel revenue. Break-even near an $11.6 crack, as Valero ran in 2020.
            'revenue' => 569_000_000_000.00,
            'addressable_share' => 0.246,
            'public_float' => 0.90,
            // The District's only refiner, its market the District's fuel and the mainland coast it ships to: opens at
            // about a quarter of it.
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.055,
            // An export refinery, sized like Singapore's or Korea's: product sales open near 10% of GDP, against 2-3%
            // for a refiner serving only its home market.
        ],
        [
            'ticker' => 'GULL',
            // Leveraged the balance sheet to buy the entire shoreline, not for return on capital
            'management_style' => 'empire_builder',
            'name' => 'Silver Gull Resorts',
            'industry' => 'Resorts & Casinos',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.15, // fixed lease income steadies the casino book
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.70,
            'revenue' => 47_000_000_000.00,
            'addressable_share' => 0.305,
            'floating_debt_ratio' => 0.40,
            'historical_fixed_rate' => 0.055,
        ],
        [
            'ticker' => 'WADE',
            // Regulated returns are distributed, not reinvested past the approved rate base
            'management_style' => 'steward',
            'name' => 'Heron Regional Water',
            'industry' => 'Utilities - Regulated Water',
            'systemic_importance' => 'base',
            'moat' => 'narrow',
            'fixed_cost_ratio' => 0.80,
            'revenue' => 24_700_000_000.00,
            'addressable_share' => 0.303,
            'public_float' => 0.98,
            'floating_debt_ratio' => 0.05,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'CORM',
            'management_style' => 'steward', // Unbreakable municipal contracts require no growth capital to renew
            'name' => 'Cormorant Environmental',
            'industry' => 'Waste Management',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.25, // waste volumes barely move in a recession
            'fixed_cost_ratio' => 0.50,
            'revenue' => 24_800_000_000.00,
            'addressable_share' => 0.296,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'CRAN',
            'name' => 'Crane Medical Network',
            'industry' => 'Medical Care Facilities',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.10, // volumes follow health programmes, not the cycle
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.65,
            'revenue' => 102_000_000_000.00,
            'addressable_share' => 0.283,
            'public_float' => 0.92,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'TALN',
            'name' => 'Talon Credit',
            'industry' => 'Credit Services',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.25, // unsecured consumer credit, strongly cyclical
            'moat' => 'very_wide',
            'capex_ratio' => 0.15,
            'payout' => 'low',
            'fixed_cost_ratio' => 0.60,
            'addressable_share' => 0.30,
            'floating_debt_ratio' => 0.60,
            'historical_fixed_rate' => 0.050,
            // Its books are built on this character by its model (CommercialBankBusinessModel::resolveOpeningBooks()).
            'revenue' => 66_800_000_000.00,
            'wholesale_funding_share' => 0.25,
            'retained_share' => 0.56,
        ],
        [
            'ticker' => 'CANV',
            'name' => 'Canvasback Logistics',
            'industry' => 'Integrated Freight & Logistics',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.15, // surge-priced volumes on a fixed network
            'moat' => 'narrow',
            'fixed_cost_ratio' => 0.55,
            'revenue' => 88_700_000_000.00,
            'addressable_share' => 0.27,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'TERN',
            'name' => 'Tern Airways',
            'industry' => 'Airlines',
            'systemic_importance' => 'none',
            'beta_tilt' => 0.15, // business travel and full planes turn with the cycle
            // aircraft ownership, maintenance bases and salaried staff; fuel, crews and fees follow the flying
            'fixed_cost_ratio' => 0.30,
            // network carriers earn 8-10% before interest in a good year
            'revenue' => 80_200_000_000.00,
            'addressable_share' => 0.37,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.050,
            'depreciation_rate' => 0.05,
        ],
        [
            'ticker' => 'SGRB',
            // Asset-light packaging lines convert operating profit straight into distributable cash
            'management_style' => 'steward',
            'name' => 'Sugarbird Confectionery',
            'industry' => 'Packaged Foods',
            'systemic_importance' => 'none',
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.25,
            'revenue' => 45_200_000_000.00,
            'addressable_share' => 0.3,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.45,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'STRK',
            'name' => 'Stork Consumer Credit',
            'industry' => 'Credit Services',
            'systemic_importance' => 'none',
            'beta_tilt' => 0.25, // unsecured lending, sharp on labour data
            'risk' => 1,
            'moat' => 'very_wide',
            'capex_ratio' => 0.10,
            'fixed_cost_ratio' => 0.45,
            'public_float' => 0.92,
            'addressable_share' => 0.28,
            'floating_debt_ratio' => 0.65,
            'historical_fixed_rate' => 0.050,
            // Its books are built on this character by its model (CommercialBankBusinessModel::resolveOpeningBooks()).
            'revenue' => 44_400_000_000.00,
            'wholesale_funding_share' => 0.050,
            'retained_share' => 0.43,
        ],
        [
            'ticker' => 'BREW',
            'name' => 'Copperhead Coffee Roasters',
            'industry' => 'Restaurants',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.15, // steadier than most restaurants
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.50,
            // company-operated shops book full retail coffee sales on owned sites, turning over more revenue than a
            // franchisor
            'capital_intensity' => 'light',
            'revenue' => 38_100_000_000.00,
            'addressable_share' => 0.33,
            'public_float' => 0.70,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'CLAW',
            'management_style' => 'steward', // Partnership economics: settlement windfalls are drawn down, not banked
            'name' => 'Clear Rivers Law',
            'industry' => 'Legal Services',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.15, // retainer income holds through the cycle
            'events' => 1,
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.25,
            'revenue' => 2_260_000_000.00,
            'addressable_share' => 0.045,
            'public_float' => 0.60,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.040,
            'depreciation_rate' => 0.04,
        ],
        [
            'ticker' => 'FINC',
            'name' => 'Finch Securities',
            'industry' => 'Brokerages',
            'systemic_importance' => 'none',
            'beta_tilt' => 0.20, // retail trading and margin balances rise and fall with the market
            'moat' => 'moderate',
            'capex_ratio' => 0.08,
            'fixed_cost_ratio' => 0.70,
            'operating_margin' => 0.45,
            'public_float' => 0.75,
            'addressable_share' => 0.10,
            'floating_debt_ratio' => 0.80,
            'historical_fixed_rate' => 0.040,
            // Its books are built on this character by its model (BrokerageBusinessModel::resolveOpeningBooks()).
            'revenue' => 2_160_000_000.00,
            'retained_share' => 0.40,
        ],
        [
            'ticker' => 'PLZA',
            // Century-long municipal leases leave nothing to reinvest in; the rent goes out as yield
            'management_style' => 'steward',
            'name' => 'Plaza Civic River Trust',
            'industry' => 'REIT - Office',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.10, // rent paid from public budgets
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.85,
            'operating_margin' => 0.55,
            'revenue' => 21_300_000_000.00,
            'addressable_share' => 0.295,
            'retained_share' => 0.64,
            'public_float' => 0.15,
            'floating_debt_ratio' => 0.05,
            'historical_fixed_rate' => 0.020,
        ],
        [
            'ticker' => 'LYRE',
            'name' => 'Lyrebird Media',
            'industry' => 'Advertising Agencies',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.25, // retainers and crisis work steady a recession
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.25,
            'revenue' => 18_800_000_000.00,
            'addressable_share' => 0.25,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'BELL',
            'name' => 'Bellbird Media',
            'industry' => 'Broadcasting',
            'systemic_importance' => 'base',
            'beta_tilt' => 0.10, // the airtime half sells at the going ad rate
            'moat' => 'moderate',
            // sports rights, newsrooms and the stations are paid for whatever the airtime sells for
            'fixed_cost_ratio' => 0.55,
            // news-led networks earn about 20% before interest (Fox, fiscal 2024-25)
            'revenue' => 59_300_000_000.00,
            'addressable_share' => 0.352,
            // the founding family's trust holds the voting shares
            'public_float' => 0.60,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'STAR',
            'name' => 'Starling Academic Systems',
            'industry' => 'Education & Training Services',
            'systemic_importance' => 'base',
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.65,
            'revenue' => 16_300_000_000.00,
            'addressable_share' => 0.13,
            'public_float' => 0.75,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'WEAV',
            // Buys out its own successful vendors to absorb their volume into the platform
            'management_style' => 'empire_builder',
            'name' => 'Weaver Marketplace',
            'industry' => 'Internet Retail',
            'systemic_importance' => 'base',
            'beta_tilt' => 0.25, // thin margin on fixed fulfilment costs
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.70,
            'revenue' => 111_000_000_000.00,
            'addressable_share' => 0.347,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'JDAW',
            'management_style' => 'steward', // Reinvests in the platform; no warehouses to build
            'name' => 'Jackdaw Bazaar',
            'industry' => 'Online Marketplaces',
            'systemic_importance' => 'base',
            'beta_tilt' => 0.10, // pure commission on discretionary sales, no fixed fulfilment base
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.55,
            'revenue' => 51_700_000_000.00,
            'addressable_share' => 0.327,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'CROP',
            'name' => 'Poultry Crop Operations',
            'industry' => 'Farm Products',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.15, // volumes hold in a downturn
            'moat' => 'narrow',
            'fixed_cost_ratio' => 0.60,
            'revenue' => 105_000_000_000.00,
            'addressable_share' => 0.3,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.50,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'BRKW',
            // Multi-generational dynastic stewardship: active board governance, conservative leverage, and generational
            // patient capital
            'management_style' => 'fortress',
            'name' => 'Breakwater Trust',
            // Industrial holding trust structured around dual-class foundation voting control (40% public float),
            // anchor equity stakes across the District's crown-jewel engineering and infrastructure pillars, active
            // board-level stewardship, and counter-cyclical emergency liquidity.
            //
            // Financials, like OWLS: what this house does for a living is hold other people's balance
            // sheets, which is the trade the sector names — its own industry says Investment Companies,
            // and a closed-end trust is filed under Financials for the same reason a fund is. It stays
            // an INDUSTRIAL holding in every way that touches the physics: the business model is
            // investment_company, not a financial one, so it keeps its subsidiaries' plant and its
            // NAV-anchored valuation. Two things do move with the label — its streams load on the
            // financial demand factor at the 0.25 sector loading its model declares, and its P/E shrinks
            // toward the Financials anchor of 14 rather than the Industrials 20, which pulls with the
            // holding-company discount this trust's lore claims rather than against it.
            'industry' => 'Investment Companies',
            'systemic_importance' => 'titan',
            'beta_tilt' => 0.06, // follows the District economy through its holdings
            // The consolidated subsidiaries' own return on the capital they run, which is all the revenue line sells
            // on: berths, rights of way, concessions and toolmakers held to about their cost of capital (~8.7% at
            // the opening rates), which the board sets them. The stakes earn their dividends below the line and their
            // appreciation as OCI (AnchorStakeLedger).
            'baseline_roic' => 0.090,
            'moat' => 'none',
            // The Foundation's grants run on the dividend, and a trust whose stakes are fixed has nowhere to put what
            // it keeps: at 40% the rest piled up as cash, a quarter of NAV.
            'fixed_cost_ratio' => 0.03,
            // The subsidiaries' margin: their sales are the whole revenue line, and dividends and interest arrive
            // below it with no cost of goods.
            'operating_margin' => 0.35,
            'public_float' => 0.75,
            'sam_ratio' => 1.62,
            'floating_debt_ratio' => 0.10,
            'historical_fixed_rate' => 0.030,
            'depreciation_rate' => 0.03,
            'corporate_treasury' => 135_000_000_000.00,
            // The stakes open at about 400B, near two fifths of capital employed; the subsidiaries are the rest,
            // well above the 20% AnchorHoldings::MIN_CONSOLIDATED_SHARE requires.
            'total_equity' => 1_128_600_000_000.00,
            'customer_deposits' => 0.00,
            'wholesale_debt' => 75_600_000_000.00,
            'retained_earnings' => 648_000_000_000.00,
        ],
        [
            'ticker' => 'ELDE',
            'management_style' => 'steward', // Securitised pension streams are passed through to holders by design
            'name' => 'Elderbird Retirement Services',
            'industry' => 'REIT - Healthcare Facilities',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.10, // ageing-population rents trade like a long bond
            'fixed_cost_ratio' => 0.75,
            'operating_margin' => 0.55,
            'revenue' => 8_490_000_000.00,
            'addressable_share' => 0.275,
            'retained_share' => 0.01,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.030,
        ],
        [
            'ticker' => 'SWFT',
            // Master-franchise royalties grow the estate on the franchisees capital, not its own
            'management_style' => 'steward',
            'name' => 'Golden Swift Holdings',
            // Restaurants are Consumer Discretionary, and BREW — the same industry, the same
            // RestaurantBusinessModel — was already filed there. One industry standing in two
            // sectors means the twins draw different demand factors and shrink toward different
            // P/E cohorts. What makes this one defensive is its book, not its label: beta 0.15 and
            // 95% of revenue in royalties and leases. If its EARNINGS should also ride the cycle
            // more gently than a company-operated chain's, that is ModelParam::OperatingCyclicality
            // (the restaurant default is a full 1.00), not the sector.
            'industry' => 'Restaurants',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.25, // diners trade down to it in a recession
            'events' => -1,
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.60,
            // owns the high-traffic franchise sites and finances automated equipment, booking rent and royalties
            'capital_intensity' => 'heavy',
            'revenue' => 31_100_000_000.00,
            'addressable_share' => 0.264,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'VULT',
            'management_style' => 'fortress', // Stockpiles dry powder through expansions to deploy it into the bust
            'name' => 'Vulture Capital Recovery',
            'industry' => 'Distressed Debt',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // deploys into distress, but is still long credit
            'moat' => 'wide',
            'capex_ratio' => 0.05,
            'fixed_cost_ratio' => 0.35,
            'operating_margin' => 0.40,
            'public_float' => 0.80,
            'floating_debt_ratio' => 0.85,
            'historical_fixed_rate' => 0.070,
            // Its books are built on this character by its model (AssetManagementBusinessModel::resolveOpeningBooks()).
            'revenue' => 20_400_000_000.00,
            'retained_share' => 0.875,
        ],
        [
            'ticker' => 'FALC',
            // Sells the mass-market fleet at a loss to take the market; returns come later
            'management_style' => 'empire_builder',
            'name' => 'Falconet Motor Group',
            'industry' => 'Auto Manufacturers',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.15, // luxury buyers ride out an ordinary slowdown
            'payout' => 'retain',
            // An OEM's plant, tooling and contracted line labour are roughly two fifths of its cost base; at
            // 0.58 the fleet broke even on a 15% volume drop and any ordinary slowdown put it in the red.
            'fixed_cost_ratio' => 0.45,
            'revenue' => 280_000_000_000.00,
            'addressable_share' => 0.304,
            'public_float' => 0.75,
            'floating_debt_ratio' => 0.35,
            'historical_fixed_rate' => 0.055,
        ],
        [
            'ticker' => 'OSPR',
            'name' => 'Osprey Global Vanguard',
            'industry' => 'Security & Protection Services',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // deployments rise with tension abroad
            'risk' => 1,
            'moat' => 'moderate',
            'payout' => 'income',
            'fixed_cost_ratio' => 0.40,
            // mechanised offshore field force with its own vehicles, aircraft, escort ships and supply lines
            'capital_intensity' => 'very_heavy',
            'revenue' => 40_300_000_000.00,
            'addressable_share' => 0.28,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.45,
            'historical_fixed_rate' => 0.050,
        ],
        [
            'ticker' => 'NCKR',
            'name' => 'Nutcracker Cash & Vault',
            'industry' => 'Security & Protection Services',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.10, // notes are drawn in a bank scare as well as spent in a boom
            'moat' => 'narrow',
            // depots, vaults and the armoured fleet; crews follow the rounds
            'fixed_cost_ratio' => 0.40,
            // cash carriers earn 9-14% before interest (Brink's, Loomis, Prosegur Cash)
            'revenue' => 34_900_000_000.00,
            'addressable_share' => 0.0725,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.35,
            'historical_fixed_rate' => 0.045,
            'depreciation_rate' => 0.08,
        ],
        [
            'ticker' => 'WALL',
            'name' => 'Wallcreeper Custodial',
            'industry' => 'Correctional Services',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.25, // the District pays for every place whatever the economy does
            'moat' => 'narrow',
            // owned prisons and the minimum staffing each must keep, full or not
            'fixed_cost_ratio' => 0.55,
            // prison operators earn 15-20% before interest on owned estates (GEO, CoreCivic, Serco custodial)
            'revenue' => 16_900_000_000.00,
            'addressable_share' => 0.0525,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'NJAR',
            'name' => 'Nightjar Risk Partners',
            'industry' => 'Security & Protection Services',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.15, // fraud and crisis work arrives when markets break
            'risk' => 1,
            // investigators and analysts are kept on whether the cases come or not
            'fixed_cost_ratio' => 0.50,
            // risk and investigations firms earn 15-20% before interest (Kroll, Control Risks, FTI forensic)
            'revenue' => 20_700_000_000.00,
            'addressable_share' => 0.0517,
            'floating_debt_ratio' => 0.40,
            'historical_fixed_rate' => 0.050,
        ],
        [
            'ticker' => 'CROW',
            'name' => 'Crowfall Capital',
            'industry' => 'Asset Management',
            'systemic_importance' => 'systemic',
            // Defensive, not inverse. A short book marks up in a selloff, but the fee base underneath it is
            // still assets under management: it swells through a bull run and shrinks on redemptions after
            // one. Its performance fees are idiosyncratic campaign wins with no link to the cycle at all.
            // At -0.50 the price claimed a hedge the earnings never delivered; 0.20 is what they support.
            'beta_tilt' => -0.15, // short books offset part of a market that sets its assets
            'moat' => 'wide',
            'capex_ratio' => 0.05,
            'fixed_cost_ratio' => 0.30,
            'operating_margin' => 0.48,
            'floating_debt_ratio' => 0.90,
            'historical_fixed_rate' => 0.075,
            // Its books are built on this character by its model (AssetManagementBusinessModel::resolveOpeningBooks()).
            'revenue' => 13_300_000_000.00,
            'retained_share' => 0.75,
        ],
        [
            'ticker' => 'CNDR',
            // Conquers new mineral territory with debt whenever a sovereign borrower defaults
            'management_style' => 'empire_builder',
            'name' => 'Condor Extraction',
            'industry' => 'Copper',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.10, // heavy capital spending against a metal price
            'fixed_cost_ratio' => 0.80,
            // A low-cost major: copper majors earn 25-35% at mid-cycle on ~0.5x asset turnover, which is what
            // carries them through a metals slump that sinks the high-cost producers.
            'revenue' => 29_200_000_000.00,
            'addressable_share' => 0.208,
            // Majority owned by LAKE and SWAN, per its own description. A thin float is what makes a
            // high-volatility miner genuinely hard to trade in size and hard to borrow.
            'public_float' => 0.45,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.055,
        ],
        [
            'ticker' => 'SILC',
            // Debt-fuelled fab construction priced to deny rivals scale, not to clear a hurdle
            'management_style' => 'empire_builder',
            'name' => 'Silicon Creek Foundries',
            'industry' => 'Semiconductors',
            'systemic_importance' => 'systemic',
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.70,
            'revenue' => 58_300_000_000.00,
            'addressable_share' => 0.293,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'KSTL',
            'name' => 'Kestrel Civic Lines',
            'industry' => 'Railroads',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // half its revenue is commuter subscriptions
            'risk' => -1,
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.80,
            'revenue' => 18_500_000_000.00,
            'addressable_share' => 0.288,
            'floating_debt_ratio' => 0.05,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'POOL',
            'name' => 'Brine Pool Capital',
            'industry' => 'Mortgage Finance',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.25, // wholesale-funded, falls steeply in credit crises
            'capex_ratio' => 0.05,
            'fixed_cost_ratio' => 0.30,
            'operating_margin' => 0.50,
            'addressable_share' => 0.314,
            'floating_debt_ratio' => 0.70,
            'historical_fixed_rate' => 0.050,
            'depreciation_rate' => 0.01,
            // Its books are built on this character by its model (CommercialBankBusinessModel::resolveOpeningBooks()).
            'revenue' => 8_060_000_000.00,
            'retained_share' => 0.59,
        ],
        [
            'ticker' => 'ACC',
            // The default waterfall must stay funded before any capital leaves the clearinghouse
            'management_style' => 'fortress',
            'name' => 'Aerie Central Clearing',
            'industry' => 'Financial Clearinghouses',
            'systemic_importance' => 'titan',
            'beta_tilt' => -0.25, // clearing volume rises in sell-offs
            'events' => -1,
            'moat' => 'very_wide',
            'capex_ratio' => 0.05,
            'fixed_cost_ratio' => 0.30,
            'operating_margin' => 0.55,
            // The closely held block is the District's (StrategicHoldings), taken off in AnchorHoldings::tradableFloat
            'public_float' => 1.00,
            'addressable_share' => 0.50,
            'floating_debt_ratio' => 0.10,
            'historical_fixed_rate' => 0.035,
            // Its books are built on this character by its model (ClearingHouseBusinessModel::resolveOpeningBooks()).
            'revenue' => 12_500_000_000.00,
            'retained_share' => 0.40,
        ],
        [
            'ticker' => 'ROOK',
            'name' => 'Rookery Holdings',
            'industry' => 'Securities & Commodity Exchanges',
            'systemic_importance' => 'titan',
            'beta_tilt' => 0.05, // turnover rises in a sell-off as the value it is charged on falls
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.75,
            'revenue' => 7_630_000_000.00,
            'addressable_share' => 0.317, // the only licensed venue: the District's own turnover is the room it has
            // Control sits in unlisted Class B voting shares (the District, LAKE, SWAN, SAFE), which carry little of
            // the capital; the District's 20% of the capital is StrategicHoldings', taken off in
            // AnchorHoldings::tradableFloat.
            'public_float' => 1.00,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.035,
        ],
        [
            'ticker' => 'WELL',
            'management_style' => 'steward', // Pays out what the spread earns; growth is the pension schemes it buys
            'name' => 'Wellspring Life',
            'industry' => 'Insurance - Life',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.10, // savings fees ride the market and a rally in bonds squeezes the guarantees
            'moat' => 'narrow',
            'capex_ratio' => 0.03,
            'fixed_cost_ratio' => 0.30,
            // Premiums and fees nearly all go on benefits, reserves and running the book: a life insurer's profit is
            // the investment spread.
            'operating_margin' => 0.04,
            'public_float' => 1.00,
            'addressable_share' => 0.24,
            'floating_debt_ratio' => 0.10,
            'historical_fixed_rate' => 0.035,
            // Reserves are paid out over decades, so the bonds behind them run longer than a composite's.
            'securities_duration' => 10.0,
            'aoci_filtered' => true,
            // Its books are built on this character by its model (InsuranceBusinessModel::resolveOpeningBooks()).
            'revenue' => 90_000_000_000.00,
        ],
        [
            'ticker' => 'HONY',
            'management_style' => 'empire_builder', // Grows by buying the small brokers it places business beside
            'name' => 'Honeyguide Partners',
            'industry' => 'Insurance Brokers',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.15, // commissions follow the insurance cycle, not the business cycle
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.60,
            'revenue' => 11_600_000_000.00,
            'addressable_share' => 0.15,
            // The partners who sold their firms to it keep part of the equity.
            'public_float' => 0.92,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.040,
            'depreciation_rate' => 0.05,
        ],
        [
            'ticker' => 'ALBT',
            'name' => 'Albatross Deepwaters',
            'industry' => 'Marine Shipping',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.25, // spot freight on a fixed fleet
            'moat' => 'moderate',
            'payout' => 'low',
            'fixed_cost_ratio' => 0.65,
            'revenue' => 53_000_000_000.00,
            'addressable_share' => 0.258,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.050,
        ],
        [
            'ticker' => 'IBIS',
            'name' => 'Ibis Pharmaceuticals',
            'industry' => 'Drug Manufacturers - General',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.15, // prescription demand ignores the cycle
            'fixed_cost_ratio' => 0.70,
            'revenue' => 63_300_000_000.00,
            'addressable_share' => 0.307,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.040,
            'depreciation_rate' => 0.10,
        ],
        [
            'ticker' => 'PEAC',
            'name' => 'Peacock Heritage Group',
            'industry' => 'Luxury Goods',
            'systemic_importance' => 'systemic',
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.50,
            'revenue' => 43_100_000_000.00,
            'addressable_share' => 0.32,
            'public_float' => 0.55,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.035,
        ],
        [
            'ticker' => 'LARK',
            // Mature staples brands defend shelf space with marketing spend, not capital
            'management_style' => 'steward',
            'name' => 'Lark & Crest Brands',
            'industry' => 'Household & Personal Products',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.10, // among the least volatile on the exchange
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.45,
            'revenue' => 86_700_000_000.00,
            'addressable_share' => 0.314,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.035,
        ],
        [
            'ticker' => 'CBIL',
            'name' => 'Crossbill Precision Tooling',
            'industry' => 'Tools & Accessories',
            'systemic_importance' => 'systemic',
            // A tollbooth that "completely bypasses the traditional boom-and-bust cycles of standard
            // manufacturing" cannot carry a cyclical beta. At 1.10 the CAPM charged it a cyclical cost of
            // equity and priced its 37% return on equity below the tools sector multiple.
            'beta_tilt' => -0.15, // falls by less than most equipment makers
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.60,
            'revenue' => 36_100_000_000.00,
            'addressable_share' => 0.3,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
            'depreciation_rate' => 0.08,
        ],
        [
            'ticker' => 'PINT',
            'name' => 'Pintail Beverage Group',
            'industry' => 'Beverages - Wineries & Distilleries',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.10, // demand barely moves with the cycle
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.45,
            'revenue' => 34_500_000_000.00,
            'addressable_share' => 0.315,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'SPRW',
            'name' => 'Sparrow Markets',
            'industry' => 'Grocery Stores',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.25, // food is bought whatever the economy does
            'moat' => 'moderate',
            // store rents, depots and head office; suppliers, fuel and store payroll follow the baskets sold
            'fixed_cost_ratio' => 0.10,
            // supermarket chains earn 3-4% before interest (Kroger, Tesco, Ahold Delhaize)
            'revenue' => 175_000_000_000.00,
            'addressable_share' => 0.353,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'KITE',
            // Statutory trust distribution on a sub-2% vacancy portfolio leaves little to retain
            'management_style' => 'steward',
            'name' => 'Kite Logistics Trust',
            'industry' => 'REIT - Industrial',
            'systemic_importance' => 'base',
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.30,
            'operating_margin' => 0.52,
            'revenue' => 20_500_000_000.00,
            'addressable_share' => 0.358,
            'retained_share' => 0.44,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'SHER',
            'name' => 'Shearwater Mills',
            'industry' => 'Apparel Manufacturing',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.10, // trade-down softens a downturn
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.55,
            'revenue' => 59_000_000_000.00,
            'addressable_share' => 0.178,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'FULM',
            'name' => 'Fulmar Chemical Group',
            'industry' => 'Chemicals',
            'systemic_importance' => 'base',
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.60,
            'revenue' => 67_800_000_000.00,
            'addressable_share' => 0.25,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'EIDR',
            // Prioritizes passenger safety engineering, fleet longevity, and steady dividend distributions
            'management_style' => 'steward',
            'name' => 'Eider Motor Group',
            'industry' => 'Auto Manufacturers',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // fleet replacement, parts and subscriptions
            'risk' => -1,
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.40,
            'revenue' => 124_000_000_000.00,
            'addressable_share' => 0.253,
            'public_float' => 0.80,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'ERNE',
            // Generational telecom R&D and steady dividend distributions backed by patent tollbooths
            'management_style' => 'steward',
            'name' => 'Erne Network Systems',
            'industry' => 'Communication Equipment',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.10, // royalties steady the equipment cycle
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.35, // Ericsson 2022: cost of sales 65% of operating costs
            'revenue' => 62_800_000_000.00,
            'addressable_share' => 0.119,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'SNDR',
            // Disciplined metallurgical R&D, conservative leverage, and steady cycle-spanning dividend distributions
            'management_style' => 'steward',
            'name' => 'Sanderling Rock Dynamics',
            'industry' => 'Specialty Industrial Machinery',
            'systemic_importance' => 'systemic',
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.55,
            // vertically integrated tungsten-carbide sintering plants and heavy tunnel borers
            'capital_intensity' => 'heavy',
            'revenue' => 89_200_000_000.00,
            'addressable_share' => 0.247,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'NUTH',
            // Disciplined programmatic HVAC roll-up acquisitions, conservative leverage, and steady dividend
            // distributions
            'management_style' => 'steward',
            'name' => 'Nuthatch Climate Systems',
            'industry' => 'Building Products & Equipment',
            'systemic_importance' => 'base',
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.50,
            'capital_intensity' => 'light', // OEM heat-pump systems sold through certified independent installers
            'revenue' => 80_500_000_000.00,
            'addressable_share' => 0.22,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'ALCA',
            // Decentralized operational autonomy, asset-light assembly, world-class capital efficiency, and reliable
            // dividend payouts
            'management_style' => 'steward',
            'name' => 'Alca Compression Dynamics',
            'industry' => 'Specialty Industrial Machinery',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.10, // service income carries a downturn
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.45,
            'revenue' => 86_100_000_000.00,
            'addressable_share' => 0.242,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'RAVN',
            // Disciplined volume throughput, inventory velocity, and counterparty risk management across global trade
            // corridors
            'management_style' => 'operator',
            'name' => 'Raven Merchant Group',
            'industry' => 'Merchant Houses',
            'systemic_importance' => 'titan',
            'beta_tilt' => -0.15, // staples volumes hold in recessions
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.45,
            'revenue' => 160_000_000_000.00,
            'addressable_share' => 0.148,
            'public_float' => 0.65,
            'floating_debt_ratio' => 0.20, // the cargo it finances: its opening working capital over its debt
            'historical_fixed_rate' => 0.038,
        ],
        [
            'ticker' => 'HARR',
            // Serial acquisitive capital allocation: buys mission-critical niche leaders and reinvests free cash flow
            // into programmatic bolt-ons
            'management_style' => 'steward',
            'name' => 'Harrier Industrial Holdings',
            'industry' => 'Conglomerates',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.10, // pricing power, little capital
            'moat' => 'wide',
            'payout' => 'low',
            'fixed_cost_ratio' => 0.30,
            'revenue' => 36_000_000_000.00,
            'addressable_share' => 0.0822,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.035,
            'depreciation_rate' => 0.04,
        ],
        [
            'ticker' => 'TIER',
            // Leveraged private capital deployment, operational turnarounds, and pro-cyclical carried interest
            // extraction
            'management_style' => 'empire_builder',
            'name' => 'Tiercel Capital Partners',
            'industry' => 'Private Equity',
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.25, // leveraged buyouts whose exits need open credit
            'moat' => 'wide',
            'capex_ratio' => 0.04,
            'fixed_cost_ratio' => 0.40,
            'operating_margin' => 0.45,
            'public_float' => 0.70,
            'floating_debt_ratio' => 0.40,
            'historical_fixed_rate' => 0.042,
            // Its books are built on this character by its model (AssetManagementBusinessModel::resolveOpeningBooks()).
            'revenue' => 14_000_000_000.00,
            'retained_share' => 0.60,
        ],
        [
            'ticker' => 'BUZT',
            // Heavy capital cycle discipline, conservative balance-sheet headroom, and captive aftermarket fleet
            // monetization
            'management_style' => 'fortress',
            'name' => 'Bustard Heavy Dynamics',
            'industry' => 'Farm & Heavy Construction Machinery',
            'systemic_importance' => 'base',
            'payout' => 'balanced',
            'fixed_cost_ratio' => 0.60,
            // high-fixed-cost welding, heat-treatment and machining plants for surface earthmoving machinery
            'capital_intensity' => 'heavy',
            'revenue' => 42_500_000_000.00,
            'addressable_share' => 0.0965,
            'public_float' => 0.80,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'BOBY',
            // Mission-critical grid reliability, high-specification manufacturing, and multi-year utility backlog
            // execution
            'management_style' => 'operator',
            'name' => 'Boby Electrical Systems',
            'industry' => 'Electrical Equipment & Parts',
            'systemic_importance' => 'systemic',
            'beta_tilt' => -0.25, // backlog and substation replacement, not the cycle
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.50,
            'revenue' => 48_200_000_000.00,
            'addressable_share' => 0.0727,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.036,
        ],
        [
            'ticker' => 'MAGP',
            // High-velocity working capital, automated distribution routing, and disciplined shareholder cash returns
            'management_style' => 'steward',
            'name' => 'Magpie Industrial Supply',
            'industry' => 'Industrial Distribution',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.10, // repair parts when capex is deferred
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.35,
            'revenue' => 39_600_000_000.00,
            'addressable_share' => 0.0571,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.035,
        ],
        [
            'ticker' => 'PTAR',
            // Surge production discipline: an export order book is won on delivery slots, not on programme prestige
            'management_style' => 'operator',
            'name' => 'Ptarmigan Land Systems',
            'industry' => 'Aerospace & Defense',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.25, // orders follow defence budgets, not the District cycle
            'moat' => 'wide',
            'payout' => 'balanced',
            'fixed_cost_ratio' => 0.60,
            'revenue' => 69_100_000_000.00,
            'addressable_share' => 0.268,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'WREN',
            // Programmatic absorption of regional lock and door makers, funded out of the aftermarket
            'management_style' => 'empire_builder',
            'name' => 'Wren Access Systems',
            'industry' => 'Building Products & Equipment',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.15, // less cyclical than the building trade
            'risk' => -1,
            'moat' => 'wide',
            'fixed_cost_ratio' => 0.45,
            // decades of lockmaker roll-up acquisitions consolidated into Wren factories
            'capital_intensity' => 'heavy',
            'revenue' => 22_900_000_000.00,
            'addressable_share' => 0.27,
            'public_float' => 0.88,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.040,
        ],
        [
            'ticker' => 'CHUF',
            // Machine availability is the product; the rig is only how it is delivered
            'management_style' => 'operator',
            'name' => 'Chough Mining Systems',
            'industry' => 'Farm & Heavy Construction Machinery',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.10, // parts demand runs while mines produce
            'risk' => -1,
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.45,
            'revenue' => 62_500_000_000.00,
            'addressable_share' => 0.261,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.042,
            'depreciation_rate' => 0.07,
        ],
        [
            'ticker' => 'GANN',
            // Tonnage is contracted years ahead, so the fleet is renewed on a schedule rather than on a rate spike
            'management_style' => 'steward',
            'name' => 'Gannet Ro-Ro Lines',
            'industry' => 'Marine Shipping',
            'systemic_importance' => 'base',
            'risk' => -1,
            'moat' => 'moderate',
            'fixed_cost_ratio' => 0.60,
            'revenue' => 59_700_000_000.00,
            'addressable_share' => 0.261,
            'public_float' => 0.82,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.048,
            'depreciation_rate' => 0.07,
        ],
        [
            'ticker' => 'REDW',
            // A single-purpose heavy-haul line cannot be rebuilt, so the balance sheet protects the track
            'management_style' => 'fortress',
            'name' => 'Redwing Ore Line',
            'industry' => 'Railroads',
            'systemic_importance' => 'base',
            'beta_tilt' => 0.10, // steel, timber and container traffic follows the factory cycle
            'payout' => 'income',
            'fixed_cost_ratio' => 0.75,
            'revenue' => 23_500_000_000.00,
            'addressable_share' => 0.267,
            'public_float' => 0.70,
            'floating_debt_ratio' => 0.10,
            'historical_fixed_rate' => 0.045,
            'depreciation_rate' => 0.05,
        ],
        [
            'ticker' => 'BOWR',
            // Buys land at what the homes on it will bear and pays out what it cannot build on
            'management_style' => 'operator',
            'name' => 'Bowerbird Homes',
            'industry' => 'Residential Construction',
            'systemic_importance' => 'base',
            'beta_tilt' => 0.10, // a land bank bought on the housing cycle
            'moat' => 'narrow',
            // Selling and overhead are about a tenth of costs; land and construction are the rest. At a 13% operating
            // margin that leaves a home sales margin near 22%, D.R. Horton's 23% in FY2004.
            'fixed_cost_ratio' => 0.10,
            'revenue' => 30_100_000_000.00,
            'addressable_share' => 0.167,
            'public_float' => 0.80,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.060,
        ],
        [
            'ticker' => 'FNCH',
            // Develops a mine when the deck clears its cost of capital and pays out the rest
            'management_style' => 'operator',
            'name' => 'Goldfinch Mining',
            'industry' => 'Gold',
            'systemic_importance' => 'base',
            'moat' => 'narrow',
            'fixed_cost_ratio' => 0.80,
            'revenue' => 13_100_000_000.00,
            'addressable_share' => 0.169,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.055,
        ],
        [
            'ticker' => 'PELI',
            // Prices its book to the loss ratio it can earn and pays out what it cannot grow
            'management_style' => 'operator',
            'name' => 'Pelican Health Plans',
            'industry' => 'Healthcare Plans',
            'systemic_importance' => 'base',
            'moat' => 'wide',
            // Claims are 82.5% of premium, UnitedHealth's 2019 medical care ratio: the engine strikes the split on cash
            // costs net of depreciation (1.6% of revenue on its acquired systems), and administration takes 10% and
            // leaves a 5.5% operating margin, about its insurance segment's.
            'fixed_cost_ratio' => 0.112,
            'revenue' => 80_600_000_000.00,
            'addressable_share' => 0.153,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.15,
            'historical_fixed_rate' => 0.050,
            // Operating cash plus the claims float: about seven weeks of claims owed, invested until paid.
        ],
        [
            'ticker' => 'EGRT',
            'management_style' => 'steward', // A custodian's clients want it dull: steady dividends, no acquisitions
            'name' => 'Egret Trust',
            'industry' => 'Custody Banks',
            // Custodian of the Sovereign Reserve Fund and of most assets on Glasswater Row
            'systemic_importance' => 'systemic',
            'beta_tilt' => 0.25, // its fees are struck on market values
            // One step over its industry's narrow moat: its deposit, securities and fee book already out-earns a
            // narrow target before a loan is priced, as custodian of the District's reserves would
            'moat' => 'moderate',
            'capex_ratio' => 0.10,
            'fixed_cost_ratio' => 0.60,
            'public_float' => 0.90,
            'addressable_share' => 0.34,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.030,
            // Its books are built on this character by its model (CommercialBankBusinessModel::resolveOpeningBooks()).
            'revenue' => 86_000_000_000.00,
            // Deposits are 94% of its funding, as State Street's 72% of assets on a tenth of them in equity
            'wholesale_funding_share' => 0.065,
            'retained_share' => 0.50,
        ],
        [
            'ticker' => 'PTRL',
            'name' => 'Storm Petrel Securities',
            'industry' => 'Investment Banking',
            'systemic_importance' => 'systemic', // Lead primary dealer in Exchequer debt and the repo market
            'risk' => 1,
            'moat' => 'moderate',
            'capex_ratio' => 0.05,
            'fixed_cost_ratio' => 0.45,
            'operating_margin' => 0.35,
            'public_float' => 0.92,
            'addressable_share' => 0.267,
            'floating_debt_ratio' => 0.80, // Inventory funded overnight in repo
            'historical_fixed_rate' => 0.035,
            // Its books are built on this character by its model (BrokerageBusinessModel::resolveOpeningBooks()).
            'revenue' => 26_100_000_000.00,
            'retained_share' => 0.375,
        ],
        [
            'ticker' => 'BNTH',
            'management_style' => 'steward', // Discretion first: steady dividends, no acquisitions that draw attention
            'name' => 'Benthos Fiduciary & Trust',
            'industry' => 'Private Banks',
            'systemic_importance' => 'base',
            'beta_tilt' => 0.25, // its fees are struck on market values
            // ROE 19% at the opening. Julius Baer earned 12% in 2019 with Swiss franc rates negative; at the
            // District's rates its fees and deposit franchise earn more with its loans priced at about the market rate
            'moat' => 'wide',
            'capex_ratio' => 0.08,
            'fixed_cost_ratio' => 0.65,
            'addressable_share' => 0.40,
            'floating_debt_ratio' => 0.40,
            'historical_fixed_rate' => 0.030,
            // Its books are built on this character by its model (CommercialBankBusinessModel::resolveOpeningBooks()).
            'revenue' => 34_700_000_000.00,
            // Julius Baer's balance sheet at 2019: deposits 71% of assets, equity 6.1%. The rest of a private bank's
            // funding is mostly client-linked, so it sits with the deposits here, leaving the wholesale notes inside
            // twice equity
            'wholesale_funding_share' => 0.125,
            'retained_share' => 0.525,
        ],
        [
            'ticker' => 'SKUA',
            // A challenger run for subscribers: payout held down, the network built ahead of them
            'management_style' => 'empire_builder',
            'name' => 'Skua Mobile',
            'industry' => 'Telecom Services',
            'systemic_importance' => 'base',
            // above Loon Call's 14%: a challenger's sign-ups swing more, and this sets their noise too
            'beta_tilt' => 0.10, // device-led growth leans on discretionary upgrades
            'moat' => 'none',
            'payout' => 'low',
            'fixed_cost_ratio' => 0.64, // devices, sold below cost, are a third of cash operating costs
            'revenue' => 23_000_000_000.00,
            'addressable_share' => 0.284,
            'public_float' => 0.45, // its mainland parent holds the rest
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.050,
            'depreciation_rate' => 0.08,
        ],
        [
            'ticker' => 'DIPR',
            'management_style' => 'operator',
            'name' => 'Dipper Generation',
            'industry' => 'Utilities - Independent Power Producers',
            'systemic_importance' => 'base',
            'moat' => 'narrow',
            'fixed_cost_ratio' => 0.35, // fuel and carbon for the gas fleet are most of the cash cost
            'revenue' => 10_200_000_000.00,
            'addressable_share' => 0.295,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.050,
            'depreciation_rate' => 0.045,
            // about 1.8 times EBITDA: a one-sigma gas slump halves it and must leave it under the 4.0 covenant
        ],
        [
            'ticker' => 'FIRE',
            // Fuel and service contracts run for decades and need little new capital, so the cash goes to shareholders
            'management_style' => 'steward',
            'name' => 'Firecrest Atomic',
            'industry' => 'Nuclear Power Equipment',
            'systemic_importance' => 'base',
            'beta_tilt' => -0.25, // orders follow world energy prices and long rates, the fleet runs whatever the cycle
            // engineering staff, licensing and the fuel plants; crews and materials follow the plants being built
            'fixed_cost_ratio' => 0.50,
            'revenue' => 38_400_000_000.00,
            'addressable_share' => 0.267,
            'public_float' => 0.95,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'KTWK',
            // A concession holder: builds quays the trade has asked for, pays out the rest
            'management_style' => 'steward',
            'name' => 'Kittiwake Harbour Terminals',
            'industry' => 'Marine Ports & Services',
            'systemic_importance' => 'base',
            'beta_tilt' => 0.05, // throughput follows trade
            'moat' => 'narrow',
            // dock crews on shift, crane upkeep and the concession's fixed rent; the royalty and power scale with boxes
            'fixed_cost_ratio' => 0.60,
            // container terminal operators earn 35-45% before interest (ICTSI, Hutchison Port Holdings Trust)
            'revenue' => 11_400_000_000.00,
            'addressable_share' => 0.36,
            'floating_debt_ratio' => 0.20,
            'historical_fixed_rate' => 0.045,
        ],
        [
            'ticker' => 'GDWT',
            'name' => 'Godwit Consulting Group',
            'sector' => 'Information Technology', // a technology consultancy, filed with its clients' systems trade
            'industry' => 'Consulting Services',
            'systemic_importance' => 'none',
            'beta_tilt' => 0.10, // strategy bookings are the first budget a client cuts
            'moat' => 'very_wide',
            // salaried consultants and delivery centres; subcontractors, travel and bonuses scale with the work
            'fixed_cost_ratio' => 0.45,
            // strategy and technology consultancies earn 13-16% before interest (Accenture, Capgemini, CGI)
            'revenue' => 57_800_000_000.00,
            'addressable_share' => 0.12,
            'public_float' => 0.90,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
            'depreciation_rate' => 0.12,
        ],
        [
            'ticker' => 'TSTN',
            'management_style' => 'steward', // Partnership economics: crisis-year fees are paid out to the partners
            'name' => 'Turnstone Advisory',
            'industry' => 'Consulting Services',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.25, // busiest when credit cracks
            'risk' => 1,
            'moat' => 'very_wide',
            'fixed_cost_ratio' => 0.40,
            // restructuring, forensic and economic advisers earn 10-15% before interest (FTI Consulting, Huron)
            'revenue' => 5_340_000_000.00,
            'addressable_share' => 0.103,
            'public_float' => 0.70,
            'floating_debt_ratio' => 0.30,
            'historical_fixed_rate' => 0.045,
            'depreciation_rate' => 0.08,
        ],
        [
            'ticker' => 'AVCT',
            'management_style' => 'fortress', // Keeps a war chest against the lawsuits a failed audit brings
            'name' => 'Avocet & Curlew',
            'industry' => 'Consulting Services',
            'systemic_importance' => 'none',
            'beta_tilt' => -0.05, // the audit book holds through a slump; the advisory book does not
            'moat' => 'very_wide',
            // salaried audit and tax staff carried through the year; contractors and travel follow the work
            'fixed_cost_ratio' => 0.50,
            // a listed multidisciplinary firm paying its partners market salaries earns 12-15% before interest
            'revenue' => 45_400_000_000.00,
            'addressable_share' => 0.12,
            'public_float' => 0.75,
            'floating_debt_ratio' => 0.25,
            'historical_fixed_rate' => 0.045,
            'depreciation_rate' => 0.10,
        ],
    ];

    /**
     * The listed index funds.
     *
     * A fund is not an index and it is not named like one. The INDICES are the Lakebird family, published by
     * Lakebird Bank (see App\Service\Market\Index\MarketIndex); the FUNDS here are the tradable
     * vehicles that track them, and every one of them is run by the Skein Group. That is the structure real
     * fund names carry — sponsor, then index, then vehicle — and it is why a name like "Skein Lakebird 30
     * ETF" tells you three separate things while "Lakebird Index" told you none of them.
     *
     * `expense_ratio` is the annual fee, and it is not decoration: it is the whole of the fund's long-run
     * shortfall against the index it follows, and it is why a broad fund is a better vehicle for the same
     * market than a narrow one. The spread across the four is narrow because the Skein Group is mutually
     * owned by its own funds and has no shareholders to earn anything for — what is left is the real cost of
     * running each mandate. A whole-board fund that never has to trade is nearly free; a sector fund costs a
     * little more; a fund that re-ranks the market on a risk measure every quarter and trades its whole book
     * to match costs several times either, and pays its index licence out of the same fee.
     */
    public const ETFS = [
        [
            'ticker' => 'LBI',
            'name' => 'Skein Lakebird 30 ETF',
            'price' => 100.00,
            'expense_ratio' => 0.0005,
        ],
        [
            'ticker' => 'LBC',
            'name' => 'Skein Total Market ETF',
            'price' => 100.00,
            'expense_ratio' => 0.0003,
        ],
        [
            'ticker' => 'LBV',
            'name' => 'Skein Low Volatility ETF',
            'price' => 100.00,
            'expense_ratio' => 0.0013,
        ],
        [
            'ticker' => 'LBS',
            'name' => 'Skein Consumer Staples ETF',
            'price' => 100.00,
            'expense_ratio' => 0.0010,
        ],
    ];
}

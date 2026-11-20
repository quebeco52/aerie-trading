<?php

declare(strict_types=1);

namespace App\Data\Company;

use App\Entity\Stock;

/**
 * Industry moats, the step every listed firm's moat is set around: how much an industry's structure (entry costs,
 * networks, licences, switching costs, price competition) lets its firms earn over their cost of capital for good.
 *
 * Read off Damodaran's excess returns by industry (return on capital less cost of capital; ROE less cost of equity for
 * banks and insurers), January 2026, across the US, Europe, Japan and the emerging markets, and set for the District
 * where the economies disagree. Keyed by the Damodaran industry IndustryBetas maps each board industry to.
 */
final class IndustryMoats
{
    // --- Lore Band ---
    /**
     * Steps lore may move a firm from its industry's moat: two, a leader or a laggard. An industry's figure is weighted
     * to its large firms, and its best and worst firms sit far apart (31pp in software, 2pp in utilities).
     */
    public const LORE_BAND_STEPS = 2;

    // --- Industry Map ---
    /**
     * Board industries whose moat reads a different Damodaran industry from their beta: a search and cloud business
     * earns as information services do, not as the loss-making internet start-ups beside it; an online retailer as a
     * specialist retailer, not a department store.
     *
     * @var array<string, string>
     */
    public const INDUSTRY_MAP = [
        'Internet Content & Information' => 'Information Services',
        'Internet Retail' => 'Retail (Special Lines)',
        'Online Marketplaces' => 'Retail (Special Lines)',
    ];

    /**
     * Each Damodaran industry's usual moat: the step nearest the median excess return of the US, Europe, Japan, the
     * global and the emerging-market tables (January 2024-2026), moved where a region's reading is a cycle's or an
     * accounting artefact's.
     *
     * @var array<string, Moat>
     */
    public const DAMODARAN = [
        'Advertising' => Moat::Moderate, // Small book capital: buybacks lift its measured return
        'Aerospace/Defense' => Moat::Moderate,
        'Air Transport' => Moat::None, // Cyclical: the post-pandemic peak flatters it
        'Apparel' => Moat::Moderate,
        'Auto & Truck' => Moat::None,
        'Auto Parts' => Moat::None,
        'Bank (Money Center)' => Moat::Narrow,
        'Banks (Regional)' => Moat::None,
        'Beverage (Alcoholic)' => Moat::Moderate,
        'Beverage (Soft)' => Moat::VeryWide,
        'Broadcasting' => Moat::Narrow, // Licences: the US and Europe earn about 7pp
        'Brokerage & Investment Banking' => Moat::None, // Cyclical
        'Building Materials' => Moat::Moderate,
        'Business & Consumer Services' => Moat::Wide,
        'Chemical (Basic)' => Moat::None, // Cyclical, read at a trough
        'Chemical (Specialty)' => Moat::Narrow, // Goodwill understates it
        'Computer Services' => Moat::Wide,
        'Computers/Peripherals' => Moat::Narrow, // The US figure is mostly one firm
        'Diversified' => Moat::Narrow,
        'Drugs (Biotechnology)' => Moat::None, // Pipeline losses
        'Drugs (Pharmaceutical)' => Moat::Moderate, // Goodwill and patents
        'Education' => Moat::Narrow,
        'Electrical Equipment' => Moat::Narrow,
        'Electronics (General)' => Moat::None,
        'Engineering/Construction' => Moat::None,
        'Entertainment' => Moat::None,
        'Environmental & Waste Services' => Moat::Narrow,
        'Farming/Agriculture' => Moat::None, // Commodity
        'Financial Svcs. (Non-bank & Insurance)' => Moat::Moderate,
        'Food Processing' => Moat::Moderate, // Goodwill understates it
        'Food Wholesalers' => Moat::None,
        'Furn/Home Furnishings' => Moat::Narrow,
        'Green & Renewable Energy' => Moat::None,
        'Healthcare Products' => Moat::Narrow, // Goodwill understates it
        'Healthcare Support Services' => Moat::Moderate,
        'Heathcare Information and Technology' => Moat::Narrow,
        'Homebuilding' => Moat::None, // Cyclical
        'Hospitals/Healthcare Facilities' => Moat::Narrow,
        'Hotel/Gaming' => Moat::Narrow, // Casino licences
        'Household Products' => Moat::Wide,
        'Information Services' => Moat::Wide,
        'Insurance (General)' => Moat::Moderate,
        'Insurance (Life)' => Moat::None,
        'Insurance (Prop/Cas.)' => Moat::Moderate, // Read in a hard market
        'Investments & Asset Management' => Moat::Narrow, // Fee franchise
        'Machinery' => Moat::Moderate,
        'Metals & Mining' => Moat::Narrow, // Cyclical
        'Office Equipment & Services' => Moat::Narrow,
        'Oil/Gas (Integrated)' => Moat::Narrow, // Cyclical: 2022-24 prices
        // Cyclical: the US fell from 21pp to 7.5pp in three years
        'Oil/Gas (Production and Exploration)' => Moat::Narrow,
        'Oil/Gas Distribution' => Moat::Narrow,
        'Oilfield Svcs/Equip.' => Moat::None, // Cyclical
        'Packaging & Container' => Moat::Narrow,
        'Power' => Moat::None, // Regulated
        'Precious Metals' => Moat::None, // Cyclical
        'Publishing & Newspapers' => Moat::None,
        'R.E.I.T.' => Moat::None, // Book returns understate it (depreciation)
        'Real Estate (Development)' => Moat::None,
        'Real Estate (Operations & Services)' => Moat::None,
        'Recreation' => Moat::None,
        'Restaurant/Dining' => Moat::Moderate, // Franchisors' small book capital
        'Retail (Automotive)' => Moat::Narrow,
        'Retail (Building Supply)' => Moat::Narrow, // Small book capital: two large firms
        'Retail (Distributors)' => Moat::None,
        'Retail (General)' => Moat::None,
        'Retail (Grocery and Food)' => Moat::Narrow,
        'Retail (REITs)' => Moat::None,
        'Retail (Special Lines)' => Moat::Moderate,
        'Semiconductor' => Moat::Narrow, // Cyclical
        'Semiconductor Equip' => Moat::Moderate,
        'Shipbuilding & Marine' => Moat::None, // Cyclical
        'Shoe' => Moat::Moderate,
        'Software (Entertainment)' => Moat::Moderate,
        'Software (Internet)' => Moat::None, // Loss-makers
        'Software (System & Application)' => Moat::Wide, // Goodwill understates it
        'Steel' => Moat::None, // Cyclical
        'Telecom. Equipment' => Moat::None,
        'Telecom. Services' => Moat::Narrow, // Spectrum, regulated
        'Tobacco' => Moat::VeryWide,
        'Transportation' => Moat::None,
        'Transportation (Railroads)' => Moat::Narrow, // Networks: US Class I railroads 7pp each year
        'Trucking' => Moat::None,
        'Utility (General)' => Moat::None, // Regulated
        'Utility (Water)' => Moat::None, // Regulated
        'Total Market (without financials)' => Moat::Narrow,
    ];

    /** @var array<array-key, Moat>|null Each seeded firm's moat, by ticker (a numeric ticker keys as an int). */
    private static ?array $byTicker = null;

    /** The moat an industry's firms usually have; an unmapped industry takes the market's. */
    public static function forIndustry(?string $industry): Moat
    {
        return self::DAMODARAN[self::INDUSTRY_MAP[$industry ?? ''] ?? IndustryBetas::damodaranIndustryFor($industry)] ?? Moat::Narrow;
    }

    /**
     * A seed row's moat: the step its lore names, held within the band around its industry's, or its industry's.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function forRow(array $row): Moat
    {
        $industry = self::forIndustry($row['industry'] ?? null);
        if (!isset($row['moat'])) {
            return $industry;
        }

        $named = Moat::from((string) $row['moat']);

        return $industry->shifted(max(-self::LORE_BAND_STEPS, min(self::LORE_BAND_STEPS, $named->rank() - $industry->rank())));
    }

    /** A listed firm's moat: its seed row's, or its industry's for a firm the seed does not list. */
    public static function forStock(Stock $stock): Moat
    {
        if (self::$byTicker === null) {
            self::$byTicker = [];
            foreach (InitialMarket::STOCKS as $row) {
                self::$byTicker[$row['ticker']] = self::forRow($row);
            }
        }

        return self::$byTicker[$stock->getTicker()] ?? self::forIndustry($stock->getIndustry());
    }
}

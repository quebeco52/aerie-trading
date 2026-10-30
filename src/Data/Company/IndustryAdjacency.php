<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * Which industries sit next to which: a company enters only an industry next to one it is in, where what it already
 * knows (its customers, its technology, its people's skills) carries over. Firms diversify along such links and leave
 * the businesses furthest from them (Chang 1996; Miller & Yang 2016; Teece 1982).
 *
 * Each pair carries how close the two are: 0.8 for neighbours sharing most of a trade (cars and heavy machinery, two
 * kinds of software), 0.5 for businesses sharing customers or technology, 0.25 for a stretch a company makes on one of
 * those alone. An industry no company can enter by building or buying a business in it (a licence, a pass-through
 * structure, a commodity sold at a world price) is a source only: its firms may enter its neighbours, never the
 * reverse.
 */
final class IndustryAdjacency
{
    // --- Closeness ---
    /** Neighbours sharing most of a trade: the same customers, technology and skills. */
    public const CLOSE = 0.8;
    /** Businesses sharing customers or technology. */
    public const RELATED = 0.5;
    /** A stretch on one shared asset alone. */
    public const DISTANT = 0.25;

    /** @var list<array{0: string, 1: string, 2: float}> Industry, industry, closeness. */
    private const PAIRS = [
        // Machinery and equipment.
        ['Specialty Industrial Machinery', 'Farm & Heavy Construction Machinery', self::CLOSE],
        ['Specialty Industrial Machinery', 'Electrical Equipment & Parts', self::RELATED],
        ['Specialty Industrial Machinery', 'Tools & Accessories', self::RELATED],
        ['Specialty Industrial Machinery', 'Industrial Distribution', self::RELATED],
        ['Specialty Industrial Machinery', 'Aerospace & Defense', self::DISTANT],
        ['Specialty Industrial Machinery', 'Auto Manufacturers', self::DISTANT],
        ['Farm & Heavy Construction Machinery', 'Auto Manufacturers', self::RELATED],
        ['Farm & Heavy Construction Machinery', 'Agricultural Inputs', self::RELATED],
        ['Auto Manufacturers', 'Electrical Equipment & Parts', self::DISTANT],
        ['Tools & Accessories', 'Building Products & Equipment', self::RELATED],
        ['Electrical Equipment & Parts', 'Nuclear Power Equipment', self::RELATED],
        ['Electrical Equipment & Parts', 'Computer Hardware', self::DISTANT],
        ['Electrical Equipment & Parts', 'Utilities - Independent Power Producers', self::DISTANT],
        ['Nuclear Power Equipment', 'Utilities - Independent Power Producers', self::DISTANT],
        ['Aerospace & Defense', 'Space Systems', self::RELATED],
        ['Aerospace & Defense', 'Information Technology Services', self::DISTANT],
        ['Space Systems', 'Communication Equipment', self::RELATED],
        ['Aerospace & Defense', 'Security & Protection Services', self::DISTANT],
        ['Space Systems', 'Telecom Services', self::DISTANT],
        ['Steel', 'Building Products & Equipment', self::DISTANT],
        ['Steel', 'Industrial Distribution', self::DISTANT],
        ['Copper', 'Steel', self::RELATED],
        ['Copper', 'Electrical Equipment & Parts', self::DISTANT],
        // Building.
        ['Engineering & Construction', 'Building Products & Equipment', self::RELATED],
        ['Engineering & Construction', 'Residential Construction', self::RELATED],
        ['Engineering & Construction', 'Waste Management', self::DISTANT],
        ['Building Products & Equipment', 'Residential Construction', self::RELATED],
        // Technology.
        ['Computer Hardware', 'Communication Equipment', self::CLOSE],
        ['Computer Hardware', 'Semiconductors', self::RELATED],
        ['Computer Hardware', 'Software - Infrastructure', self::DISTANT],
        ['Computer Hardware', 'Information Technology Services', self::RELATED],
        ['Communication Equipment', 'Semiconductors', self::RELATED],
        ['Communication Equipment', 'Telecom Services', self::RELATED],
        ['Software - Infrastructure', 'Software - Application', self::CLOSE],
        ['Software - Infrastructure', 'Internet Content & Information', self::RELATED],
        ['Software - Infrastructure', 'Information Technology Services', self::RELATED],
        ['Software - Application', 'Information Technology Services', self::RELATED],
        ['Software - Application', 'Education & Training Services', self::DISTANT],
        ['Information Technology Services', 'Consulting Services', self::CLOSE],
        ['Information Technology Services', 'Security & Protection Services', self::DISTANT],
        ['Financial Data & Ratings', 'Software - Application', self::DISTANT],
        ['Financial Data & Ratings', 'Information Technology Services', self::DISTANT],
        // Services.
        ['Consulting Services', 'Legal Services', self::RELATED],
        ['Consulting Services', 'Education & Training Services', self::DISTANT],
        ['Security & Protection Services', 'Correctional Services', self::RELATED],
        ['Security & Protection Services', 'Consulting Services', self::DISTANT],
        // Media and online.
        ['Internet Content & Information', 'Internet Retail', self::RELATED],
        ['Internet Content & Information', 'Advertising Agencies', self::RELATED],
        ['Internet Content & Information', 'Broadcasting', self::DISTANT],
        ['Internet Content & Information', 'Telecom Services', self::DISTANT],
        ['Advertising Agencies', 'Broadcasting', self::RELATED],
        ['Internet Retail', 'Grocery Stores', self::DISTANT],
        ['Internet Retail', 'Integrated Freight & Logistics', self::DISTANT],
        ['Internet Retail', 'Apparel Manufacturing', self::DISTANT],
        // Consumer goods and food.
        ['Household & Personal Products', 'Packaged Foods', self::RELATED],
        ['Household & Personal Products', 'Luxury Goods', self::DISTANT],
        ['Household & Personal Products', 'Drug Manufacturers - General', self::DISTANT],
        ['Packaged Foods', 'Farm Products', self::RELATED],
        ['Packaged Foods', 'Beverages - Wineries & Distilleries', self::RELATED],
        ['Packaged Foods', 'Grocery Stores', self::DISTANT],
        ['Packaged Foods', 'Restaurants', self::DISTANT],
        ['Beverages - Wineries & Distilleries', 'Restaurants', self::DISTANT],
        ['Grocery Stores', 'Restaurants', self::DISTANT],
        ['Luxury Goods', 'Apparel Manufacturing', self::RELATED],
        ['Luxury Goods', 'Beverages - Wineries & Distilleries', self::RELATED],
        ['Restaurants', 'Resorts & Casinos', self::DISTANT],
        ['Tobacco', 'Packaged Foods', self::DISTANT],
        ['Tobacco', 'Beverages - Wineries & Distilleries', self::DISTANT],
        // Chemicals and farming.
        ['Chemicals', 'Specialty Chemicals', self::CLOSE],
        ['Chemicals', 'Agricultural Inputs', self::RELATED],
        ['Specialty Chemicals', 'Agricultural Inputs', self::RELATED],
        ['Specialty Chemicals', 'Drug Manufacturers - General', self::DISTANT],
        ['Specialty Chemicals', 'Semiconductors', self::DISTANT],
        ['Semiconductors', 'Electrical Equipment & Parts', self::DISTANT],
        ['Agricultural Inputs', 'Farm Products', self::RELATED],
        ['Oil & Gas Refining & Marketing', 'Chemicals', self::RELATED],
        // Power.
        ['Oil & Gas E&P', 'Utilities - Independent Power Producers', self::DISTANT],
        ['Utilities - Regulated Electric', 'Utilities - Independent Power Producers', self::RELATED],
        ['Utilities - Regulated Water', 'Waste Management', self::DISTANT],
        // Transport.
        ['Airlines', 'Integrated Freight & Logistics', self::RELATED],
        ['Airlines', 'Passenger Transit', self::DISTANT],
        ['Integrated Freight & Logistics', 'Marine Shipping', self::RELATED],
        ['Integrated Freight & Logistics', 'Marine Ports & Services', self::RELATED],
        ['Integrated Freight & Logistics', 'Industrial Distribution', self::DISTANT],
        ['Integrated Freight & Logistics', 'Waste Management', self::DISTANT],
        ['Marine Shipping', 'Marine Ports & Services', self::RELATED],
        ['Railroads', 'Passenger Transit', self::RELATED],
        ['Railroads', 'Integrated Freight & Logistics', self::RELATED],
        ['Merchant Houses', 'Marine Ports & Services', self::RELATED],
        ['Merchant Houses', 'Farm Products', self::RELATED],
        ['Merchant Houses', 'Industrial Distribution', self::RELATED],
        // Health.
        ['Healthcare Plans', 'Medical Care Facilities', self::RELATED],
        // Holding companies buy what their managers know how to run: a stretch from anywhere.
        ['Conglomerates', 'Specialty Industrial Machinery', self::RELATED],
        ['Conglomerates', 'Electrical Equipment & Parts', self::DISTANT],
        ['Conglomerates', 'Household & Personal Products', self::DISTANT],
        ['Conglomerates', 'Building Products & Equipment', self::DISTANT],
        ['Conglomerates', 'Packaged Foods', self::DISTANT],
        ['Conglomerates', 'Industrial Distribution', self::DISTANT],
        ['Investment Companies', 'Packaged Foods', self::DISTANT],
        ['Investment Companies', 'Household & Personal Products', self::DISTANT],
        ['Investment Companies', 'Building Products & Equipment', self::DISTANT],
        ['Investment Companies', 'Industrial Distribution', self::DISTANT],
        ['Investment Companies', 'Utilities - Independent Power Producers', self::DISTANT],
    ];

    /**
     * Industries no company enters by building plant or buying a business: a licence, spectrum or rights of way (an
     * exchange, a railroad, a health plan, a casino, a network or a broadcaster, tobacco), patents that earn what plant
     * does not (a drug maker), or a commodity sold at a world price or a regulated tariff. Their firms may still enter
     * their neighbours.
     */
    private const SOURCES_ONLY = [
        'Tobacco',
        'Financial Data & Ratings',
        'Securities & Commodity Exchanges',
        'Healthcare Plans',
        'Railroads',
        'Resorts & Casinos',
        'Telecom Services',
        'Broadcasting',
        'Drug Manufacturers - General',
        'Copper',
        'Oil & Gas E&P',
        'Oil & Gas Refining & Marketing',
        'Utilities - Regulated Electric',
        'Utilities - Regulated Water',
        'Conglomerates',
        'Investment Companies',
        'Merchant Houses',
    ];

    /**
     * Holding companies, which add businesses by buying them and leave building to the businesses they own (Berkshire's
     * model; a conglomerate's divisions): they never build a venture of their own.
     */
    private const HOLDING_COMPANIES = ['Conglomerates', 'Investment Companies'];

    /** The name a company gives a business it starts or buys in an industry, by that industry. */
    private const LABELS = [
        'Advertising Agencies' => 'Advertising',
        'Aerospace & Defense' => 'Aerospace and defence',
        'Agricultural Inputs' => 'Crop inputs',
        'Airlines' => 'Airline',
        'Apparel Manufacturing' => 'Apparel',
        'Auto Manufacturers' => 'Vehicles',
        'Beverages - Wineries & Distilleries' => 'Wines and spirits',
        'Broadcasting' => 'Broadcasting',
        'Building Products & Equipment' => 'Building products',
        'Chemicals' => 'Chemicals',
        'Communication Equipment' => 'Network equipment',
        'Computer Hardware' => 'Computer hardware',
        'Consulting Services' => 'Consulting',
        'Correctional Services' => 'Prisons',
        'Drug Manufacturers - General' => 'Pharmaceuticals',
        'Education & Training Services' => 'Education',
        'Electrical Equipment & Parts' => 'Electrical equipment',
        'Engineering & Construction' => 'Engineering and construction',
        'Farm & Heavy Construction Machinery' => 'Heavy machinery',
        'Farm Products' => 'Farming',
        'Grocery Stores' => 'Grocery',
        'Household & Personal Products' => 'Household products',
        'Industrial Distribution' => 'Industrial distribution',
        'Information Technology Services' => 'IT services',
        'Integrated Freight & Logistics' => 'Freight and logistics',
        'Internet Content & Information' => 'Online services',
        'Internet Retail' => 'Online retail',
        'Legal Services' => 'Legal services',
        'Luxury Goods' => 'Luxury goods',
        'Marine Ports & Services' => 'Ports and terminals',
        'Marine Shipping' => 'Shipping',
        'Medical Care Facilities' => 'Hospitals and clinics',
        'Nuclear Power Equipment' => 'Nuclear equipment',
        'Packaged Foods' => 'Packaged foods',
        'Passenger Transit' => 'Passenger transit',
        'Residential Construction' => 'Homebuilding',
        'Resorts & Casinos' => 'Resorts',
        'Restaurants' => 'Restaurants',
        'Security & Protection Services' => 'Security services',
        'Semiconductors' => 'Semiconductors',
        'Software - Application' => 'Application software',
        'Software - Infrastructure' => 'Infrastructure software',
        'Space Systems' => 'Space systems',
        'Specialty Chemicals' => 'Specialty chemicals',
        'Specialty Industrial Machinery' => 'Industrial machinery',
        'Steel' => 'Steel',
        'Telecom Services' => 'Telecom services',
        'Tools & Accessories' => 'Tools',
        'Utilities - Independent Power Producers' => 'Power generation',
        'Waste Management' => 'Waste services',
    ];

    /**
     * The industries a company in this one may enter, each with how close it is; the closest link counts where two
     * pairs name the same industry.
     *
     * @return array<string, float> Industry => closeness.
     */
    public static function neighbours(string $industry): array
    {
        $neighbours = [];
        foreach (self::PAIRS as [$first, $second, $closeness]) {
            $other = match ($industry) {
                $first => $second,
                $second => $first,
                default => null,
            };
            if ($other !== null && !in_array($other, self::SOURCES_ONLY, true)) {
                $neighbours[$other] = max($neighbours[$other] ?? 0.0, $closeness);
            }
        }

        return $neighbours;
    }

    /** Whether a company in this industry is a holding company, which buys businesses and builds none. */
    public static function isHoldingCompany(string $industry): bool
    {
        return in_array($industry, self::HOLDING_COMPANIES, true);
    }

    /** The name of a business in this industry, or null for one no company enters. */
    public static function label(string $industry): ?string
    {
        return self::LABELS[$industry] ?? null;
    }

    /**
     * Every pair, for the data's own checks.
     *
     * @return list<array{0: string, 1: string, 2: float}>
     */
    public static function pairs(): array
    {
        return self::PAIRS;
    }

    /** @return list<string> */
    public static function sourcesOnly(): array
    {
        return self::SOURCES_ONLY;
    }
}

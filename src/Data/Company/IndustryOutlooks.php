<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * How unsettled each industry's long-run demand is (IndustryOutlook): how fast its true level drifts from the path its
 * model expects, and how fast the public learns where it has gone. Sized from how far industries' shares of gross
 * output moved around their own trends (BEA GDP by Industry 1997-2025, checked against ten EU economies); an industry
 * not listed takes the defaults.
 */
final class IndustryOutlooks
{
    // --- Outlook Uncertainty ---
    /** Annual volatility of an industry's true long-run demand around its expected path, in log: none unless listed. */
    public const DEFAULT_DRIFT_VOL = 0.0;
    /**
     * Years the settled public estimate takes to close half its gap to the truth (0.75): measured shares move as a
     * random walk with little to filter, so the lag is a reading's delay plus a little momentum.
     */
    public const DEFAULT_LEARNING_HALF_LIFE_YEARS = 0.75;

    /** @var array<string, array{drift_vol?: float, learning_half_life?: float}> Industry => its own settings. */
    private const BY_INDUSTRY = [
        // Staples, care and settled services: about 11% spread of true demand over 20 years.
        'Education & Training Services' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Farm Products' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Grocery Stores' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Healthcare Plans' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Household & Personal Products' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Integrated Freight & Logistics' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Marine Ports & Services' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Medical Care Facilities' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Packaged Foods' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'REIT - Healthcare Facilities' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'REIT - Residential' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Restaurants' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        'Waste Management' => ['drift_vol' => 0.025, 'learning_half_life' => 0.5],
        // Steady services, media and transport: about 18% over 20 years.
        'Agricultural Inputs' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Airlines' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Apparel Manufacturing' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Beverages - Wineries & Distilleries' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Broadcasting' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Consulting Services' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Correctional Services' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Financial Data & Stock Exchanges' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Legal Services' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Passenger Transit' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'REIT - Industrial' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Resorts & Casinos' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Security & Protection Services' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Telecom Services' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        'Utilities - Independent Power Producers' => ['drift_vol' => 0.04, 'learning_half_life' => 0.5],
        // Capital goods, materials, offices and pharma: about 27% over 20 years; a shift shows over a year.
        'Advertising Agencies' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Building Products & Equipment' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Chemicals' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Drug Manufacturers - General' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Electrical Equipment & Parts' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Engineering & Construction' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Industrial Distribution' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Information Technology Services' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Internet Retail' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Luxury Goods' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Marine Shipping' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'REIT - Office' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'REIT - Retail' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Railroads' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Specialty Chemicals' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Specialty Industrial Machinery' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Steel' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Tobacco' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        'Tools & Accessories' => ['drift_vol' => 0.06, 'learning_half_life' => 1.0],
        // Technology, vehicles, heavy machinery and frontier markets: about 40% over 20 years.
        'Aerospace & Defense' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Auto Manufacturers' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Communication Equipment' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Computer Hardware' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Farm & Heavy Construction Machinery' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Internet Content & Information' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Nuclear Power Equipment' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Residential Construction' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Semiconductors' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Software - Application' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Software - Infrastructure' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        'Space Systems' => ['drift_vol' => 0.09, 'learning_half_life' => 0.75],
        // World commodities the macro prices: a local outlook would put a glut on a world price.
        'Copper' => ['drift_vol' => 0.0],
        'Gold' => ['drift_vol' => 0.0],
        'Oil & Gas E&P' => ['drift_vol' => 0.0],
        'Oil & Gas Refining & Marketing' => ['drift_vol' => 0.0],
        // Output not substitutable: no capacity balance to move, so a walk would only rescale the others.
        'Conglomerates' => ['drift_vol' => 0.0],
        'Investment Companies' => ['drift_vol' => 0.0],
        'Merchant Houses' => ['drift_vol' => 0.0],
        'Utilities - Regulated Electric' => ['drift_vol' => 0.0],
        'Utilities - Regulated Water' => ['drift_vol' => 0.0],
    ];

    public static function driftVol(string $industry): float
    {
        return self::BY_INDUSTRY[$industry]['drift_vol'] ?? self::DEFAULT_DRIFT_VOL;
    }

    public static function learningHalfLife(string $industry): float
    {
        return self::BY_INDUSTRY[$industry]['learning_half_life'] ?? self::DEFAULT_LEARNING_HALF_LIFE_YEARS;
    }
}

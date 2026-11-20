<?php

declare(strict_types=1);

namespace App\Data\Company;

use App\Service\Math\MacroConstants;

/**
 * Total opening revenue market by board industry (listed firms plus the private, unlisted, foreign and public-sector
 * competitive fringe), expressed as a multiple of District GDP at the opening (MacroConstants::DISTRICT_GDP_USD).
 *
 * Sizing the market once per industry means every firm in a multi-firm industry shares one demand pool and leaves the
 * unlisted fringe as 1 minus the sum of listed shares, while growing with the economy alongside GDP.
 */
final class IndustryMarkets
{
    // --- Industry Market Sizes ---
    /**
     * Total opening annual revenue market (listed firms plus unlisted competitive fringe) as a multiple of
     * MacroConstants::DISTRICT_GDP_USD ($6T), by board industry. Excludes Investment Companies (BRKW, hand-set books)
     * and the closed-fund AssetManagementBusinessModel industries, which close on ModelParam::CapacityShare.
     *
     * @var array<string, float>
     */
    public const BY_INDUSTRY = [
        'Advertising Agencies' => 0.013,
        'Aerospace & Defense' => 0.046,
        'Airlines' => 0.036,
        'Apparel Manufacturing' => 0.055,
        'Auto Manufacturers' => 0.150,
        'Banks - Diversified' => 0.292,
        // Shared regional banking market: PLVR opens at 35% and RIVR at 22%, both with headroom below LAKE.
        'Banks - Regional' => 0.065,
        'Beverages - Wineries & Distilleries' => 0.018,
        'Broadcasting' => 0.028,
        'Brokerages' => 0.0036,
        'Building Products & Equipment' => 0.055,
        'Chemicals' => 0.045,
        'Communication Equipment' => 0.088,
        'Computer Hardware' => 0.024,
        'Conglomerates' => 0.045,
        'Consulting Services' => 0.070,
        'Copper' => 0.023,
        'Correctional Services' => 0.008,
        'Credit Services' => 0.032,
        'Custody Banks' => 0.042,
        'Drug Manufacturers - General' => 0.034,
        'Education & Training Services' => 0.021,
        'Electrical Equipment & Parts' => 0.035,
        'Engineering & Construction' => 0.123,
        'Farm & Heavy Construction Machinery' => 0.050,
        'Farm Products' => 0.058,
        'Financial Clearinghouses' => 0.0042,
        'Financial Data & Ratings' => 0.012,
        'Gold' => 0.013,
        'Grocery Stores' => 0.083,
        'Healthcare Plans' => 0.088,
        'Household & Personal Products' => 0.046,
        'Industrial Distribution' => 0.030,
        'Insurance - Diversified' => 0.059,
        'Insurance - Life' => 0.063,
        'Insurance - Reinsurance' => 0.228,
        'Insurance Brokers' => 0.013,
        'Integrated Freight & Logistics' => 0.055,
        'Internet Content & Information' => 0.047,
        'Internet Retail' => 0.053,
        'Investment Banking' => 0.035,
        'Legal Services' => 0.0084,
        'Luxury Goods' => 0.022,
        'Marine Ports & Services' => 0.0053,
        'Marine Shipping' => 0.036,
        'Medical Care Facilities' => 0.060,
        'Merchant Houses' => 0.180,
        'Mortgage Finance' => 0.0043,
        'Nuclear Power Equipment' => 0.024,
        'Oil & Gas E&P' => 0.038,
        'Oil & Gas Refining & Marketing' => 0.386,
        'Online Marketplaces' => 0.026,
        'Packaged Foods' => 0.025,
        'Private Banks' => 0.014,
        'REIT - Healthcare Facilities' => 0.0051,
        'REIT - Industrial' => 0.0095,
        'REIT - Office' => 0.012,
        'REIT - Residential' => 0.0091,
        'Railroads' => 0.013,
        'Residential Construction' => 0.030,
        'Resorts & Casinos' => 0.026,
        'Restaurants' => 0.020,
        // The only licensed venue: the District's own turnover (plus off-exchange/regional venues) is the room it has.
        'Securities & Commodity Exchanges' => 0.004,
        'Security & Protection Services' => 0.040,
        'Semiconductors' => 0.033,
        'Space Systems' => 0.0063,
        'Specialty Industrial Machinery' => 0.070,
        'Steel' => 0.040,
        'Telecom Services' => 0.032,
        'Tobacco' => 0.014,
        'Tools & Accessories' => 0.020,
        'Utilities - Independent Power Producers' => 0.0058,
        'Utilities - Regulated Electric' => 0.038,
        'Utilities - Regulated Water' => 0.014,
        'Waste Management' => 0.014,
    ];

    /** Whether the board industry has a declared opening market size in BY_INDUSTRY. */
    public static function hasIndustry(?string $industry): bool
    {
        return isset(self::BY_INDUSTRY[$industry ?? '']);
    }

    /** Opening total annual revenue market as a multiple of District GDP. */
    public static function gdpShare(?string $industry): float
    {
        if (!self::hasIndustry($industry)) {
            throw new \DomainException(sprintf('Industry %s has no entry in IndustryMarkets.', $industry ?? 'null'));
        }

        return self::BY_INDUSTRY[(string) $industry];
    }

    /** Opening total annual revenue market in USD. */
    public static function openingMarket(?string $industry): float
    {
        return MacroConstants::DISTRICT_GDP_USD * self::gdpShare($industry);
    }

    /** Opening share of its industry's market for a firm opening on the stated annual revenue. */
    public static function openingShare(?string $industry, float $revenue): float
    {
        $market = self::openingMarket($industry);
        if ($revenue <= 0.0 || $revenue > $market) {
            throw new \DomainException(sprintf(
                'Revenue %.2f cannot open inside %s market %.2f.',
                $revenue,
                $industry ?? 'null',
                $market,
            ));
        }

        return $revenue / $market;
    }

    /**
     * Refuses an in-scope seed row whose industry has no market entry, whose revenue is missing or non-positive, or
     * whose revenue exceeds its industry's total market.
     *
     * @param array<string, mixed> $row
     */
    public static function assertRow(array $row): void
    {
        $industry = $row['industry'] ?? null;
        if (!is_string($industry) || !self::hasIndustry($industry)) {
            throw new \DomainException(sprintf(
                '%s sits in industry %s, which has no entry in IndustryMarkets.',
                (string) ($row['ticker'] ?? '?'),
                is_scalar($industry) ? (string) $industry : get_debug_type($industry),
            ));
        }

        self::openingShare($industry, (float) ($row['revenue'] ?? 0.0));
    }

    /**
     * A seed row's opening addressable market share, derived from its revenue over its industry's market.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function forRow(array $row): float
    {
        self::assertRow($row);

        return self::openingShare((string) $row['industry'], (float) $row['revenue']);
    }
}

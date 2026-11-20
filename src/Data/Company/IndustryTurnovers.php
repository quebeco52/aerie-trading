<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * Opening capital turnover (annual revenue per unit of invested capital) by board industry.
 *
 * Keyed by board industry rather than Damodaran industry because physical capital intensity diverges sharply inside
 * coarse Damodaran buckets (marine ports vs freight logistics, luxury goods vs apparel manufacturing, law firms vs
 * consultants).
 *
 * Board turnovers run about 1.5-2x below Damodaran's US Sales/Invested Capital dataset (January 2026) for two reasons:
 * the moat ladder caps opening excess returns near 15pp (opening ROIC around 25%) while US book capital is shrunk by
 * buybacks, and several US buckets do not match District firms (exchanges under non-bank finance, refiners under oil
 * pipelines, health insurers under healthcare support).
 */
final class IndustryTurnovers
{
    // --- Lore Band ---
    /**
     * Maximum steps lore may move a firm's turnover from its industry's (very_light..very_heavy around typical).
     */
    public const LORE_BAND_STEPS = 2;

    // --- Industry Turnovers ---
    /** @var array<string, float> Opening annual revenue per unit of invested capital, by board industry. */
    public const BY_INDUSTRY = [
        'Advertising Agencies' => 1.16,
        'Aerospace & Defense' => 1.45,
        'Airlines' => 1.17,
        'Apparel Manufacturing' => 1.25,
        'Auto Manufacturers' => 1.43,
        'Beverages - Wineries & Distilleries' => 1.21,
        'Broadcasting' => 0.76,
        'Building Products & Equipment' => 1.38,
        'Chemicals' => 0.81,
        'Communication Equipment' => 1.43,
        'Computer Hardware' => 0.83,
        'Conglomerates' => 0.83,
        'Consulting Services' => 1.93,
        'Copper' => 0.51,
        'Correctional Services' => 0.68,
        'Drug Manufacturers - General' => 0.49,
        'Education & Training Services' => 0.93,
        'Electrical Equipment & Parts' => 1.34,
        'Engineering & Construction' => 1.86,
        'Farm & Heavy Construction Machinery' => 1.33,
        'Farm Products' => 2.17,
        'Financial Data & Ratings' => 0.53,
        'Gold' => 0.49,
        'Grocery Stores' => 4.59,
        'Healthcare Plans' => 3.94,
        'Household & Personal Products' => 1.13,
        'Industrial Distribution' => 1.19,
        'Insurance Brokers' => 0.80,
        'Integrated Freight & Logistics' => 1.26,
        'Internet Content & Information' => 0.77,
        'Internet Retail' => 3.25,
        'Legal Services' => 0.91,
        'Luxury Goods' => 0.58,
        'Marine Ports & Services' => 0.35,
        'Marine Shipping' => 0.74,
        'Medical Care Facilities' => 1.68,
        'Merchant Houses' => 1.27,
        'Nuclear Power Equipment' => 1.25,
        'Oil & Gas E&P' => 0.67,
        'Oil & Gas Refining & Marketing' => 1.75,
        // A marketplace books its commission, not the merchandise, so it turns over less than an online shop.
        'Online Marketplaces' => 1.42,
        'Packaged Foods' => 1.19,
        'Railroads' => 0.35,
        'Residential Construction' => 1.10,
        'Resorts & Casinos' => 0.86,
        'Restaurants' => 1.12,
        'Securities & Commodity Exchanges' => 0.43,
        'Security & Protection Services' => 1.16,
        'Semiconductors' => 0.52,
        'Space Systems' => 1.53,
        'Specialty Industrial Machinery' => 1.31,
        'Steel' => 0.99,
        'Telecom Services' => 0.58,
        'Tobacco' => 0.65,
        'Tools & Accessories' => 0.79,
        'Utilities - Independent Power Producers' => 0.39,
        'Utilities - Regulated Electric' => 0.54,
        'Utilities - Regulated Water' => 0.54,
        'Waste Management' => 0.68,
    ];

    public static function hasIndustry(?string $industry): bool
    {
        return isset(self::BY_INDUSTRY[$industry ?? '']);
    }

    /** Opening annual revenue per unit of invested capital for a board industry. */
    public static function forIndustry(?string $industry): float
    {
        if (!self::hasIndustry($industry)) {
            throw new \DomainException(sprintf('Industry %s has no entry in IndustryTurnovers.', $industry ?? 'null'));
        }

        return self::BY_INDUSTRY[(string) $industry];
    }

    /**
     * A seed row's capital-intensity step, or typical when absent.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function intensityForRow(array $row): CapitalIntensity
    {
        if (!isset($row['capital_intensity'])) {
            return CapitalIntensity::Typical;
        }

        return CapitalIntensity::from((string) $row['capital_intensity']);
    }

    /**
     * A seed row's opening capital turnover: its industry's turnover shifted by its lore capital-intensity step.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function forRow(array $row): float
    {
        self::assertRow($row);
        $steps = max(-self::LORE_BAND_STEPS, min(self::LORE_BAND_STEPS, self::intensityForRow($row)->steps()));

        return self::forIndustry($row['industry'] ?? null) * (CapitalIntensity::STEP_FACTOR ** $steps);
    }

    /**
     * Refuses a seed row that names an unknown capital_intensity step or one outside ±LORE_BAND_STEPS.
     *
     * @param array<string, mixed> $row
     */
    public static function assertCapitalIntensity(array $row): void
    {
        if (!array_key_exists('capital_intensity', $row)) {
            return;
        }

        $raw = $row['capital_intensity'];
        $intensity = is_string($raw) ? CapitalIntensity::tryFrom($raw) : null;
        if ($intensity === null || abs($intensity->steps()) > self::LORE_BAND_STEPS) {
            throw new \DomainException(sprintf(
                '%s states unknown capital_intensity %s.',
                (string) ($row['ticker'] ?? '?'),
                is_scalar($raw) ? (string) $raw : get_debug_type($raw),
            ));
        }
    }

    /**
     * Refuses an in-scope seed row whose industry has no turnover entry or whose capital_intensity step is invalid.
     *
     * @param array<string, mixed> $row
     */
    public static function assertRow(array $row): void
    {
        self::assertCapitalIntensity($row);

        $industry = $row['industry'] ?? null;
        if (!is_string($industry) || !self::hasIndustry($industry)) {
            throw new \DomainException(sprintf(
                '%s sits in industry %s, which has no entry in IndustryTurnovers.',
                (string) ($row['ticker'] ?? '?'),
                is_scalar($industry) ? (string) $industry : get_debug_type($industry),
            ));
        }
    }
}

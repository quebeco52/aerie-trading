<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * Opening fixed share of cash operating cost (net of depreciation) by board industry, shifted at most two steps by
 * CostStructure.
 *
 * Keyed by board industry rather than Damodaran gross margins (January 2026): Damodaran was checked and rejected
 * because GAAP cost of goods absorbs fixed plant, track and site maintenance and Aerie's moat ladder pushes operating
 * margins above US industry gross margins.
 */
final class IndustryFixedCosts
{
    // --- Lore Band & Bounds ---
    /**
     * Maximum steps lore may move a firm's cost structure from its industry's (very_flexible..very_rigid around
     * typical).
     */
    public const LORE_BAND_STEPS = 2;

    /** Lower clamp on the opening fixed share of cash operating cost. */
    public const MIN_FIXED_SHARE = 0.0;

    /** Upper clamp on the opening fixed share of cash operating cost. */
    public const MAX_FIXED_SHARE = 0.95;

    // --- Industry Fixed Shares ---
    /** @var array<string, float> Opening fixed share of cash operating cost, by board industry. */
    public const BY_INDUSTRY = [
        'Advertising Agencies' => 0.25,
        'Aerospace & Defense' => 0.65,
        // Aircraft ownership, maintenance bases and salaried staff are fixed; fuel, crews and fees follow flying.
        'Airlines' => 0.30,
        'Apparel Manufacturing' => 0.55,
        'Asset Management' => 0.30,
        'Auto Manufacturers' => 0.45,
        'Beverages - Wineries & Distilleries' => 0.45,
        'Broadcasting' => 0.55,
        'Brokerages' => 0.70,
        'Building Products & Equipment' => 0.50,
        'Chemicals' => 0.60,
        // Ericsson 2022: cost of sales is roughly 65% of operating costs.
        'Communication Equipment' => 0.35,
        'Computer Hardware' => 0.55,
        'Conglomerates' => 0.35,
        'Consulting Services' => 0.45,
        'Copper' => 0.80,
        'Correctional Services' => 0.55,
        'Distressed Debt' => 0.35,
        'Drug Manufacturers - General' => 0.70,
        'Education & Training Services' => 0.65,
        'Electrical Equipment & Parts' => 0.50,
        'Engineering & Construction' => 0.60,
        'Farm & Heavy Construction Machinery' => 0.55,
        'Farm Products' => 0.60,
        'Financial Clearinghouses' => 0.30,
        'Financial Data & Ratings' => 0.70,
        'Gold' => 0.80,
        'Grocery Stores' => 0.10,
        'Hedge Fund' => 0.45,
        'Household & Personal Products' => 0.45,
        'Industrial Distribution' => 0.35,
        'Insurance Brokers' => 0.60,
        'Integrated Freight & Logistics' => 0.55,
        'Internet Content & Information' => 0.85,
        'Internet Retail' => 0.70,
        'Investment Banking' => 0.50,
        // Matches Conglomerates: consolidated industrial subsidiaries carry real operating leverage, not fund overhead.
        'Investment Companies' => 0.35,
        'Legal Services' => 0.25,
        'Luxury Goods' => 0.50,
        'Marine Ports & Services' => 0.60,
        'Marine Shipping' => 0.65,
        'Medical Care Facilities' => 0.65,
        'Merchant Houses' => 0.45,
        'Mortgage Finance' => 0.30,
        'Nuclear Power Equipment' => 0.50,
        'Oil & Gas E&P' => 0.75,
        // Crude feedstock takes ~85% of barrel realization, capping non-feedstock fixed share near 3%.
        'Oil & Gas Refining & Marketing' => 0.025,
        'Online Marketplaces' => 0.55,
        'Packaged Foods' => 0.25,
        'Private Equity' => 0.40,
        'REIT - Healthcare Facilities' => 0.75,
        'REIT - Industrial' => 0.30,
        'REIT - Office' => 0.85,
        'REIT - Residential' => 0.25,
        'Railroads' => 0.80,
        // Selling and overhead are about 10% of costs; land and construction are the rest.
        'Residential Construction' => 0.10,
        'Resorts & Casinos' => 0.70,
        'Restaurants' => 0.55,
        'Securities & Commodity Exchanges' => 0.75,
        'Security & Protection Services' => 0.45,
        'Semiconductors' => 0.70,
        'Space Systems' => 0.45,
        'Specialty Industrial Machinery' => 0.55,
        'Steel' => 0.45,
        // Device cost is roughly a quarter to a third of cash operating costs.
        'Telecom Services' => 0.70,
        'Tobacco' => 0.25,
        'Tools & Accessories' => 0.60,
        // Fuel and carbon for the gas fleet are most of cash cost.
        'Utilities - Independent Power Producers' => 0.35,
        'Utilities - Regulated Electric' => 0.80,
        'Utilities - Regulated Water' => 0.80,
        'Waste Management' => 0.50,
    ];

    public static function hasIndustry(?string $industry): bool
    {
        return isset(self::BY_INDUSTRY[$industry ?? '']);
    }

    /** Opening fixed share of cash operating cost for a board industry. */
    public static function forIndustry(?string $industry): float
    {
        if (!self::hasIndustry($industry)) {
            throw new \DomainException(sprintf('Industry %s has no entry in IndustryFixedCosts.', $industry ?? 'null'));
        }

        return self::BY_INDUSTRY[(string) $industry];
    }

    /**
     * A seed row's cost-structure step, or typical when absent.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function costStructureForRow(array $row): CostStructure
    {
        if (!isset($row['cost_structure'])) {
            return CostStructure::Typical;
        }

        return CostStructure::from((string) $row['cost_structure']);
    }

    /**
     * A seed row's opening fixed share of cash operating cost: its industry's fixed share shifted by its lore step and
     * clamped to [MIN_FIXED_SHARE, MAX_FIXED_SHARE].
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function forRow(array $row): float
    {
        self::assertRow($row);
        $steps = max(-self::LORE_BAND_STEPS, min(self::LORE_BAND_STEPS, self::costStructureForRow($row)->steps()));
        $raw = round(self::forIndustry($row['industry'] ?? null) + ($steps * CostStructure::STEP_OFFSET), 6);

        return max(self::MIN_FIXED_SHARE, min(self::MAX_FIXED_SHARE, $raw));
    }

    /**
     * Refuses a seed row that names an unknown cost_structure step or one outside ±LORE_BAND_STEPS.
     *
     * @param array<string, mixed> $row
     */
    public static function assertCostStructure(array $row): void
    {
        if (!array_key_exists('cost_structure', $row)) {
            return;
        }

        $raw = $row['cost_structure'];
        $step = is_string($raw) ? CostStructure::tryFrom($raw) : null;
        if ($step === null || abs($step->steps()) > self::LORE_BAND_STEPS) {
            throw new \DomainException(sprintf(
                '%s states unknown cost_structure %s.',
                (string) ($row['ticker'] ?? '?'),
                is_scalar($raw) ? (string) $raw : get_debug_type($raw),
            ));
        }
    }

    /**
     * Refuses an in-scope seed row whose industry has no fixed-cost entry or whose cost_structure step is invalid.
     *
     * @param array<string, mixed> $row
     */
    public static function assertRow(array $row): void
    {
        self::assertCostStructure($row);

        $industry = $row['industry'] ?? null;
        if (!is_string($industry) || !self::hasIndustry($industry)) {
            throw new \DomainException(sprintf(
                '%s sits in industry %s, which has no entry in IndustryFixedCosts.',
                (string) ($row['ticker'] ?? '?'),
                is_scalar($industry) ? (string) $industry : get_debug_type($industry),
            ));
        }
    }
}

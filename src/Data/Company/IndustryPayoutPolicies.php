<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * Industry payout policies, the dividend policy a listed firm opens on unless its lore names another: how much of its
 * earnings an industry's firms usually hand back while their markets still have room. Set from the District's listed
 * firms at the opening; the board's annual review moves each firm from there as its market fills or opens up.
 */
final class IndustryPayoutPolicies
{
    /** @var array<string, PayoutPolicy> The usual opening policy, by board industry. */
    public const BY_INDUSTRY = [
        'Advertising Agencies' => PayoutPolicy::Income,
        'Aerospace & Defense' => PayoutPolicy::Income,
        'Airlines' => PayoutPolicy::Low,
        'Apparel Manufacturing' => PayoutPolicy::Balanced,
        'Asset Management' => PayoutPolicy::Income,
        'Auto Manufacturers' => PayoutPolicy::Income,
        'Banks - Diversified' => PayoutPolicy::Balanced,
        'Banks - Regional' => PayoutPolicy::Income,
        'Beverages - Wineries & Distilleries' => PayoutPolicy::Income,
        'Broadcasting' => PayoutPolicy::Balanced,
        'Brokerages' => PayoutPolicy::Income,
        'Building Products & Equipment' => PayoutPolicy::Balanced,
        'Chemicals' => PayoutPolicy::Balanced,
        'Communication Equipment' => PayoutPolicy::Income,
        'Computer Hardware' => PayoutPolicy::Low,
        'Conglomerates' => PayoutPolicy::Balanced,
        'Consulting Services' => PayoutPolicy::Income,
        'Copper' => PayoutPolicy::Low,
        'Correctional Services' => PayoutPolicy::Harvest,
        'Credit Services' => PayoutPolicy::Balanced,
        'Custody Banks' => PayoutPolicy::Balanced,
        'Distressed Debt' => PayoutPolicy::Balanced,
        'Drug Manufacturers - General' => PayoutPolicy::Balanced,
        'Education & Training Services' => PayoutPolicy::Income,
        'Electrical Equipment & Parts' => PayoutPolicy::Balanced,
        'Engineering & Construction' => PayoutPolicy::Low,
        'Farm & Heavy Construction Machinery' => PayoutPolicy::Income,
        'Farm Products' => PayoutPolicy::Balanced,
        'Financial Clearinghouses' => PayoutPolicy::Income,
        'Financial Data & Ratings' => PayoutPolicy::Harvest,
        'Gold' => PayoutPolicy::Balanced,
        'Grocery Stores' => PayoutPolicy::Balanced,
        'Healthcare Plans' => PayoutPolicy::Balanced,
        'Hedge Fund' => PayoutPolicy::Low,
        'Household & Personal Products' => PayoutPolicy::Harvest,
        'Industrial Distribution' => PayoutPolicy::Balanced,
        'Insurance - Diversified' => PayoutPolicy::Balanced,
        'Insurance - Life' => PayoutPolicy::Income,
        'Insurance - Reinsurance' => PayoutPolicy::Balanced,
        'Insurance Brokers' => PayoutPolicy::Balanced,
        'Integrated Freight & Logistics' => PayoutPolicy::Low,
        'Internet Content & Information' => PayoutPolicy::Low,
        'Internet Retail' => PayoutPolicy::Low,
        'Investment Banking' => PayoutPolicy::Balanced,
        'Investment Companies' => PayoutPolicy::Income,
        'Legal Services' => PayoutPolicy::Harvest,
        'Luxury Goods' => PayoutPolicy::Income,
        'Marine Ports & Services' => PayoutPolicy::Income,
        'Marine Shipping' => PayoutPolicy::Balanced,
        'Medical Care Facilities' => PayoutPolicy::Income,
        'Merchant Houses' => PayoutPolicy::Balanced,
        'Mortgage Finance' => PayoutPolicy::Balanced,
        'Nuclear Power Equipment' => PayoutPolicy::Income,
        'Oil & Gas E&P' => PayoutPolicy::Balanced,
        'Oil & Gas Refining & Marketing' => PayoutPolicy::Balanced,
        'Packaged Foods' => PayoutPolicy::Income,
        'Private Banks' => PayoutPolicy::Income,
        'Private Equity' => PayoutPolicy::Income,
        'REIT - Healthcare Facilities' => PayoutPolicy::Harvest,
        'REIT - Industrial' => PayoutPolicy::Harvest,
        'REIT - Office' => PayoutPolicy::Harvest,
        'REIT - Residential' => PayoutPolicy::Harvest,
        'Railroads' => PayoutPolicy::Harvest,
        'Residential Construction' => PayoutPolicy::Low,
        'Resorts & Casinos' => PayoutPolicy::Balanced,
        'Restaurants' => PayoutPolicy::Income,
        'Securities & Commodity Exchanges' => PayoutPolicy::Harvest,
        'Security & Protection Services' => PayoutPolicy::Balanced,
        'Semiconductors' => PayoutPolicy::Low,
        'Space Systems' => PayoutPolicy::Retain,
        'Specialty Industrial Machinery' => PayoutPolicy::Income,
        'Steel' => PayoutPolicy::Low,
        'Telecom Services' => PayoutPolicy::Harvest,
        'Tobacco' => PayoutPolicy::Harvest,
        'Tools & Accessories' => PayoutPolicy::Income,
        'Utilities - Independent Power Producers' => PayoutPolicy::Balanced,
        'Utilities - Regulated Electric' => PayoutPolicy::Harvest,
        'Utilities - Regulated Water' => PayoutPolicy::Harvest,
        'Waste Management' => PayoutPolicy::Income,
    ];

    /** The policy an industry's firms usually open on; an unlisted industry takes the balanced one. */
    public static function forIndustry(?string $industry): PayoutPolicy
    {
        return self::BY_INDUSTRY[$industry ?? ''] ?? PayoutPolicy::Balanced;
    }

    /**
     * A seed row's opening policy: the one its lore names, or its industry's.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function forRow(array $row): PayoutPolicy
    {
        return isset($row['payout']) ? PayoutPolicy::from((string) $row['payout']) : self::forIndustry($row['industry'] ?? null);
    }
}

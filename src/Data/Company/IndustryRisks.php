<?php

declare(strict_types=1);

namespace App\Data\Company;

use App\Entity\Stock;

/**
 * Industry business-risk and event-risk steps, the anchors every listed firm's opening volatility and jump process are
 * built on. Each board industry sits at the step nearest (in log) the median of its firms' implied business
 * idiosyncratic volatility and jump intensity, with judgements documented where an industry was floored or sits on a
 * boundary. Lore moves a firm at most one step (`LORE_BAND_STEPS`) via `'risk'` and `'events'` offsets on its row.
 */
final class IndustryRisks
{
    // --- Lore Band & Derived Keys ---
    /** Steps lore may move a firm from its industry's business or event risk. */
    public const LORE_BAND_STEPS = 1;

    /**
     * Risk keys derived at opening from the industry steps and leverage; a seed row must never state them.
     *
     * @var list<string>
     */
    public const DERIVED_KEYS = ['volatility', 'jump_intensity', 'jump_vol'];

    /**
     * Usual business-risk and event-risk steps by board industry.
     *
     * @var array<string, array{business: BusinessRisk, events: EventRisk}>
     */
    public const BY_INDUSTRY = [
        'Advertising Agencies' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Aerospace & Defense' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Frequent],
        'Airlines' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Apparel Manufacturing' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        'Asset Management' => ['business' => BusinessRisk::High, 'events' => EventRisk::Constant],
        // Judgement: the floored peer (EIDR) leaves FALC setting the industry at Moderate.
        'Auto Manufacturers' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Constant],
        // Judgement: on the idiosyncratic floor today; diversified deposit book.
        'Banks - Diversified' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        // Judgement: deposit banks are not a high-risk business; RIVR is the lore exception.
        'Banks - Regional' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Beverages - Wineries & Distilleries' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        'Broadcasting' => ['business' => BusinessRisk::High, 'events' => EventRisk::Occasional],
        'Brokerages' => ['business' => BusinessRisk::High, 'events' => EventRisk::Frequent],
        // Judgement: the floored peer (WREN) leaves NUTH setting the industry at Moderate.
        'Building Products & Equipment' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        // Judgement: implied 0.194 sits on the Moderate/High boundary.
        'Chemicals' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        'Communication Equipment' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        'Computer Hardware' => ['business' => BusinessRisk::VeryHigh, 'events' => EventRisk::Constant],
        // Judgement: all three on the idiosyncratic floor today; diversified books.
        'Conglomerates' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        'Consulting Services' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        'Copper' => ['business' => BusinessRisk::VeryHigh, 'events' => EventRisk::Occasional],
        'Correctional Services' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        // Judgement: TALN sets the industry; STRK's unsecured lending is the lore exception.
        'Credit Services' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Custody Banks' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        'Distressed Debt' => ['business' => BusinessRisk::High, 'events' => EventRisk::Constant],
        'Drug Manufacturers - General' => ['business' => BusinessRisk::High, 'events' => EventRisk::Frequent],
        // Judgement: on the idiosyncratic floor today; sponsored-university contracts.
        'Education & Training Services' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        // Judgement: on the idiosyncratic floor today; cyclical industrial equipment.
        'Electrical Equipment & Parts' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Rare],
        'Engineering & Construction' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        // Judgement: the floored peer (BUZT) leaves CHUF setting the industry at Moderate.
        'Farm & Heavy Construction Machinery' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        'Farm Products' => ['business' => BusinessRisk::VeryLow, 'events' => EventRisk::Occasional],
        // Judgement: on the idiosyncratic floor today; diversified clearing book.
        'Financial Clearinghouses' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        // Judgement: implied 0.186 sits on the Moderate/High boundary.
        'Financial Data & Ratings' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Gold' => ['business' => BusinessRisk::VeryHigh, 'events' => EventRisk::Occasional],
        // Judgement: implied 0.134 sits on the Low/Moderate boundary; grocers are defensive.
        'Grocery Stores' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        'Healthcare Plans' => ['business' => BusinessRisk::High, 'events' => EventRisk::Occasional],
        'Hedge Fund' => ['business' => BusinessRisk::High, 'events' => EventRisk::Constant],
        // Judgement: on the idiosyncratic floor today; defensive household staples.
        'Household & Personal Products' => ['business' => BusinessRisk::VeryLow, 'events' => EventRisk::Rare],
        // Judgement: on the idiosyncratic floor today; diversified distribution book.
        'Industrial Distribution' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        // Judgement: implied 0.186 sits on the Moderate/High boundary.
        'Insurance - Diversified' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        'Insurance - Life' => ['business' => BusinessRisk::High, 'events' => EventRisk::Occasional],
        'Insurance - Reinsurance' => ['business' => BusinessRisk::High, 'events' => EventRisk::Rare],
        'Insurance Brokers' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Rare],
        'Integrated Freight & Logistics' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        // Judgement: on the idiosyncratic floor today; platform regulation swings on its own.
        'Internet Content & Information' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Internet Retail' => ['business' => BusinessRisk::High, 'events' => EventRisk::Frequent],
        // Judgement: four firms spread around Moderate by lore (CORV/PERE below, KING/PTRL above).
        'Investment Banking' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Constant],
        'Investment Companies' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        'Legal Services' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Luxury Goods' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        'Marine Ports & Services' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        // Judgement: spot shipping (ALBT 0.29) sets the industry; GANN's contracted fleet is the exception.
        'Marine Shipping' => ['business' => BusinessRisk::VeryHigh, 'events' => EventRisk::Occasional],
        'Medical Care Facilities' => ['business' => BusinessRisk::VeryLow, 'events' => EventRisk::Frequent],
        // Judgement: on the idiosyncratic floor today; diversified trading and terminal book.
        'Merchant Houses' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        'Mortgage Finance' => ['business' => BusinessRisk::High, 'events' => EventRisk::Constant],
        'Nuclear Power Equipment' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        'Oil & Gas E&P' => ['business' => BusinessRisk::VeryHigh, 'events' => EventRisk::Frequent],
        // Judgement: on the idiosyncratic floor today; refining crack spreads swing on their own.
        'Oil & Gas Refining & Marketing' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Packaged Foods' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        // Judgement: deposit-taking private bank is not a high-risk business.
        'Private Banks' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        'Private Equity' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        // Judgement: on the idiosyncratic floor today; leases set the rent.
        'REIT - Healthcare Facilities' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        // Judgement: on the idiosyncratic floor today; leases set the rent.
        'REIT - Industrial' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        // Judgement: on the idiosyncratic floor today; century public-body leases.
        'REIT - Office' => ['business' => BusinessRisk::VeryLow, 'events' => EventRisk::Rare],
        'REIT - Residential' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Frequent],
        // Judgement: REDW off the floor sets the industry; commuter rail (KSTL) is the lore exception.
        'Railroads' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        'Residential Construction' => ['business' => BusinessRisk::High, 'events' => EventRisk::Occasional],
        'Resorts & Casinos' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Restaurants' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        // Judgement: a fee-driven exchange venue is not a high-risk business.
        'Securities & Commodity Exchanges' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        // Judgement: implied 0.187 sits on the Moderate/High boundary; four firms spread around it.
        'Security & Protection Services' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Frequent],
        'Semiconductors' => ['business' => BusinessRisk::High, 'events' => EventRisk::Constant],
        'Space Systems' => ['business' => BusinessRisk::VeryHigh, 'events' => EventRisk::Frequent],
        'Specialty Industrial Machinery' => ['business' => BusinessRisk::Moderate, 'events' => EventRisk::Occasional],
        'Steel' => ['business' => BusinessRisk::High, 'events' => EventRisk::Frequent],
        'Telecom Services' => ['business' => BusinessRisk::VeryLow, 'events' => EventRisk::Occasional],
        'Tobacco' => ['business' => BusinessRisk::VeryLow, 'events' => EventRisk::Frequent],
        'Tools & Accessories' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Occasional],
        'Utilities - Independent Power Producers' => ['business' => BusinessRisk::VeryHigh, 'events' => EventRisk::Occasional],
        'Utilities - Regulated Electric' => ['business' => BusinessRisk::Low, 'events' => EventRisk::Rare],
        'Utilities - Regulated Water' => ['business' => BusinessRisk::VeryLow, 'events' => EventRisk::Rare],
        // Judgement: on the idiosyncratic floor today; contracted municipal waste service.
        'Waste Management' => ['business' => BusinessRisk::VeryLow, 'events' => EventRisk::Rare],
    ];

    /**
     * The usual business-risk and event-risk steps for an industry; an unlisted industry takes Moderate / Occasional.
     *
     * @return array{business: BusinessRisk, events: EventRisk}
     */
    public static function forIndustry(?string $industry): array
    {
        return self::BY_INDUSTRY[$industry ?? ''] ?? [
            'business' => BusinessRisk::Moderate,
            'events' => EventRisk::Occasional,
        ];
    }

    /**
     * A seed row's business-risk step: its industry's shifted by its lore offset, held within the band.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function businessForRow(array $row): BusinessRisk
    {
        $offset = self::clampOffset((int) ($row['risk'] ?? 0));

        return self::forIndustry($row['industry'] ?? null)['business']->shifted($offset);
    }

    /**
     * A seed row's event-risk step: its industry's shifted by its lore offset, held within the band.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     */
    public static function eventsForRow(array $row): EventRisk
    {
        $offset = self::clampOffset((int) ($row['events'] ?? 0));

        return self::forIndustry($row['industry'] ?? null)['events']->shifted($offset);
    }

    /**
     * @var array<string, array<string, mixed>>|null Seed rows indexed by ticker for healing null businessVolatility.
     */
    private static ?array $seedRowsByTicker = null;

    /**
     * Both risk steps for a seed row.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     * @return array{business: BusinessRisk, events: EventRisk}
     */
    public static function forRow(array $row): array
    {
        return [
            'business' => self::businessForRow($row),
            'events' => self::eventsForRow($row),
        ];
    }

    /**
     * The operating business-risk step volatility a stock carries: its stored column when set, else healed from its
     * seed row in InitialMarket::STOCKS, else its industry default, so a live database works before a reseed.
     */
    public static function resolveBusinessVolatility(Stock $stock): float
    {
        $stored = $stock->getBusinessVolatility();
        if ($stored !== null) {
            return $stored;
        }

        if (self::$seedRowsByTicker === null) {
            $byTicker = [];
            foreach (InitialMarket::STOCKS as $row) {
                $byTicker[(string) $row['ticker']] = $row;
            }
            self::$seedRowsByTicker = $byTicker;
        }

        $row = self::$seedRowsByTicker[$stock->getTicker()] ?? null;

        return ($row !== null ? self::businessForRow($row) : self::forIndustry($stock->getIndustry())['business'])->vol();
    }

    /**
     * Refuses a seed row that still states a derived risk key (`volatility`, `jump_intensity`, `jump_vol`) or a lore
     * offset outside the band (`±LORE_BAND_STEPS`) or non-integer.
     *
     * @param array<string, mixed> $row
     */
    public static function assertRow(array $row): void
    {
        $stated = array_intersect(self::DERIVED_KEYS, array_keys($row));
        $badOffsets = [];

        foreach (['risk', 'events'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (!is_int($value) || abs($value) > self::LORE_BAND_STEPS) {
                $badOffsets[] = $key;
            }
        }

        if ($stated !== [] || $badOffsets !== []) {
            throw new \DomainException(sprintf(
                '%s states %s derived risk key(s) and %s invalid risk offset(s).',
                (string) ($row['ticker'] ?? '?'),
                implode(', ', $stated) ?: 'no',
                implode(', ', $badOffsets) ?: 'no',
            ));
        }
    }

    private static function clampOffset(int $offset): int
    {
        return max(-self::LORE_BAND_STEPS, min(self::LORE_BAND_STEPS, $offset));
    }
}

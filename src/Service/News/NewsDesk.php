<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Service\Market\Bond\CreditRatingAgency;
use App\Service\Math\FinancialConstants;

/**
 * Files each story under a newswire section and decides which stories are headlines.
 *
 * A district story, a failure and a rating move across investment grade are headlines by kind. A deal is a headline
 * when it is significant to the company, a price shock beyond a 10% move, and an industry outlook revised 7.5%. An
 * earnings or analyst story is a headline when its move is significant against the stock's own volatility: the
 * standardised abnormal return of an event study (Brown & Warner 1985; MacKinlay 1997), over a one-day window. The page
 * load, the live wire and the headline strip all ask this class, so a story never reads as a headline in one place and
 * not another.
 */
final class NewsDesk
{
    // --- Sections ---

    /** Every newswire section, keyed by the slug in the address, with its label. */
    public const SECTIONS = [
        'all' => 'All news',
        'district' => 'Economy and government',
        'companies' => 'Companies',
        'earnings' => 'Earnings',
        'deals' => 'Deals',
        'credit' => 'Credit',
        'funds' => 'Index funds',
    ];

    /** Who a story is about: the District as a whole, a listed company, or an index fund. */
    public const SCOPE_DISTRICT = 'district';
    public const SCOPE_COMPANY = 'company';
    public const SCOPE_FUND = 'fund';

    // --- Headlines ---

    /**
     * A price shock is a headline beyond a 10% move either way: the single-stock circuit-breaker size (SEC Rule 201;
     * LULD Tier 2 band).
     */
    private const HEADLINE_SHOCK_MOVE = 0.10;

    /** Two-sided 1% critical value of a standardised abnormal return (|SAR| ≥ 2.576; MacKinlay 1997). */
    public const HEADLINE_ABNORMAL_RETURN_Z = 2.576;

    /**
     * A deal is a headline at 10% of the company's market value: the SEC's significance test (Regulation S-X Rule
     * 1-02(w)).
     */
    private const HEADLINE_DEAL_SHARE = 0.10;

    /**
     * An industry outlook revision is a headline at 7.5% either way: a frontier industry's in about one quarter in ten,
     * a settled one's almost never; about four a year across the board.
     */
    public const HEADLINE_OUTLOOK_REVISION = 0.075;

    /** The section a story is filed under on its own; "all" and "companies" also take it. */
    public static function sectionOf(string $scope, NewsCard $card): string
    {
        return match ($scope) {
            self::SCOPE_COMPANY => $card->companySection() ?? 'companies',
            self::SCOPE_DISTRICT => 'district',
            default => 'funds',
        };
    }

    /** Whether a story belongs on a section's page; assets/js/pages/news.js mirrors it. */
    public static function inSection(string $section, string $scope, NewsCard $card): bool
    {
        return match ($section) {
            'all' => true,
            'companies' => $scope === self::SCOPE_COMPANY,
            default => self::sectionOf($scope, $card) === $section,
        };
    }

    /** The section a requested slug names, or "all" for one the wire does not have. */
    public static function section(?string $slug): string
    {
        return $slug !== null && isset(self::SECTIONS[$slug]) ? $slug : 'all';
    }

    /**
     * Whether a presented card is a headline.
     *
     * @param float|null $annualVolatility the stock's annualised volatility; null for a story with no stock
     * @param float|null $dealShareOfValue a deal's price over the company's market value before it
     */
    public static function isHeadline(EventCard $card, ?float $annualVolatility = null, ?float $dealShareOfValue = null): bool
    {
        $move = $card->changePercent !== null ? $card->changePercent / 100.0 : null;

        return match ($card->card->headlineTest()) {
            HeadlineTest::ByKind => true,
            HeadlineTest::DealSize => $dealShareOfValue !== null && $dealShareOfValue >= self::HEADLINE_DEAL_SHARE,
            HeadlineTest::GradeCrossing => self::crossesInvestmentGrade($card->facts),
            HeadlineTest::ShockMove => $move !== null && abs($move) > self::HEADLINE_SHOCK_MOVE,
            HeadlineTest::RevisionSize => abs((float) ($card->facts['revision'] ?? 0.0)) >= self::HEADLINE_OUTLOOK_REVISION,
            HeadlineTest::AbnormalReturn => $move !== null && $annualVolatility !== null
                && self::abnormalReturnZ($move, $annualVolatility) >= self::HEADLINE_ABNORMAL_RETURN_Z,
            HeadlineTest::Never => false,
        };
    }

    /**
     * The cards a story can make a headline on; the wire's headline scan reads only these.
     *
     * @return list<NewsCard>
     */
    public static function headlineCards(): array
    {
        return array_values(array_filter(NewsCard::cases(), static fn(NewsCard $card): bool => $card->headlineTest() !== HeadlineTest::Never));
    }

    /** A one-day move in units of the stock's daily volatility; 0 when the volatility is unknown. */
    private static function abnormalReturnZ(float $move, float $annualVolatility): float
    {
        $dailyVolatility = $annualVolatility / sqrt(FinancialConstants::TRADING_DAYS_PER_YEAR);

        return $dailyVolatility > 0.0 ? abs($move) / $dailyVolatility : 0.0;
    }

    /**
     * A fallen angel or a rising star: the rating moved between investment grade (BBB and better) and high yield.
     *
     * @param array<string, mixed> $facts the rating change's facts: its old and new rating
     */
    private static function crossesInvestmentGrade(array $facts): bool
    {
        $from = $facts['from'] ?? null;
        $to = $facts['to'] ?? null;
        if (!is_string($from) || !is_string($to) || !isset(CreditRatingAgency::RATING_RANKS[$from], CreditRatingAgency::RATING_RANKS[$to])) {
            return false;
        }

        return CreditRatingAgency::isInvestmentGrade($from) !== CreditRatingAgency::isInvestmentGrade($to);
    }
}

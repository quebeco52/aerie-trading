<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Service\Market\Bond\CreditRatingAgency;
use App\Service\Math\FinancialConstants;

/**
 * Files each story under a newswire section and decides which stories are headlines.
 *
 * A district story, a failure and a rating move across investment grade are headlines by kind. A deal is a headline
 * when it is significant to the company, and a price shock beyond a 10% move. An earnings or analyst story is a headline when its move is significant against the
 * stock's own volatility: the standardised abnormal return of an event study (Brown & Warner 1985; MacKinlay 1997),
 * over a one-day window.
 * The page load, the live wire and the headline strip all ask this class, so a story never reads as a headline
 * in one place and not another.
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

    /** The company sections narrower than "companies", with the cards each one carries. */
    public const COMPANY_SECTION_CATEGORIES = [
        'earnings' => ['earnings'],
        'deals' => ['mna'],
        'credit' => ['debt', 'bankruptcy', 'reorganization'],
    ];

    /** Who a story is about: the District as a whole, a listed company, or an index fund. */
    public const SCOPE_DISTRICT = 'district';
    public const SCOPE_COMPANY = 'company';
    public const SCOPE_FUND = 'fund';

    // --- Headlines ---

    /** Cards that are headlines by kind, whatever the price did. */
    private const HEADLINE_CATEGORIES = ['economy', 'government', 'bankruptcy', 'reorganization'];

    /** Cards that are headlines only when the price move they report is significant. */
    private const MOVE_TESTED_CATEGORIES = ['earnings', 'analyst'];

    /** A price shock is a headline beyond a 10% move either way: the single-stock circuit-breaker size (SEC Rule 201; LULD Tier 2 band). */
    public const HEADLINE_SHOCK_MOVE = 0.10;

    /** Two-sided 1% critical value of a standardised abnormal return (|SAR| ≥ 2.576; MacKinlay 1997). */
    public const HEADLINE_ABNORMAL_RETURN_Z = 2.576;

    /** A deal is a headline at 10% of the company's market value: the SEC's significance test (Regulation S-X Rule 1-02(w)). */
    public const HEADLINE_DEAL_SHARE = 0.10;

    /** A rating change as the debt engine words it: "from BBB to BB". */
    private const RATING_CHANGE_PATTERN = '/\bfrom (AAA|AA|A|BBB|BB|B|CCC|D) to (AAA|AA|A|BBB|BB|B|CCC|D)\b/';

    /** The section a story is filed under on its own; "all" and "companies" also take it. */
    public static function sectionOf(string $scope, string $category): string
    {
        if ($scope !== self::SCOPE_COMPANY) {
            return $scope === self::SCOPE_DISTRICT ? 'district' : 'funds';
        }

        foreach (self::COMPANY_SECTION_CATEGORIES as $section => $categories) {
            if (in_array($category, $categories, true)) {
                return $section;
            }
        }

        return 'companies';
    }

    /** Whether a story belongs on a section's page. */
    public static function inSection(string $section, string $scope, string $category): bool
    {
        return match ($section) {
            'all' => true,
            'companies' => $scope === self::SCOPE_COMPANY,
            default => self::sectionOf($scope, $category) === $section,
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
     * @param array<string, mixed> $card              EventPresenter's card
     * @param float|null           $annualVolatility  the stock's annualised volatility; null for a story with no stock
     * @param float|null           $dealShareOfValue  a deal's price over the company's market value before it
     */
    public static function isHeadline(array $card, ?float $annualVolatility = null, ?float $dealShareOfValue = null): bool
    {
        $category = (string) ($card['category'] ?? 'general');

        if (in_array($category, self::HEADLINE_CATEGORIES, true)) {
            return true;
        }

        if ($category === 'mna') {
            return $dealShareOfValue !== null && $dealShareOfValue >= self::HEADLINE_DEAL_SHARE;
        }

        if ($category === 'debt') {
            return self::crossesInvestmentGrade((string) ($card['rawDescription'] ?? ''));
        }

        $changePercent = isset($card['changePercent']) ? (float) $card['changePercent'] : null;

        if ($category === 'shock') {
            return $changePercent !== null && abs($changePercent / 100.0) > self::HEADLINE_SHOCK_MOVE;
        }

        if (!in_array($category, self::MOVE_TESTED_CATEGORIES, true)) {
            return false;
        }

        return $changePercent !== null && $annualVolatility !== null
            && self::abnormalReturnZ($changePercent / 100.0, $annualVolatility) >= self::HEADLINE_ABNORMAL_RETURN_Z;
    }

    /** A one-day move in units of the stock's daily volatility; 0 when the volatility is unknown. */
    public static function abnormalReturnZ(float $move, float $annualVolatility): float
    {
        $dailyVolatility = $annualVolatility / sqrt(FinancialConstants::TRADING_DAYS_PER_YEAR);

        return $dailyVolatility > 0.0 ? abs($move) / $dailyVolatility : 0.0;
    }

    /** A fallen angel or a rising star: the rating moved between investment grade (BBB and better) and high yield. */
    private static function crossesInvestmentGrade(string $description): bool
    {
        if (preg_match(self::RATING_CHANGE_PATTERN, $description, $match) !== 1) {
            return false;
        }

        $floor = CreditRatingAgency::RATING_RANKS['BBB'];

        return (CreditRatingAgency::RATING_RANKS[$match[1]] >= $floor) !== (CreditRatingAgency::RATING_RANKS[$match[2]] >= $floor);
    }
}

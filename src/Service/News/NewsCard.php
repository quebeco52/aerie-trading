<?php

declare(strict_types=1);

namespace App\Service\News;

/**
 * The card a story is presented as, and everything that follows from the kind of story it is: the published types
 * that render as it, the company section it is filed under and how the desk judges it a headline.
 *
 * The engines publish free-form type strings: a deal is typed by the business model that made it
 * (StrategyInterface::getAcquisitionType), a rating change by the debt engine. The page, the live feed and the district
 * all route through this one table, so a type an engine starts publishing is either listed here or visibly general,
 * never styled one way on load and another way live. The values are the `category` the card carries.
 */
enum NewsCard: string
{
    case Earnings = 'earnings';
    case Shock = 'shock';
    case Split = 'split';
    case Rating = 'debt';
    case Deal = 'mna';
    case Analyst = 'analyst';
    case Bankruptcy = 'bankruptcy';
    case Reorganization = 'reorganization';
    case Street = 'district';
    case Index = 'index';
    case Distribution = 'income';
    case Succession = 'governance';
    case Outlook = 'outlook';
    case Economy = 'economy';
    case Government = 'government';
    case General = 'general';

    // --- Routing ---

    /** Every type the engines publish, keyed to the card it renders as; anything else is general news. */
    private const TYPES = [
        'EARNINGS' => self::Earnings,
        'SHOCK' => self::Shock,
        'SPLIT' => self::Split,
        'REVERSE_SPLIT' => self::Split,
        'CREDIT_UPGRADE' => self::Rating,
        'CREDIT_DOWNGRADE' => self::Rating,
        'STRATEGIC ACQUISITION' => self::Deal,
        'CONGLOMERATE EXPANSION' => self::Deal,
        'LEVERAGED BUYOUT' => self::Deal,
        'HOSTILE TAKEOVER' => self::Deal,
        'STOCK-FOR-STOCK MERGER' => self::Deal,
        'DIVESTITURE' => self::Deal,
        'MARKET ENTRY' => self::Deal,
        'ANALYST' => self::Analyst,
        'GUIDANCE' => self::Analyst,
        'BANKRUPTCY' => self::Bankruptcy,
        'REORGANIZATION' => self::Reorganization,
        'DISTRICT' => self::Street,
        'INDEX' => self::Index,
        'DIVIDEND' => self::Distribution,
        'MANAGEMENT CHANGE' => self::Succession,
        'OUTLOOK' => self::Outlook,
        'ECONOMY' => self::Economy,
        'GOVERNMENT' => self::Government,
    ];

    /** The card a published type renders as. */
    public static function forType(string $type): self
    {
        return self::TYPES[strtoupper(trim($type))] ?? self::General;
    }

    /**
     * Every published type that renders as one of the given cards, for selecting stories by card in a query.
     *
     * @param  list<self>   $cards
     * @return list<string>
     */
    public static function typesOf(array $cards): array
    {
        return array_keys(array_filter(self::TYPES, static fn(self $card): bool => in_array($card, $cards, true)));
    }

    /**
     * The cards a company section of the newswire carries.
     *
     * @return list<self>
     */
    public static function inCompanySection(string $section): array
    {
        return array_values(array_filter(self::cases(), static fn(self $card): bool => $card->companySection() === $section));
    }

    // --- Filing ---

    /** The section narrower than "companies" a company story on this card is filed under; null for none. */
    public function companySection(): ?string
    {
        return match ($this) {
            self::Earnings => 'earnings',
            self::Deal => 'deals',
            self::Rating, self::Bankruptcy, self::Reorganization => 'credit',
            default => null,
        };
    }

    /** How the news desk judges whether a story on this card is a headline. */
    public function headlineTest(): HeadlineTest
    {
        return match ($this) {
            self::Economy, self::Government, self::Bankruptcy, self::Reorganization => HeadlineTest::ByKind,
            self::Deal => HeadlineTest::DealSize,
            self::Rating => HeadlineTest::GradeCrossing,
            self::Shock => HeadlineTest::ShockMove,
            self::Outlook => HeadlineTest::RevisionSize,
            self::Earnings, self::Analyst => HeadlineTest::AbnormalReturn,
            self::Split, self::Street, self::Index, self::Distribution, self::Succession, self::General => HeadlineTest::Never,
        };
    }
}

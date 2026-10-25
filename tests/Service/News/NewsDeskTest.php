<?php

declare(strict_types=1);

namespace App\Tests\Service\News;

use App\Service\Math\FinancialConstants;
use App\Service\News\EventCard;
use App\Service\News\EventPresenter;
use App\Service\News\NewsCard;
use App\Service\News\NewsDesk;
use App\Service\News\PresentableEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which stories are headlines, and where each is filed on the newswire.
 */
class NewsDeskTest extends TestCase
{
    /** Annualised volatility of the test stock: a daily sigma of 0.32 / sqrt(252) ≈ 2.02%. */
    private const VOLATILITY = 0.32;

    /** An earnings move is a headline when its standardised abnormal return clears 2.576, and not below it. */
    public function testAnEarningsMoveIsAHeadlineOnlyWhenSignificantAgainstTheStocksOwnVolatility(): void
    {
        $dailySigma = self::VOLATILITY / sqrt(FinancialConstants::TRADING_DAYS_PER_YEAR);
        $justOver = 100.0 * $dailySigma * (NewsDesk::HEADLINE_ABNORMAL_RETURN_Z + 0.05);
        $justUnder = 100.0 * $dailySigma * (NewsDesk::HEADLINE_ABNORMAL_RETURN_Z - 0.05);

        $this->assertTrue(NewsDesk::isHeadline($this->card('EARNINGS', $justOver), self::VOLATILITY));
        $this->assertTrue(NewsDesk::isHeadline($this->card('EARNINGS', -$justOver), self::VOLATILITY), 'A fall is as much news as a rise.');
        $this->assertFalse(NewsDesk::isHeadline($this->card('EARNINGS', $justUnder), self::VOLATILITY));
    }

    /** The same move is news for a quiet stock and routine for a volatile one. */
    public function testTheBarScalesWithTheStock(): void
    {
        $this->assertTrue(NewsDesk::isHeadline($this->card('EARNINGS', 3.0), 0.15));
        $this->assertFalse(NewsDesk::isHeadline($this->card('EARNINGS', 3.0), 0.60));
    }

    public function testAMoveTestedStoryWithoutANumberOrAVolatilityIsNotAHeadline(): void
    {
        $this->assertFalse(NewsDesk::isHeadline($this->card('EARNINGS', null), self::VOLATILITY));
        $this->assertFalse(NewsDesk::isHeadline($this->card('ANALYST', 12.0), null));
    }

    /** A price shock is a headline beyond 10% either way, whatever the stock's volatility. */
    public function testAPriceShockIsAHeadlineOnlyBeyondTenPercent(): void
    {
        $this->assertTrue(NewsDesk::isHeadline($this->card('SHOCK', 10.5), 0.15));
        $this->assertTrue(NewsDesk::isHeadline($this->card('SHOCK', -12.0), null));
        $this->assertFalse(NewsDesk::isHeadline($this->card('SHOCK', 10.0), 0.15), 'Exactly 10% is not more than 10%.');
        $this->assertFalse(NewsDesk::isHeadline($this->card('SHOCK', -8.0), 0.15), 'Significant for a quiet stock, but under the bar.');
        $this->assertFalse(NewsDesk::isHeadline($this->card('SHOCK', null), 0.15));
    }

    /** An industry outlook revised 7.5% or more either way is a headline; a smaller revision is only a story. */
    public function testAnOutlookRevisionIsAHeadlineFromSevenAndAHalfPercent(): void
    {
        $outlook = static fn(float $revision): EventCard => (new EventPresenter())->present(new PresentableEvent(type: 'OUTLOOK', description: 'x', facts: ['revision' => $revision]));

        $this->assertTrue(NewsDesk::isHeadline($outlook(NewsDesk::HEADLINE_OUTLOOK_REVISION), self::VOLATILITY));
        $this->assertTrue(NewsDesk::isHeadline($outlook(-0.09), null), 'A cut is as much news as a raise.');
        $this->assertFalse(NewsDesk::isHeadline($outlook(0.074), self::VOLATILITY));
        $this->assertSame('companies', NewsDesk::sectionOf(NewsDesk::SCOPE_COMPANY, NewsCard::Outlook));
    }

    /** @return iterable<string, array{string}> */
    public static function headlinesByKind(): iterable
    {
        foreach (['ECONOMY', 'GOVERNMENT', 'BANKRUPTCY', 'REORGANIZATION'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('headlinesByKind')]
    public function testDistrictStoriesAndFailuresAreHeadlinesWhateverThePriceDid(string $type): void
    {
        $this->assertTrue(NewsDesk::isHeadline($this->card($type, 0.0), self::VOLATILITY));
    }

    /** A deal, bought or sold, is a headline when it is at least 10% of the company's market value. */
    public function testADealIsAHeadlineWhenSignificantToTheCompany(): void
    {
        foreach (['LEVERAGED BUYOUT', 'STRATEGIC ACQUISITION', 'DIVESTITURE'] as $type) {
            $this->assertTrue(NewsDesk::isHeadline($this->card($type, 0.4), self::VOLATILITY, 0.10), $type);
            $this->assertFalse(NewsDesk::isHeadline($this->card($type, 0.4), self::VOLATILITY, 0.099), $type);
            $this->assertFalse(NewsDesk::isHeadline($this->card($type, 0.4), self::VOLATILITY), "{$type} of unknown size");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routineKinds(): iterable
    {
        foreach (['SPLIT', 'INDEX', 'DIVIDEND', 'MANAGEMENT CHANGE', 'DISTRICT', 'SOMETHING NEW'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('routineKinds')]
    public function testRoutineNoticesAreNeverHeadlines(string $type): void
    {
        $this->assertFalse(NewsDesk::isHeadline($this->card($type, 25.0), self::VOLATILITY));
    }

    /** A fallen angel or a rising star is a headline; a move within either grade is not. */
    public function testARatingChangeIsAHeadlineWhenItCrossesInvestmentGrade(): void
    {
        $this->assertTrue(NewsDesk::isHeadline($this->rating('BBB', 'BB'), self::VOLATILITY));
        $this->assertTrue(NewsDesk::isHeadline($this->rating('BB', 'BBB'), self::VOLATILITY));
        $this->assertFalse(NewsDesk::isHeadline($this->rating('A', 'BBB'), self::VOLATILITY));
        $this->assertFalse(NewsDesk::isHeadline($this->rating('B', 'CCC'), self::VOLATILITY));
    }

    public function testEachStoryIsFiledUnderOneSectionAndAppearsUnderAllAndItsScope(): void
    {
        $this->assertSame('district', NewsDesk::sectionOf(NewsDesk::SCOPE_DISTRICT, NewsCard::Government));
        $this->assertSame('funds', NewsDesk::sectionOf(NewsDesk::SCOPE_FUND, NewsCard::Index));
        $this->assertSame('earnings', NewsDesk::sectionOf(NewsDesk::SCOPE_COMPANY, NewsCard::Earnings));
        $this->assertSame('deals', NewsDesk::sectionOf(NewsDesk::SCOPE_COMPANY, NewsCard::Deal));
        $this->assertSame('credit', NewsDesk::sectionOf(NewsDesk::SCOPE_COMPANY, NewsCard::Bankruptcy));
        $this->assertSame('companies', NewsDesk::sectionOf(NewsDesk::SCOPE_COMPANY, NewsCard::Succession));

        $this->assertTrue(NewsDesk::inSection('companies', NewsDesk::SCOPE_COMPANY, NewsCard::Earnings));
        $this->assertFalse(NewsDesk::inSection('companies', NewsDesk::SCOPE_FUND, NewsCard::Index));
        $this->assertTrue(NewsDesk::inSection('all', NewsDesk::SCOPE_DISTRICT, NewsCard::Economy));
        $this->assertSame('all', NewsDesk::section('no-such-section'));
    }

    /** The headline scan reads every card a story can be a headline on, and only those. */
    public function testTheHeadlineScanReadsEveryCardThatCanMakeAHeadline(): void
    {
        $cards = NewsDesk::headlineCards();

        foreach (NewsCard::cases() as $card) {
            $story = (new EventPresenter())->present(new PresentableEvent(type: NewsCard::typesOf([$card])[0] ?? 'SOMETHING NEW', description: 'x', facts: ['from' => 'BBB', 'to' => 'BB', 'revision' => 0.5], changePercent: 50.0));
            $this->assertSame(in_array($card, $cards, true), NewsDesk::isHeadline($story, 0.01, 1.0), $card->name);
        }
    }

    private function card(string $type, ?float $changePercent): EventCard
    {
        return (new EventPresenter())->present(new PresentableEvent(type: $type, description: 'x', changePercent: $changePercent));
    }

    private function rating(string $from, string $to): EventCard
    {
        return (new EventPresenter())->present(new PresentableEvent(
            type: 'CREDIT_DOWNGRADE',
            description: "Credit rating downgraded from {$from} to {$to} due to deteriorating credit profile.",
            facts: ['from' => $from, 'to' => $to],
        ));
    }
}

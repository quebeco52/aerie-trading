<?php

declare(strict_types=1);

namespace App\Tests\Service\News;

use App\Data\Company\Sectors;
use App\Service\News\EventPresenter;
use App\Service\News\NewsCard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every type an engine publishes has a card of its own. The deal types used to fall through to the grey
 * general card because the engines type a deal by the business model that made it and the presenter only
 * knew ACQUISITION/MERGER; the debt engine publishes CREDIT_* where the presenter expected RATING_*.
 */
class NewsCardTest extends TestCase
{
    #[DataProvider('publishedTypes')]
    public function testEveryPublishedTypeHasACardOfItsOwn(string $type): void
    {
        $this->assertNotSame(NewsCard::General, NewsCard::forType($type), "{$type} would render as general news");
        $this->assertSame(NewsCard::forType($type)->value, (new EventPresenter())->present(['type' => $type, 'description' => 'x'])['category']);
    }

    /** The table routes only what the engines publish, so a type no engine still writes does not linger in it. */
    public function testEveryRoutedTypeIsPublished(): void
    {
        $published = [
            ...array_keys(iterator_to_array(self::publishedTypes())),
            ...array_map(static fn(array $deal): string => $deal[1], iterator_to_array(self::dealTypes())),
        ];

        $this->assertSame([], array_values(array_diff(NewsCard::typesOf(NewsCard::cases()), $published)));
    }

    #[DataProvider('dealTypes')]
    public function testEveryBusinessModelsDealIsPresentedAsADeal(string $model, string $type): void
    {
        $this->assertSame(NewsCard::Deal, NewsCard::forType($type), "{$model} publishes its deals as {$type}");
    }

    /** The wire selects a company section by type, so every type of each section's cards must be selectable. */
    public function testEachCompanySectionSelectsEveryTypeItsCardsAreRoutedFrom(): void
    {
        foreach (['earnings', 'deals', 'credit'] as $section) {
            $cards = NewsCard::inCompanySection($section);
            $this->assertNotSame([], $cards, $section);
            foreach (NewsCard::typesOf($cards) as $type) {
                $this->assertSame($section, NewsCard::forType($type)->companySection(), "{$type} selected for {$section}");
            }
        }
        $this->assertContains('STRATEGIC ACQUISITION', NewsCard::typesOf(NewsCard::inCompanySection('deals')));
        $this->assertContains('CREDIT_DOWNGRADE', NewsCard::typesOf(NewsCard::inCompanySection('credit')));
    }

    public function testARatingChangeReadsInItsDirectionWithoutTheLogPrefix(): void
    {
        $presenter = new EventPresenter();

        $down = $presenter->present(['type' => 'CREDIT_DOWNGRADE', 'description' => '[CREDIT DOWNGRADE] LAKE: Credit rating downgraded from A to BBB due to deteriorating credit profile.']);
        $this->assertSame('RATING DOWNGRADE', $down['badge']);
        $this->assertSame('Credit rating downgraded from A to BBB due to deteriorating credit profile.', $down['headline']);

        $up = $presenter->present(['type' => 'CREDIT_UPGRADE', 'description' => '[CREDIT UPGRADE] LAKE: Credit rating upgraded from BBB to A following balance sheet strengthening.']);
        $this->assertSame('RATING UPGRADE', $up['badge']);
        $this->assertSame('up', $up['tone']);
    }

    public function testAnalystRevisionsReadInTheirDirection(): void
    {
        $presenter = new EventPresenter();

        $this->assertSame('TARGET RAISED', $presenter->present(['type' => 'ANALYST', 'description' => 'Sell-side raised its price target on WEAV to $120.00 from $100.00.'])['badge']);
        $cut = $presenter->present(['type' => 'ANALYST', 'description' => 'Sell-side cut its price target on WEAV to $80.00 from $100.00.']);
        $this->assertSame('TARGET CUT', $cut['badge']);
        $this->assertSame('down', $cut['tone']);
        $this->assertSame('GUIDANCE CUT', $presenter->present(['type' => 'GUIDANCE', 'description' => 'Guidance cut: management expects to miss consensus by roughly 8%.'])['badge']);
    }

    /**
     * Every literal type passed to MarketEventPublisher::publish in src, the literal deal types the M&A
     * engine configures, the two the debt engine picks between at run time, and the two district desks.
     *
     * @return iterable<string, array{string}>
     */
    public static function publishedTypes(): iterable
    {
        $types = ['CREDIT_UPGRADE' => true, 'CREDIT_DOWNGRADE' => true, 'ECONOMY' => true, 'GOVERNMENT' => true];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src'));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/->publish\\(\\s*[^,]+,\\s*'([^']+)'/", $source, $published);
            preg_match_all("/'type' => '([A-Z][A-Z -]+)'/", str_contains($file->getFilename(), 'MergerAndAcquisition') ? $source : '', $configured);
            foreach ([...$published[1], ...$configured[1]] as $type) {
                $types[$type] = true;
            }
        }

        foreach (array_keys($types) as $type) {
            yield $type => [$type];
        }
    }

    /**
     * @return iterable<string, array{string, string}> The deal type each business model gives each default the M&A
     *                                                 engine offers it.
     */
    public static function dealTypes(): iterable
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Service/Corporate/MergerAndAcquisitionEngine.php');
        preg_match_all("/getAcquisitionType\\('([^']+)'\\)/", $source, $defaults);

        $seen = [];
        foreach (Sectors::INDUSTRY_METRICS as $metrics) {
            $model = $metrics['business_model'] ?? 'none';
            foreach (array_unique($defaults[1]) as $default) {
                $type = Sectors::getBusinessModelStrategy($model)->getAcquisitionType($default);
                if (!isset($seen["{$model}:{$type}"])) {
                    $seen["{$model}:{$type}"] = true;
                    yield "{$model} {$default}" => [$model, $type];
                }
            }
        }
    }
}

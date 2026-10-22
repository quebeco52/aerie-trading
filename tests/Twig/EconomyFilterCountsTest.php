<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/** The economy page's topic pills count the charts they filter, so adding a chart means updating its pill. */
class EconomyFilterCountsTest extends TestCase
{
    public function testEachPillCountsTheChartsInItsTopic(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../templates/economy/index.html.twig');
        // Each card is a chart() call or an embedded card, both naming their topic.
        preg_match_all("/_self\\.chart\\('\\w+', '([a-z]+)'|\\bcategory: '([a-z]+)'/", $source, $cards);
        $charts = array_count_values(array_filter([...$cards[1], ...$cards[2]]));
        $cardCount = substr_count($source, '_self.chart(') + substr_count($source, "embed 'partials/_chart_card.html.twig'");
        $this->assertSame($cardCount, array_sum($charts), 'every chart card names its topic');
        preg_match_all('/data-category="([a-z]+)">[^<]*<span[^>]*>\((\d+)\)<\/span>/', $source, $pills, PREG_SET_ORDER);

        $this->assertNotEmpty($pills);
        foreach ($pills as [, $category, $count]) {
            $expected = $category === 'all' ? array_sum($charts) : ($charts[$category] ?? 0);
            $this->assertSame($expected, (int) $count, "The '{$category}' pill says {$count}.");
        }
    }
}

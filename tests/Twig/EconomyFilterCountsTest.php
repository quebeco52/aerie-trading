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
        preg_match_all('/data-macro-category="([a-z]+)"/', $source, $cards);
        $charts = array_count_values($cards[1]);
        preg_match_all('/data-category="([a-z]+)">[^<]*<span[^>]*>\((\d+)\)<\/span>/', $source, $pills, PREG_SET_ORDER);

        $this->assertNotEmpty($pills);
        foreach ($pills as [, $category, $count]) {
            $expected = $category === 'all' ? array_sum($charts) : ($charts[$category] ?? 0);
            $this->assertSame($expected, (int) $count, "The '{$category}' pill says {$count}.");
        }
    }
}

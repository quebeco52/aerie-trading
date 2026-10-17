<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Controller\StockController;
use PHPUnit\Framework\TestCase;

/**
 * The fundamentals endpoint returns only the newest reports, so it must return at least as many as the stock page's
 * charts read back: a chart window widened past the cap would draw its oldest years from nothing.
 */
final class FundamentalsWindowTest extends TestCase
{
    private static function script(string $path): string
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/assets/js/stock/' . $path);
        self::assertIsString($source);

        return $source;
    }

    /** @return int The report cap, read off the controller. */
    private static function cap(): int
    {
        $cap = (new \ReflectionClassConstant(StockController::class, 'FUNDAMENTALS_REPORTS'))->getValue();
        self::assertIsInt($cap);

        return $cap;
    }

    public function testTheCapCoversEveryChartWindow(): void
    {
        $this->assertSame(1, preg_match('/const yearsToFetch = (\d+);/', self::script('fundamental-charts.js'), $annual), 'The annual view\'s window has moved.');
        $this->assertSame(1, preg_match('/export const YEARS_SHOWN = (\d+);/', self::script('earnings-surprise.js'), $surprise));
        $this->assertSame(1, preg_match('/export const QUARTERS_SHOWN = (\d+);/', self::script('earnings-surprise.js'), $quarters));
        $this->assertSame(1, preg_match('/rawReports\.slice\(-(\d+)\)/', self::script('fundamental-charts.js'), $quarterly));

        $this->assertGreaterThanOrEqual(4 * (int) $annual[1], self::cap(), 'Twelve years of quarters, each year read as four.');
        $this->assertGreaterThanOrEqual(4 * (int) $surprise[1], self::cap());
        $this->assertGreaterThanOrEqual((int) $quarters[1], self::cap());
        $this->assertGreaterThanOrEqual((int) $quarterly[1], self::cap());
    }
}

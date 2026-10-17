<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Ticker;

use App\Entity\Etf;
use App\Service\Market\Ticker\EtfTickColumns;
use PHPUnit\Framework\TestCase;

/**
 * A fill is struck at the row, so the rows have to carry the price the screens show. Stocks and funds are written as
 * data on every frame tick between bars; this pins the fund writer to the mapping and the ticker to calling both.
 */
class EtfTickColumnsTest extends TestCase
{
    public function testEveryColumnWrittenIsAFundColumn(): void
    {
        $mapped = [];
        foreach ((new \ReflectionClass(Etf::class))->getProperties() as $property) {
            foreach ($property->getAttributes(\Doctrine\ORM\Mapping\Column::class) as $attribute) {
                $mapped[] = $attribute->newInstance()->name ?? strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $property->getName()));
            }
        }

        foreach (EtfTickColumns::COLUMNS as $column) {
            $this->assertContains($column, $mapped, "etfs has no {$column} column to write.");
        }
    }

    public function testRowsCarryTheQuoteKeyedByIdAndSkipUnpersistedFunds(): void
    {
        $fund = (new Etf())->setPrice('101.25')->setArbitrageBand(0.004)->setNavPremium(-0.002);
        (new \ReflectionProperty(Etf::class, 'id'))->setValue($fund, 3);

        $rows = EtfTickColumns::rows([$fund, new Etf()]);

        $this->assertSame([3 => ['101.25', 0.004, -0.002]], $rows);
    }

    /** Between bars, every tick a frame goes out writes both the stock and the fund quote columns. */
    public function testTheTickerWritesTheQuotesOnEveryFrameTick(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 4) . '/src/Command/MarketTickerCommand.php');

        $this->assertMatchesRegularExpression(
            '/elseif \(\$isFrameTick\) \{(?:(?!\n {16}\}).)*StockTickColumns::write\(\$conn, \$stocks\);(?:(?!\n {16}\}).)*EtfTickColumns::write\(\$conn, \$indexFunds\);/s',
            $source
        );
    }
}

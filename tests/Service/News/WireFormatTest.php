<?php

declare(strict_types=1);

namespace App\Tests\Service\News;

use App\Service\News\WireFormat;
use App\Twig\Extension\UiExtension;
use PHPUnit\Framework\TestCase;

/** News text quotes money and share counts the way the page beside it does. */
final class WireFormatTest extends TestCase
{
    public function testMoneyReadsAtItsOwnScale(): void
    {
        $this->assertSame('$40.00M', WireFormat::money(4.0e7));
        $this->assertSame('$1.23B', WireFormat::money(1.234e9));
        $this->assertSame('-$2.50T', WireFormat::money(-2.5e12));
        $this->assertSame('+$80.92B', WireFormat::money(80.92e9, true));
        $this->assertSame(UiExtension::large(3.3e8, '$'), WireFormat::money(3.3e8), 'The page prints the same amount the same way.');
    }

    public function testShareCountsAbbreviateFromAMillion(): void
    {
        $this->assertSame('410.29M', WireFormat::shares(410_288_930));
        $this->assertSame('45,000', WireFormat::shares(45_000));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The arbitrary-precision arithmetic the money and share ledgers are built on.
 *
 * Production always has the bcmath extension; the CLI sandbox does not, and tests/bootstrap.php fills in
 * for it. That polyfill used to route every operation through `(float)` and `number_format`, which
 * diverges from real bcmath in two ways that matter to this schema: doubles carry about 16 significant
 * digits while share counts run to 9.2e18 and money is stored at DECIMAL(20,4), and number_format ROUNDS
 * where bcmath TRUNCATES. Tests calibrated against that were not testing the arithmetic that ships.
 *
 * These cases assert the semantics both implementations must satisfy, so they are meaningful whether the
 * extension is present or polyfilled.
 */
final class DecimalArithmeticTest extends TestCase
{
    /** bcmath truncates toward zero; it never rounds. */
    public function testScaleTruncatesRatherThanRounds(): void
    {
        $this->assertSame('0.0000', bcadd('0.00009', '0', 4));
        $this->assertSame('0.0000', bcadd('-0.00009', '0', 4));
        $this->assertSame('1.9999', bcadd('1.99999', '0', 4));
        $this->assertSame('-1.9999', bcadd('-1.99999', '0', 4));
    }

    /** Precision holds past the point where a double would start losing digits. */
    public function testAdditionIsExactBeyondDoublePrecision(): void
    {
        $this->assertSame('9223372036854775809', bcadd('9223372036854775807', '2', 0));
        $this->assertSame('100000000000000000000.0002', bcadd('99999999999999999999.9999', '0.0003', 4));
    }

    /** Multiplication of two twenty-digit figures keeps every digit. */
    public function testMultiplicationIsExact(): void
    {
        $this->assertSame('99999999999999999990.0000', bcmul('9999999999999999999', '10', 4));
        $this->assertSame('0.0100', bcmul('0.1', '0.1', 4));
    }

    /** Division truncates at the requested scale rather than rounding up. */
    public function testDivisionTruncatesAtScale(): void
    {
        $this->assertSame('0.3333', bcdiv('1', '3', 4));
        $this->assertSame('-0.6666', bcdiv('-2', '3', 4));
        $this->assertSame('0', bcdiv('9', '10', 0));
    }

    public function testDivisionByZeroThrows(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        bcdiv('1', '0', 4);
    }

    /** Subtraction crossing zero keeps the sign, and an exact zero is unsigned. */
    public function testSubtractionAcrossZero(): void
    {
        $this->assertSame('-0.5000', bcsub('0.25', '0.75', 4));
        $this->assertSame('0.0000', bcsub('0.25', '0.25', 4));
        $this->assertSame('0.5000', bcsub('-0.25', '-0.75', 4));
    }

    /** Comparison is made at the given scale, so digits below it do not decide it. */
    public function testComparisonHonoursScale(): void
    {
        $this->assertSame(0, bccomp('1.00001', '1.00002', 4));
        $this->assertSame(-1, bccomp('1.00001', '1.00002', 5));
        $this->assertSame(1, bccomp('9223372036854775807', '9223372036854775806', 0));
        $this->assertSame(-1, bccomp('-5', '-4', 0));
        $this->assertSame(0, bccomp('-0.00001', '0.00001', 4), 'Both truncate to zero, which has no sign.');
    }

    /**
     * The property the ledgers actually rely on: a value split into parts and added back is unchanged.
     * Float arithmetic fails this at the magnitudes this schema stores.
     */
    public function testSplittingAndRecombiningIsLossless(): void
    {
        $total = '18446744073709551615.1234';
        $half = bcdiv($total, '2', 4);
        $remainder = bcsub($total, $half, 4);

        $this->assertSame($total, bcadd($half, $remainder, 4));
    }

    /**
     * Malformed input must be REJECTED, not repaired.
     *
     * The polyfill used to strip every non-digit before parsing, so it answered where the real extension
     * throws - and answered wrongly. "1.0E-5" is what PHP renders 0.00001 as, and stripping the 'E' and
     * '-' turned it into 1.05. Any call site casting a float to string before handing it to bcmath would
     * have been silently wrong in production and silently fine under test, which is the one divergence a
     * polyfill must not have.
     *
     * @param string $malformed A value real bcmath refuses.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedNumbers')]
    public function testMalformedInputThrowsRatherThanBeingRepaired(string $malformed): void
    {
        $this->expectException(\ValueError::class);

        bcadd($malformed, '0', 4);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedNumbers(): iterable
    {
        yield 'exponent notation' => ['1.0E-5'];
        yield 'large exponent' => ['1.0E+25'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'currency symbol' => ['$12.50'];
        yield 'thousands separator' => ['1,000.00'];
        yield 'trailing garbage' => ['12.5x'];
    }

    /** The forms real bcmath does accept still parse, including the signs and a bare decimal point. */
    public function testWellFormedInputIsStillAccepted(): void
    {
        $this->assertSame('12.5000', bcadd('12.5', '0', 4));
        $this->assertSame('12.5000', bcadd('+12.5', '0', 4));
        $this->assertSame('-12.5000', bcadd('-12.5', '0', 4));
        $this->assertSame('0.5000', bcadd('.5', '0', 4));
        $this->assertSame('5.0000', bcadd('5.', '0', 4));
        $this->assertSame('12.5000', bcadd('  12.5  ', '0', 4));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Data\Institutions;
use App\Twig\Extension\UiExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;

final class UiExtensionTest extends TestCase
{
    /** Exchange boards print an unchanged price in grey; colouring it green read as a gain that never happened. */
    public function testSignedClassSeparatesUpDownFlatAndUnknown(): void
    {
        $this->assertSame('text-secondary', UiExtension::signedClass(0.0001));
        $this->assertSame('text-tertiary', UiExtension::signedClass(-2));
        $this->assertSame('text-on-surface-variant', UiExtension::signedClass(0));
        $this->assertSame('text-on-surface-variant', UiExtension::signedClass(-0.0));
        $this->assertSame('text-on-surface-faint', UiExtension::signedClass(null));
        $this->assertSame('text-on-surface-faint', UiExtension::signedClass('n/a'));
        $this->assertSame('text-secondary', UiExtension::signedClass('1.25'));
    }

    public function testPercentScalesAFractionAndSignsOnlyARise(): void
    {
        $this->assertSame('5.23%', UiExtension::percent(0.0523));
        $this->assertSame('5.2%', UiExtension::percent(0.0523, 1));
        $this->assertSame('+5.2%', UiExtension::percent(0.0523, 1, true));
        $this->assertSame('-5.2%', UiExtension::percent(-0.0523, 1, true));
        $this->assertSame('0.00%', UiExtension::percent(0.0, 2, true));
        $this->assertSame('0.00%', UiExtension::percent(-0.00001, 2, true));
        $this->assertSame('1,250%', UiExtension::percent(12.5, 0));
    }

    /** `(null * 100)|number_format(2)` printed "0.00%", a figure nobody measured. */
    public function testMissingFiguresPrintTheDashNotAZero(): void
    {
        $this->assertSame('—', UiExtension::percent(null));
        $this->assertSame('—', UiExtension::percent(INF));
        $this->assertSame('—', UiExtension::money(null));
        $this->assertSame('—', UiExtension::money(NAN));
    }

    /** "$-12.00" in the page became "-$12.00" on the first market frame; both sides now put the sign first. */
    public function testMoneyCarriesTheSignOutsideTheSymbol(): void
    {
        $this->assertSame('$1,234.56', UiExtension::money(1234.564));
        $this->assertSame('-$12.00', UiExtension::money(-12));
        $this->assertSame('+$12.00', UiExtension::money(12, 2, true));
        $this->assertSame('$0.00', UiExtension::money(-0.001, 2, true));
        $this->assertSame('$1,235', UiExtension::money(1234.5, 0));
    }

    public function testSourceNamesPublishersFromTheGlossary(): void
    {
        $this->assertSame('Source: Statistical Office; Exchequer', UiExtension::source('statistical-office', 'exchequer'));
        $this->assertSame('Source: ' . Institutions::MONETARY_AUTHORITY, UiExtension::source('monetary-authority'));
    }

    public function testSourceRefusesABodyTheDistrictDoesNotHave(): void
    {
        $this->expectException(RuntimeError::class);
        UiExtension::source('treasury');
    }

    public function testFiltersAreRegisteredUnderTheirTemplateNames(): void
    {
        $twig = new Environment(new ArrayLoader([
            'page' => "{{ x|signed_class }} {{ x|pct(1, true) }} {{ y|money }} {{ source('credit-registry') }}",
        ]));
        $twig->addExtension(new UiExtension());

        $this->assertSame('text-secondary +4.2% -$3.50 Source: Credit Registry', $twig->render('page', ['x' => 0.042, 'y' => -3.5]));
    }
}

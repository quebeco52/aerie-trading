<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Data\District\Institutions;
use App\Service\View\FigureFormat;
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

    public function testPointsBasisPointsAndMultiplesScaleTheirFraction(): void
    {
        $this->assertSame('+1.23pp', UiExtension::points(0.0123, 2, true));
        $this->assertSame('-0.5pp', UiExtension::points(-0.005, 1, true));
        $this->assertSame('123 bps', UiExtension::bps(0.0123));
        $this->assertSame('+50 bps', UiExtension::bps(0.005, 0, true));
        $this->assertSame('0 bps', UiExtension::bps(-0.00001, 0, true), 'a move that rounds to nothing is unsigned');
        $this->assertSame('1,250 bps', UiExtension::bps(0.125));
        $this->assertSame('12.4 bps', UiExtension::bps(0.00124, 1));
        $this->assertSame('1.23x', UiExtension::multiple(1.234));
        $this->assertSame('-0.8x', UiExtension::multiple(-0.84, 1));
        $this->assertSame('1,800', UiExtension::hhi(0.18));
        foreach ([UiExtension::points(null), UiExtension::bps(NAN), UiExtension::multiple(null), UiExtension::hhi(INF)] as $missing) {
            $this->assertSame('—', $missing);
        }
    }

    /**
     * An insolvent firm's negative equity is scaled like its peers' (-$3.20B, not -$3,200,000,000.00), with the prefix
     * inside the sign, as formatters.js formatLarge() repaints it; Doctrine's decimal strings are taken as they come.
     */
    public function testLargeScalesTheMagnitudeAndKeepsThePrefixInsideTheSign(): void
    {
        $this->assertSame('1.50T', UiExtension::large(1_500_000_000_000));
        $this->assertSame('2.50B', UiExtension::large(2_500_000_000));
        $this->assertSame('45.20M', UiExtension::large(45_200_000));
        $this->assertSame('1,000.00', UiExtension::large(1_000));
        $this->assertSame('-450.00M', UiExtension::large(-450_000_000));
        $this->assertSame('-$3.20B', UiExtension::large(-3_200_000_000, '$'));
        $this->assertSame('-$3.20B', UiExtension::large('-3200000000.00', '$'));
        $this->assertSame('+$3.20B', UiExtension::large(3_200_000_000, '$', true));
        $this->assertSame('$0.00', UiExtension::large(0, '$', true));
        $this->assertSame('—', UiExtension::large(null, '$'));
    }

    /**
     * Each FigureFormat prints through the filter of its kind, at its own precision unless the page asks for another.
     */
    public function testFigurePrintsEachFormatThroughItsFilter(): void
    {
        $this->assertSame('5.20%', UiExtension::figure(0.052, FigureFormat::Percent));
        $this->assertSame('5.2%', UiExtension::figure(0.052, FigureFormat::Percent, 1));
        $this->assertSame('+5.20%', UiExtension::figure(0.052, FigureFormat::SignedPercent));
        $this->assertSame('-1.23pp', UiExtension::figure(-0.0123, FigureFormat::SignedPoints));
        $this->assertSame('123 bps', UiExtension::figure(0.0123, FigureFormat::Bps));
        $this->assertSame('$1.54T', UiExtension::figure(1.54e12, FigureFormat::LargeMoney));
        $this->assertSame('1.23x', UiExtension::figure(1.234, FigureFormat::Multiple));
        $this->assertSame('2.5 qtrs', UiExtension::figure(2.5, FigureFormat::Quarters));
        $this->assertSame('6.3 yrs', UiExtension::figure(6.25, FigureFormat::Years));
        $this->assertSame('1.042', UiExtension::figure(1.0423, FigureFormat::Index));
        $this->assertSame('+0.012', UiExtension::figure(0.0123, FigureFormat::SignedIndex));
        $this->assertSame('+0.012', UiExtension::figure(0.0123, 'signed_index'), 'a format arriving as its value string');
        $this->assertSame('—', UiExtension::figure(null, FigureFormat::Multiple));
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
            'page' => "{{ x|signed_class }} {{ x|pct(1, true) }} {{ y|money }} {{ source_line('credit-registry') }}"
                . " {{ x|pp(1) }} {{ x|bps }} {{ y|multiple }} {{ x|hhi }} {{ z|format_large('$') }} {{ x|figure('signed_percent', 1) }}",
        ]));
        $twig->addExtension(new UiExtension());

        $this->assertSame(
            'text-secondary +4.2% -$3.50 Source: Credit Registry 4.2pp 420 bps -3.50x 420 $1.20B +4.2%',
            $twig->render('page', ['x' => 0.042, 'y' => -3.5, 'z' => 1.2e9]),
        );
    }

    /** A macro called twice on a page draws two tooltips, and each trigger must point at its own. */
    public function testUiIdNeverRepeatsOnAPage(): void
    {
        $twig = new Environment(new ArrayLoader(['page' => "{{ ui_id('tip') }} {{ ui_id('tip') }} {{ ui_id('row') }}"]));
        $twig->addExtension(new UiExtension());

        $this->assertSame('tip-1 tip-2 row-3', $twig->render('page'));
    }

    /**
     * Twig's own source(), which inlines a template's text as the web profiler does its icons, still works beside it.
     */
    public function testTwigsOwnSourceFunctionIsNotReplaced(): void
    {
        $twig = new Environment(new ArrayLoader([
            'icon.svg' => '<svg></svg>',
            'page' => "{{ source('icon.svg') }}",
        ]));
        $twig->addExtension(new UiExtension());

        $this->assertSame('<svg></svg>', $twig->render('page'));
    }
}

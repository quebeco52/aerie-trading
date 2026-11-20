<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\CapitalIntensity;
use App\Data\Company\IndustryTurnovers;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\FirmEconomics;
use App\Service\Model\Sector\ReitBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The capital-intensity ladder and per-industry opening turnovers: step scaling, full board coverage, validation of
 * lore offsets, and refusal of stated operating_margin on in-scope rows.
 */
#[Group('unit')]
final class IndustryTurnoversTest extends TestCase
{
    public function testCapitalIntensityStepsMultiplyTurnoverMonotonically(): void
    {
        $this->assertSame(1.25, CapitalIntensity::STEP_FACTOR);

        $previous = INF;
        foreach (CapitalIntensity::cases() as $step) {
            $mult = $step->turnoverMultiplier();
            $this->assertLessThan($previous, $mult, "{$step->value} must turn over less than the lighter step above it.");
            $this->assertNotEmpty($step->label());
            $previous = $mult;
        }

        $this->assertEqualsWithDelta(1.25 ** 2, CapitalIntensity::VeryLight->turnoverMultiplier(), 1e-12);
        $this->assertEqualsWithDelta(1.25, CapitalIntensity::Light->turnoverMultiplier(), 1e-12);
        $this->assertSame(1.0, CapitalIntensity::Typical->turnoverMultiplier());
        $this->assertEqualsWithDelta(1.0 / 1.25, CapitalIntensity::Heavy->turnoverMultiplier(), 1e-12);
        $this->assertEqualsWithDelta(1.0 / (1.25 ** 2), CapitalIntensity::VeryHeavy->turnoverMultiplier(), 1e-12);
    }

    public function testEveryInScopeBoardIndustryHasATurnoverAndSeedRowsPassValidation(): void
    {
        foreach (array_keys(IndustryTurnovers::BY_INDUSTRY) as $industry) {
            $this->assertArrayHasKey($industry, Sectors::INDUSTRY_METRICS, "IndustryTurnovers lists unknown industry {$industry}.");
            $this->assertGreaterThan(0.0, IndustryTurnovers::forIndustry($industry));
        }

        $checked = 0;
        foreach (InitialMarket::STOCKS as $row) {
            $industry = is_string($row['industry'] ?? null) ? $row['industry'] : null;
            $strategy = Sectors::strategyFor($industry);
            if (!($strategy instanceof StandardCorporateBusinessModel) || $strategy instanceof ReitBusinessModel || !isset($row['revenue'])) {
                continue;
            }
            $checked++;
            IndustryTurnovers::assertRow($row);
            if (isset($row['capital_intensity'])) {
                $this->assertNotSame(CapitalIntensity::Typical->value, $row['capital_intensity'], "{$row['ticker']} states typical capital_intensity; omit the default.");
            }
        }

        $this->assertSame(77, $checked);
    }

    public function testOneCapitalIntensityStepRaisesOpeningMarginByStepFactorAtSameReturn(): void
    {
        $base = $this->rawRow('GRIP');
        unset($base['capital_intensity']);

        $typical = SeedRows::resolve($base);
        $heavy = SeedRows::resolve(['capital_intensity' => CapitalIntensity::Heavy->value] + $base);
        $light = SeedRows::resolve(['capital_intensity' => CapitalIntensity::Light->value] + $base);

        // Exact factor on the unrounded formula before 4dp column rounding.
        $taxRate = (new MacroStateDTO())->corporateTaxRate;
        $unroundedTypical = FirmEconomics::structuralOperatingMargin($typical['baseline_roic'], IndustryTurnovers::forRow($base), $taxRate);
        $unroundedHeavy = FirmEconomics::structuralOperatingMargin($heavy['baseline_roic'], IndustryTurnovers::forRow(['capital_intensity' => CapitalIntensity::Heavy->value] + $base), $taxRate);
        $unroundedLight = FirmEconomics::structuralOperatingMargin($light['baseline_roic'], IndustryTurnovers::forRow(['capital_intensity' => CapitalIntensity::Light->value] + $base), $taxRate);

        $this->assertEqualsWithDelta(CapitalIntensity::STEP_FACTOR, $unroundedHeavy / $unroundedTypical, 1e-12);
        $this->assertEqualsWithDelta(CapitalIntensity::STEP_FACTOR, $unroundedTypical / $unroundedLight, 1e-12);

        // Derived opening books carry the same return on capital and margin scaled by the step within 4dp rounding.
        $this->assertEqualsWithDelta($typical['baseline_roic'], $heavy['baseline_roic'], 1e-12);
        $this->assertEqualsWithDelta($typical['baseline_roic'], $light['baseline_roic'], 1e-12);
        $this->assertEqualsWithDelta(CapitalIntensity::STEP_FACTOR, $heavy['operating_margin'] / $typical['operating_margin'], 1e-3);
        $this->assertEqualsWithDelta(CapitalIntensity::STEP_FACTOR, $typical['operating_margin'] / $light['operating_margin'], 1e-3);
    }

    public function testSeedRowsRefusesOperatingMarginOnInScopeModelAndUnknownCapitalIntensity(): void
    {
        $grip = $this->rawRow('GRIP');

        foreach ([
            ['operating_margin' => 0.14] + $grip,
            ['capital_intensity' => 'ultralight'] + $grip,
            ['capital_intensity' => 1] + $grip,
            ['industry' => 'General'] + $grip,
        ] as $badRow) {
            try {
                SeedRows::resolve($badRow);
                $this->fail('Expected SeedRows::resolve() to refuse invalid character row.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        // A REIT still keeps operating_margin as a character input and refuses a zero margin or unknown
        // capital_intensity.
        $shor = $this->rawRow('SHOR');
        $resolvedShor = SeedRows::resolve($shor);
        $this->assertSame($shor['operating_margin'], $resolvedShor['operating_margin']);

        foreach ([
            ['operating_margin' => 0.0] + $shor,
            ['capital_intensity' => 'ultralight'] + $shor,
        ] as $badReit) {
            try {
                SeedRows::resolve($badReit);
                $this->fail('Expected SeedRows::resolve() to refuse invalid REIT row.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, mixed> */
    private function rawRow(string $ticker): array
    {
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === $ticker) {
                return $row;
            }
        }

        $this->fail("No seed row for {$ticker}.");
    }
}

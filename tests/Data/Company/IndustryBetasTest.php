<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\IndustryBetas;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\Service\Math\MacroConstants;
use App\Service\Math\Valuation;
use PHPUnit\Framework\TestCase;

/**
 * The industry betas a firm's beta is built on: Damodaran's January 2026 US table, the map from the board's
 * industries into it, and the band lore positions a firm in.
 */
final class IndustryBetasTest extends TestCase
{
    /** Rounding of the published two-decimal betas, after unlevering and the cash correction. */
    private const TRANSCRIPTION_TOLERANCE = 0.015;

    /** Each row re-derives its own cash-corrected unlevered beta, so a mistyped figure fails here. */
    public function testEveryRowReproducesDamodaransOwnUnlevering(): void
    {
        foreach (IndustryBetas::DAMODARAN as $industry => $row) {
            $unlevered = $row['levered'] / (1.0 + (1.0 - IndustryBetas::DAMODARAN_MARGINAL_TAX_RATE) * $row['de']);
            $this->assertEqualsWithDelta($row['unlevered_cash'], $unlevered / (1.0 - $row['cash']), self::TRANSCRIPTION_TOLERANCE, $industry);
        }
    }

    public function testEveryIndustryOnTheBoardMapsToADamodaranRow(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(Sectors::INDUSTRY_METRICS), array_keys(IndustryBetas::INDUSTRY_MAP))), 'Unmapped industries.');
        $this->assertSame([], array_values(array_diff(array_keys(IndustryBetas::INDUSTRY_MAP), array_keys(Sectors::INDUSTRY_METRICS))), 'Mapped industries Sectors does not list.');

        foreach (IndustryBetas::INDUSTRY_MAP as $industry => $damodaran) {
            $this->assertArrayHasKey($damodaran, IndustryBetas::DAMODARAN, $industry);
        }
    }

    public function testAnUnlistedIndustryReadsAsGeneral(): void
    {
        $this->assertSame('Total Market (without financials)', IndustryBetas::damodaranIndustryFor(null));
        $this->assertSame('Total Market (without financials)', IndustryBetas::damodaranIndustryFor('Not An Industry'));
        $this->assertSame('Power', IndustryBetas::damodaranIndustryFor('Utilities - Regulated Electric'));
    }

    /**
     * The anchor is the form the industry's model consumes: a model that re-levers the column by Hamada reads the
     * operating-asset beta, one that passes it through reads the levered beta. Struck on what each model does, so a
     * model that changes how it levers changes the anchor it needs.
     */
    public function testTheAnchorIsTheBetaTheModelExpectsInTheColumn(): void
    {

        foreach (array_keys(Sectors::INDUSTRY_METRICS) as $industry) {
            $row = IndustryBetas::DAMODARAN[IndustryBetas::damodaranIndustryFor($industry)];
            $relevered = Sectors::strategyFor($industry)->calculateLeveredBeta(1.0, 0.25, 1.0);

            if (abs($relevered - 1.0) < 1e-12) {
                $this->assertSame($row['levered'], IndustryBetas::anchorFor($industry), "$industry passes its beta through, so it stores the levered one.");
            } else {
                $this->assertEqualsWithDelta(Valuation::calculateLeveredBeta(1.0, 0.25, 1.0), $relevered, 1e-12, "$industry re-levers by Hamada in full.");
                $this->assertSame($row['unlevered_cash'], IndustryBetas::anchorFor($industry), "$industry re-levers, so it stores the asset beta.");
            }
        }
    }

    public function testATiltIsHeldToTheBand(): void
    {
        $this->assertSame(IndustryBetas::LORE_BAND, IndustryBetas::clampTilt(0.40));
        $this->assertSame(-IndustryBetas::LORE_BAND, IndustryBetas::clampTilt(-0.40));
        $this->assertSame(0.10, IndustryBetas::clampTilt(0.10));

        $anchor = IndustryBetas::anchorFor('Utilities - Regulated Electric');
        $this->assertEqualsWithDelta($anchor + IndustryBetas::LORE_BAND, IndustryBetas::seedBeta('Utilities - Regulated Electric', 1.0), 1e-12);
        $this->assertEqualsWithDelta($anchor, IndustryBetas::seedBetaFor(['industry' => 'Utilities - Regulated Electric']), 1e-12, 'A row without a tilt sits on its anchor.');
    }

    /** The band is 1.96 standard errors of a 60-month regression beta at the board's median volatility. */
    public function testTheBandIsARegressionBetasConfidenceInterval(): void
    {
        $volatilities = array_column(InitialMarket::STOCKS, 'volatility');
        sort($volatilities);
        $median = $volatilities[intdiv(count($volatilities), 2)];
        $usAverageBeta = 0.91;
        $market = MacroConstants::MACRO_VOL_BASE_ANCHOR;

        $residual = sqrt(($median ** 2) - (($usAverageBeta * $market) ** 2));
        $standardError = $residual / ($market * sqrt(60.0));

        $this->assertEqualsWithDelta(0.21, $median, 1e-12, 'The band is derived at a 0.21 median volatility.');
        $this->assertEqualsWithDelta(1.96 * $standardError, IndustryBetas::LORE_BAND, 0.005);
    }

    /** No business on the board is a structural hedge: the lowest US industry beta is Utility (General) at 0.24. */
    public function testNoFirmOpensWithANegativeBeta(): void
    {
        foreach (InitialMarket::STOCKS as $row) {
            $this->assertGreaterThan(0.0, IndustryBetas::seedBetaFor($row), $row['ticker']);
        }
    }
}

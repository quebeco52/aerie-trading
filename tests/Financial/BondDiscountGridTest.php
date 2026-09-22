<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Macro\MacroEngine;
use App\Service\Market\BondPricingEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The sampled discount curve has to BE the curve.
 *
 * The bond desk discounts every cash flow off a tenor grid rather than evaluating the term structure per
 * flow, because five thousand evaluations a tick was thirteen percent of the ticker. That is only a
 * legitimate trade if the grid answers what the function would have answered: a mark that drifts from the
 * quoted curve is an arbitrage against the macro dashboard, and the same discrepancy shows up in NAV in four
 * places. So the error is measured here rather than asserted to be small, across curve shapes chosen to be
 * awkward — inverted, humped, at the lower bound, under heavy central-bank duration extraction.
 */
class BondDiscountGridTest extends TestCase
{
    /** Basis points of zero-yield error the grid is allowed against direct evaluation. */
    private const TOLERANCE_BPS = 1.0;

    private BondPricingEngine $engine;
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
        $this->engine = new BondPricingEngine($this->math);
    }

    /**
     * @return array<string, SovereignCurveDTO>
     */
    private function curves(): array
    {
        $make = static fn (float $level, float $slope, float $c1, float $c2, float $balanceSheet = 0.0): SovereignCurveDTO
            => new SovereignCurveDTO(
                level: $level,
                slope: $slope,
                curvature1: $c1,
                curvature2: $c2,
                baseTermPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
                longEndPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
                balanceSheetIntensity: $balanceSheet,
            );

        return [
            'normal' => $make(0.0425, -0.0175, 0.0, 0.0),
            'inverted' => $make(0.0300, 0.0250, 0.0, 0.0),
            'humped' => $make(0.0400, -0.0100, 0.0300, -0.0200),
            'steep' => $make(0.0600, -0.0450, 0.0100, 0.0100),
            // Deep enough that the effective lower bound bites across the short end, which is the one place
            // the curve is not smooth and the only reason the floor is applied after the interpolation.
            'at the lower bound' => $make(-0.0050, -0.0300, 0.0, 0.0),
            'heavy QE' => $make(0.0350, -0.0200, 0.0, 0.0, 0.0200),
            'QT' => $make(0.0350, -0.0200, 0.0, 0.0, -0.0200),
        ];
    }

    public function testTheGridAnswersWhatTheCurveFunctionAnswers(): void
    {
        $worst = 0.0;
        $worstAt = '';

        foreach ($this->curves() as $name => $curve) {
            // Deliberately off the pillars: a tenor that lands on one is exact by construction and proves
            // nothing about the cells between them.
            for ($tenor = 0.0; $tenor <= 30.0; $tenor += 0.017) {
                $error = abs($this->engine->discountZeroYield($curve, $tenor) - $this->engine->zeroYield($curve, $tenor));

                if ($error > $worst) {
                    $worst = $error;
                    $worstAt = sprintf('%s at %.3fy', $name, $tenor);
                }
            }
        }

        $this->assertLessThan(
            self::TOLERANCE_BPS / 10000.0,
            $worst,
            sprintf('Grid drifts %.3f bps from the curve (worst: %s).', $worst * 10000.0, $worstAt)
        );
    }

    /** The benchmark tenors the macro engine quotes fall on pillars, so those are exact rather than close. */
    public function testTheQuotedBenchmarkTenorsAreExact(): void
    {
        foreach ($this->curves() as $name => $curve) {
            foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
                $this->assertEqualsWithDelta(
                    $this->engine->zeroYield($curve, $tenor),
                    $this->engine->discountZeroYield($curve, $tenor),
                    1e-12,
                    sprintf('The quoted %gy is not exact on the %s curve.', $tenor, $name)
                );
            }
        }
    }

    /** What the error is actually worth: the price of a long bond, where duration multiplies it. */
    public function testALongBondPricesWithinACentOfDirectEvaluation(): void
    {
        $curve = $this->curves()['normal'];
        $bond = (new Bond())
            ->setTicker('G30-GRID')
            ->setName('grid probe')
            ->setTenorYears('30')
            ->setCouponRate('0.04')
            ->setFaceValue((string) FinancialConstants::BOND_FACE_VALUE)
            ->setIssuedAtTime(0.0)
            ->setMaturesAtTime(30.0)
            ->setLastCouponTime(0.0);

        $flows = $this->engine->remainingCashFlows($bond, 0.3);

        $gridPrice = $this->math->calculateBondPresentValue(
            $flows,
            fn (float $tau): float => $this->engine->discountZeroYield($curve, $tau)
        );
        $exactPrice = $this->math->calculateBondPresentValue(
            $flows,
            fn (float $tau): float => $this->engine->zeroYield($curve, $tau)
        );

        // A cent on a thousand-unit face, against a coupon struck in eighths of a percent.
        $this->assertEqualsWithDelta($exactPrice, $gridPrice, 0.01);
    }

    /** A fresh curve object resamples: a stale grid would price today's bonds off yesterday's rates. */
    public function testChangingTheCurveResamplesTheGrid(): void
    {
        $curves = $this->curves();

        $first = $this->engine->discountZeroYield($curves['normal'], 7.3);
        $second = $this->engine->discountZeroYield($curves['steep'], 7.3);
        $back = $this->engine->discountZeroYield($curves['normal'], 7.3);

        $this->assertNotEqualsWithDelta($first, $second, 1e-6);
        $this->assertEqualsWithDelta($first, $back, 1e-12);
    }

    /** Past the end of the grid there is nothing to interpolate between, so the curve is asked directly. */
    public function testATenorBeyondTheGridFallsBackToDirectEvaluation(): void
    {
        $curve = $this->curves()['normal'];
        $beyond = BondPricingEngine::PILLAR_MAX_TENOR_YEARS + 5.0;

        $this->assertSame($this->engine->zeroYield($curve, $beyond), $this->engine->discountZeroYield($curve, $beyond));
    }
}

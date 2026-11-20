<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\Service\Market\Pricing\VarianceBudget;
use App\Service\Math\MacroConstants;
use App\Service\Math\Valuation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pure risk-derivation formulas on VarianceBudget and Valuation: levering business idiosyncratic volatility onto
 * equity volatility alongside the systematic loading, and solving the Kou jump scale for an exact variance share.
 */
#[Group('unit')]
final class VarianceBudgetTest extends TestCase
{
    public function testLeveringLawEqualsBusinessRiskAtUnleveredZeroBetaAndDoublesIdiosyncraticVolAtL2(): void
    {
        $stepVol = 0.155;

        // At beta = 0 and L = 1, equity volatility equals the operating business step exactly.
        $this->assertEqualsWithDelta(
            $stepVol,
            VarianceBudget::equityVolatilityFromBusinessRisk(0.0, 1.0, $stepVol),
            1e-12,
        );

        // At L = 2 with a non-zero beta, the idiosyncratic part doubles while the systematic loading stays fixed.
        $beta = 1.15;
        $volL1 = VarianceBudget::equityVolatilityFromBusinessRisk($beta, 1.0, $stepVol);
        $volL2 = VarianceBudget::equityVolatilityFromBusinessRisk($beta, 2.0, $stepVol);

        $sysVar = (($beta * MacroConstants::MACRO_VOL_BASE_ANCHOR) ** 2)
            + VarianceBudget::systemicJumpMoments($beta)['variance'];
        $idioL1 = sqrt(($volL1 ** 2) - $sysVar);
        $idioL2 = sqrt(($volL2 ** 2) - $sysVar);

        $this->assertEqualsWithDelta($stepVol, $idioL1, 1e-12);
        $this->assertEqualsWithDelta(2.0 * $stepVol, $idioL2, 1e-12);
    }

    public function testDerivedJumpScalePutsExactShareOfIdiosyncraticVarianceIntoJumps(): void
    {
        $idioE = 0.22;
        $beta = 1.10;
        $equityVol = VarianceBudget::equityVolatilityFromBusinessRisk($beta, 1.0, $idioE);

        foreach ([0.30, 0.50, 0.80, 1.25] as $lambda) {
            $share = VarianceBudget::MAX_IDIOSYNCRATIC_JUMP_VARIANCE_SHARE;
            $scale = VarianceBudget::jumpScaleForVarianceShare($idioE, $lambda, $share);
            $impliedJumpVar = $lambda * VarianceBudget::kouRawSecondMomentPerUnit() * ($scale ** 2);

            $this->assertEqualsWithDelta($share * ($idioE ** 2), $impliedJumpVar, 1e-9, "lambda={$lambda}");

            $calibrated = VarianceBudget::calibratedJumpParameters($equityVol, $beta, $lambda, $scale);
            $this->assertEqualsWithDelta($scale, $calibrated['scale'], 1e-12, "calibratedJumpParameters must not rescale derived scale at lambda={$lambda}");
        }
    }

    public function testLeverageMultiplierMatchesBetaLaw(): void
    {
        $assetBeta = 0.85;
        $taxRate = 0.21;
        $de = 0.65;
        $businessValue = 100.0;
        $netCash = 15.0;

        $hamada = Valuation::hamadaMultiplier($taxRate, $de);
        $this->assertEqualsWithDelta(
            Valuation::calculateLeveredBeta($assetBeta, $taxRate, $de),
            $assetBeta * $hamada,
            1e-12,
        );

        $cashBlended = Valuation::cashBlendedMultiplier($hamada, $businessValue, $netCash);
        $this->assertEqualsWithDelta(
            Valuation::cashBlendedBeta($assetBeta * $hamada, $businessValue, $netCash),
            $assetBeta * $cashBlended,
            1e-12,
        );
    }
}

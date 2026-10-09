<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Macro\MacroEngine;
use App\Service\Math\CreditRisk;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Corporate credit primitives. Both of these exist to stop the same mistake — pricing a risky claim as if
 * the bad year never comes — so the properties asserted are the ones that only show up in a bad year:
 * recovery falling exactly when defaults cluster, and a spread that is not zero on a safe name.
 */
class CreditRiskTest extends TestCase
{
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
    }

    // --- Recovery ---

    public function testAnAverageDefaultYearRecoversTheBaseRate(): void
    {
        $recovery = CreditRisk::calculateRecoveryGivenDefault(
            FinancialConstants::RECOVERY_SENIOR_UNSECURED,
            FinancialConstants::RECOVERY_BASELINE_DEFAULT_RATE
        );

        $this->assertEqualsWithDelta(FinancialConstants::RECOVERY_SENIOR_UNSECURED, $recovery, 1e-12);
    }

    public function testRecoveryFallsAsDefaultsCluster(): void
    {
        // The whole point of the model: the claim is worth least in the year most of them are being settled.
        $quiet = CreditRisk::calculateRecoveryGivenDefault(0.48, 0.009);
        $average = CreditRisk::calculateRecoveryGivenDefault(0.48, 0.018);
        $crisis = CreditRisk::calculateRecoveryGivenDefault(0.48, 0.072);

        $this->assertGreaterThan($average, $quiet);
        $this->assertLessThan($average, $crisis);
    }

    public function testTheFallIsLogLinearInTheDefaultRate(): void
    {
        // Each doubling of the default rate costs the same amount of recovery.
        $first = CreditRisk::calculateRecoveryGivenDefault(0.60, 0.018) - CreditRisk::calculateRecoveryGivenDefault(0.60, 0.036);
        $second = CreditRisk::calculateRecoveryGivenDefault(0.60, 0.036) - CreditRisk::calculateRecoveryGivenDefault(0.60, 0.072);

        $this->assertEqualsWithDelta($first, $second, 1e-12);
        $this->assertEqualsWithDelta(FinancialConstants::RECOVERY_DEFAULT_RATE_ELASTICITY * log(2.0), $first, 1e-12);
    }

    public function testSeniorityOrdersRecoveryAtAnyPointInTheCycle(): void
    {
        foreach ([0.005, 0.018, 0.10] as $defaultRate) {
            $secured = CreditRisk::calculateRecoveryGivenDefault(FinancialConstants::RECOVERY_SENIOR_SECURED, $defaultRate);
            $unsecured = CreditRisk::calculateRecoveryGivenDefault(FinancialConstants::RECOVERY_SENIOR_UNSECURED, $defaultRate);
            $subordinated = CreditRisk::calculateRecoveryGivenDefault(FinancialConstants::RECOVERY_SUBORDINATED, $defaultRate);

            $this->assertGreaterThan($unsecured, $secured, "at a default rate of {$defaultRate}");
            $this->assertGreaterThan($subordinated, $unsecured, "at a default rate of {$defaultRate}");
        }
    }

    public function testRecoveryStaysInsideItsBounds(): void
    {
        // A catastrophic default year, and an implausibly quiet one.
        $this->assertGreaterThanOrEqual(
            FinancialConstants::MIN_RECOVERY_RATE,
            CreditRisk::calculateRecoveryGivenDefault(0.28, 0.99)
        );
        $this->assertLessThanOrEqual(
            FinancialConstants::MAX_RECOVERY_RATE,
            CreditRisk::calculateRecoveryGivenDefault(0.62, 1.0e-9)
        );
    }

    public function testANonsenseDefaultRateReturnsTheBaseRatherThanDividingByIt(): void
    {
        $this->assertEqualsWithDelta(0.48, CreditRisk::calculateRecoveryGivenDefault(0.48, 0.0), 1e-12);
        $this->assertEqualsWithDelta(0.48, CreditRisk::calculateRecoveryGivenDefault(0.48, 0.02, 0.0), 1e-12);
    }

    // --- Spread ---

    public function testASafeIssuerStillPaysTheNonDefaultComponent(): void
    {
        // A bond priced on default risk alone quotes through the market, and worst exactly here: at the safe
        // end the default component is almost nothing and the residual is almost all of the spread.
        $spread = $this->math->calculateCorporateSpread(8.0, 0.52, 5.0);

        $this->assertGreaterThanOrEqual(FinancialConstants::CORPORATE_ILLIQUIDITY_SPREAD, $spread);
        $this->assertLessThan(0.01, $spread);
    }

    public function testAWeakerIssuerPaysMore(): void
    {
        $strong = $this->math->calculateCorporateSpread(5.0, 0.52, 5.0);
        $weak = $this->math->calculateCorporateSpread(1.0, 0.52, 5.0);

        $this->assertGreaterThan($strong, $weak);
    }

    public function testALowerRecoveryClaimPaysMoreOnTheSameIssuer(): void
    {
        $senior = $this->math->calculateCorporateSpread(2.0, 1.0 - FinancialConstants::RECOVERY_SENIOR_SECURED, 5.0);
        $subordinated = $this->math->calculateCorporateSpread(2.0, 1.0 - FinancialConstants::RECOVERY_SUBORDINATED, 5.0);

        // Same probability of default, different amount lost when it happens.
        $this->assertGreaterThan($senior, $subordinated);
    }

    public function testTheSpreadCarriesATermStructureRatherThanBeingFlat(): void
    {
        // A distressed name is riskier over one year than over ten: it either survives the year or it does
        // not, and the annualized cost of that is highest at the front.
        $oneYear = $this->math->calculateCorporateSpread(0.5, 0.52, 1.0);
        $tenYear = $this->math->calculateCorporateSpread(0.5, 0.52, 10.0);

        $this->assertGreaterThan($tenYear, $oneYear);
    }

    public function testTheSpreadIsBoundedAndNeverNegative(): void
    {
        $this->assertLessThanOrEqual(FinancialConstants::MAX_CORPORATE_SPREAD, $this->math->calculateCorporateSpread(-10.0, 0.95, 0.1));
        $this->assertGreaterThanOrEqual(0.0, $this->math->calculateCorporateSpread(50.0, 0.0, 30.0, 0.0));
    }

    public function testAZeroMaturityDoesNotDivideByItself(): void
    {
        $spread = $this->math->calculateCorporateSpread(2.0, 0.52, 0.0);

        $this->assertTrue(is_finite($spread));
        $this->assertGreaterThanOrEqual(0.0, $spread);
    }

    public function testCalculateVasicekExpectedLoss(): void
    {
        $pdLra = 0.015; // 1.5% through-the-cycle PD
        $rho = 0.15;   // 15% asset correlation
        $lgd = 0.45;   // 45% LGD

        // Neutral macro shock (Z = 0) -> Conditional PD is Phi(Phi^-1(PD_LRA) / sqrt(1 - rho))
        // Due to convexity (Jensen's inequality), conditional median at Z=0 is lower than TTC mean
        $baselineEl = CreditRisk::calculateVasicekExpectedLoss(0.0, $pdLra, $rho, $lgd);
        $invPd = Distributions::calculateInverseNormalCDF($pdLra);
        $expectedConditionalPd = Distributions::calculateNormalCDF($invPd / sqrt(1.0 - $rho));
        $this->assertEqualsWithDelta($expectedConditionalPd * $lgd, $baselineEl, 0.00001);

        // Severe economic downturn / credit crunch (Z = -3.0) -> Tail risk non-linear surge
        $stressedEl = CreditRisk::calculateVasicekExpectedLoss(-3.0, $pdLra, $rho, $lgd);
        $this->assertGreaterThan($baselineEl * 5.0, $stressedEl, 'Stressed loss should be non-linearly higher than baseline.');

        // Benign economic boom (Z = +2.0) -> Defaults fall below TTC baseline
        $boomEl = CreditRisk::calculateVasicekExpectedLoss(2.0, $pdLra, $rho, $lgd);
        $this->assertLessThan($baselineEl, $boomEl, 'Boom loss should be lower than baseline.');
        $this->assertGreaterThan(0.0, $boomEl);

        // Higher asset correlation increases tail loss under stress
        $highRhoEl = CreditRisk::calculateVasicekExpectedLoss(-3.0, $pdLra, 0.30, $lgd);
        $this->assertGreaterThan($stressedEl, $highRhoEl, 'Higher asset correlation should amplify stressed tail losses.');
    }

    /** Belkin-Suchower-Forest: the factor implied by a conditional PD is the one that produced it. */
    public function testVasicekSystematicFactorInvertsTheConditionalPd(): void
    {
        foreach ([-2.5, -1.0, 0.0, 0.7, 2.0] as $z) {
            $conditionalPd = CreditRisk::calculateVasicekExpectedLoss($z, 0.025, 0.12, 1.0);
            $this->assertEqualsWithDelta($z, CreditRisk::calculateVasicekSystematicFactor($conditionalPd, 0.025, 0.12), 1e-4);
        }

        $this->assertLessThan(0.0, CreditRisk::calculateVasicekSystematicFactor(0.06, 0.016, 0.10), 'defaults above the median read as a downturn');
    }

    public function testCollateralLgdRisesAsCollateralFallsBelowOrigination(): void
    {
        $this->assertEqualsWithDelta(0.45, CreditRisk::calculateCollateralLgd(0.45, 100.0, 100.0), 1e-12);
        $this->assertEqualsWithDelta(1.0 - 0.55 * 0.70, CreditRisk::calculateCollateralLgd(0.45, 70.0, 100.0), 1e-12);
        $this->assertEqualsWithDelta(1.0 - 0.55 * 1.20, CreditRisk::calculateCollateralLgd(0.45, 120.0, 100.0), 1e-12);
        $this->assertSame(0.0, CreditRisk::calculateCollateralLgd(0.45, 250.0, 100.0));
        $this->assertSame(1.0, CreditRisk::calculateCollateralLgd(0.45, 0.0, 100.0));
        $this->assertSame(0.45, CreditRisk::calculateCollateralLgd(0.45, 70.0, 0.0), 'no origination reference reads as base LGD');
    }

    /**
     * The solver inverts Merton's equity-as-a-call: price a firm forward from known assets, hand the solver
     * only what the market would show (equity value and volatility), and it must recover the asset volatility.
     */
    public function testMertonAssetVolatilityInvertsTheEquityCall(): void
    {
        $normal = fn (float $x): float => Distributions::calculateNormalCDF($x);
        $rate = 0.03;
        $horizon = 5.0;

        foreach ([[100.0, 0.25, 40.0], [100.0, 0.15, 85.0], [100.0, 0.30, 95.0]] as [$assets, $assetVolatility, $debt]) {
            $d1 = (log($assets / $debt) + (($rate + (0.5 * $assetVolatility ** 2)) * $horizon)) / ($assetVolatility * sqrt($horizon));
            $d2 = $d1 - ($assetVolatility * sqrt($horizon));
            $equity = ($assets * $normal($d1)) - ($debt * exp(-$rate * $horizon) * $normal($d2));
            $equityVolatility = $normal($d1) * $assetVolatility * $assets / $equity;

            $solved = CreditRisk::solveMertonAssetVolatility($equity, $equityVolatility, $debt, $rate, $horizon);

            $this->assertEqualsWithDelta($assetVolatility, $solved, 1e-6, sprintf('D/V %.2f', $debt / $assets));
            $this->assertEqualsWithDelta($assets, CreditRisk::solveMertonAssetValue($equity, $assetVolatility, $debt, $rate, $horizon), 1e-6, 'the equity price implies the assets');
        }
    }

    /**
     * Close to the barrier the call's delta falls below one, so the de-levered shortcut sigma_E E / V
     * understates the assets' risk; the joint solution does not.
     */
    public function testMertonAssetVolatilityExceedsTheDeleveredShortcutNearTheBarrier(): void
    {
        $solved = CreditRisk::solveMertonAssetVolatility(20.0, 0.60, 90.0, 0.03, 5.0);

        $this->assertGreaterThan(0.60 * 20.0 / 110.0, $solved);
    }

    /**
     * Deep out of the money the equity is almost all option value: a firm whose shares are worth 1% of its
     * debt has assets worth well under the debt, which is what makes it insolvent rather than merely levered.
     */
    public function testMertonAssetValueOfNearlyWorthlessEquityIsBelowTheDebt(): void
    {
        $assets = CreditRisk::solveMertonAssetValue(1.0, 0.20, 100.0, 0.03, 5.0);

        $this->assertLessThan(100.0, $assets);
        $this->assertGreaterThan(1.0, $assets);
        $this->assertSame(500.0, CreditRisk::solveMertonAssetValue(500.0, 0.22, 0.0, 0.03, 5.0), 'no debt: the equity is the firm');
        $this->assertSame(0.0, CreditRisk::solveMertonAssetValue(0.0, 0.22, 100.0, 0.03, 5.0));
    }

    public function testMertonAssetVolatilityOfAnUnleveredFirmIsItsEquityVolatility(): void
    {
        $this->assertSame(0.22, CreditRisk::solveMertonAssetVolatility(500.0, 0.22, 0.0, 0.03, 5.0));
        $this->assertSame(0.0, CreditRisk::solveMertonAssetVolatility(0.0, 0.22, 100.0, 0.03, 5.0));
    }

    public function testCalculateEstrellaMishkinProbability(): void
    {
        // Normal upward-sloping yield curve (+150bps slope), normal term premium (+30bps), neutral FCI (0.0)
        $normalProb = CreditRisk::calculateEstrellaMishkinProbability(
            slope: 0.015,
            termPremium: 0.003,
            fci: 0.0
        );
        $this->assertLessThan(0.20, $normalProb, 'Normal steep yield curve must indicate low recession risk.');
        $this->assertGreaterThan(0.001, $normalProb);

        // Inverted yield curve (-150bps slope), negative term premium (-50bps), tightened FCI (+1.50)
        $invertedProb = CreditRisk::calculateEstrellaMishkinProbability(
            slope: -0.015,
            termPremium: -0.005,
            fci: 1.50
        );
        $this->assertGreaterThan(0.70, $invertedProb, 'Deeply inverted yield curve with tight financial conditions must predict high recession probability.');
        $this->assertLessThanOrEqual(0.999, $invertedProb);
    }

    public function testCalculateCorporateDefaultRate(): void
    {
        // Neutral conditions (macro Z = 0) sit at the Vasicek median, below the 1.6% mean: Moody's all-rated
        // record since 1983 has its median year near 1.2%.
        $baselineDefault = CreditRisk::calculateCorporateDefaultRate(
            macroZ: 0.0
        );
        $this->assertEqualsWithDelta(0.012, $baselineDefault, 0.001);

        // Crisis conditions (deep recession macro Z = -2.5)
        $crisisDefault = CreditRisk::calculateCorporateDefaultRate(
            macroZ: -2.5
        );
        $this->assertGreaterThan(0.06, $crisisDefault, 'Severe recession credit shock must sharply elevate corporate defaults.');
        $this->assertLessThanOrEqual(0.18, $crisisDefault);
    }

    public function testCalculateSloosCreditStandards(): void
    {
        // Reversion toward target without diffusion (dW = 0)
        $tightened = CreditRisk::calculateSloosCreditStandards(
            currentSloos: 0.0,
            outputGap: -0.03,             // Recession
            excessCreditSpread: 0.040,   // Wide credit spread (+400bps above baseline 0.02)
            dt: 0.25,
            dW: 0.0
        );
        $this->assertGreaterThan(0.10, $tightened, 'Recession and wide credit spreads must drive bank lending standards to tighten.');
        $this->assertLessThanOrEqual(0.85, $tightened);

        // Easing regime (negative excess credit spread -0.005, positive output gap +0.02)
        $eased = CreditRisk::calculateSloosCreditStandards(
            currentSloos: 0.20,
            outputGap: 0.02,
            excessCreditSpread: -0.005,
            dt: 0.50,
            dW: 0.0
        );
        $this->assertLessThan(0.20, $eased, 'Benign conditions must cause bank lending standards to ease.');
    }

    public function testCalculateDiminishingDistressMultiplier(): void
    {
        // Zero or negative distress signal yields 0.0
        $this->assertSame(0.0, CreditRisk::calculateDiminishingDistressMultiplier(0.0));
        $this->assertSame(0.0, CreditRisk::calculateDiminishingDistressMultiplier(-0.5));

        // At half-saturation S = K_s = 1.0, multiplier is exactly half of M_max (1.25 / 2 = 0.625)
        $half = CreditRisk::calculateDiminishingDistressMultiplier(1.0, 1.25, 1.0);
        $this->assertEqualsWithDelta(0.625, $half, 0.0001);

        // Under an extreme shock S = 100.0, multiplier approaches M_max without exceeding it
        $extreme = CreditRisk::calculateDiminishingDistressMultiplier(100.0, 1.25, 1.0);
        $this->assertLessThanOrEqual(1.25, $extreme);
        $this->assertGreaterThan(1.20, $extreme);

        // Infinite shock remains strictly bounded by M_max
        $infinite = CreditRisk::calculateDiminishingDistressMultiplier(1_000_000.0, 1.25, 1.0);
        $this->assertLessThanOrEqual(1.25, $infinite);
    }

    /**
     * The HY tranche is a fixed multiple of IG, cycle and crisis alike (ICE HY/IG ~3.3x in calm years and at the
     * December 2008 peak), and both legs respect their clamps.
     */
    public function testDualTrancheCreditSpreadsWidenAsymmetricallyAndRespectTheirClamps(): void
    {
        // Neutral cycle, vol exactly at the threshold, no interbank stress: IG is the base spread untouched.
        $neutral = CreditRisk::calculateDualTrancheCreditSpreads(
            MacroEngine::BASE_CREDIT_SPREAD,
            0.0,
            MacroEngine::CREDIT_SPREAD_EXCESS_VOL_THRESHOLD,
            0.0
        );
        $this->assertEqualsWithDelta(MacroEngine::BASE_CREDIT_SPREAD, $neutral['ig'], 0.0000001, 'A closed gap leaves the IG spread at its base.');
        $this->assertEqualsWithDelta(
            MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
            $neutral['hy'],
            0.0000001,
            'With no contraction the HY tranche is a flat multiple of IG.'
        );

        // Vol below the threshold contributes nothing: the excess term is one-sided.
        $lowVol = CreditRisk::calculateDualTrancheCreditSpreads(MacroEngine::BASE_CREDIT_SPREAD, 0.0, 0.05, 0.0);
        $this->assertEqualsWithDelta($neutral['ig'], $lowVol['ig'], 0.0000001, 'Calm markets must not tighten spreads below base.');

        // Excess vol and interbank stress are additive on the IG leg.
        $stressed = CreditRisk::calculateDualTrancheCreditSpreads(
            MacroEngine::BASE_CREDIT_SPREAD,
            0.0,
            MacroEngine::CREDIT_SPREAD_EXCESS_VOL_THRESHOLD + 0.10,
            0.05
        );
        $this->assertEqualsWithDelta(
            MacroEngine::BASE_CREDIT_SPREAD
                + 0.10 * MacroEngine::MERTON_VOL_SENSITIVITY
                + 0.05 * MacroEngine::INTERBANK_CREDIT_CONTAGION_SENSITIVITY,
            $stressed['ig'],
            0.0000001,
            'Vol and contagion must add linearly onto the cycle spread.'
        );

        // A contraction widens IG through distance-to-default, and HY keeps its multiple of it.
        $recession = CreditRisk::calculateDualTrancheCreditSpreads(MacroEngine::BASE_CREDIT_SPREAD, -0.04, 0.10, 0.0);
        $this->assertEqualsWithDelta(MacroEngine::BASE_CREDIT_SPREAD * exp(MacroEngine::MERTON_LEVERAGE_SENSITIVITY * 0.04), $recession['ig'], 1e-12, 'A contraction must widen investment grade.');
        $this->assertEqualsWithDelta($recession['ig'] / $neutral['ig'], $recession['hy'] / $neutral['hy'], 1e-12, 'High yield tracks investment grade at its base multiple.');

        // Both legs clamp: a depression cannot produce an unbounded spread.
        $depression = CreditRisk::calculateDualTrancheCreditSpreads(MacroEngine::BASE_CREDIT_SPREAD, -1.0, 2.0, 1.0);
        $this->assertSame(MacroEngine::MAX_CREDIT_SPREAD, $depression['ig'], 'The IG spread must clamp at its ceiling.');
        $this->assertEqualsWithDelta(MacroEngine::MAX_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER, $depression['hy'], 1e-12, 'The HY spread tops out at its multiple of the IG record.');

        // A boom floors IG, and HY never compresses inside its minimum multiple of IG.
        $boom = CreditRisk::calculateDualTrancheCreditSpreads(MacroEngine::BASE_CREDIT_SPREAD, 0.50, 0.0, 0.0);
        $this->assertSame(MacroEngine::MIN_CREDIT_SPREAD, $boom['ig'], 'The IG spread must floor at its minimum.');
        $this->assertEqualsWithDelta($boom['ig'] * MacroEngine::HY_BASE_SPREAD_MULTIPLIER, $boom['hy'], 1e-12, 'HY floors with IG at its multiple.');
    }

    /** A capital buffer is an add-on to the equity share a leverage cap implies, so it lowers the cap and never raises it. */
    public function testABufferedLeverageLimitIsTheCapTheRaisedEquityShareAllows(): void
    {
        $this->assertEqualsWithDelta(10.0, CreditRisk::calculateBufferedLeverageLimit(10.0, 0.0), 1e-12, 'No buffer, no change.');
        $this->assertEqualsWithDelta(1.0 / ((1.0 / 11.0) + 0.025) - 1.0, CreditRisk::calculateBufferedLeverageLimit(10.0, 0.025), 1e-12);
        $this->assertLessThan(10.0, CreditRisk::calculateBufferedLeverageLimit(10.0, 0.01));
        $this->assertGreaterThan(CreditRisk::calculateBufferedLeverageLimit(10.0, 0.025), CreditRisk::calculateBufferedLeverageLimit(10.0, 0.01), 'A bigger buffer binds harder.');
    }

    public function testSchularickTaylorCrisisHazardRisesWithTheCreditBoom(): void
    {
        $intercept = -4.50;
        $slope = 3.98;
        $noBoom = CreditRisk::calculateSchularickTaylorCrisisHazard(creditGap: 0.0, beta0: $intercept, betaGap: $slope);
        $this->assertEqualsWithDelta(1.0 / (1.0 + exp(4.50)), $noBoom, 1e-12, 'With no boom the hazard is the intercept\'s base rate, ~1.1% a year.');

        $boom = CreditRisk::calculateSchularickTaylorCrisisHazard(creditGap: 0.30, beta0: $intercept, betaGap: $slope);
        $this->assertGreaterThan(2.5 * $noBoom, $boom, 'A thirty-point boom (the US household gap of 2006) triples the odds.');

        $bust = CreditRisk::calculateSchularickTaylorCrisisHazard(creditGap: -0.10, beta0: $intercept, betaGap: $slope);
        $this->assertLessThan($noBoom, $bust, 'Credit below trend is safer than trend.');

        $capped = CreditRisk::calculateSchularickTaylorCrisisHazard(creditGap: 10.0, beta0: 5.0, betaGap: 10.0);
        $this->assertSame(0.99, $capped);
    }
}

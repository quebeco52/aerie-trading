<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Covers the openings a snapshot computes rather than reads: the values a payload that predates a
 * field, or was written by an older engine, has to be given before the pricing surfaces see it.
 */
class MacroStateHydrationTest extends TestCase
{
    public function testAbsentCurveIsRebuiltOffThePolicyRate(): void
    {
        $dto = MacroStateDTO::fromArray(['policy_rate' => 0.06]);
        $opening = new MacroStateDTO();

        // The opening curve's term spreads, carried on top of the policy rate the payload does report.
        foreach (['yield2y', 'yield5y', 'yield10y', 'yield30y'] as $tenor) {
            $this->assertEqualsWithDelta(0.06 + ($opening->$tenor - $opening->policyRate), $dto->$tenor, 1e-12, $tenor);
        }
        $this->assertGreaterThan($dto->yield2y, $dto->yield30y, 'The rebuilt curve must slope upward.');
    }

    public function testRecordedCurveIsNeverOverwrittenByTheRebuiltOne(): void
    {
        $dto = MacroStateDTO::fromArray([
            'policy_rate' => 0.06,
            'yield_2y' => 0.011,
            'yield_5y' => 0.022,
            'yield_10y' => 0.033,
            'yield_30y' => 0.044,
        ]);

        $this->assertSame(0.011, $dto->yield2y);
        $this->assertSame(0.022, $dto->yield5y);
        $this->assertSame(0.033, $dto->yield10y);
        $this->assertSame(0.044, $dto->yield30y);
    }

    /**
     * The smoothed 5y was recorded under the macro_report column spelling before the wire key
     * existed. A payload carrying both is a database row, and the column spelling is what it means.
     */
    public function testDatabaseColumnSpellingWinsForTheSmoothedFiveYear(): void
    {
        $this->assertSame(0.0631, MacroStateDTO::fromArray(['yield5y_ema' => 0.0631])->yield5yEma);

        $both = MacroStateDTO::fromArray(['yield5y_ema' => 0.0631, 'yield_5y_ema' => 0.0777]);
        $this->assertSame(0.0631, $both->yield5yEma);
    }

    /**
     * Balance sheet intensity was recorded as a one-sided qe_intensity before QT existed, so a
     * pre-QT payload must still resolve both sides of the balance sheet.
     */
    public function testLegacyQeIntensityPayloadResolvesBothBalanceSheetSides(): void
    {
        $easing = MacroStateDTO::fromArray(['qe_intensity' => 0.004]);
        $this->assertSame(0.004, $easing->balanceSheetIntensity);
        $this->assertTrue($easing->qeActive);
        $this->assertFalse($easing->qtActive);
        $this->assertSame(0.004, $easing->qeIntensity);
        $this->assertSame(0.0, $easing->qtIntensity);

        $tightening = MacroStateDTO::fromArray(['balance_sheet_intensity' => -0.003]);
        $this->assertFalse($tightening->qeActive);
        $this->assertTrue($tightening->qtActive);
        $this->assertSame(0.0, $tightening->qeIntensity);
        $this->assertSame(0.003, $tightening->qtIntensity);
    }

    public function testBalanceSheetInsideTheThresholdIsNeitherEasingNorTightening(): void
    {
        $flat = MacroStateDTO::fromArray([
            'balance_sheet_intensity' => MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD / 2,
        ]);

        $this->assertFalse($flat->qeActive);
        $this->assertFalse($flat->qtActive);
    }

    public function testPotentialOutputIsBackedOutOfNominalGdpAndTheGap(): void
    {
        $dto = MacroStateDTO::fromArray(['nominal_gdp_index' => 1.2, 'output_gap' => 0.02]);

        $this->assertEqualsWithDelta(1.2 / 1.02, $dto->potentialGdpIndex, 1e-12);
    }

    public function testShortEndCurveFactorIsTheSpreadOfPolicyOverTheLevel(): void
    {
        $dto = MacroStateDTO::fromArray(['policy_rate' => 0.05, 'ns_level' => 0.03]);

        $this->assertEqualsWithDelta(0.02, $dto->nsBeta1, 1e-12);
    }

    public function testHighYieldSpreadOpensAsAMultipleOfTheInvestmentGradeSpread(): void
    {
        $dto = MacroStateDTO::fromArray(['macro_credit_spread' => 0.02]);

        $this->assertEqualsWithDelta(0.02 * MacroEngine::HY_BASE_SPREAD_MULTIPLIER, $dto->highYieldCreditSpread, 1e-12);
        $this->assertEqualsWithDelta(
            $dto->highYieldCreditSpread,
            $dto->highYieldCreditSpreadEma,
            1e-12,
            'A smoothed spread with no history opens level with the spread it smooths.'
        );
    }

    /**
     * Seeding runs in constructor order, so a series seeded from one that is itself seeded has to
     * resolve in the same pass rather than picking up an opening value of zero.
     */
    public function testChainedSeedsResolveInOnePass(): void
    {
        $dto = MacroStateDTO::fromArray(['inflation' => 0.055]);

        $this->assertSame(0.055, $dto->inflationEma);
        $this->assertSame(0.055, $dto->supercoreInflation);
        $this->assertSame(0.055, $dto->supercoreInflationEma, 'Seeded from a field that is itself seeded.');
        $this->assertSame(0.055, $dto->coreGoodsInflation);
        $this->assertSame(0.055, $dto->coreGoodsInflationEma);
        $this->assertSame(0.055, $dto->tipsBreakeven);
        $this->assertSame(0.055, $dto->tipsBreakevenEma);
    }

    public function testRecordedSmoothedSeriesIsNotOverwrittenByItsSeed(): void
    {
        $dto = MacroStateDTO::fromArray(['inflation' => 0.055, 'inflation_ema' => 0.021]);

        $this->assertSame(0.021, $dto->inflationEma);
    }

    public function testNonScalarFieldsSurviveTheWire(): void
    {
        $dto = MacroStateDTO::fromArray([
            'sector_z' => ['TECH' => 1.5, 'BANK' => '-0.25'],
            'sector_demand_z' => ['REIT' => 0.75],
            'event_type' => 'CRISIS',
        ]);

        $this->assertSame(['TECH' => 1.5, 'BANK' => -0.25], $dto->sectorZ, 'Sector draws are cast to floats.');
        $this->assertSame(['REIT' => 0.75], $dto->sectorDemandZ);
        $this->assertSame('CRISIS', $dto->eventType);
    }

    public function testMalformedSectorDrawsHydrateEmptyRatherThanFatal(): void
    {
        $dto = MacroStateDTO::fromArray(['sector_z' => 'not-an-array']);

        $this->assertSame([], $dto->sectorZ);
    }

    /** A fresh engine, an empty snapshot and a DTO built with no arguments are one economy. */
    public function testEveryReaderOpensOnTheSameEconomy(): void
    {
        $opening = (new MacroStateDTO())->toArray();

        $this->assertSame($opening, MacroStateDTO::fromArray([])->toArray());
        $this->assertSame($opening, (new MacroState())->toArray());
        $this->assertSame($opening, MacroState::fromArray([])->toArray());
    }

    /** The opening curve is the curve the engine fits at the opening state, so the first tick does not reprice it. */
    public function testTheOpeningCurveIsTheEnginesOwnCurveAtTheOpeningState(): void
    {
        $state = new MacroState();
        $curve = (new MonetaryPolicySubsystem(new MathUtility()))->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, $state->naturalRate);

        // Openings are declared to the basis point.
        foreach (['yield_2y' => 'yield2y', 'yield_5y' => 'yield5y', 'yield_10y' => 'yield10y', 'yield_30y' => 'yield30y',
                  'level' => 'nsLevel', 'beta1' => 'nsBeta1', 'curvature' => 'nsCurvature', 'curvature2' => 'nsCurvature2',
                  'base_term_premium' => 'nsBaseTermPremium', 'term_premium_10y' => 'termPremium10y', 'risk_neutral_10y' => 'riskNeutral10y'] as $key => $field) {
            $this->assertEqualsWithDelta($curve[$key], $state->$field, 0.00006, $field);
        }
    }

    /**
     * The engine's state and the snapshot are hydrated by separate readers, so the same payload has
     * to mean the same thing to both.
     */
    public function testEngineStateAndSnapshotAgreeOnTheSamePayload(): void
    {
        $payload = [
            'policy_rate' => 0.055,
            'inflation' => 0.031,
            'macro_credit_spread' => 0.018,
            'ns_level' => 0.041,
            'nominal_gdp_index' => 1.35,
            'qe_intensity' => 0.002,
        ];

        $viaState = MacroStateDTO::fromMacroState(MacroState::fromArray($payload));
        $viaSnapshot = MacroStateDTO::fromArray($payload);

        foreach (['policyRate', 'inflation', 'inflationEma', 'macroCreditSpread', 'highYieldCreditSpread',
                  'nsBeta1', 'qeActive', 'qeIntensity', 'qtIntensity', 'balanceSheetIntensity'] as $field) {
            $this->assertSame($viaState->$field, $viaSnapshot->$field, "Readers disagree on {$field}.");
        }
    }

    /**
     * Two identities tie the curve's stored factors to the tenors they generate, and a reader that
     * breaks one reports a curve that cannot exist.
     *
     * The snapshot broke all three on an empty payload: the rebuild above gave the tenors a policy-rate
     * anchor but never seeded the Nelson-Siegel level, so the long end decayed to zero -- the flat zero
     * curve the rebuild exists to prevent -- and beta1, which is derived from the level, came out at
     * +0.02 when the short end sits BELOW the long end and it has to be negative. The engine's own
     * openings broke the slope the other way, reporting a 150bp inversion over tenors sloping upward.
     *
     * @return array<string, array{callable(): object}>
     */
    public static function curveReaderProvider(): array
    {
        return [
            'snapshot opening' => [static fn (): object => MacroStateDTO::fromArray([])],
            'engine opening'   => [static fn (): object => new MacroState()],
        ];
    }

    /**
     * Sentiment is constructed by subtracting one-sided penalties from SENTIMENT_BASELINE, so that constant
     * is the series' ceiling, not its mean, and an opening taken from it describes a boom. Both readers
     * open on the measured resting level instead.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('curveReaderProvider')]
    public function testSentimentOpensAtItsTrendLevelAndNotAtTheCeilingItIsBuiltFrom(callable $open): void
    {
        $reader = $open();

        $this->assertSame(MacroEngine::SENTIMENT_TREND_LEVEL, $reader->consumerSentimentIndex);
        $this->assertSame(MacroEngine::SENTIMENT_TREND_LEVEL, $reader->consumerSentimentIndexEma);
        $this->assertLessThan(
            MacroEngine::SENTIMENT_BASELINE,
            $reader->consumerSentimentIndex,
            'The baseline is what the index is built down from, so no neutral opening may sit at or above it.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('curveReaderProvider')]
    public function testTheOpeningCurveSatisfiesItsOwnIdentities(callable $open): void
    {
        $curve = $open();

        $this->assertEqualsWithDelta(
            $curve->policyRate - $curve->nsLevel,
            $curve->nsBeta1,
            1e-12,
            'beta1 is the policy rate under the long-run level, not a free parameter.'
        );
        $this->assertEqualsWithDelta(
            $curve->yield10y - $curve->policyRate,
            $curve->nsSlope,
            1e-12,
            'The reported slope is the 10y-over-policy term spread.'
        );
        $this->assertGreaterThan(0.0, $curve->nsLevel, 'A long end at zero is a degenerate curve.');
        $this->assertLessThan(0.0, $curve->nsBeta1, 'An upward curve carries a negative beta1.');
        $this->assertGreaterThan($curve->policyRate, $curve->yield30y, 'The opening curve slopes upward.');
    }
}

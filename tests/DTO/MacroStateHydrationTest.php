<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
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

        $this->assertSame(0.06, $dto->yield2y, 'A 2y with no reading opens at the policy rate.');
        $this->assertEqualsWithDelta(0.065, $dto->yield5y, 1e-12);
        $this->assertEqualsWithDelta(0.070, $dto->yield10y, 1e-12);
        $this->assertEqualsWithDelta(0.075, $dto->yield30y, 1e-12);
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
}

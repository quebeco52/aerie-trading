<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Subsystem;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/** The Financial Regulator's mortgage loan-to-value cap in the household credit cycle (Iacoviello 2005; Richter, Schularick & Shim 2019). */
class MortgageLtvCapTest extends TestCase
{
    private CreditFiscalSubsystem $subsystem;

    protected function setUp(): void
    {
        $math = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };
        $this->subsystem = new CreditFiscalSubsystem($math);
    }

    /** Household rates at the engine's neutral, so leverage holds at its baseline unless the term under test moves it. */
    private static function household(?float $cap = null): MacroState
    {
        $state = new MacroState();
        $state->yield10yEma = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + MacroEngine::NS_BASE_TERM_PREMIUM;
        $state->policyRateEma = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $state->outputGapEma = 0.0;
        $state->householdDebtServiceTrend = MacroEngine::HOUSEHOLD_DSR_NEUTRAL;
        $state->retailDefaultRate = MacroEngine::RETAIL_DEFAULT_BASELINE;
        $state->mortgageLtvCap = $cap;

        return $state;
    }

    private function advance(MacroState $state, float $years, int $ticksPerYear): MacroState
    {
        $dt = 1.0 / $ticksPerYear;
        for ($i = 0, $n = (int) round($years * $ticksPerYear); $i < $n; ++$i) {
            $this->subsystem->calculateHouseholdCredit($state, $dt);
        }

        return $state;
    }

    /** No cap, or one above every loan, holds nothing; a 100% cap binds only the few loans above it. */
    public function testASlackOrAbsentCapHoldsNothing(): void
    {
        $this->assertSame(0.0, CreditFiscalSubsystem::mortgageLtvCreditCut(null));
        $this->assertSame(0.0, CreditFiscalSubsystem::mortgageLtvCreditCut(1.02));
        $this->assertSame(0.0, CreditFiscalSubsystem::mortgageLtvCreditCut(1.10));
        $this->assertLessThan(0.001, CreditFiscalSubsystem::mortgageLtvCreditCut(1.00));

        $free = $this->advance(self::household(), 3.0, 52);
        $this->assertEqualsWithDelta(MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE, $free->householdDebtToIncome, 1e-12);
        $this->assertSame(0.0, $free->ltvCutBuilt);
    }

    /**
     * The cut is the mortgage share of the part of NMDB's purchase-loan distribution above the cap over its mean: 2.77pp
     * of 82.2% above 90%, 5.13pp above 85%, 7.82pp above 80%; and each point of tightening cuts more than the last.
     */
    public function testTheCutPinsTheMortgageDistribution(): void
    {
        $share = CreditFiscalSubsystem::HOUSEHOLD_MORTGAGE_DEBT_SHARE;
        $this->assertEqualsWithDelta(-log(1.0 - ($share * 2.77 / 82.2)), CreditFiscalSubsystem::mortgageLtvCreditCut(0.90), 3e-4);
        $this->assertEqualsWithDelta(-log(1.0 - ($share * 5.13 / 82.2)), CreditFiscalSubsystem::mortgageLtvCreditCut(0.85), 3e-4);
        $this->assertEqualsWithDelta(-log(1.0 - ($share * 7.82 / 82.2)), CreditFiscalSubsystem::mortgageLtvCreditCut(0.80), 3e-4);

        $previousCut = 0.0;
        $previousStep = 0.0;
        foreach ([1.00, 0.95, 0.90, 0.85, 0.80, 0.75, 0.70] as $cap) {
            $cut = CreditFiscalSubsystem::mortgageLtvCreditCut($cap);
            $this->assertGreaterThan($previousCut, $cut, "tighter at {$cap}");
            $this->assertGreaterThan($previousStep, $cut - $previousCut, "convex at {$cap}");
            $previousStep = $cut - $previousCut;
            $previousCut = $cut;
        }
    }

    /** A tightened cap is built into the stock over a one-year time constant, the same at any tick rate. */
    public function testATighterCapBuildsAtTheSamePaceAtAnyTickRate(): void
    {
        $cut = CreditFiscalSubsystem::mortgageLtvCreditCut(0.85);
        foreach ([12, 52, 252] as $ticksPerYear) {
            $state = $this->advance(self::household(0.85), 1.0, $ticksPerYear);
            $built = $cut * (1.0 - exp(-1.0 / CreditFiscalSubsystem::MORTGAGE_LTV_CUT_YEARS));
            $this->assertEqualsWithDelta($built, $state->ltvCutBuilt, 1e-12, "{$ticksPerYear} ticks a year");
            $this->assertEqualsWithDelta(-$built, log($state->householdDebtToIncome / MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE), 1e-12, 'The stock falls by what is built, and reversion does not fight it.');
        }
    }

    /** Held for thirty years, the cap holds leverage at its full cut: reversion pulls toward the baseline the cap allows. */
    public function testTheCutHoldsAgainstReversion(): void
    {
        $state = $this->advance(self::household(0.85), 30.0, 4);

        $this->assertEqualsWithDelta(-CreditFiscalSubsystem::mortgageLtvCreditCut(0.85), log($state->householdDebtToIncome / MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE), 1e-9);
    }

    /**
     * A loosened cap lifts the constraint at once but lends nothing on the day: borrowing rises only at the fitted
     * reversion speed, ~7% of the gap in the first year against the 63% a tightening builds.
     */
    public function testALooserCapLendsBackOnlyAsHouseholdsReLever(): void
    {
        $cut = CreditFiscalSubsystem::mortgageLtvCreditCut(0.85);
        $state = $this->advance(self::household(0.85), 30.0, 4);
        $held = $state->householdDebtToIncome;

        $state->mortgageLtvCap = null;
        $this->subsystem->calculateHouseholdCredit($state, 1.0 / 252.0);
        $this->assertSame(0.0, $state->ltvCutBuilt, 'The constraint is lifted at once.');
        $this->assertSame($held, $state->householdDebtToIncome, 'Nothing is lent back on the day.');

        $this->advance($state, 1.0 - (1.0 / 252.0), 252);
        $recovered = log($state->householdDebtToIncome / $held) / $cut;
        $this->assertEqualsWithDelta(1.0 - exp(-CreditFiscalSubsystem::CREDIT_MEAN_REVERSION), $recovered, 5e-3);
        $this->assertLessThan(0.1, $recovered);
    }

    /** The cap binds borrowers, not lenders: it moves neither the capital banks must hold nor their lending standards. */
    public function testTheCapIsNotLenderCapital(): void
    {
        $free = $this->advance(self::household(), 1.0, 52);
        $capped = $this->advance(self::household(0.80), 1.0, 52);

        $this->assertSame($free->bankCapitalRequiredLast, $capped->bankCapitalRequiredLast);
        $this->assertSame($free->bankCapitalBuilt, $capped->bankCapitalBuilt);
        $this->subsystem->calculateSloosCreditStandards($free, 1.0 / 52.0);
        $this->subsystem->calculateSloosCreditStandards($capped, 1.0 / 52.0);
        $this->assertSame($free->sloosTighteningIndex, $capped->sloosTighteningIndex);
        $this->assertLessThan($free->householdDebtToIncome, $capped->householdDebtToIncome);
    }

    /** A payload that predates the cap reads as none, with nothing built; a cap in force survives the round trip. */
    public function testThePayloadCarriesTheCap(): void
    {
        $this->assertNull(MacroState::fromArray([])->mortgageLtvCap);
        $this->assertSame(0.0, MacroState::fromArray([])->ltvCutBuilt);
        $this->assertNull(MacroStateDTO::fromArray([])->mortgageLtvCap);

        $state = self::household(0.85);
        $state->ltvCutBuilt = 0.02;
        $decoded = json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0.85, MacroState::fromArray($decoded)->mortgageLtvCap);
        $this->assertSame(0.02, MacroState::fromArray($decoded)->ltvCutBuilt);
        $this->assertSame(0.85, MacroStateDTO::fromArray($decoded)->mortgageLtvCap);
    }
}

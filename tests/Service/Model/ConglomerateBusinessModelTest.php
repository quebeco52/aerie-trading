<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConglomerateBusinessModel;
use PHPUnit\Framework\TestCase;

class ConglomerateBusinessModelTest extends TestCase
{
    private ConglomerateBusinessModel $model;
    private MathUtility $mathUtility;

    /** The short rate a calm economy sits at: the neutral rate the MacroStateDTO itself perceives. */
    private const NEUTRAL_POLICY_RATE = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;

    protected function setUp(): void
    {
        $this->model = new ConglomerateBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    /**
     * A macro state with every series this model reads sitting exactly on its baseline. Anything a test
     * varies from here is the only thing moving, which is what lets the assertions below be exact.
     */
    private function neutralMacro(): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
        );
    }

    /**
     * Real financial mathematics with the random draws pinned to zero. Every assertion below about the COST
     * base is worth a few tenths of a point, and the +-2.3 sigma portfolio event is worth several, so a live
     * RNG would flip these comparisons about one run in fifty. The HHI, the lag and the saturation curves
     * stay real, which is the half these tests are actually about.
     */
    private function deterministicMath(): MathUtility
    {
        return new class extends MathUtility {
            public function generatePersistentZ(float $previousZ, float $phi, ?float $commonInnovation = null, float $commonLoading = 0.0): float
            {
                return 0.0;
            }

            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
    }

    private function stock(string $ticker, string $beta): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setBeta($beta);

        return $stock;
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = $this->stock('GEN_CONGLOMERATE', '0.8');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 15_000_000.0,
            baselineVol: 0.08,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('industrial_manufacturing', $result->streamRevenue);
        $this->assertArrayHasKey('defensive_staples', $result->streamRevenue);
        $this->assertArrayHasKey('financial_investments', $result->streamRevenue);

        $this->assertArrayHasKey('industrial_manufacturing', $result->streamZ);
        $this->assertArrayHasKey('defensive_staples', $result->streamZ);
        $this->assertArrayHasKey('financial_investments', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['industrial_manufacturing']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['defensive_staples']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['financial_investments']);

        $sumStreams = $result->streamRevenue['industrial_manufacturing']
            + $result->streamRevenue['defensive_staples']
            + $result->streamRevenue['financial_investments'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    /**
     * A stream a firm does not run must not be reported, drawn, or costed. This is what lets one class
     * price several different holding companies: the portfolio a conglomerate declares is the portfolio it
     * is priced on. A portfolio this model has no stream for at all belongs to a sub-model instead — see
     * MerchantHouseBusinessModelTest.
     */
    public function testDormantStreamsAreNeitherDrawnNorReported(): void
    {
        $result = $this->model->computeActualFinancials(
            $this->stock('TRIV', '0.6'),
            100_000_000.0,
            0.20,
            10_000_000.0,
            0.05,
            $this->neutralMacro(),
            $this->deterministicMath()
        );

        $this->assertArrayNotHasKey('merchant_trading', $result->streamRevenue);
        $this->assertArrayNotHasKey('merchant_trading', $result->streamZ);
        $this->assertArrayHasKey('industrial_manufacturing', $result->streamRevenue);

        // ...and conversely, a firm that holds no float by policy reports no float segment.
        $harr = $this->model->computeActualFinancials(
            $this->stock('HARR', '0.65'),
            100_000_000.0,
            0.64,
            10_000_000.0,
            0.05,
            $this->neutralMacro(),
            $this->deterministicMath()
        );

        $this->assertArrayNotHasKey('financial_investments', $harr->streamRevenue);
        $this->assertArrayHasKey('defensive_staples', $harr->streamRevenue);
    }

    public function testSustainedCreditBlowoutSurgesContrarianFloat(): void
    {
        $stock = $this->stock('BRKW', '0.4');

        // Spot and trend spreads agree: the blowout has plateaued, so carry is earned with no further repricing.
        $calmMacro = $this->neutralMacro();
        $blowoutMacro = new MacroStateDTO(
            outputGapEma: -0.04,
            macroCreditSpread: 0.045,
            macroCreditSpreadEma: 0.045,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
        );

        $calm = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $calmMacro, $this->mathUtility);
        $blowout = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $blowoutMacro, $this->mathUtility);

        $this->assertGreaterThan(
            $calm->streamRevenue['financial_investments'],
            $blowout->streamRevenue['financial_investments']
        );
    }

    public function testSpreadWideningImpulseMarksFloatBookDown(): void
    {
        $stock = $this->stock('BRKW', '0.4');

        $calmMacro = $this->neutralMacro();
        // Spot spread gaps 320bps above trend: the held credit book reprices downward before any carry is earned.
        $wideningMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: 0.045,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
        );

        $calm = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $calmMacro, $this->mathUtility);
        $widening = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $wideningMacro, $this->mathUtility);

        $this->assertLessThan(
            $calm->streamRevenue['financial_investments'],
            $widening->streamRevenue['financial_investments']
        );
    }

    public function testCorporateDefaultsErodeContrarianFloatCarry(): void
    {
        $stock = $this->stock('BRKW', '0.4');

        $cleanBlowout = new MacroStateDTO(
            outputGapEma: -0.04,
            macroCreditSpread: 0.045,
            macroCreditSpreadEma: 0.045,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
        );
        // Same spread level, but the spread is now compensating for a genuine default wave.
        $defaultWave = new MacroStateDTO(
            outputGapEma: -0.04,
            macroCreditSpread: 0.045,
            macroCreditSpreadEma: 0.045,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
            corporateDefaultRate: 0.090,
            corporateDefaultRateEma: 0.090,
        );

        $clean = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $cleanBlowout, $this->mathUtility);
        $losses = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $defaultWave, $this->mathUtility);

        $this->assertLessThan(
            $clean->streamRevenue['financial_investments'],
            $losses->streamRevenue['financial_investments']
        );
    }

    public function testBaselineCreditSpreadYieldsNoContrarianAlpha(): void
    {
        $stock = $this->stock('TRIV', '0.6');

        $result = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 10_000_000.0, 0.0, $this->neutralMacro(), $this->mathUtility);

        // TRIV holds 10% float; a calm economy at a neutral short rate must not book a standing bonus on it.
        $this->assertEqualsWithDelta(10_000_000.0, $result->streamRevenue['financial_investments'], 1.0);
    }

    /**
     * Dry powder is not free money. A treasury float is cash and short sovereign paper, so it earns the
     * front rate: an asset when policy is restrictive and an idle drag through a zero-rate decade. The model
     * used to produce identical float revenue at 0% and at 6%, which made a holding company's hoard
     * costless to carry and its patience free.
     */
    public function testFloatCarryTracksThePolicyRateGapFromNeutral(): void
    {
        $stock = $this->stock('BRKW', '0.4');

        $zirp = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: 0.0,
        );
        $restrictive = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: 0.055,
        );

        $neutral = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $atZero = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $zirp, $this->deterministicMath());
        $tight = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $restrictive, $this->deterministicMath());

        $this->assertLessThan($neutral->streamRevenue['financial_investments'], $atZero->streamRevenue['financial_investments']);
        $this->assertGreaterThan($neutral->streamRevenue['financial_investments'], $tight->streamRevenue['financial_investments']);
    }

    public function testInflationPassesThroughToNominalRevenueBase(): void
    {
        $stock = $this->stock('BRKW', '0.4');

        $stable = $this->model->getMacroPhysics($stock, new MacroStateDTO(tipsBreakevenEma: 0.02));
        $inflationary = $this->model->getMacroPhysics($stock, new MacroStateDTO(tipsBreakevenEma: 0.06));

        // Cyclicality is handled per-stream, so the blended demand shift stays neutralized.
        $this->assertSame(0.0, $stable['macro_demand_shift']);

        // Tollbooth escalators and staples list-price resets must reprice output, not just absorb cost inflation.
        $this->assertGreaterThan(1.0, $stable['pricing_power_multiplier']);
        $this->assertGreaterThan($stable['pricing_power_multiplier'], $inflationary['pricing_power_multiplier']);
    }

    public function testPortfolioMixResolutionAcrossEveryConglomerateArchetype(): void
    {
        $macro = $this->neutralMacro();

        // Declared in the order the model reports its segments, so the assertion also pins the fact that a
        // dormant stream leaves no hole in the segment table.
        $expected = [
            // TRIV: 60% Industrial, 30% Defensive, 10% Float
            'TRIV' => ['industrial_manufacturing' => 60.0, 'defensive_staples' => 30.0, 'financial_investments' => 10.0],
            // BRKW: 50% Industrial, 15% Defensive, 35% Float
            'BRKW' => ['industrial_manufacturing' => 50.0, 'defensive_staples' => 15.0, 'financial_investments' => 35.0],
            // HARR: 30% Industrial hardware, 70% Niche instrumentation — and no float, by policy
            'HARR' => ['industrial_manufacturing' => 30.0, 'defensive_staples' => 70.0],
        ];

        foreach ($expected as $ticker => $mix) {
            $result = $this->model->computeActualFinancials($this->stock($ticker, '0.6'), 100_000_000.0, 0.20, 10_000_000.0, 0.0, $macro, $this->deterministicMath());

            $this->assertSame(array_keys($mix), array_keys($result->streamRevenue), sprintf('%s reports the wrong segments.', $ticker));
            foreach ($mix as $stream => $millions) {
                $this->assertEqualsWithDelta($millions * 1_000_000.0, $result->streamRevenue[$stream], 1.0, sprintf('%s %s', $ticker, $stream));
            }
        }
    }
}

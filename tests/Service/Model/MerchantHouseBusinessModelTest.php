<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\MerchantHouseBusinessModel;
use PHPUnit\Framework\TestCase;

class MerchantHouseBusinessModelTest extends TestCase
{
    private MerchantHouseBusinessModel $model;

    /** The short rate a calm economy sits at: the neutral rate the MacroStateDTO itself perceives. */
    private const NEUTRAL_POLICY_RATE = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;

    protected function setUp(): void
    {
        $this->model = new MerchantHouseBusinessModel();
    }

    /**
     * Real financial mathematics with the random draws pinned to zero. Every assertion below about the COST
     * base is worth a few tenths of a point and the -2.3 sigma writedown is worth several, so a live RNG
     * would flip these comparisons about one run in a hundred. The convenience yield and the lags stay real,
     * which is the half these tests are about.
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

    /** Every series this model reads sitting exactly on its baseline. */
    private function neutralMacro(): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
        );
    }

    private function ravn(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('RAVN');
        $stock->setBeta('0.75');

        return $stock;
    }

    /**
     * A merchant house manufactures nothing, so the parent's industrial stream must not appear on it. This
     * is the whole reason the sub-model exists rather than a zero weight on the conglomerate.
     */
    public function testReportsAMerchantPortfolioAndNoFactory(): void
    {
        $result = $this->model->computeActualFinancials(
            $this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $this->assertSame(
            ['merchant_trading', 'defensive_staples', 'financial_investments'],
            array_keys($result->streamRevenue)
        );

        $this->assertEqualsWithDelta(55_000_000.0, $result->streamRevenue['merchant_trading'], 1.0);
        $this->assertEqualsWithDelta(30_000_000.0, $result->streamRevenue['defensive_staples'], 1.0);
        $this->assertEqualsWithDelta(15_000_000.0, $result->streamRevenue['financial_investments'], 1.0);

        // A calm quarter charges nothing beyond the cost base it was handed.
        $this->assertEqualsWithDelta(0.80, $result->clampedMargin, 1e-9);
    }

    /**
     * A merchant's top line is the market value of the tonnage it moved, so a commodity bull market inflates
     * revenue with no extra barrel handled — and the per-unit spread does not inflate with it, so the cost
     * RATIO rises by exactly p(1 - c) / (1 + p) while the dollar gross profit is untouched. Reported margin
     * collapses; the desk earns precisely what it earned before.
     *
     * Struck on agricultural prices alone, because agri is the one tracked commodity absent from
     * INPUT_COST_EXPOSURES — a metals or energy boom also (legitimately) squeezes the terminal estate's
     * overhead basket, which would hide the identity this test exists to pin.
     */
    public function testCommodityBoomInflatesTurnoverAndPreservesTheDollarSpread(): void
    {
        $boom = new MacroStateDTO(
            outputGapEma: 0.0,
            agriculturalCommodityIndexEma: 140.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
        );

        $calm = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $hot = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $boom, $this->deterministicMath());

        // Agri 40% over baseline at a 0.35 book share and a 0.90 pass-through: turnover up 12.6%.
        $priceLift = 0.40 * MerchantHouseBusinessModel::MERCHANT_BOOK_EXPOSURES['agri'] * MerchantHouseBusinessModel::MERCHANT_PRICE_PASS_THROUGH;
        $this->assertEqualsWithDelta($calm->streamRevenue['merchant_trading'] * (1.0 + $priceLift), $hot->streamRevenue['merchant_trading'], 1.0);

        // The spread did not inflate with the cargo, so the group's cost ratio rises...
        $this->assertGreaterThan($calm->clampedMargin, $hot->clampedMargin);

        // ...and the dollar profit is exactly preserved. A merchant's collapsing margin percentage in a
        // boom is an accounting artefact of the denominator, not a worse year.
        $this->assertEqualsWithDelta($calm->ebit, $hot->ebit, 1.0);
    }

    /**
     * Volume is not price. Shipping more tonnage scales revenue and cost of goods together, so throughput
     * growth must lift revenue while leaving the cost ratio exactly where it was — the distinction the
     * spread-dilution identity depends on.
     */
    public function testThroughputGrowthLiftsRevenueWithoutDilutingTheSpread(): void
    {
        $busy = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
            manufacturingPmiEma: 57.0,
        );

        $calm = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $moving = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $busy, $this->deterministicMath());

        $this->assertGreaterThan($calm->streamRevenue['merchant_trading'], $moving->streamRevenue['merchant_trading']);
        $this->assertEqualsWithDelta($calm->clampedMargin, $moving->clampedMargin, 1e-9);
    }

    /**
     * Scarcity is the merchant's margin. Depleted physical inventories (Kaldor-Working backwardation) and
     * congested corridors widen the spread for whoever owns the berth and the bonded warehouse.
     */
    public function testPhysicalDislocationWidensTheSpread(): void
    {
        $dislocated = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
            energyInventoryIndexEma: 72.0,
            supplyChainPressureIndexEma: 2.20,
        );

        $calm = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $squeeze = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $dislocated, $this->deterministicMath());

        $this->assertLessThan($calm->clampedMargin, $squeeze->clampedMargin);
    }

    /**
     * Cargo and bills of lading are carried on short wholesale credit for about a quarter, so the funding
     * rate is a direct charge on turnover. On a thin trading spread this is the leg that ends merchant
     * houses, and a firm that never felt it would be carrying inventory for free.
     */
    public function testFundingCostSqueezesTheWorkingCapitalBook(): void
    {
        $expensiveFunding = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: 0.040,
            macroCreditSpreadEma: 0.040,
            policyRateEma: 0.070,
        );

        $calm = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $squeezed = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $expensiveFunding, $this->deterministicMath());

        $this->assertGreaterThan($calm->clampedMargin, $squeezed->clampedMargin);
    }

    /**
     * The trade-credit ledger is the parent's contrarian float book, not a variant of it, so it must still
     * earn the front rate. This is the inherited half of the sub-model and is worth pinning: an override
     * that stopped calling it would be silent.
     */
    public function testTradeCreditBookStillEarnsTheShortRate(): void
    {
        $zirp = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: 0.0,
        );

        $calm = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $atZero = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $zirp, $this->deterministicMath());

        $this->assertLessThan($calm->streamRevenue['financial_investments'], $atZero->streamRevenue['financial_investments']);
    }
}

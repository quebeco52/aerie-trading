<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\InitialMarket;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\MerchantHouseBusinessModel;
use App\Tests\Support\FloatBookCreditDrivers;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class MerchantHouseBusinessModelTest extends TestCase
{
    private MerchantHouseBusinessModel $model;

    /** The short rate a calm economy sits at: the neutral rate the MacroStateDTO itself perceives. */
    private const NEUTRAL_POLICY_RATE = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;

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
        return new ScriptedDraws(generatePersistentZ: 0.0);
    }

    /** Every series this model reads sitting exactly on its baseline. */
    private function neutralMacro(): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
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
            $this->ravn(),
            100_000_000.0,
            0.80,
            10_000_000.0,
            0.0,
            $this->neutralMacro(),
            $this->deterministicMath(),
        );

        $this->assertSame(
            ['merchant_trading', 'defensive_staples', 'financial_investments'],
            array_keys($result->streamRevenue),
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
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
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
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
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
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
            energyInventoryIndexEma: 72.0,
            supplyChainPressureIndexEma: 2.20,
        );

        $calm = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $squeeze = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $dislocated, $this->deterministicMath());

        $this->assertLessThan($calm->clampedMargin, $squeeze->clampedMargin);
    }

    /**
     * Cargo is financed on the group's floating debt, so a funding squeeze reaches RAVN once: an interbank freeze
     * raises its interest by the premium on that debt, as DebtEngine charges it, and leaves its revenue and its cost
     * of goods where they were.
     */
    public function testAFundingSqueezeReachesTheCargoOnceThroughInterest(): void
    {
        $premium = 0.0102;
        $calmMacro = new MacroStateDTO();
        $freeze = new MacroStateDTO(interbankLiquiditySpreadEma: $calmMacro->interbankLiquiditySpreadEma + $premium);
        $ravn = $this->openedRavn();

        $calm = $this->model->computeActualFinancials($ravn, 100_000_000.0, 0.80, 10_000_000.0, 0.0, $calmMacro, $this->deterministicMath());
        $frozen = $this->model->computeActualFinancials($ravn, 100_000_000.0, 0.80, 10_000_000.0, 0.0, $freeze, $this->deterministicMath());

        $this->assertEqualsWithDelta($calm->actualRevenue, $frozen->actualRevenue, 1e-6);
        $this->assertEqualsWithDelta($calm->clampedMargin, $frozen->clampedMargin, 1e-12, 'The cargo\'s funding is not in its cost of goods.');

        $debtEngine = new DebtEngine(MathUtility::ownStream(20261024), CorporateMetrics::getInstance());
        $interest = static fn(MacroStateDTO $macro): float => $debtEngine->calculateInterestExpense($ravn, $macro)->interestExpense;
        $floatingDebt = (float) $ravn->getTotalDebt() * (float) $ravn->getFloatingDebtRatio();

        $this->assertGreaterThan(0.0, $floatingDebt);
        $this->assertEqualsWithDelta($floatingDebt * $premium, $interest($freeze) - $interest($calmMacro), 1.0, 'It is charged on the floating debt.');
    }

    /**
     * The floating debt is what finances the cargo, so the seed sizes it to the working capital the group opens with:
     * receivables and inventory less what its suppliers carry, within a tenth.
     */
    public function testItsFloatingDebtIsTheCargoItFinances(): void
    {
        $ravn = $this->openedRavn();
        $floatingDebt = (float) $ravn->getTotalDebt() * (float) $ravn->getFloatingDebtRatio();
        $workingCapital = (float) $ravn->getNetWorkingCapital();

        $this->assertGreaterThan(0.0, $workingCapital);
        $this->assertEqualsWithDelta(1.0, $floatingDebt / $workingCapital, 0.10, sprintf('%.1f bn floating against %.1f bn of cargo.', $floatingDebt / 1e9, $workingCapital / 1e9));
    }

    /** RAVN as the seed opens it. */
    private function openedRavn(): Stock
    {
        $seed = null;
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === 'RAVN') {
                $seed = $row;
            }
        }
        $this->assertNotNull($seed);

        $math = MathUtility::ownStream(20261024);
        $debtEngine = new DebtEngine($math, CorporateMetrics::getInstance());
        $stock = (new Stock())->setTicker('RAVN');
        (new OpeningBoardBuilder($math, $debtEngine, new MarketEngine($math), new AnchorStakeLedger()))->open($stock, $seed, new MacroStateDTO());

        return $stock;
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
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
            policyRateEma: 0.0,
        );

        $calm = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath());
        $atZero = $this->model->computeActualFinancials($this->ravn(), 100_000_000.0, 0.80, 10_000_000.0, 0.0, $zirp, $this->deterministicMath());

        $this->assertLessThan($calm->streamRevenue['financial_investments'], $atZero->streamRevenue['financial_investments']);
    }

    public function testTheCostBaseFollowsTonnageAndTerminalTrafficNotCargoPrices(): void
    {
        $stock = new Stock();
        $stock->setTicker('MERC_TEST');
        $recession = new MacroStateDTO(
            outputGapEma: -0.03,
            foreignOutputGapEma: 0.0,
            manufacturingPmiEma: MacroConstants::PMI_BASELINE,
            tradeBalanceToGdpEma: MacroConstants::TRADE_BALANCE_BASELINE,
            exchangeRateIndexEma: 100.0,
        );

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // A domestic gap alone reaches only the terminals: 0.30 x (-0.03 x 0.15). Tonnage reads PMI, trade and the
        // foreign gap.
        $this->assertEqualsWithDelta(0.30 * (-0.03 * 0.15), $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);

        // A global slump: tonnage 0.55 x ((-0.06 x 0.25) + (-0.02 x 2.0)) plus the same terminal leg; a metals crash
        // adds nothing.
        $global = new MacroStateDTO(
            outputGapEma: -0.03,
            foreignOutputGapEma: -0.02,
            industrialMetalsIndexEma: 60.0,
            manufacturingPmiEma: 47.0,
            tradeBalanceToGdpEma: MacroConstants::TRADE_BALANCE_BASELINE,
            exchangeRateIndexEma: 100.0,
        );
        $expected = (0.55 * ((-0.06 * 0.25) + (-0.02 * 2.0))) + (0.30 * (-0.03 * 0.15));
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $global), 1e-9);
    }

    /**
     * The drivers panel reads the float book's own arithmetic on the IG spread: the line for its level over trend is
     * the deployment premium alone and the line for its move the mark alone, so the two add up to what the spread did,
     * up to the product of the two.
     */
    public function testTheCreditSpreadDriversReadTheFloatBooksOwnArithmetic(): void
    {
        $drivers = FloatBookCreditDrivers::measure($this->model, $this->ravn());

        // Each line is measured against the quarter with the other effect still in it.
        $this->assertEqualsWithDelta($drivers['deployment'] / (1.0 + $drivers['mark']), $drivers['level'], 1e-12);
        $this->assertEqualsWithDelta($drivers['mark'] / (1.0 + $drivers['deployment']), $drivers['move'], 1e-12);
        $this->assertEqualsWithDelta($drivers['deployment'] + $drivers['mark'], $drivers['level'] + $drivers['move'], 2.0 * abs($drivers['deployment'] * $drivers['mark']));
    }
}

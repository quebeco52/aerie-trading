<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConglomerateBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class ConglomerateBusinessModelTest extends TestCase
{
    private ConglomerateBusinessModel $model;
    private MathUtility $mathUtility;

    /** The short rate a calm economy sits at: the neutral rate the MacroStateDTO itself perceives. */
    private const NEUTRAL_POLICY_RATE = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;

    protected function setUp(): void
    {
        $this->model = new ConglomerateBusinessModel();
        $this->mathUtility = MathUtility::ownStream(20261024);
    }

    /**
     * A macro state with every series this model reads sitting exactly on its baseline. Anything a test
     * varies from here is the only thing moving, which is what lets the assertions below be exact.
     */
    private function neutralMacro(): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
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
        return new ScriptedDraws(generatePersistentZ: 0.0);
    }

    private function stock(string $ticker, string $beta): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setBeta($beta);

        return $stock;
    }

    /**
     * The plant's revenue is its operating subsidiaries': the float earns on the treasury, below the operating line,
     * not as a share of what the plant sells.
     */
    public function testThePlantSellsOnlyTheOperatingStreams(): void
    {
        $result = $this->model->computeActualFinancials(
            $this->stock('GEN_CONGLOMERATE', '0.8'),
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 15_000_000.0,
            baselineVol: 0.08,
            macroState: new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0),
            mathUtility: $this->mathUtility,
        );

        $this->assertSame(['industrial_manufacturing', 'defensive_staples'], array_keys($result->streamRevenue));
        $this->assertArrayNotHasKey('financial_investments', $result->streamZ);
        $this->assertEqualsWithDelta($result->actualRevenue, array_sum($result->streamRevenue), 1.0);
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
            $this->deterministicMath(),
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
            $this->deterministicMath(),
        );

        $this->assertArrayNotHasKey('financial_investments', $harr->streamRevenue);
        $this->assertArrayHasKey('defensive_staples', $harr->streamRevenue);
    }

    /**
     * The float earns on the cash the company holds above its operating needs: the money market on all of it and,
     * on the share held in credit, the book's spread less the losses that spread pays for. A company with no spare
     * cash earns nothing on it, whatever its plant sells.
     */
    public function testTheFloatEarnsOnTheCashItHolds(): void
    {
        $macro = $this->neutralMacro();
        $hoard = $this->holder(40_000_000_000.0);
        $standard = $this->standardIncome($hoard, $macro);
        $book = $this->model->calculateInterestIncome($hoard, $macro) - $standard;

        $riskMultiple = ConglomerateBusinessModel::CREDIT_BOOK_SPREAD / MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN;
        $net = ConglomerateBusinessModel::CREDIT_BOOK_SPREAD - ($riskMultiple * ConglomerateBusinessModel::CREDIT_BOOK_LOSS_GIVEN_DEFAULT * MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN);
        $excess = $standard / $macro->moneyMarketYield();
        $this->assertEqualsWithDelta($excess * ConglomerateBusinessModel::CREDIT_BOOK_STANDING_SHARE * $net, $book, 1.0);
        $this->assertSame(0.0, $this->model->calculateInterestIncome($this->holder(0.0), $macro), 'no spare cash, no float');
    }

    /**
     * As spreads blow out and the economy turns down, more of the float is put to work in credit, up to the most it
     * deploys; at the market's own trend it holds its standing share.
     */
    public function testDistressPutsMoreOfTheFloatToWork(): void
    {
        $hoard = $this->holder(40_000_000_000.0);
        $calm = $this->neutralMacro();
        $blowout = new MacroStateDTO(outputGapEma: -0.04, macroCreditSpread: 0.045, macroCreditSpreadEma: 0.045, policyRateEma: self::NEUTRAL_POLICY_RATE);

        $calmBook = $this->model->calculateInterestIncome($hoard, $calm) - $this->standardIncome($hoard, $calm);
        $blowoutBook = $this->model->calculateInterestIncome($hoard, $blowout) - $this->standardIncome($hoard, $blowout);

        $this->assertGreaterThan($calmBook, $blowoutBook);
        $this->assertLessThan($calmBook * ConglomerateBusinessModel::CREDIT_BOOK_MAX_SHARE / ConglomerateBusinessModel::CREDIT_BOOK_STANDING_SHARE, $blowoutBook + 1.0);
    }

    /**
     * Defaults over their measured trend eat the book's carry and a quiet year gives it back; a default rate that has
     * settled at its trend is no news, wherever the trend sits.
     */
    public function testDefaultsOverTheirTrendEatTheCarry(): void
    {
        $hoard = $this->holder(40_000_000_000.0);
        $book = fn(float $rate, float $trend): float => $this->model->calculateInterestIncome($hoard, $this->defaults($rate, $trend))
            - $this->standardIncome($hoard, $this->defaults($rate, $trend));
        $usual = $book(0.0143, 0.0143);

        $this->assertLessThan($usual, $book(0.03, 0.0143));
        $this->assertGreaterThan($usual, $book(0.01, 0.0143));
        $this->assertEqualsWithDelta($usual, $book(0.03, 0.03), 1.0, 'settled at its trend');
    }

    /** A merchant house's float is trade credit in its working capital: its cash runs no credit book. */
    public function testAMerchantHouseRunsNoCreditBookOnItsCash(): void
    {
        $ravn = $this->holder(40_000_000_000.0);
        $merchant = new \App\Service\Model\Sector\MerchantHouseBusinessModel();

        $this->assertSame($this->standardIncome($ravn, $this->neutralMacro()), $merchant->calculateInterestIncome($ravn, $this->neutralMacro()));
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
        // dormant stream leaves no hole in the segment table. BRKW is deliberately absent: it moved to
        // InvestmentCompanyBusinessModel, which reports its own streams and reads none of these weights.
        $expected = [
            // TRIV: two thirds industrial, a third defensive; its float earns on its cash
            'TRIV' => ['industrial_manufacturing' => 67.0, 'defensive_staples' => 33.0],
            // HARR: 30% Industrial hardware, 70% Niche instrumentation
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

    public function testTheCycleReachesTheCostBaseThroughTheOperatingStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('CONG_TEST');
        $recession = new MacroStateDTO(outputGapEma: -0.03, manufacturingPmiEma: MacroConstants::PMI_BASELINE, exchangeRateIndexEma: 100.0);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // Industrial 0.50 x (-0.03 x 1.2 x 0.90) + staples 0.50 x (-0.03 x 0.15).
        $expected = (0.50 * (-0.03 * 1.2 * 0.90)) + (0.50 * (-0.03 * 0.15));
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);

        // A PMI of 47 adds 0.50 x (-0.06 x 0.40) x 0.90 to the industrial leg.
        $pmiSlump = new MacroStateDTO(outputGapEma: -0.03, manufacturingPmiEma: 47.0, exchangeRateIndexEma: 100.0);
        $this->assertEqualsWithDelta($expected + (0.50 * -0.06 * 0.40 * 0.90), $this->model->resolveSectorActivityShift($stock, $pmiSlump), 1e-9);
    }

    private function holder(float $treasury): Stock
    {
        $stock = $this->stock('HOLD', '0.6');
        $stock->setTotalRevenue('20000000000');
        $stock->setTotalEquity('60000000000');
        $stock->setWholesaleDebt('5000000000');
        $stock->setCorporateTreasury((string) $treasury);

        return $stock;
    }

    /** What an operating company's treasury earns: the money market on its cash above operating needs. */
    private function standardIncome(Stock $stock, MacroStateDTO $macro): float
    {
        return (new \App\Service\Model\Sector\StandardCorporateBusinessModel())->calculateInterestIncome($stock, $macro);
    }

    private function defaults(float $rate, float $trend): MacroStateDTO
    {
        return new MacroStateDTO(
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroConstants::BASE_CREDIT_SPREAD,
            policyRateEma: self::NEUTRAL_POLICY_RATE,
            corporateDefaultRateEma: $rate,
            corporateDefaultRateTrend: $trend,
        );
    }
}

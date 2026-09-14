<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Data\InitialMarket;
use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Market\MarketConsensusEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Growth has a terminal state.
 *
 * Revenue capacity is invested capital times a fixed turnover, so the only thing that can stop a firm
 * compounding is the growth gate: the marginal return on the next dollar, net of the Penrose diseconomy of
 * its scale, has to fall below the hurdle and stay there. While that gate's input was floored at the cost
 * of equity, any firm whose levered WACC sat below the floor — a regulated utility, a pharma major, a
 * freight carrier — could never fail the test and reinvested its capex ratio every quarter forever: 7 to 9%
 * a year against a 4% economy, finishing with two to three times the capital of the market it served. The
 * sector TAM cap never caught it; it sits a median 32x above seed revenue and grows with nominal GDP.
 *
 * The property pinned is the one a player experiences: a mature firm's capital tracks the economy. Over the
 * back half of a 48-year run every firm here has long since saturated its market, so its capital may grow
 * at most a quarter faster than nominal GDP, and may not finish far past the market itself. Measured over
 * three seeds the fixed engine sits at 3.5 to 4.3% and 0.75 to 1.4x; the floored one ran 5.3 to 8.7% and
 * 1.8 to 3.5x on the three firms whose hurdle the floor had disarmed.
 *
 * The harness is the earnings/allocation stack alone — no market engine, price marked to a flat earnings
 * multiple each quarter so the issuance paths see something sane — and the PRNG is reseeded per firm.
 */
final class CapitalSaturationInvariantTest extends TestCase
{
    private const YEARS = 48;
    private const TICKS_PER_YEAR = 252;
    private const NOMINAL_GDP_GROWTH = 0.04;
    private const SEED = 20260913;
    /** Mature-phase capital growth allowed, as a multiple of nominal GDP growth. */
    private const MAX_MATURE_GROWTH_MULTIPLE = 1.25;
    /** Widest capital-to-serviceable-market ratio a saturated firm may finish at. */
    private const MAX_TERMINAL_SHARE = 1.75;
    /** A regulated utility, a platform monopoly, a steel maker, a pharma major, a freight carrier: five very different reinvestment profiles. */
    private const TICKERS = ['BIRD', 'HUMM', 'WING', 'IBIS', 'CANV'];

    private function buildEngine(): EarningsEngine
    {
        $math = new MathUtility();
        $metrics = new CorporateMetrics();
        $debt = new DebtEngine($math, $metrics);
        $capex = new CapExEngine();
        $treasury = new TreasuryEngine($metrics, $debt, $capex, $math);
        $allocation = new CapitalAllocationEngine($this->createStub(CorporateLedgerService::class), $metrics, $debt, $math, $treasury);

        return new EarningsEngine(
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MarketEventPublisher::class),
            $allocation,
            $debt,
            $capex,
            $math,
            $metrics,
            $this->createStub(NarrativeEngine::class),
            new MarketConsensusEngine()
        );
    }

    private function macro(float $nominalGdpIndex): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: 0.0,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            nominalGdpIndex: $nominalGdpIndex,
            marketVolatilityEma: 0.15,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.0010
        );
    }

    /** The seed row's balance sheet and structural parameters; the engine opens its own ledgers on the first report. */
    private function seedFromRow(array $row): Stock
    {
        $stock = new Stock();
        $stock->setTicker($row['ticker']);
        $stock->setName($row['name']);
        $stock->setSector($row['sector']);
        $stock->setIndustry($row['industry']);
        $stock->setSharesOutstanding((string) $row['shares_outstanding']);
        $stock->setVolatility((string) $row['volatility']);
        $stock->setCurrentVolatility((string) $row['volatility']);
        $stock->setBeta((string) $row['beta']);
        $stock->setJumpIntensity((string) $row['jump_intensity']);
        $stock->setJumpVol((string) $row['jump_vol']);
        $stock->setSystemicImportance($row['systemic_importance']);
        $stock->setBaselineRoic((string) $row['baseline_roic']);
        $stock->setCurrentRoic((string) $row['baseline_roic']);
        $stock->setRoicTtm((string) $row['baseline_roic']);
        $stock->setCapexRatio((string) $row['capex_ratio']);
        $stock->setTargetPayoutRatio((string) $row['target_payout_ratio']);
        $stock->setDividendSpeed((string) $row['dividendSpeed']);
        $stock->setFixedCostRatio((float) $row['fixed_cost_ratio']);
        $stock->setOperatingMargin((string) $row['operating_margin']);
        $stock->setDepreciationRate((string) $row['depreciation_rate']);
        $stock->setCorporateTreasury((string) $row['corporate_treasury']);
        $stock->setFloatingDebtRatio((string) $row['floating_debt_ratio']);
        $stock->setTotalEquity((string) $row['total_equity']);
        $stock->setWholesaleDebt((string) $row['wholesale_debt']);
        $stock->setCustomerDeposits((string) $row['customer_deposits']);
        $stock->setRetainedEarnings((string) $row['retained_earnings']);
        $stock->setSamRatio((string) $row['sam_ratio']);
        $stock->setCreditSpread((string) $row['credit_spread']);
        $stock->setHistoricalFixedRate((string) $row['historical_fixed_rate']);
        $stock->setPrice('100.00');

        return $stock;
    }

    public static function seedRowProvider(): array
    {
        $rows = [];
        foreach (InitialMarket::STOCKS as $row) {
            if (in_array($row['ticker'], self::TICKERS, true)) {
                $rows[$row['ticker']] = [$row];
            }
        }

        return $rows;
    }

    #[DataProvider('seedRowProvider')]
    public function testMatureCapitalTracksTheEconomyRatherThanCompounding(array $row): void
    {
        mt_srand(self::SEED);
        $engine = $this->buildEngine();
        $stock = $this->seedFromRow($row);
        $industryPe = (float) (Sectors::INDUSTRY_METRICS[$row['industry']]['pe'] ?? 18.0);

        $ticksPerQuarter = intdiv(self::TICKS_PER_YEAR, 4);
        $reportingTick = EarningsEngine::resolveReportingTick($stock->getTicker(), self::TICKS_PER_YEAR);
        $halfway = self::YEARS * 2;
        $matureYears = self::YEARS / 2;

        $capitalAtHalfway = 1.0;
        $gdpAtHalfway = 1.0;
        $gdp = 1.0;

        for ($quarter = 1; $quarter <= self::YEARS * 4; $quarter++) {
            $gdp = pow(1.0 + self::NOMINAL_GDP_GROWTH, ($quarter - 1) / 4.0);
            $engine->calculate($stock, $this->macro($gdp), (($quarter - 1) * $ticksPerQuarter) + $reportingTick, self::TICKS_PER_YEAR);

            // Marked to a plain earnings multiple: with no market engine a frozen price reads as a permanent
            // bubble and the secondary-offering path fires every quarter.
            $stock->setPrice((string) max(1.0, (float) $stock->getEarningsPerShare() * $industryPe));

            $this->assertTrue(is_finite($stock->getInvestedCapital()), "{$row['ticker']} invested capital became non-finite in Q{$quarter}");

            if ($quarter === $halfway) {
                $capitalAtHalfway = $stock->getInvestedCapital();
                $gdpAtHalfway = $gdp;
            }
        }

        $terminalCapital = $stock->getInvestedCapital();
        $capitalCagr = pow($terminalCapital / $capitalAtHalfway, 1.0 / $matureYears) - 1.0;
        $gdpCagr = pow($gdp / $gdpAtHalfway, 1.0 / $matureYears) - 1.0;

        $this->assertLessThan(
            $gdpCagr * self::MAX_MATURE_GROWTH_MULTIPLE,
            $capitalCagr,
            sprintf('%s compounded capital at %.2f%%/yr over its mature half against a %.2f%% economy: the growth gate is not firing.', $row['ticker'], 100 * $capitalCagr, 100 * $gdpCagr)
        );

        $serviceableMarket = FinancialConstants::BASELINE_SECTOR_TAM * $gdp * (float) $row['sam_ratio'];
        $terminalShare = $terminalCapital / $serviceableMarket;
        $this->assertLessThan(
            self::MAX_TERMINAL_SHARE,
            $terminalShare,
            sprintf('%s finished with capital at %.2fx the market it serves; growth never stopped.', $row['ticker'], $terminalShare)
        );
    }
}

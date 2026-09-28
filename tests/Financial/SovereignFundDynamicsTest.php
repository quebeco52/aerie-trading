<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The sovereign fund over a lifetime of the real macro engine, against a board that moves on the engine's own
 * market factor: it has to stay a fund of roughly the size it opened at, keep its footprint on the board bounded,
 * rebalance now and then rather than never or constantly, and carry the fiscal consequence the plan states --
 * a budget that no longer borrows for its structural deficit settles on a lower debt.
 *
 * The board is synthetic on purpose. The whole-ticker harness measures the fund's effect on prices; this pins the
 * fund's own arithmetic over decades, which that harness is far too slow to run in a suite.
 */
class SovereignFundDynamicsTest extends TestCase
{
    private const SEEDS = [11, 23, 37, 53, 71, 89];

    private const YEARS = 80;

    /** Twelve months divide it, so every month end lands on a tick. */
    private const TICKS_PER_YEAR = 24;

    /** Float and total capitalisation at the opening, about a trillion. */
    private const OPENING_FLOAT_CAP = 1.0e12;

    private const FLOAT_SHARE = 0.85;

    /** Dividend yield of the synthetic board, near the S&P 500's long-run average. */
    private const DIVIDEND_YIELD = 0.02;

    /** Log capitalisation-to-GDP per unit of ERP, fitted on the live engine's own reports (log(eq/GDP) = 31.35 - 5.475 ERP, R^2 0.66, 2026-09-20). */
    private const ERP_VALUATION_SLOPE = 5.475;

    /** Half-life of the transitory price component around fundamentals (Poterba & Summers 1988: a few years). */
    private const TRANSITORY_HALF_LIFE_YEARS = 2.0;

    public function testTheFundStaysAFundOfItsSizeAndTheBudgetBorrowsLess(): void
    {
        $withFund = [];
        $withoutFund = [];
        foreach (self::SEEDS as $seed) {
            $withFund[$seed] = $this->simulate($seed, true);
            $withoutFund[$seed] = $this->simulate($seed, false);
        }

        // Log change in size over the run. It compounds at about 2.8% real, pays half, and the economy grows 2%, so
        // the expected drift is about -0.6% a year, -0.48 over 79 years; one seed's noise over that span is about
        // 1.1, so six seeds pin the mean to +/-0.44 and the bounds are three of those. The spending arithmetic
        // itself is pinned exactly in SovereignFundSubsystemTest; this guards a fund that bleeds away or explodes.
        $logChanges = array_map(
            static fn (array $run): float => log($run['sizes'][count($run['sizes']) - 1] / $run['sizes'][0]),
            $withFund
        );
        $meanLogChange = array_sum($logChanges) / count($logChanges);
        $this->assertGreaterThan(-1.8, $meanLogChange, 'The fund must not bleed away; it keeps half its compound return.');
        $this->assertLessThan(0.85, $meanLogChange, 'Nor compound without bound; it pays the other half.');

        // Rebalancing holds the domestic sleeve at its policy weight: the band trips at a quarter's relative move and the
        // market carries on while a programme trades. The board footprint is that weight times the fund's size, so it
        // is bounded below by the size check above, not here: a fund run down by a slump it paid for owns less.
        $weights = array_merge(...array_values(array_map(static fn (array $run): array => $run['weights'], $withFund)));
        $this->assertGreaterThan(0.6, min($weights), 'Rebalancing keeps the fund on the board at its policy weight.');
        $this->assertLessThan(1.5, max($weights), 'And off it when the board runs up.');
        // A fund that bought a fall to the 10% ceiling can drift past it only by the board falling further.
        $ownership = array_merge(...array_values(array_map(static fn (array $run): array => $run['ownership'], $withFund)));
        $this->assertLessThan(0.15, max($ownership), 'And the ownership ceiling keeps its footprint bounded.');

        $rebalances = array_sum(array_map(static fn (array $run): int => $run['rebalances'], $withFund));
        $perDecade = $rebalances / (count(self::SEEDS) * self::YEARS / 10.0);
        $this->assertGreaterThan(0.05, $perDecade, 'A fixed-weight rule has to trade sometimes.');
        $this->assertLessThan(6.0, $perDecade, 'A band a 30% relative move trips is not a daily trade.');

        $debtWith = $this->median(array_merge(...array_map(static fn (array $run): array => $run['lateDebt'], $withFund)));
        $debtWithout = $this->median(array_merge(...array_map(static fn (array $run): array => $run['lateDebt'], $withoutFund)));
        $this->assertLessThan($debtWithout - 0.10, $debtWith, 'Funding the structural deficit lowers the debt the fiscal rule settles on.');
        $this->assertGreaterThan(0.55, $debtWith, 'Bohn above 0.70 still holds it well off the floor.');
    }

    /**
     * @return array{sizes: list<float>, ownership: list<float>, weights: list<float>, rebalances: int, lateDebt: list<float>}
     */
    private function simulate(int $seed, bool $withFund): array
    {
        mt_srand($seed);
        $math = new MathUtility();
        $engine = new MacroEngine(
            $math,
            $this->inMemoryRedis(),
            new MacroSnapshotRecorder(),
            new MonetaryPolicySubsystem($math),
            new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($math),
            new CommodityLogisticsSubsystem($math),
            new AssetMarketSubsystem($math),
            new CreditFiscalSubsystem($math),
            null,
            null,
            $withFund ? new SovereignFundSubsystem($math) : null,
        );

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $floatCap = self::OPENING_FLOAT_CAP;
        $openingGdp = null;
        $transitory = 0.0;
        $reversion = exp(-$dt * log(2.0) / self::TRANSITORY_HALF_LIFE_YEARS);
        $priceReturn = null;
        $dividends = null;
        $sizes = [];
        $ownership = [];
        $weights = [];
        $lateDebt = [];
        $rebalances = 0;
        $lastRebalance = -1.0;

        for ($tick = 1; $tick <= self::YEARS * self::TICKS_PER_YEAR; ++$tick) {
            $macro = $engine->updateMacroState($dt, $floatCap / self::FLOAT_SHARE, $floatCap, $priceReturn, $dividends);
            $openingGdp ??= $macro->nominalGdpIndex;

            // A board anchored the way the engine's is: it scales with nominal GDP, re-rates against the equity risk
            // premium, and carries the engine's own market shocks as a transitory component around that.
            $transitory = ($reversion * $transitory) + ($macro->marketVolatility * sqrt($dt) * $macro->marketZ);
            $nextFloatCap = self::OPENING_FLOAT_CAP * ($macro->nominalGdpIndex / $openingGdp)
                * exp((-self::ERP_VALUATION_SLOPE * ($macro->equityRiskPremium - MacroEngine::BASE_EQUITY_RISK_PREMIUM)) + $transitory);
            $dividends = self::DIVIDEND_YIELD * $floatCap * $dt;
            $priceReturn = ($nextFloatCap / $floatCap) - 1.0;
            $floatCap = $nextFloatCap;

            if ($macro->lastSovereignRebalanceAt > $lastRebalance) {
                $lastRebalance = $macro->lastSovereignRebalanceAt;
                ++$rebalances;
            }
            if ($tick % self::TICKS_PER_YEAR === 0) {
                $sizes[] = $macro->sovereignFundToGdp;
                $ownership[] = $macro->sovereignFundOwnershipShare;
                if ($macro->sovereignFundTargetWeight > 0.0) {
                    $weights[] = $macro->sovereignFundDomesticWeight / $macro->sovereignFundTargetWeight;
                }
                if ($tick > (self::YEARS - 30) * self::TICKS_PER_YEAR) {
                    $lateDebt[] = $macro->sovereignDebtToGdp;
                }
            }
        }

        return ['sizes' => $sizes, 'ownership' => $ownership, 'weights' => $weights, 'rebalances' => $rebalances, 'lateDebt' => $lateDebt];
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    private function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
    }
}

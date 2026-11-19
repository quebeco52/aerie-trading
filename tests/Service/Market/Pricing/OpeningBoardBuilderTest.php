<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\Data\Company\AnchorHoldings;
use App\Data\Company\DividendTier;
use App\Data\Company\PayoutPolicy;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The seed and the reset both open the board through OpeningBoardBuilder, so what it opens is what every new market
 * starts from.
 */
#[Group('unit')]
class OpeningBoardBuilderTest extends TestCase
{
    private MathUtility $math;
    private DebtEngine $debtEngine;
    private MarketEngine $marketEngine;
    private AnchorStakeLedger $ledger;
    private OpeningBoardBuilder $builder;

    protected function setUp(): void
    {
        mt_srand(20260929);
        $this->math = new MathUtility();
        $this->debtEngine = new DebtEngine($this->math, CorporateMetrics::getInstance());
        $this->marketEngine = new MarketEngine($this->math);
        $this->ledger = new AnchorStakeLedger();
        $this->builder = new OpeningBoardBuilder($this->math, $this->debtEngine, $this->marketEngine, $this->ledger);
    }

    /** @return array<string, Stock> The whole seed board, opened as the commands open it. */
    private function openBoard(MacroStateDTO $openingMacro): array
    {
        $board = [];
        $spheres = [];
        foreach (SeedRows::all() as $stockData) {
            $stock = (new Stock())->setTicker($stockData['ticker']);
            $sphere = $this->builder->open($stock, $stockData, $openingMacro);
            if ($sphere !== null) {
                $spheres[] = $sphere;
            }
            $board[$stockData['ticker']] = $stock;
        }
        $this->builder->openSpheres($spheres, $board, $openingMacro);

        return $board;
    }

    /**
     * Every firm opens at the fair value the market strikes for it on the inputs it will price it on at the first
     * tick, so trading does not start with a re-rating. The opening used to run a lender's debt analysis before its
     * industry was set, on standard corporate physics, and opened the large banks and funds 30-50% below it.
     */
    public function testEveryFirmOpensAtThePriceTheMarketValuesItAt(): void
    {
        $openingMacro = new MacroStateDTO();

        foreach ($this->openBoard($openingMacro) as $ticker => $stock) {
            $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);
            $fairValue = $this->marketEngine->strikeFairValue(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];

            $this->assertEqualsWithDelta(1.0, $fairValue / (float) $stock->getPrice(), 0.005, sprintf('%s opens %.1f%% away from the value the market puts on it.', $ticker, 100.0 * ($fairValue / (float) $stock->getPrice() - 1.0)));
        }
    }

    /**
     * A market that has been trading opens where each board's dividend policy has already brought it (Lintner 1956): a
     * board closing part of its gap each year runs a steady step below its target payout while earnings grow with the
     * economy, so it opens there, with the manager's fixed effect. A trust's legal minimum binds from its first report,
     * on the taxable income the engine books: applied to the opening estimate of earnings, which carries the
     * depreciation a trust adds back, it opened trusts above their own policy and their records broke in the first
     * year. Opening at the target left the first review nothing to raise toward, and every record of increases broke in
     * its first year or two; opening at half of it left every payer to grow into the rest.
     */
    public function testEveryFirmOpensPayingWhereItsPolicyHasBroughtIt(): void
    {
        $macro = new MacroStateDTO();
        $trendGrowth = MacroConstants::TREND_REAL_GROWTH + $macro->inflationEma;
        $payers = 0;
        foreach ($this->openBoard($macro) as $ticker => $stock) {
            $quarterlyEps = max(0.0, (float) $stock->getTotalNetIncome() / (float) $stock->getSharesOutstanding() / 4.0);
            $annualSpeed = Valuation::lintnerAnnualSpeed((float) $stock->getDividendSpeed() * $stock->getDividendTier()->smoothing());
            $expected = $quarterlyEps * $stock->getManagementProfile()->appliedPayoutRatio((float) $stock->getTargetPayoutRatio()) * Valuation::lintnerSteadyDividendShare($annualSpeed, $trendGrowth);

            // Within 2%: the opening price loop and the anchor-stake pass re-strike earnings after the dividend is set,
            // and the target itself, the old opening, is 8-16% away.
            $this->assertEqualsWithDelta($expected, (float) $stock->getLastDividend(), max(1e-4, 0.02 * $expected), "{$ticker} opens off where its policy has brought it.");
            $payers += $expected > 0.0 ? 1 : 0;
        }

        $this->assertGreaterThan(60, $payers);
    }

    /**
     * A place on the dividend roll is a record of increases, not an adjustment speed. Every payer opens on a record
     * from the board's long-run distribution, so the roll opens at about the size it keeps (a third of payers, one in
     * thirteen a champion) instead of filling from empty, the longest records with the firms paying most, and a firm
     * paying nothing opens with no record.
     */
    public function testTheBoardOpensOnTheRollsLongRunShape(): void
    {
        $board = $this->openBoard(new MacroStateDTO());
        $payers = 0;
        $listed = 0;
        $champions = 0;
        $byPolicy = [];

        foreach ($board as $ticker => $stock) {
            $streak = $stock->getDividendStreakYears();
            if ((float) $stock->getLastDividend() <= 0.0) {
                $this->assertSame(0, $streak, "{$ticker} pays nothing but opens with a record of increases.");

                continue;
            }
            $payers++;
            $listed += $stock->getDividendTier()->isListed() ? 1 : 0;
            $champions += $streak >= DividendTier::CHAMPION_YEARS ? 1 : 0;
            $byPolicy[$stock->getPayoutPolicy()->value][] = $streak;
            $this->assertLessThanOrEqual(DividendTier::LONGEST_OPENING_RECORD, $streak);
        }

        // The mature firm paying most has been paying longest: a harvest payer opens on a longer record than a low one.
        $mean = static fn(array $streaks): float => array_sum($streaks) / count($streaks);
        $this->assertArrayHasKey(PayoutPolicy::Low->value, $byPolicy, 'The board opens with low payers.');
        $this->assertArrayHasKey(PayoutPolicy::Harvest->value, $byPolicy, 'The board opens with harvest payers.');
        $this->assertGreaterThan($mean($byPolicy[PayoutPolicy::Low->value]), $mean($byPolicy[PayoutPolicy::Harvest->value]));

        // Ninety-odd payers draw their records, so a share sits within about two binomial standard errors of its own.
        $this->assertEqualsWithDelta(0.32, $listed / $payers, 0.10);
        $this->assertEqualsWithDelta(0.08, $champions / $payers, 0.06);
    }

    /**
     * Omitting a dividend is bad news in every study of it (Healy & Palepu 1988: about -7%), and it can never be
     * good news for value. An operating company's fair value does not move at all; a trust or a utility, priced
     * on the income its policy pays, loses the income missed while the dividend is rebuilt.
     */
    public function testCuttingADividendNeverRaisesAFirmsFairValue(): void
    {
        $openingMacro = new MacroStateDTO();
        $incomePriced = 0;

        foreach ($this->openBoard($openingMacro) as $ticker => $stock) {
            $fairValue = function () use ($stock, $openingMacro): float {
                $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);

                return $this->marketEngine->strikeFairValue(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];
            };
            $paying = $fairValue();
            $stock->setLastDividend('0.00');
            $omitted = $fairValue();

            $this->assertLessThanOrEqual($paying * (1.0 + 1e-9), $omitted, "{$ticker} is worth more for omitting its dividend.");
            $incomePriced += $omitted < $paying * (1.0 - 1e-6) ? 1 : 0;
        }

        $this->assertGreaterThanOrEqual(4, $incomePriced, 'The REITs and utilities, priced on income, do lose value.');
    }

    /**
     * The board opens before any firm has reported a cash flow, and its first report must not re-rate it. Fair value
     * used to average the multiple with a DCF on one quarter's free cash flow: absent at the open, it switched on at
     * the first report and marked the board down about 10%, a thin positive cash flow halving a firm's earnings value.
     */
    public function testAReportedFreeCashFlowDoesNotRerateTheOpeningBoard(): void
    {
        $openingMacro = new MacroStateDTO();

        foreach ($this->openBoard($openingMacro) as $ticker => $stock) {
            $fairValue = function () use ($stock, $openingMacro): float {
                $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);

                return $this->marketEngine->strikeFairValue(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];
            };
            $opening = $fairValue();

            foreach (['0.01', '-5.00', '12.00'] as $freeCashFlowPerShare) {
                $stock->setFreeCashFlowPerShare($freeCashFlowPerShare);
                $this->assertEqualsWithDelta($opening, $fairValue(), $opening * 1e-9, "{$ticker} re-rates on a free cash flow of {$freeCashFlowPerShare} a share.");
            }
        }
    }

    /** Opening earnings are struck after the interest the firm's debt costs, at no less than its credit spread. */
    public function testALeveredFirmOpensOnEarningsAfterItsInterestBill(): void
    {
        $openingMacro = new MacroStateDTO();
        $checked = 0;

        foreach (SeedRows::all() as $stockData) {
            $strategy = Sectors::strategyFor($stockData['industry']);
            $debt = (float) ($stockData['wholesale_debt'] ?? 0.0);
            if ($strategy->isFinancial() || AnchorHoldings::forHolder($stockData['ticker']) !== [] || $debt < 0.25 * (float) $stockData['total_equity']) {
                continue;
            }

            $stock = (new Stock())->setTicker($stockData['ticker']);
            $this->builder->open($stock, $stockData, $openingMacro);

            $taxRate = $strategy->getEffectiveTaxRate($openingMacro->corporateTaxRate);
            $depreciationAddBack = $strategy->reportsFundsFromOperations()
                ? $strategy->getPlantCapital($stock, $stock->getInvestedCapital()) * \App\Service\Corporate\Earnings\FixedAssetLedger::depreciationRateFor($stock)
                : 0.0;
            $preInterest = ((float) $stock->getTotalRevenue() * (float) $stock->getOperatingMargin() + $depreciationAddBack + $strategy->calculateInterestIncome($stock, $openingMacro)) * (1.0 - $taxRate);
            $leastInterest = $debt * (float) $stock->getCreditSpread() * (1.0 - $taxRate);
            if ($preInterest <= $leastInterest) {
                continue;
            }

            $this->assertLessThan($preInterest - $leastInterest, (float) $stock->getTotalNetIncome(), sprintf('%s opens on earnings that ignore its interest bill.', $stockData['ticker']));
            $checked++;
        }

        $this->assertGreaterThanOrEqual(5, $checked);
    }
}

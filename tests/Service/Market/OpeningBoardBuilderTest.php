<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Data\AnchorHoldings;
use App\Data\InitialMarket;
use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\MarketEngine;
use App\Service\Market\OpeningBoardBuilder;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The seed and the reset both open the board through OpeningBoardBuilder, so what it opens is what every new market
 * starts from.
 */
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
        foreach (InitialMarket::STOCKS as $stockData) {
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
            $fairValue = $this->marketEngine->calculateNextPrice(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];

            $this->assertEqualsWithDelta(1.0, $fairValue / (float) $stock->getPrice(), 0.005, sprintf('%s opens %.1f%% away from the value the market puts on it.', $ticker, 100.0 * ($fairValue / (float) $stock->getPrice() - 1.0)));
        }
    }

    /**
     * A market that has been trading opens where each board's dividend policy has already brought it (Lintner
     * 1956): the target payout with the manager's fixed effect, and never under a distribution the law requires.
     * Opening at half the target left every payer to grow into the rest, a drift nothing in the business caused.
     */
    public function testEveryFirmOpensPayingItsTargetPayout(): void
    {
        $payers = 0;
        foreach ($this->openBoard(new MacroStateDTO()) as $ticker => $stock) {
            $strategy = Sectors::strategyFor((string) $stock->getIndustry());
            $quarterlyEps = max(0.0, (float) $stock->getTotalNetIncome() / (float) $stock->getSharesOutstanding() / 4.0);
            $payout = max((float) $stock->getTargetPayoutRatio() * $stock->getManagementProfile()->payoutBias(), $strategy->getMinimumDistributionRatio());
            $expected = $quarterlyEps * $payout;

            // Within 2%: the opening price loop and the anchor-stake pass re-strike earnings after the dividend is set,
            // and half the target, the old opening, is 50% away.
            $this->assertEqualsWithDelta($expected, (float) $stock->getLastDividend(), max(1e-4, 0.02 * $expected), "{$ticker} opens off its target payout.");
            $payers += $expected > 0.0 ? 1 : 0;
        }

        $this->assertGreaterThan(60, $payers);
    }

    /**
     * An aristocrat is a record of increases, not an adjustment speed. The seed names about the share of payers the
     * S&P 500 Dividend Aristocrats are of that index (about 13%), and the board opens with exactly those firms
     * carrying it. Reading it off a slow speed made 35 of 77 payers aristocrats.
     */
    public function testTheBoardOpensWithTheAristocratsTheSeedNames(): void
    {
        $board = $this->openBoard(new MacroStateDTO());
        $payers = 0;
        $aristocrats = 0;

        foreach (InitialMarket::STOCKS as $row) {
            $isPayer = (float) $row['target_payout_ratio'] > 0.0;
            $named = (bool) ($row['dividend_aristocrat'] ?? false);

            $this->assertSame($named, $board[$row['ticker']]->isDividendAristocrat(), "{$row['ticker']} opens with the wrong aristocrat standing.");
            $this->assertTrue($isPayer || !$named, "{$row['ticker']} is named an aristocrat without paying a dividend.");
            $payers += $isPayer ? 1 : 0;
            $aristocrats += $named ? 1 : 0;
        }

        $this->assertEqualsWithDelta(0.13, $aristocrats / $payers, 0.03);
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

                return $this->marketEngine->calculateNextPrice(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];
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

                return $this->marketEngine->calculateNextPrice(MarketPricingContext::forStock($stock, $openingMacro, $health, $this->ledger))['perceived_fair_value'];
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

        foreach (InitialMarket::STOCKS as $stockData) {
            $strategy = Sectors::strategyFor($stockData['industry']);
            $debt = (float) ($stockData['wholesale_debt'] ?? 0.0);
            if ($strategy->isFinancial() || AnchorHoldings::forHolder($stockData['ticker']) !== [] || $debt < 0.25 * (float) $stockData['total_equity']) {
                continue;
            }

            $stock = (new Stock())->setTicker($stockData['ticker']);
            $this->builder->open($stock, $stockData, $openingMacro);

            $taxRate = $strategy->getEffectiveTaxRate($openingMacro->corporateTaxRate);
            $preInterest = ((float) $stock->getTotalRevenue() * (float) $stock->getOperatingMargin() + $strategy->calculateInterestIncome($stock, $openingMacro, $this->math)) * (1.0 - $taxRate);
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

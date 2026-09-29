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

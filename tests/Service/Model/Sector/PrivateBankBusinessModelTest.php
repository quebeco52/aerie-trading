<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector\CustodyBankBusinessModel;
use App\Service\Model\Sector\PrivateBankBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PrivateBankBusinessModelTest extends TestCase
{
    private PrivateBankBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new PrivateBankBusinessModel();
    }

    private function bank(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('GEN_PRIVATE');

        return $stock;
    }

    private function feeShift(MacroStateDTO $state): float
    {
        return (float) (new \ReflectionMethod($this->model, 'resolveFeeVolumeShift'))->invoke($this->model, $this->bank(), $state);
    }

    public function testHomeClientsAreMarkedOnTheHomeMarketAtTheirShare(): void
    {
        // A 10% home rally reaches only the 38% of client wealth booked at home, at the custodian's fee elasticity.
        $rally = new MacroStateDTO(equityWealthRatio: 1.10, equityWealthTrend: 1.0);

        $this->assertEqualsWithDelta(0.38 * 0.033, $this->feeShift($rally), 1e-12);
    }

    public function testForeignClientsAreMarkedOnTheForeignMarket(): void
    {
        $foreignRally = new MacroStateDTO(equityWealthRatio: 1.0, equityWealthTrend: 1.0, foreignEquityValuation: log(1.10));

        $this->assertEqualsWithDelta(0.62 * CustodyBankBusinessModel::EQUITY_FEE_ELASTICITY * 0.10, $this->feeShift($foreignRally), 1e-12);
    }

    public function testAStrongCurrencyShrinksForeignClientsAssetsAtHome(): void
    {
        // The currency 10% above its fundamental: foreign wealth is worth 1/1.1 of itself in District money.
        $strong = new MacroStateDTO(equityWealthRatio: 1.0, equityWealthTrend: 1.0, exchangeRateDeviation: 1.10);
        $weak = new MacroStateDTO(equityWealthRatio: 1.0, equityWealthTrend: 1.0, exchangeRateDeviation: 0.90);

        $this->assertEqualsWithDelta(0.62 * ((1.0 / 1.10) - 1.0), $this->feeShift($strong), 1e-12);
        $this->assertGreaterThan(0.0, $this->feeShift($weak));
        $this->assertSame(0.0, $this->feeShift(new MacroStateDTO()), 'markets and currency at trend, no mark');
    }

    public function testLombardLendingMakesHalfTheBook(): void
    {
        $this->assertSame(0.475, $this->model->getLoanShareOfEarningAssets());
        $this->assertGreaterThan((new CustodyBankBusinessModel())->getLoanShareOfEarningAssets(), $this->model->getLoanShareOfEarningAssets());
    }

    public function testItReadsTheForeignMarketAndTheCurrency(): void
    {
        $fields = $this->model->getOperatingMacroFields();

        $this->assertContains('foreign_equity_valuation', $fields);
        $this->assertContains('exchange_rate_deviation', $fields);
        $this->assertContains('equity_wealth_ratio', $fields);
    }
}

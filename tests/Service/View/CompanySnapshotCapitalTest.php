<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\View\CompanySnapshotBuilder;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * A firm the regulator governs is quoted on tangible book and shown the ratio it would be closed on, against
 * the lines the regulator acts on. An operating company is shown neither.
 */
#[AllowMockObjectsWithoutExpectations]
class CompanySnapshotCapitalTest extends TestCase
{
    private DebtEngine $debtEngine;

    protected function setUp(): void
    {
        $this->debtEngine = new DebtEngine(new MathUtility(), CorporateMetrics::getInstance());
    }

    public function testABankIsQuotedOnTangibleBookAndItsClosureRatio(): void
    {
        $bank = $this->bank(goodwill: 30e9);

        $capital = $this->builder()->build($bank, new MacroStateDTO())['capital'];

        $this->assertNotNull($capital);
        $this->assertEqualsWithDelta(70e9 / 1e9, $capital['tangibleBookPerShare'], 1e-9, 'book less goodwill, per share');
        $this->assertEqualsWithDelta(50.0 / 70.0, $capital['priceToTangibleBook'], 1e-9);
        $this->assertEqualsWithDelta(8e9 / 70e9, $capital['returnOnTangibleEquity'], 1e-9, 'trailing four quarters over tangible equity');
        $this->assertEqualsWithDelta(0.30, $capital['goodwillShare'], 1e-9);
        $this->assertNotNull($capital['cet1']);

        $closure = $this->debtEngine->calculateAltmanZScore($bank, 0.0, (float) $bank->getTotalRevenue(), 50.0);
        $this->assertEqualsWithDelta($closure['z_score'] / 100.0, $capital['ratio'], 1e-12, 'the ratio FailureSweep closes the firm on');
        $this->assertSame($closure['zone'], $capital['zone']);
    }

    /** The regulator's lines come from the firm's own model, so the chart and the closure agree. */
    public function testTheThresholdsAreTheModelsOwn(): void
    {
        $model = new CommercialBankBusinessModel();

        $thresholds = $this->builder()->build($this->bank(goodwill: 0.0), new MacroStateDTO())['capitalThresholds'];

        $this->assertSame(
            ['warning' => $model->getWarningEquityThreshold(), 'distress' => $model->getDistressEquityThreshold(), 'bankrupt' => $model->getBankruptEquityThreshold()],
            $thresholds
        );
    }

    public function testAnOperatingCompanyIsShownNoCapitalRatio(): void
    {
        $ordinary = StockBuilder::create('CBIL')->withPrice(100.0)->build()->setIndustry('Tools & Accessories');

        $snapshot = $this->builder()->build($ordinary, new MacroStateDTO());

        $this->assertNull($snapshot['capital']);
        $this->assertNull($snapshot['capitalThresholds']);
    }

    /** An insurer is governed by its capital ratio but is not a Basel bank: no CET1. */
    public function testAnInsurerHasACapitalRatioButNoCet1(): void
    {
        $insurer = $this->bank(goodwill: 0.0)->setIndustry('Insurance - Diversified');

        $capital = $this->builder()->build($insurer, new MacroStateDTO())['capital'];

        $this->assertNotNull($capital);
        $this->assertNull($capital['cet1']);
    }

    private function bank(float $goodwill): Stock
    {
        $bank = StockBuilder::create('LAKE')
            ->withPrice(50.0)
            ->withSharesOutstanding(1_000_000_000)
            ->withTotalEquity(100e9)
            ->build()
            ->setIndustry('Banks - Diversified');
        $bank->setGoodwill((string) $goodwill);
        $bank->setCustomerDeposits('900000000000');
        $bank->setCorporateTreasury('50000000000');
        $bank->setTotalRevenue('60000000000');
        $bank->setQuarterlyNetIncomeHistory([1e9, 2e9, 2e9, 2e9, 2e9]);

        return $bank;
    }

    private function builder(): CompanySnapshotBuilder
    {
        $math = new MathUtility();

        return new CompanySnapshotBuilder(new MarketEngine($math), $this->debtEngine, new AnchorStakeLedger(), $this->createStub(StockRepository::class));
    }
}

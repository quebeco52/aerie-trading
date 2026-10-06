<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Goodwill is not capital. Every regulator deducts it (Basel III from CET1, Solvency II at nil, PFMI's liquid
 * net assets), so the capital that sizes a financial's business is tangible and a write-off, which moves
 * book equity and goodwill together, moves none of it.
 */
class TangibleCapitalInvariantTest extends TestCase
{
    public function testEveryCapitalMeasureDeductsGoodwill(): void
    {
        $stock = new Stock();
        $stock->setTotalEquity('100000000000');
        $stock->setGoodwill('30000000000');
        $stock->setUnrealizedSecuritiesMark('-10000000000');
        $recognizedMark = $stock->getRecognizedSecuritiesMark();

        $this->assertSame(70e9, $stock->getTangibleEquity());
        $stock->setAociFiltered(false);
        $this->assertSame(70e9, $stock->getRegulatoryEquity(), 'CET1 without the AOCI filter: book equity less goodwill');
        $this->assertEqualsWithDelta(100e9 - $recognizedMark - 30e9, $stock->getStatutorySurplus(), 1e-3);

        $stock->setAociFiltered(true);
        $this->assertEqualsWithDelta(100e9 - $recognizedMark - 30e9, $stock->getRegulatoryEquity(), 1e-3, 'with the filter the mark goes back, the goodwill still comes off');
    }

    /**
     * A firm that wrote its goodwill off earns, sizes its book and rations its capacity exactly as it did
     * before: equity E with goodwill G is the same business as equity E − G with none. Size measures (the
     * cash buffer's operating base, the saturation penalty) read the whole book, so the fixture keeps revenue
     * above equity and the firm small in its market, and only capital differs between the two.
     */
    #[DataProvider('financialIndustries')]
    public function testAGoodwillWriteOffLeavesTheCapacityTargetWhole(string $industry): void
    {
        $strategy = Sectors::getBusinessModelStrategy(Sectors::INDUSTRY_METRICS[$industry]['business_model']);
        $macroState = new MacroStateDTO(policyRate: 0.03, equityRiskPremium: 0.05, corporateTaxRate: 0.20, policyRateEma: 0.03, yield5yEma: 0.04, yield2yEma: 0.035, yield10yEma: 0.045, nominalGdpIndex: 1.0);
        $math = $this->createStub(MathUtility::class);

        $beforeWriteOff = $strategy->getTargetMetrics($this->firm($industry, equity: 80e9, goodwill: 30e9), $macroState, $math);
        $afterWriteOff = $strategy->getTargetMetrics($this->firm($industry, equity: 50e9, goodwill: 0.0), $macroState, $math);

        $this->assertEqualsWithDelta($afterWriteOff['invested_capital'], $beforeWriteOff['invested_capital'], 1e-3, "{$industry}: capital base");
        $this->assertEqualsWithDelta($afterWriteOff['baseline_roic'], $beforeWriteOff['baseline_roic'], 1e-12, "{$industry}: return target");
    }

    /**
     * The regulator's leverage test counts capital that can absorb a loss. A broker with $400B of debt on $100B
     * of book equity is well inside its 8x limit; the same broker carrying $70B of goodwill runs 13x tangible
     * leverage and is barred from paying out.
     */
    public function testPayoutLocksReadTangibleLeverage(): void
    {
        $model = new \App\Service\Model\Sector\BrokerageBusinessModel();
        $broker = new Stock();
        $broker->setIndustry('Brokerages');
        $broker->setTotalEquity('100000000000');
        $broker->setWholesaleDebt('400000000000');

        $this->assertNull($model->getRegulatoryDividendCap($broker, 0.0));
        $this->assertFalse($model->checkBuybackRegulatoryLockout($broker, 0.0));

        $broker->setGoodwill('70000000000');
        $this->assertSame(0.0, $model->getRegulatoryDividendCap($broker, 0.0));
        $this->assertTrue($model->checkBuybackRegulatoryLockout($broker, 0.0));
    }

    /**
     * An insurer whose surplus is mostly goodwill cannot back the premium it writes. $90B of premium needs
     * $60B of surplus at the Kenney ratio; the regulator stops payouts below half of that. $100B of book
     * equity clears it, the $20B left once $80B of goodwill is deducted does not, while leverage stays well
     * inside the limit either way.
     */
    public function testAnInsurersDividendLockReadsTangibleCapital(): void
    {
        $model = new \App\Service\Model\Sector\RetailInsuranceBusinessModel();
        $insurer = new Stock();
        $insurer->setIndustry('Insurance - Diversified');
        $insurer->setTotalEquity('100000000000');
        $insurer->setCustomerDeposits('100000000000');
        $insurer->setTotalRevenue('90000000000');

        $this->assertNull($model->getRegulatoryDividendCap($insurer, 0.0));

        $insurer->setGoodwill('80000000000');
        $this->assertSame(0.0, $model->getRegulatoryDividendCap($insurer, 0.0));
    }

    /** Goodwill is deducted from CET1 and carries no risk weight, so a write-off leaves the ratio where it was. */
    public function testCet1DeductsGoodwillAndAWriteOffLeavesItWhole(): void
    {
        $model = new \App\Service\Model\Sector\CommercialBankBusinessModel();
        $bank = static function (float $equity, float $goodwill): Stock {
            $stock = new Stock();
            $stock->setTicker('GDWL');
            $stock->setIndustry('Banks - Diversified');
            $stock->setAociFiltered(false);
            $stock->setTotalEquity((string) $equity);
            $stock->setGoodwill((string) $goodwill);
            $stock->setCustomerDeposits('1000000000000');
            $stock->setCorporateTreasury('100000000000');

            return $stock;
        };

        $carried = $model->calculateCet1Ratio($bank(100e9, 40e9));
        $this->assertEqualsWithDelta($model->calculateCet1Ratio($bank(60e9, 0.0)), $carried, 1e-12);
        $this->assertLessThan($model->calculateCet1Ratio($bank(100e9, 0.0)), $carried);
    }

    /** @return iterable<string, array{string}> One industry per financial business model. */
    public static function financialIndustries(): iterable
    {
        $seen = [];
        foreach (Sectors::INDUSTRY_METRICS as $industry => $metrics) {
            $model = $metrics['business_model'] ?? 'none';
            if (isset($seen[$model]) || !Sectors::getBusinessModelStrategy($model)->isFinancial()) {
                continue;
            }
            $seen[$model] = true;

            yield $model => [$industry];
        }
    }

    private function firm(string $industry, float $equity, float $goodwill): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TNGB');
        $stock->setIndustry($industry);
        $stock->setSamRatio('1.0');
        $stock->setTotalEquity((string) $equity);
        $stock->setGoodwill((string) $goodwill);
        $stock->setTotalRevenue('300000000000');
        $stock->setOperatingMargin('0.30');
        $stock->setBaselineRoe('0.12');
        $stock->setCreditSpread('0.01');
        $stock->setFloatingDebtRatio('0.30');
        $stock->setWholesaleDebt('60000000000');
        $stock->setCustomerDeposits('200000000000');
        $stock->setCorporateTreasury('100000000000');

        return $stock;
    }
}

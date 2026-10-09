<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\View\CreditHealthBuilder;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The card shows what would put an operating company into bankruptcy: a payment default and the grace left
 * to cure it, the committed line, the covenant and the coverage. A firm the capital ratio governs is judged
 * on that instead, and a delisted one owes nothing any more.
 */
class CreditHealthBuilderTest extends TestCase
{
    private DebtEngine $debtEngine;
    private MacroStateDTO $macroState;

    protected function setUp(): void
    {
        $this->debtEngine = new DebtEngine(new MathUtility(), CorporateMetrics::getInstance());
        $this->macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, yield5yEma: 0.04);
    }

    public function testADefaultedFirmPastItsGracePeriodMustCureByTheNextReport(): void
    {
        $firm = $this->industrial();
        $firm->setPaymentDefault(true);
        $firm->setQuartersInDefault(FinancialConstants::PAYMENT_DEFAULT_GRACE_QUARTERS);

        $credit = $this->build($firm);

        $this->assertTrue($credit['inDefault']);
        $this->assertSame(FinancialConstants::PAYMENT_DEFAULT_GRACE_QUARTERS, $credit['quartersInDefault']);
        $this->assertTrue($credit['cureDueNextReport'], 'one more missed report and FailureSweep accelerates');
    }

    public function testACurrentFirmShowsItsLineCovenantCoverageAndDistanceToDefault(): void
    {
        $firm = $this->industrial();
        $firm->setRevolverCommitment('20000000000');
        $firm->setRevolverDrawn('5000000000');

        $credit = $this->build($firm);
        $health = $this->debtEngine->analyzeDebtHealth($firm, $this->macroState);

        $this->assertFalse($credit['inDefault']);
        $this->assertFalse($credit['cureDueNextReport']);
        $this->assertEqualsWithDelta(0.25, $credit['revolverUtilization'], 1e-12);
        $this->assertSame($health->netDebtToEbitda, $credit['netDebtToEbitda']);
        $this->assertSame($health->ebitdaCovenantLimit, $credit['covenantLimit'], 'an industrial carries a cash-flow covenant');
        $this->assertSame($health->interestCoverage, $credit['interestCoverage']);
        $this->assertSame($this->debtEngine->resolveDistanceToDefault($firm, $this->macroState), $credit['distanceToDefault']);
    }

    public function testAFirmWithNoCommittedLineShowsNoUtilization(): void
    {
        $this->assertNull($this->build($this->industrial())['revolverUtilization']);
    }

    public function testAFirmItsCapitalRatioGovernsGetsNoCard(): void
    {
        $bank = $this->industrial()->setIndustry('Banks - Diversified');

        $this->assertNull((new CreditHealthBuilder($this->debtEngine))->build($bank, $this->macroState)['creditHealth']);
    }

    public function testADelistedFirmGetsNoCard(): void
    {
        $shell = $this->industrial();
        $shell->setIsBankrupt(true);

        $this->assertNull((new CreditHealthBuilder($this->debtEngine))->build($shell, $this->macroState)['creditHealth']);
    }

    /** @return array<string, mixed> */
    private function build(Stock $stock): array
    {
        $credit = (new CreditHealthBuilder($this->debtEngine))->build($stock, $this->macroState)['creditHealth'];
        $this->assertNotNull($credit);

        return $credit;
    }

    private function industrial(): Stock
    {
        $firm = StockBuilder::create('MILL')
            ->withPrice(80.0)
            ->withSharesOutstanding(1_000_000_000)
            ->withTotalEquity(100e9)
            ->withWholesaleDebt(60e9)
            ->withVolatility(0.30)
            ->build()
            ->setIndustry('Tools & Accessories');
        $firm->setTotalRevenue('90000000000');
        $firm->setOperatingMargin('0.15');

        return $firm;
    }
}

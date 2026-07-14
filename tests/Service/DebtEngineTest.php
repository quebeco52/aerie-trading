<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\CreditRatingAgency;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DebtEngineTest extends TestCase
{
    private MathUtility|MockObject $mathUtilityMock;
    private CorporateMetrics|MockObject $corporateMetricsMock;
    private CreditRatingAgency $creditRatingAgency;
    private MarketEventPublisher|MockObject $marketEventPublisherMock;
    private DebtEngine $engine;

    protected function setUp(): void
    {
        $this->mathUtilityMock = $this->createMock(MathUtility::class);
        $this->corporateMetricsMock = $this->createMock(CorporateMetrics::class);
        $this->creditRatingAgency = new CreditRatingAgency();
        $this->marketEventPublisherMock = $this->createMock(MarketEventPublisher::class);

        $this->engine = new DebtEngine(
            $this->mathUtilityMock,
            $this->corporateMetricsMock,
            $this->creditRatingAgency,
            $this->marketEventPublisherMock
        );
    }

    public function testDebtTurnoverTriggersCreditRatingDowngradeEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEBT');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('100000000');
        $stock->setWholesaleDebt('50000000');
        $stock->setVolatility('0.25');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.21,
            yield5yEma: 0.04
        );

        // Distance to Default falls to 1.2 -> clamped to BB (downgrade from BBB)
        $this->mathUtilityMock->method('calculateDistanceToDefault')->willReturn(1.2);
        $this->mathUtilityMock->method('calculateMertonCreditSpread')->willReturn(0.04);

        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_DOWNGRADE'),
                $this->stringContains('downgraded from BBB to BB'),
                $this->equalTo(-3.0)
            );

        $this->engine->calculateQuarterlyInterestAndTurnover($stock, $macroState, false, 'General');

        $this->assertSame('BB', $stock->getCreditRating());
    }

    public function testDebtTurnoverTriggersCreditRatingUpgradeEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('SAFE');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('200000000');
        $stock->setWholesaleDebt('10000000');
        $stock->setVolatility('0.15');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('200.00');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.21,
            yield5yEma: 0.04
        );

        // Distance to Default rises to 3.6 -> clamped to A (upgrade from BBB)
        $this->mathUtilityMock->method('calculateDistanceToDefault')->willReturn(3.6);
        $this->mathUtilityMock->method('calculateMertonCreditSpread')->willReturn(0.005);

        $this->marketEventPublisherMock->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo($stock),
                $this->equalTo('CREDIT_UPGRADE'),
                $this->stringContains('upgraded from BBB to A'),
                $this->equalTo(2.0)
            );

        $this->engine->calculateQuarterlyInterestAndTurnover($stock, $macroState, false, 'General');

        $this->assertSame('A', $stock->getCreditRating());
    }
}

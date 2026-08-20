<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\ReinsuranceBusinessModel;
use PHPUnit\Framework\TestCase;

class ReinsuranceBusinessModelTest extends TestCase
{
    private ReinsuranceBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ReinsuranceBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualRevenueStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('50000000000');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.045,
            policyRateEma: 0.025
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.65,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('treaty_reinsurance', $result->streamRevenue);
        $this->assertArrayHasKey('catastrophe_bonds', $result->streamRevenue);
        $this->assertArrayHasKey('treaty_reinsurance', $result->streamZ);
        $this->assertArrayHasKey('catastrophe_bonds', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['treaty_reinsurance']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['catastrophe_bonds']);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['treaty_reinsurance'] + $result->streamRevenue['catastrophe_bonds'],
            1.0
        );
    }

    public function testHardMarketPricingExpandsTreatyRevenueWithoutMarginDoubleDip(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        // Very low equity relative to expected revenue creates high surplus deficit
        $stock->setTotalEquity('1000000000'); // $1B vs $10B expected revenue (target surplus = $6.67B)

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.04,
            policyRateEma: 0.02
        );

        $expectedRevenue = 10_000_000_000.0;
        $realizedVariableMargin = 0.60;

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: $expectedRevenue,
            realizedVariableMargin: $realizedVariableMargin,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathMock
        );

        // Treaty revenue should be boosted by hard market pricing bonus (> baseline weight * expectedRevenue)
        $baselineTreatyRevenue = $expectedRevenue * ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $this->assertGreaterThan($baselineTreatyRevenue, $result->streamRevenue['treaty_reinsurance']);

        // Margin should NOT have hardMarketPricingBonus subtracted from it (it should equal realizedVariableMargin since claimZ = 0)
        $this->assertEqualsWithDelta($realizedVariableMargin, $result->clampedMargin, 0.0001);
    }

    public function testCatBondAttachmentBreachAndRetrocessionShielding(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('30000000000');

        $mathMock = $this->createMock(MathUtility::class);
        // Return Z-scores: treatyZ = 0.0, catBondZ = 0.0, claimZ = -3.0 (breaches attachment at -2.50)
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, 0.0, -3.0);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.04,
            policyRateEma: 0.02
        );

        $expectedRevenue = 10_000_000_000.0;
        $realizedVariableMargin = 0.60;

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: $expectedRevenue,
            realizedVariableMargin: $realizedVariableMargin,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertEquals(ShockEvent::REINSURANCE_ATTACHMENT_BREACH, $result->eventType);

        // Cat bond revenue should suffer default haircut (50%)
        $expectedCatBondRevenue = $expectedRevenue * ReinsuranceBusinessModel::CAT_BOND_WEIGHT * (1.0 - ReinsuranceBusinessModel::CAT_BOND_DEFAULT_HAIRCUT);
        $this->assertEqualsWithDelta($expectedCatBondRevenue, $result->streamRevenue['catastrophe_bonds'], 1.0);

        // Underwriting loss should be capped by retrocession shielding (MAX_REINSURED_LOSS_SHOCK * treatyWeight)
        $maxShock = ReinsuranceBusinessModel::MAX_REINSURED_LOSS_SHOCK * ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $expectedMaxMargin = $realizedVariableMargin + $maxShock;
        $this->assertLessThanOrEqual($expectedMaxMargin + 0.001, $result->clampedMargin);
    }
}

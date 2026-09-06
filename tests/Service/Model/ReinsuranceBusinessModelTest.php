<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
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

        $mathMock = $this->createStub(MathUtility::class);
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

    public function testCatBondAttachmentBreachAbsorbsUncappedTailRisk(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('30000000000');

        $mathMock = $this->createStub(MathUtility::class);
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

        // Underwriting shock applies to Treaty Reinsurance book (0.65 weight)
        // and Cat Bond collateral absorbs 40% of tail severity beyond attachment point (Z = -2.50)
        $treatyShock = 3.0 * ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR * ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $excessTailShock = (3.0 - 2.50) * ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR * ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $catBondShield = $excessTailShock * ReinsuranceBusinessModel::CAT_BOND_ATTACHMENT_SHIELD_SHARE;
        $expectedNetShock = $treatyShock - $catBondShield;
        $expectedMargin = $realizedVariableMargin + $expectedNetShock;
        $this->assertEqualsWithDelta($expectedMargin, $result->clampedMargin, 0.0001);
    }

    public function testExtremeCatastropheExpandsVariableMarginBeyondStandardCap(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('30000000000');

        $mathMock = $this->createStub(MathUtility::class);
        // Extreme black swan catastrophe: claimZ = -12.0
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, 0.0, -12.0);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.04,
            policyRateEma: 0.02
        );

        $expectedRevenue = 10_000_000_000.0;
        $realizedVariableMargin = 0.95;

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: $expectedRevenue,
            realizedVariableMargin: $realizedVariableMargin,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathMock
        );

        // Variable margin expands beyond the standard 1.50 corporate clamp, bounded by 1.65 PML ceiling
        $this->assertGreaterThan(1.50, $result->clampedMargin);
        $this->assertLessThanOrEqual(ReinsuranceBusinessModel::MAX_REINSURANCE_MARGIN_CLAMP, $result->clampedMargin);
    }

    public function testReinsuranceSolvencyThresholdsTreatPositiveEquityAsSolvent(): void
    {
        $this->assertSame(0.0, $this->model->getBankruptEquityThreshold());
        $this->assertSame(2.0, $this->model->getDistressEquityThreshold());
        $this->assertSame(4.0, $this->model->getWarningEquityThreshold());
    }

    public function testDividendHaltedWhenSurplusImpairedDuringCatastropheQuarter(): void
    {
        $stock = new Stock();
        $stock->setTicker('SAFE');
        $stock->setTotalEquity('373000000000'); // $373B remaining equity
        $stock->setTotalRevenue('63850000000000'); // $63.85T annual revenue -> target surplus = $42.57T
        $stock->setSharesOutstanding('1000000000');
        $stock->setRoeTtm('0.15');
        $stock->setWholesaleDebt('11000000000000');
        $stock->setCustomerDeposits('59000000000000');

        // Negative quarterly EPS during catastrophe quarter
        $quarterlyEps = -130.22;
        $sustainableBase = $this->model->getSustainableDividendBase($stock, $quarterlyEps, 500000000000.0, 0.02);
        $this->assertSame(0.0, $sustainableBase, 'Dividends must be completely halted when capital surplus is impaired.');

        $regCap = $this->model->getRegulatoryDividendCap($stock, 46000000000000.0);
        $this->assertSame(0.0, $regCap, 'Regulatory dividend cap must be 0.0 when capital ratio is in regulatory distress.');
    }
}

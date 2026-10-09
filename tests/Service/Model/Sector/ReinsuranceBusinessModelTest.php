<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
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

    /**
     * A capital shortfall reaches treaty revenue once, through the hard-market rate on the pricing-power
     * multiplier. Adding it to this quarter's treaty revenue as well booked a price rise as volume, which the
     * engine then costs at the full claims ratio.
     */
    public function testCapitalShortfallStartsTheHardMarketWithoutBookingItAsTreatyVolume(): void
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

        $baselineTreatyRevenue = $expectedRevenue * ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $this->assertEqualsWithDelta($baselineTreatyRevenue, $result->streamRevenue['treaty_reinsurance'], 1.0);

        $regimeKey = \App\Service\Model\StreamContext::REGIME_STATE_PREFIX . ReinsuranceBusinessModel::REGIME_HARD_MARKET;
        $this->assertSame(1.0, $result->streamZ[$regimeKey] ?? 0.0, 'The shortfall must start the regime that carries the rate.');

        // A quiet quarter: only the unused large-loss layer allowance is credited back.
        $expectedLayer = ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT * ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR
            * Distributions::calculateNormalLowerPartialMoment(ReinsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD);
        $this->assertEqualsWithDelta($realizedVariableMargin - $expectedLayer, $result->clampedMargin, 1e-9);
    }

    public function testCatBondAttachmentBreachAbsorbsUncappedTailRisk(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('30000000000');

        $mathMock = $this->createStub(MathUtility::class);
        // treatyZ = 0.0, catBondZ = 0.0, claimZ = -3.0: a large loss of the reinsurer's own in a heavy district season.
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, 0.0, -3.0);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $burden = 6.0;
        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.04,
            policyRateEma: 0.02,
            catastropheLossIndexEma: $burden,
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

        // Claims on the treaty book: the large-loss layer below the threshold plus the reinsurer's share of the
        // district season; cat bond collateral absorbs its share of everything above the retention.
        $treaty = ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $threshold = ReinsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD;
        $layer = $treaty * ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR * ($threshold - (-3.0));
        $expectedLayer = $treaty * ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR * Distributions::calculateNormalLowerPartialMoment($threshold);
        $district = $treaty * ReinsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD * ($burden - 1.0);
        $gross = $layer + $district;
        $shield = ($gross - (ReinsuranceBusinessModel::MAX_REINSURED_LOSS_SHOCK * $treaty)) * ReinsuranceBusinessModel::CAT_BOND_ATTACHMENT_SHIELD_SHARE;
        $this->assertGreaterThan(0.0, $shield, 'The scenario must breach the retention.');
        $this->assertEqualsWithDelta($realizedVariableMargin + $gross - $expectedLayer - $shield, $result->clampedMargin, 1e-9);
    }

    /** The same large loss of its own, in an average district year, stays inside the retention: no breach, no haircut. */
    public function testOwnLargeLossInAnAverageYearStaysInsideTheRetention(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('30000000000');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, 0.0, -3.0);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $expectedRevenue = 10_000_000_000.0;
        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: $expectedRevenue,
            realizedVariableMargin: 0.60,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: new MacroStateDTO(outputGapEma: 0.0, yield10yEma: 0.04, policyRateEma: 0.02),
            mathUtility: $mathMock
        );

        $this->assertNotSame(ShockEvent::REINSURANCE_ATTACHMENT_BREACH, $result->eventType);
        $this->assertEqualsWithDelta($expectedRevenue * ReinsuranceBusinessModel::CAT_BOND_WEIGHT, $result->streamRevenue['catastrophe_bonds'], 1.0);
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

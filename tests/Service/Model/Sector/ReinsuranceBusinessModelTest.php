<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

class ReinsuranceBusinessModelTest extends TestCase
{
    private ReinsuranceBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ReinsuranceBusinessModel();
        $this->mathUtility = MathUtility::ownStream(20261024);
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
            policyRateEma: 0.025,
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.65,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility,
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
            1.0,
        );
    }

    /**
     * A capital shortfall against premium demand reaches treaty revenue once, through the hard-market rate on the
     * pricing-power multiplier. Adding it to this quarter's treaty revenue as well booked a price rise as volume, which
     * the engine then costs at the full claims ratio.
     */
    public function testCapitalShortfallStartsTheHardMarketWithoutBookingItAsTreatyVolume(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        // $1B of surplus against $40B of annual premium demand, which needs $26.7B at the Kenney ratio.
        $stock->setTotalEquity('1000000000');
        $stock->setSamRatio('1.0');
        $stock->setEarningsMomentumZ([
            ReinsuranceBusinessModel::STATE_PREMIUM_FRANCHISE_SHARE => 40_000_000_000.0 / \App\Service\Corporate\CorporateMetrics::BASELINE_SECTOR_TAM,
        ]);

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.04,
            policyRateEma: 0.02,
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
            mathUtility: $mathMock,
        );

        $baselineTreatyRevenue = $expectedRevenue * ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $this->assertEqualsWithDelta($baselineTreatyRevenue, $result->streamRevenue['treaty_reinsurance'], 1.0);

        $regimeKey = \App\Service\Model\StreamContext::REGIME_STATE_PREFIX . ReinsuranceBusinessModel::REGIME_HARD_MARKET;
        $this->assertSame(1.0, $result->streamZ[$regimeKey] ?? 0.0, 'The shortfall must start the regime that carries the rate.');

        // A quiet quarter: only the unused large-loss layer allowance is credited back, and the collateral's share of
        // the layer is paid for, per unit of the treaty book's premium at the base rate.
        $expectedLayer = ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR * Distributions::calculateNormalLowerPartialMoment(ReinsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD);
        $this->assertEqualsWithDelta(self::baseCostRatio($realizedVariableMargin, $result) + ((self::shieldPremium() - $expectedLayer) * $this->treatyVolume($stock, $macro)), $result->clampedMargin, 1e-9);
    }

    public function testCatBondAttachmentBreachAbsorbsUncappedTailRisk(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('30000000000');

        // treatyZ = 0.0, catBondZ = 0.0, claimZ = -3.0: a large loss of the reinsurer's own in a heavy district season.
        $mathMock = new ScriptedDraws(generatePersistentZ: [0.0, 0.0, -3.0]);
        $volumeStock = clone $stock;

        // Six times the quarter's average district losses.
        $burden = 6.0;
        $macro = new MacroStateDTO(...[
            'outputGapEma' => 0.0,
            'yield10yEma' => 0.04,
            'policyRateEma' => 0.02,
            ...DistrictCatastropheWindow::prime($stock, $burden),
        ]);

        $expectedRevenue = 10_000_000_000.0;
        $realizedVariableMargin = 0.60;

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: $expectedRevenue,
            realizedVariableMargin: $realizedVariableMargin,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathMock,
        );

        $this->assertEquals(ShockEvent::REINSURANCE_ATTACHMENT_BREACH, $result->eventType);

        // Claims on the treaty book, per unit of its premium: the large-loss layer below the threshold plus the
        // reinsurer's share of the district season; the bond's collateral takes its share above the retention.
        $threshold = ReinsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD;
        $cover = self::layer();
        $largeLoss = ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR * ($threshold - (-3.0));
        $expectedLayer = ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR * Distributions::calculateNormalLowerPartialMoment($threshold);
        $gross = $largeLoss + (ReinsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD * ($burden - 1.0));
        $tail = min($cover['limit'], $gross - $cover['retention']);
        $this->assertGreaterThan(0.0, $tail, 'The scenario must breach the retention.');

        // The investors' principal is written down by the share of the layer the storm used, and the fees with it.
        $this->assertEqualsWithDelta($expectedRevenue * ReinsuranceBusinessModel::CAT_BOND_WEIGHT * (1.0 - ($tail / $cover['limit'])), $result->streamRevenue['catastrophe_bonds'], 1.0);
        $shield = $tail * ReinsuranceBusinessModel::CAT_BOND_ATTACHMENT_SHIELD_SHARE;
        $treatyVolume = $result->streamRevenue['treaty_reinsurance'] / ($this->model->getMacroPhysics($volumeStock, $macro)['pricing_power_multiplier'] * $result->actualRevenue);
        $this->assertEqualsWithDelta(self::baseCostRatio($realizedVariableMargin, $result) + (($gross - $expectedLayer - $shield + self::shieldPremium()) * $treatyVolume), $result->clampedMargin, 1e-9);
    }

    /**
     * The same large loss of its own, in an average district year, stays inside the retention: no breach, no haircut.
     */
    public function testOwnLargeLossInAnAverageYearStaysInsideTheRetention(): void
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('30000000000');

        $mathMock = new ScriptedDraws(generatePersistentZ: [0.0, 0.0, -3.0]);

        $expectedRevenue = 10_000_000_000.0;
        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: $expectedRevenue,
            realizedVariableMargin: 0.60,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: new MacroStateDTO(outputGapEma: 0.0, yield10yEma: 0.04, policyRateEma: 0.02),
            mathUtility: $mathMock,
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

        // Extreme black swan catastrophe: claimZ = -12.0
        $mathMock = new ScriptedDraws(generatePersistentZ: [0.0, 0.0, -12.0]);

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.04,
            policyRateEma: 0.02,
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
            mathUtility: $mathMock,
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

    /**
     * One quarter of the default reinsurer on scripted treaty, fee and claim draws in a district quarter at a burden.
     */
    private function quarter(float $catBondZ, float $claimZ, float $burden): \App\DTO\ActualFinancialsDTO
    {
        $stock = new Stock();
        $stock->setTicker('REIN');
        $stock->setBeta('0.9');
        $stock->setTotalEquity('30000000000');
        $mathMock = new ScriptedDraws(generatePersistentZ: [0.0, $catBondZ, $claimZ]);
        $macro = new MacroStateDTO(...['outputGapEma' => 0.0, 'yield10yEma' => 0.04, 'policyRateEma' => 0.02, ...DistrictCatastropheWindow::prime($stock, $burden)]);

        return $this->model->computeActualFinancials($stock, 10_000_000_000.0, 0.60, 1_000_000_000.0, 0.10, $macro, $mathMock);
    }

    /** The ILS fees carry none of the treaty book's claims: more fee revenue, the same claim dollars. */
    public function testIlsFeesCarryNoClaims(): void
    {
        $claims = static fn(\App\DTO\ActualFinancialsDTO $quarter): float => $quarter->actualVariableCosts - (self::baseCostRatio(0.60, $quarter) * $quarter->actualRevenue);
        $quiet = $this->quarter(0.0, -3.0, 6.0);
        $busy = $this->quarter(2.0, -3.0, 6.0);

        $this->assertGreaterThan($quiet->streamRevenue['catastrophe_bonds'], $busy->streamRevenue['catastrophe_bonds']);
        $this->assertEqualsWithDelta($claims($quiet), $claims($busy), 1.0);
    }

    /**
     * A storm that uses half the bond's layer writes half the investors' principal down, and the fees charged on it
     * with it; new issuance rebuilds it over about a year. A flat half was taken whatever the storm used.
     */
    public function testIlsFeeBaseFallsByPrincipalWrittenDownAndRebuilds(): void
    {
        $cover = self::layer();
        $feeBase = function (float $layerUsed) use ($cover): array {
            $burden = 1.0 + (($cover['retention'] + ($layerUsed * $cover['limit'])) / ReinsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD);
            // A claim draw at the threshold leaves the reinsurer's own large-loss layer out of it.
            $quarter = $this->quarter(0.0, ReinsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD, $burden);

            return [$quarter->streamRevenue['catastrophe_bonds'] / (10_000_000_000.0 * ReinsuranceBusinessModel::CAT_BOND_WEIGHT), $quarter->streamZ[ReinsuranceBusinessModel::STATE_ILS_PRINCIPAL_INDEX]];
        };

        [$half, $halfNext] = $feeBase(0.5);
        [$little] = $feeBase(0.01);

        $this->assertEqualsWithDelta(0.5, $half, 1e-6);
        $this->assertEqualsWithDelta(0.99, $little, 1e-6);
        $this->assertEqualsWithDelta(1.0 - (0.5 * exp(-0.25 / ReinsuranceBusinessModel::ILS_REISSUANCE_YEARS)), $halfNext, 1e-9);
    }

    /** The cost line before the catastrophe terms: in full on the treaty book, its expense share on the ILS fees. */
    private static function baseCostRatio(float $structural, \App\DTO\ActualFinancialsDTO $quarter): float
    {
        return $structural * ($quarter->streamRevenue['treaty_reinsurance'] + ($quarter->streamRevenue['catastrophe_bonds'] * ReinsuranceBusinessModel::BASE_EXPENSE_RATIO_SHARE)) / $quarter->actualRevenue;
    }

    /** @return array{retention: float, limit: float, expected_recovery: float} The default reinsurer's own layer. */
    private static function layer(): array
    {
        return ReinsuranceBusinessModel::catastropheLayer(ReinsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD, ReinsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR, ReinsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD);
    }

    /**
     * The collateral's share of the cover, bought at the cover's price on its own layer, per unit of treaty premium.
     */
    private static function shieldPremium(): float
    {
        return ReinsuranceBusinessModel::CAT_BOND_ATTACHMENT_SHIELD_SHARE * self::layer()['expected_recovery'] * (1.0 + ReinsuranceBusinessModel::CATASTROPHE_COVER_RATE_LOADING);
    }

    /** The treaty book's premium at the base rate per unit of revenue, for a quarter whose streams draw nothing. */
    private function treatyVolume(Stock $stock, MacroStateDTO $macro): float
    {
        return ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT / $this->model->getMacroPhysics($stock, $macro)['pricing_power_multiplier'];
    }
}

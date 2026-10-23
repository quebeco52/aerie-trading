<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\TestCase;

class RetailInsuranceBusinessModelTest extends TestCase
{
    private RetailInsuranceBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new RetailInsuranceBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDistinctPncAndLifeStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('DOVE');
        $stock->setBeta('0.8');
        $stock->setTotalEquity('50000000000');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.045,
            policyRateEma: 0.025,
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.70,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.08,
            macroState: $macro,
            mathUtility: $this->mathUtility,
        );

        $this->assertArrayHasKey('property_casualty_premiums', $result->streamRevenue);
        $this->assertArrayHasKey('life_insurance_premiums', $result->streamRevenue);
        $this->assertArrayHasKey('property_casualty_premiums', $result->streamZ);
        $this->assertArrayHasKey('life_insurance_premiums', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['property_casualty_premiums']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['life_insurance_premiums']);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['property_casualty_premiums'] + $result->streamRevenue['life_insurance_premiums'],
            1.0,
        );
    }

    /**
     * A carrier short of the capital its premium demand requires hardens its renewal rates through the regime,
     * which reaches the P&L via the pricing-power multiplier. Its claims this quarter cost the same as a
     * well-capitalised peer's.
     */
    public function testCapitalShortfallDoesNotDiscountThisQuartersClaims(): void
    {
        $run = function (string $equity): \App\DTO\ActualFinancialsDTO {
            $stock = new Stock();
            $stock->setTicker('DOVE');
            $stock->setBeta('0.8');
            $stock->setTotalEquity(\App\Service\Math\Decimal::of($equity));
            // 40B of annual premium demand on a unit serviceable market: 26.7B of surplus covers it.
            $stock->setSamRatio('1.0');
            // Both quote off the same observed capacity, so the rate their books are written at is the same too.
            $stock->setEarningsMomentumZ([
                RetailInsuranceBusinessModel::STATE_PREMIUM_FRANCHISE_SHARE => 40_000_000_000.0 / \App\Service\Corporate\CorporateMetrics::BASELINE_SECTOR_TAM,
                RetailInsuranceBusinessModel::STATE_OBSERVED_CAPACITY => 1.0,
            ]);
            $mathMock = $this->createStub(MathUtility::class);
            $mathMock->method('generatePersistentZ')->willReturn(0.0);
            $mathMock->method('generateStandardNormal')->willReturn(0.0);

            return $this->model->computeActualFinancials($stock, 10_000_000_000.0, 0.70, 1_000_000_000.0, 0.08, new MacroStateDTO(), $mathMock);
        };

        $impaired = $run('5000000000');
        $adequate = $run('50000000000');
        $regimeKey = \App\Service\Model\StreamContext::REGIME_STATE_PREFIX . RetailInsuranceBusinessModel::REGIME_HARD_MARKET;

        $this->assertSame(1.0, $impaired->streamZ[$regimeKey] ?? 0.0, 'The shortfall must start the regime that carries the rate.');
        $this->assertEqualsWithDelta($adequate->clampedMargin, $impaired->clampedMargin, 1e-12);
    }

    /** A composite carrying the given life reserves on a float it invests at a bond book yield. */
    private function composite(float $lifeReserves, float $bookYield): Stock
    {
        return (new Stock())->setTicker('DOVE')->setBeta('0.8')->setTotalEquity('85000000000')
            ->setCorporateTreasury('275000000000')->setCustomerDeposits('275000000000')->setOperatingMargin('0.045')
            ->setSecuritiesCarryingYield($bookYield)
            ->setEarningsMomentumZ([RetailInsuranceBusinessModel::STATE_LIFE_RESERVES => $lifeReserves]);
    }

    /**
     * A life reserve is not free float: it credits its holders the bond book's yield less the life investment spread,
     * never less than the statutory floor, and the insurer keeps the spread.
     */
    public function testLifeReservesCreditTheirHoldersTheBookYieldLessTheSpread(): void
    {
        $macro = new MacroStateDTO();
        // The float's mix held fixed, so the two books differ only in what their life reserves credit.
        $model = new class extends RetailInsuranceBusinessModel {
            protected function resolveFloatEquityWeight(Stock $stock): float
            {
                return self::FLOAT_EQUITY_WEIGHT;
            }
        };
        $credited = fn(float $bookYield): float => $model->calculateInterestIncome($this->composite(0.0, $bookYield), $macro)
            - $model->calculateInterestIncome($this->composite(100_000_000_000.0, $bookYield), $macro);

        $this->assertEqualsWithDelta(100_000_000_000.0 * (0.045 - RetailInsuranceBusinessModel::LIFE_INVESTMENT_SPREAD), $credited(0.045), 1.0);
        $this->assertEqualsWithDelta(100_000_000_000.0 * RetailInsuranceBusinessModel::NONFORFEITURE_MIN_CREDITING_RATE, $credited(0.015), 1.0, 'the statutory floor');
    }

    /**
     * The assets behind life reserves are matched with bonds: the property-casualty float holds the firm's equity
     * weight, the life reserves a quarter of it, and the bond book takes what the equities give up.
     */
    public function testLifeReservesHoldLessEquityThanThePropertyCasualtyFloat(): void
    {
        $macro = new MacroStateDTO();
        $casualty = $this->composite(0.0, 0.045);
        $composite = $this->composite(200_000_000_000.0, 0.045);
        $sleeve = fn(Stock $stock): float => $this->model->resolveEquitySleeve($stock, $macro)['value'];

        $this->assertEqualsWithDelta(275_000_000_000.0 * RetailInsuranceBusinessModel::FLOAT_EQUITY_WEIGHT, $sleeve($casualty), 1.0);
        $this->assertEqualsWithDelta(
            (75_000_000_000.0 * RetailInsuranceBusinessModel::FLOAT_EQUITY_WEIGHT) + (200_000_000_000.0 * RetailInsuranceBusinessModel::LIFE_RESERVE_EQUITY_WEIGHT),
            $sleeve($composite),
            1.0,
        );
        $bondShare = RetailInsuranceBusinessModel::FLOAT_BOND_WEIGHT / (RetailInsuranceBusinessModel::FLOAT_LIQUIDITY_WEIGHT + RetailInsuranceBusinessModel::FLOAT_BOND_WEIGHT);
        $this->assertEqualsWithDelta(
            ($sleeve($casualty) - $sleeve($composite)) * $bondShare,
            $this->model->resolveSecuritiesBook($composite) - $this->model->resolveSecuritiesBook($casualty),
            1.0,
        );
    }

    /** The float a new life book brings is weighed at the spread it keeps, not the yield it passes on. */
    public function testTheWriteDecisionSeesTheSpreadNotTheGrossYield(): void
    {
        $free = new class extends RetailInsuranceBusinessModel {
            protected function resolveLifeCreditingRate(Stock $stock, MacroStateDTO $macroState): float
            {
                return 0.0;
            }
        };
        // No float held yet, so only the float the new book would bring is weighed.
        $stock = $this->composite(0.0, 0.045)->setCorporateTreasury('0')->setCustomerDeposits('0');
        $macro = new MacroStateDTO();

        $this->assertLessThan(
            $free->resolveTargetWrittenShare($stock, $macro, new MathUtility(), 85_000_000_000.0),
            $this->model->resolveTargetWrittenShare($stock, $macro, new MathUtility(), 85_000_000_000.0),
        );
    }
}

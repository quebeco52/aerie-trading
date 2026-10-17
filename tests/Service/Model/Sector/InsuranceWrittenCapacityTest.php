<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The written share of Kenney capacity: a Winter (1994) / Gron (1994) supply curve in the full book's return over
 * the hurdle, reached through renewals (first-order partial adjustment over the policy term).
 */
#[AllowMockObjectsWithoutExpectations]
final class InsuranceWrittenCapacityTest extends TestCase
{
    private const DISPERSION = 0.06;

    /**
     * More return over the hurdle never buys less book, the share stays inside [floor, 1], and it has no jump anywhere.
     */
    public function testTheSupplyCurveIsMonotoneBoundedAndContinuous(): void
    {
        $floor = InsuranceBusinessModel::MIN_WRITTEN_CAPACITY;
        // The steepest the curve can be: the normal density at its centre, over the discretionary range.
        $lipschitz = (1.0 - $floor) * Distributions::standardNormalPdf(0.0) / self::DISPERSION;
        $step = 0.0005;

        $previous = InsuranceBusinessModel::writtenShareAtExcessReturn(-0.50, self::DISPERSION);
        for ($excess = -0.50 + $step; $excess <= 0.50; $excess += $step) {
            $share = InsuranceBusinessModel::writtenShareAtExcessReturn($excess, self::DISPERSION);
            $this->assertGreaterThanOrEqual($previous, $share);
            $this->assertLessThanOrEqual($lipschitz * $step + 1e-12, $share - $previous, sprintf('No jump at excess %.4f.', $excess));
            $this->assertGreaterThanOrEqual($floor, $share);
            $this->assertLessThanOrEqual(1.0, $share);
            $previous = $share;
        }

        // A book that exactly earns its hurdle sits at the curve's centre; far either side it reaches its bounds.
        $this->assertEqualsWithDelta($floor + ((1.0 - $floor) / 2.0), InsuranceBusinessModel::writtenShareAtExcessReturn(0.0, self::DISPERSION), 1e-8);
        $this->assertEqualsWithDelta($floor, InsuranceBusinessModel::writtenShareAtExcessReturn(-1.0, self::DISPERSION), 1e-9);
        $this->assertEqualsWithDelta(1.0, InsuranceBusinessModel::writtenShareAtExcessReturn(1.0, self::DISPERSION), 1e-9);
    }

    /** In-force life policies are renewed whatever the rate: only the rest of the book is withdrawn. */
    public function testTheContractualBookIsWrittenWhateverTheRate(): void
    {
        $floor = InsuranceBusinessModel::MIN_WRITTEN_CAPACITY;

        $this->assertEqualsWithDelta(0.45 + (0.55 * $floor), InsuranceBusinessModel::writtenShareAtExcessReturn(-1.0, self::DISPERSION, 0.45), 1e-9);
        $this->assertEqualsWithDelta(1.0, InsuranceBusinessModel::writtenShareAtExcessReturn(-1.0, self::DISPERSION, 1.0), 1e-12);

        $retail = new RetailInsuranceBusinessModel();
        $stock = $this->underwriterAtCapacity(8.0);
        $this->assertGreaterThanOrEqual(
            $retail->resolveLifePremiumShare($stock) + ((1.0 - $retail->resolveLifePremiumShare($stock)) * $floor) - 1e-9,
            $retail->resolveTargetWrittenShare($stock, $this->neutralMacro(), new MathUtility(), (float) $stock->getTotalEquity()),
        );
    }

    /**
     * The renewal lag closes the gap to the target as e^(−dt/T), the exact step of the first-order adjustment, so
     * one step of dt equals two of dt/2 at any tick length.
     */
    public function testTheBookClosesTheGapToItsTargetAsExpOfDtOverThePolicyTerm(): void
    {
        $term = InsuranceBusinessModel::WRITTEN_SHARE_ADJUSTMENT_YEARS;
        $this->assertSame(InsuranceBusinessModel::POLICY_TERM_YEARS, $term);

        foreach ([FinancialConstants::QUARTER_YEARS, 1.0 / 360.0] as $dt) {
            $next = InsuranceBusinessModel::adjustWrittenShare(1.0, 0.40, $dt);
            $this->assertEqualsWithDelta(exp(-$dt / $term), ($next - 0.40) / (1.0 - 0.40), 1e-12, sprintf('dt = %.5f', $dt));

            $twoHalves = InsuranceBusinessModel::adjustWrittenShare(InsuranceBusinessModel::adjustWrittenShare(1.0, 0.40, $dt / 2.0), 0.40, $dt / 2.0);
            $this->assertEqualsWithDelta($next, $twoHalves, 1e-12);
        }

        // A year of renewals closes 1 − e^(−1) of the gap, not all of it: no switch survives into the book.
        $this->assertEqualsWithDelta(0.40 + (0.60 * exp(-1.0)), InsuranceBusinessModel::adjustWrittenShare(1.0, 0.40, 1.0), 1e-12);
    }

    /**
     * The quarter's physics moves the book in force one renewal step toward the target, and the next quarter's
     * revenue capacity is struck on that book, not on the target.
     */
    public function testTheQuarterAdvancesTheBookInForceAndCapacityReadsIt(): void
    {
        $model = new InsuranceBusinessModel();
        $macro = $this->neutralMacro();
        $math = $this->scriptedMath();

        $stock = $this->underwriterAtCapacity(1.30);
        $stock->setEarningsMomentumZ(array_merge($stock->getEarningsMomentumZ() ?? [], [InsuranceBusinessModel::STATE_WRITTEN_SHARE => 1.0]));
        $target = $model->resolveTargetWrittenShare($stock, $macro, $math, max(1.0, $stock->getStatutorySurplus()));
        $this->assertLessThan(0.9, $target, 'A soft market: the target is well below the book in force.');

        $result = $model->computeActualFinancials($stock, 1.0e9, 0.90, 1.0e8, 0.10, $macro, $math);
        $advanced = $result->streamZ[InsuranceBusinessModel::STATE_WRITTEN_SHARE] ?? null;
        $this->assertNotNull($advanced);
        $this->assertEqualsWithDelta(InsuranceBusinessModel::adjustWrittenShare(1.0, $target, FinancialConstants::QUARTER_YEARS), $advanced, 1e-9);

        $stock->setEarningsMomentumZ(array_merge($stock->getEarningsMomentumZ() ?? [], [InsuranceBusinessModel::STATE_WRITTEN_SHARE => $advanced]));
        $turnover = $model->getTargetMetrics($stock, $macro, $math)['baseline_roic']
            / ((float) $stock->getOperatingMargin() * (1.0 - $macro->corporateTaxRate));
        // The book is demand times the share in force, and demand is capacity over 1.3.
        $this->assertEqualsWithDelta(InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * $advanced / 1.30, $turnover, 1e-6);
    }

    private function neutralMacro(): MacroStateDTO
    {
        return new MacroStateDTO(
            policyRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            perceivedNeutralRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
        );
    }

    private function scriptedMath(): MathUtility
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal', 'generatePersistentZ', 'checkProbability'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $math->method('checkProbability')->willReturn(false);

        return $math;
    }

    /**
     * An underwriter with three times its surplus in float, whose Kenney capacity is the given multiple of its premium
     * demand.
     */
    private function underwriterAtCapacity(float $capacityToDemand): Stock
    {
        $samRatio = 2.0;
        $equity = 1_000_000_000_000.0;

        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setIndustry('Insurance - Specialty');
        $stock->setSystemicImportance('base');
        $stock->setSamRatio((string) $samRatio);
        $stock->setTotalEquity((string) $equity);
        $stock->setWholesaleDebt('0');
        $stock->setOperatingMargin('0.045');
        $stock->setCustomerDeposits((string) ($equity * 3.0));
        $stock->setCorporateTreasury((string) ($equity * 3.0));
        $stock->setTotalRevenue((string) ($equity * 0.50));
        $stock->setRoeTtm('0.10');
        $stock->setEarningsMomentumZ([
            InsuranceBusinessModel::STATE_PREMIUM_FRANCHISE_SHARE => InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * $equity / $capacityToDemand / (CorporateMetrics::BASELINE_SECTOR_TAM * $samRatio),
        ]);

        return $stock;
    }
}

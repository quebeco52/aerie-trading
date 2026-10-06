<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A carrier's structural margin is its through-the-cycle margin, catastrophe load included.
 *
 * The claim shock is therefore a pure risk around it: over many quarters of an average district year the
 * realized combined ratio averages the structural one. Charging the large-loss layer on top of a margin
 * that already carries it was a standing loss of ~2pp of revenue on the reinsurer's book, so its tuned
 * 4.5% underwriting margin was ~2.4% in expectation.
 */
final class InsuranceClaimPricingInvariantTest extends TestCase
{
    private const QUARTERS = 8_000;
    private const STRUCTURAL_COST_RATIO = 0.60;

    /** @return iterable<string, array{InsuranceBusinessModel}> */
    public static function carriers(): iterable
    {
        yield 'specialty' => [new InsuranceBusinessModel()];
        yield 'retail' => [new RetailInsuranceBusinessModel()];
        yield 'reinsurance' => [new ReinsuranceBusinessModel()];
    }

    #[DataProvider('carriers')]
    public function testClaimShockAveragesToTheStructuralCostRatio(InsuranceBusinessModel $model): void
    {
        $math = MathUtility::ownStream(20261006);
        $macro = new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04);
        $momentum = [];
        $sum = 0.0;
        $sumSq = 0.0;

        for ($i = 0; $i < self::QUARTERS; $i++) {
            $stock = new Stock();
            $stock->setTicker('MEAN');
            $stock->setBeta('1.0');
            // Three years of the book in surplus: no capital trigger, so nothing but claims moves the ratio.
            $stock->setTotalEquity('200000000000');
            $stock->setEarningsMomentumZ($momentum);

            // Revenue volatility off, so the expense ratio stays on its baseline and only claims vary.
            $result = $model->computeActualFinancials($stock, 10_000_000_000.0, self::STRUCTURAL_COST_RATIO, 1.0e9, 0.0, $macro, $math);
            $momentum = $result->streamZ;
            $sum += $result->clampedMargin;
            $sumSq += $result->clampedMargin ** 2;
        }

        $mean = $sum / self::QUARTERS;
        $standardError = sqrt(max(0.0, ($sumSq / self::QUARTERS) - ($mean ** 2)) / self::QUARTERS);

        $this->assertEqualsWithDelta(
            self::STRUCTURAL_COST_RATIO,
            $mean,
            4.0 * $standardError,
            sprintf('%s: mean cost ratio %.4f (se %.5f) must sit on the structural %.2f.', $model::class, $mean, $standardError, self::STRUCTURAL_COST_RATIO)
        );
    }
}

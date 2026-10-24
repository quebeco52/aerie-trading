<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The calibration funds a lender's through-the-cycle loss, so that is what its book has to lose while the District's
 * default rates sit at their trends and its collateral at the price its loans were written at. Read straight off the
 * Vasicek factor, the trend's factor sat below zero: a card book charged off 8% above it and a mortgage book below it,
 * quarter after quarter of an economy on trend.
 */
#[Group('unit')]
final class LenderThroughTheCycleLossTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function lenders(): iterable
    {
        foreach (['commercial_bank', 'credit_services', 'custody_bank', 'private_bank', 'shadow_bank'] as $model) {
            yield $model => [$model];
        }
    }

    #[DataProvider('lenders')]
    public function testEveryLenderLosesItsThroughTheCycleRateWhenDefaultsSitAtTrend(string $modelId): void
    {
        $model = Sectors::getBusinessModelStrategy($modelId);
        $this->assertInstanceOf(CommercialBankBusinessModel::class, $model);
        $stock = (new Stock())->setTicker('TTC')->setBeta('1.0');
        $atTrend = new MacroStateDTO(
            retailDefaultRateEma: 0.031,
            retailDefaultRateTrend: 0.031,
            corporateDefaultRateEma: 0.018,
            corporateDefaultRateTrend: 0.018,
        );

        $loss = (new \ReflectionMethod($model, 'resolveConditionalCreditLossRate'))
            ->invoke($model, $stock, $atTrend, new StreamContext([], new MathUtility()));

        $this->assertEqualsWithDelta($model->getThroughTheCycleCreditLossRate($stock), $loss['loss_rate'], 1e-12);
    }

    /**
     * Over the District's spread of default rates around their trends (sd 28% household, 41% corporate; District runs,
     * var/harness/banks/credit_at_trend.php) every lender's book averages its through-the-cycle loss. Read straight off
     * the factor, a card book averaged 7% above it.
     */
    #[DataProvider('lenders')]
    public function testEveryLenderAveragesItsThroughTheCycleRateOverTheDistrictsCycle(string $modelId): void
    {
        $model = Sectors::getBusinessModelStrategy($modelId);
        $this->assertInstanceOf(CommercialBankBusinessModel::class, $model);
        $stock = (new Stock())->setTicker('TTC')->setBeta('1.0');
        $loss = new \ReflectionMethod($model, 'resolveConditionalCreditLossRate');

        $sum = 0.0;
        $weight = 0.0;
        for ($x = -3.5; $x <= 3.5 + 1e-9; $x += 0.05) {
            $macro = new MacroStateDTO(
                retailDefaultRateEma: MacroConstants::RETAIL_DEFAULT_LONG_RUN_MEAN * (1.0 + (0.28 * $x)),
                retailDefaultRateTrend: MacroConstants::RETAIL_DEFAULT_LONG_RUN_MEAN,
                corporateDefaultRateEma: MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN * max(0.05, 1.0 + (0.41 * $x)),
                corporateDefaultRateTrend: MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN,
            );
            $density = exp(-0.5 * $x * $x);
            $weight += $density;
            $sum += $density * $loss->invoke($model, $stock, $macro, new StreamContext([], new MathUtility()))['loss_rate'];
        }

        $ttc = $model->getThroughTheCycleCreditLossRate($stock);
        $this->assertEqualsWithDelta($ttc, $sum / $weight, 0.02 * $ttc);
    }
}

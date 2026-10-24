<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Claims inflation outruns filed rates. Loss costs move with parts, materials and repair labour as they happen;
 * premiums are filed on past losses and catch up a year or so later, so an inflation surge lifts the loss ratio
 * and a return to target lets it fall back (US personal auto, 2022-24).
 */
#[Group('unit')]
final class InsuranceClaimsInflationTest extends TestCase
{
    /** @return array<string, array{class-string<InsuranceBusinessModel>}> */
    public static function insurers(): array
    {
        return [
            'specialty' => [InsuranceBusinessModel::class],
            'retail' => [RetailInsuranceBusinessModel::class],
            'reinsurance' => [ReinsuranceBusinessModel::class],
        ];
    }

    /**
     * @param class-string<InsuranceBusinessModel> $class
     * @return array{0: array<string, float>, 1: float} final stream state and final cost ratio
     */
    private function runQuarters(string $class, int $quarters, MacroStateDTO $macro, array $openingState = []): array
    {
        $model = new $class();
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setBeta('0.5');

        $math = new ScriptedDraws();

        $state = $openingState;
        $costRatio = 0.0;
        for ($q = 0; $q < $quarters; $q++) {
            $stock->setEarningsMomentumZ($state);
            $result = $model->computeActualFinancials($stock, 1_000_000_000.0, 0.60, 200_000_000.0, 0.10, $macro, $math);
            $state = $result->streamZ;
            $costRatio = $result->clampedMargin;
        }

        return [$state, $costRatio];
    }

    private static function inflation(float $coreGoods, float $supercore): MacroStateDTO
    {
        return new MacroStateDTO(coreGoodsInflationEma: $coreGoods, supercoreInflationEma: $supercore, inflationEma: MacroConstants::TARGET_INFLATION);
    }

    /** @param class-string<InsuranceBusinessModel> $class */
    #[DataProvider('insurers')]
    public function testClaimsInflationAtTargetLeavesTheLossRatioAlone(string $class): void
    {
        [$state] = $this->runQuarters($class, 8, self::inflation(MacroConstants::TARGET_INFLATION, MacroConstants::TARGET_INFLATION));

        $this->assertEqualsWithDelta(0.0, $state[InsuranceBusinessModel::STATE_UNRECOVERED_CLAIMS_COST], 1e-12);
    }

    /** @param class-string<InsuranceBusinessModel> $class */
    #[DataProvider('insurers')]
    public function testAnInflationSurgeRaisesTheCostRatioUntilRatesCatchUp(string $class): void
    {
        $calm = self::inflation(MacroConstants::TARGET_INFLATION, MacroConstants::TARGET_INFLATION);
        $surge = self::inflation(0.08, 0.05);
        [, $calmRatio] = $this->runQuarters($class, 12, $calm);
        [$surgeState, $surgeRatio] = $this->runQuarters($class, 12, $surge);

        $this->assertGreaterThan($calmRatio, $surgeRatio, 'Claims that inflate faster than filed rates cost margin.');

        // A steady excess x settles at x times the catch-up lag.
        $excess = (InsuranceBusinessModel::CLAIMS_COST_GOODS_SHARE * 0.08) + ((1.0 - InsuranceBusinessModel::CLAIMS_COST_GOODS_SHARE) * 0.05) - MacroConstants::TARGET_INFLATION;
        [$settled] = $this->runQuarters($class, 80, $surge);
        $this->assertEqualsWithDelta($excess * InsuranceBusinessModel::PREMIUM_CATCH_UP_YEARS, $settled[InsuranceBusinessModel::STATE_UNRECOVERED_CLAIMS_COST], 1e-6);

        // Back at target, rates catch up and the gap closes.
        [, $recoveredRatio] = $this->runQuarters($class, 24, $calm, $surgeState);
        $this->assertLessThan($surgeRatio, $recoveredRatio);
        $this->assertEqualsWithDelta($calmRatio, $recoveredRatio, 0.002, 'Six years on, the filed rates have caught up.');
    }
}

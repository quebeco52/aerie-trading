<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Event\EarningsReportedEvent;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MathUtility;
use App\Tests\Support\CorporateEngineFactory;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The franchise the market prices as news lands (MarketEngine) is the margin the next report books: the same quarter on
 * the same draws, run with and without franchise power, books its run costs, fixed and variable, scaled by the ratio of
 * the cost shares at the franchise and structural margins, so operating profit rises by that share of them and no cost
 * line turns negative however strong the franchise. The returns fair value is struck on are filed without it, since
 * fair value carries it on its own, while the operating return state reverts to it.
 */
final class FranchiseMarginEarningsTest extends TestCase
{
    use CorporateEngineFactory;

    private const POWER = 0.3;

    /** @return array{ctx: EarningsSimulationContext, stock: Stock} */
    private function report(float $franchisePower, ?float $operatingMargin = null): array
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public ?EarningsSimulationContext $ctx = null;

            public function dispatch(object $event, ?string $eventName = null): object
            {
                if ($event instanceof EarningsReportedEvent) {
                    $this->ctx = $event->getContext();
                }

                return $event;
            }
        };
        $engine = self::earningsEngine(new MathUtility(), $dispatcher);
        // seeded once the engine's mocks are built: PHPUnit names a new mock class from the same generator
        mt_srand(11);
        $builder = StockBuilder::matureIndustrial('FRAN');
        if ($operatingMargin !== null) {
            $builder->withOperatingMargin($operatingMargin);
        }
        $stock = $builder->build();
        $stock->setFranchisePower($franchisePower);

        $engine->calculate($stock, new MacroStateDTO(
            outputGapEma: 0.0,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            nominalGdpIndex: 1.0,
            marketVolatilityEma: 0.15,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.0010,
        ), EarningsEngine::resolveReportingTick('FRAN', 252), 252);

        $this->assertNotNull($dispatcher->ctx, 'the quarter was reported');

        return ['ctx' => $dispatcher->ctx, 'stock' => $stock];
    }

    public function testTheReportScalesItsRunCostsByTheFranchiseCostShare(): void
    {
        $plain = $this->report(0.0)['ctx'];
        $franchise = $this->report(self::POWER)['ctx'];

        $this->assertEqualsWithDelta($plain->actualRevenue, $franchise->actualRevenue, 1e-6 * $plain->actualRevenue, 'the same quarter on the same draws');
        $scale = (1.0 - FirmEconomics::franchiseMargin($franchise->stableMargin, self::POWER)) / (1.0 - $franchise->stableMargin);
        $this->assertLessThan(1.0, $scale);
        $this->assertEqualsWithDelta($scale, $franchise->franchiseCostScale, 1e-12);
        $this->assertEqualsWithDelta($plain->fixedCosts * $scale, $franchise->fixedCosts, 1e-9 * $plain->fixedCosts, 'the committed base');
        $this->assertEqualsWithDelta($plain->realizedVariableMargin * $scale, $franchise->realizedVariableMargin, 1e-9, 'the variable cost ratio booked');
        $this->assertEqualsWithDelta(
            (1.0 - $scale) * ($plain->fixedCosts + $plain->actualVariableCosts),
            $franchise->ebit - $plain->ebit,
            1e-6 * $plain->actualRevenue,
            'operating profit rises by the run costs the franchise saves, depreciation untouched',
        );
        $this->assertGreaterThan(0.0, $franchise->franchiseCostSaving);
    }

    /**
     * A thin-margin firm with a franchise power far out (a reinsurer's 1.5% at +5.7 was booking negative fixed costs
     * when the edge came off the fixed base alone) still books every run cost positive and a margin below one.
     */
    public function testAStrongFranchiseOnAThinMarginLeavesEveryCostLinePositive(): void
    {
        $ctx = $this->report(5.7, 0.015)['ctx'];

        $this->assertGreaterThan(0.0, $ctx->fixedCosts);
        $this->assertGreaterThan(0.0, $ctx->actualVariableCosts);
        $this->assertLessThan($ctx->actualRevenue, $ctx->ebit);
    }

    public function testValuationReturnsAreFiledWithoutTheFranchiseWhileTheOperatingReturnRevertsToIt(): void
    {
        $plain = $this->report(0.0);
        $franchise = $this->report(self::POWER);
        $ctx = $franchise['ctx'];

        // What is filed out is the structural quarter's saving, the franchise fair value prices; the part of its
        // variable edge that moves with the quarter's volume is the cycle's, so the two differ by a few percent of the
        // franchise.
        $added = FirmEconomics::franchiseReturn($ctx->franchiseCostSaving * EarningsEngine::TTM_QUARTERS, $ctx->corporateTaxRate, $ctx->investedCapital);
        $this->assertEqualsWithDelta($added, (float) $franchise['stock']->getCurrentRoic() - (float) $plain['stock']->getCurrentRoic(), 0.03 * $added, 'the filed return carries the franchise');

        $trailing = static fn(Stock $stock): float => (float) ($stock->getEarningsMomentumZ() ?? [])[EarningsEngine::STATE_TRAILING_RETURN];
        $anchor = static fn(Stock $stock): float => (float) ($stock->getEarningsMomentumZ() ?? [])[EarningsEngine::STATE_ANCHOR_RETURN];
        $this->assertEqualsWithDelta($trailing($plain['stock']), $trailing($franchise['stock']), 0.03 * $added, 'fair value is struck on the return without it');
        $this->assertEqualsWithDelta($anchor($plain['stock']), $anchor($franchise['stock']), 0.03 * $added);

        $this->assertEqualsWithDelta(
            $added,
            (float) $franchise['stock']->getRoicTtm() - (float) $plain['stock']->getRoicTtm(),
            0.01 * $added,
            'the operating return state keeps the franchise it earns rather than pulling it away with the moat',
        );
    }

}

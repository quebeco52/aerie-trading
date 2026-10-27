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
 * the same draws, run with and without franchise power, differs in operating profit by exactly the margin the power
 * adds on the structural revenue. The returns fair value is struck on are filed without it, since fair value carries it
 * on its own, while the operating return state reverts to it.
 */
final class FranchiseMarginEarningsTest extends TestCase
{
    use CorporateEngineFactory;

    private const POWER = 0.3;

    /** @return array{ctx: EarningsSimulationContext, stock: Stock} */
    private function report(float $franchisePower): array
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
        $stock = StockBuilder::matureIndustrial('FRAN')->build();
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

    public function testTheReportBooksTheFranchiseMarginOnItsStructuralRevenue(): void
    {
        $plain = $this->report(0.0)['ctx'];
        $franchise = $this->report(self::POWER)['ctx'];

        $this->assertEqualsWithDelta($plain->actualRevenue, $franchise->actualRevenue, 1e-6 * $plain->actualRevenue, 'the same quarter on the same draws');
        $this->assertGreaterThan(0.0, $franchise->franchiseCostSaving);
        $margin = FirmEconomics::franchiseMargin($franchise->stableMargin, self::POWER) - $franchise->stableMargin;
        $this->assertEqualsWithDelta($margin * $franchise->structuralRevenue, $franchise->franchiseCostSaving, 1e-9 * $franchise->structuralRevenue);
        $this->assertEqualsWithDelta(
            $franchise->franchiseCostSaving * $franchise->seasonalFixedCostFactor,
            $franchise->ebit - $plain->ebit,
            1e-6 * $plain->actualRevenue,
            'operating profit rises by the franchise margin on the structural revenue',
        );
    }

    public function testValuationReturnsAreFiledWithoutTheFranchiseWhileTheOperatingReturnRevertsToIt(): void
    {
        $plain = $this->report(0.0);
        $franchise = $this->report(self::POWER);
        $ctx = $franchise['ctx'];

        $added = FirmEconomics::franchiseReturn($ctx->franchiseCostSaving * EarningsEngine::TTM_QUARTERS, $ctx->corporateTaxRate, $ctx->investedCapital);
        $this->assertEqualsWithDelta($added, (float) $franchise['stock']->getCurrentRoic() - (float) $plain['stock']->getCurrentRoic(), 1e-6, 'the filed return carries the franchise');

        $trailing = static fn(Stock $stock): float => (float) ($stock->getEarningsMomentumZ() ?? [])[EarningsEngine::STATE_TRAILING_RETURN];
        $anchor = static fn(Stock $stock): float => (float) ($stock->getEarningsMomentumZ() ?? [])[EarningsEngine::STATE_ANCHOR_RETURN];
        $this->assertEqualsWithDelta($trailing($plain['stock']), $trailing($franchise['stock']), 1e-6, 'fair value is struck on the return without it');
        $this->assertEqualsWithDelta($anchor($plain['stock']), $anchor($franchise['stock']), 1e-6);

        $this->assertEqualsWithDelta(
            $added,
            (float) $franchise['stock']->getRoicTtm() - (float) $plain['stock']->getRoicTtm(),
            0.01 * $added,
            'the operating return state keeps the franchise it earns rather than pulling it away with the moat',
        );
    }

}

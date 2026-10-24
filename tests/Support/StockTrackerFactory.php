<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Deal\AcquisitionEngine;
use App\Service\Corporate\Deal\DivestitureEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\ManagementSuccessionEngine;
use App\Service\Market\Agent\AgentFlowEngine;
use App\Service\Market\Agent\AgentPopulation;
use App\Service\Market\Agent\AgentStateStoreInterface;
use App\Service\Market\Agent\AgentStrategyInterface;
use App\Service\Market\Agent\InMemoryAgentStateStore;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\StockTracker;
use App\Service\News\MarketEventPublisher;

/**
 * Builds a StockTracker around the engines a test hands in, with in-memory order-flow and agent books, no deals, and a
 * succession engine that never fires. The liquidity engine comes from the caller, since it carries the random source.
 */
trait StockTrackerFactory
{
    /** @param list<AgentStrategyInterface> $agentStrategies */
    private static function stockTracker(
        MarketEngine $marketEngine,
        EarningsEngine $earnings,
        CorporateActionEngine $corporateActions,
        DebtEngine $debtEngine,
        LiquidityEngine $liquidity,
        ?AcquisitionEngine $acquisitions = null,
        ?DivestitureEngine $divestitures = null,
        ?MarketEventPublisher $publisher = null,
        ?CorporateMetrics $metrics = null,
        ?OrderFlowStoreInterface $orderFlow = null,
        ?AgentStateStoreInterface $agentStates = null,
        array $agentStrategies = [],
    ): StockTracker {
        if ($acquisitions === null) {
            $acquisitions = self::createStub(AcquisitionEngine::class);
            $acquisitions->method('evaluatePrivateAcquisition')->willReturn(null);
        }
        if ($divestitures === null) {
            $divestitures = self::createStub(DivestitureEngine::class);
            $divestitures->method('evaluateCorporateDivestiture')->willReturn(null);
        }

        return new StockTracker(
            $marketEngine,
            $earnings,
            $corporateActions,
            $acquisitions,
            $divestitures,
            $publisher ?? self::createStub(MarketEventPublisher::class),
            $debtEngine,
            $metrics ?? self::createStub(CorporateMetrics::class),
            $liquidity,
            $orderFlow ?? new InMemoryOrderFlowStore(),
            new AgentFlowEngine(
                new AgentPopulation(),
                $agentStates ?? new InMemoryAgentStateStore(),
                new InMemoryOrderFlowStore(),
                $agentStrategies,
            ),
            // Scripted draws: checkProbability() is false, so no succession fires in a test about something else.
            new ManagementSuccessionEngine(self::createStub(MarketEventPublisher::class), new ScriptedDraws()),
        );
    }
}

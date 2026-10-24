<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Math\MathUtility;
use App\Service\News\MarketEventPublisher;
use App\Service\News\NarrativeEngine;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Builds the EarningsEngine over its real collaborators (metrics, debt, capex, treasury, capital allocation, consensus)
 * wired as the container wires them, with the ledger, publisher, narrative and dispatcher stubbed. Any collaborator can
 * be handed in instead; the random source always is, so the test chooses its draws.
 */
trait CorporateEngineFactory
{
    private static function earningsEngine(
        MathUtility $math,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?MarketEventPublisher $publisher = null,
        ?CapitalAllocationEngine $capitalAllocation = null,
        ?DebtEngine $debtEngine = null,
        ?CapExEngine $capExEngine = null,
        ?CorporateMetrics $metrics = null,
        ?NarrativeEngine $narrative = null,
        ?IndustryShareLedger $industryShareLedger = null,
        ?MarketEngine $marketEngine = null,
    ): EarningsEngine {
        $metrics ??= new CorporateMetrics();
        $debtEngine ??= new DebtEngine($math, $metrics);
        $capExEngine ??= new CapExEngine();
        $capitalAllocation ??= new CapitalAllocationEngine(
            self::createStub(CorporateLedgerService::class),
            $metrics,
            $debtEngine,
            $math,
            new TreasuryEngine($metrics, $debtEngine, $capExEngine, $math),
        );

        return new EarningsEngine(
            $eventDispatcher ?? self::createStub(EventDispatcherInterface::class),
            $publisher ?? self::createStub(MarketEventPublisher::class),
            $capitalAllocation,
            $debtEngine,
            $capExEngine,
            $math,
            $metrics,
            $narrative ?? self::createStub(NarrativeEngine::class),
            new MarketConsensusEngine(),
            $industryShareLedger,
            // The engine's own default stands unless a test hands one in.
            ...($marketEngine === null ? [] : ['marketEngine' => $marketEngine]),
        );
    }
}

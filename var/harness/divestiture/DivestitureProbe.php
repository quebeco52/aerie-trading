<?php

declare(strict_types=1);

use App\DTO\DivestitureContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\MergerAndAcquisitionEngine;

/**
 * Harness-only wrapper of the M&A engine's divestiture path. evaluateCorporateDivestiture() replays the parent's
 * exact call sequence (same random draws, same private steps via reflection), so a run is identical to the
 * production one, and logs every executed sale with the context the engine itself built.
 * classify() runs the engine's own initializeDivestitureContext() + shouldAbortDivestiture() on a fresh context.
 */
final class DivestitureProbe extends MergerAndAcquisitionEngine
{
    /** @var list<array<string, mixed>> */
    public array $sales = [];
    public float $time = 0.0;
    private \Closure $call;
    private \Closure $record;
    private object $math;
    private object $debt;

    public static function wrap(MergerAndAcquisitionEngine $engine): self
    {
        $r = new \ReflectionClass(MergerAndAcquisitionEngine::class);
        $get = static fn(string $p) => $r->getProperty($p)->getValue($engine);
        $probe = new self($get('marketEvent'), $get('debtEngine'), $get('mathUtility'), $get('corporateMetrics'), $get('industryShareLedger'));
        $probe->math = $get('mathUtility');
        $probe->debt = $get('debtEngine');
        $probe->call = static function (object $self, string $method, DivestitureContext $ctx) use ($r) {
            return $r->getMethod($method)->invoke($self, $ctx);
        };

        $probe->record = static function (Stock $stock, float $time) use ($r): void {
            $r->getMethod('openDistressEpisode')->invoke(null, $stock, $time);
        };

        return $probe;
    }

    /** @return array<string, mixed> */
    public function classify(Stock $stock, MacroStateDTO $m): array
    {
        $ctx = new DivestitureContext($stock, $m, 0.0);
        ($this->call)($this, 'initializeDivestitureContext', $ctx);
        $rawDis = $ctx->isDistressed;
        $rawDy = $ctx->isDying;
        // The episode is read off the stream state, never through isInOpenDistressEpisode (that one closes it);
        // the snapshot/restore around shouldAbortDivestiture is a guard in case it regains a side effect.
        $state0 = $stock->getEarningsMomentumZ();
        $episode = isset(($state0 ?? [])[self::STATE_DISTRESS_SALE_EPISODE]);
        $abort = (bool) ($this->call)($this, 'shouldAbortDivestiture', $ctx);
        $stock->setEarningsMomentumZ($state0);
        $hoard = (bool) ($ctx->hoardStatus['is_hoarder'] ?? false);

        return [
            'dEp' => $episode, 'dEpHeld' => $episode && ($rawDis || $rawDy), 'dTcr' => round($ctx->throughCycleReturn, 5),
            'dRet' => round($ctx->currentReturn, 5), 'dHur' => round($ctx->hurdleRate, 5), 'dEva' => round($ctx->evaSpread, 5),
            'dDisRaw' => $rawDis, 'dDyRaw' => $rawDy,
            // After the exemptions: a hoarder never sells; the cash buffer clears both flags (shouldAbort mutates them).
            'dDis' => !$hoard && $ctx->isDistressed, 'dDy' => !$hoard && $ctx->isDying,
            'dShed' => false,
            'dBuf' => $ctx->hasCashBuffer, 'dScale' => round($ctx->scaleRatio, 3), 'dHoard' => $hoard, 'dAbort' => $abort,
            'dPE' => round($ctx->currentPE, 2), 'dNNI' => $ctx->normalizedNetIncome,
            'roic' => (float) $stock->getRoicTtm(), 'roe' => (float) $stock->getRoeTtm(), 'broic' => (float) $stock->getBaselineRoic(),
            'gppe' => (float) $stock->getGrossPpe(),
        ];
    }

    public function evaluateCorporateDivestiture(Stock $seller, MacroStateDTO $macroState, float $dt): ?array
    {
        if ($seller->isBankrupt()) {
            return null;
        }
        if (!$this->math->checkProbability(self::divestitureHazardCeiling() * $dt)) {
            return null;
        }
        $ctx = new DivestitureContext($seller, $macroState, $dt);
        ($this->call)($this, 'initializeDivestitureContext', $ctx);
        $rawDis = $ctx->isDistressed;
        $rawDy = $ctx->isDying;
        if (($this->call)($this, 'isInOpenDistressEpisode', $ctx) || ($this->call)($this, 'shouldAbortDivestiture', $ctx)) {
            return null;
        }
        ($this->call)($this, 'determineDivestitureStrategy', $ctx);
        if (!$ctx->dealExecuted) {
            return null;
        }
        $h0 = $ctx->health;
        $pre = ['ppe' => $seller->getNetPpe(), 'gppe' => (float) $seller->getGrossPpe(), 'debt' => (float) $seller->getTotalDebt(),
            'wd' => (float) $seller->getWholesaleDebt(), 'cash' => (float) $seller->getCorporateTreasury(), 'eq' => (float) $seller->getTotalEquity(),
            'roic' => (float) $seller->getRoicTtm(), 'roe' => (float) $seller->getRoeTtm(), 'rev' => (float) $seller->getTotalRevenue()];
        ($this->call)($this, 'executeDivestitureSale', $ctx);
        ($this->call)($this, 'applyDivestitureAccounting', $ctx);
        if ($ctx->isDying || $ctx->isDistressed) {
            ($this->record)($seller, $macroState->totalTime);
        }
        $h1 = $this->debt->analyzeDebtHealth($seller, $macroState);
        $this->sales[] = [
            't' => round($this->time, 4), 'tk' => $seller->getTicker(),
            'br' => $ctx->isDying ? 'dying' : ($ctx->isDistressed ? 'distressed' : 'premium'),
            'shed' => false, 'rawDis' => $rawDis, 'rawDy' => $rawDy,
            'f' => $ctx->divestedFraction, 'mult' => $ctx->saleMultiple, 'px' => $ctx->salePrice, 'mcap' => $ctx->price * $ctx->shares,
            'bookEq' => $ctx->lostEquity, 'lostDebt' => $ctx->lostDebt, 'gain' => $ctx->gainOnSale, 'tax' => $ctx->taxOnSale,
            'nni' => $ctx->normalizedNetIncome, 'ret' => $ctx->currentReturn, 'tcr' => $ctx->throughCycleReturn, 'hur' => $ctx->hurdleRate, 'eva' => $ctx->evaSpread,
            'ndE0' => $h0?->netDebtToEbitda, 'covL0' => $h0?->ebitdaCovenantLimit, 'head0' => $h0?->hasLeverageHeadroom,
            'mkt0' => $h0?->hasMarketAccess, 'can0' => $h0?->canIssueDebt, 'liq0' => $h0?->isLiquidityCrisis, 'icr0' => $h0?->interestCoverage,
            'ndE1' => $h1->netDebtToEbitda, 'mkt1' => $h1->hasMarketAccess,
            'pre' => $pre,
            'post' => ['ppe' => $seller->getNetPpe(), 'debt' => (float) $seller->getTotalDebt(), 'cash' => (float) $seller->getCorporateTreasury(),
                'eq' => (float) $seller->getTotalEquity(), 'roic' => (float) $seller->getRoicTtm(), 'roe' => (float) $seller->getRoeTtm()],
        ];

        return ($this->call)($this, 'finalizeDivestitureEvent', $ctx);
    }
}

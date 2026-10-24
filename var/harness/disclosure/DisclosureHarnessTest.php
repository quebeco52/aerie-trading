<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\StockEvent;
use App\Service\Corporate\EarningsEngine;

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * disclosure: the whole-board run (var/harness/lib/BoardRun.php) with the Securities Commission's disclosure fields
 * forced (PWT, EMS) and DISC_OUT=<json>: warnings (t, ticker, log move, economic part), reports (accrual bank / TA,
 * EPS gap, price), report-day moves and the board return per tick. With DISC_OUT the standard quarter rows are
 * skipped. DIAG=1 adds the warning's shortfall ratio at each pre-announcement evaluation.
 */
final class DisclosureHarnessTest extends BoardRun
{
    /** @var array<string, mixed> */
    private array $disc = ['w' => [], 'r' => [], 'ret' => []];

    protected function quarterRows(): bool
    {
        return !getenv('DISC_OUT');
    }

    /** PWT / EMS: force the Securities Commission's two fields on the DTO the board reads, every tick. */
    protected function macroFor(MacroStateDTO $m): MacroStateDTO
    {
        $pwt = getenv('PWT');
        $ems = getenv('EMS');
        if ($pwt === false && $ems === false) {
            return $m;
        }
        $vars = (fn(): array => get_object_vars($this))->call($m);
        // The threshold is EarningsEngine::PREANNOUNCEMENT_WARNING_THRESHOLD on trees after the 10-19 politics rework,
        // not a DTO field: PWT is then ignored and only EMS is forced.
        if ($pwt !== false && property_exists(MacroStateDTO::class, 'profitWarningThreshold')) {
            $vars['profitWarningThreshold'] = (float) $pwt;
        }
        if ($ems !== false) {
            $vars['earningsManagementScale'] = (float) $ems;
        }
        $new = (new ReflectionClass(MacroStateDTO::class))->newInstanceWithoutConstructor();
        (function (array $v): void {
            foreach ($v as $k => $x) {
                $this->{$k} = $x;
            }
        })->call($new, $vars);

        return $new;
    }

    /**
     * DIAG=1: the warning's shortfall ratio, rebuilt from EarningsEngine::evaluatePreAnnouncement's inputs on the tick
     * it is evaluated (before this tick's diffusion; the state it reads is the last report's).
     */
    protected function beforeStocks(): void
    {
        if (!getenv('DIAG')) {
            return;
        }
        $m = $this->currentMacro;
        foreach ($this->list as $s) {
            if ($s->isBankrupt() || ($this->tick % intdiv($this->tpy, 4)) !== EarningsEngine::resolvePreAnnouncementTick($s->getTicker(), $this->tpy)) {
                continue;
            }
            $strat = App\Data\Company\Sectors::getBusinessModelStrategy(App\Data\Company\Sectors::businessModelFor($s->getIndustry()));
            $at = 1.0 - $strat->getEffectiveTaxRate($m->corporateTaxRate);
            $rev = $s->getManagedAccrualBank() * EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE;
            $drag = $strat->projectInputCostDragChange($s, $m);
            $qr = max(0.0, (float) $s->getTotalRevenue()) / 4.0;
            $sq = max(0.0, (float) $drag) * $qr * (1.0 - App\Service\Math\FinancialConstants::ANALYST_COST_BASE_VISIBILITY) * $at;
            $se = max(1.0, $qr * max(EarningsEngine::PREANNOUNCEMENT_MIN_MARGIN_SCALE, (float) $s->getOperatingMargin()) * $at);
            $this->disc['diag'][] = [round(($rev + $sq) / $se, 5), round($rev / $se, 5), round($sq / $se, 5), $drag === null ? null : round($drag, 5)];
        }
    }

    protected function afterStocks(array $result): void
    {
        $this->disc['ret'][] = round((float) $result['board_price_return'], 7);
        $this->disc['cap'][] = $this->tick % $this->tpy === 0 ? (float) $result['total_cap'] : null;
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        if (getenv('DISC_OUT')) {
            $st = $report->getStock();
            $this->disc['r'][] = [round($this->currentTime, 3), $st->getTicker(), $st->getManagedAccrualBank() / max(1.0, (float) $report->getTotalAssets()),
                $report->getConsensusEps() === null ? null : (float) $report->getReportedEps() - (float) $report->getConsensusEps(), (float) $st->getPrice()];
        }

        return $row;
    }

    protected function onEvent(StockEvent $event): void
    {
        if (!in_array($event->getEventType(), ['GUIDANCE', 'EARNINGS'], true)) {
            return;
        }
        $lm = log(max(1e-6, 1.0 + (float) $event->getChangePercent() / 100.0));
        if ($event->getEventType() === 'GUIDANCE') {
            $this->disc['w'][] = [round($this->currentTime, 3), $event->getStock()->getTicker(), $lm, (float) ($event->getStock()->getPreAnnouncedEconomicShortfall() ?? 0.0)];
        } else {
            $this->disc['rj'][] = $lm;
        }
    }

    protected function onEnd(): void
    {
        if (getenv('DISC_OUT')) {
            file_put_contents((string) getenv('DISC_OUT'), json_encode($this->disc + ['seed' => $this->seed, 'years' => $this->years, 'tpy' => $this->tpy, 'n_firms' => count($this->stocks), 'dead' => $this->dead, 'px_final' => array_map(fn($s) => (float) $s->getPrice(), $this->stocks), 'px_seed' => $this->seedPrice]));
        }
    }
}

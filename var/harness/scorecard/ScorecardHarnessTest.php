<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * Board scorecard probe: the plain run's record (every firm's quarter rows, deaths, reorganisations, defaults) plus
 * each firm's industry, sector and model, and nominal GDP and the board's cap each quarter, for scorecard.py. Read-only.
 */
final class ScorecardHarnessTest extends BoardRun
{
    /** @var array<string, array{industry: string, sector: string, model: string, fin: bool}> */
    private array $meta = [];
    /** @var list<array{0: float, 1: float, 2: float}> Time, nominal GDP index, board cap. */
    private array $macro = [];

    protected function onStart(): void
    {
        foreach ($this->stocks as $t => $s) {
            $strategy = App\Data\Company\Sectors::strategyFor($s->getIndustry());
            $this->meta[$t] = [
                'industry' => (string) $s->getIndustry(),
                'sector' => $s->getSector(),
                'model' => (new ReflectionClass($strategy))->getShortName(),
                'fin' => $strategy->isFinancial(),
            ];
        }
    }

    protected function afterStocks(array $result): void
    {
        if ($this->currentMacro !== null && $this->tick % max(1, intdiv($this->tpy, 4)) === 0) {
            $this->macro[] = [round($this->currentTime, 3), $this->currentMacro->nominalGdpIndex, $this->reading->equityMarketCap];
        }
    }

    protected function record(array $record): array
    {
        return $record + ['meta' => $this->meta, 'macro' => $this->macro, 'gdp_usd' => App\Service\Math\MacroConstants::DISTRICT_GDP_USD];
    }
}

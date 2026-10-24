<?php

declare(strict_types=1);

use App\DTO\BoardReadingDTO;
use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;

require_once __DIR__ . '/../../lib/BoardRun.php';

/**
 * regulator probe: the whole-board run (var/harness/lib/BoardRun.php) with the macro fed only the board's total cap
 * and no Diet, each bank report's capital lines (CET1, RWA, regulatory and tangible equity, securities book, deposits,
 * dividend cap, buffer rate), and the fund's weights on the macro row. REQ=<ratio> holds the bank capital
 * requirement the stocks read at that level.
 */
final class FullMarketHarnessTest extends BoardRun
{
    protected function macroFor(MacroStateDTO $m): MacroStateDTO
    {
        if (getenv('REQ') === false || getenv('REQ') === '') {
            return $m;
        }

        return new MacroStateDTO(...array_merge((fn(): array => get_object_vars($this))->call($m), ['bankCapitalRequirement' => (float) getenv('REQ')]));
    }

    protected function boardReading(BoardReadingDTO $reading, array $result): BoardReadingDTO
    {
        return new BoardReadingDTO(getenv('SIMCMD') ? null : (float) $result['total_cap']);
    }

    protected function macroRow(array $row, MacroStateDTO $m): array
    {
        return array_slice($row, 0, (int) array_search('oil', array_keys($row), true) + 1, true) + [
            'fundw' => $m->sovereignFundTargetWeight, 'fundd' => $m->sovereignFundDomesticWeight, 'fundeq' => $m->sovereignFundEquityShare, 'floatcap' => $m->boardFloatCap, 'eqcap' => $m->equityMarketCap,
        ];
    }

    protected function reportRow(array $row, CorporateReport $report): array
    {
        $s = $report->getStock();
        $strategy = App\Data\Company\Sectors::strategyFor($s->getIndustry());
        if (!method_exists($strategy, 'calculateCet1Ratio')) {
            return $row;
        }

        return self::insertAfter($row, 'tni', [
            'cet1' => $strategy->calculateCet1Ratio($s),
            'rwa' => $strategy->calculateRiskWeightedAssets($s),
            'regeq' => (float) $s->getRegulatoryEquity(),
            'teq' => (float) $s->getTangibleEquity(),
            'sec' => (float) $strategy->resolveSecuritiesBook($s),
            'nea' => $s->hasEarningAssetLedger() ? (float) $s->getNetEarningAssets() : -1.0,
            'dep' => (float) $s->getCustomerDeposits(),
            'divcap' => (float) ($strategy->getRegulatoryDividendCap($s, (float) $s->getCorporateTreasury(), $this->currentMacro) ?? -1.0),
            'ccyb' => $this->currentMacro?->countercyclicalBufferRateEma ?? 0.0,
        ]);
    }
}

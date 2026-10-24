<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;

require_once __DIR__ . '/../lib/BoardRun.php';

/**
 * debt_anchor: the whole-board run (var/harness/lib/BoardRun.php) whose FISCAL=1 macro rows also carry net financial
 * liabilities, the tariff, yields and spreads, the policy levies, the Diet's debt target and coalition state
 * position, and the sovereign fund's flows summed over the quarter.
 */
final class FullMarketHarnessTest extends BoardRun
{
    /** @var array<string, float> fund flows since the last macro row, shares of GDP */
    private array $fq = [];
    private ?float $ytdPrev = null;

    protected function afterStocks(array $result): void
    {
        $m = $this->currentMacro;
        $dt = $this->dt;
        // Draw and stabilisation are annual rates; the budget inflow is this tick's currency, credited next tick;
        // stamp duty comes from its year-to-date sum.
        $gdpD = $m->sovereignFundDollarsPerGdp * $m->nominalGdpIndex;
        $this->fq['draw'] = ($this->fq['draw'] ?? 0.0) + $m->sovereignFundDrawToGdp * $dt;
        $this->fq['stab'] = 0.0; // the fund finances no stabilisation
        $this->fq['inflow'] = ($this->fq['inflow'] ?? 0.0) + ($gdpD > 0.0 ? $m->sovereignFundBudgetInflow / $gdpD : 0.0);
        $ytd = $m->sovereignFundStampDutyYearToDate;
        $this->fq['stamp'] = ($this->fq['stamp'] ?? 0.0) + ($gdpD > 0.0 ? ($ytd < ($this->ytdPrev ?? 0.0) ? $ytd : $ytd - ($this->ytdPrev ?? 0.0)) / $gdpD : 0.0);
        $this->ytdPrev = $ytd;
    }

    protected function macroRow(array $row, MacroStateDTO $m): array
    {
        $fq = $this->fq;
        $this->fq = [];
        if (!getenv('FISCAL')) {
            return $row;
        }
        $pstate = $this->politicsState;

        return $row + [
            'nfl' => $m->sovereignNetFinancialLiabilitiesToGdp, 'tariff' => $m->importTariffRate, 'y10' => $m->yield10y,
            'levies' => method_exists(App\DTO\PublicAccountsDTO::class, 'policyLevies')
                ? App\Service\Macro\Subsystem\CreditFiscalSubsystem::publicAccounts($m, 0.0)->policyLevies() : null,
            'f_ret_idx' => $m->sovereignFundReturnIndex, 'f_draw' => $fq['draw'] ?? 0.0, 'f_stab' => $fq['stab'] ?? 0.0,
            'f_inflow' => $fq['inflow'] ?? 0.0, 'f_stamp' => $fq['stamp'] ?? 0.0,
            'draw_law' => $m->reserveDrawShare, 'draw_share' => $m->sovereignFundDrawShare,
            'council_line' => null, // the Council draws no debt line
            'debt_target' => property_exists($m, 'debtTarget') ? $m->debtTarget : null,
            'council_debt_line' => null,
            'anchor' => $m->debtTarget,
            'tp10' => $m->termPremium10y, 'y2' => $m->yield2y, 'ig' => $m->macroCreditSpread, 'hy' => $m->highYieldCreditSpread,
            'house' => $m->residentialPropertyIndexEma, 'house_dev' => $m->housePriceDeviation(),
            'coal_state' => $pstate !== null ? App\Service\Politics\Policy\Platforms::coalitionPosition($pstate->governingCoalition, $pstate->dietSeats, $pstate->partyPositions)[App\Data\Politics\AerieDiet::AXIS_STATE] : null,
        ];
    }
}

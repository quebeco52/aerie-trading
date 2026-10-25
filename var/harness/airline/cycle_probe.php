<?php

declare(strict_types=1);

/**
 * Single-path probe: TERN's sector physics through a stylised boom and downturn, noiseless.
 *
 * Drives AirlineBusinessModel::computeActualFinancials() quarter by quarter on a fixed plan (E = 100 a quarter, no
 * seasonality), with the engine's cost steps around it replayed: the variable cost ratio's CIR step toward the
 * cyclical theta, the overtime premium on the scheduled activity, and the sticky committed fixed base. Prints the
 * EBITDA path and its decomposition against the calm quarter.
 *
 * Usage: bin/php-slot php var/harness/airline/cycle_probe.php [scenario] [--quiet]
 *   scenarios: normal, severe, stag (severe + oil spike), oil (oil spike, flat gap), all (summary of the four),
 *   noise (calm macro with the quarter's draws on)
 */

require __DIR__ . '/../../../vendor/autoload.php';

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Earnings\OperatingCapacityEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;
use App\Service\Model\Sector\AirlineBusinessModel;
use App\Tests\Support\ScriptedDraws;

const PLAN = 100.0;
const DT = 0.25;
/** TERN at opening: cash cost base 0.85 of revenue, 30% of it fixed; EBITDA margin 15%. */
const M0 = 0.595;
const F0 = 25.5;
const KAPPA = 4.0;

/**
 * Stylised macro path, one entry per quarter: output gap, sentiment residual, deal deviation, relative energy price.
 *
 * @return list<array{g: float, mood: float, deal: float, energy: float}>
 */
function path(string $scenario): array
{
    [$peak, $trough, $moodTurn, $dealLow, $oil] = match ($scenario) {
        'normal' => [0.020, -0.020, -0.08, -0.10, 0.0],
        'severe' => [0.030, -0.035, -0.15, -0.20, 0.0],
        'stag'   => [0.030, -0.035, -0.15, -0.20, 0.60],
        'oil'    => [0.0, 0.0, 0.0, 0.0, 0.60],
        default  => throw new InvalidArgumentException($scenario),
    };
    $q = [];
    $add = static function (float $g, float $mood, float $deal, float $energy) use (&$q): void {
        $q[] = ['g' => $g, 'mood' => $mood, 'deal' => $deal, 'energy' => $energy];
    };
    // 4 calm quarters, 8 of boom, 6 of turn, 2 at the trough, 8 of recovery, 4 calm.
    for ($i = 0; $i < 4; ++$i) {
        $add(0.0, 0.0, 0.0, 0.0);
    }
    for ($i = 1; $i <= 8; ++$i) {
        $add($peak * $i / 8, 0.05 * $i / 8 * ($peak > 0 ? 1 : 0), 0.15 * $i / 8 * ($peak > 0 ? 1 : 0), 0.0);
    }
    for ($i = 1; $i <= 6; ++$i) {
        $x = $i / 6;
        // The mood sours at the turn and recovers into the trough; oil spikes over the turn and falls back.
        $add($peak + (($trough - $peak) * $x), $moodTurn * sin(M_PI * min(1.0, $x * 1.2)), $dealLow * $x, $oil * sin(M_PI * $x));
    }
    for ($i = 0; $i < 2; ++$i) {
        $add($trough, 0.0, $dealLow, 0.0);
    }
    for ($i = 1; $i <= 8; ++$i) {
        $add($trough * (1 - $i / 8), 0.0, $dealLow * (1 - $i / 8), 0.0);
    }
    for ($i = 0; $i < 4; ++$i) {
        $add(0.0, 0.0, 0.0, 0.0);
    }

    return $q;
}

function macro(array $p): MacroStateDTO
{
    return new MacroStateDTO(
        outputGapEma: $p['g'],
        energyCostPushLag: $p['energy'] * MacroConstants::ENERGY_COST_PUSH_TRANSMISSION,
        consumerSentimentMood: $p['mood'] * 100.0,
        domesticDemandGapEma: $p['g'],
        dealActivityIndexEma: MacroConstants::DEAL_ACTIVITY_BASELINE * (1.0 + $p['deal']),
        foreignOutputGapEma: 0.5 * $p['g'],
    );
}

/**
 * @return list<array<string, float>>
 */
function run(string $scenario, bool $print): array
{
    $model = new AirlineBusinessModel();
    $stock = (new Stock())->setTicker('TERN_PROBE')->setBeta('1.00');
    $stock->setEarningsMomentumZ([]);
    $ps = AirlineBusinessModel::LEISURE_PASSENGER_WEIGHT + AirlineBusinessModel::BUSINESS_PASSENGER_WEIGHT;
    $mStruct = M0;
    $scale = 1.0;
    $rows = [];
    $base = null;

    foreach (path($scenario) as $i => $p) {
        $macro = macro($p);
        $state = $stock->getEarningsMomentumZ() ?? [];
        $activity = max(0.0, 1.0 + $model->resolveSectorActivityShift($stock, $macro));
        $seats = isset($state[AirlineBusinessModel::STATE_SCHEDULED_SEATS]) ? (float) $state[AirlineBusinessModel::STATE_SCHEDULED_SEATS] : null;

        // Engine: the variable cost ratio steps toward its cyclical theta; overtime on the scheduled activity.
        $theta = M0 - ($p['g'] * 1.0 * 0.15);
        $mStruct = StochasticProcesses::calculateCIR($mStruct, KAPPA, $theta, 0.0, DT, 0.0);
        $overtime = OperatingCapacityEngine::overtimePremium($activity, 1.0);
        $mReal = $mStruct + $overtime;
        // Engine: committed fixed base follows the activity down with the sticky elasticity, never above one.
        $gap = log(max(0.10, $activity) / max(0.10, $scale));
        $speed = TimeSeries::periodAdjustmentSpeed(FinancialConstants::STICKY_COST_BETA_EXPANSION + ($gap < 0.0 ? FinancialConstants::STICKY_COST_BETA_CONTRACTION_PENALTY : 0.0), DT);
        $scale = min(1.0, max(FinancialConstants::MIN_COMMITTED_COST_SCALE, $scale * exp($speed * $gap)));
        $fixed = F0 * $scale;

        $res = $model->computeActualFinancials($stock, PLAN, $mReal, $fixed, 0.0, $macro, new ScriptedDraws());
        $stock->setEarningsMomentumZ($res->streamZ);
        $ebitda = $res->actualRevenue - $res->actualVariableCosts - $fixed;

        $seatsFlown = (float) $res->kpis['seat_capacity_index'];
        $load = (float) $res->kpis['load_factor'];
        $pax = $res->streamRevenue['leisure_passengers'] + $res->streamRevenue['business_passengers'];
        $other = $res->streamRevenue['air_cargo'] + $res->streamRevenue['loyalty_miles'];
        $drag = $res->clampedMargin - $mReal;
        $row = [
            't' => $i * DT, 'g' => $p['g'], 'mood' => $p['mood'], 'energy' => $p['energy'],
            'seats' => $seatsFlown, 'load' => $load, 'rev' => $res->actualRevenue, 'pax' => $pax, 'other' => $other,
            'var' => $res->actualVariableCosts, 'fixed' => $fixed, 'ebitda' => $ebitda, 'm' => $res->clampedMargin,
            'mstruct' => $mStruct, 'overtime' => $overtime, 'drag' => $drag, 'scale' => $scale,
        ];
        $base ??= $row;
        // Decomposition against the calm quarter, in points of plan revenue.
        $flownBase = $base['seats'];
        $row['d_pax'] = $pax - $base['pax'];
        $row['d_other'] = $other - $base['other'];
        $row['d_seatcost'] = -PLAN * $ps * ($seatsFlown - $flownBase) * M0;
        $row['d_cyc'] = -PLAN * (($ps * $seatsFlown) + 1.0 - $ps) * ($mStruct - M0);
        $row['d_overtime'] = -PLAN * (($ps * $seatsFlown) + 1.0 - $ps) * $overtime;
        $row['d_fuel'] = -PLAN * (($ps * $seatsFlown) + 1.0 - $ps) * $drag;
        $row['d_fixed'] = -($fixed - $base['fixed']);
        $rows[] = $row;
    }

    // Passenger revenue split three ways against the calm quarter: the seats flown over plan at the plan load and fare,
    // the load (passengers carried per seat), and the fare on those passengers.
    foreach ($rows as $k => $r) {
        $d = $r['load'] / AirlineBusinessModel::PLANNED_LOAD_FACTOR;
        $seats = $r['seats'] / $base['seats'];
        $rows[$k]['fare'] = $r['pax'] / max(1e-9, $base['pax'] * $seats * $d);
        $rows[$k]['d_seats'] = $base['pax'] * ($seats - 1.0);
        $rows[$k]['d_load'] = $base['pax'] * $seats * ($d - 1.0);
        $rows[$k]['d_fare'] = $r['pax'] - ($base['pax'] * $seats * $d);
        $rows[$k]['ttm'] = $k >= 3 ? array_sum(array_column(array_slice($rows, $k - 3, 4), 'ebitda')) / array_sum(array_column(array_slice($rows, $k - 3, 4), 'rev')) : NAN;
    }

    if ($print) {
        printf("scenario %s (plan revenue 100 a quarter; EBITDA and its parts in points of plan)\n", $scenario);
        printf("%5s %6s %6s %6s | %6s %6s %6s | %6s %6s %6s | %7s %7s %7s %7s %7s %7s %7s %7s %7s\n",
            't', 'gap%', 'mood', 'oil', 'seats', 'load', 'fare', 'rev', 'EBITDA', 'TTM%', 'dSeats', 'dLoad', 'dFare', 'dOther', 'dSeatC', 'dCyc', 'dFuel', 'dOvt', 'dFixed');
        foreach ($rows as $r) {
            printf("%5.2f %6.2f %6.2f %6.2f | %6.3f %6.3f %6.3f | %6.1f %6.2f %6.1f | %7.2f %7.2f %7.2f %7.2f %7.2f %7.2f %7.2f %7.2f %7.2f\n",
                $r['t'], 100 * $r['g'], $r['mood'], $r['energy'], $r['seats'], $r['load'], $r['fare'], $r['rev'], $r['ebitda'],
                is_nan($r['ttm']) ? NAN : 100 * $r['ttm'], $r['d_seats'], $r['d_load'], $r['d_fare'], $r['d_other'], $r['d_seatcost'], $r['d_cyc'], $r['d_fuel'], $r['d_overtime'], $r['d_fixed']);
        }
        // Identity check: the parts sum to the EBITDA change.
        $worst = 0.0;
        foreach ($rows as $r) {
            $sum = $r['d_seats'] + $r['d_load'] + $r['d_fare'] + $r['d_other'] + $r['d_seatcost'] + $r['d_cyc'] + $r['d_fuel'] + $r['d_overtime'] + $r['d_fixed'];
            $worst = max($worst, abs($sum - ($r['ebitda'] - $base['ebitda'])));
        }
        printf("decomposition residual max %.2e\n\n", $worst);
    }

    return $rows;
}

/**
 * Calm macro, the quarter's own draws on: TERN's stream noise at its volatility, the engine's variable-cost CIR noise
 * and its idiosyncratic revenue shock on the plan. Spread of the quarterly and trailing-four-quarter EBITDA margins.
 */
function noise(int $quarters, int $seed): void
{
    $model = new AirlineBusinessModel();
    $stock = (new Stock())->setTicker('TERN_PROBE')->setBeta('1.00');
    $stock->setEarningsMomentumZ([]);
    $draws = MathUtility::ownStream($seed);
    $engine = MathUtility::ownStream($seed + 1);
    $vol = 0.35;
    $mStruct = M0;
    $q = [];
    $macro = new MacroStateDTO();
    for ($i = 0; $i < $quarters; ++$i) {
        $mStruct = StochasticProcesses::calculateCIR($mStruct, KAPPA, M0, $vol * 0.15, DT, $engine->generateStandardNormal());
        $plan = PLAN * (1.0 + ($vol * 0.25 * sqrt(DT) * $engine->generateStandardNormal()));
        $res = $model->computeActualFinancials($stock, $plan, $mStruct, F0, $vol, $macro, $draws);
        $stock->setEarningsMomentumZ($res->streamZ);
        $q[] = [$res->actualRevenue - $res->actualVariableCosts - F0, $res->actualRevenue, $res->priceRevenue / $res->actualRevenue];
    }
    $m = array_map(static fn(array $r): float => $r[0] / $r[1], $q);
    $ttm = [];
    for ($i = 3; $i < $quarters; ++$i) {
        $s = array_slice($q, $i - 3, 4);
        $ttm[] = array_sum(array_column($s, 0)) / array_sum(array_column($s, 1));
    }
    $sd = static function (array $x): float {
        $mu = array_sum($x) / count($x);

        return sqrt(array_sum(array_map(static fn(float $v): float => ($v - $mu) ** 2, $x)) / count($x));
    };
    sort($ttm);
    $pr = array_column($q, 2);
    printf("noise, %d quarters: quarterly margin mean %.1f%% sd %.1fpp | TTM sd %.1fpp p1 %.1f%% p5 %.1f%% | price revenue sd %.1f%% of revenue\n",
        $quarters, 100 * array_sum($m) / count($m), 100 * $sd($m), 100 * $sd($ttm), 100 * $ttm[(int) (0.01 * count($ttm))], 100 * $ttm[(int) (0.05 * count($ttm))], 100 * $sd($pr));
}

$scenario = $argv[1] ?? 'all';
if ($scenario === 'noise') {
    noise(4000, 7);
} elseif ($scenario === 'all') {
    printf("%-7s %8s %8s %8s %8s %8s %8s %8s\n", 'arm', 'EBITDA0', 'minQ', 'minTTM%', 'peakTTM%', 'fall%', 'meanTTM%', 'minLoad');
    foreach (['normal', 'severe', 'stag', 'oil'] as $s) {
        $rows = run($s, false);
        $ttm = array_values(array_filter(array_column($rows, 'ttm'), static fn(float $x): bool => !is_nan($x)));
        $peak = max(array_slice($ttm, 0, 14));
        printf("%-7s %8.2f %8.2f %8.1f %8.1f %8.1f %8.1f %8.3f\n", $s, $rows[0]['ebitda'], min(array_column($rows, 'ebitda')), 100 * min($ttm), 100 * $peak,
            100 * (1 - min($ttm) / 0.15), 100 * array_sum($ttm) / count($ttm), min(array_column($rows, 'load')));
    }
} else {
    run($scenario, true);
}

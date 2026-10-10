<?php

declare(strict_types=1);

// fund_debt.php <seed> <fund 0|1> <out.json>
// SovereignFundDynamicsTest::simulate() (80 years, 24 ticks/yr, synthetic board) copied verbatim, plus a per-tick
// decomposition of the gross debt ratio's change into the public accounts' lines. Run under BASE=<snapshot tree> and
// OVR=<override dir> as var/harness/bootstrap.php reads them (run by fund_run.sh).

require __DIR__ . '/../bootstrap.php';

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\MathUtility;

const YEARS = 80;
const TPY = 24;
const LATE_FROM_YEAR = YEARS - 30; // lateDebt: annual samples with tick > (YEARS-30)*TPY
const OPENING_FLOAT_CAP = 1.0e12;
const FLOAT_SHARE = 0.85;
const DIVIDEND_YIELD = 0.02;
const ERP_VALUATION_SLOPE = 5.475;
const TRANSITORY_HALF_LIFE_YEARS = 2.0;

$seed = (int) $argv[1];
$withFund = $argv[2] === '1';
$out = $argv[3];

mt_srand($seed);
$math = new MathUtility();
$engine = new MacroEngine(
    $math,
    new \Redis(),
    new MacroSnapshotRecorder(),
    new MonetaryPolicySubsystem($math),
    new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math),
    new CommodityLogisticsSubsystem($math),
    new AssetMarketSubsystem($math),
    new CreditFiscalSubsystem($math),
    null,
    null,
    $withFund ? new SovereignFundSubsystem($math) : null,
);

$dt = 1.0 / TPY;
$floatCap = OPENING_FLOAT_CAP;
$openingGdp = null;
$transitory = 0.0;
$reversion = exp(-$dt * log(2.0) / TRANSITORY_HALF_LIFE_YEARS);
$priceReturn = null;
$dividends = null;
$lateDebt = [];
$dPrev = null;

// Cumulative contributions to the debt ratio since the first tick (each a share of GDP).
$keys = ['purchases', 'netTaxes', 'corpTax', 'otherRev', 'cbRemit', 'fundSpend', 'fundRev', 'interest', 'erosion', 'rescue', 'floorAndResidual'];
$cum = array_fill_keys($keys, 0.0);
$lateCum = array_fill_keys($keys, 0.0); // sum over late annual samples of the cumulative, to average
// Late-window tick means.
$flowKeys = ['draw', 'stab', 'pb', 'effRate', 'rMinusG', 'g', 'policy', 'y10', 'fundToGdp', 'nfl', 'bohn', 'stanceTarget', 'netTax', 'cbRemit', 'gap', 'inflation', 'stabCap', 'stabNeg', 'atFloor', 'bohnOn', 'rgD', 'interestD'];
$flow = array_fill_keys($flowKeys, 0.0);
$nLate = 0;
$realIdxStart = null;
$realIdxEnd = null;
$d0 = null;

for ($tick = 1; $tick <= YEARS * TPY; ++$tick) {
    $macro = $engine->updateMacroState($dt, $floatCap / FLOAT_SHARE, $floatCap, $priceReturn, $dividends);
    $openingGdp ??= $macro->nominalGdpIndex;

    $transitory = ($reversion * $transitory) + ($macro->marketVolatility * sqrt($dt) * $macro->marketZ);
    $nextFloatCap = OPENING_FLOAT_CAP * ($macro->nominalGdpIndex / $openingGdp)
        * exp((-ERP_VALUATION_SLOPE * ($macro->equityRiskPremium - MacroEngine::BASE_EQUITY_RISK_PREMIUM)) + $transitory);
    $dividends = DIVIDEND_YIELD * $floatCap * $dt;
    $priceReturn = ($nextFloatCap / $floatCap) - 1.0;
    $floatCap = $nextFloatCap;

    $d = $macro->sovereignDebtToGdp;
    $acc = CreditFiscalSubsystem::publicAccounts($macro, $dt);
    $effRate = $dPrev === null ? 0.0 : CreditFiscalSubsystem::sovereignEffectiveRate($macro);
    if ($dPrev === null) {
        $d0 = $d;
    } else {
        $parts = [
            'purchases' => $acc->purchases * $dt,
            'netTaxes' => -$acc->netTaxes * $dt,
            'corpTax' => -$acc->corporateTax * $dt,
            'otherRev' => -($acc->tariffs + $acc->carbonPrice + $acc->bankLevy + $acc->dividendTax + $acc->foreignBuyerTax) * $dt,
            'cbRemit' => -$acc->centralBankRemittance * $dt,
            'fundSpend' => $acc->fundFinancedSpending * $dt,
            'fundRev' => -$acc->fundContribution * $dt,
            'interest' => $effRate * $dPrev * $dt,
            'erosion' => -$macro->nominalGdpGrowth * $dPrev * $dt,
            'rescue' => $acc->bankRescueOutlay - $acc->bankRescueSales,
        ];
        $parts['floorAndResidual'] = ($d - $dPrev) - array_sum($parts);
        foreach ($parts as $k => $v) {
            $cum[$k] += $v;
        }
    }

    if ($tick > LATE_FROM_YEAR * TPY) {
        $nfl = $macro->sovereignNetFinancialLiabilitiesToGdp;
        $affordable = max(0.0, ($macro->sovereignFundToGdp * (1.0 - $macro->sovereignFundDomesticWeight)) - $macro->sovereignFundDrawToGdp);
        $vals = [
            'draw' => $withFund ? $macro->sovereignFundDrawToGdp : 0.0,
            'stab' => $macro->sovereignFundStabilisationToGdp,
            'pb' => -$acc->primaryDeficit(),
            'effRate' => $effRate,
            'rMinusG' => $effRate - $macro->nominalGdpGrowth,
            'g' => $macro->nominalGdpGrowth,
            'policy' => $macro->policyRate,
            'y10' => $macro->yield10y,
            'fundToGdp' => $macro->sovereignFundToGdp,
            'nfl' => $nfl,
            'bohn' => CreditFiscalSubsystem::bohnSurplus($nfl),
            'stanceTarget' => $withFund ? CreditFiscalSubsystem::bohnSurplus($nfl) : CreditFiscalSubsystem::bohnSurplus($nfl) - CreditFiscalSubsystem::SOVEREIGN_STRUCTURAL_DEFICIT,
            'netTax' => $acc->netTaxes,
            'cbRemit' => $acc->centralBankRemittance,
            'gap' => $macro->outputGap,
            'inflation' => $macro->inflation,
            'stabCap' => ($withFund && abs($macro->sovereignFundStabilisationToGdp - $affordable) < 1e-12) ? 1.0 : 0.0,
            'stabNeg' => $macro->sovereignFundStabilisationToGdp < 0.0 ? 1.0 : 0.0,
            'atFloor' => $d <= MacroEngine::SOVEREIGN_DEBT_FLOOR + 1e-12 ? 1.0 : 0.0,
            'bohnOn' => $nfl > CreditFiscalSubsystem::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD ? 1.0 : 0.0,
            'rgD' => ($effRate - $macro->nominalGdpGrowth) * $dPrev,
            'interestD' => $effRate * $dPrev,
        ];
        foreach ($vals as $k => $v) {
            $flow[$k] += $v;
        }
        ++$nLate;
        $realIdxStart ??= $macro->sovereignFundRealReturnIndex;
        $realIdxEnd = $macro->sovereignFundRealReturnIndex;
    }
    if ($tick % TPY === 0 && $tick > LATE_FROM_YEAR * TPY) {
        $lateDebt[] = $d;
        foreach ($cum as $k => $v) {
            $lateCum[$k] += $v;
        }
    }
    $dPrev = $d;
}

$nSamples = count($lateDebt);
$result = [
    'seed' => $seed,
    'fund' => $withFund,
    'lateDebt' => $lateDebt,
    'lateDebtMean' => array_sum($lateDebt) / $nSamples,
    'd0' => $d0,
    // Mean over the late annual samples of each cumulative contribution: lateDebtMean = d0 + sum of these.
    'decomp' => array_map(static fn (float $v): float => $v / $nSamples, $lateCum),
    'flow' => array_map(static fn (float $v): float => $v / $nLate, $flow),
    'fundRealReturn' => ($withFund && $realIdxStart > 0.0) ? log($realIdxEnd / $realIdxStart) / 30.0 : 0.0,
    'speed' => MonetaryPolicySubsystem::CB_SMOOTHING_SPEED,
];
file_put_contents($out, json_encode($result) . "\n");
exit(0);

<?php
// Full macro loop (no fund) with the politics beside it on its own stream, as the ticker runs them; one JSON line per election: the Diet the talks faced, the
// outgoing cabinet, and what the talks produced; and one per cabinet fall ('fall' => true), with the cabinet that fell. Feeds formation_fit.py (the formation constants) and the report.
// php formation_run.php <seedFrom> <seedTo> <years> <tpy> <out>
require '/home/quebeco/Projects/Code/Private/aerie-trading/tests/bootstrap.php';
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Politics\PoliticsEngine;
use App\Service\Math\MathUtility;
use App\Data\Politics\AerieDiet;
[$script, $seedFrom, $seedTo, $years, $tpy, $out] = $argv;
$fh = fopen($out, 'w');
$tpy = (int) $tpy;
for ($seed = (int) $seedFrom; $seed <= (int) $seedTo; ++$seed) {
    mt_srand($seed);
    $math = new MathUtility();
    $redis = new class extends \Redis {
        private array $store = [];
        public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
        public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
    };
    $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
        new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
    $politics = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
    $policy = $politics->liveState()->policy();
    $outgoing = AerieDiet::governingParties(AerieDiet::SEED_COALITION);
    $outgoingSupport = [];
    $ticks = (int) $years * $tpy;
    for ($i = 1; $i <= $ticks; ++$i) {
        $macro = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
        $m = $politics->updatePolitics($macro, 1.0 / $tpy);
        $policy = $m->policy();
        if ($m->lastCabinetFellAt === $m->totalTime) {
            fwrite($fh, json_encode([
                's' => $seed, 't' => round($m->totalTime, 4), 'fall' => true, 'seats' => $m->dietSeats, 'fallen' => $outgoing, 'fallenSupport' => $outgoingSupport,
                'cabinet' => AerieDiet::governingParties($m->pendingCoalition), 'support' => AerieDiet::governingParties($m->pendingSupport), 'log' => $m->formationLog,
            ]) . "\n");
            $outgoing = AerieDiet::governingParties($m->pendingCoalition);
            $outgoingSupport = AerieDiet::governingParties($m->pendingSupport);
        }
        if ($m->lastElectionAt === $m->totalTime) {
            fwrite($fh, json_encode([
                's' => $seed, 't' => round($m->totalTime, 3), 'seats' => $m->dietSeats, 'shares' => $m->dietVoteShares, 'positions' => $m->partyPositions, 'blocs' => $m->dietBlocs, 'lastCrisis' => $macro->lastCreditCrisisAt,
                'outgoing' => $outgoing, 'outgoingSupport' => $outgoingSupport,
                'cabinet' => AerieDiet::governingParties($m->pendingCoalition), 'support' => AerieDiet::governingParties($m->pendingSupport),
                'log' => $m->formationLog, 'swings' => $m->dietVoteSwings, 'incumbentSwing' => $m->electionIncumbentSwing,
                'growthGap' => $m->electionGrowthGap, 'inflationGap' => $m->electionInflationGap, 'debt' => $macro->sovereignDebtToGdp,
            ]) . "\n");
            $outgoing = AerieDiet::governingParties($m->pendingCoalition);
            $outgoingSupport = AerieDiet::governingParties($m->pendingSupport);
        }
    }
    fflush($fh);
}

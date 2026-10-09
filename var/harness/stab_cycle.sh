#!/bin/bash
# stab_cycle.sh <tag> <beta> <phi> <kappa> [seeds=36] [years=100]: one point of the stabilisation-rule fit, macro only.
# Builds OVR $D/ovr-<tag>: MacroEngine with the three FUND_STABILISATION_* constants, and a SovereignFundSubsystem whose
# update() does nothing (the fund StabCycleProbeTest writes in stays put). tag "nostab" (any beta) runs the rule off.
# Env ROUND=<years> (budget round period), GAPREAD=raw (rule reads the gap, not its EMA), DEMAND=raw (demand reads
# the stabilisation, not its EMA) test the loop's delays. Rows land in $D/<tag>-k.json; score with stab_cycle_an.py.
set -e
P=/home/quebeco/Projects/Code/Private/aerie-trading
H=$P/var/harness
TAG=$1; BETA=$2; PHI=$3; KAPPA=$4; N=${5:-36}; Y=${6:-100}
D=${STAB_DIR:-$H/stab_cycle}
O=$D/ovr-$TAG
mkdir -p "$O/Service/Macro/Subsystem"
python3 - "$P" "$O" "$TAG" "$BETA" "$PHI" "$KAPPA" <<'PY'
import sys, re
P, O, TAG, BETA, PHI, KAPPA = sys.argv[1:]
def sub(src, name, val):
    new, n = re.subn(rf"public const {name} = [0-9.]+;", f"public const {name} = {val};", src)
    assert n == 1, name
    return new
s = open(f'{P}/src/Service/Macro/MacroEngine.php').read()
s = sub(s, 'FUND_STABILISATION_GAP_RESPONSE', BETA)
s = sub(s, 'FUND_STABILISATION_IMPULSE_REVERSAL', PHI)
s = sub(s, 'FUND_STABILISATION_PERSISTENCE', KAPPA)
open(f'{O}/Service/Macro/MacroEngine.php', 'w').write(s)
f = open(f'{P}/src/Service/Macro/Subsystem/SovereignFundSubsystem.php').read()
old = "    public function update(MacroState $state, float $dt): void\n    {\n"
assert f.count(old) == 1
open(f'{O}/Service/Macro/Subsystem/SovereignFundSubsystem.php', 'w').write(f.replace(old, old + "        return;\n"))
c = open(f'{P}/src/Service/Macro/Subsystem/CreditFiscalSubsystem.php').read()
if TAG == 'nostab':
    old = "        if ($state->sovereignFundDollarsPerGdp <= 0.0) {\n            $state->sovereignFundStabilisationToGdp = 0.0;"
    assert c.count(old) == 1
    c = c.replace(old, "        if (true) {\n            $state->sovereignFundStabilisationToGdp = 0.0;")
import os
if os.environ.get('GAPREAD') == 'raw':
    old = "($state->outputGapEma - $state->sovereignFundGapTrend)"
    assert c.count(old) == 1
    c = c.replace(old, "($state->outputGap - $state->sovereignFundGapTrend)")
open(f'{O}/Service/Macro/Subsystem/CreditFiscalSubsystem.php', 'w').write(c)
if os.environ.get('ROUND'):
    s = open(f'{O}/Service/Macro/MacroEngine.php').read()
    s = sub(s, 'BUDGET_ROUND_PERIOD_YEARS', os.environ['ROUND'])
    open(f'{O}/Service/Macro/MacroEngine.php', 'w').write(s)
a = open(f'{P}/src/Service/Macro/Subsystem/MacroAggregateSubsystem.php').read()
if os.environ.get('DEMAND') == 'raw':
    old = "$state->sovereignFundStabilisationToGdpEma / MacroEngine::TARGET_CORPORATE_TAX_RATE"
    assert a.count(old) == 1
    a = a.replace(old, "$state->sovereignFundStabilisationToGdp / MacroEngine::TARGET_CORPORATE_TAX_RATE")
open(f'{O}/Service/Macro/Subsystem/MacroAggregateSubsystem.php', 'w').write(a)
PY
rm -f "$D/$TAG"-*.json
cd "$P"
for ((k = 0; k < N / 2; k++)); do
  echo "env OVR=$O SEEDS=$((k * 2 + 1)),$((k * 2 + 2)) YEARS=$Y TPY=${TPY:-180} OUT=$D/$TAG-$k.json php -d memory_limit=2G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/StabCycleProbeTest.php > $D/$TAG-$k.log 2>&1"
done | xargs -P "${JOBS:-16}" -I{} sh -c '{}'
ls "$D/$TAG"-*.json | wc -l

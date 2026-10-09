#!/bin/bash
# crisis_fit.sh <tag> <drag base> <drag per gap> <decay> [seeds=36] [years=120]: one point of the crisis-drag fit on the
# US arm (the District's JRST terms off), scored against JRST 2021 Table 8 by jrst_an.py. OVR: $FC_DIR/ovr-<tag>.
set -e
P=/home/quebeco/Projects/Code/Private/aerie-trading
H=$P/var/harness
TAG=$1; BASE=$2; PER=$3; DECAY=$4; N=${5:-36}; Y=${6:-120}
D=${FC_DIR:-$H/jrst_runs}
O=$D/ovr-$TAG
mkdir -p "$O/Service/Macro/Subsystem"
python3 - "$P" "$O" "$BASE" "$PER" "$DECAY" <<'PY'
import sys, re
P, O, BASE, PER, DECAY = sys.argv[1:]
s = open(f'{P}/src/Service/Macro/Subsystem/CreditFiscalSubsystem.php').read()
def sub(name, val):
    global s
    s, n = re.subn(rf"public const {name} = [0-9.]+;", f"public const {name} = {val};", s)
    assert n == 1, name
sub('DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT', '0.0')
sub('DISTRICT_THIN_CAPITAL_DRAG_SCALE', '1.0')
sub('CREDIT_CRISIS_DRAG_BASE', BASE)
sub('CREDIT_CRISIS_DRAG_PER_GAP', PER)
sub('CREDIT_CRISIS_DRAG_DECAY', DECAY)
open(f'{O}/Service/Macro/Subsystem/CreditFiscalSubsystem.php', 'w').write(s)
PY
rm -f "$D/$TAG"-*.json
cd "$P"
for ((k = 0; k < N / 2; k++)); do
  echo "env OVR=$O SEEDS=$((k * 2 + 1)),$((k * 2 + 2)) YEARS=$Y TPY=${TPY:-180} OUT=$D/$TAG-$k.json php -d memory_limit=2G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/CreditMacroProbeTest.php > /dev/null 2>&1"
done | xargs -P "${JOBS:-16}" -I{} sh -c '{}'

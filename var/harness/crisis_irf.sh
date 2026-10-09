#!/bin/bash
# crisis_irf.sh <tag> <drag base> <drag per gap> <decay> [seeds=32]: the engine's gap response to one credit crisis.
# OVR $D/ovr-<tag>: CreditFiscalSubsystem on the US arm (District terms off) with the three drag constants, and a
# calculateCreditCrisisHazard that draws its dice every tick in both runs (so the paired runs share every draw) but lets
# a crisis land only when FORCE_CRISIS_AT is crossed, with the boom behind it set by CRISIS_GAP. CrisisImpulseProbeTest
# runs each seed without and with the crisis; crisis_irf_an.py reads the difference against the 2008 CBO gap path.
set -e
P=/home/quebeco/Projects/Code/Private/aerie-trading
H=$P/var/harness
TAG=$1; BASE=$2; PER=$3; DECAY=$4; N=${5:-32}
D=${IRF_DIR:-$H/crisis_irf}
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
old = """        $yearsSinceLast = $state->lastCreditCrisisAt < 0.0 ? INF : $state->totalTime - $state->lastCreditCrisisAt;
        if ($yearsSinceLast < self::CREDIT_CRISIS_REFRACTORY_YEARS) {
            $state->creditCrisisHazard = 0.0;
            return;
        }

        $state->creditCrisisHazard = $this->mathUtility->calculateSchularickTaylorCrisisHazard(
            creditGap: $state->creditToGdpGapEma,
            beta0: self::CREDIT_CRISIS_LOGIT_INTERCEPT + self::DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT,
            betaGap: self::CREDIT_CRISIS_LOGIT_GAP
        );

        if (!$this->mathUtility->checkProbability($state->creditCrisisHazard * $dt)) {
            return;
        }

        $state->lastCreditCrisisAt = $state->totalTime;
        $crisisDrag = self::DISTRICT_THIN_CAPITAL_DRAG_SCALE
            * (self::CREDIT_CRISIS_DRAG_BASE + (self::CREDIT_CRISIS_DRAG_PER_GAP * max(0.0, $state->creditToGdpGapEma)));"""
new = """        $state->creditCrisisHazard = $this->mathUtility->calculateSchularickTaylorCrisisHazard(
            creditGap: $state->creditToGdpGapEma,
            beta0: self::CREDIT_CRISIS_LOGIT_INTERCEPT + self::DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT,
            betaGap: self::CREDIT_CRISIS_LOGIT_GAP
        );
        $this->mathUtility->checkProbability($state->creditCrisisHazard * $dt);
        $at = getenv('FORCE_CRISIS_AT');
        if ($at === false || $at === '' || !($state->totalTime >= (float) $at && $state->totalTime - $dt < (float) $at)) {
            return;
        }

        $state->lastCreditCrisisAt = $state->totalTime;
        $crisisDrag = self::DISTRICT_THIN_CAPITAL_DRAG_SCALE
            * (self::CREDIT_CRISIS_DRAG_BASE + (self::CREDIT_CRISIS_DRAG_PER_GAP * (float) getenv('CRISIS_GAP')));"""
assert s.count(old) == 1
s = s.replace(old, new)
open(f'{O}/Service/Macro/Subsystem/CreditFiscalSubsystem.php', 'w').write(s)
PY
rm -f "$D/$TAG"-*.json
cd "$P"
for ((k = 0; k < N / 2; k++)); do
  echo "env OVR=$O SEEDS=$((k * 2 + 1)),$((k * 2 + 2)) OUT=$D/$TAG-$k.json php -d memory_limit=2G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/CrisisImpulseProbeTest.php > $D/$TAG-$k.log 2>&1"
done | xargs -P "${JOBS:-16}" -I{} sh -c '{}'

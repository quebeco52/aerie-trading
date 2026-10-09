#!/bin/bash
# scale_arms.sh <s> [s ...]: OVR arms us_s<s> and nocrisis_s<s> (from ovr/us and ovr/nocrisis, fc_arms.sh) with the
# anonymous demand shocks on one scale: DEMAND_SHOCK_SIGMA x s and the disaster sizes x s (their exponential rates and
# cap divided and multiplied by s), intensity and up-share unchanged. Run each with fc_run.sh <arm>.
set -e
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
SRC=/home/quebeco/Projects/Code/Private/aerie-trading/src/Service/Macro/Subsystem/MacroAggregateSubsystem.php
for S in "$@"; do
  for base in us nocrisis; do
    O=$H/ovr/${base}_s$S/Service/Macro/Subsystem
    mkdir -p $O
    cp $H/ovr/$base/Service/Macro/Subsystem/CreditFiscalSubsystem.php $O/
    python3 - "$SRC" "$O/MacroAggregateSubsystem.php" "$S" <<'PY'
import sys, re
src, dst, s = sys.argv[1], sys.argv[2], float(sys.argv[3])
t = open(src).read()
def scale(name, f):
    global t
    m = re.search(rf"public const {name} = ([0-9.]+);", t); assert m, name
    t = t.replace(m.group(0), f"public const {name} = {f(float(m.group(1))):.6f};")
scale('DEMAND_SHOCK_SIGMA', lambda v: v * s)
scale('DEMAND_DISASTER_UP_RATE', lambda v: v / s)
scale('DEMAND_DISASTER_DOWN_RATE', lambda v: v / s)
scale('DEMAND_DISASTER_CAP', lambda v: v * s)
open(dst, 'w').write(t)
PY
  done
done
echo "scale arms built: $*"

#!/bin/bash
# tim_setup.sh <snapdir>: trees for tim_run.sh (spending-timing level part of GDP).
#   t0tree = git archive 9344b2de (arm A); t1tree = 9344b2de + working-tree src (arms B-D).
#   ovrB / ovrD: working-tree MacroAggregateSubsystem with SPENDING_TIMING_SHOCK_SD 0.002 / 0.006 (C = file as is).
set -eu
SNAP=$1
REV=${REV:-9344b2de}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
for t in t0tree t1tree; do
  rm -rf "$SNAP/$t"; mkdir -p "$SNAP/$t"
  git archive "$REV" | tar -x -C "$SNAP/$t"
  ln -s "$PROJ/vendor" "$SNAP/$t/vendor"
done
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/t1tree/$f"; done
MA=Service/Macro/Subsystem/MacroAggregateSubsystem.php
for a in B:0.002 D:0.006; do
  arm=${a%%:*}; v=${a#*:}
  rm -rf "$SNAP/ovr$arm"; mkdir -p "$SNAP/ovr$arm/Service/Macro/Subsystem"
  sed "s/SPENDING_TIMING_SHOCK_SD = 0.004;/SPENDING_TIMING_SHOCK_SD = $v;/" "src/$MA" > "$SNAP/ovr$arm/$MA"
  grep -c "SPENDING_TIMING_SHOCK_SD = $v;" "$SNAP/ovr$arm/$MA"
done
grep -c 'spendingTimingGap' "$SNAP/t1tree/src/$MA"
grep -c 'spendingTimingGap' "$SNAP/t0tree/src/$MA" || true
rm -rf "$PROJ/var/harness/cache-t0tree" "$PROJ/var/harness/cache-t1tree"

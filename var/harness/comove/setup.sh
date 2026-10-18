#!/bin/bash
# setup.sh <snapdir>: trees for run.sh (mainland-District common demand factor).
#   cotree0 = git archive REV (default HEAD): arm A, the old disturbance.
#   cotree1 = git archive REV + working-tree src: arm D (loading 0.95, sigma 0.025, disaster 0.04).
#   ovrB / ovrC: working-tree MacroAggregateSubsystem with DEMAND_SHOCK_MAINLAND_LOADING 0.80 / 0.90.
#   ovrE: D with TFP_POTENTIAL_ABSORPTION_SPEED 0.75 -> 1.0.
set -eu
SNAP=$1
REV=${REV:-HEAD}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
for t in cotree0 cotree1; do
  rm -rf "$SNAP/$t"; mkdir -p "$SNAP/$t"
  git archive "$REV" | tar -x -C "$SNAP/$t"
  ln -s "$PROJ/vendor" "$SNAP/$t/vendor"
done
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/cotree1/$f"; echo "copied $f"; done
MA=Service/Macro/Subsystem/MacroAggregateSubsystem.php
for a in B:0.80 C:0.90; do
  arm=${a%%:*}; v=${a#*:}
  rm -rf "$SNAP/ovr$arm"; mkdir -p "$SNAP/ovr$arm/Service/Macro/Subsystem"
  sed "s/DEMAND_SHOCK_MAINLAND_LOADING = 0.95;/DEMAND_SHOCK_MAINLAND_LOADING = $v;/" "src/$MA" > "$SNAP/ovr$arm/$MA"
  echo "ovr$arm: $(grep -c "DEMAND_SHOCK_MAINLAND_LOADING = $v;" "$SNAP/ovr$arm/$MA")"
done
rm -rf "$SNAP/ovrE"; mkdir -p "$SNAP/ovrE/Service/Macro/Subsystem"
sed "s/TFP_POTENTIAL_ABSORPTION_SPEED = 0.75;/TFP_POTENTIAL_ABSORPTION_SPEED = 1.0;/" "src/$MA" > "$SNAP/ovrE/$MA"
echo "ovrE: $(grep -c 'TFP_POTENTIAL_ABSORPTION_SPEED = 1.0;' "$SNAP/ovrE/$MA")"
echo "cotree0 mainland refs: $(grep -c 'demandMainlandShock' "$SNAP/cotree0/src/$MA" || true)"
echo "cotree1 mainland refs: $(grep -c 'demandMainlandShock' "$SNAP/cotree1/src/$MA")"
rm -rf "$PROJ/var/harness/cache-cotree0" "$PROJ/var/harness/cache-cotree1"

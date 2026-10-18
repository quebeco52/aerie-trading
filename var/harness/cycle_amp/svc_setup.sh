#!/bin/bash
# svc_setup.sh <snapdir>: trees for svc_run.sh (services relative-price shock).
#   svctree = git archive REV (default HEAD) + working-tree src (arm C, SERVICES_PRICE_SHOCK_SD 0.005).
#   ovrA / ovrB / ovrD: working-tree MacroAggregateSubsystem with SERVICES_PRICE_SHOCK_SD 0.0 / 0.004 / 0.006.
set -eu
SNAP=$1
REV=${REV:-HEAD}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
rm -rf "$SNAP/svctree"; mkdir -p "$SNAP/svctree"
git archive "$REV" | tar -x -C "$SNAP/svctree"
ln -s "$PROJ/vendor" "$SNAP/svctree/vendor"
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/svctree/$f"; echo "copied $f"; done
MA=Service/Macro/Subsystem/MacroAggregateSubsystem.php
for a in A:0.0 B:0.004 D:0.006; do
  arm=${a%%:*}; v=${a#*:}
  rm -rf "$SNAP/ovr$arm"; mkdir -p "$SNAP/ovr$arm/Service/Macro/Subsystem"
  sed "s/SERVICES_PRICE_SHOCK_SD = 0.005;/SERVICES_PRICE_SHOCK_SD = $v;/" "src/$MA" > "$SNAP/ovr$arm/$MA"
  echo "ovr$arm: $(grep -c "SERVICES_PRICE_SHOCK_SD = $v;" "$SNAP/ovr$arm/$MA")"
done
echo "svctree servicesPriceShock refs: $(grep -c 'servicesPriceShock' "$SNAP/svctree/src/$MA")"
rm -rf "$PROJ/var/harness/cache-svctree"

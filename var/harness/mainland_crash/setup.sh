#!/bin/bash
# setup.sh <snapdir>: tree for run.sh (mainland crash jump).
#   mctree = git archive REV (default HEAD) + working-tree src: arm B (crash on, sigma 0.00452).
#   ovrA = working-tree AssetMarketSubsystem with MAINLAND_CRASH_INTENSITY 0.0 and MAINLAND_GAP_SIGMA 0.00490:
#   arm A, HEAD's mainland on B's random stream (the crash draws are still made).
set -eu
SNAP=$1
REV=${REV:-HEAD}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
rm -rf "$SNAP/mctree"; mkdir -p "$SNAP/mctree"
git archive "$REV" | tar -x -C "$SNAP/mctree"
ln -s "$PROJ/vendor" "$SNAP/mctree/vendor"
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/mctree/$f"; echo "copied $f"; done
AM=Service/Macro/Subsystem/AssetMarketSubsystem.php
rm -rf "$SNAP/ovrA"; mkdir -p "$SNAP/ovrA/Service/Macro/Subsystem"
sed -e "s/MAINLAND_CRASH_INTENSITY = 0.025;/MAINLAND_CRASH_INTENSITY = 0.0;/" \
    -e "s/MAINLAND_GAP_SIGMA = 0.00452;/MAINLAND_GAP_SIGMA = 0.00490;/" "src/$AM" > "$SNAP/ovrA/$AM"
echo "ovrA: $(grep -c -e 'MAINLAND_CRASH_INTENSITY = 0.0;' -e 'MAINLAND_GAP_SIGMA = 0.00490;' "$SNAP/ovrA/$AM") of 2 edits"
echo "mctree crash refs: $(grep -c 'MAINLAND_CRASH_INTENSITY = 0.025' "$SNAP/mctree/src/$AM")"
rm -rf "$PROJ/var/harness/cache-mctree"

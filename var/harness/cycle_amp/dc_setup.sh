#!/bin/bash
# dc_setup.sh <snapdir>: trees for dc_run.sh (drag compensators).
#   dctree = git archive REV (default HEAD) + working-tree src (arm B, compensators on, timing SD 0.004).
#   ovrA: the working-tree MacroAggregateSubsystem with the three compensator constants at 0.0 (arm A = timing arm C).
set -eu
SNAP=$1
REV=${REV:-HEAD}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
rm -rf "$SNAP/dctree"; mkdir -p "$SNAP/dctree"
git archive "$REV" | tar -x -C "$SNAP/dctree"
ln -s "$PROJ/vendor" "$SNAP/dctree/vendor"
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/dctree/$f"; done
MA=Service/Macro/Subsystem/MacroAggregateSubsystem.php
rm -rf "$SNAP/ovrA"; mkdir -p "$SNAP/ovrA/Service/Macro/Subsystem"
sed -e 's/CREDIT_PREMIUM_NOISE_ADVERSE_MEAN = 0.00145;/CREDIT_PREMIUM_NOISE_ADVERSE_MEAN = 0.0;/' \
    -e 's/CATASTROPHE_ADVERSE_BURDEN_MEAN = 0.24;/CATASTROPHE_ADVERSE_BURDEN_MEAN = 0.0;/' \
    -e 's/EPU_NOISE_ADVERSE_LOG_MEAN = 0.082;/EPU_NOISE_ADVERSE_LOG_MEAN = 0.0;/' "src/$MA" > "$SNAP/ovrA/$MA"
echo "ovrA zeroed constants: $(grep -cE '(NOISE_ADVERSE_MEAN|ADVERSE_BURDEN_MEAN|NOISE_ADVERSE_LOG_MEAN) = 0.0;' "$SNAP/ovrA/$MA") (want 3)"
echo "ovrA vs src diff lines: $(diff "src/$MA" "$SNAP/ovrA/$MA" | grep -c '^>')"
echo "dctree src matches working tree: $(diff -rq src "$SNAP/dctree/src" | wc -l) differences"
rm -rf "$PROJ/var/harness/cache-dctree"

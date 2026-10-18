#!/bin/bash
# inv2_setup.sh <snapdir> <step1 src copy dir>: build the trees and overrides for inv2_run.sh (accelerator step 2).
#   s1tree = git archive HEAD + step-1 src copies (arm A); s2tree = git archive HEAD + working-tree src (arms B-E).
#   ovrC: HEAD's MonetaryPolicySubsystem (no inventory look-through); ovrD: mu 0.3; ovrE: lambda 1.6.
set -eu
SNAP=$1; STEP1=$2
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
for t in s1tree s2tree; do
  rm -rf "$SNAP/$t"; mkdir -p "$SNAP/$t"
  git archive HEAD | tar -x -C "$SNAP/$t"
  ln -s "$PROJ/vendor" "$SNAP/$t/vendor"
done
(cd "$STEP1" && find . -name '*.php') | while read -r f; do cp "$STEP1/$f" "$SNAP/s1tree/src/$f"; done
git diff --name-only HEAD -- src | while read -r f; do cp "$f" "$SNAP/s2tree/$f"; done
MA=Service/Macro/Subsystem/MacroAggregateSubsystem.php
MP=Service/Macro/Subsystem/MonetaryPolicySubsystem.php
rm -rf "$SNAP"/ovrC "$SNAP"/ovrD "$SNAP"/ovrE
mkdir -p "$SNAP/ovrC/Service/Macro/Subsystem" "$SNAP/ovrD/Service/Macro/Subsystem" "$SNAP/ovrE/Service/Macro/Subsystem"
git show "HEAD:src/$MP" > "$SNAP/ovrC/$MP"
sed 's/INVENTORY_SURPRISE_SENSITIVITY = 0.60;/INVENTORY_SURPRISE_SENSITIVITY = 0.30;/' "src/$MA" > "$SNAP/ovrD/$MA"
sed 's/INVENTORY_ADJUSTMENT_SPEED = 0.80;/INVENTORY_ADJUSTMENT_SPEED = 1.60;/' "src/$MA" > "$SNAP/ovrE/$MA"
grep -c 'INVENTORY_SURPRISE_SENSITIVITY = 0.30;' "$SNAP/ovrD/$MA"
grep -c 'INVENTORY_ADJUSTMENT_SPEED = 1.60;' "$SNAP/ovrE/$MA"
grep -c 'inventoryInvestmentGap' "$SNAP/ovrC/$MP" || true
grep -c 'inventoriesSeenThrough' "$SNAP/s2tree/src/$MP"
rm -rf "$PROJ/var/harness/cache-s1tree" "$PROJ/var/harness/cache-s2tree"

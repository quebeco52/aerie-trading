#!/bin/bash
# wr_setup.sh <snapdir>: trees for wr_run.sh (wage downward-rigidity sweep).
#   wrtree = git archive REV (default HEAD) + working-tree src (all arms).
#   ovr<arm>/Service/Macro/Subsystem/LaborMarketSubsystem.php = working-tree file with harness accumulators
#   (time-weighted wage growth, its target, downward share; static, read and reset by the harness each quarter)
#   and WAGE_DOWNWARD_RIGIDITY_FACTOR: A 0.30 (as is), B 0.60, C 1.00.
set -eu
SNAP=$1
REV=${REV:-HEAD}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
rm -rf "$SNAP/wrtree"; mkdir -p "$SNAP/wrtree"
git archive "$REV" | tar -x -C "$SNAP/wrtree"
ln -s "$PROJ/vendor" "$SNAP/wrtree/vendor"
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/wrtree/$f"; done
LM=Service/Macro/Subsystem/LaborMarketSubsystem.php
for arm in A:0.30 B:0.60 C:1.00; do
  a=${arm%%:*}; v=${arm##*:}
  rm -rf "$SNAP/ovr$a"; mkdir -p "$SNAP/ovr$a/Service/Macro/Subsystem"
  sed -e "s/WAGE_DOWNWARD_RIGIDITY_FACTOR = 0.30;/WAGE_DOWNWARD_RIGIDITY_FACTOR = $v;\n    public static float \$hW = 0.0;\n    public static float \$hT = 0.0;\n    public static float \$hDn = 0.0;\n    public static float \$hDt = 0.0;/" \
      -e 's/^\(        \)\$state->wageGrowth += \$adjustmentSpeed \* \$wageGap \* \$dt;/&\n\1self::$hW += $state->wageGrowth * $dt; self::$hT += $targetWageGrowth * $dt; self::$hDn += ($wageGap > 0 ? 0.0 : $dt); self::$hDt += $dt;/' \
      "src/$LM" > "$SNAP/ovr$a/$LM"
  echo "ovr$a: factor $(grep -o 'WAGE_DOWNWARD_RIGIDITY_FACTOR = [0-9.]*' "$SNAP/ovr$a/$LM"), added lines $(diff "src/$LM" "$SNAP/ovr$a/$LM" | grep -c '^>') (want 6)"
  php -l "$SNAP/ovr$a/$LM" | head -1
done
echo "wrtree src matches working tree: $(diff -rq src "$SNAP/wrtree/src" | wc -l) differences"
rm -rf "$PROJ/var/harness/cache-wrtree"

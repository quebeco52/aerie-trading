#!/bin/bash
# setup_echo.sh <snapdir>: tree and overlay for arm X of the mainland-echo check (run.sh X <seed>).
#   r2echo = git archive REV (default HEAD) + working-tree src (git diff --name-only REV -- src): the spillover fix,
#   mainlandNextQuarter's mean reads domesticDemandGapEma instead of outputGapEma.
#   ovrX = r2echo AMS + the pairing overlay P (same sed as setup.sh). Arm G is round 2's G (HEAD 2c3c87c8 + P).
set -eu
SNAP=$1
REV=${REV:-HEAD}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
D=Service/Macro/Subsystem
AMS=$D/AssetMarketSubsystem.php
rm -rf "$SNAP/r2echo" "$SNAP/ovrX"; mkdir -p "$SNAP/r2echo" "$SNAP/ovrX/$D"
git archive "$REV" | tar -x -C "$SNAP/r2echo"; ln -s "$PROJ/vendor" "$SNAP/r2echo/vendor"
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/r2echo/$f"; echo "copied $f"; done
sed -e '/private function stepMainlandQuarter/,/private static function mainlandCrashProbability/s/\$this->mathUtility->/$this->mainlandRng()->/g' \
    -e '/^    \/\*\* Chance a crash strikes the mainland in a given quarter. \*\/$/i\    private ?MathUtility $mainlandStream = null;\n\n    private function mainlandRng(): MathUtility\n    {\n        return $this->mainlandStream ??= MathUtility::ownStream(((int) getenv('"'"'SEED'"'"')) * 7919 + 1);\n    }\n' \
    "$SNAP/r2echo/src/$AMS" > "$SNAP/ovrX/$AMS"
c() { grep -c -F -e "$1" "$SNAP/ovrX/$AMS"; }
echo "ovrX grep: mainlandRng() draws $(c '$this->mainlandRng()->') [6], ownStream $(c 'MathUtility::ownStream(((int) getenv(') [1]," \
  "domesticDemandGapEma spillover $(c 'FOREIGN_IMPORT_SPILLOVER * $state->domesticDemandGapEma') [1]," \
  "outputGapEma spillover $(c 'FOREIGN_IMPORT_SPILLOVER * $state->outputGapEma') [0]"
php -l "$SNAP/ovrX/$AMS" > /dev/null && echo "lint ok" || echo "LINT FAIL"
echo "diff ovrX vs r2echo AMS: $(diff "$SNAP/r2echo/src/$AMS" "$SNAP/ovrX/$AMS" | grep -c '^[<>]') lines"
echo "r2echo src vs HEAD: $(git diff --stat "$REV" -- src | tail -1)"
rm -rf "$PROJ/var/harness/cache-r2echo"

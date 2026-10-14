#!/bin/bash
# run.sh a|b <seed>: whole-ticker replay of ErneWingHarnessTest.php on the macro paths recorded in var/harness/loon/macro.
#   a  treeA_ew (git archive of the base commit + copied vendor), its own phpunit
#   s  treeS_ew = base tree with only the working-tree steel seasonality patched in
#   b  working tree via the treeB_ew symlink (own container cache var/harness/cache-treeB_ew)
# Env: YEARS (12), TPY (360). Outputs var/harness/erne_wing/runs/<arm>-<seed>.{jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
PATH_GZ=$PROJ/var/harness/loon/macro/path-$2.gz
[ -f "$PATH_GZ" ] || { echo "no recorded macro path $PATH_GZ (record with var/harness/loon/run.sh rec $2)" >&2; exit 2; }
case "$1" in
  a) TREE="$H/treeA_ew" ;;
  b) TREE="$H/treeB_ew" ;;
  s) TREE="$H/treeS_ew" ;;
  *) echo "usage: run.sh a|b|s <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.jsonl"
cd "$PROJ" && env RATING_PROBE="${RATING_PROBE:-WING}" MACRO_REPLAY="$PATH_GZ" BASE="$TREE" SEED="$2" YEARS="${YEARS:-12}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  bin/php-slot php -d memory_limit=3G "$TREE/vendor/bin/phpunit" --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/ErneWingHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

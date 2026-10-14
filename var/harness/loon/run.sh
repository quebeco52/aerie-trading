#!/bin/bash
# run.sh <arm> <seed>: whole-ticker replay of LoonHarnessTest.php (FullMarketHarnessTest + consensus EPS, nominal GDP
# index and LOON's growth-capex gate per quarter).
#   rec  working tree, records the macro path for <seed> (not analysed)
#   b    working tree (via the treeB symlink, so it gets its own container cache), replays the path
#   b2   working tree after the payout 0.75 -> 0.63 change (same as b, new label)
#   c    working tree with LOON_CAPEX_RATIO=0.05 set after seeding
#   a    var/harness/loon/treeA (git archive of the base commit + copied vendor), replays the same path
# Env: YEARS (12), TPY (360). Outputs var/harness/loon/runs/<arm>-<seed>.{jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs" "$H/macro"
[ -e "$H/treeB" ] || ln -s "$PROJ" "$H/treeB"
PATH_GZ=$H/macro/path-$2.gz
case "$1" in
  rec) MAC="MACRO_RECORD=$PATH_GZ"; TREE="$H/treeB" ;;
  b)   MAC="MACRO_REPLAY=$PATH_GZ"; TREE="$H/treeB" ;;
  a)   MAC="MACRO_REPLAY=$PATH_GZ"; TREE="$H/treeA" ;;
  b2)  MAC="MACRO_REPLAY=$PATH_GZ"; TREE="$H/treeB" ;;
  c)   MAC="MACRO_REPLAY=$PATH_GZ"; TREE="$H/treeB"; export LOON_CAPEX_RATIO=0.05 ;;
  *) echo "usage: run.sh rec|a|b|b2|c <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.jsonl"
cd "$PROJ" && env "$MAC" BASE="$TREE" SEED="$2" YEARS="${YEARS:-12}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  bin/php-slot php -d memory_limit=3G "$TREE/vendor/bin/phpunit" --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/LoonHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

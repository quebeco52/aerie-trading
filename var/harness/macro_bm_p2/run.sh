#!/bin/bash
# run.sh a|b <seed>: whole-ticker LIVE macro run of MacroBmP2HarnessTest.php (no replay: Part 1 changes macro fields
# the firms read, commercialMarketRent, systemDepositBeta, housing starts, so each arm runs its own macro).
#   a  HEAD tree (git archive e2c56af + COPIED vendor) at /tmp/claude-1000/headtree, its own phpunit
#   b  working tree via the treeB_p2 symlink (own container cache var/harness/cache-treeB_p2)
# Env: YEARS (20), TPY (360). POLITICS off, fund on (as loon / erne_wing). Outputs runs/<arm>-<seed>.{jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
[ -e "$H/treeB_p2" ] || ln -s "$PROJ" "$H/treeB_p2"
case "$1" in
  a) TREE="${TREE_A:-/tmp/claude-1000/headtree}" ;;
  b) TREE="$H/treeB_p2" ;;
  *) echo "usage: run.sh a|b <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.jsonl"
cd "$PROJ" && env BASE="$TREE" SEED="$2" YEARS="${YEARS:-30}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  bin/php-slot php -d memory_limit=3G "$TREE/vendor/bin/phpunit" --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/MacroBmP2HarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

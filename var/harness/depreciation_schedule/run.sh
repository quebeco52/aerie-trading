#!/bin/bash
# run.sh <arm> <seed>: whole-ticker run of FullMarketHarnessTest.php (earnings_b1 fork + depreciation probe), no politics.
#   rec      base tree, live macro, records the macro path to $H/macro/<seed>.gz
#   base     base tree, replays that path          new      new tree, replays it
#   newlive  new tree, live macro (pair with rec for the board->macro loop)
# Each arm runs its OWN tree's vendor/bin/phpunit and tests/bootstrap.php (ROOT); kernel cache in cache-<arm>/.
# Env: YEARS (15) TPY (360) H (/tmp/claude-1000/depsched) BASE_TREE NEW_TREE. Output: $H/runs/<arm>-<seed>.jsonl
set -eu
D=$(cd "$(dirname "$0")" && pwd)
H=${H:-/tmp/claude-1000/depsched}
BASE_TREE=${BASE_TREE:-/tmp/claude-1000/snap-base}
NEW_TREE=${NEW_TREE:-/home/quebeco/Projects/Code/Private/aerie-trading/.claude/worktrees/depreciation-state}
mkdir -p "$H/runs" "$H/macro"
MR=""; MP=""
case "$1" in
  rec)     ROOT=$BASE_TREE; MR="$H/macro/$2.gz" ;;
  base)    ROOT=$BASE_TREE; MP="$H/macro/$2.gz" ;;
  new)     ROOT=$NEW_TREE;  MP="$H/macro/$2.gz" ;;
  newlive) ROOT=$NEW_TREE ;;
  *) echo "usage: run.sh rec|base|new|newlive <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.jsonl"
cd "$D" && env ROOT="$ROOT" ARM="$1" OVR="$D/ovr-$1" SEED="$2" YEARS="${YEARS:-15}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  MACRO_RECORD="$MR" MACRO_REPLAY="$MP" \
  "$NEW_TREE/bin/php-slot" php -d memory_limit=3G "$ROOT/vendor/bin/phpunit" --do-not-cache-result \
  --bootstrap "$D/bootstrap.php" --no-configuration "$D/FullMarketHarnessTest.php"

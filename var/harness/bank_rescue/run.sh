#!/bin/bash
# run.sh new|base <seed>: live whole-ticker run (POLITICS=1) writing one macro row a quarter.
#   new   the main working tree
#   base  the worktree at tree-base/ (rescue never touches debt), run on its own vendor, bootstrap and harness copy
# Env: YEARS (100), TPY (360). Output: var/harness/bank_rescue/runs/<arm>-<seed>.jsonl
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
OUTF=$H/runs/$1-$2.jsonl
rm -f "$OUTF"
case "$1" in
  new)  TREE=$PROJ; BASEV="" ;;
  base) TREE=$H/tree-base; BASEV=$H/tree-base ;;
  *) echo "usage: run.sh new|base <seed>" >&2; exit 2 ;;
esac
cd "$TREE" && env BASE="$BASEV" POLITICS=1 MACRO_OUT="$OUTF" SEED="$2" YEARS="${YEARS:-100}" TPY="${TPY:-360}" \
  "$PROJ/bin/php-slot" php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  var/harness/FullMarketHarnessTest.php

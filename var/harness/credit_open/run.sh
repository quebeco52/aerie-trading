#!/bin/bash
# run.sh before|after <seed>: live whole-ticker run (POLITICS=1, HEALTH=1) of credit_open/LenderQuarterHarnessTest.php.
#   after   the main working tree (BASE=main, cache var/harness/cache-aerie-trading: delete it after a constructor change)
#   before  a HEAD worktree in BEFORE_TREE (default $TMPDIR/co-before, `git worktree add` + copied vendor)
# Env: YEARS (8), TPY (360). Output: var/harness/credit_open/runs/<arm>-<seed>.jsonl and <arm>-<seed>.lq.json
M=/home/quebeco/Projects/Code/Private/aerie-trading
H=$M/var/harness/credit_open
OUTD=${RUNS:-$H/runs}
mkdir -p "$OUTD"; rm -f "$OUTD/$1-$2.jsonl" "$OUTD/$1-$2.lq.json"
case "$1" in
  after)  T=$M ;;
  before) T=${BEFORE_TREE:-${TMPDIR:-/tmp}/co-before} ;;
  *) echo "usage: run.sh before|after <seed>" >&2; exit 2 ;;
esac
cd "$T" && env BASE="$T" POLITICS=1 HEALTH=1 SEED="$2" YEARS="${YEARS:-8}" TPY="${TPY:-360}" \
  OUT="$OUTD/$1-$2.jsonl" LQ_OUT="$OUTD/$1-$2.lq.json" \
  "$M/bin/php-slot" php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/LenderQuarterHarnessTest.php"

#!/bin/bash
# run.sh <arm> <seed>: live full-market run (POLITICS=1, FISCAL=1 MACRO_OUT) on one tree.
#   net    main working tree (rules read GFSM net debt)
#   worth  the worktree in WORTH_TREE (rules read net financial worth), run from that tree with BASE=<tree>
# Env: YEARS (60), TPY (360). Output: var/harness/net_worth/runs/<arm>-<seed>.macro.jsonl
M=/home/quebeco/Projects/Code/Private/aerie-trading
OUTD=$M/var/harness/net_worth/runs
mkdir -p "$OUTD"
case "$1" in
  net) T=$M; B="" ;;
  worth) T=${WORTH_TREE:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/e5a3e6f6-e47c-4342-bfe5-3d39a97d68db/scratchpad/worth}; B=$T ;;
  *) echo "usage: run.sh net|worth <seed>" >&2; exit 2 ;;
esac
rm -f "$OUTD/$1-$2.macro.jsonl"
cd "$T" && env BASE="$B" POLITICS=1 FISCAL=1 SEED="$2" YEARS="${YEARS:-60}" TPY="${TPY:-360}" \
  MACRO_OUT="$OUTD/$1-$2.macro.jsonl" OUT="$OUTD/$1-$2.jsonl" \
  "$M/bin/php-slot" php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  var/harness/FullMarketHarnessTest.php

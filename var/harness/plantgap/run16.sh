#!/bin/bash
# run16.sh <arm> <seed>: the plantgap logging probe on a git-archive tree (BASE) with the logging overlay in front.
#   base  BASE=$TREES/pgbase (HEAD), OVR=ovr-baselog (HEAD TreasuryEngine + logging)
#   fund  BASE=$TREES/pgfund (HEAD + the fundPlannedPlant TreasuryEngine), OVR=ovr-fundlog (that file + logging)
# Build the overlays with mklog.py. Env: TREES (dir holding the trees), YEARS (25), TPY (360).
# Outputs runs/<arm>-<seed>.{pg.jsonl,short.json,out.jsonl,log}. Outlook salt 1000+SEED.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
TREES=${TREES:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/858be824-ff2f-44da-ac20-89a284b3e6e9/scratchpad}
mkdir -p "$H/runs"
case "$1" in
  base) B=$TREES/pgbase; O=$H/ovr-baselog ;;
  fund) B=$TREES/pgfund; O=$H/ovr-fundlog ;;
  *) echo "usage: run16.sh base|fund <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.pg.jsonl" "$H/runs/$1-$2.out.jsonl"
cd "$PROJ" && env BASE="$B" OVR="$O" SEED="$2" YEARS="${YEARS:-25}" TPY="${TPY:-360}" PGLOG="$H/runs/$1-$2.pg.jsonl" \
  SHORT_OUT="$H/runs/$1-$2.short.json" OUT="$H/runs/$1-$2.out.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/../autoshort/AutoShortHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

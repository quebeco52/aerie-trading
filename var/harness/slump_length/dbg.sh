#!/bin/bash
# dbg.sh <seed> <ovrdir>: one arm-H-style run with a debug overlay (e.g. ovrHdbg, which logs DBGNAN lines when a
# stock's tick price or volatility goes non-finite). Output runs/dbg-<seed>.log.
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP}
cd "$PROJ" && env BASE="$SNAP/sltree" OVR="$2" POLITICS="${POLITICS:-1}" SEED="$1" YEARS="${YEARS:-52}" TPY="${TPY:-360}" \
  OUT="$H/runs/dbg-$1.jsonl" MACRO_OUT="$H/runs/dbg-$1.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/SlumpLengthHarnessTest.php" > "$H/runs/dbg-$1.log" 2>&1

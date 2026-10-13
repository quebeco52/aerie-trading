#!/bin/bash
# dti_run.sh <arm> <seed>: household DTI drift question on the HEAD snapshot ($SNAP/catree), no overrides.
#   DA = POLITICS=1, DB = POLITICS=0, both at TPY (360). DT = POLITICS=1 at TPY 3600 (the live ticker's rate).
# Env: YEARS (20), TPY (360), SNAP. Outputs runs/<arm>-<seed>.{macro.jsonl,jsonl,log}.
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding catree/}
mkdir -p "$H/runs"
TPYV=${TPY:-360}
case "$1" in
  DA) POL=1 ;;
  DB) POL=0 ;;
  DT) POL=1; TPYV=3600 ;;
  *) echo "usage: dti_run.sh DA|DB|DT <seed>" >&2; exit 2 ;;
esac
POLV=""
[ "$POL" = 1 ] && POLV=1
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/catree" OVR="" POLITICS="$POLV" SEED="$2" YEARS="${YEARS:-20}" TPY="$TPYV" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/CycleAmpHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

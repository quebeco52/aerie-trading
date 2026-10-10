#!/bin/bash
# run.sh new|old <seed>: live macro + Diet (POLITICS=1), BOARD_NI probe, MACRO_OUT quarterly rows.
#   new  the working tree;  old  OLD=<tree> (built by make_old.sh).
# Env: YEARS (60), TPY (360). Outputs var/harness/tax_split/runs/<arm>-<seed>.{macro.jsonl,jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
case "$1" in
  new) TREE=$PROJ; BASEV="" ;;
  old) TREE=${OLD:?set OLD=<tree>}; BASEV=$TREE ;;
  *) echo "usage: run.sh new|old <seed>" >&2; exit 2 ;;
esac
R=$H/runs
mkdir -p "$R"
rm -f "$R/$1-$2.macro.jsonl" "$R/$1-$2.jsonl"
cd "$TREE" && env BASE="$BASEV" POLITICS=1 BOARD_NI=1 SEED="$2" YEARS="${YEARS:-60}" TPY="${TPY:-360}" \
  MACRO_OUT="$R/$1-$2.macro.jsonl" OUT="$R/$1-$2.jsonl" \
  "$PROJ/bin/php-slot" php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  var/harness/FullMarketHarnessTest.php > "$R/$1-$2.log" 2>&1

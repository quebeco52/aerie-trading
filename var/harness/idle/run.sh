#!/bin/bash
# run.sh <arm> <seed>: whole-ticker replay with the idle-KPI probe (IdleHarnessTest.php).
#   rec   working tree, records the macro path for <seed>
#   idle  working tree, replays the recorded path
#   base  basetree-cf0e6108 (git archive of HEAD + vendor symlink), replays the same path
# Env: YEARS (20), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,log} and runs/<arm>-<seed>.idle.json.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs" "$H/macro"
PATH_GZ=$H/macro/path-$2.gz
case "$1" in
  rec)  MAC="MACRO_RECORD=$PATH_GZ"; BASEV="" ;;
  idle) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;
  ovr)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OVR=$H/ovr ;;  # working tree + ovr/ src overrides
  plain) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;  # probe-free check: same as idle without the KPI log
  base) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=${BASE:-$H/basetree-cf0e6108} ;;
  *) echo "usage: run.sh rec|idle|base|plain|ovr <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env "$MAC" BASE="$BASEV" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  IDLE_OUT="$H/runs/$1-$2.idle.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$([ "$1" = plain ] && echo "$PROJ/var/harness/FullMarketHarnessTest.php" || echo "$H/IdleHarnessTest.php")" > "$H/runs/$1-$2.log" 2>&1

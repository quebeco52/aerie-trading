#!/bin/bash
# run.sh <arm> <seed>: autoshort's whole-board probe (../autoshort/AutoShortHarnessTest.php) with logging copies of
# CapitalBudgetEngine and TreasuryEngine in front (ovrlog/, behaviour-neutral): one JSON line per non-financial report
# with the accelerator's plan, the internal and treasury build, maintenance, CIP and the capital base.
#   log    working tree + ovrlog/.      Other arms: ovr-<name>/ holds a fix prototype on top of ovrlog copies.
# Env: YEARS (25), TPY (360). Outputs runs/<arm>-<seed>.{pg.jsonl,short.json,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
case "$1" in
  log) export OVR=$H/ovrlog ;;
  *) [ -d "$H/ovr-$1" ] || { echo "no arm $1" >&2; exit 2; }; export OVR=$H/ovr-$1 ;;
esac
rm -f "$H/runs/$1-$2.pg.jsonl"
cd "$PROJ" && env SEED="$2" YEARS="${YEARS:-25}" TPY="${TPY:-360}" PGLOG="$H/runs/$1-$2.pg.jsonl" SHORT_OUT="$H/runs/$1-$2.short.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/../autoshort/AutoShortHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

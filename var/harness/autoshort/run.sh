#!/bin/bash
# run.sh <arm> <seed>: whole-board run with the industry-balance probe (AutoShortHarnessTest.php).
#   head  working tree. ovr / ovr15: working tree + ovr/ (speed 0.25) or ovr15/ (0.15) src copies in front (OVR).
# Env: YEARS (25), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,log,short.json}. Outlook salt 1000+SEED.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
case "$1" in
  head) ;;
  ovr) export OVR=$H/ovr ;;
  ovr15) export OVR=$H/ovr15 ;;
  base) export BASE=${BASE:?set BASE=<git archive tree with vendor/composer copied>} ;;  # another tree's src
  *) echo "usage: run.sh head|ovr|ovr15|base <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env SEED="$2" YEARS="${YEARS:-25}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" SHORT_OUT="$H/runs/$1-$2.short.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/AutoShortHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

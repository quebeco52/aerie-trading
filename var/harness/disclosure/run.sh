#!/bin/bash
# run.sh <arm> <seed>: full-market run (live macro, no Diet) with the Securities Commission's disclosure fields forced.
#   light     threshold 0.30, earnings-management scale 1.5
#   founding  0.20, 1.0
#   strict    0.10, 0.5
# Env: YEARS (30), TPY (360). Output: runs/<arm>-<seed>.json (DISC_OUT), runs/<arm>-<seed>.log.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
case "$1" in
  light)    PWT=0.30; EMS=1.5 ;;
  founding) PWT=0.20; EMS=1.0 ;;
  strict)   PWT=0.10; EMS=0.5 ;;
  *) echo "usage: run.sh light|founding|strict <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env PWT="$PWT" EMS="$EMS" SEED="$2" YEARS="${YEARS:-30}" TPY="${TPY:-360}" DISC_OUT="$H/runs/$1-$2.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$H/bootstrap.php" --no-configuration \
  "$H/DisclosureHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

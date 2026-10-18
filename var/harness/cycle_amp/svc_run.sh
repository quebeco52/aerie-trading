#!/bin/bash
# svc_run.sh <arm> <seed>: ServicesHarnessTest.php on the services-shock arms (trees from svc_setup.sh).
#   A svctree + ovrA (sd 0.0); B ovrB (0.004); C svctree as is (0.005); D ovrD (0.006).
# Env: YEARS (33), TPY (360), POLITICS (1), SNAP (dir from svc_setup.sh). Outputs runs/S<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from svc_setup.sh}
mkdir -p "$H/runs"
case "$1" in
  C) OVRV="" ;;
  *) OVRV="$SNAP/ovr$1" ;;
esac
rm -f "$H/runs/S$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/svctree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-33}" TPY="${TPY:-360}" \
  OUT="$H/runs/S$1-$2.jsonl" MACRO_OUT="$H/runs/S$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/ServicesHarnessTest.php" > "$H/runs/S$1-$2.log" 2>&1

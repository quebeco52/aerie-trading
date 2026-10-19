#!/bin/bash
# run.sh <arm> <seed>: SlumpRound2HarnessTest.php on the round-2 arms (trees and overlays from setup.sh).
#   A: r2head + ovrA.  G, P1, P2, H2, N1, S1: r2new + ovr<arm>.
#   X: r2echo + ovrX (setup_echo.sh, the mainland-echo check).
# Env: YEARS (52), TPY (360), POLITICS (1), SNAP (dir from setup.sh). Outputs runs/<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from setup.sh}
mkdir -p "$H/runs"
case "$1" in
  A) TREE=r2head ;;
  G|P1|P2|H2|N1|S1) TREE=r2new ;;
  X) TREE=r2echo ;;
  *) echo "unknown arm $1"; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/$TREE" OVR="$SNAP/ovr$1" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-52}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/SlumpRound2HarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

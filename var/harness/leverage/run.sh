#!/bin/bash
# run.sh <arm> <seed>: LeverageHarnessTest.php (live macro, whole board, HEALTH=1) on a frozen snapshot tree.
#   L0  baseline: $SNAP/levtree as is (gross recap leverage, cash-first funding)
#   L1  net recap leverage: OVR copies in $SNAP/ovrL (src-relative paths)
#   L<n> any other OVR set in $SNAP/ovr<n>
#   C0/C<n> the same with TREE=<snapshot dir name under SNAP> (credit-spread arms: C0 no OVR, C<n> $SNAP/ovr<n>)
# Env: YEARS (20), TPY (360), POLITICS (1), SNAP (dir holding levtree/ ovrL/), TREE (levtree).
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding levtree/ and ovrL/}
mkdir -p "$H/runs"
case "$1" in
  L0|C0) OVRV="" ;;
  L1) OVRV="$SNAP/ovrL" ;;
  L*|C*) OVRV="$SNAP/ovr${1#?}" ;;
  *) echo "usage: run.sh L0|L1|L<n>|C0|C<n> <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.jsonl"
cd "$PROJ" && env BASE="$SNAP/${TREE:-levtree}" OVR="$OVRV" HEALTH=1 POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" OPENING_OUT="$H/runs/$1-$2.open.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/LeverageHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

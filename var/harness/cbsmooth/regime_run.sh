#!/bin/bash
# regime_run.sh <arm> <seed>: RegimeHarnessTest.php (live macro, whole board, politics) on a frozen snapshot tree.
#   R1  HEAD as is: $SNAP/rgtree (git archive HEAD + vendor symlink)
#   R<n> OVR copies in $SNAP/ovr<n> (src-relative paths) on the same tree
# Env: YEARS (21), TPY (360), POLITICS (1), SNAP (dir holding rgtree/ and ovr<n>/).
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding rgtree/}
mkdir -p "$H/runs"
case "$1" in
  R1) OVRV="" ;;
  R*) OVRV="$SNAP/ovr${1#R}" ;;
  *) echo "usage: regime_run.sh R1|R<n> <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/rgtree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-21}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/RegimeHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

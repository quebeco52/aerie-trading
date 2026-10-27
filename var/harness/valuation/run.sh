#!/bin/bash
# run.sh <arm> <seed>: valuation predictability (ValuationHarnessTest.php), live macro, no politics, price_vol probe on.
#   head   working tree (HEAD when clean)
# Env: YEARS (20), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,log,val.json}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
case "$1" in
  head) BASEV="" ;;  # working tree
  base) BASEV=${BASE:?set BASE=<tree>} ;;  # BASE tree (git archive + vendor symlink)
  d1) BASEV="" ;;  # working tree with fix D1 (filed returns)
  *) echo "usage: run.sh head|base|d1 <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env BASE="$BASEV" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" VAL_OUT="$H/runs/$1-$2.val.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/ValuationHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

#!/bin/bash
# run.sh <arm> <seed>: realised vs configured price vol (PriceVolHarnessTest.php), live macro, no politics.
#   probe   working tree, channel probe on (PROBE=1)
#   plain   working tree, probe off (read-only check against probe: same OUT record)
#   bis     BASE=<tree> (git archive + vendor symlink, outside the repo), probe off; TAG names the outputs
# Env: YEARS (10), TPY (360), SKIP (warm-up ticks, default TPY), TAG (output suffix).
# Outputs runs/<arm>-<seed><TAG>.{jsonl,log,pv.json}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
TAG=${TAG:-}
case "$1" in
  probe) PRB=1; BASEV="" ;;
  plain) PRB=""; BASEV="" ;;
  bis)   PRB=""; BASEV=${BASE:?set BASE=<tree>} ;;
  old)   # BASE=<98549819 tree>: that commit's own harness + probe (old98/), tick CSV of channel returns
    cd "$PROJ" && env BASE="${BASE:?set BASE=<tree>}" SEED="$2" YEARS="${YEARS:-10}" TPY="${TPY:-360}" OUT="$H/runs/old-$2$TAG.jsonl" \
      PROBE_CSV="$H/runs/old-$2$TAG.csv" PROBE_JSON="$H/runs/old-$2$TAG.json" \
      bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
      "$H/old98/PriceVolHarnessTest.php" > "$H/runs/old-$2$TAG.log" 2>&1
    exit $? ;;
  *) echo "usage: run.sh probe|plain|bis|old <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env PROBE="$PRB" BASE="$BASEV" SEED="$2" YEARS="${YEARS:-10}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2$TAG.jsonl" PV_OUT="$H/runs/$1-$2$TAG.pv.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/PriceVolHarnessTest.php" > "$H/runs/$1-$2$TAG.log" 2>&1

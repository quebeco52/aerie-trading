#!/bin/bash
# home_share.sh <arm> <seed>: HomeShareImpactTest.php, arm base|closed|open. Env: YEARS (8) TPY (360) SWITCH_YEAR (3.0).
# Output: var/harness/fund/runs_home/<arm>-<seed>.{jsonl,log}
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
RUNS=${RUNS:-$H/runs_home}
mkdir -p "$RUNS" && RUNS=$(cd "$RUNS" && pwd)
cd "$PROJ" && env ARM="$1" SEED="$2" YEARS="${YEARS:-8}" TPY="${TPY:-360}" SWITCH_YEAR="${SWITCH_YEAR:-3.0}" OUT="$RUNS/$1-$2.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/HomeShareImpactTest.php" > "$RUNS/$1-$2.log" 2>&1

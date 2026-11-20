#!/usr/bin/env bash
# run.sh <arm> <seed>: one 20y HEALTH=1 board run. Arm "head" replays on BASE_TREE (a git archive with vendor
# symlinked), any other arm on the working tree. Output runs/<TAG><arm>-s<seed>.jsonl (truncated first: BoardRun appends).
#   BASE_TREE=<tree> TAG=cs- bin/harness-batch var/harness/risk-ladder/run.sh 1-4 head new
set -uo pipefail
arm=$1 seed=$2
dir=var/harness/risk-ladder
out=$dir/runs/${TAG:-}${arm}-s${seed}.jsonl
mkdir -p "$dir/runs"
rm -f "$out"
base=()
if [[ $arm == head ]]; then
    base=(env BASE="${BASE_TREE:?set BASE_TREE}")
fi
"${base[@]}" env HEALTH=1 YEARS="${YEARS:-20}" TPY="${TPY:-360}" SEED="$seed" OUT="$out" \
    bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
    $dir/RiskLadderHarnessTest.php

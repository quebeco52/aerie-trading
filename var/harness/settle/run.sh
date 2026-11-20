#!/usr/bin/env bash
# run.sh <arm> <seed>: one settle run (frozen macro, no draws unless DRAWS set). Arm "head" runs on BASE_TREE (a git
# archive with vendor symlinked) through var/harness/bootstrap.php; any other arm on the working tree.
# Output runs/<TAG><arm>.jsonl.   BASE_TREE=<tree> TAG=cs_ bin/harness-batch var/harness/settle/run.sh 1 head new
set -uo pipefail
arm=$1 seed=$2
dir=var/harness/settle
base=()
if [[ $arm == head ]]; then
    base=(env BASE="${BASE_TREE:?set BASE_TREE}")
fi
"${base[@]}" env RATINGS="${RATINGS:-}" YEARS="${YEARS:-10}" SEED="$seed" OUT="$dir/runs/${TAG:-}${arm}.jsonl" \
    bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
    $dir/SettleHarnessTest.php

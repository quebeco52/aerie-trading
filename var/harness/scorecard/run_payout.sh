#!/usr/bin/env bash
# run_payout.sh <arm> <seed>: one whole-board run of PayoutHarnessTest. Arm `pbase` runs the BASE tree in PAY_BASE
# (git archive of dfef1d97, vendor symlinked); any other arm runs the working tree. Env YEARS (20), TPY (360).
set -u
arm="$1"; seed="$2"
dir="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$dir/../../.." && pwd)"
mkdir -p "$dir/runs"
rm -f "$dir/runs/$arm-s$seed.jsonl"
cd "$root" || exit 1
base=""
if [ "${arm#pbase}" != "$arm" ]; then base="${PAY_BASE:?set PAY_BASE to the base tree}"; fi
BASE="$base" SEED="$seed" YEARS="${YEARS:-20}" TPY="${TPY:-360}" OUT="$dir/runs/$arm-s$seed.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  var/harness/scorecard/PayoutHarnessTest.php

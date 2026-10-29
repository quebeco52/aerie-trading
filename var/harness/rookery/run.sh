#!/usr/bin/env bash
# run.sh <arm> <seed>: one whole-board run of RookeryHarnessTest on the working tree. Env YEARS (20), TPY (360).
set -u
arm="$1"; seed="$2"
dir="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$dir/../../.." && pwd)"
mkdir -p "$dir/runs"
cd "$root" || exit 1
SEED="$seed" YEARS="${YEARS:-20}" TPY="${TPY:-360}" ROOKERY_OUT="$dir/runs/$arm-s$seed.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  var/harness/rookery/RookeryHarnessTest.php

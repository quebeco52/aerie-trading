#!/usr/bin/env bash
# run.sh <arm> <seed>: one whole-board run of SecurityFirmsHarnessTest. Env YEARS (30), TPY (360).
# Arms: live / overlap run the working tree; full loads ovr_full/ (IndustryShareLedger with mixOverlap() = 1.0, the
# rule before the revenue-mix overlap) in front of src through the bootstrap's OVR autoloader.
set -u
arm="$1"; seed="$2"
dir="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$dir/../../.." && pwd)"
mkdir -p "$dir/runs"
cd "$root" || exit 1
ovr=""
[ "$arm" = full ] && ovr="$dir/ovr_full"
OVR="$ovr" SEED="$seed" YEARS="${YEARS:-30}" TPY="${TPY:-360}" SECFIRMS_OUT="$dir/runs/$arm-s$seed.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  var/harness/security_firms/SecurityFirmsHarnessTest.php

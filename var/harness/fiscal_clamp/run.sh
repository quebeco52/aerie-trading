#!/bin/bash
# run.sh <arm> <seed>: corporate-tax clamp probe. Outputs var/harness/fiscal_clamp/runs/<arm>-<seed>.jsonl.
#   A  full ticker, POLITICS=1 (board + fund feed the macro), YEARS_A (100) at TPY_A (360)
#   B  macro + politics only (no board, no fund), YEARS_B (200) at tpy 48
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
cd "$PROJ"
case "$1" in
  A) rm -f "$H/runs/A-$2.jsonl"
     env POLITICS=1 SEED="$2" YEARS="${YEARS_A:-100}" TPY="${TPY_A:-360}" CLAMP_OUT="$H/runs/A-$2.jsonl" OUT="$H/runs/A-$2.summary.jsonl" \
       bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
       "$H/FiscalClampHarnessTest.php" ;;
  B) bin/php-slot php -d memory_limit=3G "$H/macro_politics.php" "$2" "${YEARS_B:-200}" "$H/runs/B-$2.jsonl" ;;
  *) echo "usage: run.sh A|B <seed>" >&2; exit 2 ;;
esac

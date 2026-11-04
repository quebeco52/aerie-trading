#!/bin/bash
# run.sh <arm> <seed>: BRKW book/beta/payout probe (BrkwHarnessTest.php), live macro, no politics.
#   base  BASE tree (git archive of HEAD + vendor symlink), pass BASE=<tree>
#   fix   working tree (10-30)
#   bb    working tree with the buyback fix (10-31)
#   pay   working tree, bb + BRKW target_payout_ratio 0.60 (10-31)
#   rw    working tree 10-31 (2): anchor-stake rework (sit out buybacks, dividends paid, sum-of-parts NAV)
#   rw2   working tree 10-31 (3): + whole holding return, dividends on shares of record, charter deals
#   rw3   working tree 10-31 (5): rw2 + AnchorHoldings::MIN_TRADABLE_FLOAT 0.05 -> 0.10
#   rw4   working tree 10-31 (6): rw3 + held firms return surplus by pro-rata redemption
#   rw5   working tree 11-01: rw4 + subsidiaries repriced in NAV, nothing repriced twice, cash takes the discount
# Env: YEARS (25), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,brkw.jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
case "$1" in
  base) BASEV=${BASE:?set BASE=<tree>} ;;
  fix|bb|pay|rw|rw2|rw3|rw4|rw5|probe0) BASEV="" ;;
  *) echo "usage: run.sh base|fix|bb|pay|rw|rw2|rw3|rw4|rw5|probe0 <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.jsonl" "$H/runs/$1-$2.brkw.jsonl"
BO="$H/runs/$1-$2.brkw.jsonl"
[ "$1" = probe0 ] && BO=""
cd "$PROJ" && env BASE="$BASEV" SEED="$2" YEARS="${YEARS:-25}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" BRKW_OUT="$BO" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/BrkwHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

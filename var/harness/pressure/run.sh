#!/usr/bin/env bash
# run.sh <tree>-<episode> <seed>: one seed of ../politics/gate.php arm concede, for bin/harness-batch.
#   tree     head (var/harness/pressure/head_tree, a detached checkout of the baseline commit) or new (this tree)
#   episode  off (gate.php's no-politics arm, the one irf.sh fitted against), base (no episode, the paired baseline on the same code path), rand (one episode from STEP_AT whose
#            length is drawn on PoliticalPressure::CONTINUATION, as irf.sh fitted the constants), or L<n> (exactly n quarters)
# Writes runs/$STAGE/<tree>-<episode>_<seed>.jsonl; YEARS (22), TPY (180), STEP_AT (10).
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
arm=$1 seed=$2
tree=${arm%%-*} episode=${arm#*-}
case $tree in
    head) gate=$here/head_tree/var/harness/politics/gate.php ;;
    new) gate=$here/../politics/gate.php ;;
    *) echo "run.sh: unknown tree $tree" >&2; exit 2 ;;
esac
gatearm=concede
case $episode in
    off) gatearm=off ;;
    base) export CONCEDE_QUARTERS=0 ;;
    rand) unset CONCEDE_QUARTERS ;;
    L*) export CONCEDE_QUARTERS=${episode#L} ;;
    *) echo "run.sh: unknown episode $episode" >&2; exit 2 ;;
esac
out=$here/runs/${STAGE:?set STAGE}
mkdir -p "$out"
export STEP_AT=${STEP_AT:-10}
exec "$here/../../../bin/php-slot" php -d memory_limit=1G "$gate" "$seed" "$seed" "${YEARS:-22}" "${TPY:-180}" "$gatearm" "$out/${arm}_${seed}.jsonl"

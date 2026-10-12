#!/usr/bin/env bash
# bisect_run.sh <label>-<base|rand|L<n>> <seed>: episode_gate.php on trees/<label> (a detached checkout made by
# bisect_tree.sh, or "new" for this tree), for bin/harness-batch. Writes runs/$STAGE/<label>-<episode>_<seed>.jsonl.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
arm=$1 seed=$2
label=${arm%%-*} episode=${arm#*-}
if [[ $label == new ]]; then root=$(cd "$here/../../.." && pwd); else root=$here/trees/$label; fi
case $episode in
    base) export CONCEDE_QUARTERS=0 ;;
    rand) unset CONCEDE_QUARTERS ;;
    L*) export CONCEDE_QUARTERS=${episode#L} ;;
    *) echo "bisect_run.sh: unknown episode $episode" >&2; exit 2 ;;
esac
out=$here/runs/${STAGE:?set STAGE}
mkdir -p "$out"
exec "$here/../../../bin/php-slot" php -d memory_limit=1G "$here/episode_gate.php" "$root" "$seed" "${YEARS:-22}" "${TPY:-180}" "$out/${arm}_${seed}.jsonl"

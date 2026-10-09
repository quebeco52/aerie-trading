#!/bin/bash
# batch.sh: record macro paths (default seeds 1-24), then replay both arms on them; at most 8 PHP processes at once.
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/bank_credit
SEEDS=${SEEDS:-$(seq 1 24)}
printf '%s\n' $SEEDS | xargs -P 8 -I{} $H/run.sh rec {}
for s in $SEEDS; do echo "after $s"; echo "before $s"; done | xargs -P 8 -L 1 $H/run.sh
echo done

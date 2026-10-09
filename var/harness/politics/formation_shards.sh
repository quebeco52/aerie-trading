#!/bin/bash
# formation_shards.sh <label>: formation_run.php over seeds 1-50, 200 years, in 8 parallel shards, into formation/<label>_<i>.jsonl
cd "$(dirname "$0")"
L=$1
for i in 0 1 2 3 4 5 6 7; do
  php -d memory_limit=1G formation_run.php $((i * 7 + 1)) $((i == 7 ? 50 : i * 7 + 7)) 200 ${TPY:-180} formation/${L}_$i.jsonl 2> formation/${L}_$i.err &
done
wait

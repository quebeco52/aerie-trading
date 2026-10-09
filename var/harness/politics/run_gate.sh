#!/bin/bash
# run_gate.sh <label> <years> <seeds> <procs>: gate.php arms off and on, in parallel, into runs/<label>/<arm>_<i>.jsonl
cd "$(dirname "$0")"
L=$1; Y=${2:-100}; N=${3:-32}; P=${4:-8}
mkdir -p runs/$L
PER=$((N / P))
for arm in off on; do
  for i in $(seq 0 $((P - 1))); do
    php -d memory_limit=1G gate.php $((i * PER + 1)) $((i * PER + PER)) $Y ${TPY:-180} $arm runs/$L/${arm}_$i.jsonl 2> runs/$L/${arm}_$i.err &
  done
done
wait

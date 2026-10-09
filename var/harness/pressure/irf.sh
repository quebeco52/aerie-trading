#!/bin/bash
# irf.sh <label> [seeds] [years]: one episode of pressure the Authority gives ground to, from year 10 (gate.php arm
# concede), against the same seeds with none (arm off), 8 procs per arm, into var/harness/pressure/runs/<label>/.
cd "$(dirname "$0")/../politics"
L=$1; N=${2:-256}; Y=${3:-22}; P=8
OUT=../pressure/runs/$L
mkdir -p $OUT
PER=$((N / P))
for arm in off concede; do
  for i in $(seq 0 $((P - 1))); do
    php -d memory_limit=1G gate.php $((i * PER + 1)) $((i * PER + PER)) $Y ${TPY:-180} $arm $OUT/${arm}_$i.jsonl 2> $OUT/${arm}_$i.err &
  done
done
wait

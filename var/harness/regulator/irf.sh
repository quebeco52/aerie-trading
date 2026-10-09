#!/bin/bash
# irf.sh <label> [phase years] [seeds] [years]: the +1pp requirement step (gate.php arm step, at year 10, phased in
# linearly over <phase> years, 0 = at once) against the same seeds with nothing raised (arm off), 8 procs per arm,
# into var/harness/regulator/runs/<label>/{off,step}_<i>.jsonl. Analyse with irf_an.py.
cd "$(dirname "$0")/../politics"
L=$1; PH=${2:-0}; N=${3:-64}; Y=${4:-22}; P=8
OUT=../regulator/runs/$L
mkdir -p $OUT
PER=$((N / P))
for arm in off step; do
  for i in $(seq 0 $((P - 1))); do
    STEP_PHASE=$PH php -d memory_limit=1G gate.php $((i * PER + 1)) $((i * PER + PER)) $Y ${TPY:-180} $arm $OUT/${arm}_$i.jsonl 2> $OUT/${arm}_$i.err &
  done
done
wait

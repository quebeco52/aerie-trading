#!/bin/bash
# all.sh: run A (loop-fit, 36 seeds x 120y per arm) and B (full market POLITICS=1, 16 seeds x 60y per arm) at once;
# every PHP process queues on bin/php-slot (8 per session). Then both analyses into result.out.
M=/home/quebeco/Projects/Code/Private/aerie-trading
OLD=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/e5a3e6f6-e47c-4342-bfe5-3d39a97d68db/scratchpad/old
H=$M/var/harness/budget_size
cd "$M"
LOOPFIT_DIR=$H/runs/A bash var/harness/stabiliser_double_count/loopfit_tree.sh "$M" new 36 > "$H/A-new.status" 2>&1 &
LOOPFIT_DIR=$H/runs/A bash var/harness/stabiliser_double_count/loopfit_tree.sh "$OLD" old 36 > "$H/A-old.status" 2>&1 &
bin/harness-batch var/harness/budget_size/run.sh 1-16 new old > "$H/B.status" 2>&1 &
wait
cat "$H/A-new.status" "$H/A-old.status" "$H/B.status"
{
  python3 "$H/paired_a.py" "$H/runs/A" "$H/runs/A"
  python3 "$H/paired_b.py" "$H/runs"
} > "$H/result.out" && cat "$H/result.out"

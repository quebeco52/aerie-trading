#!/usr/bin/env bash
# replay.sh <arm> <seed>: run.sh on a fixed macro path, recorded by the arm `rec` and replayed by every other arm.
set -u
arm="$1"; seed="$2"
dir="$(cd "$(dirname "$0")" && pwd)"
if [[ $arm == rec ]]; then
  MACRO_RECORD="$dir/runs/macro-s$seed.gz" "$dir/run.sh" "$arm" "$seed"
else
  MACRO_REPLAY="$dir/runs/macro-s$seed.gz" "$dir/run.sh" "$arm" "$seed"
fi

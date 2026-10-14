#!/bin/bash
# run_pace.sh <arm> <seed>: run.sh with the pacing arms. P1 = the snapshot tree as is; P0 = the same tree with the
# ovr_pace0/ copy of CreditFiscalSubsystem.php (CONSOLIDATION_PACE = 10.0, the target lands in a tick or two).
H=$(cd "$(dirname "$0")" && pwd)
case "$1" in
  P0) OVR="$H/ovr_pace0" exec "$H/run.sh" "$1" "$2" ;;
  *) exec "$H/run.sh" "$1" "$2" ;;
esac

#!/usr/bin/env bash
# run_moat.sh <arm> <seed>: arm `base` runs the BASE tree in MOAT_BASE (git archive of e1b30b23, vendor symlinked),
# any other arm runs the working tree. Delegates to run.sh.
set -u
dir="$(cd "$(dirname "$0")" && pwd)"
if [ "$1" = "base" ]; then
  BASE="${MOAT_BASE:?set MOAT_BASE to the base tree}" exec "$dir/run.sh" "$1" "$2"
fi
exec "$dir/run.sh" "$1" "$2"

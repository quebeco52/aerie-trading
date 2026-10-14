#!/bin/bash
# run.sh <arm> <seed>: gscpi.php (macro loop + politics, no board) for one arm.
#   P = working tree (placeholder constants); L = OVR ovrL (fitted delivery loading); F = OVR ovrF (all fitted constants);
#   G = ovrG: F with the purge's mean shift folded into the GSCPI_*_MEAN constants;
#   Q = working tree after the raw-input, per-input-regressor rebuild; R = ovrR: Q with fit2.json's constants;
#   W = working tree with the round-2 constants applied in src (PC1 weights);
#   H = HEAD tree at $HEADTREE (git archive HEAD + cp -a vendor).
# Output runs/<arm>-<seed>.jsonl. Env: YEARS (30), TPY (360), HEADTREE.
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
BASEV=""; OVRV=""
case "$1" in
  P|Q|W) ;;
  R) OVRV="$H/ovrR" ;;
  L) OVRV="$H/ovrL" ;;
  F) OVRV="$H/ovrF" ;;
  G) OVRV="$H/ovrG" ;;
  H) BASEV=${HEADTREE:?set HEADTREE to a git archive HEAD tree with vendor copied} ;;
  *) echo "usage: run.sh P|Q|W|L|F|G|R|H <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env BASE="$BASEV" OVR="$OVRV" bin/php-slot php -d memory_limit=3G "$H/gscpi.php" "$2" "${YEARS:-30}" "${TPY:-360}" \
  "$H/runs/$1-$2.jsonl"

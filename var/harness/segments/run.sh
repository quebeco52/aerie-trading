#!/bin/bash
# run.sh <arm> <seed>: whole-ticker replay with the segment probe (SegmentsHarnessTest.php).
#   head   BASE=<git archive tree + vendor symlink> (the committed tree), outlook salt 1000+SEED, segment probe
#   seg    working tree, same salt and probe
#   segplain  working tree, no segment probe (read-only check against seg)
# Macro paths are the idle topic's recorded -oc paths (replay, so arms see the same macro).
# Env: YEARS (20), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,log,idle.json,seg.json}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
PATH_GZ=$PROJ/var/harness/idle/macro/path-$2-oc.gz
SEGV="SEG_OUT=$H/runs/$1-$2.seg.json"
case "$1" in
  head) BASEV=${BASE:?set BASE=<tree>} ;;
  seg|seg2|p4)  BASEV="" ;;  # seg2: after the RAVN entry fix; p4: phase 4 (deals by segment)
  p4live) BASEV=""; PATH_GZ="" ;;  # working tree, live macro (recorded paths hold 20 years only; for YEARS > 20)
  p3|p4c) BASEV=${BASE:?set BASE=<tree>} ;;  # p3: 803d2ade; p4c: b77f19c5 (phase 4 committed)
  econ) BASEV="" ;;  # segment economics + review fixes (working tree)
  econ2) BASEV=""; export OVR=$H/ovr_pl ;;  # working tree + SegmentBooks copy that logs segment prices/loads (read-only)
  econ2nj) BASEV=""; export OVR=$H/ovr_nj ;;  # as econ2, and every segment at the company avoidable-cost ratio (undoes fix 8)
  econ3) BASEV="" ;;  # working tree: takeover assets at revenue / the joined segment's turnover
  skyl) BASEV="" ;;  # working tree: SKYL keeps plant by segment, internet on its Bass path (head = 642a842c)
  wave2) BASEV="" ;;  # working tree: 8 more segmented companies (head = d29edb90)
  wave2pl) BASEV=""; export OVR=$H/ovr_pl ;;  # wave2 + ovr_pl price/load log (refreshed from src 2026-10-26; neutral)
  p5a) BASEV="" ;;  # working tree: phase 5a, capital follows return (head = 526ab166)
  p5b1) BASEV="" ;;  # working tree: one-plant harvest (head = e2e9fb3d)
  div1) BASEV="" ;;  # working tree: 5b-1 entry infrastructure, should be output-identical (head = f64f5afa)
  div2) BASEV="" ;;  # working tree: 5b-2, companies build into a new industry (head = ae6f0c4f)
  div3) BASEV="" ;;  # working tree: 5b-2 after the architect fixes (head = ae6f0c4f)
  div4) BASEV="" ;;  # working tree: 5b-2 with venture growth paths, outcomes and abandonment (head = ae6f0c4f)
  div5) BASEV="" ;;  # working tree: div4 + distance shortfall in venture outcomes, zero pending keys cleared (head = ae6f0c4f)
  econlive) BASEV=""; PATH_GZ="" ;;  # econ on the live macro, for YEARS > 20
  segplain) BASEV=""; SEGV="SEG_OFF=1" ;;
  gapfix) BASEV=""; export OVR=$H/ovr_gap ;;  # working tree + Redis store that reads segment_outlook_gap back
  *) echo "usage: run.sh head|seg|seg2|p3|p4|p4live|p4c|econ|econlive|econ2|econ2nj|econ3|skyl|wave2|wave2pl|p5a|p5b1|div1|div2|div3|div4|div5|segplain|gapfix <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env MACRO_REPLAY="$PATH_GZ" "$SEGV" BASE="$BASEV" OUTLOOK_SALT=1 SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" IDLE_OUT="$H/runs/$1-$2.idle.json" OUTLOOK_OUT="$H/runs/$1-$2.outlook.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/SegmentsHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1

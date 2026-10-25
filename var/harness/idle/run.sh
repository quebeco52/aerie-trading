#!/bin/bash
# run.sh <arm> <seed>: whole-ticker replay with the idle-KPI probe (IdleHarnessTest.php).
#   rec   working tree, records the macro path for <seed>
#   idle  working tree, replays the recorded path
#   base  basetree-cf0e6108 (git archive of HEAD + vendor symlink), replays the same path
# Env: YEARS (20), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,log} and runs/<arm>-<seed>.idle.json.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs" "$H/macro"
TAG=${TAG:-}  # e.g. TAG=-y40: separate macro path and outputs for a different horizon
PATH_GZ=$H/macro/path-$2$TAG.gz
case "$1" in
  rec)  MAC="MACRO_RECORD=$PATH_GZ"; BASEV="" ;;
  idle) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;
  fix)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;  # working tree (alias of idle, for the shortage-capex run)
  probe) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OVR=$H/ovr_probe PROBE_TICKER=${PROBE_TICKER:-FALC} ;;  # fix + gate log
  fix2) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;  # working tree, growth gates on WACC (section d)
  off)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;  # working tree, outlook as committed (drift vol 0)
  on)   MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OVR=$H/ovr_on OUTLOOK_SALT=1 ;;  # drift vol 0.08, salt 1000+seed
  off2) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;  # working tree (plant follows outlook, section f), outlook off
  on2)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OVR=$H/ovr_on OUTLOOK_SALT=1 ;;  # section f, outlook on
  onbase) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=${BASE:?set BASE=<tree>}; export OVR=$H/ovr_on OUTLOOK_SALT=1 ;;  # BASE tree, outlook on
  cal)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OVR=$H/ovr_cal OUTLOOK_SALT=1 ;;  # calibrated per-industry outlooks (section g)
  val)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 ;;  # section h: working tree, outlook as committed
  valnofvx) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 ;;  # FVX read-only check
  head) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=${BASE:?set BASE=<tree>}; export OUTLOOK_SALT=1 FVX=1 ;;  # section h: committed tree
  head5) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 ;;  # section i: HEAD cbe58c09 (= val dynamics)
  pre)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=${BASE:?set BASE=<tree>}; export OUTLOOK_SALT=1 FVX=1 ;;  # section i: pre-rework tree
  gapp) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 GAPPROBE=1 ;;  # HEAD + fair value at gap 0 / gap+0.1
  fund) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 GAPPROBE=1 ;;  # section j: catch-up after the payout cap
  prior) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 GAPPROBE=1 PGR=1 OVR=$H/ovr_pgr ;;  # section k (ovr_pgr = src Valuation + call log)
  priorplain) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 ;;  # section k read-only check
  gate) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 GAPPROBE=1 OVR=$H/ovr_gate PROBE_TICKERS=TERN,FALC,SKYL,EIDR ;;  # section l
  gateplain) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 ;;  # section l read-only check
  trace) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 OVR=$H/ovr_trace TRACE_TICKER=${TRACE_TICKER:-TERN} ;;  # section l buyback trace
  cmphead) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=${BASE:?set BASE=<HEAD tree>}; export OUTLOOK_SALT=1 FVX=1 OVR=$H/ovr_cmp_head TRACE_TICKERS=TERN,FALC ;;  # section m
  cmpgate) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OUTLOOK_SALT=1 FVX=1 OVR=$H/ovr_cmp_gate TRACE_TICKERS=TERN,FALC ;;  # section m
  ovr)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=""; export OVR=$H/ovr ;;  # working tree + ovr/ src overrides
  plain) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;  # probe-free check: same as idle without the KPI log
  base) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=${BASE:?set BASE=<git archive tree with a vendor symlink>} ;;
  *) echo "usage: run.sh rec|idle|fix|fix2|off|on|off2|on2|onbase|cal|val|valnofvx|head|head5|pre|gapp|fund|prior|priorplain|gate|gateplain|trace|cmphead|cmpgate|base|plain|ovr|probe <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env "$MAC" BASE="$BASEV" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2$TAG.jsonl" \
  IDLE_OUT="$H/runs/$1-$2$TAG.idle.json" OUTLOOK_OUT="$H/runs/$1-$2$TAG.outlook.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$([ "$1" = plain ] && echo "$PROJ/var/harness/FullMarketHarnessTest.php" || echo "$H/IdleHarnessTest.php")" > "$H/runs/$1-$2$TAG.log" 2>&1

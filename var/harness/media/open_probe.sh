#!/bin/bash
# open_probe.sh <variant> <seed>: a 0.1-year media run with BELL's seed data from ovr/<variant> ('base' = the tree).
# Writes runs/open_<variant>-s<seed>.json; its opening block holds BELL's seed revenue, EPS, book and price.
D=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/media
O=""; [ "$1" != base ] && O="$D/ovr/$1"
env ${O:+OVR=$O} YEARS=0.1 "$D/run.sh" "open_$1" "$2"

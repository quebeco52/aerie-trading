#!/bin/bash
# open_probe.sh <variant> <seed>: a 0.1-year airline run, seed data from ovr/<variant> ('base' = the tree).
D=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/airline
O=""; [ "$1" != base ] && O="$D/ovr/$1"
env ${O:+OVR=$O} YEARS=0.1 "$D/run.sh" "open_$1" "$2"

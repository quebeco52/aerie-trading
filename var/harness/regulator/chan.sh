#!/bin/bash
# chan.sh <arm: off|step|step4|noBridges|noPremium|noSLOOS|offBridges|offPremium|offSLOOS> <seed>: one gate.php seed into runs/${STAGE:-chan_8}/<arm>_<seed>.jsonl.
# step and no* arms raise the requirement 1pp at year STEP_AT=10, phased in linearly over STEP_PHASE (default 1) years;
# step4 phases in over 4 years (the BIS MAG comparison); no* arms run wt_<arm>/ (chan_setup.sh), the working tree
# with that one channel zeroed, and off<X> runs wt_no<X>/ with nothing raised (its own baseline). YEARS default 20, TPY 180.
cd "$(dirname "$0")"
arm=$1; seed=$2
dir=runs/${STAGE:-chan_8}
mkdir -p "$dir"
case $arm in
  off) gate=../politics/gate.php; sw=off;;
  step) gate=../politics/gate.php; sw=step;;
  step4) gate=../politics/gate.php; sw=step; STEP_PHASE=4;;
  no*) gate=wt_$arm/var/harness/politics/gate.php; sw=step;;
  off?*) gate=wt_no${arm#off}/var/harness/politics/gate.php; sw=off;;
  *) echo "bad arm $arm" >&2; exit 2;;
esac
STEP_PHASE=${STEP_PHASE:-1} exec ../../../bin/php-slot php -d memory_limit=1G "$gate" "$seed" "$seed" "${YEARS:-20}" "${TPY:-180}" "$sw" "$dir/${arm}_$seed.jsonl"

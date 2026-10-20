#!/bin/bash
# form.sh <arm> <seed>: arms new|head run formation_run.php (200y, TPY default 180) on the working tree or on
# var/harness/politics/head_tree (git worktree at the baseline commit, vendor copied, formation_run_h.php with a relative
# bootstrap); arms pnew|phead run poll_profile.php (SEEDS default 20, YEARS default 400; seed argument ignored); arm
# levers runs lever_means.php for one seed (YEARS default 200).
cd /home/quebeco/Projects/Code/Private/aerie-trading || exit 1
arm=$1; seed=$2; out=${OUT:-var/harness/polls/runs_form}
mkdir -p $out
case $arm in
  new)   exec bin/php-slot php -d memory_limit=3G var/harness/politics/formation_run.php $seed $seed ${YEARS:-200} ${TPY:-180} $out/new_$seed.jsonl ;;
  head)  exec bin/php-slot php -d memory_limit=3G var/harness/politics/head_tree/var/harness/politics/formation_run_h.php $seed $seed ${YEARS:-200} ${TPY:-180} $out/head_$seed.jsonl ;;
  pnew)  bin/php-slot php -d memory_limit=3G var/harness/polls/poll_profile.php ${SEEDS:-20} ${YEARS:-400} > $out/pnew.out ;;
  phead) bin/php-slot php -d memory_limit=3G var/harness/politics/head_tree/var/harness/polls/poll_profile_h.php ${SEEDS:-20} ${YEARS:-400} > $out/phead.out ;;
  levers) rm -f $out/levers_$seed.jsonl; exec bin/php-slot php -d memory_limit=2G var/harness/polls/lever_means.php $seed $seed ${YEARS:-200} $out/levers_$seed.jsonl ;;
  pshard) bin/php-slot php -d memory_limit=3G var/harness/polls/poll_profile_shard.php $seed ${YEARS:-400} > $out/pshard_$seed.json ;;
  *) echo "unknown arm $arm"; exit 2 ;;
esac

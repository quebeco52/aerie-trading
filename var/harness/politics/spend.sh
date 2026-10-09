#!/bin/bash
# spend.sh <arm: newon|newoff|headon|headoff> <seed>: one gate.php seed into runs/${STAGE:-spend_8}/{new,head}/{on,off}_<seed>.jsonl.
# head* arms run head_tree/ (a git worktree of the baseline commit with its own vendor; its gate.php must require its own bootstrap).
# YEARS (default 100) and TPY (default 180) from the environment.
cd "$(dirname "$0")"
arm=$1; seed=$2
case $arm in head*) tree=head; sw=${arm#head};; new*) tree=new; sw=${arm#new};; *) echo "bad arm $arm" >&2; exit 2;; esac
dir=runs/${STAGE:-spend_8}/$tree
mkdir -p "$dir"
if [ "$tree" = head ]; then gate=head_tree/var/harness/politics/gate.php; else gate=gate.php; fi
exec ../../../bin/php-slot php -d memory_limit=1G "$gate" "$seed" "$seed" "${YEARS:-100}" "${TPY:-180}" "$sw" "$dir/${sw}_$seed.jsonl"

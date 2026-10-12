#!/usr/bin/env bash
# bisect_tree.sh <label> <commit>: a detached checkout of <commit> at trees/<label> with this tree's vendor copied in
# (a symlinked vendor would run this tree's src). Remove with: git worktree remove --force var/harness/pressure/trees/<label>
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
git worktree add --detach "$here/trees/$1" "$2" > /dev/null
cp -a "$here/../../../vendor" "$here/trees/$1/"
echo "trees/$1 at $(git -C "$here/trees/$1" log --oneline -1)"

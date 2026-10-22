#!/bin/bash
# mk_trees_s.sh: Batch T tree (git archive HEAD + one scratchpad patch each) with a vendor symlink.
set -eu
PROJ=/home/quebeco/Projects/Code/Private/aerie-trading
P=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/e8ecd736-3f30-4d8c-abb2-4a224684119a/scratchpad/
cd "$PROJ"
for pair in ${PAIRS:-BC:fin123}; do
  X=${pair%%:*}; PATCH=$P/${pair#*:}.patch; D=var/harness/joint/tree-$X
  rm -rf "$D" "var/harness/cache-tree-$X"; mkdir -p "$D"
  git archive HEAD | tar -x -C "$D"
  git apply --directory="$D" "$PATCH"
  ln -s "$PROJ/vendor" "$D/vendor"
  echo "tree-$X: $(git -C "$PROJ" apply --stat --directory="$D" "$PATCH" | tail -1)"
done

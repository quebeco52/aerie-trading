#!/bin/bash
# make_before_tree.sh <dest>: HEAD snapshot (git archive, not a worktree) + main's .env* + a copied vendor (never a
# symlink: a symlinked vendor autoloads MAIN's src) + the ignored loop-fit data. Prints `diff -r src` size.
set -eu
M=/home/quebeco/Projects/Code/Private/aerie-trading
W=$1
rm -rf "$W"
mkdir -p "$W"
cd "$M"
git archive HEAD | tar -x -C "$W"
cp -a .env* "$W/"
cp -a vendor "$W/vendor"
for f in var/harness/policy_fit.json var/harness/asym_fit.json; do [ -f "$f" ] && cp "$f" "$W/$f"; done
rm -rf "$M/var/harness/cache-$(basename "$W")"
echo "src files differing from main: $(diff -rq "$M/src" "$W/src" | wc -l)"

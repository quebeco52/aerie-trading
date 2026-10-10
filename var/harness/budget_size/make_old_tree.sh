#!/bin/bash
# make_old_tree.sh <dest>: HEAD worktree + main's modified/untracked src/ tests/ var/harness/ code, a copied vendor,
# the ignored loop-fit data, and PUBLIC_PURCHASES_TO_GDP put back to 0.21 (the OLD arm).
set -eu
M=/home/quebeco/Projects/Code/Private/aerie-trading
W=$1
cd "$M"
git worktree add --detach "$W" HEAD
{ git ls-files -m -- src tests var/harness; git ls-files --others --exclude-standard -- src tests var/harness; } | sort -u > "$W/.copylist"
while read -r f; do mkdir -p "$W/$(dirname "$f")"; cp -dR "$f" "$W/$f"; done < "$W/.copylist"
rm "$W/.copylist"
cp -dR vendor "$W/vendor"
cp var/harness/policy_fit.json var/harness/asym_fit.json "$W/var/harness/"
[ -f .env.local ] && cp .env.local "$W/" || true
cp .env "$W/.env"
perl -pi -e "s/PUBLIC_PURCHASES_TO_GDP = 0\.15;/PUBLIC_PURCHASES_TO_GDP = 0.21;/" "$W/src/Service/Macro/MacroEngine.php"
rm -rf "$W/var/harness/cache"* "$M/var/harness/cache/test"
diff -r "$M/src" "$W/src" || true

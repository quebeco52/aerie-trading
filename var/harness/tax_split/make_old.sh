#!/bin/bash
# make_old.sh <dir>: OLD arm tree = HEAD worktree + the main tree's modified/untracked src, tests, var/harness code,
# a copied vendor, and patch_old.py applied to CreditFiscalSubsystem.php.
set -eu
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
D=$1
cd "$PROJ"
git worktree add --detach "$D" HEAD
{ git diff --name-only HEAD -- src tests var/harness; git ls-files --others --exclude-standard -- src tests var/harness; } | sort -u |
  while read -r f; do mkdir -p "$D/$(dirname "$f")"; cp "$f" "$D/$f"; done
cp -r vendor "$D/vendor"
cp .env "$D/.env" 2>/dev/null || true
rm -rf "$D/var/harness/cache/test"
python3 var/harness/tax_split/patch_old.py "$D"
diff -rq src "$D/src" || true
php -l "$D/src/Service/Macro/Subsystem/CreditFiscalSubsystem.php"

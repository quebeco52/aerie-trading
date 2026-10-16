#!/bin/bash
# make_base.sh: build the baseline tree (worktree minus its uncommitted src diff) at BASEDIR, vendor symlinked.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
BASEDIR=${BASEDIR:-/tmp/claude-1000/valuation-base}
rm -rf "$BASEDIR"
mkdir -p "$BASEDIR"
rsync -a --exclude=/.git --exclude=/vendor --exclude=/var "$PROJ/" "$BASEDIR/"
mkdir -p "$BASEDIR/var"
ln -s "$PROJ/vendor" "$BASEDIR/vendor"
git -C "$PROJ" diff -- src > "$BASEDIR/src.patch"
cd "$BASEDIR" && patch -R -p1 < src.patch
grep -c sectorHurdleShift "$BASEDIR/src/Service/Math/Valuation.php" || echo "baseline clean: no sectorHurdleShift"

#!/usr/bin/env bash
# Stop: PHPStan on the PHP files changed against HEAD (bin/verify --phpstan), so Claude fixes what it finds before
# reporting. Skips at once when the changed files are as they were at the last clean run. Blocks once per distinct set
# of errors: the same errors a second time let Claude stop, since they may predate the session and cannot loop.
cat > /dev/null

cd "${CLAUDE_PROJECT_DIR:-.}" || exit 0
[ -x bin/verify ] || exit 0
state=var/cache/verify
mkdir -p "$state"

files=$({
    git diff --name-only --diff-filter=d HEAD -- 'src/*.php' 'tests/*.php'
    git ls-files --others --exclude-standard -- 'src/*.php' 'tests/*.php'
} | sort -u)
[ -z "$files" ] && exit 0

stamp=$(printf '%s\n' "$files" | xargs sha1sum | sha1sum | cut -c1-40)
[ "$(cat "$state/stop-clean" 2>/dev/null)" = "$stamp" ] && exit 0

if out=$(bin/verify --phpstan 2>&1); then
    echo "$stamp" > "$state/stop-clean"
    exit 0
fi

errors=$(printf '%s' "$out" | sha1sum | cut -c1-40)
[ "$(cat "$state/stop-blocked" 2>/dev/null)" = "$errors" ] && exit 0
echo "$errors" > "$state/stop-blocked"

{
    echo "PHPStan found errors in the PHP files changed against HEAD. Fix them before finishing, or say why they stay:"
    echo "$out"
} >&2
exit 2

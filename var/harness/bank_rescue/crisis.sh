#!/bin/bash
# crisis.sh new|off <seed>: bare-macro crisis probe (YEARS default 100, TPY 252). Output runs_macro/<arm>-<seed>.jsonl
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
cd "$PROJ" && exec "$PROJ/bin/php-slot" php -d memory_limit=3G "$H/crisis_probe.php" "$1" "$2" "${YEARS:-100}" "${TPY:-252}"

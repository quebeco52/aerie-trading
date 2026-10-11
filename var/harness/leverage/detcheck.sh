#!/bin/bash
# detcheck.sh <arm> <seed> [years]: run the arm twice for a few years and diff the outputs, ignoring timing fields.
set -u
H=$(cd "$(dirname "$0")" && pwd)
D="$H/runs/detcheck"
mkdir -p "$D"
for rep in a b; do
  YEARS="${3:-3}" "$H/run.sh" "$1" "$2" || { echo "run $rep failed"; tail -3 "$H/runs/$1-$2.log"; exit 1; }
  mv "$H/runs/$1-$2.jsonl" "$D/$rep.jsonl"; mv "$H/runs/$1-$2.open.json" "$D/$rep.open.json"; mv "$H/runs/$1-$2.log" "$D/$rep.log"
done
python3 - "$D" <<'PY'
import json, sys, re
d = sys.argv[1]
TIMING = re.compile(r'(time|elapsed|sec|ms|wall|dur|mem)', re.I)
def load(p):
    out = []
    for line in open(p):
        line = line.strip()
        if not line: continue
        r = json.loads(line)
        if isinstance(r, dict):
            r = {k: v for k, v in r.items() if not TIMING.search(k)}
        out.append(r)
    return out
a, b = load(f"{d}/a.jsonl"), load(f"{d}/b.jsonl")
diff = sum(1 for x, y in zip(a, b) if x != y)
print(f"jsonl rows a={len(a)} b={len(b)} differing={diff}")
if diff:
    for x, y in zip(a, b):
        if x != y:
            ks = [k for k in x if x.get(k) != y.get(k)] if isinstance(x, dict) else ['?']
            print("first differing keys:", ks[:10]); break
oa = json.load(open(f"{d}/a.open.json")); ob = json.load(open(f"{d}/b.open.json"))
print("open.json identical:", oa == ob)
PY

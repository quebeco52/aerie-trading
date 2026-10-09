"""New-tree lever stats for the consolidation mix + Council line. python3 mix_an.py runs/<stage> [burn years]
Reads runs/<stage>/new/on_<seed>.jsonl: distribution of the Council's debt brake line, mean purchases share and cts."""
import json, math, sys, glob, collections
D = sys.argv[1]; BURN = float(sys.argv[2]) if len(sys.argv) > 2 else 10.0
rows, per = [], collections.defaultdict(list)
for f in sorted(glob.glob(f'{D}/new/on_*.jsonl')):
    for l in open(f):
        r = json.loads(l)
        if r['t'] > BURN: rows.append(r); per[r['s']].append(r)
def mean(x): return sum(x) / len(x)
def sd(x):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / len(x))
def seed_stat(k):
    v = [mean([r[k] for r in per[s]]) for s in per]
    return f'mean {mean(v):.4f} se {sd(v) / math.sqrt(len(v)):.4f} range {min(v):.4f}-{max(v):.4f} n {len(v)}'
c = collections.Counter(round(r['line'], 4) for r in rows)
print(f'NEW-on, {len(per)} seeds, burn {BURN}y')
print('Council line share of quarters: ' + '  '.join(f'{k:.3f} {100 * v / len(rows):.1f}%' for k, v in sorted(c.items())))
print(f'Council line: {seed_stat("line")}')
print(f'purchases share (spend): {seed_stat("spend")}')
print(f'consolidation tax share (cts): {seed_stat("cts")}  sd(quarters) {sd([r["cts"] for r in rows]):.4f}')

#!/usr/bin/env python3
"""ins_path.py <seeds> <arm> [<arm> ...]: DOVE/SAFE trailing-4q ROE at years 2/5/10/20 and float (cdep) over the
year's revenue (sum of the 4 quarters ending there; year 0 uses the first 4) at years 0/5/10/20, mean (se) over seeds.
Then one row per arm and seed for TIER: trailing ROE, interest cover (icr), ND/E, rating at years 2/5/10/15/20, the
first quarter with ROE < 0, rating at or below CCC, and whether the run lists TIER in defaults/reorgs/dead."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:]


def ms(x, f):
    x = [v for v in x if v is not None]
    if not x:
        return 'n/a'
    e = st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan')
    return f.format(st.mean(x)) + ' (' + f.format(e) + ')'


def idx(q, y):
    return min(range(len(q)), key=lambda i: abs(q[i]['t'] - y))


def roe_at(q, i):
    if i < 3:
        return None
    eq = st.mean(r['eq'] for r in q[i - 3:i + 1])
    return sum(r['ni'] for r in q[i - 3:i + 1]) / eq if eq > 0 else None


def frev(q, i):
    w = q[0:4] if i < 4 else q[i - 3:i + 1]
    rv = sum(r['rev'] for r in w)
    return q[i]['cdep'] / rv if rv > 0 else None


D = {a: {s: json.loads(open(f"{H}/runs/{a}-{s}.jsonl").readline()) for s in seeds} for a in arms}
print(f"seeds {sys.argv[1]}, arms {' | '.join(arms)} (unpaired, mean (se) over seeds)")
for t in ('DOVE', 'SAFE'):
    print(f"\n{t}")
    for lab, ys, fn, f in (('ROE ttm y', (2, 5, 10, 20), roe_at, '{:.3f}'), ('float/rev y', (0, 5, 10, 20), frev, '{:.2f}')):
        for y in ys:
            v = []
            for a in arms:
                x = []
                for s in seeds:
                    q = D[a][s]['final'].get(t, {}).get('q', [])
                    if q:
                        x.append(fn(q, idx(q, y)))
                v.append(ms(x, f))
            print(f"  {lab + str(y):14s} " + ' | '.join(f"{c:>16s}" for c in v))

print("\nTIER by seed: ROE / icr / ndE / rating at y2,5,10,15,20; first ROE<0 year; first CCC-or-worse year; flags")
for a in arms:
    for s in seeds:
        d = D[a][s]
        q = d['final'].get('TIER', {}).get('q', [])
        if not q:
            print(f"  {a} s{s}: no rows")
            continue
        cells = []
        for y in (2, 5, 10, 15, 20):
            i = idx(q, y)
            r = roe_at(q, i)
            cells.append(f"{(r if r is not None else float('nan')):.3f}/{q[i].get('icr', 0):.1f}/{q[i].get('ndE', 0):.1f}/{q[i].get('rating')}")
        neg = next((q[i]['t'] for i in range(4, len(q)) if (roe_at(q, i) or 0) < 0), None)
        ccc = next((r['t'] for r in q if str(r.get('rating', '')).startswith(('CCC', 'CC', 'C', 'D'))), None)
        fl = [k for k in ('defaults', 'reorgs', 'dead') if 'TIER' in json.dumps(d.get(k, []))]
        print(f"  {a} s{s}: " + ' '.join(cells) + f" | neg {neg} | ccc {ccc} | {','.join(fl) or '-'}")

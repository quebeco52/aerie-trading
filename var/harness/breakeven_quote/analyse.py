#!/usr/bin/env python3
"""Quote vs expectation breakeven, per seed and pooled. analyse.py [glob]. Rates in pp; first 2 years dropped as burn-in."""
import glob, json, math, sys
from statistics import mean, pstdev

pat = sys.argv[1] if len(sys.argv) > 1 else 'var/harness/breakeven_quote/runs/cur_*.jsonl'
BURN = 2.0

def corr(a, b):
    ma, mb = mean(a), mean(b)
    sa = math.sqrt(sum((x - ma) ** 2 for x in a)); sb = math.sqrt(sum((y - mb) ** 2 for y in b))
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / (sa * sb) if sa and sb else float('nan')

def stats(rows):
    out = {}
    d = lambda f, g=lambda v: v: [g(rows[i][f]) - g(rows[i - 1][f]) for i in range(1, len(rows))]
    hy, ig, en = d('highYieldCreditSpread'), d('macroCreditSpread'), d('energyPriceIndex', math.log)
    for k, f in (('quote', 'tipsBreakevenQuoted'), ('expect', 'tipsBreakeven')):
        lv = [r[f] * 100 for r in rows]; ch = [x * 100 for x in d(f)]
        out[k] = (pstdev(lv), pstdev(ch), min(lv), max(lv), corr(ch, hy), corr(ch, ig), corr(ch, en))
    out['diff'] = mean((r['tipsBreakevenQuoted'] - r['tipsBreakeven']) * 100 for r in rows)
    out['ebp'] = (mean(r['excessBondPremium'] * 100 for r in rows), pstdev([r['excessBondPremium'] * 100 for r in rows]))
    return out

runs = {}
for fn in sorted(glob.glob(pat)):
    rows = [json.loads(l) for l in open(fn)]
    runs[rows[0]['seed']] = [r for r in rows if r['t'] > BURN]

print(f"{'seed':>6} {'leg':>6} {'sd lvl':>7} {'sd dq':>7} {'min':>6} {'max':>6} {'c HY':>6} {'c IG':>6} {'c lnE':>6} | {'q-e mean':>8} {'EBP mean/sd':>12}")
per = {}
for s, rows in runs.items():
    st = stats(rows); per[s] = st
    for k in ('quote', 'expect'):
        v = st[k]
        tail = f" | {st['diff']:8.3f} {st['ebp'][0]:5.2f}/{st['ebp'][1]:5.2f}" if k == 'quote' else ''
        print(f"{s:>6} {k:>6} {v[0]:7.3f} {v[1]:7.3f} {v[2]:6.2f} {v[3]:6.2f} {v[4]:6.2f} {v[5]:6.2f} {v[6]:6.2f}{tail}")
n = len(per)
for k in ('quote', 'expect'):
    v = [mean(per[s][k][j] for s in per) for j in range(7)]
    v[2] = min(per[s][k][2] for s in per); v[3] = max(per[s][k][3] for s in per)
    tail = f" | {mean(per[s]['diff'] for s in per):8.3f}" if k == 'quote' else ''
    print(f"{'mean' + str(n):>6} {k:>6} {v[0]:7.3f} {v[1]:7.3f} {v[2]:6.2f} {v[3]:6.2f} {v[4]:6.2f} {v[5]:6.2f} {v[6]:6.2f}{tail}")

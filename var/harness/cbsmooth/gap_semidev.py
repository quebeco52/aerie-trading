#!/usr/bin/env python3
"""Mechanism check for a gap-sd shift: per-seed downside / upside semi-deviation of the output gap (around 0) and
the gap's 5th/95th percentiles, paired K1-K0. python3 gap_semidev.py [K0 K1]. Drops year 1."""
import json, math, glob, os, sys
H = os.path.dirname(os.path.abspath(__file__))
a0, a1 = (sys.argv[1:3] if len(sys.argv) > 2 else ['K0', 'K1'])
def stats(path):
    g = [json.loads(l)['gap'] for l in open(path) if l.strip() and json.loads(l)['t'] > 1.0 + 1e-9]
    n = len(g); s = sorted(g)
    return {'down_sd%': 100 * math.sqrt(sum(min(x, 0) ** 2 for x in g) / n),
            'up_sd%': 100 * math.sqrt(sum(max(x, 0) ** 2 for x in g) / n),
            'p05%': 100 * s[int(0.05 * n)], 'p95%': 100 * s[int(0.95 * n)]}
def load(a):
    return {int(os.path.basename(f).split('.')[0].split('-')[1]): stats(f) for f in glob.glob(f'{H}/runs/{a}-*.macro.jsonl')}
d0, d1 = load(a0), load(a1)
seeds = sorted(set(d0) & set(d1))
print(f'n={len(seeds)}')
for k in ['down_sd%', 'up_sd%', 'p05%', 'p95%']:
    x0 = [d0[s][k] for s in seeds]; x1 = [d1[s][k] for s in seeds]; d = [b - a for a, b in zip(x0, x1)]
    m = sum(d) / len(d); se = math.sqrt(sum((v - m) ** 2 for v in d) / (len(d) - 1) / len(d))
    print(f'{k:<9} {sum(x0)/len(x0):7.3f} {sum(x1)/len(x1):7.3f} {m:+7.3f} ({se:.3f}) t {m/se:+.1f}')

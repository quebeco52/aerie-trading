#!/usr/bin/env python3
"""Call-site bisect of the sector-drift SAM: TIER deaths and TIER ROE (years 10-14) per arm, failures unpaired.
Arms: before (HEAD), after (tree), pen (penalty + marginal return only), led (industry ledger share only),
ma (M&A divestiture gate only), plc (placebo: HEAD with every scale ratio x (1 + 1e-6)). Compares on the seeds every listed arm has. Usage: analyse_bisect.py [runs dir]"""
import json, glob, os, sys, math, statistics as st
D = os.path.dirname(os.path.abspath(__file__)); R = sys.argv[1] if len(sys.argv) > 1 else os.path.join(D, 'runs'); T = 'TIER'
def load(arm):
    out = {}
    for p in glob.glob(os.path.join(R, f'{arm}-*.jsonl')):
        line = open(p).readline()
        if line: r = json.loads(line); out[r['seed']] = r
    return out
def hyp(k, n1, n2, K): return math.comb(n1, k) * math.comb(n2, K - k) / math.comb(n1 + n2, K)
def fisher2(a, n1, b, n2):
    K = a + b; p0 = hyp(a, n1, n2, K)
    return min(1.0, sum(hyp(k, n1, n2, K) for k in range(max(0, K - n2), min(n1, K) + 1) if hyp(k, n1, n2, K) <= p0 * (1 + 1e-9)))
def roe(r, lo=10, hi=14):
    q = [x for x in r['final'][T]['q'] if lo <= x['t'] < hi and x['eq'] > 0]
    return sum(x['ni'] for x in q) / (hi - lo) / st.mean(x['eq'] for x in q) if q else None
arms = {a: load(a) for a in ('before', 'after', 'pen', 'led', 'ma', 'plc')}
seeds = sorted(set.intersection(*[set(v) for v in arms.values() if v]))
print(f'common seeds n={len(seeds)}')
b = sum(1 for s in seeds if T in arms['before'][s]['dead'])
for a, RR in arms.items():
    if not RR: continue
    d = sum(1 for s in seeds if T in RR[s]['dead']); o = sum(1 for s in seeds if any(t != T for t in RR[s]['dead']) or RR[s]['reorgs'])
    rs = [x for x in (roe(RR[s]) for s in seeds) if x is not None]
    print(f'{a:7s} TIER dead {d:2d}/{len(seeds)} (vs before Fisher2 {fisher2(d, len(seeds), b, len(seeds)):.2g})  other-failure runs {o:2d}  '
          f'TIER ROE y10-14 {st.mean(rs):.3f} se {st.stdev(rs) / math.sqrt(len(rs)):.3f}')

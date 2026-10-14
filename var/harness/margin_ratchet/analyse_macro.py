#!/usr/bin/env python3
"""Macro readings TIER's PE physics takes (read off TIER's own quarterly rows), before vs after, paired by seed,
in 2-year bins: output gap EMA, HY spread EMA, VIX EMA, ERP, deal-activity deviation, SLOOS EMA, policy rate EMA,
and TIER revenue/nominal GDP and ROE. Usage: analyse_macro.py [runs dir] [armA armB]"""
import json, glob, os, sys, math, statistics as st
D = os.path.dirname(os.path.abspath(__file__)); R = sys.argv[1] if len(sys.argv) > 1 else os.path.join(D, 'macro16', 'runs')
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('before', 'after'); T = 'TIER'
F = ['gap', 'hy', 'vix', 'erp', 'deal', 'sloos', 'prate']
def load(a): return {json.loads(open(p).readline())['seed']: json.loads(open(p).readline()) for p in glob.glob(os.path.join(R, f'{a}-*.jsonl'))}
ra, rb = load(A), load(B); seeds = sorted(set(ra) & set(rb))
def binv(r, lo, hi, k):
    q = [x for x in r['final'][T]['q'] if lo <= x['t'] < hi]
    if not q: return None
    if k == 'rev': return sum(x['rev'] / x['ngdp'] for x in q) / len(q) / 1e9
    if k == 'roe': return sum(x['ni'] for x in q) / len(q) * 4 / st.mean(x['eq'] for x in q) if all(x['eq'] > 0 for x in q) else None
    return st.mean(x[k] for x in q)
print(f'n={len(seeds)} {A} vs {B}; cells: {B} - {A} paired mean (t)')
print('bin    ' + ''.join(f'{k:>16s}' for k in F + ['rev', 'roe']))
for lo in range(0, 20, 2):
    row = f'y{lo:2d}-{lo + 2:<2d} '
    for k in F + ['rev', 'roe']:
        d = [binv(rb[s], lo, lo + 2, k) - binv(ra[s], lo, lo + 2, k) for s in seeds if binv(rb[s], lo, lo + 2, k) is not None and binv(ra[s], lo, lo + 2, k) is not None]
        if len(d) < 3: row += f'{"-":>16s}'; continue
        m = st.mean(d); se = st.stdev(d) / math.sqrt(len(d))
        row += f'{m:+10.4f}({m / se if se else 0:+4.1f})'
    print(row)

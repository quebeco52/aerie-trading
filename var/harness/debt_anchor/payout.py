#!/usr/bin/env python3
"""payout.py <a-b>: split the fund's payout per unit of fund, per arm, paired by seed.

Per seed: draw/fund and stabilisation/fund (%/y), stabilisation/GDP (pp/y), draw share (law and applied), share of
quarters at each draw-share value, share of quarters with gross debt above the Council line (or 0.90 if the rows lack it).
"""
import json, math, os, statistics as st, sys
from collections import Counter

H = os.path.dirname(os.path.abspath(__file__))
a, b = (sys.argv[1] if len(sys.argv) > 1 else '1-16').split('-')
seeds = list(range(int(a), int(b) + 1))
ARMS = ('before', 'after')


def per_seed(arm, s):
    R = [r for r in map(json.loads, open(f'{H}/runs/{arm}-{s}.macro.jsonl')) if r['t'] >= 0.2]
    d, sf, sg = [], [], []
    for p, r in zip(R, R[1:]):
        if p['fund'] > 0:
            d.append(400 * r['f_draw'] / p['fund'])
            sf.append(400 * r['f_stab'] / p['fund'])
        sg.append(400 * r['f_stab'])
    out = dict(draw_fund=st.mean(d), stab_fund=st.mean(sf), stab_gdp=st.mean(sg),
               above090=100 * st.mean(r['debt'] > 0.90 for r in R))
    if R[0].get('council_line') is not None:
        out.update(draw_law=st.mean(r['draw_law'] for r in R), draw_share=st.mean(r['draw_share'] for r in R),
                   above_line=100 * st.mean(r['debt'] > r['council_line'] for r in R),
                   line=st.mean(r['council_line'] for r in R))
        out['_dist'] = Counter(round(r['draw_share'], 2) for r in R)
    return out


S = {arm: {s: per_seed(arm, s) for s in seeds} for arm in ARMS}
print(f'fund payout split, seeds {a}-{b}, 100y, 360 tpy')
print(f"{'quantity':<14}{'unit':>7}{'before':>9}{'after':>9}{'diff':>9}{'se':>8}{'t':>7}{'n':>4}")
units = dict(draw_fund='%/y', stab_fund='%/y', stab_gdp='pp/y', above090='% q', draw_law='share', draw_share='share',
             above_line='% q', line='x GDP')
for k in units:
    ss = [s for s in seeds if k in S['before'][s] and k in S['after'][s]]
    if not ss:
        continue
    dd = [S['after'][s][k] - S['before'][s][k] for s in ss]
    se = st.stdev(dd) / math.sqrt(len(dd)) if len(dd) > 1 else float('nan')
    md = st.mean(dd)
    print(f"{k:<14}{units[k]:>7}{st.mean(S['before'][s][k] for s in ss):>9.3f}{st.mean(S['after'][s][k] for s in ss):>9.3f}"
          f"{md:>+9.3f}{se:>8.3f}{(md / se if se > 0 else float('nan')):>+7.2f}{len(ss):>4}")
for arm in ARMS:
    c = Counter()
    for s in seeds:
        c.update(S[arm][s].get('_dist', {}))
    n = sum(c.values())
    if n:
        print(f"applied draw share, {arm}: " + ', '.join(f"{v:.2f}: {100 * k / n:.1f}%" for v, k in sorted(c.items())) + f" (n={n} q)")

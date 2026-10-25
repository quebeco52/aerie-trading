#!/usr/bin/env python3
"""analyse_clamp.py [seeds] [arm]: is perpetualGrowthRate's clamp behind the exact-zero gap elasticities?

Rows (idle.json, PGR arm): 9 x_fv at the filed gap, 11 at gap 0, 12 at gap + 0.1, 13-14 = the perpetualGrowthRate call
with the largest input while striking fair value at the filed gap: its input and struck output. Classes: 6% cap
(input > 0.06 and output = 0.06), spread cap (output < min(input, 0.06): held 50bp under the cost of equity),
unclamped. Years 10-20, utilities/REITs/investment companies reported apart.
"""
import json, math, os, sys
from collections import Counter

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16').split(',')]
arm = sys.argv[2] if len(sys.argv) > 2 else 'prior'
c = Counter()
for s in seeds:
    for r in json.load(open(f'{H}/runs/{arm}-{s}-oc.idle.json'))['rows']:
        if len(r) < 15 or r[0] < 10 or r[13] is None or not r[9] or not r[12] or min(r[9], r[12]) <= 0:
            continue
        e = math.log(r[12] / r[9]) / 0.1
        el = 'zero' if abs(e) < 1e-6 else 'negative' if e < 0 else 'small (<0.05)' if e < 0.05 else 'positive'
        grp = 'util/REIT/inv' if r[2].startswith(('REIT', 'Utilities', 'Investment')) else 'other'
        i, o = r[13], r[14]
        cl = '6% cap' if i > 0.06 + 1e-12 and abs(o - 0.06) < 1e-12 else 'spread cap' if o < min(i, 0.06) - 1e-12 else 'unclamped'
        c[(grp, el, cl)] += 1
print('group | elasticity | clamp | firm-quarters | share of the elasticity class')
tot = Counter()
for (g, e, cl), n in c.items():
    tot[(g, e)] += n
for (g, e, cl), n in sorted(c.items()):
    print(f'{g} | {e} | {cl} | {n} | {n / tot[(g, e)]:.2f}')

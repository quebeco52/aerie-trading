#!/usr/bin/env python3
"""SKYL paid payout (dividends / net income over the year) at y13, y15, y20 per seed, and the paired fix-live
difference in real/designed at y20. Usage: payout.py fix live."""
import json, statistics as st, sys, math
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
import per_seed as ps

P = Path(__file__).parent
a, b = (sys.argv[1:] + ['fix', 'live'])[:2]
for s in range(1, 9):
    q = json.load(open(P / 'runs' / f'{a}-s{s}.json'))['q']['SKYL']
    cells = []
    for y in (13, 15, 20):
        rows = q[4 * (y - 1):4 * y]
        ni = sum(r['ni'] for r in rows)
        cells.append(f"y{y} {sum(r['div'] for r in rows) / ni:.2f}" if ni > 0 else f'y{y} -')
    print(f'seed {s} paid payout ' + ' '.join(cells))
ra = {s: ps.seed_row(a, s)['real/des y20'] for s in range(1, 9)}
rb = {s: ps.seed_row(b, s)['real/des y20'] for s in range(1, 9)}
print(f'real/designed y20 {a} - {b}: ' + fmt(paired(rb, ra)))

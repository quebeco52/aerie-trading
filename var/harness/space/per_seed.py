#!/usr/bin/env python3
"""SKYL per seed: first dividend year, payout at y15/y20, launch failures and their years, margin in grounded quarters,
worst rating, real/designed at y20; with a second arm, the same seeds side by side. Usage: per_seed.py fix [live]."""
import json, math, statistics as st, sys
from pathlib import Path

P = Path(__file__).parent
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-',
         'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']


def faded(e, t, h):
    return e * h / math.log(2) * (1 - 2 ** (-t / h))


def bass(t, p=0.03, q=0.38):
    if t <= 0:
        return 0.0
    x = math.exp(-(p + q) * t)
    return (1 - x) / (1 + (q / p) * x)


def designed(t):
    lv = lambda u: 0.7 * math.exp(faded(0.06, u, 6.0)) + 0.3 + 2.0 * bass(u - 4)
    return math.exp(0.02 * t) * lv(t) / lv(0)


def seed_row(arm, s):
    f = P / 'runs' / f'{arm}-s{s}.json'
    if not f.exists():
        return None
    d = json.load(open(f))
    q, o = d['q']['SKYL'], d['opening']['SKYL']
    rows = q[-4:]
    real = sum(r['rev'] for r in rows) / st.fmean(r['cpi'] for r in rows) * q[0]['cpi'] / o['rev']
    divq = [i for i, r in enumerate(q) if r['div'] > 0]
    grd = [r['regime'].get('state:regime:fleet_grounded', 0) for r in q]
    on = [round(q[i]['t'], 1) for i in range(len(q)) if grd[i] == 1]
    gm = [q[i]['om'] for i in range(len(q)) if grd[i] > 0]
    return {
        'real/des y20': real / designed(19.5),
        'first div yr': round((divq[0] + 1) / 4, 2) if divq else None,
        'payout y15': q[59]['diag']['payout'], 'payout y20': q[-1]['diag']['payout'],
        'failures': len(on), 'failure years': on,
        'om grounded': st.fmean(gm) if gm else None,
        'worst': max((r['rating'] for r in q if r['rating']), key=ORDER.index),
        'loss q': sum(1 for r in q if r['ebit'] < 0),
    }


arms = sys.argv[1:] or ['fix']
print('seed  ' + '  |  '.join(arms))
for s in range(1, 9):
    cells = []
    for a in arms:
        r = seed_row(a, s)
        if r is None:
            cells.append('-')
            continue
        omg = f"{r['om grounded'] * 100:.0f}%" if r['om grounded'] is not None else '-'
        cells.append(f"real/des {r['real/des y20']:.2f}, worst {r['worst']}, loss q {r['loss q']}, first div {r['first div yr']}, "
                     f"payout y15 {r['payout y15']:.2f} y20 {r['payout y20']:.2f}, failures {r['failures']} at {r['failure years']}, om grounded {omg}")
    print(f'{s:4d}  ' + '  |  '.join(cells))

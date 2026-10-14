#!/usr/bin/env python3
"""oil_firms.py <lo-hi> [armA armB]: energy-exposed firms, paired B - A by seed.

Per firm: EBIT margin y2-30 (sum ebit / sum rev) and corr(yoy dlog revenue, yoy change in gdg) after year 2
(same definition as analyse.py's metals rows). Firms picked by the `industry` field of the final rows.
"""
import json, math, sys, os

H = os.path.dirname(os.path.abspath(__file__))
lo, hi = map(int, sys.argv[1].split('-'))
A, B = (sys.argv[2:4] if len(sys.argv) > 3 else ['n', 'b'])
SEEDS = list(range(lo, hi + 1))
ENERGY = ('Oil & Gas', 'Utilities', 'Marine Shipping', 'Integrated Freight', 'Railroads', 'Chemicals', 'Airlines',
          'Trucking')


def load(arm, seed):
    with open(f'{H}/runs/{arm}-{seed}.jsonl') as f:
        return json.loads(f.readline())


def mean(x):
    return sum(x) / len(x) if x else float('nan')


def sd(x):
    m = mean(x)
    return math.sqrt(sum((a - m) ** 2 for a in x) / (len(x) - 1)) if len(x) > 1 else float('nan')


def corr(x, y):
    mx, my = mean(x), mean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / math.sqrt(sxx * syy) if sxx > 0 and syy > 0 else None


def qs(rec, t, a, b):
    f = rec['final'].get(t)
    return [q for q in f.get('q', []) if a < q['t'] <= b] if f else []


def margin(rec, t):
    q = qs(rec, t, 2.0, 30.0)
    r = sum(x['rev'] for x in q)
    return sum(x['ebit'] for x in q) / r if q and r > 0 else None


def rcorr(rec, t):
    q = qs(rec, t, 1.0, 1e9)
    g, d = [], []
    for p, c in zip(q, q[4:]):
        if p['rev'] > 0 and c['rev'] > 0 and c['t'] > 2.0:
            g.append(math.log(c['rev'] / p['rev']))
            d.append(c['gdg'] - p['gdg'])
    return corr(g, d) if len(g) > 8 else None


def paired(fn, t):
    a, b, d = [], [], []
    for s in SEEDS:
        x, y = fn(R[A][s], t), fn(R[B][s], t)
        if x is not None and y is not None:
            a.append(x); b.append(y); d.append(y - x)
    n = len(d)
    se = sd(d) / math.sqrt(n) if n > 1 else float('nan')
    return mean(a), mean(b), mean(d), se, (mean(d) / se if se and se > 0 else float('nan')), n


R = {arm: {s: load(arm, s) for s in SEEDS} for arm in (A, B)}
fin = R[A][SEEDS[0]]['final']
firms = sorted((f['industry'], t) for t, f in fin.items() if f['industry'].startswith(ENERGY))
print(f'Energy-exposed firms, {B} - {A}, seeds {lo}-{hi}')
print(f'{"ticker":6} {"industry":32} | {"EBIT% " + A:>8} {B:>7} {"d(pp)":>7} {"se":>6} {"t":>6} | '
      f'{"corr " + A:>7} {B:>6} {"d":>6} {"se":>6} {"t":>6} {"n":>3}')
for ind, t in firms:
    m = paired(margin, t)
    c = paired(rcorr, t)
    print(f'{t:6} {ind[:32]:32} | {100*m[0]:8.2f} {100*m[1]:7.2f} {100*m[2]:+7.2f} {100*m[3]:6.2f} {m[4]:+6.1f} | '
          f'{c[0]:7.3f} {c[1]:6.3f} {c[2]:+6.3f} {c[3]:6.3f} {c[4]:+6.1f} {c[5]:3d}')

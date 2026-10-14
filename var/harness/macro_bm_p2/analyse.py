#!/usr/bin/env python3
"""analyse.py <lo-hi> [arms a b]: macro -> business-model Part 2 targets, paired b - a by seed.

Per seed a statistic per arm; the table gives mean A, mean B, paired mean difference, its se (sd of differences /
sqrt n) and t. Ratios are sums over the window (capex/rev = sum capex / sum rev). ROIC is the report's (the
engine's truePostTaxReturn), falling back to the stock's currentRoic.
"""
import json, math, sys, os

H = os.path.dirname(os.path.abspath(__file__))
lo, hi = map(int, sys.argv[1].split('-'))
arms = sys.argv[2:] or ['a', 'b']
SEEDS = list(range(lo, hi + 1))
DRIFT = ['LOON', 'HUMM', 'SHRK', 'TICK', 'WATCH', 'OSPR', 'SHOR', 'PLZA', 'ELDE', 'KITE', 'GULL', 'CNDR', 'SINK',
         'BIRD', 'WADE', 'CORM', 'STAR']
METALS = ['CNDR', 'WING']


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
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / math.sqrt(sxx * syy) if sxx > 0 and syy > 0 else float('nan')


def win(rec, t, a, b):
    f = rec['final'].get(t)
    if f is None:
        return []
    return [q for q in f.get('q', []) if a < q['t'] <= b]


def ratio(rec, t, num, a, b):
    q = win(rec, t, a, b)
    r = sum(x['rev'] for x in q)
    return sum(x[num] for x in q) / r if q and r > 0 else None


def roic(q):
    v = q.get('roic')
    return v if v is not None else q.get('croic')


def roic_mean(rec, t, a, b):
    v = [roic(q) for q in win(rec, t, a, b)]
    v = [x for x in v if x is not None]
    return mean(v) if v else None


def creep(rec, t):
    if rec['final'][t]['dead']:
        return None
    late, early = roic_mean(rec, t, 25, 30), roic_mean(rec, t, 3, 6)
    return late - early if late is not None and early is not None else None


def median(x):
    x = sorted(x)
    n = len(x)
    return float('nan') if n == 0 else (x[n // 2] if n % 2 else 0.5 * (x[n // 2 - 1] + x[n // 2]))


def nonfin(rec):
    return [t for t, f in rec['final'].items() if not f['finsec']]


def median_creep(rec):
    return median([c for c in (creep(rec, t) for t in nonfin(rec)) if c is not None])


def yoy(rec, t):
    q = win(rec, t, 1.0, 1e9)
    g, d = [], []
    for p, c in zip(q, q[4:]):
        if p['rev'] > 0 and c['rev'] > 0 and c['t'] > 2.0:
            g.append(math.log(c['rev'] / p['rev']))
            d.append(c['gdg'] - p['gdg'])
    return g, d


def metal_corr(rec, t):
    g, d = yoy(rec, t)
    return corr(g, d) if len(g) > 8 else None


def metal_sd(rec, t):
    g, _ = yoy(rec, t)
    return sd(g) if len(g) > 8 else None


recs = {a: {s: load(a, s) for s in SEEDS} for a in arms}
out = []


def line(label, fn, scale=1.0, fmt='{:+.3f}', flag=None):
    va = {s: fn(recs[arms[0]][s]) for s in SEEDS}
    vb = {s: fn(recs[arms[1]][s]) for s in SEEDS}
    ok = [s for s in SEEDS if va[s] is not None and vb[s] is not None and not math.isnan(va[s]) and not math.isnan(vb[s])]
    a = [va[s] * scale for s in ok]
    b = [vb[s] * scale for s in ok]
    d = [y - x for x, y in zip(a, b)]
    se = sd(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    t = mean(d) / se if se and se > 0 else float('nan')
    mark = ' *' if flag is not None and abs(mean(d)) > flag else ''
    out.append(f'{label:<40} {fmt.format(mean(a)):>9} {fmt.format(mean(b)):>9} {fmt.format(mean(d)):>9} {fmt.format(se):>8} {t:>+7.1f} {len(d):>3}{mark}')


def header(title):
    out.append('')
    out.append(f'== {title}')
    out.append(f'{"quantity":<40} {"A":>9} {"B":>9} {"B-A":>9} {"se":>8} {"t":>7} {"n":>3}')


header('1. LOON (pp), years 20-30')
line('LOON capex/revenue', lambda r: ratio(r, 'LOON', 'capex', 20, 30), 100, '{:+.2f}')
line('LOON EBIT margin', lambda r: ratio(r, 'LOON', 'ebit', 20, 30), 100, '{:+.2f}')

header('2. ROIC creep (pp): median non-fin of mean(y25-30) - mean(y3-6)')
line('median ROIC creep', median_creep, 100, '{:+.2f}')
line('median ROIC y3-6', lambda r: median([x for x in (roic_mean(r, t, 3, 6) for t in nonfin(r)) if x is not None]), 100, '{:+.2f}')
line('median ROIC y25-30', lambda r: median([x for x in (roic_mean(r, t, 25, 30) for t in nonfin(r) if not r['final'][t]['dead']) if x is not None]), 100, '{:+.2f}')

header('3. Metals: yoy dlog revenue vs yoy change in globalDemandGapEma')
for t in METALS:
    line(f'{t} corr', lambda r, t=t: metal_corr(r, t))
    line(f'{t} sd yoy dlog rev', lambda r, t=t: metal_sd(r, t))

header('Drift firms: ROIC creep (pp)')
for t in DRIFT:
    line(f'{t} ROIC creep', lambda r, t=t: creep(r, t), 100, '{:+.2f}')
header('Drift firms: capex/revenue, years 2-30 (pp)')
for t in DRIFT:
    line(f'{t} capex/rev', lambda r, t=t: ratio(r, t, 'capex', 2, 30), 100, '{:+.2f}')

header('Failures per seed')
line('deaths', lambda r: float(len(r['dead'])), 1, '{:+.2f}')
line('default episodes', lambda r: float(sum(r['defaults'].values()) if isinstance(r['defaults'], dict) else 0), 1, '{:+.2f}')
line('reorgs', lambda r: float(len(r['reorgs'])), 1, '{:+.2f}')

if 'b' in arms:
    header('B: ppe relative price at year 30 (A has none)')
    for t in ['LOON', 'SHOR']:
        v = []
        for s in SEEDS:
            q = [x for x in recs['b'][s]['final'][t]['q'] if x.get('ppr') is not None]
            if q:
                v.append(q[-1]['ppr'])
        out.append(f'{t + " ppr":<40} {"":>9} {mean(v):>9.3f} {"":>9} {sd(v) / math.sqrt(len(v)) if len(v) > 1 else float("nan"):>8.3f} {"":>7} {len(v):>3}')

header('Non-fin EBIT margin, years 2-30 (pp), * = |B-A| > 1pp')
for t in sorted(nonfin(recs[arms[0]][SEEDS[0]])):
    line(f'{t} EBIT margin', lambda r, t=t: ratio(r, t, 'ebit', 2, 30), 100, '{:+.2f}', flag=1.0)

print('\n'.join(out))

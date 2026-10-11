#!/usr/bin/env python3
"""Paired moments (arms share one random stream, which the board's state-dependent draws decouple within quarters, so
pairing removes little variance) for the long-end question: python3 y10_analyse.py [base arm Y0] [other arms ...]. Drops year 1.
Targets: 10Y ACF4, 10Y term premium ACF4, 10Y breakeven sd. Guards: gap ACF4 and sd, policy ACF4, inflation mean and
sd, 10Y mean, real 10Y mean (10Y less breakeven), IG spread mean; a run that ends short or non-finite counts as blown up."""
import json, math, glob, os, sys
H = os.path.dirname(os.path.abspath(__file__))
YEARS = float(os.environ.get('YEARS', 21))

def acf(x, k):
    n = len(x); m = sum(x) / n; v = sum((a - m) ** 2 for a in x)
    return sum((x[i] - m) * (x[i + k] - m) for i in range(n - k)) / v if v > 0 else float('nan')
def sd(x):
    m = sum(x) / len(x); return math.sqrt(sum((a - m) ** 2 for a in x) / (len(x) - 1))
def mean(x): return sum(x) / len(x)

KEYS = ['y10_acf4', 'tp10_acf4', 'be10_sd%', 'be10_acf4', 'rn10_acf4', 'gap_acf4', 'gap_sd%', 'pol_acf4', 'infl_mean%', 'infl_sd%',
        'y10_mean%', 'real10_mean%', 'ig_mean%', 'be10_mean%', 'y10_sd%', 'tp10_sd%', 'crisis', 'gap_min%']
def moments(path):
    rows = [json.loads(l) for l in open(path) if l.strip()]
    if not rows or rows[-1]['t'] < YEARS - 0.3:
        return None
    vals = [v for r in rows for v in (r['y10'], r['gap'], r['infl']) ]
    if any(not math.isfinite(v) for v in vals):
        return None
    rows = [r for r in rows if r['t'] > 1.0 + 1e-9]
    c = lambda k: [r[k] for r in rows]
    y, tp, be, rn, g, p, i, ig = c('y10'), c('tp10'), c('be10'), c('rn10'), c('gap'), c('policy'), c('infl'), c('ig')
    return {'y10_acf4': acf(y, 4), 'tp10_acf4': acf(tp, 4), 'be10_sd%': 100 * sd(be), 'be10_acf4': acf(be, 4), 'rn10_acf4': acf(rn, 4),
            'gap_acf4': acf(g, 4), 'gap_sd%': 100 * sd(g), 'pol_acf4': acf(p, 4), 'infl_mean%': 100 * mean(i), 'infl_sd%': 100 * sd(i),
            'y10_mean%': 100 * mean(y), 'real10_mean%': 100 * mean([a - b for a, b in zip(y, be)]), 'ig_mean%': 100 * mean(ig),
            'be10_mean%': 100 * mean(be), 'y10_sd%': 100 * sd(y), 'tp10_sd%': 100 * sd(tp),
            'crisis': 1.0 if any(r['crisis_at'] > 1.0 for r in rows) else 0.0, 'gap_min%': 100 * min(g)}

arms = sys.argv[1:] or ['Y0', 'Y1']
base = arms[0]
data, blown = {}, {}
for a in arms:
    for f in glob.glob(f'{H}/runs/{a}-*.macro.jsonl'):
        s = int(os.path.basename(f).split('.')[0].split('-')[1])
        m = moments(f)
        if m is None:
            blown.setdefault(a, []).append(s)
        else:
            data.setdefault(a, {})[s] = m
seeds = sorted(set.intersection(*(set(data.get(a, {})) for a in arms)))
n = len(seeds)
print(f'n={n} paired seeds; blown up: ' + ', '.join(f'{a} {sorted(blown.get(a, []))}' for a in arms))
print(f"{'quantity':<14}" + ''.join(f'{a:>17}' for a in arms) + ''.join(f'{"d" + a + "-" + base + " (se) t":>28}' for a in arms[1:]))
for k in KEYS:
    line = f'{k:<14}'
    for a in arms:
        xs = [data[a][s][k] for s in seeds]
        line += f'{mean(xs):>9.3f} ({sd(xs) / math.sqrt(n):.3f})'
    for a in arms[1:]:
        d = [data[a][s][k] - data[base][s][k] for s in seeds]
        md, se = mean(d), sd(d) / math.sqrt(n)
        line += f'{md:>+12.3f} ({se:.3f}) {md / se if se > 0 else float("nan"):>+6.1f}'
    print(line)

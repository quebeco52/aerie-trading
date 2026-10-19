"""analyse.py [arms]: round-2 slump moments from runs/<arm>-<seed>.macro.jsonl (default arms A G P1 P2 H2 N1 S1).
Env SEEDS (1-8), BURN (2 years), YEARS (52, completeness check). Quarterly rows with t > BURN.
Prints the pairing check (corr of the quarterly fgap series with the control's, same seed), per arm mean [min, max]
over seeds, and paired differences G - A and <arm> - G: mean (se) t, and the mainland-adjusted figure (intercept of
the paired difference regressed on the paired difference of fgap means: est (se) t)."""
import json, math, os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'cycle_amp'))
from gapstats_lib import mean, sd, acf

H = os.path.dirname(os.path.abspath(__file__))
BURN = float(os.environ.get('BURN', 2))
NAN = float('nan')
ARMS = sys.argv[1:] or ['A', 'G', 'P1', 'P2', 'H2', 'N1', 'S1']


def seeds():
    out = []
    for part in os.environ.get('SEEDS', '1-8').split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def corr(x, y):
    mx, my = mean(x), mean(y)
    sxy = sum((a - mx) * (b - my) for a, b in zip(x, y))
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    return sxy / math.sqrt(sxx * syy) if sxx * syy > 0 else NAN


def runs_below(x, h):
    out, n = [], 0
    for v in x:
        if v < h:
            n += 1
        elif n:
            out.append(n); n = 0
    if n:
        out.append(n)
    return out


TARGETS = [('sh<-2', 'share gap < -2%'), ('longest<-1.5', 'longest run < -1.5% (q)'), ('gap_mean%', 'gap mean %')]
WATCH = [
    ('u-nairu%', 'u - nairu mean (pp)'), ('infl4_mean%', 'infl 4q-avg mean %'), ('infl4_sd%', 'infl 4q-avg sd %'),
    ('sc_sd%', 'supercore sd %'), ('floor', 'policy floor share (<= 0.25%)'), ('rec/dec', 'recession starts / decade'),
    ('crises/cent', 'credit crises / century'), ('acf13', 'gap acf lag 13'), ('c(infl,gap-4)', 'corr(infl, gap 4q earlier)'),
    ('sh>+2', 'share gap > +2%'), ('gap_sd%', 'gap sd %'), ('fgap_mean%', 'fgap mean % (mainland)'),
]
KEYS = TARGETS + WATCH


def load(arm, seed):
    path = f'{H}/runs/{arm}-{seed}.macro.jsonl'
    if not os.path.exists(path):
        return None
    return [r for r in (json.loads(l) for l in open(path)) if r['t'] > 0]


def stats(arm, seed):
    rows = load(arm, seed)
    if rows is None:
        return None, None, f'{arm}-{seed}: missing'
    flag = ''
    if any(not math.isfinite(r['gap']) or not math.isfinite(r['policy']) for r in rows):
        flag = f'{arm}-{seed}: non-finite macro value'
    pre = [r for r in rows if r['t'] <= BURN]
    R = [r for r in rows if r['t'] > BURN]
    yrs = len(R) / 4
    g = [r['gap'] for r in R]
    infl = [r['infl'] for r in R]
    i4 = [mean(infl[i - 3:i + 1]) for i in range(3, len(infl))]
    pol = [r['policy'] for r in R]
    rec = [1 if r['rec'] else 0 for r in R]
    rec0 = 1 if (pre and pre[-1]['rec']) else 0
    starts = sum(1 for i, v in enumerate(rec) if v == 1 and (rec[i - 1] if i else rec0) == 0)
    seen, crises = (pre[-1].get('crisis_at') if pre else None), 0
    for r in R:
        c = r.get('crisis_at')
        if c is not None and c != seen and c > BURN:
            crises += 1
        seen = c
    rb = runs_below(g, -0.015)
    out = {
        'gap_mean%': 100 * mean(g), 'gap_sd%': 100 * sd(g),
        'sh<-2': sum(v < -0.02 for v in g) / len(g), 'sh>+2': sum(v > 0.02 for v in g) / len(g),
        'longest<-1.5': max(rb) if rb else 0,
        'u-nairu%': 100 * mean([r['u'] - r['nairu'] for r in R]),
        'infl4_mean%': 100 * mean(i4), 'infl4_sd%': 100 * sd(i4), 'sc_sd%': 100 * sd([r['supercore'] for r in R]),
        'floor': sum(p <= 0.0025 for p in pol) / len(pol), 'rec/dec': 10 * starts / yrs,
        'crises/cent': 100 * crises / yrs, 'acf13': acf(g, 13), 'c(infl,gap-4)': corr(infl[4:], g[:-4]),
        'fgap_mean%': 100 * mean([r['fgap'] for r in R]),
    }
    if len(R) < 4 * (float(os.environ.get('YEARS', 52)) - BURN) - 2:
        flag = (flag + '; ' if flag else '') + f'{arm}-{seed}: only {len(R)} quarters'
    return out, [r['fgap'] for r in rows], flag


def fmt(v):
    return 'nan' if not math.isfinite(v) else (f'{v:.3f}' if abs(v) < 10 else f'{v:.1f}')


def summary(xs):
    xs = [x for x in xs if math.isfinite(x)]
    return f'{fmt(mean(xs))} [{fmt(min(xs))}, {fmt(max(xs))}]' if xs else 'nan'


def paired(d):
    if len(d) < 2:
        return 'n/a'
    m, se = mean(d), sd(d) / math.sqrt(len(d))
    return f'{fmt(m)} ({fmt(se)}) t={m / se if se > 0 else NAN:+.1f}'


def ancova(d, x):
    """Intercept of d = a + b x (OLS): the paired difference at equal mainland means."""
    n = len(d)
    if n < 4:
        return 'n/a'
    mx, md = mean(x), mean(d)
    sxx = sum((v - mx) ** 2 for v in x)
    if sxx <= 0:
        return 'n/a'
    b = sum((u - mx) * (v - md) for u, v in zip(x, d)) / sxx
    a = md - b * mx
    s2 = sum((v - a - b * u) ** 2 for u, v in zip(x, d)) / (n - 2)
    se = math.sqrt(s2 * (1 / n + mx * mx / sxx))
    return f'{fmt(a)} ({fmt(se)}) t={a / se if se > 0 else NAN:+.1f}'


S = seeds()
data, fg, flags = {}, {}, []
for arm in ARMS:
    for s_ in S:
        st, f, fl = stats(arm, s_)
        if fl:
            flags.append(fl)
        if st:
            data[(arm, s_)], fg[(arm, s_)] = st, f
common = [s for s in S if all((a, s) in data for a in ARMS)]
print(f'BURN {BURN} y; seeds with every arm: {len(common)} of {len(S)} ({",".join(map(str, common))})')
print('flags: ' + ('; '.join(flags) if flags else 'none'))
col = lambda arm, k: [data[(arm, s)][k] for s in common]
PAIRS = ([('G', 'A')] if 'G' in ARMS and 'A' in ARMS else []) + [(a, 'G') for a in ARMS if a not in ('A', 'G') and 'G' in ARMS]

print('\n== pairing: corr of quarterly fgap with the control, same seed (min [arm-seed]) ==')
for a, c in PAIRS:
    cs = [(corr(*[x[:min(len(fg[(a, s)]), len(fg[(c, s)]))] for x in (fg[(a, s)], fg[(c, s)])]), s) for s in common]
    lo = min(cs)
    print(f'  {a} vs {c}: min {lo[0]:.4f} (seed {lo[1]}), mean {mean([v for v, _ in cs]):.4f}')

print('\n== per arm: mean [min, max] over seeds ==')
print('quantity'.ljust(34) + ''.join(a.ljust(25) for a in ARMS))
for k, label in KEYS:
    print(label.ljust(34) + ''.join(summary(col(a, k)).ljust(25) for a in ARMS))

for a, c in PAIRS:
    print(f'\n== {a} - {c}: paired mean (se) t | mainland-adjusted intercept (se) t ==')
    x = [p - q for p, q in zip(col(a, 'fgap_mean%'), col(c, 'fgap_mean%'))]
    for k, label in KEYS:
        d = [p - q for p, q in zip(col(a, k), col(c, k))]
        ok = [i for i, v in enumerate(d) if math.isfinite(v)]
        dd, xx = [d[i] for i in ok], [x[i] for i in ok]
        adj = ancova(dd, xx) if k != 'fgap_mean%' else ''
        print(label.ljust(34) + paired(dd).ljust(30) + adj)

if os.environ.get('PERSEED'):
    for k, label in TARGETS:
        print(f'\n== per seed: {label} ==')
        print('seed'.ljust(6) + ''.join(a.rjust(9) for a in ARMS))
        for s_ in common:
            print(str(s_).ljust(6) + ''.join(fmt(data[(a, s_)][k]).rjust(9) for a in ARMS))

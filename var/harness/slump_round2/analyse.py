"""analyse.py [arms]: slump length/depth moments from runs/<arm>-<seed>.macro.jsonl (default arms A B C D E F).
Env SEEDS (1-8), BURN (2 years). Quarterly rows with t > BURN. Prints per arm mean [min, max] over seeds, the paired
difference vs A (mean, se, t), and F against the best single arm (largest fall in share gap < -2% vs A)."""
import json, math, os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'cycle_amp'))
from gapstats_lib import hp, mean, sd, acf

H = os.path.dirname(os.path.abspath(__file__))
BURN = float(os.environ.get('BURN', 2))
NAN = float('nan')
ARMS = sys.argv[1:] or ['A', 'B', 'C', 'D', 'E', 'F']


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


KEYS = [
    ('gap_mean%', 'gap mean %'), ('gap_sd%', 'gap sd %'), ('sh<-2', 'share gap < -2%'), ('sh>+2', 'share gap > +2%'),
    ('sh<-1sd', 'share gap < -1 own sd'), ('longest<-1.5', 'longest run < -1.5% (q)'),
    ('deep/cent', 'runs >= 8q < -1.5% per century'), ('len>=4q', 'mean length of runs >= 4q < -1.5%'),
    ('acf4', 'gap acf lag 4'), ('acf13', 'gap acf lag 13'),
    ('infl4_mean%', 'infl 4q-avg mean %'), ('infl4_sd%', 'infl 4q-avg sd %'),
    ('sc_mean%', 'supercore mean %'), ('sc_sd%', 'supercore sd %'),
    ('floor', 'policy floor share (<= 0.25%)'), ('pol_sd%', 'policy sd %'),
    ('hp_corr', 'HP cycle corr log Y vs fgap'), ('c(rgq,dfg)', 'corr rgq, d fgap (same q)'),
    ('c(dg,dfg)', 'corr d gap, d fgap (E check)'),
    ('worst_rgq%', 'worst rgq %'), ('rgq<-1/cent', 'quarters rgq < -1% per century'), ('rec/dec', 'recession starts per decade'),
]


def stats(arm, seed):
    path = f'{H}/runs/{arm}-{seed}.macro.jsonl'
    if not os.path.exists(path):
        return None, f'{arm}-{seed}: missing'
    rows = [json.loads(l) for l in open(path)]
    rows = [r for r in rows if r['t'] > 0]
    flag = ''
    if any(not math.isfinite(r['gap']) or not math.isfinite(r['policy']) for r in rows):
        flag = f'{arm}-{seed}: non-finite macro value'
    pre = [r for r in rows if r['t'] <= BURN]
    R = [r for r in rows if r['t'] > BURN]
    yrs = len(R) / 4
    g = [r['gap'] for r in R]
    f = [r['fgap'] for r in R]
    dg = [g[i] - g[i - 1] for i in range(1, len(g))]
    df = [f[i] - f[i - 1] for i in range(1, len(f))]
    q = [r['rgq'] for r in R]
    infl = [r['infl'] for r in R]
    i4 = [mean(infl[i - 3:i + 1]) for i in range(3, len(infl))]
    sc = [r['supercore'] for r in R]
    pol = [r['policy'] for r in R]
    lny = [math.log(r['pot'] * (1 + r['gap'])) for r in R]
    cy = [a - b for a, b in zip(lny, hp(lny))]
    cf = [a - b for a, b in zip(f, hp(f))]
    rec = [1 if r['rec'] else 0 for r in R]
    rec0 = 1 if (pre and pre[-1]['rec']) else 0
    starts = sum(1 for i, v in enumerate(rec) if v == 1 and (rec[i - 1] if i else rec0) == 0)
    s = sd(g)
    rb = runs_below(g, -0.015)
    long4 = [n for n in rb if n >= 4]
    out = {
        'gap_mean%': 100 * mean(g), 'gap_sd%': 100 * s,
        'sh<-2': sum(v < -0.02 for v in g) / len(g), 'sh>+2': sum(v > 0.02 for v in g) / len(g),
        'sh<-1sd': sum(v < -s for v in g) / len(g),
        'longest<-1.5': max(rb) if rb else 0, 'deep/cent': 100 * sum(n >= 8 for n in rb) / yrs,
        'len>=4q': mean(long4) if long4 else NAN,
        'acf4': acf(g, 4), 'acf13': acf(g, 13),
        'infl4_mean%': 100 * mean(i4), 'infl4_sd%': 100 * sd(i4), 'sc_mean%': 100 * mean(sc), 'sc_sd%': 100 * sd(sc),
        'floor': sum(p <= 0.0025 for p in pol) / len(pol), 'pol_sd%': 100 * sd(pol),
        'hp_corr': corr(cy, cf), 'c(rgq,dfg)': corr(q[1:], df), 'c(dg,dfg)': corr(dg, df),
        'worst_rgq%': 100 * min(q), 'rgq<-1/cent': 100 * sum(v < -0.01 for v in q) / yrs, 'rec/dec': 10 * starts / yrs,
    }
    if len(R) < 4 * (float(os.environ.get('YEARS', 52)) - BURN) - 2:
        flag = (flag + '; ' if flag else '') + f'{arm}-{seed}: only {len(R)} quarters'
    return out, flag


def fmt(v):
    return 'nan' if not math.isfinite(v) else (f'{v:.3f}' if abs(v) < 10 else f'{v:.1f}')


def summary(xs):
    xs = [x for x in xs if math.isfinite(x)]
    return f'{fmt(mean(xs))} [{fmt(min(xs))}, {fmt(max(xs))}]' if xs else 'nan'


def paired(a, b):
    d = [y - x for x, y in zip(a, b) if math.isfinite(x) and math.isfinite(y)]
    if len(d) < 2:
        return 'n/a'
    m, se = mean(d), sd(d) / math.sqrt(len(d))
    t = m / se if se > 0 else NAN
    return f'{fmt(m)} ({fmt(se)}) t={t:+.1f}'


S = seeds()
data, flags = {}, []
for arm in ARMS:
    for sd_ in S:
        st, fl = stats(arm, sd_)
        if fl:
            flags.append(fl)
        if st:
            data[(arm, sd_)] = st
common = [s for s in S if all((a, s) in data for a in ARMS)]
print(f'BURN {BURN} y; seeds with every arm: {len(common)} of {len(S)} ({",".join(map(str, common))})')
print('flags: ' + ('; '.join(flags) if flags else 'none'))
col = lambda arm, k: [data[(arm, s)][k] for s in common]

print('\n== per arm: mean [min, max] over seeds ==')
for k, label in KEYS:
    print(f'{label}')
    for arm in ARMS:
        print(f'    {arm}: {summary(col(arm, k))}')

print('\n== paired difference vs A: mean (se) t ==')
print('quantity'.ljust(40) + ''.join(a.ljust(30) for a in ARMS if a != 'A'))
for k, label in KEYS:
    print(label.ljust(40) + ''.join(paired(col('A', k), col(a, k)).ljust(30) for a in ARMS if a != 'A'))

singles = [a for a in ARMS if a not in ('A', 'F')]
if 'F' in ARMS and singles and 'A' in ARMS:
    best = min(singles, key=lambda a: mean(col(a, 'sh<-2')) - mean(col('A', 'sh<-2')))
    print(f'\n== F minus best single arm ({best}, largest fall in share gap < -2%): mean (se) t ==')
    for k, label in KEYS:
        print(label.ljust(40) + paired(col(best, k), col('F', k)))

if 'G' in ARMS and 'H' in ARMS:
    print('\n== G minus H (what E adds on top of B + D): mean (se) t ==')
    for k, label in KEYS:
        print(label.ljust(40) + paired(col('H', k), col('G', k)))
if 'G' in ARMS and 'E' in ARMS:
    print('\n== G minus E (what B + D add on top of E): mean (se) t ==')
    for k, label in KEYS:
        print(label.ljust(40) + paired(col('E', k), col('G', k)))
PER = [k for k in ('sh<-2', 'longest<-1.5', 'gap_mean%')]
shown = [a for a in ('A', 'E', 'H', 'G') if a in ARMS]
if os.environ.get('PERSEED') and shown:
    lab = dict(KEYS)
    for k in PER:
        print(f'\n== per seed: {lab[k]} ==')
        print('seed'.ljust(6) + ''.join(a.rjust(10) for a in shown))
        for s_ in common:
            print(str(s_).ljust(6) + ''.join(fmt(data[(a, s_)][k]).rjust(10) for a in shown))

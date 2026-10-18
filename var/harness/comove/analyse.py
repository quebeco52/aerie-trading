"""analyse.py A B ...: District-mainland co-movement and cycle guards from runs/<arm>-<seed>.macro.jsonl.
Env SEEDS (1-8), BURN (5 years), WIN (120 quarters, the HP window), PAIRS (default: each arm vs the first, plus E:D).
Quarterly samples after BURN. Real output = pot x (1 + gap); HP(1600) per non-overlapping window."""
import json, math, os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'cycle_amp'))
from gapstats_lib import hp, mean, sd, acf

H = os.path.dirname(os.path.abspath(__file__))
BURN = float(os.environ.get('BURN', 5))
WIN = int(os.environ.get('WIN', 120))


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
    return sxy / math.sqrt(sxx * syy) if sxx * syy > 0 else float('nan')


def lagc(g, f, k):  # corr(g_t, f_{t+k}); k > 0: the District leads
    return corr(g[:len(g) - k], f[k:]) if k >= 0 else corr(g[-k:], f[:len(g) + k])


def skew(x):
    m, s = mean(x), sd(x)
    return sum(((v - m) / s) ** 3 for v in x) / len(x)


def load(arm, seed):
    rows = [json.loads(l) for l in open(f'{H}/runs/{arm}-{seed}.macro.jsonl')]
    rows = [r for r in rows if r['t'] > 0]
    return rows


def stats(rows):
    pre = [r for r in rows if r['t'] <= BURN]
    R = [r for r in rows if r['t'] > BURN]
    yrs = len(R) / 4
    g = [r['gap'] for r in R]
    f = [r['fgap'] for r in R]
    lny = [math.log(r['pot'] * (1 + r['gap'])) for r in R]
    gr = [lny[i] - lny[i - 1] for i in range(1, len(lny))]
    pol = [r['policy'] for r in R]
    rf = [r['rf'] for r in R]
    lfx = [math.log(r['fx']) for r in R]
    nx = [r['nx'] for r in R]
    rec = [1 if r['rec'] else 0 for r in R]
    rec0 = 1 if (pre and pre[-1]['rec']) else 0
    starts = sum(1 for i, v in enumerate(rec) if v == 1 and (rec[i - 1] if i else rec0) == 0)
    dis_n = R[-1]['dis_n'] - (pre[-1]['dis_n'] if pre else 0)
    hpc, hpf = [], []
    for s0 in range(0, len(R) - WIN + 1, WIN):
        y = lny[s0:s0 + WIN]
        cy = [a - b for a, b in zip(y, hp(y))]
        fw = f[s0:s0 + WIN]
        cf = [a - b for a, b in zip(fw, hp(fw))]
        hpc.append(corr(cy, cf))
        hpf.append(corr(cy, fw))
    lags = {k: lagc(g, f, k) for k in range(-4, 5)}
    weak = [r['fund_draw'] for r in R if r['gap'] < -0.02]
    m = {
        'corr0': corr(g, f),
        'peak_k': max(lags, key=lags.get),
        'hp_mean': mean(hpc), 'hp_sd': sd(hpc) if len(hpc) > 1 else float('nan'),
        'hpraw_mean': mean(hpf),
        'gap_mean%': mean(g) * 100, 'gap_sd%': sd(g) * 100, 'gap_acf4': acf(g, 4), 'gap_skew': skew(g),
        'gr_ar1': acf(gr, 1), 'gr_sd%': sd(gr) * 100,
        'rec/dec': starts / yrs * 10, 'dis/dec': dis_n / yrs * 10,
        'pol_sd%': sd(pol) * 100, 'floor': sum(1 for x in pol if x <= 0.0025) / len(pol), 'corr_r_rf': corr(pol, rf),
        'fx_sd%': sd(lfx) * 100,
        'nx_share': corr(nx, g) * sd(nx) / sd(g),
        'fgap_sd%': sd(f) * 100,
        'fund_min': min(r['fund'] for r in R), 'fund_mean': mean([r['fund'] for r in R]),
        'draw_weak%': mean(weak) * 100 if weak else float('nan'),
        'dmain_sd%': sd([r['dmain'] for r in R]) * 100,
    }
    for k, v in lags.items():
        m[f'lag{k:+d}'] = v
    return m


arms = sys.argv[1:]
S = seeds()
res = {a: {s: stats(load(a, s)) for s in S} for a in arms}
keys = list(next(iter(res[arms[0]].values())).keys())
nanmean = lambda xs: mean([x for x in xs if x == x]) if any(x == x for x in xs) else float('nan')
print(f'n = {len(S)} seeds per arm (SEEDS {os.environ.get("SEEDS", "1-8")}), quarters after year {BURN:g}; mean [min, max] over seeds')
print('quantity'.ljust(12) + ''.join(a.center(24) for a in arms))
for k in keys:
    cells = []
    for a in arms:
        v = [res[a][s][k] for s in S]
        vv = [x for x in v if x == x]
        cells.append(f'{nanmean(v):7.3f} [{min(vv):6.2f},{max(vv):6.2f}]' if vv else 'nan'.center(24))
    print(k.ljust(12) + ''.join(c.rjust(24) for c in cells))

pk = ['corr0', 'hp_mean', 'gap_sd%', 'gap_skew', 'gr_ar1', 'rec/dec', 'pol_sd%', 'floor', 'fx_sd%']
pairs = [(arms[0], b) for b in arms[1:]]
if 'E' in arms and 'D' in arms:
    pairs.append(('D', 'E'))
if os.environ.get('PAIRS'):
    pairs = [tuple(p.split(':')) for p in os.environ['PAIRS'].split(',')]
print('\npaired difference: mean (se) t, n =', len(S))
print('quantity'.ljust(12) + ''.join(f'{b}-{a}'.center(24) for a, b in pairs))
for k in pk:
    cells = []
    for a, b in pairs:
        d = [res[b][s][k] - res[a][s][k] for s in S]
        se = sd(d) / math.sqrt(len(d))
        cells.append(f'{mean(d):7.3f} ({se:.3f}) {mean(d) / se if se else float("nan"):5.1f}')
    print(k.ljust(12) + ''.join(c.rjust(24) for c in cells))

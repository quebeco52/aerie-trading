"""analyse.py A B: District left tail, cycle guards, slump speed and co-movement from runs/<arm>-<seed>.macro.jsonl.
Env SEEDS (1-8), BURN (5 years), WIN (120 quarters, the HP window). Quarterly samples after BURN.
Prints per arm mean [min, max] over seeds, then the paired difference B-A: mean (se) t."""
import json, math, os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'cycle_amp'))
from gapstats_lib import hp, mean, sd, acf

H = os.path.dirname(os.path.abspath(__file__))
BURN = float(os.environ.get('BURN', 5))
WIN = int(os.environ.get('WIN', 120))
NAN = float('nan')


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


def lagc(g, f, k):  # corr(g_t, f_{t+k}); k < 0: the mainland leads
    return corr(g[:len(g) - k], f[k:]) if k >= 0 else corr(g[-k:], f[:len(g) + k])


def skew(x):
    m, s = mean(x), sd(x)
    return sum(((v - m) / s) ** 3 for v in x) / len(x)


def exkurt(x):
    m, s = mean(x), sd(x)
    return sum(((v - m) / s) ** 4 for v in x) / len(x) - 3.0


def slumps(x, h):
    """Zigzag: peak-to-trough falls larger than h. Returns [(fall, quarters)]."""
    out = []
    hi_i, lo_i, mode = 0, 0, 'up'  # mode 'up': tracking a peak; 'down': tracking a trough after a confirmed fall
    for i, v in enumerate(x):
        if mode == 'up':
            if v > x[hi_i]:
                hi_i = i
            elif x[hi_i] - v > h:
                mode, lo_i = 'down', i
        else:
            if v < x[lo_i]:
                lo_i = i
            elif v - x[lo_i] > h:
                out.append((x[hi_i] - x[lo_i], lo_i - hi_i))
                mode, hi_i = 'up', i
    if mode == 'down':
        out.append((x[hi_i] - x[lo_i], lo_i - hi_i))
    return out


def slump_stats(x):
    s = sd(x)
    sl = slumps(x, 1.2 * s)
    if not sl:
        return NAN, NAN, 0
    return mean([q for _, q in sl]), mean([f / s / max(q, 1) for f, q in sl]), len(sl)


def load(arm, seed):
    return [r for r in (json.loads(l) for l in open(f'{H}/runs/{arm}-{seed}.macro.jsonl')) if r['t'] > 0]


def stats(rows):
    pre = [r for r in rows if r['t'] <= BURN]
    R = [r for r in rows if r['t'] > BURN]
    yrs = len(R) / 4
    g = [r['gap'] for r in R]
    f = [r['fgap'] for r in R]
    df = [f[i] - f[i - 1] for i in range(1, len(f))]
    q = [r['rgq'] for r in R]
    q1 = q[1:]  # aligned with df
    g4 = [math.prod(1 + v for v in q[i - 3:i + 1]) - 1 for i in range(3, len(q))]
    lny = [math.log(r['pot'] * (1 + r['gap'])) for r in R]
    pol = [r['policy'] for r in R]
    rf = [r['rf'] for r in R]
    rec = [1 if r['rec'] else 0 for r in R]
    rec0 = 1 if (pre and pre[-1]['rec']) else 0
    starts = sum(1 for i, v in enumerate(rec) if v == 1 and (rec[i - 1] if i else rec0) == 0)
    dis_n = R[-1]['dis_n'] - (pre[-1]['dis_n'] if pre else 0)
    hpc = []
    for s0 in range(0, len(R) - WIN + 1, WIN):
        y = lny[s0:s0 + WIN]
        cy = [a - b for a, b in zip(y, hp(y))]
        fw = f[s0:s0 + WIN]
        cf = [a - b for a, b in zip(fw, hp(fw))]
        hpc.append(corr(cy, cf))
    lags = {k: lagc(g, f, k) for k in range(-4, 5)}
    kpk = max(lags, key=lags.get)
    gd, gv, gn = slump_stats(g)
    fd, fv, fn = slump_stats(f)
    return {
        # 1. District quarterly growth
        'gr_skew': skew(q), 'gr_exkurt': exkurt(q), 'gr_worst%': min(q) * 100,
        'gr<-1%_sh': sum(1 for v in q if v < -0.01) / len(q), 'gr<-2%/100y': sum(1 for v in q if v < -0.02) / yrs * 100,
        'gr4_worst%': min(g4) * 100,
        # 2. gap
        'gap_mean%': mean(g) * 100, 'gap_sd%': sd(g) * 100, 'gap_skew': skew(g), 'gap_acf4': acf(g, 4),
        'gap<-2%_sh': sum(1 for v in g if v < -0.02) / len(g),
        # 3. guards
        'rec/dec': starts / yrs * 10, 'floor': sum(1 for x in pol if x <= 0.0025) / len(pol), 'pol_sd%': sd(pol) * 100,
        # 4. slump speed
        'slump_q': gd, 'slump_sd/q': gv, 'slump_n/100y': gn / yrs * 100,
        'fslump_q': fd, 'fslump_sd/q': fv,
        # 5. mainland
        'fgap_sd%': sd(f) * 100, 'dfgap_skew': skew(df), 'dfgap_exk': exkurt(df), 'dfgap_min%': min(df) * 100,
        'fcrash/100y': sum(1 for v in df if v < -0.015) / yrs * 100,
        'rf_floor': sum(1 for x in rf if x <= 0.0025) / len(rf), 'rf_mean%': mean(rf) * 100,
        # 6. co-movement
        'corr0': corr(g, f), 'corr_peak': lags[kpk], 'peak_k': kpk, 'hp_corr': mean(hpc),
        'gr_df_0': corr(q1, df), 'gr_df_m1': lagc(q1, df, -1), 'gr_df_m2': lagc(q1, df, -2),
        # 7. local disasters
        'dis/dec': dis_n / yrs * 10,
    }


arms = sys.argv[1:]
S = seeds()
res = {a: {s: stats(load(a, s)) for s in S} for a in arms}
keys = list(res[arms[0]][S[0]].keys())
fin = lambda xs: [x for x in xs if x == x]
print(f'n = {len(S)} seeds per arm (SEEDS {os.environ.get("SEEDS", "1-8")}), quarters after year {BURN:g}; '
      f'mean [min, max] over seeds; paired B-A: mean (se) t')
a0, b0 = arms[0], arms[1]
print('quantity'.ljust(14) + a0.center(26) + b0.center(26) + f'{b0}-{a0}'.center(28))
for k in keys:
    cells = []
    for a in (a0, b0):
        v = fin([res[a][s][k] for s in S])
        cells.append(f'{mean(v):8.3f} [{min(v):7.2f},{max(v):7.2f}]' if v else 'nan'.center(26))
    d = fin([res[b0][s][k] - res[a0][s][k] for s in S])
    if len(d) > 1:
        se = sd(d) / math.sqrt(len(d))
        cells.append(f'{mean(d):8.3f} ({se:.3f}) {mean(d) / se if se else NAN:5.1f}')
    else:
        cells.append('nan'.center(28))
    print(k.ljust(14) + ''.join(c.rjust(26) for c in cells[:2]) + cells[2].rjust(28))

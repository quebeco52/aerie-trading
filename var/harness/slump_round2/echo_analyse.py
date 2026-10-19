"""echo_analyse.py [A B]: mainland-echo check (default G X). Mainland and District cycle moments from
runs/<arm>-<seed>.macro.jsonl, quarters after BURN (2 years). Env SEEDS (1-8), BURN, YEARS (52, completeness check).
Prints flags (non-finite values, short runs, run times), the fgap pairing check, then per arm mean [min, max] over seeds
and the paired B-A: mean (se) t. Zigzag slumps as in ../mainland_crash/analyse.py; HP(1600) on the whole sample."""
import json, math, os, re, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'cycle_amp'))
from gapstats_lib import hp, mean, sd, acf, runs

H = os.path.dirname(os.path.abspath(__file__))
BURN = float(os.environ.get('BURN', 2))
YEARS = float(os.environ.get('YEARS', 52))
NAN = float('nan')
A, B = (sys.argv[1:3] if len(sys.argv) > 2 else ['G', 'X'])


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


def slumps(x, h):
    """Zigzag: peak-to-trough falls larger than h. Returns [(fall, quarters)]."""
    out = []
    hi_i, lo_i, mode = 0, 0, 'up'
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


FLAGS = []


def load(arm, seed):
    path = f'{H}/runs/{arm}-{seed}.macro.jsonl'
    if not os.path.exists(path):
        FLAGS.append(f'{arm}-{seed}: missing'); return None
    rows = [json.loads(l) for l in open(path)]
    rows = [r for r in rows if r['t'] > 0]
    for k in ('gap', 'fgap', 'rf', 'policy', 'infl', 'pot'):
        if any(not isinstance(r[k], (int, float)) or not math.isfinite(r[k]) for r in rows):
            FLAGS.append(f'{arm}-{seed}: non-finite {k}')
    log = f'{H}/runs/{arm}-{seed}.log'
    if os.path.exists(log):
        txt = open(log).read()
        m = re.search(r'Time: ([0-9:.]+)', txt)
        if m:
            FLAGS.append(f'{arm}-{seed}: {m.group(1)}')
        if not re.search(r'^OK', txt, re.M):
            FLAGS.append(f'{arm}-{seed}: no OK line in log')
    return rows


def stats(rows):
    R = [r for r in rows if r['t'] > BURN]
    g = [r['gap'] for r in R]
    f = [r['fgap'] for r in R]
    rf = [r['rf'] for r in R]
    pol = [r['policy'] for r in R]
    infl = [r['infl'] for r in R]
    i4 = [mean(infl[i - 3:i + 1]) for i in range(3, len(infl))]
    rs = runs(f)
    inner = rs[1:-1] if len(rs) > 2 else rs
    sl = slumps(f, 1.2 * sd(f))
    rb = runs_below(g, -0.015)
    lny = [math.log(r['pot'] * (1 + r['gap'])) for r in R]
    cy = [a - b for a, b in zip(lny, hp(lny))]
    cf = [a - b for a, b in zip(f, hp(f))]
    return {
        'fgap_mean%': 100 * mean(f), 'fgap_sd%': 100 * sd(f), 'fgap_acf4': acf(f, 4), 'fgap_acf8': acf(f, 8),
        'f_longest_run': max(n for _, n in rs), 'f_mean_run': mean([n for _, n in inner]),
        'f|>2%|_sh': sum(abs(v) > 0.02 for v in f) / len(f),
        'rf_mean%': 100 * mean(rf), 'rf_max%': 100 * max(rf), 'rf>6%_sh': sum(v > 0.06 for v in rf) / len(rf),
        'rf_floor': sum(v <= 0.0025 for v in rf) / len(rf),
        'fslump_q': mean([q for _, q in sl]) if sl else NAN,
        'gap_mean%': 100 * mean(g), 'gap_sd%': 100 * sd(g), 'gap<-2%_sh': sum(v < -0.02 for v in g) / len(g),
        'longest<-1.5': max(rb) if rb else 0,
        'corr(g,f)': corr(g, f), 'hp_corr': corr(cy, cf),
        'infl4_mean%': 100 * mean(i4), 'infl4_sd%': 100 * sd(i4),
        'pol_floor': sum(v <= 0.0025 for v in pol) / len(pol),
    }, len(R)


def summary(xs):
    xs = [x for x in xs if x == x]
    return f'{mean(xs):8.3f} [{min(xs):7.3f},{max(xs):7.3f}]' if xs else 'nan'


def paired(d):
    d = [x for x in d if x == x]
    n = len(d)
    if n < 2:
        return 'n/a'
    m, s = mean(d), sd(d) / math.sqrt(n)
    return f'{m:+8.3f} ({s:6.3f}) t {m / s if s > 0 else NAN:+5.1f}'


S = seeds()
res, fg = {A: {}, B: {}}, {A: {}, B: {}}
for arm in (A, B):
    for s in S:
        rows = load(arm, s)
        if rows is None:
            continue
        st, n = stats(rows)
        if n < 4 * (YEARS - BURN) - 2:
            FLAGS.append(f'{arm}-{s}: only {n} quarters')
        res[arm][s] = st
        fg[arm][s] = [r['fgap'] for r in rows if r['t'] > BURN]
both = [s for s in S if s in res[A] and s in res[B]]
times = [f for f in FLAGS if re.search(r': \d', f)]
other = [f for f in FLAGS if f not in times]
print(f'n = {len(both)} paired seeds ({os.environ.get("SEEDS", "1-8")}), quarters after year {BURN:g}, '
      f'{YEARS:g} years; mean [min, max] over seeds; paired {B}-{A}: mean (se) t')
print('flags: ' + ('; '.join(other) if other else 'none (all finite, all runs complete, OK in every log)'))
print('run times: ' + ', '.join(times))
pc = [corr(fg[A][s], fg[B][s]) for s in both]
print(f'pairing: corr of quarterly fgap {B} vs {A} per seed: min {min(pc):.3f}, mean {mean(pc):.3f}')
print('quantity'.ljust(14) + A.center(27) + B.center(27) + f'{B}-{A}'.center(28))
for k in res[A][both[0]]:
    a = [res[A][s][k] for s in both]
    b = [res[B][s][k] for s in both]
    print(k.ljust(14) + summary(a).rjust(27) + summary(b).rjust(27) + '  ' + paired([y - x for x, y in zip(a, b)]))

#!/usr/bin/env python3
"""Recession-regime trigger (regime_run.sh arms R1 R2 ...; GRID=1 adds counterfactual latch shares): python3 regime_analyse.py [base K0] [arms K1 ...]. Drops year 1; quarterly rows.
Targets (named before the run):
  T1 cut4q_rec%  largest 4-quarter policy-rate cut, over seeds with a recession (a Sahm-rule quarter after year 1),
                 paired on seeds where both arms have one;
  T2 elb_share   share of quarters with the policy rate at the floor (<= ELB + 5bp, as analyse.py);
  T3 trough_gap% deepest output gap in the seed's first recession window (from its first Sahm quarter to 8 quarters on),
     rec_q       quarters from that trough back to a gap >= 0 (censored at the run's end).
Guards: pol ACF4 (~0.68), gap ACF4 and sd, inflation mean and sd, 10Y mean, crisis incidence, blow-ups.
Open economy: fx_qsd% (quarter-end log-change sd of the currency; BIS CHF/GBP/SEK ~2.4-2.9), fxfund/fxdev, tb_mean/sd%, nx_sd%, imp_qsd%.
Extras: evans_t (share of ticks the Evans hold binds), regime_t (share of ticks in the recession regime), switches/yr,
fastcut_t (share of ticks in the regime while cutting)."""
import json, math, glob, os, sys, re
H = os.path.dirname(os.path.abspath(__file__))
YEARS = float(os.environ.get('YEARS', 21))
ELB, SAHM = 0.001, 0.005
WIN = int(os.environ.get('WIN', 140))   # ACF window: first WIN quarters after year 1

def acf(x, k):
    n = len(x); m = sum(x) / n; v = sum((a - m) ** 2 for a in x)
    return sum((x[i] - m) * (x[i + k] - m) for i in range(n - k)) / v if v > 0 else float('nan')
def sd(x):
    m = sum(x) / len(x); return math.sqrt(sum((a - m) ** 2 for a in x) / (len(x) - 1))
def mean(x): return sum(x) / len(x)

KEYS = ['cut4q_rec%', 'cut4q_all%', 'elb_share', 'trough_gap%', 'rec_q', 'pol_acf4', 'gap_acf4', 'gap_acf8', 'dgap_sd%', 'gap_gt2', 'gap_lt-2', 'gap_sd%', 'infl_mean%',
        'infl_sd%', 'y10_mean%', 'gap_mean%', 'pol_mean%', 'crisis', 'evans_t', 'regime_t', 'switches/yr', 'fastcut_t', 'hike4q%', 'sahm_t', 'latch_t', 'latch_only', 'sahm_only', 'both_t', 'ebp1_t', 'ebp_sd%', 'ebp_mean%', 'panic_t', 'realpol_max%',
        'fx_qsd%', 'fxfund_qsd%', 'fxdev_qsd%', 'tb_mean%', 'tb_sd%', 'nx_sd%', 'imp_qsd%']
if os.environ.get('GRID'): KEYS += [f'{p}{b}_t' for p in ('lat', 'uni') for b in (100, 125, 150, 175, 200, 250)]
COND = {'cut4q_rec%', 'trough_gap%', 'rec_q'}   # defined only on seeds with a recession
def moments(path):
    rows = [json.loads(l) for l in open(path) if l.strip()]
    if not rows or rows[-1]['t'] < YEARS - 0.3:
        return None
    if any(not math.isfinite(v) for r in rows for v in (r['y10'], r['gap'], r['infl'], r['policy'])):
        return None
    rows = [r for r in rows if r['t'] > 1.0 + 1e-9]
    c = lambda k: [r[k] for r in rows]
    p, g, i, y = c('policy'), c('gap'), c('infl'), c('y10')
    cuts = [p[k] - p[k + 4] for k in range(len(p) - 4)]
    sahm_q = [k for k, r in enumerate(rows) if r['sahm'] >= SAHM]
    m = {'cut4q_all%': 100 * max(cuts), 'hike4q%': 100 * max(-x for x in cuts), 'elb_share': sum(1 for a in p if a <= ELB + 0.0005) / len(p),
         'pol_acf4': acf(p[:WIN], 4), 'gap_acf4': acf(g[:WIN], 4), 'gap_acf8': acf(g[:WIN], 8),
         'dgap_sd%': 100 * sd([g[k + 1] - g[k] for k in range(len(g) - 1)]), 'gap_gt2': sum(1 for a in g if a > 0.02) / len(g), 'gap_lt-2': sum(1 for a in g if a < -0.02) / len(g), 'gap_sd%': 100 * sd(g), 'infl_mean%': 100 * mean(i), 'infl_sd%': 100 * sd(i),
         'y10_mean%': 100 * mean(y), 'gap_mean%': 100 * mean(g), 'pol_mean%': 100 * mean(p), 'crisis': 1.0 if any(r['crisis_at'] > 1.0 for r in rows) else 0.0,
         'evans_t': mean(c('evans_t')), 'regime_t': mean(c('regime_t')), 'switches/yr': sum(c('switches')) / (YEARS - 1.0),
         'fastcut_t': mean(c('fastcut_t'))}
    for k in [k for k in rows[0] if k.endswith('_t') and k not in m]: m[k] = mean(c(k))
    if 'sahm_t' in m: m['latch_only'] = m['latch_t'] - m['both_t']; m['sahm_only'] = m['sahm_t'] - m['both_t']
    m['ebp_sd%'] = 100 * sd(c('ebp')); m['ebp_mean%'] = 100 * mean(c('ebp'))
    # Open-economy block (2026-10-11): quarter-end log-change sd of the currency (all rows have fx), of its fundamental
    # and deviation, and of the import price level; the trade balance's level and sd; net exports' sd.
    dl = lambda x: [math.log(x[k + 1] / x[k]) for k in range(len(x) - 1)]
    m['fx_qsd%'] = 100 * sd(dl(c('fx')))
    m['nx_sd%'] = 100 * sd(c('nx'))
    if 'fx_fund' in rows[0]:
        m['fxfund_qsd%'] = 100 * sd(dl(c('fx_fund'))); m['fxdev_qsd%'] = 100 * sd(dl(c('fx_dev')))
        m['tb_mean%'] = 100 * mean(c('tb')); m['tb_sd%'] = 100 * sd(c('tb'))
        m['imp_qsd%'] = 100 * sd([b - a for a, b in zip(c('imp'), c('imp')[1:])])
    if 'pi_rule' in rows[0]: m['realpol_max%'] = 100 * max(r['policy'] - r['pi_rule'] for r in rows)   # policy less the rule's inflation measure
    if sahm_q:
        k0 = sahm_q[0]
        # The largest 4-quarter cut whose window touches a recession quarter (a Sahm quarter, or the four before one).
        rec = set(k for s in sahm_q for k in range(s - 4, s + 1))
        m['cut4q_rec%'] = 100 * max([cuts[k] for k in range(len(cuts)) if k in rec] or [float('nan')])
        lo, hi = max(0, k0 - 4), min(len(g), k0 + 9)
        kt = min(range(lo, hi), key=lambda k: g[k])
        m['trough_gap%'] = 100 * g[kt]
        back = next((k for k in range(kt, len(g)) if g[k] >= 0.0), len(g))
        m['rec_q'] = back - kt
    return m

arms = sys.argv[1:] or ['R1', 'R2']
base = arms[0]
data, blown = {}, {}
for a in arms:
    for f in glob.glob(f'{H}/runs/{a}-*.macro.jsonl'):
        s = int(os.path.basename(f).split('.')[0].split('-')[1])
        m = moments(f)
        if m is None: blown.setdefault(a, []).append(s)
        else: data.setdefault(a, {})[s] = m
seeds = sorted(set.intersection(*(set(data.get(a, {})) for a in arms)))
if os.environ.get('SEEDS'):  # SEEDS=1-16 restricts to a stage
    lo_, hi_ = map(int, os.environ['SEEDS'].split('-')); seeds = [s for s in seeds if lo_ <= s <= hi_]
n = len(seeds)
print(f'n={n} paired seeds; blown up: ' + ', '.join(f'{a} {sorted(blown.get(a, []))}' for a in arms))
print(f"{'quantity':<13}" + ''.join(f'{a:>18}' for a in arms) + ''.join(f'{"d" + a + "-" + base + " (se) t  n":>32}' for a in arms[1:]))
for k in KEYS:
    line = f'{k:<13}'
    for a in arms:
        xs = [data[a][s][k] for s in seeds if k in data[a][s] and math.isfinite(data[a][s][k])]
        line += f'{mean(xs):>9.3f} ({sd(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float("nan"):.3f})' if xs else f'{"-":>18}'
    for a in arms[1:]:
        ps = [s for s in seeds if k in data[a][s] and k in data[base][s] and math.isfinite(data[a][s][k]) and math.isfinite(data[base][s][k])]
        d = [data[a][s][k] - data[base][s][k] for s in ps]
        if len(d) > 1:
            md, se = mean(d), sd(d) / math.sqrt(len(d))
            line += f'{md:>+12.3f} ({se:.3f}) {md / se if se > 0 else float("nan"):>+6.1f} {len(d):>3}'
    print(line)
print('recession seeds: ' + ', '.join(f'{a} {sum(1 for s in seeds if "rec_q" in data[a][s])}' for a in arms))

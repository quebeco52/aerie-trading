"""inv_analyse.py A B C D E: inventory investment as a level part of GDP (runs/I<arm>-<seed>.macro.jsonl).
Per seed, then per arm with paired deltas vs the first arm and vs the second. Env SEEDS (1-8), BURN (2), YEARS (33).
Real GDP = pot x (1 + gap) at quarter end; published growth = the row's realGdpQuarterlyGrowth (quarter averages);
final sales = pot x (1 + gap - inv); inventory contribution = 100 x change in inv over the quarter (pp of GDP)."""
import json, math, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from gapstats_lib import hp, mean, sd, acf, ar1, spell_stats

H = os.path.dirname(os.path.abspath(__file__))
ARMS = sys.argv[1:] or ['A', 'B', 'C', 'D', 'E']
def seeds(s):
    out = []
    for p in s.split(','):
        a, _, b = p.partition('-'); out += list(range(int(a), int(b or a) + 1))
    return out
SEEDS = seeds(os.environ.get('SEEDS', '1-8'))
BURN = float(os.environ.get('BURN', 2)); YEARS = float(os.environ.get('YEARS', 33))
ELB = 0.001
NAN = float('nan')

def var0(x): return len(x) > 2 and sd(x) > 1e-12
def sar1(x): return ar1(x) if var0(x) else NAN
def sacf(x, k): return acf(x, k) if var0(x) else NAN
def skew(x):
    m, s = mean(x), sd(x); return sum((v - m) ** 3 for v in x) / len(x) / s ** 3
def cov(a, b):
    ma, mb = mean(a), mean(b); return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / (len(a) - 1)
def corr(a, b):
    return cov(a, b) / (sd(a) * sd(b)) if var0(a) and var0(b) else NAN
def lagprof(rate, cyc, K=12):
    cs = [corr(rate[k:], cyc[:len(cyc) - k]) for k in range(K + 1)]
    k = max(range(K + 1), key=lambda i: cs[i] if math.isfinite(cs[i]) else -9); return k
def dlog(x): return [100 * math.log(x[t] / x[t - 1]) for t in range(1, len(x))]

def stats(path):
    rows = [json.loads(l) for l in open(path)]
    if not rows or rows[-1]['t'] < YEARS - 0.01:
        return None
    crises_all = set(r['crisis_at'] for r in rows if r.get('crisis_at'))
    rows = [r for r in rows if r['t'] > BURN + 1e-9]
    gap = [100 * r['gap'] for r in rows]
    if any(not math.isfinite(g) or abs(g) > 25 for g in gap):
        return None
    y = [r['pot'] * (1 + r['gap']) for r in rows]
    lny = [math.log(v) for v in y]
    g = dlog(y)
    pub = [100 * r['rgq'] for r in rows[1:]]
    fs = [r['pot'] * (1 + r['gap'] - r['inv']) for r in rows]
    fsg = dlog(fs)
    inv = [100 * r['inv'] for r in rows]
    ic = [inv[t] - inv[t - 1] for t in range(1, len(inv))]
    dd = [100 * (r['gap'] - r['supply'] - r['nx'] - r['fin'] - r['inv']) / r['W'] for r in rows]
    cyc = [100 * (a - b) for a, b in zip(lny, hp(lny))]
    rate = [100 * r['policy'] for r in rows]
    sp, hs = spell_stats(gap), spell_stats(cyc)
    d4 = [rate[t] - rate[t - 4] for t in range(4, len(rate))]
    peak = y[0]; ddn = 0.0
    for v in y:
        peak = max(peak, v); ddn = min(ddn, v / peak - 1)
    n = len(gap); infl = [100 * r['infl'] for r in rows]; u = [100 * r['u'] for r in rows]
    rec = [bool(r['rec']) for r in rows]
    starts = sum(1 for t in range(1, n) if rec[t] and not rec[t - 1])
    crises = [c for c in crises_all if c and float(c) > BURN]
    return {
        '-- growth (targets)': None,
        'g_sd': sd(g), 'g_ar1': ar1(g), 'g_skew': skew(g),
        'pub_sd': sd(pub), 'pub_ar1': ar1(pub), 'pub_skew': skew(pub),
        'fs_g_ar1': ar1(fsg), 'fs_g_sd': sd(fsg),
        'inv_sd_pp': sd(inv), 'inv_ar1': sar1(inv), 'inv_acf4': sacf(inv, 4),
        'icontr_sd_pp': sd(ic), 'icontr_ar1': sar1(ic),
        'inv_varsh': cov(g, ic) / sd(g) ** 2, 'corr_fs_ic': corr(fsg, ic),
        'var_g/var_fs': sd(g) ** 2 / sd(fsg) ** 2,
        'stock_sd_pp': sd([100 * r['inv_stock'] for r in rows]),
        'corr_stock_dd': corr([r['inv_stock'] for r in rows], dd),
        '-- cycle (guards)': None,
        'gap_sd': sd(gap), 'gap_mean': mean(gap), 'gap_acf1': acf(gap, 1), 'gap_acf4': acf(gap, 4),
        'gap_acf8': acf(gap, 8), 'gap_acf12': acf(gap, 12), 'gap_spell_q': sp['mean_spell'],
        'gap_longneg_q': sp['longest_neg'],
        'hp_sd': sd(cyc), 'hp_ar1': ar1(cyc), 'hp_acf4': acf(cyc, 4), 'hp_spell_q': hs['mean_spell'],
        'dd_sd': sd(dd),
        'rec_per_dec': starts / (n / 40), 'rec_share': sum(rec) / n,
        'infl_mean': mean(infl), 'infl_sd': sd(infl), 'u_mean': mean(u), 'u_sd': sd(u), 'u_max': max(u),
        'lag_gap_k': lagprof(rate, gap), 'cut4q_pp': min(d4),
        'elb_share': sum(r['policy'] <= ELB + 1e-9 for r in rows) / n,
        'crises': len(crises), 'drawdown%': 100 * ddn,
        'stockema_sd_pp': sd([100 * r['inv_ema'] for r in rows]),
        'shock_sd_pp': sd([100 * r['inv_shock'] for r in rows]),
    }

data = {a: {} for a in ARMS}; blown = {a: [] for a in ARMS}
for a in ARMS:
    for s in SEEDS:
        p = os.path.join(H, 'runs', f'I{a}-{s}.macro.jsonl')
        st = stats(p) if os.path.exists(p) else None
        if st is None: blown[a].append(s)
        else: data[a][s] = st
keys = list(next(iter(data[ARMS[0]].values())).keys())

def armmean(a, k):
    v = [d[k] for d in data[a].values() if math.isfinite(d[k])]
    return mean(v) if v else NAN
def paired(b, a, k):
    ds = [data[b][s][k] - data[a][s][k] for s in SEEDS if s in data[b] and s in data[a]
          and math.isfinite(data[b][s][k]) and math.isfinite(data[a][s][k])]
    if len(ds) < 2: return NAN, NAN, len(ds)
    m = mean(ds); se = sd(ds) / math.sqrt(len(ds)); return m, se, len(ds)
def cell(m, se):
    if not math.isfinite(m): return f"{'-':>18s}"
    t = m / se if se > 0 else NAN
    return f'{m:+8.3f}({se:5.3f}){t:+5.1f}'[:18].rjust(18)

print(f"seeds {os.environ.get('SEEDS', '1-8')}, years {BURN:g}-{YEARS:g} (quarterly), blown up: "
      + ', '.join(f'{a}:{blown[a] or 0}' for a in ARMS))
for ref, others in ((ARMS[0], ARMS[1:]), (ARMS[1], ARMS[2:])):
    print(f"\npaired vs {ref}: cell = delta(se) t")
    print(f"{'quantity':15s}" + ''.join(f'{a:>8s}' for a in ARMS) + ''.join(f"{b + '-' + ref:>18s}" for b in others))
    for k in keys:
        if k.startswith('--'):
            print(k); continue
        line = f'{k:15s}' + ''.join(f'{armmean(a, k):8.3f}' for a in ARMS)
        for b in others:
            m, se, _ = paired(b, ref, k); line += cell(m, se)
        print(line)
print(f"\nn per arm: " + ', '.join(f'{a}:{len(data[a])}' for a in ARMS))

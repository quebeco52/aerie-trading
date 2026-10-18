"""pers_analyse.py A B C: gap persistence and policy-rate lag, per seed, then per arm with paired deltas vs the first arm.
Env SEEDS (1-8), BURN (2 years dropped), YEARS (33). Statistics use gapstats_lib (the dump analysis' definitions):
real GDP = pot x (1 + gap); HP(1600) on its log; spells on the sign of the series; ACF about the sample mean."""
import json, math, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from gapstats_lib import hp, mean, sd, acf, ar1, spell_stats, growth_stats

H = os.path.dirname(os.path.abspath(__file__))
ARMS = sys.argv[1:] or ['A', 'B', 'C']
def seeds(s):
    out = []
    for p in s.split(','):
        a, _, b = p.partition('-'); out += list(range(int(a), int(b or a) + 1))
    return out
SEEDS = seeds(os.environ.get('SEEDS', '1-8'))
BURN = float(os.environ.get('BURN', 2)); YEARS = float(os.environ.get('YEARS', 33))
ELB = 0.001

def corr(a, b):
    ma, mb = mean(a), mean(b)
    den = math.sqrt(sum((x - ma) ** 2 for x in a) * sum((y - mb) ** 2 for y in b))
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / den if den > 0 else float('nan')
def lagprof(rate, cyc, K=12):
    cs = [corr(rate[k:], cyc[:len(cyc) - k]) for k in range(K + 1)]
    k = max(range(K + 1), key=lambda i: cs[i]); return k, cs[k]

def stats(path):
    rows = [json.loads(l) for l in open(path)]
    if not rows or rows[-1]['t'] < YEARS - 0.01:
        return None
    crises_all = set(r['crisis_at'] for r in rows if r.get('crisis_at'))
    rows = [r for r in rows if r['t'] > BURN + 1e-9]
    gap = [100 * r['gap'] for r in rows]
    if any(not math.isfinite(g) or abs(g) > 25 for g in gap):
        return None
    lny = [math.log(r['pot'] * (1 + r['gap'])) for r in rows]
    cyc = [100 * (a - b) for a, b in zip(lny, hp(lny))]
    rate = [100 * r['policy'] for r in rows]; rule = [100 * r['target'] for r in rows]
    sp, hs = spell_stats(gap), spell_stats(cyc)
    kg, cg = lagprof(rate, gap); kr, cr = lagprof(rate, rule)
    d4 = [rate[t] - rate[t - 4] for t in range(4, len(rate))]
    lvl = [math.exp(v) for v in lny]; peak = lvl[0]; dd = 0.0
    for v in lvl:
        peak = max(peak, v); dd = min(dd, v / peak - 1)
    sup = [100 * r['supply'] for r in rows]
    n = len(gap); infl = [100 * r['infl'] for r in rows]; u = [100 * r['u'] for r in rows]
    crises = [c for c in crises_all if c and float(c) > BURN]
    return {
        'gap_acf1': acf(gap, 1), 'gap_acf4': acf(gap, 4), 'gap_acf8': acf(gap, 8), 'gap_acf12': acf(gap, 12),
        'gap_spell_q': sp['mean_spell'], 'gap_longneg_q': sp['longest_neg'], 'gap_cross_dec': sp['crossings'] / (n / 40),
        'hp_sd': sd(cyc), 'hp_ar1': ar1(cyc), 'hp_acf4': acf(cyc, 4), 'hp_spell_q': hs['mean_spell'],
        'g_ar1': growth_stats(lny)['g_ar1'],
        'lag_gap_k': kg, 'lag_gap_corr': cg, 'lag_rule_k': kr, 'sd_rate_rule': sd([a - b for a, b in zip(rate, rule)]),
        'cut4q_pp': min(d4), 'hike4q_pp': max(d4),
        '-- guards': None,
        'gap_sd': sd(gap), 'gap_mean': mean(gap), 'gap_lt-3': sum(g < -3 for g in gap) / n, 'gap_gt+3': sum(g > 3 for g in gap) / n,
        'infl_mean': mean(infl), 'infl_sd': sd(infl), 'u_mean': mean(u), 'u_sd': sd(u), 'u_max': max(u),
        'elb_share': sum(r['policy'] <= ELB + 1e-9 for r in rows) / n, 'rule_min': min(rule), 'rule_max': max(rule),
        'crises': len(crises), 'drawdown%': 100 * dd, 'supply_varsh': sd(sup) ** 2 / sd(gap) ** 2,
    }

data = {a: {} for a in ARMS}; blown = {a: [] for a in ARMS}
for a in ARMS:
    for s in SEEDS:
        p = os.path.join(H, 'runs', f'P{a}-{s}.macro.jsonl')
        st = stats(p) if os.path.exists(p) else None
        if st is None: blown[a].append(s)
        else: data[a][s] = st
keys = list(next(iter(data[ARMS[0]].values())).keys())
def f(v): return '' if v is None else f'{v:8.3f}'
head = f"{'quantity':15s}" + ''.join(f'{a:>8s}' for a in ARMS)
for b in ARMS[1:]: head += f"{b + '-' + ARMS[0]:>10s}{'se':>7s}{'t':>6s}{'n':>4s}"
print(f"seeds {os.environ.get('SEEDS', '1-8')}, years {BURN:g}-{YEARS:g} (quarterly), blown up: " + ', '.join(f'{a}:{blown[a] or 0}' for a in ARMS))
print(head)
for k in keys:
    if k.startswith('--'):
        print(k); continue
    line = f'{k:15s}' + ''.join(f'{mean([d[k] for d in data[a].values()]):8.3f}' for a in ARMS)
    for b in ARMS[1:]:
        ds = [data[b][s][k] - data[ARMS[0]][s][k] for s in SEEDS if s in data[b] and s in data[ARMS[0]]
              and math.isfinite(data[b][s][k]) and math.isfinite(data[ARMS[0]][s][k])]
        m = mean(ds); se = sd(ds) / math.sqrt(len(ds)) if len(ds) > 1 else float('nan')
        line += f'{m:10.3f}{se:7.3f}{(m / se if se > 0 else float("nan")):6.1f}{len(ds):4d}'
    print(line)
print(f'\narm {ARMS[0]} per-seed spread (min / median / max), and seeds at or above the live path')
live = {'gap_acf4': 0.74, 'gap_acf8': 0.35, 'gap_spell_q': 18.0, 'gap_longneg_q': 39, 'gap_acf1': 0.98, 'hp_acf4': 0.39, 'lag_gap_k': 7}
for k, lv in live.items():
    v = sorted(d[k] for d in data[ARMS[0]].values()); n = len(v)
    med = v[n // 2] if n % 2 else 0.5 * (v[n // 2 - 1] + v[n // 2])
    print(f'{k:15s} {v[0]:8.3f} {med:8.3f} {v[-1]:8.3f}   live {lv:6.2f}: {sum(x >= lv for x in v)}/{n} seeds >=')

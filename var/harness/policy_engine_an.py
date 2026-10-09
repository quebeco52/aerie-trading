"""Engine vs data on the policy loop: the same regressions policy_fit.py runs on US data, run on engine output.

    python3 policy_engine_an.py <dir> <macro arm> [<shock arm>]

<macro arm>-*.json come from CreditMacroProbeTest (quarterly averages); <shock arm>-*.json from PolicyShockProbeTest.
"""
import cmath, glob, json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('# --- 1. Premium dynamics')])
DATA = json.load(open(os.path.join(H, 'policy_fit.json')))
D, ARM = sys.argv[1], sys.argv[2]
SHOCK = sys.argv[3] if len(sys.argv) > 3 else None
PATH = sys.argv[4] if len(sys.argv) > 4 else None
SUPERCORE, GOODS = 0.55, 0.25  # MacroAggregateSubsystem::INFLATION_WEIGHT_*
CORE_W, ANCHOR_W = 0.70, 0.30   # MonetaryPolicySubsystem::TAYLOR_INFLATION_*_WEIGHT


def roots(a1, a2):
    d = cmath.sqrt(a1 * a1 + 4 * a2)
    return [(a1 + d) / 2, (a1 - d) / 2]


runs = {}
for f in sorted(glob.glob(f'{D}/{ARM}-*.json')):
    for r in json.load(open(f)):
        if r['t'] > 10:
            runs.setdefault(r['seed'], []).append(r)
gapq = {s: [100 * r['gap_avg'] for r in q] for s, q in runs.items()}

# [1] gap dynamics
Y, X = [], []
for s, q in runs.items():
    g = gapq[s]
    for i in range(8, len(q)):
        rbar = sum(100 * (q[i - j]['policy'] - q[i - j]['infl_ema'] - q[i - j]['rstar']) for j in range(1, 5)) / 4
        Y.append(g[i])
        X.append([1.0, g[i - 1], g[i - 2], rbar])
b, se, _, _ = ols(Y, X)
rt = roots(b[1], b[2])
d61 = DATA['is']['1961']
print(f"[1] gap dynamics: engine a1 {b[1]:.3f} a2 {b[2]:+.3f} rate {b[3]:+.3f}; roots {rt[0].real:.3f}{rt[0].imag:+.3f}i "
      f"| US 1961-2008 a1 {d61['a1']:.3f} a2 {d61['a2']:+.3f} rate {d61['rate']:+.3f}, roots real")
lags = [1, 2, 4, 6, 8, 10, 12, 14, 16, 20]
allg = [x for g in gapq.values() for x in g]
m = sum(allg) / len(allg)
v = sum((x - m) ** 2 for x in allg) / len(allg)
acf = {L: sum((g[i] - m) * (g[i - L] - m) for g in gapq.values() for i in range(L, len(g))) / sum(len(g) - L for g in gapq.values()) / v for L in lags}
print('    ACF engine: ' + '  '.join(f"{L}q {acf[L]:+.2f}" for L in lags))
print('    ACF US:     ' + '  '.join(f"{L}q {DATA['acf'][str(L)]:+.2f}" for L in lags))
sd = math.sqrt(v)
# ACF distance on lags 4/8/12/16 with Bartlett (1946) standard errors for the US 1949-2019 sample
N_US = 280
us_acf = {int(k): v for k, v in DATA['acf'].items()}
def bartlett(L):
    rho = [us_acf.get(k, us_acf[max(x for x in us_acf if x <= k)]) for k in range(1, L)]
    return math.sqrt((1 + 2 * sum(r * r for r in rho)) / N_US)
J_acf = sum(((acf[L] - us_acf[L]) / bartlett(L)) ** 2 for L in (4, 8, 12, 16))
print(f"    ACF distance (4/8/12/16q, Bartlett se {bartlett(4):.2f}/{bartlett(8):.2f}/{bartlett(12):.2f}/{bartlett(16):.2f}): J_acf {J_acf:.2f}")
print(f"    bands: within +/-1% {sum(abs(x) < 1 for x in allg) / len(allg) * 100:.0f}% (US 28-50), beyond +/-3% {sum(abs(x) > 3 for x in allg) / len(allg) * 100:.0f}% (US 0-35), sd {sd:.2f} (US 1.2-2.8)")

# [2] policy rule on the engine's own inflation measure
Y, X, Xa = [], [], []
for s, q in runs.items():
    for i in range(1, len(q)):
        r = q[i]
        core = (SUPERCORE * r['supercore_ema'] + GOODS * r['goods_ema']) / (SUPERCORE + GOODS)
        pi = 100 * (CORE_W * core + ANCHOR_W * r['tips'])
        g = 100 * r['gap']
        Y.append(100 * r['policy'])
        X.append([1.0, 100 * q[i - 1]['policy'], pi, g])
        Xa.append([1.0, 100 * q[i - 1]['policy'], pi, max(g, 0.0), min(g, 0.0)])
b, _, _, _ = ols(Y, X)
ba, _, _, _ = ols(Y, Xa)
rule = DATA['rule']
print(f"[2] rule: engine rho {b[1]:.3f} ({-4 * math.log(b[1]):.2f}/yr), inflation {b[2] / (1 - b[1]):.2f}, gap {b[3] / (1 - b[1]):.2f}, gap impact boom {ba[3]:.3f} / slack {ba[4]:.3f} "
      f"| US rho {rule['rho']:.3f} ({rule['speed_per_year']:.2f}/yr), inflation {rule['phi_pi']:.2f}, gap {rule['phi_y']:.2f}, impact boom {rule['impact_boom']:.3f} / slack {rule['impact_slack']:.3f}")

# [3] policy shock
if SHOCK and SHOCK != "none":
    diffs_g, diffs_r = [], []
    for f in sorted(glob.glob(f'{D}/{SHOCK}-*.json')):
        d = json.load(open(f))
        for seed, pair in d['runs'].items():
            diffs_g.append([100 * (s_['gap'] - b_['gap']) / (100 * d['shock']) for s_, b_ in zip(pair['shock'], pair['base'])])
            diffs_r.append([100 * (s_['rate'] - b_['rate']) / (100 * d['shock']) for s_, b_ in zip(pair['shock'], pair['base'])])
    n = len(diffs_g)
    mg = [sum(x[h] for x in diffs_g) / n for h in range(len(diffs_g[0]))]
    mr = [sum(x[h] for x in diffs_r) / n for h in range(len(diffs_r[0]))]
    area = sum(mr[:9])
    trough = min(mg)
    th = mg.index(trough)
    print(f"[3] policy shock (+1pp, {n} paired seeds): gap " + '  '.join(f"{h}q {mg[h]:+.2f}" for h in (0, 1, 2, 4, 6, 8, 10, 12, 16) if h < len(mg))
          + '\n    rate ' + '  '.join(f"{h}q {mr[h]:+.2f}" for h in (0, 1, 2, 4, 6, 8, 10, 12, 16) if h < len(mr)))
    # IRF matching (Christiano, Eichenbaum & Evans 2005): the gap path per pp-quarter of rate, engine vs Bauer-Swanson
    bs = DATA['policy_irf_bs']
    us_area = sum(bs[str(h)]['rate'] for h in (0, 1, 2)) + 2 * sum(bs[str(h)]['rate'] for h in (4, 6, 8))
    J = 0.0
    cells = []
    for h in (4, 6, 8, 10, 12, 16):
        e_, u_, se_ = mg[h] / area, bs[str(h)]['gap'] / us_area, bs[str(h)]['gap_se'] / us_area
        J += ((e_ - u_) / se_) ** 2
        cells.append(f"{h}q {e_:+.3f}/{u_:+.3f}")
    print(f"    IRF per pp-quarter engine/US: " + '  '.join(cells) + f"  | J {J:.2f}")
    print(f"    normalized: trough {trough:+.2f} at {th}q over rate area {area:.2f} pp-q -> {trough / area:+.3f} per pp-quarter "
          f"| US (Bauer-Swanson) {DATA['policy_irf_bs_norm']:+.3f}, trough at 10-12q; literature band -0.15..-0.40, 6-12q")

# [4] fiscal reaction, the Auerbach regression on engine output: quarterly averages, as NIPA flows are
if 'pdef_avg' in next(iter(runs.values()))[0]:
    GOV = json.load(open(os.path.join(H, 'gov_fit.json')))['1949']
    Y, X, Yg, Xg = [], [], [], []
    for q in runs.values():
        for i in range(2, len(q)):
            pd, pdp = 100 * q[i]['pdef_avg'], 100 * q[i - 1]['pdef_avg']
            g, gp, gpp = 100 * q[i]['gap_avg'], 100 * q[i - 1]['gap_avg'], 100 * q[i - 2]['gap_avg']
            Y.append(pd - pdp)
            X.append([1.0, g - gp, gpp, pdp])
            Yg.append(q[i]['lgovt_avg'] - q[i - 1]['lgovt_avg'])
            Xg.append([1.0, q[i - 1]['lgovt_avg'], gp / 100.0])
    b, _, _, _ = ols(Y, X)
    bg, _, _, eg = ols(Yg, Xg)
    f = DATA['fiscal']['1960']
    print(f"[4] fiscal: engine deficit automatic {b[1]:+.3f}, discretionary {b[2]:+.3f}, persistence {b[3]:+.3f} | US 1960-2019 automatic {f['auto']:+.3f} ({f['auto_se']:.3f}), "
          f"discretionary {f['disc']:+.3f} ({f['disc_se']:.3f}), persistence {f['persist']:+.3f} ({f['persist_se']:.3f})")
    print(f"    log purchases: engine kappa {-4 * math.log(1 + bg[1]):.3f}/yr, lagged gap {bg[2]:+.3f}, residual sd {moments(eg)[1]:.4f}/q "
          f"| US 1949-2019 kappa {GOV['kappa']:.3f}/yr, lagged gap {GOV['gap']:+.3f} ({GOV['gap_se']:.3f}), residual sd {GOV['resid_sd']:.4f}/q")

# [3c] gap response to the US post-surprise rate path, per unit surprise, against Bauer-Swanson directly
if PATH:
    diffs = []
    # with a negative-scale companion run ({PATH}n) both signs are pooled: the US projection is linear in the surprise
    for f in sorted(glob.glob(f'{D}/{PATH}-*.json') + glob.glob(f'{D}/{PATH}n-*.json')):
        d = json.load(open(f))
        for seed, pair in d['runs'].items():
            diffs.append([100 * (p_['gap'] - b_['gap']) / d['scale'] for p_, b_ in zip(pair['path'], pair['base'])])
    n = len(diffs)
    mg = [sum(x[h] for x in diffs) / n for h in range(len(diffs[0]))]
    bs = DATA['policy_irf_bs']
    J_path = 0.0
    cells = []
    for h in (0, 1, 2, 4, 6, 8, 10, 12, 16):
        J_path += ((mg[h] - bs[str(h)]['gap']) / bs[str(h)]['gap_se']) ** 2
        cells.append(f"{h}q {mg[h]:+.2f}/{bs[str(h)]['gap']:+.2f}")
    print(f"[3c] gap under the US rate path ({n} seeds), engine/US per unit surprise: " + '  '.join(cells) + f"  | J_path {J_path:.2f}")

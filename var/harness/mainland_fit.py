"""The mainland as a compact US model: Rudebusch & Svensson (1999) re-estimated on US data, with the Fed's smoothed rule.

    python3 var/harness/mainland_fit.py     # needs policy_data.json (FRED) and hlw.xlsx (NY Fed r*); writes mainland_fit.json

[1] IS:   gap_t = a1 gap_{t-1} + a2 gap_{t-2} + b (rbar_{t-1} - r*_{t-1}) + e_y      rbar = 4q funds less 4q core inflation
[2] PC:   pi_t  = c + a1..a4 pi_{t-1..t-4} + b gap_{t-1} + e_pi                       pi = core PCE, annualized quarterly
[3] Rule: i_t   = c + rho i_{t-1} + b_pi pi4_t + b_y gap_t + e_i                     pi4 = core PCE, 4q
Then the three are simulated together at quarterly steps, with the lower bound, and their moments set against the data's.
"""
import json, math, os, random, re, zipfile, xml.etree.ElementTree as ET
from datetime import date, timedelta

H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('# --- 1. Premium dynamics')])
D = json.load(open(os.path.join(H, 'policy_data.json')))


def q_of(k):
    return f"{k[:4]}-{(int(k[5:7]) - 1) // 3 * 3 + 1:02d}"


def qavg(series):
    acc = {}
    for k, v in series.items():
        acc.setdefault(q_of(k), []).append(v)
    return {k: sum(v) / len(v) for k, v in acc.items() if len(v) == 3}


z = zipfile.ZipFile(os.path.join(H, 'hlw.xlsx'))
NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
ss = [''.join(t.text or '' for t in si.findall('.//m:t', NS)) for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si', NS)]
rstar = {}
for r in ET.fromstring(z.read('xl/worksheets/sheet2.xml')).find('m:sheetData', NS):
    row = {}
    for c in r:
        v = c.find('m:v', NS)
        if v is not None:
            row[re.match(r'[A-Z]+', c.get('r')).group()] = ss[int(v.text)] if c.get('t') == 's' else v.text
    try:
        rstar[q_of((date(1899, 12, 30) + timedelta(days=int(float(row['A'])))).isoformat()[:7])] = float(row['K'])
    except (KeyError, ValueError):
        pass

gap = {q_of(k): 100 * (v / D['GDPPOT'][k] - 1) for k, v in D['GDPC1'].items() if k in D['GDPPOT']}
ff = qavg(D['FEDFUNDS'])
core = qavg(D['PCEPILFE'])
cq = sorted(core)
pi = {cq[i]: 400 * math.log(core[cq[i]] / core[cq[i - 1]]) for i in range(1, len(cq))}
pi4 = {cq[i]: 100 * math.log(core[cq[i]] / core[cq[i - 4]]) for i in range(4, len(cq))}
qs = sorted(k for k in gap if k in ff and k in pi and k in pi4)
idx = {k: i for i, k in enumerate(qs)}
ELB = lambda k: ('2009-01' <= k <= '2015-10') or ('2020-01' <= k <= '2021-10')
out = {}

IS_SAMPLE = ('1985-01', '2019-10')
PC_SAMPLE = ('1985-01', '2019-10')
RULE_SAMPLE = ('1987-07', '2008-07')

# --- [1] IS -------------------------------------------------------------------------------------------------------
Y, X = [], []
for i in range(8, len(qs)):
    k = qs[i]
    if not (IS_SAMPLE[0] <= k <= IS_SAMPLE[1]) or ELB(k) or any(ELB(qs[i - j]) for j in range(1, 5)):
        continue
    if any(qs[i - j] not in rstar for j in range(1, 5)):
        continue
    rbar = sum(ff[qs[i - j]] - pi4[qs[i - j]] - rstar[qs[i - j]] for j in range(1, 5)) / 4
    Y.append(gap[k])
    X.append([1.0, gap[qs[i - 1]], gap[qs[i - 2]], rbar])
b, se, r2, e = ols(Y, X, nw_lags=4)
out['is'] = {'c': b[0], 'a1': b[1], 'a1_se': se[1], 'a2': b[2], 'a2_se': se[2], 'rate': b[3], 'rate_se': se[3], 'sigma': moments(e)[1], 'n': len(Y)}
print(f"[1] IS {IS_SAMPLE} n {len(Y)}: c {b[0]:+.3f} a1 {b[1]:.3f} ({se[1]:.3f}) a2 {b[2]:+.3f} ({se[2]:.3f}) rate {b[3]:+.3f} ({se[3]:.3f}) resid sd {moments(e)[1]:.3f}")
# the same without the rate term, for the record
b0, se0, _, e0 = ols(Y, [x[:3] for x in X], nw_lags=4)
print(f"    without the rate: a1 {b0[1]:.3f} a2 {b0[2]:+.3f} resid sd {moments(e0)[1]:.3f}")

# --- [2] PC -------------------------------------------------------------------------------------------------------
Y, X = [], []
for i in range(5, len(qs)):
    k = qs[i]
    if not (PC_SAMPLE[0] <= k <= PC_SAMPLE[1]):
        continue
    Y.append(pi[k])
    X.append([1.0] + [pi[qs[i - j]] for j in range(1, 5)] + [gap[qs[i - 1]]])
b, se, r2, e = ols(Y, X, nw_lags=4)
s = sum(b[1:5])
out['pc'] = {'c': b[0], 'lags': b[1:5], 'lags_se': se[1:5], 'gap': b[5], 'gap_se': se[5], 'sigma': moments(e)[1], 'n': len(Y), 'mean': b[0] / (1 - s)}
print(f"[2] PC {PC_SAMPLE} n {len(Y)}: lags {' '.join(f'{x:+.3f}' for x in b[1:5])} (sum {s:.3f}) gap {b[5]:+.3f} ({se[5]:.3f}) resid sd {moments(e)[1]:.3f}; "
      f"implied mean {b[0] / (1 - s):.2f}%")

# --- [3] Rule -----------------------------------------------------------------------------------------------------
sample = [k for k in qs if RULE_SAMPLE[0] <= k <= RULE_SAMPLE[1]]
Y = [ff[k] for k in sample]
X = [[1.0, ff[qs[idx[k] - 1]], pi4[k], gap[k]] for k in sample]
b, se, r2, e = ols(Y, X, nw_lags=4)
rho = b[1]
rs_mean = sum(rstar[k] for k in sample) / len(sample)
out['rule'] = {'c': b[0], 'rho': rho, 'rho_se': se[1], 'phi_pi': b[2] / (1 - rho), 'phi_y': b[3] / (1 - rho), 'sigma': moments(e)[1], 'n': len(Y),
               'neutral_at_2pct': (b[0] + b[2] * 2.0) / (1 - rho), 'hlw_rstar_mean': rs_mean}
print(f"[3] rule {RULE_SAMPLE} n {len(Y)}: rho {rho:.3f} ({se[1]:.3f}), long-run inflation {b[2] / (1 - rho):.2f}, gap {b[3] / (1 - rho):.2f}, "
      f"resid sd {moments(e)[1]:.3f}pp; rate at 2% inflation and zero gap {(b[0] + b[2] * 2.0) / (1 - rho):.2f}% (HLW r* averaged {rs_mean:.2f}%)")


# --- [4] Simulate the three together --------------------------------------------------------------------------------
def simulate(quarters, seed, rstar_sim, target=2.0, elb=0.1, pc_mean=None):
    rnd = random.Random(seed)
    IS, PC, R = out['is'], out['pc'], out['rule']
    phi_pi, phi_y = R['phi_pi'], R['phi_y']
    # The PC intercept re-anchored so the mean is the target (the sample mean carries the 1980s disinflation).
    lags = PC['lags']
    mean_pi = target if pc_mean is None else pc_mean
    c_pi = mean_pi * (1 - sum(lags))
    g = [0.0, 0.0]
    p = [target] * 4
    i = [rstar_sim + target] * 4
    rows = []
    for _ in range(quarters):
        p4 = sum(p) / 4
        rbar = sum(i) / 4 - p4 - rstar_sim
        g_new = IS['a1'] * g[0] + IS['a2'] * g[1] + IS['rate'] * rbar + rnd.gauss(0, IS['sigma'])
        p_new = c_pi + sum(a * x for a, x in zip(lags, p)) + PC['gap'] * g[0] + rnd.gauss(0, PC['sigma'])
        g = [g_new, g[0]]
        p = [p_new] + p[:3]
        p4 = sum(p) / 4
        target_rate = rstar_sim + p4 + (phi_pi - 1) * (p4 - target) + phi_y * g_new
        i_new = max(elb, R['rho'] * i[0] + (1 - R['rho']) * target_rate + rnd.gauss(0, R['sigma']))
        i = [i_new] + i[:3]
        rows.append((g_new, p4, i_new))
    return rows


def acf(x, L):
    m = sum(x) / len(x)
    v = sum((a - m) ** 2 for a in x)
    return sum((x[t] - m) * (x[t - L] - m) for t in range(L, len(x))) / v


data_g = [gap[k] for k in qs if '1985-01' <= k <= '2019-10']
data_p = [pi4[k] for k in qs if '1985-01' <= k <= '2019-10']
data_i = [ff[k] for k in qs if '1985-01' <= k <= '2019-10']
rows = []
for sd in range(40):
    rows += simulate(400, sd, rstar_sim=1.5)[40:]
sg, sp, si = [r[0] for r in rows], [r[1] for r in rows], [r[2] for r in rows]
print("[4] simulated at r* 1.5 (40 x 90y) vs US 1985-2019:")
for name, sim, dat in (('gap', sg, data_g), ('core 4q', sp, data_p), ('funds', si, data_i)):
    ms, md = moments(sim), moments(dat)
    print(f"    {name:8s} mean {ms[0]:+.2f} ({md[0]:+.2f})  sd {ms[1]:.2f} ({md[1]:.2f})  ACF 1/4/8 "
          f"{acf(sim, 1):.2f}/{acf(sim, 4):.2f}/{acf(sim, 8):.2f} ({acf(dat, 1):.2f}/{acf(dat, 4):.2f}/{acf(dat, 8):.2f})")
print(f"    lower bound binds {sum(1 for x in si if x <= 0.1001) / len(si):.1%} of quarters (US 1985-2019: {sum(1 for x in data_i if x < 0.25) / len(data_i):.1%})")
out['sim'] = {'gap_sd': moments(sg)[1], 'pi_sd': moments(sp)[1], 'funds_mean': moments(si)[0], 'funds_sd': moments(si)[1]}
json.dump(out, open(os.path.join(H, 'mainland_fit.json'), 'w'), indent=1)
print('wrote mainland_fit.json')

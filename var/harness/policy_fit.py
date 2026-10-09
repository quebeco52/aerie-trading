"""Policy-loop estimates on US data: reduced-form gap dynamics, the smoothed policy rule, the monetary shock response.

    python3 var/harness/policy_fit.py      # needs policy_data.json (FRED) and hlw.xlsx (NY Fed r*)

[1] Rudebusch & Svensson (1999): gap_t = c + a1 gap_{t-1} + a2 gap_{t-2} + b (4q real rate - r*)_{t-1}.
[2] Clarida, Gali & Gertler (2000): i_t = c + rho i_{t-1} + b_pi pi_t + b_y gap_t, with the engine's asymmetries tested.
[3] Local projection of the gap on the rule's residual (recursive: gap and inflation ordered before the rate).
"""
import cmath, json, math, os, re, zipfile, xml.etree.ElementTree as ET
from datetime import date, timedelta

H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('# --- 1. Premium dynamics')])
D = json.load(open(os.path.join(H, 'policy_data.json')))


def q_of(k):
    y, m = int(k[:4]), int(k[5:7])
    return f"{y}-{(m - 1) // 3 * 3 + 1:02d}"


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
mich = qavg(D['MICH'])
infexp10 = qavg(D['EXPINF10YR'])
qs = sorted(k for k in gap if k in ff and k in core)
core_yoy = {qs[i]: 100 * math.log(core[qs[i]] / core[qs[i - 4]]) for i in range(4, len(qs))}
# The engine's rule reads 0.70 realized core + 0.30 an expectations anchor (TAYLOR_INFLATION_*_WEIGHT).
pi_rule = {k: 0.70 * v + 0.30 * mich[k] if k in mich else v for k, v in core_yoy.items()}
# That anchor is the engine's ten-year breakeven, so the like-for-like US anchor is a ten-year expectation: the
# Cleveland Fed model's (EXPINF10YR, from 1982). The Michigan one-year survey tracks gasoline, as the engine's
# breakeven did before it was fitted to T10YIE; its rule is kept as rule_michigan.
pi_rule_10y = {k: 0.70 * v + 0.30 * infexp10[k] for k, v in core_yoy.items() if k in infexp10}
qs = [k for k in qs if k in core_yoy]
idx = {k: i for i, k in enumerate(qs)}
ELB = lambda k: '2009-01' <= k <= '2015-10'


def roots(a1, a2):
    d = cmath.sqrt(a1 * a1 + 4 * a2)
    return [(a1 + d) / 2, (a1 - d) / 2]


out = {}

# --- [1] Reduced-form gap dynamics -----------------------------------------------------------------------------
print('[1] gap dynamics, Rudebusch-Svensson form (gap %, real rate pp):')
for first, last in (('1961-01', '2008-07'), ('1985-01', '2019-10')):
    Y, X = [], []
    for i in range(8, len(qs)):
        k = qs[i]
        if not (first <= k <= last) or ELB(k) or any(ELB(qs[i - j]) for j in range(1, 5)) or any(qs[i - j] not in rstar for j in range(1, 5)):
            continue
        rbar = sum(ff[qs[i - j]] - core_yoy[qs[i - j]] - rstar[qs[i - j]] for j in range(1, 5)) / 4
        Y.append(gap[k])
        X.append([1.0, gap[qs[i - 1]], gap[qs[i - 2]], rbar])
    b, se, r2, _ = ols(Y, X, nw_lags=4)
    rt = roots(b[1], b[2])
    print(f"    {first}..{last} n {len(Y)}: a1 {b[1]:.3f} ({se[1]:.3f}) a2 {b[2]:+.3f} ({se[2]:.3f}) real rate {b[3]:+.3f} ({se[3]:.3f}); "
          f"roots {rt[0].real:.3f}{rt[0].imag:+.3f}i, {rt[1].real:.3f}{rt[1].imag:+.3f}i")
    out.setdefault('is', {})[first[:4]] = {'a1': b[1], 'a1_se': se[1], 'a2': b[2], 'a2_se': se[2], 'rate': b[3], 'rate_se': se[3]}
lags = [1, 2, 4, 6, 8, 10, 12, 14, 16, 20]
g = [gap[k] for k in sorted(gap) if k < '2020-01']
m = sum(g) / len(g)
v = sum((x - m) ** 2 for x in g) / len(g)
acf = {L: sum((g[i] - m) * (g[i - L] - m) for i in range(L, len(g))) / (len(g) - L) / v for L in lags}
print('    CBO gap ACF 1949-2019: ' + '  '.join(f"{L}q {acf[L]:+.2f}" for L in lags))
out['acf'] = acf

# --- [2] Smoothed policy rule, 1987-2008 (Greenspan-Bernanke, before the lower bound) ---------------------------
RULE = ('1987-07', '2008-07')
sample = [k for k in qs if RULE[0] <= k <= RULE[1]]
def fit_rule(pi, label):
    """The smoothed rule on the inflation measure pi, with its asymmetry tests; returns the moments and the residuals."""
    Y = [ff[k] for k in sample]
    X = [[1.0, ff[qs[idx[k] - 1]], pi[k], gap[k]] for k in sample]
    b, se, r2, res = ols(Y, X, nw_lags=4)
    rho = b[1]
    print(f"[2] rule ({label}) {RULE[0]}..{RULE[1]} n {len(Y)}: rho {rho:.3f} ({se[1]:.3f}) = {-4 * math.log(rho):.2f}/yr; long-run inflation {b[2] / (1 - rho):.2f}, "
          f"gap {b[3] / (1 - rho):.2f}; intercept implies neutral nominal {b[0] / (1 - rho):.2f}% at zero gap and inflation; R2 {r2:.3f}")
    # Delta-method standard errors of the long-run responses b / (1 - rho), from the full coefficient covariance.
    Xs = X
    XtX_inv = inverse([[sum(x[a] * x[c] for x in Xs) for c in range(4)] for a in range(4)])
    s2 = sum(e * e for e in res) / (len(res) - 4)
    V = [[s2 * XtX_inv[a][c] for c in range(4)] for a in range(4)]


    def lr_se(j):
        g_rho, g_b = b[j] / (1 - rho) ** 2, 1 / (1 - rho)
        return math.sqrt(g_b * g_b * V[j][j] + g_rho * g_rho * V[1][1] + 2 * g_b * g_rho * V[j][1])


    rule = {'rho': rho, 'rho_se': se[1], 'speed_per_year': -4 * math.log(rho), 'phi_pi': b[2] / (1 - rho), 'phi_pi_se': lr_se(2), 'phi_y': b[3] / (1 - rho), 'phi_y_se': lr_se(3)}
    print(f"    long-run se (delta method, OLS covariance): inflation {rule['phi_pi_se']:.2f}, gap {rule['phi_y_se']:.2f}")
    # (a) gap weight in slack vs boom
    Xa = [[1.0, ff[qs[idx[k] - 1]], pi[k], max(gap[k], 0.0), min(gap[k], 0.0)] for k in sample]
    ba, sea, _, _ = ols(Y, Xa, nw_lags=4)
    print(f"    asymmetry (a) gap response: boom {ba[3] / (1 - ba[1]):.2f} (impact {ba[3]:.3f}, se {sea[3]:.3f}), slack {ba[4] / (1 - ba[1]):.2f} (impact {ba[4]:.3f}, se {sea[4]:.3f})")
    rule['phi_y_boom'], rule['phi_y_slack'] = ba[3] / (1 - ba[1]), ba[4] / (1 - ba[1])
    rule['impact_boom'], rule['impact_boom_se'], rule['impact_slack'], rule['impact_slack_se'] = ba[3], sea[3], ba[4], sea[4]
    # (b) adjustment speed when cutting vs hiking toward the fitted target
    target = {k: (b[0] + b[2] * pi[k] + b[3] * gap[k]) / (1 - rho) for k in sample}
    for name, sel in (('cut', lambda k: target[k] < ff[qs[idx[k] - 1]]), ('hike', lambda k: target[k] >= ff[qs[idx[k] - 1]])):
        ks = [k for k in sample if sel(k)]
        Yb = [ff[k] - ff[qs[idx[k] - 1]] for k in ks]
        Xb = [[target[k] - ff[qs[idx[k] - 1]]] for k in ks]
        bb, sb, _, _ = ols(Yb, Xb, nw_lags=4)
        print(f"    asymmetry (b) {name}: closes {bb[0]:.3f} ({sb[0]:.3f}) of the distance to target a quarter = {-4 * math.log(1 - bb[0]):.2f}/yr (n {len(ks)})")
        rule[f'close_{name}'], rule[f'close_{name}_se'] = bb[0], sb[0]
    return rule, res


rule, res = fit_rule(pi_rule, 'Michigan 1y anchor')
out['rule_michigan'] = rule
out['rule'], _ = fit_rule(pi_rule_10y, 'Cleveland 10y anchor, the engine\'s like-for-like')

# --- [3] Monetary-policy shock: the rule's residual, local projections ---------------------------------------
shock = dict(zip(sample, res))
print(f"[3] policy shocks: sd {moments(res)[1]:.2f}pp; gap and rate response to a +1pp shock (Newey-West se):")
irf = {}
for h in [0, 1, 2, 4, 6, 8, 10, 12, 16]:
    Yg, Yi, X = [], [], []
    for k in sample:
        i = idx[k]
        if i + h >= len(qs) or ELB(qs[i + h]):
            continue
        Yg.append(gap[qs[i + h]] - gap[qs[i - 1]])
        Yi.append(ff[qs[i + h]] - ff[qs[i - 1]])
        X.append([1.0, shock[k], gap[qs[i - 1]], gap[qs[i - 2]], ff[qs[i - 1]], pi_rule[qs[i - 1]]])
    bg, sg, _, _ = ols(Yg, X, nw_lags=h + 1)
    bi, si, _, _ = ols(Yi, X, nw_lags=h + 1)
    irf[h] = {'gap': bg[1], 'gap_se': sg[1], 'rate': bi[1], 'rate_se': si[1]}
print('    gap:  ' + '  '.join(f"{h}q {v['gap']:+.2f}({v['gap_se']:.2f})" for h, v in irf.items()))
print('    rate: ' + '  '.join(f"{h}q {v['rate']:+.2f}({v['rate_se']:.2f})" for h, v in irf.items()))
out['policy_irf'] = irf
print('    -> recursive identification gives the Fed-information puzzle (output rises after a hike): not usable as transmission.')

# --- [3b] Externally identified shocks: Bauer & Swanson (2023) high-frequency surprises, orthogonalized to news ---
zm = zipfile.ZipFile(os.path.join(H, 'mps.xlsx'))
mss = [''.join(t.text or '' for t in si.findall('.//m:t', NS)) for si in ET.fromstring(zm.read('xl/sharedStrings.xml')).findall('m:si', NS)]
mps = {}
for r in list(ET.fromstring(zm.read('xl/worksheets/sheet3.xml')).find('m:sheetData', NS))[1:]:
    row = {}
    for c in r:
        v = c.find('m:v', NS)
        if v is not None:
            row[re.match(r'[A-Z]+', c.get('r')).group()] = mss[int(v.text)] if c.get('t') == 's' else v.text
    try:
        k = q_of(f"{int(float(row['A']))}-{int(float(row['B'])):02d}")
        mps[k] = mps.get(k, 0.0) + float(row['D'])
    except (KeyError, ValueError):
        pass
MPS_SAMPLE = ('1988-04', '2019-10')
print(f"[3b] Bauer-Swanson MPS_ORTH, quarterly sums {MPS_SAMPLE[0]}..{MPS_SAMPLE[1]}: sd {moments([v for k, v in mps.items() if MPS_SAMPLE[0] <= k <= MPS_SAMPLE[1]])[1]:.3f}")
hs = [0, 1, 2, 4, 6, 8, 10, 12, 16]
irf_b = {}
for h in hs:
    Yg, Yi, X = [], [], []
    for k in qs:
        i = idx[k]
        if not (MPS_SAMPLE[0] <= k <= MPS_SAMPLE[1]) or k not in mps or i + h >= len(qs):
            continue
        Yg.append(gap[qs[i + h]] - gap[qs[i - 1]])
        Yi.append(ff[qs[i + h]] - ff[qs[i - 1]])
        X.append([1.0, mps[k], gap[qs[i - 1]], gap[qs[i - 2]], ff[qs[i - 1]], pi_rule[qs[i - 1]]])
    bg, sg, _, _ = ols(Yg, X, nw_lags=h + 1)
    bi, si, _, _ = ols(Yi, X, nw_lags=h + 1)
    irf_b[h] = {'gap': bg[1], 'gap_se': sg[1], 'rate': bi[1], 'rate_se': si[1]}
print('    gap per unit surprise:  ' + '  '.join(f"{h}q {v['gap']:+.2f}({v['gap_se']:.2f})" for h, v in irf_b.items()))
print('    funds rate per unit:    ' + '  '.join(f"{h}q {v['rate']:+.2f}({v['rate_se']:.2f})" for h, v in irf_b.items()))
area = sum(irf_b[h]['rate'] for h in (0, 1, 2)) + irf_b[4]['rate'] * 2 + irf_b[6]['rate'] * 2 + irf_b[8]['rate'] * 2
peak = min(irf_b[h]['gap'] for h in hs)
print(f"    normalized: trough gap {peak:+.2f} per unit surprise, funds-rate path area over 0-8q {area:+.2f} pp-quarters -> "
      f"{peak / area if area else float('nan'):+.3f} gap pp per pp-quarter of rate")
out['policy_irf_bs'] = irf_b
out['policy_irf_bs_norm'] = peak / area if area else None
# --- [4] Fiscal reaction (Auerbach 2002 form): the deficit's change on the SAME-quarter gap change (automatic
# stabilisers, which need no decision) and the LAGGED gap level (discretionary policy, which does) ---------------
PANDEMIC = lambda k: '2020-01' <= k <= '2021-10'
ngp = {q_of(k): v for k, v in D['NGDPPOT'].items()}
fq = lambda series: {q_of(k): v for k, v in series.items()}
recv, spend, intr = fq(D['FGRECPT']), fq(D['FGEXPND']), fq(D['A091RC1Q027SBEA'])
pdef = {k: 100 * ((spend[k] - intr[k]) - recv[k]) / ngp[k] for k in recv if k in spend and k in intr and k in ngp}
fk = sorted(k for k in pdef if k in gap)
for first, last in (('1960-01', '2019-10'), ('1985-01', '2019-10')):
    Y, X = [], []
    for i in range(2, len(fk)):
        k, p, pp = fk[i], fk[i - 1], fk[i - 2]
        if not (first <= k <= last) or PANDEMIC(k):
            continue
        Y.append(pdef[k] - pdef[p])
        X.append([1.0, gap[k] - gap[p], gap[pp], pdef[p]])
    b, se, r2, _ = ols(Y, X, nw_lags=4)
    print(f"[4] primary deficit (% of potential), {first}..{last} n {len(Y)}: automatic {b[1]:+.3f} ({se[1]:.3f}) per pp of gap change, "
          f"discretionary {b[2]:+.3f} ({se[2]:.3f}) per pp of lagged gap a quarter, persistence {b[3]:+.3f} ({se[3]:.3f})")
    out.setdefault('fiscal', {})[first[:4]] = {'auto': b[1], 'auto_se': se[1], 'disc': b[2], 'disc_se': se[2], 'persist': b[3], 'persist_se': se[3]}
gpot = {q_of(k): v for k, v in D['GDPPOT'].items()}
gce = {q_of(k): 100 * v / gpot[q_of(k)] for k, v in D['GCEC1'].items() if q_of(k) in gpot}
gk = sorted(k for k in gce if k in gap)
Y, X = [], []
for i in range(2, len(gk)):
    k, p, pp = gk[i], gk[i - 1], gk[i - 2]
    if not ('1960-01' <= k <= '2019-10'):
        continue
    Y.append(gce[k] - gce[p])
    X.append([1.0, gap[k] - gap[p], gap[pp], gce[p]])
b, se, r2, _ = ols(Y, X, nw_lags=4)
print(f"    government purchases (% of potential), 1960-2019: same-quarter {b[1]:+.3f} ({se[1]:.3f}), lagged gap {b[2]:+.3f} ({se[2]:.3f}), persistence {b[3]:+.3f} ({se[3]:.3f})")
out['purchases'] = {'same': b[1], 'same_se': se[1], 'lag': b[2], 'lag_se': se[2], 'persist': b[3], 'persist_se': se[3]}
# the level trends (defence drawdowns): the same regression with a linear trend reads the cyclical persistence
for first, last in (('1960-01', '2019-10'), ('1985-01', '2019-10')):
    Y, X = [], []
    for i in range(2, len(gk)):
        k, p, pp = gk[i], gk[i - 1], gk[i - 2]
        if not (first <= k <= last):
            continue
        Y.append(gce[k] - gce[p])
        X.append([1.0, gap[k] - gap[p], gap[pp], gce[p], i / 4.0])
    b, se, r2, res_ = ols(Y, X, nw_lags=4)
    print(f"      with trend {first[:4]}-{last[:4]}: same-quarter {b[1]:+.3f} ({se[1]:.3f}), lagged gap {b[2]:+.3f} ({se[2]:.3f}), persistence {b[3]:+.3f} ({se[3]:.3f}) = {-4 * math.log(1 + b[3]):.3f}/yr, residual sd {moments(res_)[1]:.3f}pp")
    out['purchases'][f'trend_{first[:4]}'] = {'lag': b[2], 'lag_se': se[2], 'persist': b[3], 'persist_se': se[3], 'resid_sd': moments(res_)[1]}
json.dump(out, open(os.path.join(H, 'policy_fit.json'), 'w'), indent=1)
print('wrote policy_fit.json')

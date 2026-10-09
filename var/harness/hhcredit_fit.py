"""Household credit growth equation, estimated on US data with regressors built exactly as the engine builds them.

    python3 var/harness/hhcredit_real.py   # once
    python3 var/harness/hhcredit_fit.py

Engine (CreditFiscalSubsystem::calculateHouseholdCredit): d ln(DTI)/dt = a*lift - b*(S - S_trend) - r*(rate - neutral)
+ d*gap - e*(DTI - 1) - f*max(0, DSRgap - 0.02). lift = EMA_0.25y(house price) / EMA_5y(that) - 1 on a stationary
(real) index; neutral = r* + target inflation + spreads; DSR = DTI x BIS annuity(effective rate, 18y), gap vs a 15y EMA.
"""
import json, math, os, re, zipfile, xml.etree.ElementTree as ET
from datetime import date, timedelta

H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('out = {}')].split('# --- 1. Premium dynamics')[0])
F = json.load(open(os.path.join(H, 'hhcredit_data.json')))['fred']


def q_of(k):
    y, m = int(k[:4]), int(k[5:7])
    return f"{y}-{(m - 1) // 3 * 3 + 1:02d}"


def qavg(series):
    acc = {}
    for k, v in series.items():
        acc.setdefault(q_of(k), []).append(v)
    return {k: sum(v) / len(v) for k, v in acc.items()}


# HLW one-sided r* (US), quarterly
z = zipfile.ZipFile(os.path.join(H, 'hlw.xlsx'))
ns = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
ss = [''.join(t.text or '' for t in si.findall('.//m:t', ns)) for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si', ns)]
rstar = {}
for r in ET.fromstring(z.read('xl/worksheets/sheet2.xml')).find('m:sheetData', ns):
    row = {}
    for c in r:
        v = c.find('m:v', ns)
        if v is not None:
            row[re.match(r'[A-Z]+', c.get('r')).group()] = ss[int(v.text)] if c.get('t') == 's' else v.text
    try:
        d = date(1899, 12, 30) + timedelta(days=int(float(row['A'])))
        rstar[q_of(d.isoformat()[:7])] = float(row['K']) / 100
    except (KeyError, ValueError):
        pass

debt = {q_of(k): v for k, v in F['CMDEBT'].items()}  # households and nonprofits, all credit market debt
income = qavg(F['DSPI'])
cpi = qavg(F['CPIAUCSL'])
hpi = {q_of(k): v / cpi[q_of(k)] for k, v in F['USSTHPI'].items() if q_of(k) in cpi}
mort = qavg({k[:7]: v for k, v in F['MORTGAGE30US'].items()}) if False else None
m_acc = {}
for k, v in F['MORTGAGE30US'].items():
    m_acc.setdefault(q_of(k), []).append(v)
mort = {k: sum(v) / len(v) / 100 for k, v in m_acc.items()}
ff = {k: v / 100 for k, v in qavg(F['FEDFUNDS']).items()}
sloos = {q_of(k): v / 100 for k, v in F['DRTSCILM'].items()}
gap = {q_of(k): v / F['GDPPOT'][k] - 1 for k, v in F['GDPC1'].items() if k in F['GDPPOT']}

qs = sorted(k for k in debt if k in income and k in hpi and k in mort and k in ff and k in rstar and k in gap)
MORT_SHARE, CONSUMER_SPREAD, MATURITY = 0.70, 0.08, 18.0
w = lambda h: 1 - math.exp(-0.25 / h)
ema_hp, trend_hp, dsr_trend = None, None, None
s_mean = sum(sloos.values()) / len(sloos)
s_trend = s_mean
rows = []
for k in qs:
    dti = (debt[k] / 1000.0) / income[k]  # Z.1 in millions, DSPI in billions
    eff = MORT_SHARE * mort[k] + (1 - MORT_SHARE) * (max(0.0, ff[k]) + CONSUMER_SPREAD)
    ann = eff / (1 - (1 + eff) ** (-MATURITY))
    dsr = dti * ann
    ema_hp = hpi[k] if ema_hp is None else ema_hp + w(0.25) * (hpi[k] - ema_hp)
    trend_hp = ema_hp if trend_hp is None else trend_hp + w(5.0) * (ema_hp - trend_hp)
    dsr_trend = dsr if dsr_trend is None else dsr_trend + w(15.0) * (dsr - dsr_trend)
    s = sloos.get(k)
    if s is not None:
        s_trend = s_trend + w(20.0) * (s - s_trend)
    rows.append({'q': k, 'dti': dti, 'lift': ema_hp / trend_hp - 1, 'sloos': (s - s_trend) if s is not None else None,
                 'rate': eff - rstar[k], 'gap': gap[k], 'dsr_excess': max(0.0, dsr - dsr_trend - 0.02), 'dsr_gap': dsr - dsr_trend})

PANDEMIC = {'2020-04', '2020-07', '2020-10', '2021-01', '2021-04'}


def estimate(first, last, use_sloos, extra=''):
    Y, X = [], []
    for i in range(1, len(rows)):
        r, p = rows[i], rows[i - 1]
        if not (first <= r['q'] <= last) or r['q'] in PANDEMIC or (use_sloos and p['sloos'] is None):
            continue
        Y.append(4 * math.log(r['dti'] / p['dti']))
        x = [1.0, p['lift']]
        if use_sloos:
            x.append(p['sloos'])
        x += [p['rate'], p['gap'], p['dti'], p['dsr_excess']]
        X.append(x)
    names = ['const', 'house price lift'] + (['SLOOS vs trend'] if use_sloos else []) + ['rate - r*', 'output gap', 'DTI level', 'DSR excess']
    b, se, r2, e = ols(Y, X, nw_lags=4)
    print(f"--- {first}..{last} {'with' if use_sloos else 'without'} SLOOS {extra}: n {len(Y)}, R2 {r2:.2f}, resid sd {moments(e)[1]:.4f}/yr-rate")
    for nm, bb, s_ in zip(names, b, se):
        print(f"    {nm:18s} {bb:+8.3f}  (se {s_:.3f}, t {bb / s_ if s_ else 0:+.1f})")
    return dict(zip(names, zip(b, se))), e


est, e1 = estimate('1990-10', '2019-10', True)
estimate('1976-01', '2019-10', False)
estimate('1990-10', '2026-04', True, '(incl. post-pandemic)')
print("DSR excess > 0 in", sum(1 for r in rows if r['dsr_excess'] > 0), "of", len(rows), "quarters; DSR gap range %.3f..%.3f" % (min(r['dsr_gap'] for r in rows), max(r['dsr_gap'] for r in rows)))
print("DTI range %.2f..%.2f" % (min(r['dti'] for r in rows), max(r['dti'] for r in rows)))


# --- The parsimonious model: the terms the data support, re-estimated without the ones they reject ------------
def reduced(first, last):
    Y, X, K = [], [], []
    for i in range(1, len(rows)):
        r, p = rows[i], rows[i - 1]
        if not (first <= r['q'] <= last) or r['q'] in PANDEMIC:
            continue
        Y.append(4 * math.log(r['dti'] / p['dti']))
        X.append([1.0, p['lift'], p['dti'], p['dsr_excess']])
        K.append(r['q'])
    b, se, r2, e = ols(Y, X, nw_lags=4)
    ar = ar1(e)
    print(f"=== reduced {first}..{last}: n {len(Y)}, R2 {r2:.2f}; lift {b[1]:+.3f} ({se[1]:.3f}), DTI {b[2]:+.3f} ({se[2]:.3f}) -> baseline {-b[0] / b[2]:.2f}, "
          f"DSR excess {b[3]:+.2f} ({se[3]:.2f}); resid sd {moments(e)[1]:.4f} (annualized-rate units), AR1 {ar:+.2f}")
    return b, se, e


b_long, se_long, e_long = reduced('1976-01', '2019-10')
b_short, se_short, _ = reduced('1990-10', '2019-10')
# innovation volatility: quarterly log change residual = e/4 per quarter; annual sd = that x sqrt(4)
sigma_q = moments(e_long)[1] / 4
print(f"credit growth noise: {sigma_q:.4f} per quarter -> {sigma_q * 2:.4f} per sqrt(year) (residual AR1 {ar1(e_long):+.2f})")
json.dump({'house_price': b_long[1], 'house_price_se': se_long[1], 'mean_reversion': -b_long[2], 'mean_reversion_se': se_long[2],
           'deleveraging': -b_long[3], 'deleveraging_se': se_long[3], 'baseline': -b_long[0] / b_long[2], 'sigma': sigma_q * 2},
          open(os.path.join(H, 'hhcredit_fit.json'), 'w'), indent=1)

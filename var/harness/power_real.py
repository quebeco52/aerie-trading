"""Wholesale power vs Henry Hub calibration (EIA): the macro power index's elasticity, heat-rate residual and seasonality.

Pulls EIA ICE daily on-peak prices (four gas-marginal hubs) and Henry Hub monthly spot, caches them to power_data.json,
then fits ln P = a + b ln HH, a first + second harmonic seasonal on the LEVEL profile, and a log-OU on the rest.
"""
import datetime as dt, io, json, math, os, re, statistics as st, sys, urllib.request, zipfile
from collections import defaultdict
HERE = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.join(HERE, 'power_data.json')
HUBS = ['PJM WH Real Time Peak', 'Nepool MH DA LMP Peak', 'SP15 EZ Gen DA LMP Peak', 'Palo Verde Peak']
YEARS = range(2017, 2026)


def pull():
    def cells(z):
        ss = [re.sub(r'<[^>]+>', '', m) for m in re.findall(r'<si>(.*?)</si>', z.read('xl/sharedStrings.xml').decode(), re.S)]
        for row in re.findall(r'<row[^>]*>(.*?)</row>', z.read('xl/worksheets/sheet1.xml').decode(), re.S):
            out = {}
            for ref, attrs, body in re.findall(r'<c r="([A-Z]+)\d+"([^>]*?)(?:/>|>(.*?)</c>)', row, re.S):
                v = re.search(r'<v>([^<]*)</v>', body)
                if v:
                    out[ref] = ss[int(v.group(1))] if 't="s"' in attrs else v.group(1)
            yield out
    daily = defaultdict(list)
    for y in YEARS:
        url = f'https://www.eia.gov/electricity/wholesale/xls/archive/ice_electric-{y}final.xlsx'
        z = zipfile.ZipFile(io.BytesIO(urllib.request.urlopen(url, timeout=60).read()))
        hdr = None
        for r in cells(z):
            if hdr is None:
                if 'Price hub' in r.values():
                    hdr = {v: k for k, v in r.items()}
                continue
            try:
                hub = r[hdr['Price hub']].strip(); d = float(r[hdr['Trade date']]); p = float(r[hdr['Wtd avg price $/MWh']])
            except (KeyError, ValueError):
                continue
            if hub in HUBS:
                day = dt.date(1899, 12, 30) + dt.timedelta(days=d)
                daily[f'{hub}|{day.year}-{day.month:02d}'].append(p)
    h = urllib.request.urlopen('https://www.eia.gov/dnav/ng/hist/rngwhhdM.htm', timeout=60).read().decode()
    hh = {}
    for y, body in re.findall(r"<td class='B4'>&nbsp;&nbsp;(\d{4})</td>(.*?)</tr>", h, re.S):
        for m, v in enumerate(re.findall(r"<td class='B3'>([0-9.]*)</td>", body)):
            if v and int(y) in YEARS:
                hh[f'{y}-{m + 1:02d}'] = float(v)
    power = {k: round(st.mean(v), 3) for k, v in daily.items()}
    json.dump({'source': 'EIA ICE on-peak daily (wtd avg) monthly means; EIA RNGWHHDm Henry Hub', 'hubs': HUBS, 'power': power, 'henry_hub': hh}, open(CACHE, 'w'), indent=0)


if not os.path.exists(CACHE) or '--pull' in sys.argv:
    pull()
data = json.load(open(CACHE))
months = [f'{y}-{m:02d}' for y in YEARS for m in range(1, 13)]
G = [data['henry_hub'][k] for k in months]
P = [st.mean(data['power'][f'{h}|{k}'] for h in HUBS) for k in months]
n = len(P); lg = [math.log(g) for g in G]; lp = [math.log(p) for p in P]
mx, my = st.mean(lg), st.mean(lp)
b = sum((x - mx) * (y - my) for x, y in zip(lg, lp)) / sum((x - mx) ** 2 for x in lg); a = my - b * mx
se = math.sqrt(sum((y - a - b * x) ** 2 for x, y in zip(lg, lp)) / (n - 2) / sum((x - mx) ** 2 for x in lg))
res = [y - a - b * x for x, y in zip(lg, lp)]
# Seasonal on the LEVEL profile: mean of exp(residual) by calendar month over the overall mean, then two harmonics.
lvl = [math.exp(r) for r in res]; mlvl = st.mean(lvl)
prof = [st.mean(lvl[i] for i in range(n) if i % 12 == k) / mlvl for k in range(12)]
def harmonic(k):
    c = sum((p - 1) * math.cos(2 * math.pi * k * (mth + 0.5) / 12) for mth, p in enumerate(prof)) * 2 / 12
    s = sum((p - 1) * math.sin(2 * math.pi * k * (mth + 0.5) / 12) for mth, p in enumerate(prof)) * 2 / 12
    return math.hypot(c, s), (math.atan2(s, c) / (2 * math.pi * k)) % (1.0 / k)
A1, T1 = harmonic(1); A2, T2 = harmonic(2)
season = lambda t: 1 + A1 * math.cos(2 * math.pi * (t - T1)) + A2 * math.cos(4 * math.pi * (t - T2))
des = [math.log(lvl[i] / season(((i % 12) + 0.5) / 12)) for i in range(n)]
m = st.mean(des); ac = sum((des[i] - m) * (des[i - 1] - m) for i in range(1, n)) / sum((v - m) ** 2 for v in des)
kappa = -math.log(ac) * 12; sd = st.pstdev(des)
q = [st.mean(des[i:i + 3]) for i in range(0, n, 3)]; mq = st.mean(q)
acq = sum((q[i] - mq) * (q[i - 1] - mq) for i in range(1, len(q))) / sum((v - mq) ** 2 for v in q)
print(f'4-hub on-peak vs Henry Hub, monthly {months[0]}..{months[-1]} (n={n})')
print(f'  ln P = {a:.3f} + {b:.3f} ln HH (se {se:.3f});  means: power ${st.mean(P):.2f}/MWh, HH ${st.mean(G):.2f}/MMBtu, spark@7.0 ${st.mean(p - 7 * g for p, g in zip(P, G)):.2f}')
print('  level seasonal profile Jan..Dec:', ' '.join(f'{p:.2f}' for p in prof))
print(f'  harmonics: A1 {A1:.3f} peak {T1:.3f} | A2 {A2:.3f} peaks {T2:.3f} & {T2 + 0.5:.3f};  fitted Jan..Dec:', ' '.join(f'{season((k + 0.5) / 12):.2f}' for k in range(12)))
print(f'  de-seasonalised log residual: sd {sd:.3f}, monthly AR1 {ac:.3f} -> kappa {kappa:.2f}/y (half-life {math.log(2) / kappa * 12:.1f} mo), sigma {sd * math.sqrt(2 * kappa):.3f}')
print(f'  quarterly-mean residual: sd {st.pstdev(q):.3f}, AR1 {acq:.2f}')

# A point OU averaged over a window h: AR1 of consecutive means = (1 - e^-x)^2 / (2 (x - 1 + e^-x)) with x = kappa h, and
# var(mean) = var(point) * 2 (x - 1 + e^-x) / x^2. Firms book quarters, so solve kappa and sigma from the QUARTERLY means.
def mean_ar1(x):
    return (1 - math.exp(-x)) ** 2 / (2 * (x - 1 + math.exp(-x)))
def solve_x(target):
    lo, hi = 1e-4, 50.0
    for _ in range(200):
        mid = (lo + hi) / 2
        lo, hi = (mid, hi) if mean_ar1(mid) > target else (lo, mid)
    return (lo + hi) / 2
xq = solve_x(acq); kq = xq / 0.25
var_point = st.pstdev(q) ** 2 * xq ** 2 / (2 * (xq - 1 + math.exp(-xq)))
sq = math.sqrt(2 * kq * var_point)
xm = kq / 12
print(f'  time-aggregated OU fitted to quarterly means: kappa {kq:.2f}/y (half-life {math.log(2) / kq * 12:.1f} mo), sigma {sq:.3f}, point log sd {math.sqrt(var_point):.3f}')
print(f'    implied monthly means: sd {math.sqrt(var_point * 2 * (xm - 1 + math.exp(-xm)) / xm ** 2):.3f} (data {sd:.3f}), AR1 {mean_ar1(xm):.2f} (data {ac:.2f})')
x9 = 8.99 / 4
print(f'    naive monthly fit (kappa 8.99, sigma 1.04) at quarters: sd {1.04 / math.sqrt(2 * 8.99) * math.sqrt(2 * (x9 - 1 + math.exp(-x9)) / x9 ** 2):.3f}, AR1 {mean_ar1(x9):.2f}')

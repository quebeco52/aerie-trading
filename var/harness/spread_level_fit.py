"""What moves the default-risk part of US corporate spreads: the gap's level, its change, or equity volatility?

    python3 var/harness/spread_level_fit.py

D = GZ spread - EBP (Gilchrist-Zakrajsek's predicted-default component), quarterly averages; HY OAS 1997+.
Regressions by OLS with Newey-West (4 lags) standard errors.
"""
import csv, json, math, os
H = os.path.dirname(os.path.abspath(__file__))
F = json.load(open(os.path.join(H, 'credit_data.json')))['fred']
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('# --- 1. Premium dynamics')])

def q(k): y, m = int(k[:4]), int(k[5:7]); return f"{y}-{(m - 1) // 3 * 3 + 1:02d}"
def qavg(series):
    acc = {}
    for k, v in series.items(): acc.setdefault(q(k), []).append(v)
    return {k: sum(v) / len(v) for k, v in acc.items()}
gz, ebp = {}, {}
for r in csv.DictReader(open(os.path.join(H, 'ebp.csv'))):
    m, _, y = r['date'].split('/'); k = f"{y}-{int(m):02d}-01"
    gz[k] = float(r['gz_spread']) / 100; ebp[k] = float(r['ebp']) / 100
GZ, EBP = qavg(gz), qavg(ebp)
D = {k: GZ[k] - EBP[k] for k in GZ if k in EBP}
HY = qavg({k: v / 100 for k, v in F['BAMLH0A0HYM2'].items()})
IG = qavg({k: v / 100 for k, v in F['BAMLC0A0CM'].items()})
VIX = qavg({k: v / 100 for k, v in F['VIXCLS'].items()})
gap = {q(k): v / F['GDPPOT'][k] - 1 for k, v in F['GDPC1'].items() if k in F['GDPPOT']}
qs = sorted(gap)
dgap = {qs[i]: gap[qs[i]] - gap[qs[i - 4]] for i in range(4, len(qs))}

def fit(name, Y, cols, keys):
    ks = [k for k in keys if all(k in c for c in cols.values()) and k in Y]
    X = [[1.0] + [cols[c][k] for c in cols] for k in ks]
    y = [Y[k] for k in ks]
    b, se, r2, _ = ols(y, X)
    print(f"{name:34s} n={len(ks):3d} R2 {r2:.2f} | " + '  '.join(f"{c} {bb*100:+.2f}({s*100:.2f})" for c, bb, s in zip(['const'] + list(cols), b, se)))
    return b, ks

logD = {k: math.log(v) for k, v in D.items() if v > 0}
lnvix = {k: math.log(v / 0.20) for k, v in VIX.items()}
print("log default component ln(GZ-EBP), coefficients x100 (se):")
fit('gap level', logD, {'gap': gap}, qs)
fit('gap level + 4q change', logD, {'gap': gap, 'dgap4': dgap}, qs)
fit('gap level + 4q change + lnVIX', logD, {'gap': gap, 'dgap4': dgap, 'lnVIX': lnvix}, qs)
fit('lnVIX only', logD, {'lnVIX': lnvix}, qs)
print("\nlog HY OAS:")
logHY = {k: math.log(v) for k, v in HY.items()}
fit('gap level', logHY, {'gap': gap}, qs)
fit('gap level + 4q change + lnVIX + EBP', logHY, {'gap': gap, 'dgap4': dgap, 'lnVIX': lnvix, 'ebp': EBP}, qs)
ratio = {k: HY[k] / IG[k] for k in HY if k in IG}
print("\n(FRED serves the ICE HY and IG OAS for the last three years only, so the HY/IG ratio cannot be fitted here.)")
print("\nlog default component, gap level and VIX only:")
fit('gap level + lnVIX', logD, {'gap': gap, 'lnVIX': lnvix}, qs)
fit('gap level + lnVIX, 1990-2007', logD, {'gap': gap, 'lnVIX': lnvix}, [k for k in qs if k < '2008'])
print("\nlog GZ spread (default part plus premium):")
logGZ = {k: math.log(v) for k, v in GZ.items() if v > 0}
fit('gap level + lnVIX + EBP', logGZ, {'gap': gap, 'lnVIX': lnvix, 'ebp': EBP}, qs)
print("\n2008-2012: quarter  gap   dgap4  VIX   D(GZ-EBP)  EBP   IG    HY   HY/IG")
for k in qs:
    if '2008-01' <= k <= '2012-10':
        print(f"  {k}  {100*gap[k]:+5.1f} {100*dgap.get(k,0):+5.1f} {100*VIX.get(k,0):5.1f}  {100*D.get(k,0):5.2f}  {100*EBP.get(k,0):+5.2f} {100*IG.get(k,0):5.2f} {100*HY.get(k,0):5.2f} {ratio.get(k,0):5.2f}")

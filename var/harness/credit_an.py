"""Real credit-cycle episodes: does the spread track the gap level, or spike and fade while the gap stays deep?"""
import json, math, os, csv
from datetime import date
H = os.path.dirname(__file__)
d = json.load(open(os.path.join(H, 'credit_data.json')))
F = d['fred']

def monthly(series):
    acc = {}
    for k, v in series.items():
        acc.setdefault(k[:7], []).append(v)
    return {k: sum(v) / len(v) for k, v in acc.items()}

ebp, gz = {}, {}
for r in csv.DictReader(open(os.path.join(H, 'ebp.csv'))):
    m, _, y = r['date'].split('/')
    key = f"{y}-{int(m):02d}"
    gz[key] = float(r['gz_spread']); ebp[key] = float(r['ebp'])
dr = {k: gz[k] - ebp[k] for k in gz}  # default-risk (predicted) component

# CBO gap, quarterly -> assign to the 3 months of the quarter
gap = {}
for k, v in F['GDPC1'].items():
    if k in F['GDPPOT']:
        g = 100 * (v / F['GDPPOT'][k] - 1)
        y, m = int(k[:4]), int(k[5:7])
        for i in range(3):
            gap[f"{y}-{m + i:02d}"] = g
baa = monthly(F['BAA10Y']); vix = monthly(F['VIXCLS']); ted = monthly(F['TEDRATE'])
sloos = {}
for k, v in F['DRTSCILM'].items():
    y, m = int(k[:4]), int(k[5:7])
    for i in range(3):
        sloos[f"{y}-{m + i:02d}"] = v

def row(k):
    f = lambda s: f"{s[k]:6.2f}" if k in s else '     .'
    return f"{k} gap{f(gap)} | GZ{f(gz)} EBP{f(ebp)} DR{f(dr)} | Baa{f(baa)} VIX{f(vix)} TED{f(ted)} SLOOS{f(sloos)}"

for a, b in [('1990-01', '1993-12'), ('2000-06', '2004-06'), ('2007-06', '2012-12'), ('2019-10', '2021-12')]:
    print(f"\n=== {a} .. {b}")
    y, m = int(a[:4]), int(a[5:7])
    while f"{y}-{m:02d}" <= b:
        k = f"{y}-{m:02d}"
        if m % 3 == 1:
            print(row(k))
        m += 1
        if m > 12: y, m = y + 1, 1

# Persistence: monthly AR(1) of EBP and the default-risk part, full sample
def ar1(s):
    ks = sorted(s)
    x = [s[k] for k in ks]
    mu = sum(x) / len(x)
    num = sum((x[i] - mu) * (x[i - 1] - mu) for i in range(1, len(x)))
    den = sum((v - mu) ** 2 for v in x[:-1])
    rho = num / den
    return rho, math.log(0.5) / math.log(rho), mu, math.sqrt(sum((v - mu) ** 2 for v in x) / len(x))
for name, s in [('EBP', ebp), ('default-risk', dr), ('GZ total', gz), ('Baa-10y', baa)]:
    rho, hl, mu, sd = ar1(s)
    print(f"{name:13s} monthly AR1 {rho:.3f}  half-life {hl:5.1f} months  ({-12*math.log(rho):.2f}/yr)  mean {mu:.2f} sd {sd:.2f}")

# How much of each component the gap LEVEL explains (monthly, overlap)
def ols(y, x):
    ks = [k for k in y if k in x]
    X = [x[k] for k in ks]; Y = [y[k] for k in ks]
    mx, my = sum(X) / len(X), sum(Y) / len(Y)
    b = sum((a - mx) * (c - my) for a, c in zip(X, Y)) / sum((a - mx) ** 2 for a in X)
    res = [c - my - b * (a - mx) for a, c in zip(X, Y)]
    r2 = 1 - sum(e * e for e in res) / sum((c - my) ** 2 for c in Y)
    return b, r2, len(ks)
for name, s in [('EBP', ebp), ('default-risk', dr), ('GZ total', gz), ('Baa-10y', baa), ('VIX', vix), ('TED', ted), ('SLOOS', sloos)]:
    b, r2, n = ols(s, gap)
    print(f"{name:13s} on gap level: slope {b:+.3f} per pp gap, R2 {r2:.2f}, n {n}")

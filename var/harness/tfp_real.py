"""Productivity shocks in US data: size, shape of the responses, and the absorption speeds the macro uses.

Shocks are Fernald's utilization-adjusted quarterly TFP growth (SF Fed), treated as exogenous innovations as in
Basu, Fernald & Kimball (2006). Responses are distributed lags over 0..K quarters:
  output (dY_prod, cumulated: the level of output), CBO output gap (GDPC1/GDPPOT), unemployment gap (UNRATE - NROU),
  core PCE inflation (with 4 own lags).
The engine absorbs a TFP level shock x into actual output through a second-order Pascal lag at kappa_y
(Solow 1960) and into potential through a first-order lag at kappa_p, so
  output(t) = 1 - (1 + kappa_y t) e^(-kappa_y t),   gap(t) = e^(-kappa_p t) - (1 + kappa_y t) e^(-kappa_y t),
fitted jointly by weighted least squares on the output and gap responses.

    python3 tfp_real.py        # uses tfp_data.json when present, else pulls SF Fed + FRED
"""
import io, json, math, os, re, statistics as st, urllib.request, zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.join(HERE, 'tfp_data.json')
K = int(os.environ.get("K", 20))


def pull():
    raw = urllib.request.urlopen('https://www.frbsf.org/wp-content/uploads/quarterly_tfp.xlsx', timeout=60).read()
    z = zipfile.ZipFile(io.BytesIO(raw))
    strings = [re.sub(r'<[^>]+>', '', m) for m in re.findall(r'<si>(.*?)</si>', z.read('xl/sharedStrings.xml').decode(), re.S)]
    fernald = {}
    for row in re.findall(r'<row[^>]*>(.*?)</row>', z.read('xl/worksheets/sheet2.xml').decode(), re.S):
        cells = {}
        for ref, attrs, val in re.findall(r'<c r="([A-Z]+)\d+"([^>]*?)(?:/>|>(.*?)</c>)', row, re.S):
            v = re.search(r'<v>(.*?)</v>', val or '')
            if v:
                cells[ref] = strings[int(v.group(1))] if 't="s"' in attrs else v.group(1)
        m = re.match(r'(\d{4}):Q(\d)', cells.get('A', ''))
        if m and all(c in cells for c in 'BEN'):
            fernald[f"{m.group(1)}-{(int(m.group(2)) - 1) * 3 + 1:02d}-01"] = {'y': float(cells['B']), 'h': float(cells['E']), 'tfpu': float(cells['N'])}
    fred = {}
    for s in ('GDPC1', 'GDPPOT', 'UNRATE', 'NROU', 'PCEPILFE'):
        lines = urllib.request.urlopen(f'https://fred.stlouisfed.org/graph/fredgraph.csv?id={s}', timeout=60).read().decode().splitlines()[1:]
        fred[s] = {r.split(',')[0]: float(r.split(',')[1]) for r in lines if r.split(',')[1] not in ('.', '')}
    return {'fernald': fernald, 'fred': fred}


d = json.load(open(CACHE)) if os.path.exists(CACHE) else pull()
if not os.path.exists(CACHE):
    json.dump(d, open(CACHE, 'w'))
F, R = d['fernald'], d['fred']


def quarterly(series):
    out = {}
    for k, v in series.items():
        y, m, _ = k.split('-')
        out.setdefault(f"{y}-{(int(m) - 1) // 3 * 3 + 1:02d}-01", []).append(v)
    return {k: st.mean(v) for k, v in out.items() if len(v) == 3}


def ols(X, y):
    n, k = len(y), len(X[0])
    M = [[sum(X[r][a] * X[r][b] for r in range(n)) for b in range(k)] + [1.0 if a == j else 0.0 for j in range(k)] for a in range(k)]
    for c in range(k):
        p = max(range(c, k), key=lambda r: abs(M[r][c])); M[c], M[p] = M[p], M[c]
        pv = M[c][c]; M[c] = [v / pv for v in M[c]]
        for r in range(k):
            if r != c:
                f = M[r][c]; M[r] = [a - f * b for a, b in zip(M[r], M[c])]
    inv = [r[k:] for r in M]
    xty = [sum(X[r][a] * y[r] for r in range(n)) for a in range(k)]
    b = [sum(inv[a][c] * xty[c] for c in range(k)) for a in range(k)]
    res = [y[r] - sum(X[r][a] * b[a] for a in range(k)) for r in range(n)]
    s2 = sum(v * v for v in res) / (n - k)
    return b, [[s2 * inv[a][c] for c in range(k)] for a in range(k)]


keys = sorted(F)
shock = {k: F[k]['tfpu'] / 4 for k in keys}   # quarterly % level innovation

# --- Size: TFP is a random walk at business-cycle horizons ---
lnA = [0.0]
for k in keys:
    lnA.append(lnA[-1] + shock[k] / 100)
sigma = {h: math.sqrt(st.pvariance([lnA[i + 4 * h] - lnA[i] for i in range(len(lnA) - 4 * h)]) / h) for h in (1, 2, 3, 5, 10)}
ann = [lnA[i + 4] - lnA[i] for i in range(0, len(lnA) - 4, 4)]
m, s = st.mean(ann), st.pstdev(ann)
print('innovation sd /sqrt(yr) by horizon: ' + '  '.join(f'{h}y {v * 100:.2f}%' for h, v in sigma.items()))
print(f'annual growth: mean {m * 100:.2f}%  sd {s * 100:.2f}%  kurtosis {st.mean([((a - m) / s) ** 4 for a in ann]):.2f}  max {max(ann) * 100:+.1f}%  min {min(ann) * 100:+.1f}%')

# --- Responses to a +1% TFP level shock ---
def lagged(target, cumulate):
    X, y = [], []
    ks = [k for k in keys if k in target]
    for i in range(K, len(ks)):
        if any(ks[i - j] not in shock for j in range(K + 1)):
            continue
        X.append([1.0] + [shock[ks[i - j]] for j in range(K + 1)])
        y.append(target[ks[i]])
    b, V = ols(X, y)
    irf, se = [], []
    for h in range(K + 1):
        idx = list(range(1, h + 2)) if cumulate else [h + 1]
        irf.append(sum(b[i] for i in idx))
        se.append(math.sqrt(sum(V[i][j] for i in idx for j in idx)))
    return irf, se


out_irf, out_se = lagged({k: F[k]['y'] / 4 for k in keys}, cumulate=True)          # output level, % per quarter summed
gap = {k: 100 * math.log(R['GDPC1'][k] / R['GDPPOT'][k]) for k in keys if k in R['GDPC1'] and k in R['GDPPOT']}
gap_irf, gap_se = lagged(gap, cumulate=False)
uq = quarterly(R['UNRATE'])
ugap = {k: uq[k] - R['NROU'][k] for k in keys if k in uq and k in R['NROU']}
u_irf, u_se = lagged(ugap, cumulate=False)

for name, irf, se in (('output', out_irf, out_se), ('CBO gap', gap_irf, gap_se), ('u gap', u_irf, u_se)):
    print(f'{name:8s} ' + ' '.join(f'q{h}:{irf[h]:+.2f}({se[h]:.2f})' for h in range(0, K + 1, 2)))

# --- Fit of the absorption speeds on the GAP response ---
# The gap is what the engine reads (Okun, Phillips, policy, firms). Output's level response rises and then falls
# back over five years, which no monotone absorption reproduces; it and the unemployment gap are reported as
# out-of-sample checks (the engine's Okun coefficient maps the gap into unemployment).
def pascal(k, t): return 1.0 - (1.0 + k * t) * math.exp(-k * t)
def supply_gap(ky, kp, t): return math.exp(-kp * t) - (1.0 + ky * t) * math.exp(-ky * t)
def loss(ky, kp):
    return sum(((gap_irf[h] - supply_gap(ky, kp, (h + 0.5) / 4)) / gap_se[h]) ** 2 for h in range(K + 1))
grid = [(ky / 20, kp / 100) for ky in range(4, 81) for kp in range(1, 201) if kp / 100 < ky / 20]
ky, kp = min(grid, key=lambda g: loss(*g))
print(f'fit on the gap: kappa_y {ky:.2f}/yr  kappa_p {kp:.2f}/yr  (weighted SSE {loss(ky, kp):.1f} on {K + 1} points)')
for h in range(0, K + 1, 2):
    t = (h + 0.5) / 4
    print(f'  q{h:2d}: gap {supply_gap(ky, kp, t):+.2f} vs {gap_irf[h]:+.2f}({gap_se[h]:.2f})   output {pascal(ky, t):+.2f} vs {out_irf[h]:+.2f}({out_se[h]:.2f})   u (Okun 0.5) {-0.5 * supply_gap(ky, kp, t):+.2f} vs {u_irf[h]:+.2f}({u_se[h]:.2f})')

json.dump({'sigma': sigma, 'kappa_y': ky, 'kappa_p': kp,
           'irf': {'output': [out_irf, out_se], 'gap': [gap_irf, gap_se], 'u': [u_irf, u_se]}},
          open(os.path.join(HERE, 'tfp_fit.json'), 'w'), indent=1)

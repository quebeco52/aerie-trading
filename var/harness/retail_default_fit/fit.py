"""Retail default Z: fit of a house-price gap term and a persistent OU residual factor (US 1991Q1-2019Q4).

Engine (CreditFiscalSubsystem::calculateRetailDefaultRate):
  Z = -(40 (u_ema - nairu) + 25 (pi_ema - 0.02) + 15 (max(0, IG_ema - 0.013) + max(0, TED_ema - 0.0015)) + 30 DSRgap)
  PD = Vasicek(Z; 0.025, rho 0.12); PD_ema = EMA_0.25y(PD).
Data:
  PD proxy = 0.70 DRSFRMACBS + 0.30 DRCLACBS (30+ day delinquency, all commercial banks), rescaled to mean 0.025.
  DSR built as the engine builds it (DTI x BIS annuity, 18y; hhcredit_data.json), gap vs 15y EMA. FRED TDSP now
  starts 2005, so it cannot carry a 15y trend into a 1991 sample.
  H = USSTHPI / CPI, H_ema = EMA_0.25y, H_trend = EMA_5y(H_ema), g = ln(H_ema / H_trend).
  IG proxy: BAA10Y re-centred so its sample median is 1.30% (BASE_CREDIT_SPREAD is the IG OAS median); robustness
  with GZ (Gilchrist-Zakrajsek) spread mapped to IG OAS at 0.78 (CREDIT_SPREAD_PREMIUM_LOADING docblock) and AAA10Y.
"""
import csv, json, math, os, sys
from statistics import NormalDist

H = os.path.dirname(os.path.abspath(__file__))
N = NormalDist()
RHO, PD_LRA = 0.12, 0.025
IG_MODE = os.environ.get('IG', 'baa')
FIRST, LAST = os.environ.get('FIRST', '1991-01'), os.environ.get('LAST', '2019-10')


def load(path):
    out = {}
    with open(path) as f:
        r = csv.DictReader(f)
        for row in r:
            for k, v in row.items():
                if k != 'observation_date' and v not in ('', '.'):
                    out.setdefault(k, {})[row['observation_date']] = float(v)
    return out


S = {}
for f in ('daily.csv', 'monthly.csv', 'quarterly.csv', 'quarterly,_end_of_period.csv'):
    S.update(load(os.path.join(H, f)))
F = json.load(open(os.path.join(H, '..', 'hhcredit_data.json')))['fred']


def mavg(series):  # daily -> monthly mean
    acc = {}
    for k, v in series.items():
        acc.setdefault(k[:7], []).append(v)
    return {k: sum(v) / len(v) for k, v in acc.items()}


def q_of(m):
    y, mm = int(m[:4]), int(m[5:7])
    return f"{y}-{(mm - 1) // 3 * 3 + 1:02d}"


def qavg(series):
    acc = {}
    for k, v in series.items():
        acc.setdefault(q_of(k[:7]), []).append(v)
    return {k: sum(v) / len(v) for k, v in acc.items()}


# --- monthly engine inputs ---
un = {k[:7]: v / 100 for k, v in S['UNRATE'].items()}
nrou = {q_of(k[:7]): v / 100 for k, v in S['NROU'].items()}
cpi_m = {k[:7]: v for k, v in S['CPIAUCSL'].items()}
infl = {}
for k, v in cpi_m.items():
    y = int(k[:4]); prev = f"{y - 1}{k[4:]}"
    if prev in cpi_m:
        infl[k] = v / cpi_m[prev] - 1
baa = mavg(S['BAA10Y']); aaa = mavg(S['AAA10Y']); ted = mavg(S['TEDRATE'])
gz = {}
with open(os.path.join(H, '..', 'ebp.csv')) as f:
    for row in csv.DictReader(f):
        m, d, y = row['date'].split('/')
        gz[f"{y}-{int(m):02d}"] = float(row['gz_spread'])


def median(xs):
    xs = sorted(xs); n = len(xs)
    return xs[n // 2] if n % 2 else 0.5 * (xs[n // 2 - 1] + xs[n // 2])


win = lambda d: {k: v for k, v in d.items() if '1986-01' <= k <= '2019-12'}
if IG_MODE == 'baa':
    shift = median(win(baa).values()) - 1.30
    ig = {k: (v - shift) / 100 for k, v in baa.items()}
elif IG_MODE == 'aaa':
    shift = median(win(aaa).values()) - 1.30
    ig = {k: (v - shift) / 100 for k, v in aaa.items()}
else:  # GZ mapped onto IG OAS at the engine's 0.78 loading
    mg = median(win(gz).values())
    ig = {k: (1.30 + 0.78 * (v - mg)) / 100 for k, v in gz.items()}
ted = {k: v / 100 for k, v in ted.items()}

# --- quarterly DSR (engine construction) and house price ---
debt = {q_of(k[:7]): v for k, v in F['CMDEBT'].items()}
income = qavg(F['DSPI'])
mort = {k: v / 100 for k, v in qavg(F['MORTGAGE30US']).items()}
ff = {k: v / 100 for k, v in qavg(F['FEDFUNDS']).items()}
cpi_q = qavg(S['CPIAUCSL'])
hpi = {q_of(k[:7]): v / cpi_q[q_of(k[:7])] for k, v in S['USSTHPI'].items() if q_of(k[:7]) in cpi_q}
if os.environ.get('HPI') == 'cs':
    hpi = {k: v / cpi_q[k] for k, v in qavg(S['CSUSHPINSA']).items() if k in cpi_q}

wq = lambda tau: 1 - math.exp(-0.25 / tau)
wm = lambda tau: 1 - math.exp(-1 / 12 / tau)
dsr_gap, hgap = {}, {}
dsr_tr = None
for k in sorted(debt):
    if k not in income or k not in mort or k not in ff:
        continue
    dti = (debt[k] / 1000.0) / income[k]
    eff = 0.70 * mort[k] + 0.30 * (max(0.0, ff[k]) + 0.08)
    dsr = dti * eff / (1 - (1 + eff) ** (-18.0))
    dsr_tr = dsr if dsr_tr is None else dsr_tr + wq(15.0) * (dsr - dsr_tr)
    dsr_gap[k] = dsr - dsr_tr
he = ht = None
for k in sorted(hpi):  # monthly steps on the quarterly level
    for _ in range(3):
        he = hpi[k] if he is None else he + wm(0.25) * (hpi[k] - he)
        ht = he if ht is None else ht + wm(float(os.environ.get("HTREND", "5"))) * (he - ht)
    hgap[k] = math.log(he / ht)

# --- monthly engine Z, PD and PD EMA ---
months = sorted(k for k in un if '1986-01' <= k <= '2019-12')
ue = pe = ige = te = None
pd_ema = None
Zq_raw, Zq_eff, parts = {}, {}, {}
for m in months:
    q = q_of(m)
    ue = un[m] if ue is None else ue + wm(0.25) * (un[m] - ue)
    pe = infl[m] if pe is None else pe + wm(0.25) * (infl[m] - pe)
    ige = ig[m] if ige is None else ige + wm(0.25) * (ig[m] - ige)
    te = ted[m] if te is None else te + wm(0.25) * (ted[m] - te)
    pu = 40 * (ue - nrou[q]); pi_ = 25 * (pe - 0.02)
    ps = 15 * (max(0.0, ige - 0.013) + max(0.0, te - 0.0015)); pd_ = 30 * dsr_gap[q]
    z = -(pu + pi_ + ps + pd_)
    pd = max(0.005, min(0.20, N.cdf((N.inv_cdf(PD_LRA) - math.sqrt(RHO) * z) / math.sqrt(1 - RHO))))
    pd_ema = pd if pd_ema is None else pd_ema + wm(0.25) * (pd - pd_ema)
    if int(m[5:7]) % 3 == 0:  # quarter end: delinquency is a quarter-end stock
        Zq_raw[q] = z
        Zq_eff[q] = (N.inv_cdf(PD_LRA) - math.sqrt(1 - RHO) * N.inv_cdf(pd_ema)) / math.sqrt(RHO)
        parts[q] = (-pu, -pi_, -ps, -pd_)

dm = {q_of(k[:7]): v for k, v in S['DRSFRMACBS'].items()}
dc = {q_of(k[:7]): v for k, v in S['DRCLACBS'].items()}
Q = sorted(k for k in dm if FIRST <= k <= LAST and k in dc and k in Zq_eff and k in hgap)
raw = {k: 0.70 * dm[k] + 0.30 * dc[k] for k in Q}
mu = sum(raw.values()) / len(raw)
pdo = {k: PD_LRA * raw[k] / mu for k in Q}
zobs = {k: (N.inv_cdf(PD_LRA) - math.sqrt(1 - RHO) * N.inv_cdf(pdo[k])) / math.sqrt(RHO) for k in Q}
ZE = Zq_raw if os.environ.get('ZE') == 'raw' else Zq_eff


# --- linear algebra ---
def solve(A, b):
    n = len(A); M = [row[:] + [b[i]] for i, row in enumerate(A)]
    for c in range(n):
        p = max(range(c, n), key=lambda r: abs(M[r][c])); M[c], M[p] = M[p], M[c]
        for r in range(n):
            if r != c:
                f = M[r][c] / M[c][c]
                M[r] = [M[r][j] - f * M[c][j] for j in range(n + 1)]
    return [M[i][n] / M[i][i] for i in range(n)]


def inv(A):
    n = len(A)
    cols = [solve(A, [1.0 if i == j else 0.0 for i in range(n)]) for j in range(n)]
    return [[cols[j][i] for j in range(n)] for i in range(n)]


def ols(Y, X, lags=4):
    n, k = len(Y), len(X[0])
    XtX = [[sum(X[t][i] * X[t][j] for t in range(n)) for j in range(k)] for i in range(k)]
    Xty = [sum(X[t][i] * Y[t] for t in range(n)) for i in range(k)]
    b = solve(XtX, Xty)
    e = [Y[t] - sum(b[i] * X[t][i] for i in range(k)) for t in range(n)]
    Ai = inv(XtX)
    s2 = sum(x * x for x in e) / (n - k)
    se_ols = [math.sqrt(s2 * Ai[i][i]) for i in range(k)]
    # Newey-West: S = G0 + sum_L w_L (G_L + G_L')
    Sm = [[0.0] * k for _ in range(k)]
    for L in range(lags + 1):
        w = 1.0 if L == 0 else 1 - L / (lags + 1)
        G = [[sum(e[t] * e[t - L] * X[t][i] * X[t - L][j] for t in range(L, n)) for j in range(k)] for i in range(k)]
        for i in range(k):
            for j in range(k):
                Sm[i][j] += w * (G[i][j] if L == 0 else G[i][j] + G[j][i])
    V = [[sum(Ai[i][a] * Sm[a][c] * Ai[c][j] for a in range(k) for c in range(k)) for j in range(k)] for i in range(k)]
    se_nw = [math.sqrt(V[i][i]) for i in range(k)]
    ybar = sum(Y) / n
    r2 = 1 - sum(x * x for x in e) / sum((y - ybar) ** 2 for y in Y)
    return b, se_nw, se_ols, e, r2


r = [zobs[k] - ZE[k] for k in Q]
g = [hgap[k] for k in Q]
print(f"sample {Q[0]}..{Q[-1]} n={len(Q)}  IG={IG_MODE}  ZE={'raw' if ZE is Zq_raw else 'eff (PD EMA)'}  "
      f"raw-delinquency mean {mu:.2f}%")
print(f"Z_obs mean {sum(zobs.values())/len(Q):+.3f} sd {math.sqrt(sum((zobs[k]-sum(zobs.values())/len(Q))**2 for k in Q)/len(Q)):.3f}; "
      f"Z_eng mean {sum(ZE[k] for k in Q)/len(Q):+.3f} sd {math.sqrt(sum((ZE[k]-sum(ZE[j] for j in Q)/len(Q))**2 for k in Q)/len(Q)):.3f}; "
      f"corr {sum((zobs[k]-sum(zobs.values())/len(Q))*(ZE[k]-sum(ZE[j] for j in Q)/len(Q)) for k in Q)/len(Q)/math.sqrt(sum((zobs[k]-sum(zobs.values())/len(Q))**2 for k in Q)/len(Q))/math.sqrt(sum((ZE[k]-sum(ZE[j] for j in Q)/len(Q))**2 for k in Q)/len(Q)):.3f}")
print(f"residual r: mean {sum(r)/len(r):+.3f} sd {math.sqrt(sum((x-sum(r)/len(r))**2 for x in r)/len(r)):.3f}; g mean {sum(g)/len(g):+.4f} sd {math.sqrt(sum((x-sum(g)/len(g))**2 for x in g)/len(g)):.4f}")

b, se, se_o, e, r2 = ols(r, [[1.0, x] for x in g])
print(f"\n[1] r = a + beta_h g:  a {b[0]:+.3f} ({se[0]:.3f})  beta_h {b[1]:+.3f} (NW4 {se[1]:.3f}, OLS {se_o[1]:.3f})  R2 {r2:.3f}")
b0, se0, _, e0, r20 = ols(r, [[0.0 + 1.0, ] for _ in g])
b_ni, se_ni, _, _, r2_ni = ols(r, [[x] for x in g])
print(f"    no intercept: beta_h {b_ni[0]:+.3f} (NW4 {se_ni[0]:.3f})")

# lead/lag check
for L in (-4, -2, 0, 2, 4, 8):
    idx = [i for i in range(len(Q)) if 0 <= i - L < len(Q)]
    bb, ss, _, _, rr = ols([r[i] for i in idx], [[1.0, g[i - L]] for i in idx])
    print(f"    g lagged {L:+d}q: beta {bb[1]:+.3f} ({ss[1]:.3f}) R2 {rr:.3f}")


def ar1(e, label):
    Y = e[1:]; X = [[1.0, x] for x in e[:-1]]
    bb, ss, so, eta, _ = ols(Y, X, lags=0)
    phi, sphi = bb[1], so[1]
    n = len(Y)
    phi_bc = phi + (1 + 3 * phi) / n  # Kendall (1954) small-sample bias correction
    sd_eta = math.sqrt(sum(x * x for x in eta) / (n - 2))
    out = {}
    for tag, p in (('OLS', phi), ('Kendall', phi_bc)):
        if 0 < p < 1:
            kap = -4 * math.log(p); skap = 4 * sphi / p
            s = sd_eta / math.sqrt(1 - p * p)
            # delta method for s on phi plus chi-square sampling of sd_eta
            ds_dphi = sd_eta * p / (1 - p * p) ** 1.5
            ss_ = math.sqrt((ds_dphi * sphi) ** 2 + (s / math.sqrt(2 * (n - 2))) ** 2)
            # EMA (tau 0.25y) correction: observed y = EMA(x); corr_y(h) = (lam e^{-k h} - k e^{-lam h})/(lam - k)
            lam = 4.0
            f = lambda k: (lam * math.exp(-k / 4) - k * math.exp(-lam / 4)) / (lam - k) - p
            lo, hi = 1e-4, 3.99
            if f(lo) > 0 > f(hi):
                for _ in range(80):
                    mid = 0.5 * (lo + hi)
                    lo, hi = (mid, hi) if f(mid) > 0 else (lo, mid)
                ks = 0.5 * (lo + hi)
                s_struct = s * math.sqrt((lam + ks) / lam)
            else:
                ks, s_struct = float('nan'), float('nan')
            out[tag] = (p, kap, skap, s, ss_, ks, s_struct)
            print(f"    {label} {tag:7s}: phi {p:.3f} ({sphi:.3f})  kappa {kap:.3f}/y ({skap:.3f})  half-life {math.log(2)/kap:.2f}y  "
                  f"s {s:.3f} ({ss_:.3f})  | EMA-corrected kappa {ks:.3f}, s {s_struct:.3f}")
        else:
            print(f"    {label} {tag}: phi {p:.3f} ({sphi:.3f}) -> nonstationary")
    return out


print("\n[2] AR(1) of residual after house term (e = r - a - beta_h g):")
ar1(e, 'with g ')
print("    for comparison, residual without the house term (r - mean):")
rm = [x - sum(r) / len(r) for x in r]
ar1(rm, 'no g   ')

# --- unconstrained diagnostic: Z_obs on the engine's own regressors (+ g) ---
print("\n[3] Diagnostic: Z_obs on the engine's channel contributions (coef 1 = engine is right):")
X = [[1.0] + list(parts[k]) for k in Q]
bb, ss, _, _, rr = ols([zobs[k] for k in Q], X)
print("    w/o g : " + "  ".join(f"{n} {bb[i]:+.2f}({ss[i]:.2f})" for i, n in enumerate(['c', 'u', 'pi', 'spr', 'dsr'])) + f"  R2 {rr:.3f}")
X = [[1.0] + list(parts[k]) + [hgap[k]] for k in Q]
bb, ss, _, _, rr = ols([zobs[k] for k in Q], X)
print("    with g: " + "  ".join(f"{n} {bb[i]:+.2f}({ss[i]:.2f})" for i, n in enumerate(['c', 'u', 'pi', 'spr', 'dsr', 'g'])) + f"  R2 {rr:.3f}")

# --- 2006-2012 decomposition ---
a, bh = b
print("\n[4] PD paths (scaled delinquency, engine PD_ema, engine + beta_h g [+a]):")
cdf = lambda z: N.cdf((N.inv_cdf(PD_LRA) - math.sqrt(RHO) * z) / math.sqrt(1 - RHO))
print("    q        PDobs   PDeng  PDeng+g  PDeng+a+g   g      u_part  pi_part spr_part dsr_part")
for k in Q:
    if '2006-01' <= k <= '2012-10':
        print(f"    {k}  {pdo[k]:.4f}  {cdf(ZE[k]):.4f}  {cdf(ZE[k] + bh * hgap[k]):.4f}  {cdf(ZE[k] + a + bh * hgap[k]):.4f}  "
              f"{hgap[k]:+.3f}  " + "  ".join(f"{p:+.3f}" for p in parts[k]))
peak = max((k for k in Q if '2008-01' <= k <= '2011-10'), key=lambda k: pdo[k])
pre = '2006-01'
print(f"    peak {peak}: obs rise {pdo[peak]-pdo[pre]:+.4f} from {pre}; engine {cdf(ZE[peak])-cdf(ZE[pre]):+.4f}; "
      f"engine+g {cdf(ZE[peak]+bh*hgap[peak])-cdf(ZE[pre]+bh*hgap[pre]):+.4f}")
print(f"    peak Z gap: Zobs {zobs[peak]:+.3f}  Zeng {ZE[peak]:+.3f}  beta_h g {bh*hgap[peak]:+.3f}")
json.dump({'Q': Q, 'zobs': [zobs[k] for k in Q], 'ze': [ZE[k] for k in Q], 'g': g, 'pdo': [pdo[k] for k in Q],
           'parts': [parts[k] for k in Q]}, open(os.path.join(H, f'series_{IG_MODE}' + os.environ.get('HTREND', '') + '.json'), 'w'))

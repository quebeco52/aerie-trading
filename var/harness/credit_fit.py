"""Fits the excess bond premium state and its loadings to real data. Writes credit_fit.json.

    python3 var/harness/credit_real.py   # once: FRED + Fed GZ file -> credit_data.json, ebp.csv
    python3 var/harness/credit_fit.py

Units: the premium P is the Gilchrist-Zakrajsek EBP (a spread, stored as a fraction); the gap is a fraction.
"""
import csv, json, math, os, random

H = os.path.dirname(__file__)
F = json.load(open(os.path.join(H, 'credit_data.json')))['fred']

# --- Series -------------------------------------------------------------------------------------------------
ebp, gz = {}, {}
for r in csv.DictReader(open(os.path.join(H, 'ebp.csv'))):
    m, _, y = r['date'].split('/')
    k = f"{y}-{int(m):02d}"
    gz[k] = float(r['gz_spread']) / 100
    ebp[k] = float(r['ebp']) / 100


def qkey(k):
    y, m = int(k[:4]), int(k[5:7])
    return f"{y}-{(m - 1) // 3 * 3 + 1:02d}"


def monthly(series):
    acc = {}
    for k, v in series.items():
        acc.setdefault(k[:7], []).append(v)
    return {k: sum(v) / len(v) for k, v in acc.items()}


def quarterly(series):
    acc = {}
    for k, v in series.items():
        acc.setdefault(qkey(k), []).append(v)
    return {k: sum(v) / len(v) for k, v in acc.items() if len(v) == 3}


gap = {k[:7]: v / F['GDPPOT'][k] - 1 for k, v in F['GDPC1'].items() if k in F['GDPPOT']}
qs = sorted(gap)
E, GZ = quarterly(ebp), quarterly(gz)
DR = {k: GZ[k] - E[k] for k in GZ}
VIX = quarterly({k: v / 100 for k, v in monthly(F['VIXCLS']).items()})
TED = quarterly({k: v / 100 for k, v in monthly(F['TEDRATE']).items()})
SLOOS = {k[:7]: v / 100 for k, v in F['DRTSCILM'].items()}


# --- Linear algebra -----------------------------------------------------------------------------------------
def inverse(A):
    p = len(A)
    M = [A[i][:] + [1.0 if i == j else 0.0 for j in range(p)] for i in range(p)]
    for c in range(p):
        pv = max(range(c, p), key=lambda r: abs(M[r][c]))
        M[c], M[pv] = M[pv], M[c]
        d = M[c][c]
        M[c] = [x / d for x in M[c]]
        for r in range(p):
            if r != c:
                f = M[r][c]
                M[r] = [a - f * b for a, b in zip(M[r], M[c])]
    return [row[p:] for row in M]


def ols(Y, X, nw_lags=0):
    """OLS with Newey-West (Bartlett) standard errors."""
    n, p = len(Y), len(X[0])
    XtX_inv = inverse([[sum(X[i][a] * X[i][b] for i in range(n)) for b in range(p)] for a in range(p)])
    Xty = [sum(X[i][a] * Y[i] for i in range(n)) for a in range(p)]
    b = [sum(XtX_inv[a][c] * Xty[c] for c in range(p)) for a in range(p)]
    e = [Y[i] - sum(b[j] * X[i][j] for j in range(p)) for i in range(n)]
    S = [[sum(X[i][a] * X[i][c] * e[i] * e[i] for i in range(n)) for c in range(p)] for a in range(p)]
    for L in range(1, nw_lags + 1):
        w = 1 - L / (nw_lags + 1)
        for a in range(p):
            for c in range(p):
                g = sum(X[i][a] * e[i] * X[i - L][c] * e[i - L] for i in range(L, n))
                g2 = sum(X[i - L][a] * e[i - L] * X[i][c] * e[i] for i in range(L, n))
                S[a][c] += w * (g + g2)
    V = [[sum(XtX_inv[a][k] * S[k][l] * XtX_inv[l][c] for k in range(p) for l in range(p)) for c in range(p)] for a in range(p)]
    my = sum(Y) / n
    r2 = 1 - sum(x * x for x in e) / sum((y - my) ** 2 for y in Y)
    return b, [math.sqrt(V[i][i]) for i in range(p)], r2, e


def moments(x):
    n = len(x)
    m = sum(x) / n
    sd = math.sqrt(sum((v - m) ** 2 for v in x) / n)
    return m, sd, sum((v - m) ** 3 for v in x) / n / sd ** 3, sum((v - m) ** 4 for v in x) / n / sd ** 4 - 3


def ar1(x):
    m = sum(x) / len(x)
    return sum((x[i] - m) * (x[i - 1] - m) for i in range(1, len(x))) / sum((v - m) ** 2 for v in x[:-1])


out = {}

# --- 1. Premium dynamics: monthly ARX  P_m = a + rho P_{m-1} + b dGap_m ---------------------------------------
dgap_m = {}
for i in range(1, len(qs)):
    y, m = int(qs[i][:4]), int(qs[i][5:7])
    for j in range(3):
        dgap_m[f"{y}-{m + j:02d}"] = (gap[qs[i]] - gap[qs[i - 1]]) / 3
mk = sorted(ebp)
Y, X, K = [], [], []
for i in range(1, len(mk)):
    if mk[i] in dgap_m:
        Y.append(ebp[mk[i]])
        X.append([1.0, ebp[mk[i - 1]], dgap_m[mk[i]]])
        K.append(mk[i])
b, se, r2, res = ols(Y, X, nw_lags=3)
rho = b[1]
kappa_m = -12 * math.log(rho)
beta = -b[2]
print(f"[1] monthly ARX: rho {rho:.3f} ({se[1]:.3f}) -> kappa {kappa_m:.2f}/yr, half-life {12 * math.log(2) / kappa_m:.1f} mo; "
      f"gap-speed beta {beta:.3f} ({se[2]:.3f}) = {beta * 100:.1f}bp per 1pp gap fall; R2 {r2:.2f}")

# Quarterly check of the gap-speed term (the gap is a quarterly series; monthly interpolation dilutes it).
Y, X = [], []
qe = sorted(k for k in E if k in gap)
for i in range(1, len(qe)):
    k, p = qe[i], qe[i - 1]
    Y.append(E[k])
    X.append([1.0, E[p], gap[k] - gap[p]])
bq, seq, r2q, _ = ols(Y, X, nw_lags=4)
print(f"    quarterly ARX: rho {bq[1]:.3f} ({seq[1]:.3f}), gap-speed beta {-bq[2]:.3f} ({seq[2]:.3f}) Newey-West, R2 {r2q:.2f}")
beta, beta_se = -bq[2], seq[2]

# --- 2. Crisis-onset jump: Aug -> Oct 2008, and the months it covers are left out of the background fit ---------
CRISIS_MONTHS = {'2008-09', '2008-10'}
crisis_jump = ebp['2008-10'] - ebp['2008-08']
bis = {}
for r in csv.DictReader(open(os.path.join(H, 'bis_credit_gap.csv'))):
    cols = {c.split(':')[0]: v.split(':')[0] for c, v in r.items() if v is not None}
    if cols['BORROWERS_CTY'] == 'US' and cols['CG_DTYPE'] == 'C' and cols['OBS_VALUE']:
        bis[cols['TIME_PERIOD']] = float(cols['OBS_VALUE']) / 100
us_gap_2007 = max(v for k, v in bis.items() if k.startswith('2007'))
print(f"[2] crisis jump (EBP Aug->Oct 2008) {crisis_jump * 100:+.2f}pp; BIS US credit gap 2007 peak {us_gap_2007 * 100:.1f} points")

# --- 3. Displaced lognormal premium: X = EBP + c follows a log-ARX. In LEVELS the shock size rises with the
# level (|resid| slope 0.18, se 0.035); c is estimated by profile maximum likelihood, Jacobian included.
m_res = sum(res) / len(res)
lag1 = sum((res[i] - m_res) * (res[i - 1] - m_res) for i in range(1, len(res))) / sum((x - m_res) ** 2 for x in res)
print(f"    monthly residual lag-1 autocorrelation {lag1:+.3f}: bond-sampling noise, so shocks are fitted quarterly")
CRISIS_QUARTERS = {'2008-07', '2008-10'}


def log_arx(c):
    Y, X, K = [], [], []
    for i in range(1, len(qe)):
        k, p = qe[i], qe[i - 1]
        if k in CRISIS_QUARTERS or p in CRISIS_QUARTERS:
            continue
        Y.append(math.log(E[k] + c))
        X.append([1.0, math.log(E[p] + c), gap[k] - gap[p]])
        K.append(p)
    b, se, _, e = ols(Y, X, nw_lags=4)
    s2 = sum(v * v for v in e) / len(e)
    loglik = -0.5 * len(e) * (math.log(2 * math.pi * s2) + 1) - sum(Y)
    return loglik, b, se, e, K


grid = [x / 10000 for x in range(80, 401)]
profile = [(log_arx(c)[0], c) for c in grid]
ll_max, c_hat = max(profile)
inside = [c for ll, c in profile if ll >= ll_max - 1.92]
_, bl, sel, rl, KL = log_arx(c_hat)
rho_log, beta_log, beta_log_se = bl[1], -bl[2], sel[2]
lag_level = [math.log(E[k] + c_hat) for k in KL]
bh, seh, _, _ = ols([abs(v) for v in rl], [[1.0, v] for v in lag_level], nw_lags=4)
_, l_sd, l_skew, l_kurt = moments(rl)
mu_log = sum(math.log(E[k] + c_hat) for k in qe if k not in CRISIS_QUARTERS) / len([k for k in qe if k not in CRISIS_QUARTERS])
print(f"[3] displacement c {c_hat * 100:.2f}pp (95% profile CI {min(inside) * 100:.2f}-{max(inside) * 100:.2f}); log-ARX rho {rho_log:.3f}, "
      f"gap speed {beta_log:.2f} ({beta_log_se:.2f}) log points per unit fall; log residual sd {l_sd:.3f} skew {l_skew:+.2f} exkurt {l_kurt:.2f}")
print(f"    homoskedastic in logs: |resid| on lagged log level {bh[1]:+.3f} ({seh[1]:.3f}); mean log level {mu_log:.4f}")
r_bg = rl
Phi = lambda z: 0.5 * math.erfc(-z / math.sqrt(2))


def emg_pdf(x, s, eta):
    """Density of N(0, s^2) + Exp(eta)."""
    z = x / s - eta * s
    if z < -30:
        return 0.0
    return eta * math.exp(eta * eta * s * s / 2 - eta * x) * Phi(z)


def nll(theta):
    """Merton (1976) compound Poisson in log space, exact over the jump count: Gaussian core + N(muJ, sJ^2) jumps."""
    s, lam_q, mu_j, s_j = math.exp(theta[0]), math.exp(theta[1]), theta[2], math.exp(theta[3])
    tot = 0.0
    for x in r_bg:
        acc, pn = 0.0, math.exp(-lam_q)
        for k in range(25):
            if k > 0:
                pn *= lam_q / k
            var = s * s + k * s_j * s_j
            acc += pn * math.exp(-(x - k * mu_j) ** 2 / (2 * var)) / math.sqrt(2 * math.pi * var)
        tot -= math.log(max(acc, 1e-300))
    return tot


def nelder_mead(f, x0, step=0.3, iters=4000, tol=1e-10):
    n = len(x0)
    pts = [x0[:]] + [[x0[j] + (step if j == i else 0.0) for j in range(n)] for i in range(n)]
    vals = [f(p) for p in pts]
    for _ in range(iters):
        order = sorted(range(n + 1), key=lambda i: vals[i])
        pts, vals = [pts[i] for i in order], [vals[i] for i in order]
        if abs(vals[-1] - vals[0]) < tol:
            break
        c = [sum(p[j] for p in pts[:-1]) / n for j in range(n)]
        xr = [c[j] + (c[j] - pts[-1][j]) for j in range(n)]
        fr = f(xr)
        if fr < vals[0]:
            xe = [c[j] + 2 * (c[j] - pts[-1][j]) for j in range(n)]
            fe = f(xe)
            pts[-1], vals[-1] = (xe, fe) if fe < fr else (xr, fr)
        elif fr < vals[-2]:
            pts[-1], vals[-1] = xr, fr
        else:
            xc = [c[j] + 0.5 * (pts[-1][j] - c[j]) for j in range(n)]
            fc = f(xc)
            if fc < vals[-1]:
                pts[-1], vals[-1] = xc, fc
            else:
                pts = [pts[0]] + [[pts[0][j] + 0.5 * (p[j] - pts[0][j]) for j in range(n)] for p in pts[1:]]
                vals = [vals[0]] + [f(p) for p in pts[1:]]
    return pts[0], vals[0]


best = None
for start in [[math.log(0.10), math.log(0.1), 0.0, math.log(0.25)], [math.log(0.12), math.log(0.05), 0.1, math.log(0.3)],
              [math.log(0.08), math.log(0.3), 0.0, math.log(0.15)], [math.log(0.11), math.log(0.02), 0.2, math.log(0.4)]]:
    theta, v = nelder_mead(nll, start)
    theta, v = nelder_mead(nll, theta, step=0.05)
    if best is None or v < best[1]:
        best = (theta, v)
theta = best[0]
q_core = math.exp(theta[0])
lam = 4 * math.exp(theta[1])
jump_mean, jump_sd = theta[2], math.exp(theta[3])
gauss_nll = len(r_bg) * (0.5 * math.log(2 * math.pi * l_sd ** 2) + 0.5)
lr = 2 * (gauss_nll - best[1])
print(f"[3b] log-space shocks, Merton MLE: core sd {q_core:.3f}/q, jumps {lam:.2f}/yr of N({jump_mean:+.3f}, {jump_sd:.3f}^2) log points; "
      f"LR vs Gaussian {lr:.1f} (chi2(3) 5% = 7.81)")
use_jumps = lr > 7.81


# --- 4. Continuous time, by indirect inference: quarterly AVERAGES of the continuous displaced log-OU must give the
# data's log-ARX residual sd and the log premium's own local projection. kappa is picked on the projection.
def simulate(kappa, sigma, lam, j_sd, years, seed, steps=90):
    rnd = random.Random(seed)
    dt = 1 / (4 * steps)
    y, series = mu_log, []
    for _ in range(years * 4):
        acc = 0.0
        for _ in range(steps):
            j = rnd.gauss(0.0, j_sd) if lam > 0 and rnd.random() < lam * dt else 0.0
            y += kappa * (mu_log - y) * dt + sigma * math.sqrt(dt) * rnd.gauss(0, 1) + j
            acc += math.exp(y) - c_hat
        series.append(math.log(acc / steps + c_hat))
    return series


def resid_sd(series):
    Y = series[1:]
    X = [[1.0, v] for v in series[:-1]]
    _, _, _, e = ols(Y, X)
    return moments(e)[1]


def lp_self(series):
    n = len(series)
    Y, X, idx = [], [], []
    for i in range(2, n):
        Y.append(series[i])
        X.append([1.0, series[i - 1], series[i - 2]])
        idx.append(i)
    _, _, _, e = ols(Y, X)
    inn_s = dict(zip(idx, e))
    out_s = []
    for h in [1, 2, 3, 4, 6, 8]:
        Y, X = [], []
        for i in range(2, n - h):
            Y.append(series[i + h])
            X.append([1.0, inn_s[i], series[i - 1]])
        bb, _, _, _ = ols(Y, X)
        out_s.append(bb[1])
    return out_s


data_lp = lp_self([math.log(E[k] + c_hat) for k in qe if k not in CRISIS_QUARTERS])
jl = lam if use_jumps else 0.0
fits = []
for kap in [0.8, 1.0, 1.2, 1.4, 1.6, 1.8, 2.0]:
    scale = 1.0
    sig = q_core * 2.0 if use_jumps else l_sd * 2.0
    for it in range(5):
        ssd = resid_sd(simulate(kap, sig * scale, jl, jump_sd * scale, 800, 11 + it))
        scale *= l_sd / ssd
    sim_lp = lp_self(simulate(kap, sig * scale, jl, jump_sd * scale, 1500, 5))
    dist = sum((a - b) ** 2 for a, b in zip(sim_lp, data_lp))
    fits.append((dist, kap, sig * scale, scale, sim_lp))
    print(f"     kappa {kap}: sigma {sig * scale:.3f}, jump sd {jump_sd * scale:.3f}, own response {[round(v, 2) for v in sim_lp]} (distance {dist:.4f})")
dist, kappa, sigma, scale, _ = min(fits)
jump_sd_ct = jump_sd * scale
print(f"[4] data's own response {[round(v, 2) for v in data_lp]} -> kappa {kappa}/yr, sigma {sigma:.3f}/sqrt(yr), jumps {'kept' if use_jumps else 'rejected'} ({jl:.2f}/yr, sd {jump_sd_ct:.3f})")
sim = [math.exp(v) - c_hat for v in simulate(kappa, sigma, jl, jump_sd_ct, 3000, 99)]
ex = [E[k] for k in sorted(E) if not ('2008-07' <= k <= '2009-04')]
dm, dsd, dsk, dku = moments(ex)
sm, psd, psk, pku = moments(sim)
print(f"    validation, premium LEVEL ex-GFC: data mean {dm * 100:+.2f}pp sd {dsd * 100:.2f} skew {dsk:+.2f} exkurt {dku:.2f} min {min(ex) * 100:+.2f}; "
      f"sim mean {sm * 100:+.2f}pp sd {psd * 100:.2f} skew {psk:+.2f} exkurt {pku:.2f} p0.1 {sorted(sim)[len(sim) // 1000] * 100:+.2f}")

# --- 5. Loadings on the premium ------------------------------------------------------------------------------
Y, X = [], []
for k in qs:
    if k in VIX and k in E:
        Y.append(math.log(VIX[k] / 0.15))
        X.append([1.0, -gap[k], E[k]])
bv, sev, r2v, _ = ols(Y, X, nw_lags=4)
print(f"[5] ln(VIX/15%): gap level {bv[1]:.2f} ({sev[1]:.2f}), premium {bv[2]:.1f} ({sev[2]:.1f}), R2 {r2v:.2f}")

Y, X = [], []
for k in qs:
    if k in TED and k in E:
        Y.append(TED[k])
        X.append([1.0, max(0.0, E[k])])
bt, set_, r2t, _ = ols(Y, X, nw_lags=4)
print(f"[6] TED: {bt[1]:.3f} ({set_[1]:.3f}) per unit positive premium, R2 {r2t:.2f}")

Y, X = [], []
for k in qs:
    if k in SLOOS and k in E:
        Y.append(SLOOS[k])
        X.append([1.0, E[k], gap[k]])
bs, ses, r2s, _ = ols(Y, X, nw_lags=4)
print(f"[7] SLOOS static: premium {bs[1]:.1f} ({ses[1]:.1f}), gap {bs[2]:+.2f} ({ses[2]:.2f}), R2 {r2s:.2f}")
sk = sorted(k for k in SLOOS if k in E)
Y, X = [], []
for i in range(1, len(sk)):
    Y.append(SLOOS[sk[i]])
    X.append([1.0, SLOOS[sk[i - 1]], E[sk[i]]])
bpa, sepa, r2pa, _ = ols(Y, X, nw_lags=4)
sloos_kappa = -4 * math.log(bpa[1])
sloos_long_run = bpa[2] / (1 - bpa[1])
print(f"    partial adjustment: AR {bpa[1]:.3f} ({sepa[1]:.3f}) -> kappa {sloos_kappa:.2f}/yr, impact {bpa[2]:.1f} ({sepa[2]:.1f}), long-run {sloos_long_run:.1f}, R2 {r2pa:.2f}")

Y, X = [], []
for k in qs:
    if k in DR and k in VIX:
        Y.append(DR[k])
        X.append([1.0, -gap[k], max(0.0, VIX[k] - 0.20)])
bd, sed, r2d, _ = ols(Y, X, nw_lags=4)
gz_sorted = sorted(gz.values())
gz_median = gz_sorted[len(gz_sorted) // 2]
IG_PEAK_2008, IG_MEDIAN = 0.062, 0.013  # ICE BofA US Corporate OAS: Dec-2008 peak (~620bp), long-run median (MacroEngine::BASE_CREDIT_SPREAD)
gz_to_ig = (IG_PEAK_2008 - IG_MEDIAN) / (max(gz.values()) - gz_median)
print(f"[8] GZ default-risk part: gap {bd[1]:.3f} ({sed[1]:.3f}), vol above 20% {bd[2]:.3f} ({sed[2]:.3f}), R2 {r2d:.2f}; "
      f"GZ->IG scale {gz_to_ig:.2f} -> IG vol leg {bd[2] * gz_to_ig:.3f}, IG premium loading {gz_to_ig:.2f}")

# --- 9. Local projection: CBO gap response to a +1pp premium innovation (gap ordered first) ------------------
qk = [k for k in qs if k in E]
inn = {}
Y, X, KK = [], [], []
for i in range(2, len(qk)):
    k, p, pp = qk[i], qk[i - 1], qk[i - 2]
    Y.append(E[k])
    X.append([1.0, E[p], E[pp], gap[k], gap[p], gap[pp]])
    KK.append(k)
bi, _, _, ei = ols(Y, X)
inn = dict(zip(KK, ei))
irf = {}
for h in [0, 1, 2, 4, 6, 8, 10, 12, 16]:
    Y, X = [], []
    for i in range(2, len(qk) - h):
        k = qk[i]
        if k in inn:
            Y.append(100 * (gap[qk[i + h]] - gap[qk[i - 1]]))
            X.append([1.0, 100 * inn[k], 100 * gap[qk[i - 1]], 100 * (gap[qk[i - 1]] - gap[qk[i - 2]]), 100 * E[qk[i - 1]]])
    bh, seh, _, _ = ols(Y, X, nw_lags=h + 1)
    irf[h] = (bh[1], seh[1])
print("[9] gap IRF to +1pp premium innovation (pp, Newey-West se): " + ", ".join(f"{h}q {m:+.2f}({s:.2f})" for h, (m, s) in irf.items()))

out = {
    'displacement': c_hat, 'mu_log': mu_log, 'kappa': kappa, 'sigma_log': sigma, 'beta_log': beta_log, 'beta_log_se': beta_log_se,
    'jumps': use_jumps, 'jump_lambda': jl, 'jump_sd_log': jump_sd_ct, 'jump_mean_log': jump_mean,
    'crisis_jump': crisis_jump, 'us_credit_gap_2007': us_gap_2007,
    'vol_gap': bv[1], 'vol_gap_se': sev[1], 'vol_premium': bv[2], 'vol_premium_se': sev[2],
    'ted_premium': bt[1], 'ted_premium_se': set_[1],
    'sloos_premium_static': bs[1], 'sloos_gap': bs[2], 'sloos_gap_se': ses[2],
    'sloos_kappa': sloos_kappa, 'sloos_premium_long_run': sloos_long_run,
    'dr_gap': bd[1], 'dr_vol': bd[2], 'dr_vol_se': sed[2], 'gz_to_ig': gz_to_ig,
    'irf': {str(h): list(v) for h, v in irf.items()},
    'data_ebp_ex_gfc': {'sd': dsd, 'skew': dsk, 'exkurt': dku},
}
json.dump(out, open(os.path.join(H, 'credit_fit.json'), 'w'), indent=1)
print('wrote credit_fit.json')

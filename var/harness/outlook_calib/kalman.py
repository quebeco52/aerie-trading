"""Local level with deterministic drift (and optional regressors) plus white noise, profiled on the signal-to-noise ratio.

y_t = level_t + sum_j beta_j X_jt + eps_t,   eps ~ N(0, r)
level_{t+1} = level_t + drift * exp(-fade * t) + eta_t,   eta ~ N(0, q)

State a = [level, drift, beta_1..]; level, drift and betas start diffuse (large variance), and the first len(a)
observations are dropped from the likelihood. The filter runs with r = 1 and q = ratio, so r is concentrated out.
"""
import math, random

KAPPA = 1e7


def filter_profile(y, ratio, X=(), fade=0.0):
    """Return (sum log F, sum v^2/F, n) over the non-diffuse observations; y may hold None for missing years."""
    m = 2 + len(X)
    a = [0.0] * m
    P = [[KAPPA if i == j else 0.0 for j in range(m)] for i in range(m)]
    slogf = 0.0
    ssq = 0.0
    n = 0
    seen = 0
    for t, yt in enumerate(y):
        if yt is not None:
            Z = [1.0, 0.0] + [x[t] for x in X]
            PZ = [sum(P[i][j] * Z[j] for j in range(m)) for i in range(m)]
            F = sum(Z[i] * PZ[i] for i in range(m)) + 1.0
            v = yt - sum(Z[i] * a[i] for i in range(m))
            K = [pz / F for pz in PZ]
            a = [a[i] + K[i] * v for i in range(m)]
            P = [[P[i][j] - K[i] * PZ[j] for j in range(m)] for i in range(m)]
            seen += 1
            if seen > m:
                slogf += math.log(F)
                ssq += v * v / F
                n += 1
        # transition: level += drift * g_t
        g = math.exp(-fade * t)
        a[0] = a[0] + g * a[1]
        # P <- T P T' + Q with T = I + g e0 e1'
        row0 = [P[0][j] + g * P[1][j] for j in range(m)]
        P[0] = row0
        for i in range(m):
            P[i][0] = P[i][0] + g * P[i][1]
        P[0][0] += ratio
    return slogf, ssq, n


def profile_ll(y, ratio, X=(), fade=0.0):
    """Concentrated log likelihood and the ML noise variance r at a given q/r."""
    slogf, ssq, n = filter_profile(y, ratio, X, fade)
    if n == 0:
        return float('-inf'), 0.0
    r = max(ssq / n, 1e-300)
    return -0.5 * (slogf + n * math.log(r) + n), r


RATIO_GRID = [0.0] + [10 ** (k / 10.0) for k in range(-30, 31)]  # q/r from 0.001 to 1000


def fit_pooled(series, X=(), fade=0.0, grid=RATIO_GRID):
    """Common q/r across series, each its own r. Returns (ratio, ll, [r_i], profile list)."""
    best = None
    prof = []
    for ratio in grid:
        tot = 0.0
        rs = []
        for y in series:
            ll, r = profile_ll(y, ratio, X, fade)
            tot += ll
            rs.append(r)
        prof.append((ratio, tot))
        if best is None or tot > best[1]:
            best = (ratio, tot, rs)
    return best[0], best[1], best[2], prof


def ratio_interval(prof, drop=1.92):
    """Profile-likelihood 95% interval on the ratio (chi2(1)/2 = 1.92)."""
    top = max(ll for _, ll in prof)
    inside = [rt for rt, ll in prof if ll >= top - drop]
    return min(inside), max(inside)


def steady_gain(ratio):
    """Annual steady-state Kalman gain of the local level filter at q/r."""
    if ratio <= 0:
        return 0.0
    p = (ratio + math.sqrt(ratio * ratio + 4 * ratio)) / 2.0  # prior variance / r
    return p / (p + 1.0)


def half_life(ratio):
    k = steady_gain(ratio)
    if k <= 0:
        return float('inf')
    if k >= 1:
        return 0.0
    return math.log(0.5) / math.log(1.0 - k)


def moment_q(y):
    """sigma_eta^2 = gamma0 + 2 gamma1 of the demeaned first differences (MA(1) identity); can be negative."""
    d = [y[t] - y[t - 1] for t in range(1, len(y)) if y[t] is not None and y[t - 1] is not None]
    n = len(d)
    mu = sum(d) / n
    e = [x - mu for x in d]
    g0 = sum(x * x for x in e) / n
    g1 = sum(e[t] * e[t - 1] for t in range(1, n)) / n
    return g0 + 2 * g1, -g1


def simulate(n, q, r, drift, rng, missing=()):
    lvl = 0.0
    out = []
    for t in range(n):
        out.append(None if t in missing else lvl + rng.gauss(0, math.sqrt(r)))
        lvl += drift + rng.gauss(0, math.sqrt(q))
    return out

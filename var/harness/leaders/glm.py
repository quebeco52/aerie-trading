"""Pure-Python binomial GLM (logit or cloglog link) fitted by Newton-Raphson, with model-based standard errors.

fit(X, y, link='logit') -> dict(beta, se, z, ll, n, events). X rows include the constant column if wanted.
"""
import math


def _inv(a):
    n = len(a)
    m = [row[:] + [1.0 if i == j else 0.0 for j in range(n)] for i, row in enumerate(a)]
    for c in range(n):
        p = max(range(c, n), key=lambda r: abs(m[r][c]))
        if abs(m[p][c]) < 1e-14:
            raise ValueError('singular information matrix (column %d)' % c)
        m[c], m[p] = m[p], m[c]
        piv = m[c][c]
        m[c] = [v / piv for v in m[c]]
        for r in range(n):
            if r != c and m[r][c] != 0.0:
                f = m[r][c]
                m[r] = [vr - f * vc for vr, vc in zip(m[r], m[c])]
    return [row[n:] for row in m]


def _mu_and_derivs(eta, link):
    if link == 'logit':
        if eta >= 0:
            e = math.exp(-eta); mu = 1.0 / (1.0 + e)
        else:
            e = math.exp(eta); mu = e / (1.0 + e)
        dmu = mu * (1.0 - mu)
    else:  # cloglog: mu = 1 - exp(-exp(eta))
        eta = min(eta, 30.0)
        ee = math.exp(eta)
        mu = 1.0 - math.exp(-ee)
        dmu = ee * math.exp(-ee)
    mu = min(max(mu, 1e-12), 1 - 1e-12)
    return mu, dmu


def fit(X, y, link='logit', iters=100, tol=1e-10):
    k = len(X[0])
    beta = [0.0] * k
    # sensible start for the intercept
    ybar = sum(y) / len(y)
    if link == 'logit':
        beta[0] = math.log(ybar / (1 - ybar))
    else:
        beta[0] = math.log(-math.log(1 - ybar))
    ll_old = None
    for _ in range(iters):
        info = [[0.0] * k for _ in range(k)]
        score = [0.0] * k
        ll = 0.0
        for xi, yi in zip(X, y):
            eta = sum(b * v for b, v in zip(beta, xi))
            mu, dmu = _mu_and_derivs(eta, link)
            ll += yi * math.log(mu) + (1 - yi) * math.log(1 - mu)
            w = dmu * dmu / (mu * (1 - mu))
            r = (yi - mu) * dmu / (mu * (1 - mu))
            for a in range(k):
                score[a] += r * xi[a]
                wa = w * xi[a]
                for b in range(a, k):
                    info[a][b] += wa * xi[b]
        for a in range(k):
            for b in range(a):
                info[a][b] = info[b][a]
        cov = _inv(info)
        step = [sum(cov[a][b] * score[b] for b in range(k)) for a in range(k)]
        beta = [b + s for b, s in zip(beta, step)]
        if ll_old is not None and abs(ll - ll_old) < tol:
            break
        ll_old = ll
    se = [math.sqrt(cov[a][a]) for a in range(k)]
    return {'beta': beta, 'se': se, 'z': [b / s for b, s in zip(beta, se)], 'll': ll, 'n': len(y), 'events': sum(y)}


def report(res, names, title=''):
    out = [title] if title else []
    out.append('  n=%d events=%d logL=%.2f' % (res['n'], res['events'], res['ll']))
    for nm, b, s, z in zip(names, res['beta'], res['se'], res['z']):
        out.append('  %-34s % .4f  (se %.4f, z % .2f, exp %.3f)' % (nm, b, s, z, math.exp(b)))
    return '\n'.join(out)

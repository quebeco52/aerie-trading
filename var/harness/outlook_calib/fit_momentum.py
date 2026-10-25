"""Share-growth persistence and long-run (permanent) variance per industry and category.

Detrended share growth u_t = ds_t - b - beta*dgap_t (1997-2019, before the lockdown years). Reports:
  rho1   first autocorrelation of u (RW + white noise forces rho1 <= 0; momentum gives rho1 > 0)
  sd1    SD of one-year growth around trend
  lr_k   SD per sqrt(year) of k-year changes, small-sample corrected: sqrt(V_k / (k (1 - k/N)))
  phi    pooled AR(1) of u, so a permanent move shows half its size after ln 0.5 / ln phi years
  lr_ar  ARIMA(1,1,0) long-run SD: sd(e) / (1 - phi)

python3 -I fit_momentum.py [source]   source: us (default) | eu | us71
"""
import json, math, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = sys.argv[1] if len(sys.argv) > 1 else 'us'
sys.path.insert(0, HERE)
from categories import CATEGORIES as CATS  # noqa: E402
KS = (1, 2, 3, 5, 8)


def ols(Y, Xs):
    """Least squares with intercept; returns (coefs, residuals). Xs is a list of regressor columns."""
    cols = [[1.0] * len(Y)] + Xs
    m = len(cols)
    A = [[sum(cols[i][t] * cols[j][t] for t in range(len(Y))) for j in range(m)] for i in range(m)]
    c = [sum(cols[i][t] * Y[t] for t in range(len(Y))) for i in range(m)]
    for i in range(m):  # Gauss-Jordan
        p = A[i][i]
        for j in range(m):
            A[i][j] /= p
        c[i] /= p
        for k in range(m):
            if k != i:
                f = A[k][i]
                for j in range(m):
                    A[k][j] -= f * A[i][j]
                c[k] -= f * c[i]
    res = [Y[t] - sum(c[i] * cols[i][t] for i in range(m)) for t in range(len(Y))]
    return c, res


def growth_resid(s, gap):
    d = [s[t] - s[t - 1] for t in range(1, len(s))]
    if gap is None:
        _, u = ols(d, [])
    else:
        dg = [gap[t] - gap[t - 1] for t in range(1, len(gap))]
        _, u = ols(d, [dg])
    return u


def stats(u):
    n = len(u)
    g0 = sum(x * x for x in u) / n
    g1 = sum(u[t] * u[t - 1] for t in range(1, n)) / n
    lr = {}
    for k in KS:
        sums = [sum(u[t:t + k]) for t in range(n - k + 1)]
        vk = sum(x * x for x in sums) / len(sums)
        lr[k] = math.sqrt(vk / (k * (1 - k / n)))
    return {'rho1': g1 / g0, 'sd1': math.sqrt(g0), 'lr': lr}


def pooled_phi(us):
    num = sum(u[t] * u[t - 1] for u in us for t in range(1, len(u)))
    den = sum(u[t - 1] ** 2 for u in us for t in range(1, len(u)))
    phi = num / den
    e2 = [sum((u[t] - phi * u[t - 1]) ** 2 for t in range(1, len(u))) / (len(u) - 1) for u in us]
    return phi, e2


def load():
    if SRC == 'us':
        D = json.load(open(os.path.join(HERE, 'data', 'us_shares.json')))
        cut = D['years'].index(2019) + 1
        return {k: v[:cut] for k, v in D['industries'].items()}, D['gap'][:cut]
    D = json.load(open(os.path.join(HERE, 'data', f'{SRC}_shares.json')))
    return D['series'], None


def main():
    series, gap = load()
    per = {}
    for name, s in sorted(series.items()):
        if any(v is None for v in s):
            continue
        g = gap if SRC == 'us' else series.get('__gap__' + name.split('|')[0])
        u = growth_resid(s, g)
        per[name] = (u, stats(u))
    if SRC == 'us':
        print(f"{'industry':42s} {'rho1':>6s} {'sd1':>6s} " + ' '.join(f'lr{k:<4d}' for k in KS))
        for name, (u, st) in per.items():
            print(f"{name:42s} {st['rho1']:6.2f} {st['sd1']:6.3f} " + ' '.join(f"{st['lr'][k]:6.3f}" for k in KS))
        groups = {c: [n for n in names if n in per] for c, names in CATS.items()}
    else:
        groups = {}
        for name in per:
            groups.setdefault(name.split('|')[1], []).append(name)
    print(f"\n{'category':14s} {'n':>3s} {'rho1':>6s} {'phi':>6s} {'HL_phi':>6s} {'sd1':>6s} " + ' '.join(f'lr{k:<4d}' for k in KS) + '  lr_ar')
    out = {}
    for c, names in groups.items():
        if not names:
            continue
        us = [per[n][0] for n in names]
        sts = [per[n][1] for n in names]
        phi, e2 = pooled_phi(us)
        med = lambda xs: sorted(xs)[len(xs) // 2]
        rho = med([s['rho1'] for s in sts])
        sd1 = med([s['sd1'] for s in sts])
        lr = {k: med([s['lr'][k] for s in sts]) for k in KS}
        lr_ar = med([math.sqrt(e) / (1 - phi) for e in e2]) if phi < 1 else float('inf')
        hl = math.log(0.5) / math.log(phi) if 0 < phi < 1 else 0.0
        out[c] = {'n': len(names), 'rho1': rho, 'phi': phi, 'hl_phi': hl, 'sd1': sd1, 'lr': lr, 'lr_ar': lr_ar}
        print(f"{c:14s} {len(names):3d} {rho:6.2f} {phi:6.2f} {hl:6.2f} {sd1:6.3f} " + ' '.join(f'{lr[k]:6.3f}' for k in KS) + f'  {lr_ar:6.3f}')
    json.dump(out, open(os.path.join(HERE, 'data', f'momentum_{SRC}.json'), 'w'), indent=1)


if __name__ == '__main__':
    main()

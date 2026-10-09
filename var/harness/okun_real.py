"""Okun slope by economy, Ball-Leigh-Loungani (2017) levels form: HP(1600) cycle of u on HP cycle of 100 log real GDP.

    python3 var/harness/okun_real.py [from] [to]      # FRED series cached in okun_data.json; default 1999Q2-2019Q4

The engine is read the same way by okun_engine_an.py, so the slope OKUNS_COEFFICIENT is set on compares like with like.
"""
import json, math, os, sys
H = os.path.dirname(os.path.abspath(__file__))
DATA = json.load(open(f'{H}/okun_data.json'))
def load(id):
    return DATA[id]
def quarterly(d):
    q = {}
    for k, v in d.items():
        y, m = int(k[:4]), int(k[5:7]); q.setdefault(f'{y}-{((m - 1) // 3) * 3 + 1:02d}-01', []).append(v)
    return {k: sum(v) / len(v) for k, v in q.items() if len(v) in (1, 3)}
def hp(y, lam=1600.0):
    """Cycle y - trend, trend solving (I + lam D'D) t = y by banded Gaussian elimination (bandwidth 2)."""
    n = len(y)
    A = [[0.0] * n for _ in range(n)]
    for i in range(n - 2):
        c = [1.0, -2.0, 1.0]
        for a in range(3):
            for b in range(3):
                A[i + a][i + b] += lam * c[a] * c[b]
    for i in range(n): A[i][i] += 1.0
    b = list(y)
    for c in range(n):
        for r in range(c + 1, min(n, c + 3)):
            f = A[r][c] / A[c][c]
            if f:
                for k in range(c, min(n, c + 3)): A[r][k] -= f * A[c][k]
                b[r] -= f * b[c]
    t = [0.0] * n
    for r in range(n - 1, -1, -1):
        t[r] = (b[r] - sum(A[r][k] * t[k] for k in range(r + 1, min(n, r + 3)))) / A[r][r]
    return [yy - tt for yy, tt in zip(y, t)]
PAIRS = {'US': ('UNRATE', 'GDPC1'), 'Germany': ('LRHUTTTTDEQ156S', 'CLVMNACSCAB1GQDE'), 'Japan': ('LRHUTTTTJPQ156S', 'JPNRGDPEXP'),
         'Switzerland': ('LRHUTTTTCHQ156S', 'CLVMNACSCAB1GQCH'), 'Netherlands': ('LRHUTTTTNLQ156S', 'CLVMNACSCAB1GQNL'),
         'Austria': ('LRHUTTTTATQ156S', 'CLVMNACSCAB1GQAT'), 'Norway': ('LRHUTTTTNOQ156S', 'CLVMNACSCAB1GQNO'),
         'Canada': ('LRHUTTTTCAQ156S', 'NGDPRSAXDCCAQ'), 'UK': ('LRHUTTTTGBQ156S', 'CLVMNACSCAB1GQUK')}
lo, hi = sys.argv[1] if len(sys.argv) > 1 else '1999-04-01', sys.argv[2] if len(sys.argv) > 2 else '2020-01-01'
print(f'window {lo[:7]}..{hi[:7]} (HP 1600 both sides)')
print(f"{'':12s} {'n':>4s} {'slope':>7s} {'se':>5s} {'corr':>6s} {'u sd':>6s} {'y sd':>6s} {'u/y sd':>7s} {'uACF4':>6s} {'uACF8':>6s}")
for name, (u_id, y_id) in PAIRS.items():
    u, y = quarterly(load(u_id)), quarterly(load(y_id))
    ks = [k for k in sorted(u) if k in y and lo <= k < hi]
    uc = hp([u[k] for k in ks]); yc = hp([100 * math.log(y[k]) for k in ks])
    n = len(ks); mu, my = sum(uc) / n, sum(yc) / n
    su = math.sqrt(sum((x - mu) ** 2 for x in uc) / n); sy = math.sqrt(sum((x - my) ** 2 for x in yc) / n)
    cov = sum((a - mu) * (c - my) for a, c in zip(uc, yc)) / n
    b = cov / sy ** 2
    res = [(a - mu) - b * (c - my) for a, c in zip(uc, yc)]; se = math.sqrt(sum(r * r for r in res) / (n - 2) / (n * sy ** 2))
    ac = lambda x, k, m: sum((x[i] - m) * (x[i - k] - m) for i in range(k, len(x))) / sum((v - m) ** 2 for v in x)
    print(f'{name:12s} {n:4d} {b:7.3f} {se:5.3f} {cov / (su * sy):6.2f} {su:6.2f} {sy:6.2f} {su / sy:7.2f} {ac(uc, 4, mu):6.2f} {ac(uc, 8, mu):6.2f}')

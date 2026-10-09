"""Oil shocks: Merton (1976) jump-diffusion fitted to month-end real WTI log returns (FRED DCOILWTICO / CPIAUCSL).

The engine's energy base price is a Schwartz (1997) log-OU toward a moving supply/demand equilibrium. At a monthly
horizon the drift is negligible, so the monthly return distribution identifies the diffusion and the jumps; the
reversion speed and the equilibrium are left to their own calibration.
"""
import json, math, os, urllib.request

H = os.path.dirname(os.path.abspath(__file__))
P = os.path.join(H, 'oil_data.json')
if not os.path.exists(P):
    d = {}
    for s in ('DCOILWTICO', 'CPIAUCSL'):
        lines = urllib.request.urlopen(f'https://fred.stlouisfed.org/graph/fredgraph.csv?id={s}', timeout=60).read().decode().splitlines()[1:]
        d[s] = {r.split(',')[0]: float(r.split(',')[1]) for r in lines if r.split(',')[1] not in ('.', '')}
    json.dump(d, open(P, 'w'))
d = json.load(open(P))
month_end = {}
for day, px in sorted(d['DCOILWTICO'].items()):
    if px > 0:
        month_end[day[:7]] = px
cpi = {k[:7]: v for k, v in d['CPIAUCSL'].items()}
ks = sorted(k for k in month_end if k in cpi and '1986-01' <= k <= '2025-12')
lr = [math.log(month_end[ks[i]] / cpi[ks[i]]) - math.log(month_end[ks[i - 1]] / cpi[ks[i - 1]]) for i in range(1, len(ks))]
n = len(lr)
m = sum(lr) / n
sd = math.sqrt(sum((x - m) ** 2 for x in lr) / n)
sk = sum((x - m) ** 3 for x in lr) / n / sd ** 3
ku = sum((x - m) ** 4 for x in lr) / n / sd ** 4 - 3
print(f"month-end real WTI log returns 1986-2025: n {n}, mean {m:+.4f}, sd {sd:.4f} ({sd * math.sqrt(12):.3f}/sqrt(yr)), skew {sk:+.2f}, exkurt {ku:.2f}")
big = sorted(zip(lr, ks[1:]), key=lambda t: abs(t[0]))[-10:]
print("largest months:", [(k, round(v, 2)) for v, k in sorted(big, key=lambda t: t[1])])


def nll(th):
    mu, s, lam, mj, sj = th[0], math.exp(th[1]), math.exp(th[2]), th[3], math.exp(th[4])
    tot = 0.0
    for x in lr:
        acc, pn = 0.0, math.exp(-lam)
        for k in range(12):
            if k > 0:
                pn *= lam / k
            var = s * s + k * sj * sj
            acc += pn * math.exp(-(x - mu - k * mj) ** 2 / (2 * var)) / math.sqrt(2 * math.pi * var)
        tot -= math.log(max(acc, 1e-300))
    return tot


src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('def nelder_mead'):src.index('best = None')])
best = None
for st0 in [[0.0, math.log(0.07), math.log(0.05), 0.0, math.log(0.2)], [0.0, math.log(0.06), math.log(0.1), -0.05, math.log(0.15)],
            [0.0, math.log(0.08), math.log(0.02), 0.0, math.log(0.3)], [0.005, math.log(0.065), math.log(0.2), -0.02, math.log(0.12)]]:
    th, v = nelder_mead(nll, st0)
    th, v = nelder_mead(nll, th, step=0.05)
    if best is None or v < best[1]:
        best = (th, v)
th, v = best
mu, s, lam, mj, sj = th[0], math.exp(th[1]), math.exp(th[2]), th[3], math.exp(th[4])
gauss = n * (0.5 * math.log(2 * math.pi * sd * sd) + 0.5)
lr_stat = 2 * (gauss - v)
print(f"Merton MLE: diffusion {s:.4f}/month = {s * math.sqrt(12):.3f}/sqrt(yr); jumps {lam * 12:.2f}/yr of N({mj:+.3f}, {sj:.3f}^2) log; LR vs Gaussian {lr_stat:.1f} (chi2(3) 5% = 7.81)")
json.dump({'sigma': s * math.sqrt(12), 'jump_lambda': lam * 12, 'jump_mean': mj, 'jump_vol': sj, 'lr': lr_stat, 'n': n,
           'data_sd_annual': sd * math.sqrt(12), 'exkurt': ku}, open(os.path.join(H, 'oil_fit.json'), 'w'), indent=1)

"""US timing of policy against the cycle, 1985-2008: corr(policy_{t+k}, gap_t) at k = 0, 2, 4, 8 quarters, as a SHAPE.

Each correlation is divided by the one at 2q (the US peak): the level is not comparable, since the 1985-2008 funds rate
carries a disinflation downtrend the engine has no counterpart for; the decay from 2q to 8q is the timing.

Standard errors by moving-block bootstrap (block 12q, 2000 draws), since both series are highly persistent.
Writes timing_fit.json; timing_an.py compares an engine arm.
"""
import json, math, os, random
H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'policy_fit.py')).read()
exec(src[:src.index('out = {}')])
LAGS = (0, 2, 4, 8)


def xcorr(pol, g, k):
    A = [pol[t + k] for t in range(len(g) - k)]; B = [g[t] for t in range(len(g) - k)]
    n = len(A); ma = sum(A) / n; mb = sum(B) / n
    return sum((a - ma) * (b - mb) for a, b in zip(A, B)) / math.sqrt(sum((a - ma) ** 2 for a in A) * sum((b - mb) ** 2 for b in B))


keys = [k for k in qs if '1985-01' <= k <= '2008-10']
g = [gap[k] for k in keys]; pol = [ff[k] for k in keys]
point = {k: xcorr(pol, g, k) for k in LAGS}
random.seed(7)
B, L, n = 2000, 12, len(keys)
draws = {k: [] for k in LAGS}
pairs = list(zip(pol, g))
for _ in range(B):
    s = []
    while len(s) < n:
        i = random.randrange(0, n - L - 8)
        s += list(range(i, i + L))
    s = s[:n]
    # resample blocks of (t, t+k) pairs so each lagged pair stays intact
    for k in LAGS:
        A = [pol[t + k] for t in s if t + k < n]; Bg = [g[t] for t in s if t + k < n]
        m = len(A); ma = sum(A) / m; mb = sum(Bg) / m
        draws[k].append(sum((a - ma) * (b - mb) for a, b in zip(A, Bg)) / math.sqrt(sum((a - ma) ** 2 for a in A) * sum((b - mb) ** 2 for b in Bg)))
out = {}
for k in LAGS:
    if k == 2:
        continue
    d = [a / b for a, b in zip(draws[k], draws[2])]; m = sum(d) / B
    out[str(k)] = {'ratio': point[k] / point[2], 'se': math.sqrt(sum((x - m) ** 2 for x in d) / (B - 1))}
    print(f"US 1985-2008 corr(policy_t+{k}, gap_t) / corr at 2q: {out[str(k)]['ratio']:+.2f} (se {out[str(k)]['se']:.2f})  [corr {point[k]:+.2f}]")
json.dump(out, open(os.path.join(H, 'timing_fit.json'), 'w'), indent=1)

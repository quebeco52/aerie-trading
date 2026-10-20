"""Design calc for the normal vote's pull back (step 5 of the 2026-10-20 politics plan).

Simulates OpinionPolls::walkNormals alone, a month at a time: each party's normal vote decays toward its founding share
at rate mu = ln2 / half-life, then takes the walk's step sqrt(sigma2 * dt * normal) * z, floored at MIN_VOTE_SHARE and
renormalised. sigma2 is refitted for each half-life so the walk's semivariogram at 40 years (n * Var(dlog) / 2) stays
at the random walk's 0.341e-3 * 40, the ParlGov fit. Prints the effective number of parties (votes) and the largest
share's 90th percentile by year, plus the walk's semivariogram at 10, 20 and 40 years.
Usage: python3 normal_pull.py [paths=2000] [years=200]
"""
import math
import random
import sys

SEED = {'civic': 99, 'vanguard': 90, 'iron': 25, 'exchange': 25, 'chartists': 20, 'common': 6, 'tideline': 5, 'horizon': 30}
PI = {k: v / 300.0 for k, v in SEED.items()}
RW = 2 * 0.000341
FLOOR = 0.005
paths = int(sys.argv[1]) if len(sys.argv) > 1 else 2000
years = int(sys.argv[2]) if len(sys.argv) > 2 else 200
dt = 1.0 / 12.0


def run(half_life):
    mu = 0.0 if half_life is None else math.log(2) / half_life
    sigma2 = RW if mu == 0.0 else RW * mu * 40 / (1 - math.exp(-mu * 40))
    decay = math.exp(-mu * dt)
    rng = random.Random(7)
    enp = {y: [] for y in (0, 40, 80, 120, 160, 200) if y <= years}
    top = {y: [] for y in enp}
    vario = {10: [], 20: [], 40: []}
    for _ in range(paths):
        p = dict(PI)
        start = dict(p)
        for m in range(1, years * 12 + 1):
            for k in p:
                p[k] = max(FLOOR, PI[k] + (p[k] - PI[k]) * decay + math.sqrt(sigma2 * dt * p[k]) * rng.gauss(0, 1))
            s = sum(p.values())
            for k in p:
                p[k] /= s
            if m % 12 == 0:
                y = m // 12
                if y in enp:
                    enp[y].append(1 / sum(v * v for v in p.values()))
                    top[y].append(max(p.values()))
                if y in vario:
                    vario[y].append(sum(start[k] * math.log(p[k] / start[k]) ** 2 / 2 for k in p) / len(p))
        enp[0].append(1 / sum(v * v for v in start.values()))
        top[0].append(max(start.values()))
    p90 = lambda xs: sorted(xs)[int(0.9 * (len(xs) - 1))]
    label = 'walk' if half_life is None else f'H={half_life}'
    print(f"{label:6s} sigma2 {sigma2 * 1e3:.3f}e-3  " + '  '.join(f"y{y}: ENP {sum(v) / len(v):.2f} top90 {p90(top[y]):.2f}" for y, v in enp.items()))
    print(' ' * 7 + 'vario x1e3 ' + '  '.join(f"{g}y {1e3 * sum(v) / len(v):.2f} (walk {1e3 * RW / 2 * g:.2f})" for g, v in vario.items()))


for h in (None, 80, 60, 40, 30):
    run(h)

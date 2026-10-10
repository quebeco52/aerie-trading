#!/usr/bin/env python3
"""fund_analyse.py [seeds]: paired fund-effect table for fund_run.sh outputs (arms A1 A0 B1 B0).

Primary: per-seed D = mean lateDebt(off) - mean lateDebt(on), per speed, and DiD = D(B) - D(A).
Then a decomposition of late mean debt (= d0 + mean cumulative contributions) and late-window flow means.
"""
import json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
R = os.path.join(H, 'runs')


def seeds_arg(s):
    out = []
    for part in s.split(','):
        if '-' in part:
            a, b = part.split('-'); out += range(int(a), int(b) + 1)
        else:
            out.append(int(part))
    return out


seeds = seeds_arg(sys.argv[1] if len(sys.argv) > 1 else '1-16')
arms = ['A1', 'A0', 'B1', 'B0']
data = {a: {} for a in arms}
for a in arms:
    for s in seeds:
        p = os.path.join(R, f'fund-{a}-{s}.json')
        if os.path.exists(p):
            data[a][s] = json.load(open(p))
seeds = [s for s in seeds if all(s in data[a] for a in arms)]
n = len(seeds)


def ms(xs):
    m = sum(xs) / len(xs)
    sd = math.sqrt(sum((x - m) ** 2 for x in xs) / (len(xs) - 1)) if len(xs) > 1 else float('nan')
    return m, sd / math.sqrt(len(xs)), min(xs), max(xs)


def med(xs):
    xs = sorted(xs); return xs[len(xs) // 2]


def lm(a, s):
    return data[a][s]['lateDebtMean']


print(f'n = {n} seeds per arm; 80 years, 24 ticks/yr, late window = years 51-80 (30 annual samples)')
print()
print('Primary: late debt/GDP (per-seed mean of the 30 lateDebt samples)')
print(f"{'quantity':34s} {'mean':>8s} {'se':>7s} {'t':>7s} {'min':>8s} {'max':>8s}")
rows = {
    'debt A on (4.0)': [lm('A1', s) for s in seeds],
    'debt A off (4.0)': [lm('A0', s) for s in seeds],
    'debt B on (0.65)': [lm('B1', s) for s in seeds],
    'debt B off (0.65)': [lm('B0', s) for s in seeds],
    'Delta A = off - on (4.0)': [lm('A0', s) - lm('A1', s) for s in seeds],
    'Delta B = off - on (0.65)': [lm('B0', s) - lm('B1', s) for s in seeds],
    'DiD = Delta B - Delta A': [(lm('B0', s) - lm('B1', s)) - (lm('A0', s) - lm('A1', s)) for s in seeds],
    'on: B - A': [lm('B1', s) - lm('A1', s) for s in seeds],
    'off: B - A': [lm('B0', s) - lm('A0', s) for s in seeds],
}
for k, xs in rows.items():
    m, se, lo, hi = ms(xs)
    print(f'{k:34s} {m:8.4f} {se:7.4f} {m / se:7.2f} {lo:8.4f} {hi:8.4f}')
print()
print("Test statistic (pooled median of lateDebt samples): " + ', '.join(
    f"{a} {med([x for s in seeds for x in data[a][s]['lateDebt']]):.3f}" for a in arms))
print()

dkeys = ['purchases', 'netTaxes', 'corpTax', 'otherRev', 'cbRemit', 'fundSpend', 'fundRev', 'interest', 'erosion', 'rescue', 'floorAndResidual']
print('Decomposition of late mean debt: d0 + mean cumulative contribution since tick 1 (share of GDP)')
print(f"{'line':18s} {'A on':>8s} {'A off':>8s} {'B on':>8s} {'B off':>8s} {'DeltaA':>8s} {'DeltaB':>8s} {'DiD':>8s} {'se':>7s}")


def line(name, f):
    v = {a: [f(a, s) for s in seeds] for a in arms}
    did = [(v['B0'][i] - v['B1'][i]) - (v['A0'][i] - v['A1'][i]) for i in range(n)]
    m = {a: sum(v[a]) / n for a in arms}
    dm, dse, _, _ = ms(did)
    print(f"{name:18s} {m['A1']:8.4f} {m['A0']:8.4f} {m['B1']:8.4f} {m['B0']:8.4f} {m['A0'] - m['A1']:8.4f} {m['B0'] - m['B1']:8.4f} {dm:8.4f} {dse:7.4f}")


line('d0', lambda a, s: data[a][s]['d0'])
for k in dkeys:
    line(k, lambda a, s, k=k: data[a][s]['decomp'][k])
line('primary deficit', lambda a, s: sum(data[a][s]['decomp'][k] for k in dkeys[:7]))
line('(r-g)d', lambda a, s: data[a][s]['decomp']['interest'] + data[a][s]['decomp']['erosion'])
line('= late debt', lambda a, s: data[a][s]['lateDebtMean'])
print()

fkeys = [('draw', 'fund rule draw %GDP'), ('stab', 'fund stabilisation'), ('pb', 'primary balance'), ('netTax', 'net tax rate'),
         ('stanceTarget', 'stance target'), ('bohn', 'Bohn surplus'), ('cbRemit', 'CB remittance'), ('effRate', 'eff. rate on debt'),
         ('g', 'nominal GDP growth'), ('rMinusG', 'r - g'), ('rgD', '(r-g)*d'), ('interestD', 'interest %GDP'), ('policy', 'policy rate'),
         ('y10', '10Y yield'), ('inflation', 'inflation'), ('gap', 'output gap'), ('fundToGdp', 'fund / GDP'), ('nfl', 'net fin. liab.'),
         ('bohnOn', 'share Bohn active'), ('stabCap', 'share stab at cap'), ('stabNeg', 'share stab < 0'), ('atFloor', 'share debt at floor')]
print('Late-window means (years 51-80, every tick)')
for k, name in fkeys:
    line(name, lambda a, s, k=k: data[a][s]['flow'][k])
line('fund real return', lambda a, s: data[a][s]['fundRealReturn'])
print()
print('Cross-seed OLS of the fund effect Delta on the late fund size (both speeds pooled, one point per seed and speed)')


def ols(xs, ys):
    mx, my = sum(xs) / len(xs), sum(ys) / len(ys)
    sxx = sum((x - mx) ** 2 for x in xs); b = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sxx
    res = [y - my - b * (x - mx) for x, y in zip(xs, ys)]
    se = math.sqrt(sum(r * r for r in res) / (len(xs) - 2) / sxx)
    r2 = 1 - sum(r * r for r in res) / sum((y - my) ** 2 for y in ys)
    return my - b * mx, b, se, r2


for label, xf in [('fund/GDP late', lambda a, s: data[a][s]['flow']['fundToGdp']),
                  ('rescue(on)-rescue(off)', None)]:
    xs, ys = [], []
    for sp in 'AB':
        for s in seeds:
            if xf is None:
                x = data[sp + '1'][s]['decomp']['rescue'] - data[sp + '0'][s]['decomp']['rescue']
            else:
                x = xf(sp + '1', s)
            xs.append(x); ys.append(lm(sp + '0', s) - lm(sp + '1', s))
    a, b, se, r2 = ols(xs, ys)
    print(f'  Delta = {a:.3f} + {b:.3f} (se {se:.3f}) x {label}, R^2 {r2:.2f}, n {len(xs)}')

#!/usr/bin/env python3
"""tp.py <a-b>: debt-supply term premium (before = HEAD snapshot, after = ds-after snapshot), paired by seed.

Targets (named before the run): per-seed OLS slope of the 10y yield on gross debt y20-100 (pp yield per unit debt);
mean 10y yield y20-100; mean output gap y20-100.
"""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
a, b = (sys.argv[1] if len(sys.argv) > 1 else '1-8').split('-')
seeds = list(range(int(a), int(b) + 1))
ARMS = ('before', 'after')
WIN = 32


def mean(xs):
    xs = list(xs)
    return st.mean(xs) if xs else float('nan')


def ols(xs, ys):
    mx, my = mean(xs), mean(ys)
    sxx = sum((x - mx) ** 2 for x in xs)
    return sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sxx if sxx > 0 else float('nan')


def stats(arm, s):
    R = [r for r in map(json.loads, open(f'{H}/runs/{arm}-{s}.macro.jsonl')) if r['t'] >= 0.2]
    pos = [r['coal_state'] for r in R]
    trail = [mean(pos[max(0, i - WIN + 1):i + 1]) for i in range(len(pos))]
    idx = [i for i, r in enumerate(R) if 20 <= r['t'] <= 100]
    W = [R[i] for i in idx]
    return dict(
        y10_on_debt=100 * ols([r['debt'] for r in W], [r['y10'] for r in W]),
        y10=100 * mean(r['y10'] for r in W),
        gap=100 * mean(r['gap'] for r in W),
        tp10=100 * mean(r['tp10'] for r in W),
        s2s10=100 * mean(r['y10'] - r['y2'] for r in W),
        inverted=100 * mean(r['y10'] < r['y2'] for r in W),
        debt=mean(r['debt'] for r in W), debt_sd=st.pstdev([r['debt'] for r in W]),
        house=mean(r['house'] for r in W), house_dev=100 * mean(r['house_dev'] for r in W),
        ig=1e4 * mean(r['ig'] for r in W), hy=1e4 * mean(r['hy'] for r in W),
        u=100 * mean(r['u'] for r in W), net_tax=100 * mean(r['net_tax'] for r in W),
        debt_on_pos=100 * ols([trail[i] for i in idx], [R[i]['debt'] for i in idx]),
        policy=100 * mean(r['policy'] for r in W),
        nan=sum(1 for r in R for k in ('debt', 'y10', 'tp10', 'gap') if not math.isfinite(r[k])),
    )


S = {arm: {s: stats(arm, s) for s in seeds} for arm in ARMS}
units = dict(y10_on_debt='pp/unit', y10='%', gap='%', tp10='%', s2s10='pp', inverted='% q', debt='x GDP', debt_sd='x GDP',
             house='index', house_dev='%', ig='bp', hy='bp', u='%', net_tax='% GDP', debt_on_pos='pp/unit', policy='%')
print(f'debt-supply term premium, paired before vs after, seeds {a}-{b}, y20-100, 360 tpy, POLITICS=1')
print(f"{'quantity':<12}{'unit':>8}{'before':>9}{'after':>9}{'diff':>9}{'se':>8}{'t':>7}{'n':>3}")
for k, u in units.items():
    if k == 'tp10':
        print('-- side checks --')
    dd = [S['after'][s][k] - S['before'][s][k] for s in seeds]
    se = st.stdev(dd) / math.sqrt(len(dd))
    print(f"{k:<12}{u:>8}{mean(S['before'][s][k] for s in seeds):>9.3f}{mean(S['after'][s][k] for s in seeds):>9.3f}"
          f"{mean(dd):>+9.3f}{se:>8.3f}{mean(dd) / se if se > 0 else float('nan'):>+7.2f}{len(dd):>3}")
print('per seed y10_on_debt (before/after): ' + ', '.join(f"{s}: {S['before'][s]['y10_on_debt']:+.2f}/{S['after'][s]['y10_on_debt']:+.2f}" for s in seeds))
print(f"NaN cells: before {sum(S['before'][s]['nan'] for s in seeds)}, after {sum(S['after'][s]['nan'] for s in seeds)}")

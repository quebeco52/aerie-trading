#!/usr/bin/env python3
"""target.py <a-b>: debt-target lever (before = HEAD, after = working tree), paired by seed.

Targets (named before the run): sd of gross debt y20-100; OLS slope of gross debt on the trailing 8-year mean of the
governing coalition's state-axis position, y20-100 (pp of debt per unit of position); share of quarters with gross
debt above the Council line (DebtBrake::councilLine of the politics state, both arms).
"""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
a, b = (sys.argv[1] if len(sys.argv) > 1 else '1-8').split('-')
seeds = list(range(int(a), int(b) + 1))
ARMS = ('before', 'after')
WIN = 32  # 8 years of quarters


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
    d = dict(
        sd20=st.pstdev([r['debt'] for r in W]),
        slope_pos=100 * ols([trail[i] for i in idx], [R[i]['debt'] for i in idx]),
        above_line=100 * mean(r['debt'] > r['council_line'] for r in R),
        debt20=mean(r['debt'] for r in W),
        debt0_10=mean(r['debt'] for r in R if r['t'] <= 10), debt10=min(R, key=lambda r: abs(r['t'] - 10))['debt'],
        primary0_10=100 * mean(-r['primary'] for r in R if r['t'] <= 10),
        y10=100 * mean(r['y10'] for r in R), gap=100 * mean(r['gap'] for r in R), u=100 * mean(r['u'] for r in R),
        net_tax=100 * mean(r['net_tax'] for r in R), spread=1e4 * mean(r['spread'] for r in R),
        floor=100 * mean(r['debt'] <= 0.205 for r in R),
        pos_sd=st.pstdev([trail[i] for i in idx]),
    )
    if R[0].get('anchor') is not None:
        d['capped'] = 100 * mean(r['council_debt_line'] < r['debt_target'] for r in R)
        d['target'] = mean(r['debt_target'] for r in W)
        d['anchor'] = mean(r['anchor'] for r in W)
        d['nan'] = sum(1 for r in R for k in ('debt', 'net_tax', 'anchor') if not math.isfinite(r[k]))
    return d


S = {arm: {s: stats(arm, s) for s in seeds} for arm in ARMS}
units = dict(sd20='x GDP', slope_pos='pp/unit', above_line='% q', debt20='x GDP', debt0_10='x GDP', debt10='x GDP', primary0_10='pp GDP', y10='%', gap='%', u='%',
             net_tax='% GDP', spread='bp', floor='% q', pos_sd='unit')
print(f'debt-target lever, paired before vs after, seeds {a}-{b}, 100y, 360 tpy, POLITICS=1')
print(f"{'quantity':<12}{'unit':>8}{'before':>9}{'after':>9}{'diff':>9}{'se':>8}{'t':>7}{'n':>3}")
for k, u in units.items():
    if k == 'debt20':
        print('-- side checks --')
    pairs = [(S['before'][s][k], S['after'][s][k]) for s in seeds if math.isfinite(S['before'][s][k]) and math.isfinite(S['after'][s][k])]
    dd = [y - x for x, y in pairs]
    se = st.stdev(dd) / math.sqrt(len(dd))
    print(f"{k:<12}{u:>8}{mean(x for x, _ in pairs):>9.3f}{mean(y for _, y in pairs):>9.3f}{mean(dd):>+9.3f}{se:>8.3f}{mean(dd) / se if se > 0 else float('nan'):>+7.2f}{len(dd):>3}")
A = S['after']
sb = [S['before'][s]['slope_pos'] for s in seeds]; sa = [A[s]['slope_pos'] for s in seeds]
dd = [y - x for x, y in zip(sb, sa)]
med = st.median(dd); mad = st.median(abs(d - med) for d in dd)
out = [s for s, d in zip(seeds, dd) if mad > 0 and abs(d - med) > 5 * mad]
keep = [d for s, d in zip(seeds, dd) if s not in out]
print(f"slope_pos medians: before {st.median(sb):+.1f}, after {st.median(sa):+.1f}, diff {med:+.1f}; outlier seeds (|d-med|>5 MAD): {out}"
      + (f"; without them diff {mean(keep):+.2f} se {st.stdev(keep) / math.sqrt(len(keep)):.2f} t {mean(keep) / (st.stdev(keep) / math.sqrt(len(keep))):+.2f} n={len(keep)}" if out and len(keep) > 1 else ''))
print(f"after only: anchor capped by the line {mean(A[s]['capped'] for s in seeds):.1f}% q; mean target y20-100 {mean(A[s]['target'] for s in seeds):.3f}, "
      f"anchor {mean(A[s]['anchor'] for s in seeds):.3f}; NaN {sum(A[s]['nan'] for s in seeds)}")
print('per seed (before / after): sd20 | slope_pos | above_line | debt20')
for s in seeds:
    B, Af = S['before'][s], A[s]
    print(f"  {s}: {B['sd20']:.3f}/{Af['sd20']:.3f} | {B['slope_pos']:+7.1f}/{Af['slope_pos']:+7.1f} | {B['above_line']:5.1f}/{Af['above_line']:5.1f} | {B['debt20']:.3f}/{Af['debt20']:.3f}")

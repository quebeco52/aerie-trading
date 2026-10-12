#!/usr/bin/env python3
"""paired.py <a-b>: before vs after, paired by seed. Prints targets, side checks and the fund/GDP decomposition.

Targets (named before the run): |OLS slope| of gross debt y20-100 (pp/decade); tariff surplus = mean primary balance
(tariff>0) - (tariff=0), pp GDP; log fund/GDP at y100 (and level).
"""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
a, b = (sys.argv[1] if len(sys.argv) > 1 else '1-8').split('-')
seeds = list(range(int(a), int(b) + 1))
ARMS = ('before', 'after')


def load(arm, s):
    with open(f'{H}/runs/{arm}-{s}.macro.jsonl') as f:
        return [r for r in map(json.loads, f) if r['t'] >= 0.2]


def at(R, y):
    return min(R, key=lambda r: abs(r['t'] - y))


def slope(R, lo, hi):
    P = [(r['t'], r['debt']) for r in R if lo <= r['t'] <= hi]
    mx, my = st.mean(x for x, _ in P), st.mean(y for _, y in P)
    return sum((x - mx) * (y - my) for x, y in P) / sum((x - mx) ** 2 for x, _ in P) * 1000


def mean(xs):
    xs = list(xs)
    return st.mean(xs) if xs else float('nan')


def decomp(R):
    """Sum over the run of the quarterly change in fund/GDP: (r-g)/(1+g) F_prev, inflow, stamp, -(draw+stab), residual."""
    out = dict(ret=0.0, inflow=0.0, stamp=0.0, draw=0.0, resid=0.0)
    for p, r in zip(R, R[1:]):
        if p['f_ret_idx'] <= 0 or p['fund'] <= 0:
            continue
        ret = r['f_ret_idx'] / p['f_ret_idx'] - 1
        g = r['nominal_gdp'] / p['nominal_gdp'] - 1
        rg = (ret - g) / (1 + g) * p['fund']
        flows = r['f_inflow'] + r['f_stamp'] - r['f_draw'] - r['f_stab']
        out['ret'] += rg
        out['inflow'] += r['f_inflow']
        out['stamp'] += r['f_stamp']
        out['draw'] -= r['f_draw'] + r['f_stab']
        out['resid'] += (r['fund'] - p['fund']) - rg - flows
    out['total'] = R[-1]['fund'] - R[0]['fund']
    return out


def stats(R):
    late = [r for r in R if 30 <= r['t'] <= 100]
    on = [-r['primary'] for r in R if r['tariff'] > 0]
    off = [-r['primary'] for r in R if r['tariff'] <= 0]
    f100 = at(R, 100)['fund']
    d = dict(
        slope=abs(slope(R, 20, 100)),
        tariff_surplus=(mean(on) - mean(off)) * 100 if on and off else float('nan'),
        log_fund100=math.log(f100) if f100 > 0 else float('nan'),
        fund100=f100,
        debt30=mean(r['debt'] for r in late), debt30_sd=st.pstdev([r['debt'] for r in late]),
        gt090=100 * mean(r['debt'] > 0.90 for r in R), floor=100 * mean(r['debt'] <= 0.205 for r in R),
        gap=100 * mean(r['gap'] for r in R), u=100 * mean(r['u'] for r in R), y10=100 * mean(r['y10'] for r in R),
        spread=1e4 * mean(r['spread'] for r in R), net_tax=100 * mean(r['net_tax'] for r in R),
        tariff_q=100 * mean(r['tariff'] > 0 for r in R), primary=100 * mean(-r['primary'] for r in R),
        nan=sum(1 for r in R for k in ('debt', 'fund', 'net_tax', 'primary') if not math.isfinite(r[k])),
    )
    d.update({'fd_' + k: v for k, v in decomp(R).items()})
    return d


S = {arm: {s: stats(load(arm, s)) for s in seeds} for arm in ARMS}
keys = list(S['after'][seeds[0]].keys())
units = dict(slope='pp/dec', tariff_surplus='pp GDP', log_fund100='log', fund100='x GDP', debt30='x GDP', debt30_sd='x GDP',
             gt090='% q', floor='% q', gap='%', u='%', y10='%', spread='bp', net_tax='% GDP', tariff_q='% q', primary='pp GDP')
print(f'paired before vs after, seeds {a}-{b}, 100y, 360 tpy, POLITICS=1')
print(f"{'quantity':<16}{'unit':>8}{'before':>9}{'after':>9}{'diff':>9}{'se':>8}{'t':>7}{'n':>3}")
for k in keys:
    if k == 'nan' or k.startswith('fd_'):
        continue
    pairs = [(S['before'][s][k], S['after'][s][k]) for s in seeds
             if math.isfinite(S['before'][s][k]) and math.isfinite(S['after'][s][k])]
    n = len(pairs)
    dd = [y - x for x, y in pairs]
    se = st.stdev(dd) / math.sqrt(n) if n > 1 else float('nan')
    md = mean(dd)
    t = md / se if se and se > 0 else float('nan')
    if k == 'tariff_q':
        print('-- side checks / context --')
    print(f"{k:<16}{units.get(k, ''):>8}{mean(x for x, _ in pairs):>9.3f}{mean(y for _, y in pairs):>9.3f}{md:>+9.3f}{se:>8.3f}{t:>+7.2f}{n:>3}")
print('\nper seed (before / after): slope | tariff surplus | fund100 | debt30')
for s in seeds:
    B, A = S['before'][s], S['after'][s]
    print(f"  {s}: {B['slope']:5.2f}/{A['slope']:5.2f} | {B['tariff_surplus']:+6.2f}/{A['tariff_surplus']:+6.2f} | {B['fund100']:5.2f}/{A['fund100']:5.2f} | {B['debt30']:.3f}/{A['debt30']:.3f}")
print('\nfund/GDP change over the run, mean over seeds (x GDP): return-less-growth, budget inflow, stamp duty, -(draw+stab), residual = total')
for arm in ARMS:
    m = {k: mean(S[arm][s]['fd_' + k] for s in seeds) for k in ('ret', 'inflow', 'stamp', 'draw', 'resid', 'total')}
    print(f"  {arm:<7} {m['ret']:+7.3f} {m['inflow']:+7.3f} {m['stamp']:+7.3f} {m['draw']:+7.3f} {m['resid']:+7.3f} = {m['total']:+7.3f}")
print(f"NaN cells: before {sum(S['before'][s]['nan'] for s in seeds)}, after {sum(S['after'][s]['nan'] for s in seeds)}")

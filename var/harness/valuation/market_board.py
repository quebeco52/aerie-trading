#!/usr/bin/env python3
"""market_board.py <seeds> <arm> [<arm> ...]: how boards' buybacks answer the market factor, and the board's valuation
range, from runs/<arm>-<seed>.{jsonl,val.json}, with paired differences against the first arm. Year 1 skipped.

V is the market factor's repricing rebuilt from the probe: each quarter's cap-weighted market and market-jump channels
(mkt + jS), faded at the 5-year half-life of MacroStateDTO::equityValuationShift.

Per seed:
- bb/cap: the board's buybacks a year over its cap, quarterly, averaged.
- corr bb,V / slope bb,V: correlation and slope of that yield on V. The yield rises after a fall on the cap alone.
- bb/NI, corr bbNI,V: buybacks a year over trailing board earnings, and its correlation with V: whether boards buy
  harder after a fall or only pay the same share of earnings into a cheaper market.
- shares y20/y1: median over surviving names of split-adjusted shares (shares x price / price index), end over year 1.
- PE min / PE max / sd log PE: board cap over board earnings, quarters with positive earnings.
- sd V, corr r,dV: the factor itself and its hold on the board's quarterly return (should not move with a board fix).
- deals: acquisitions and buyouts over the run.
"""
import json, math, os, statistics as st, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se, paired, fmt  # noqa: E402

H = os.path.dirname(os.path.abspath(__file__))
FADE = math.log(2) / 5.0
MEASURES = ['bb/cap', 'corr bb,V', 'slope bb,V', 'bb/NI', 'corr bbNI,V', 'shares y20/y1', 'PE min', 'PE max', 'sd log PE', 'sd V', 'corr r,dV', 'deals']


def seeds_of(arg):
    if '-' in arg:
        a, b = arg.split('-')
        return list(range(int(a), int(b) + 1))
    return [int(x) for x in arg.split(',')]


def corr(a, b):
    ma, mb, sa, sb = st.fmean(a), st.fmean(b), st.pstdev(a), st.pstdev(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / len(a) / (sa * sb) if sa > 0 and sb > 0 else math.nan


def seed_measures(arm, seed):
    val = json.load(open(f'{H}/runs/{arm}-{seed}.val.json'))
    run = json.loads(open(f'{H}/runs/{arm}-{seed}.jsonl').readline())
    im, ij = 6 + val['names'].index('mkt'), 6 + val['names'].index('jS')
    v, quarters, first, last = 0.0, {}, {}, {}
    for t, snap in val['rows']:
        cap = sum(x[1] * x[4] for x in snap.values())
        ni = sum(x[3] * x[4] for x in snap.values())
        v = v * math.exp(-FADE * 0.25) + sum(x[1] * x[4] * (x[im] + x[ij]) for x in snap.values()) / cap
        quarters[round(t * 4)] = (t, cap, ni, v)
        if t >= 1.0:
            for name, x in snap.items():
                if x[0] > 0 and x[4] > 0:
                    first.setdefault(name, x[4] * x[1] / x[0])
                    last[name] = x[4] * x[1] / x[0]
    buybacks, earnings = {}, {}
    for f in run['final'].values():
        for q in f['q']:
            k = math.ceil(q['t'] * 4 - 1e-9)
            buybacks[k] = buybacks.get(k, 0.0) + (q.get('bb') or 0.0)
            earnings[k] = earnings.get(k, 0.0) + q['ni']
    ks = [k for k in sorted(quarters) if quarters[k][0] >= 1.0]
    bk = [k for k in ks if k in buybacks]
    yld = [4.0 * buybacks[k] / quarters[k][1] for k in bk]
    vs = [quarters[k][3] for k in bk]
    pe = [quarters[k][1] / quarters[k][2] for k in ks if quarters[k][2] > 0]
    caps = [quarters[k][1] for k in ks]
    vq = [quarters[k][3] for k in ks]
    ret = [math.log(caps[i + 1] / caps[i]) for i in range(len(caps) - 1)]
    dv = [vq[i + 1] - vq[i] for i in range(len(vq) - 1)]
    c = corr(vs, yld)
    ttm = {k: sum(earnings.get(j, 0.0) for j in range(k - 3, k + 1)) for k in bk}
    nk = [k for k in bk if ttm[k] > 0]
    payout = [4.0 * buybacks[k] / ttm[k] for k in nk]
    survivors = [n for n in last if n in first and n not in run['dead']]
    return {
        'bb/cap': st.fmean(yld),
        'corr bb,V': c,
        'slope bb,V': c * st.pstdev(yld) / st.pstdev(vs),
        'bb/NI': st.fmean(payout),
        'corr bbNI,V': corr([quarters[k][3] for k in nk], payout),
        'shares y20/y1': st.median(last[n] / first[n] for n in survivors),
        'PE min': min(pe),
        'PE max': max(pe),
        'sd log PE': st.pstdev(math.log(x) for x in pe),
        'sd V': st.pstdev(vq),
        'corr r,dV': corr(ret, dv),
        'deals': sum(val['deals'].values()),
    }


def main():
    seeds, arms = seeds_of(sys.argv[1]), sys.argv[2:]
    per = {a: {s: seed_measures(a, s) for s in seeds if os.path.exists(f'{H}/runs/{a}-{s}.val.json')} for a in arms}
    print(f"{'measure':14s}" + ''.join(f'{a + " mean (se)":>22s}' for a in arms)
          + ''.join(f'{a + " - " + arms[0]:>34s}' for a in arms[1:]))
    for k in MEASURES:
        line = f'{k:14s}'
        for a in arms:
            m = mean_se([x[k] for x in per[a].values()])
            line += f'{m.mean:>13.3f} ({m.se:.3f})'
        for a in arms[1:]:
            line += f"{fmt(paired({s: x[k] for s, x in per[arms[0]].items()}, {s: x[k] for s, x in per[a].items()})):>34s}"
        print(line)


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""mainland.py <seeds> <arm> [<arm> ...]: the mainland equity index against the board, from the quarterly macro rows
runs/<arm>-<seed>.macro.jsonl (fidx, fshift, v, cap, fund), with paired differences against the first arm. Year 1
skipped.

Per seed:
- fidx ret / fidx vol: mean annual log return of the mainland index, and the annual sd of its quarterly log returns.
- fidx VR5: variance ratio of its 5-year log changes over five 1-year ones (overlapping windows). Low power: in 20
  years a 5-year give-back shows mostly as a trend, which the sample variance removes; read the paired log(fidx B /
  fidx A) instead, which moves only by the give-back.
- board VR5: the same for the board's cap, for comparison.
- rel 5y sd: sd of 5-year changes in log(board cap / mainland index): how far the two drift apart over five years.
- corr 5y: correlation of the board's and the mainland's 5-year log changes.
- fund/GDP mean, fund/GDP sd: the reserve fund over GDP across the run.
"""
import json, math, os, statistics as st, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se, paired, fmt  # noqa: E402

H = os.path.dirname(os.path.abspath(__file__))
MEASURES = ['fidx ret', 'fidx vol', 'fidx VR5', 'board VR5', 'rel 5y sd', 'corr 5y', 'fund/GDP mean', 'fund/GDP sd']


def seeds_of(arg):
    if '-' in arg:
        a, b = arg.split('-')
        return list(range(int(a), int(b) + 1))
    return [int(x) for x in arg.split(',')]


def corr(a, b):
    ma, mb, sa, sb = st.fmean(a), st.fmean(b), st.pstdev(a), st.pstdev(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / len(a) / (sa * sb) if sa > 0 and sb > 0 else math.nan


def changes(xs, lag):
    return [xs[i + lag] - xs[i] for i in range(len(xs) - lag)]


def seed_measures(arm, seed):
    rows = [json.loads(line) for line in open(f'{H}/runs/{arm}-{seed}.macro.jsonl')]
    rows = [r for r in rows if r['t'] >= 1.0 and r['fidx'] > 0 and r['cap'] > 0]
    fidx = [math.log(r['fidx']) for r in rows]
    cap = [math.log(r['cap']) for r in rows]
    years = rows[-1]['t'] - rows[0]['t']
    vr = lambda xs: st.pvariance(changes(xs, 20)) / (5.0 * st.pvariance(changes(xs, 4)))
    return {
        'fidx ret': (fidx[-1] - fidx[0]) / years,
        'fidx vol': 2.0 * st.pstdev(changes(fidx, 1)),
        'fidx VR5': vr(fidx),
        'board VR5': vr(cap),
        'rel 5y sd': st.pstdev(changes([c - f for c, f in zip(cap, fidx)], 20)),
        'corr 5y': corr(changes(cap, 20), changes(fidx, 20)),
        'fund/GDP mean': st.fmean(r['fund'] for r in rows),
        'fund/GDP sd': st.pstdev(r['fund'] for r in rows),
    }


def main():
    seeds, arms = seeds_of(sys.argv[1]), sys.argv[2:]
    per = {a: {s: seed_measures(a, s) for s in seeds if os.path.exists(f'{H}/runs/{a}-{s}.macro.jsonl')} for a in arms}
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

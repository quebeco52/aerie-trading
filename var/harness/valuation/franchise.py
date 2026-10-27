#!/usr/bin/env python3
"""franchise.py <seeds> <arm> [<arm> ...]: franchise power and runaway valuations at the last quarter, from
runs/<arm>-<seed>.val.json, with paired differences of each later arm against the first.

- p sd / max |p| / n |p|>2: cross-sectional sd, maximum absolute value and count above 2 of franchise power
  (franchise_rows) at the run's end; p sd y10: the same sd at year 10.
- SAFE p: the reinsurer's power at the end (also printed per seed).
- P/E max / P/E p90: price over the stock's EPS among names with positive EPS (rows slots 1 and 3).
- top cap share: largest float-adjusted cap over the board's (rows slot 5); P/F max: largest price over fair value.
"""
import json, math, os, statistics as st, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se, paired, fmt  # noqa: E402

H = os.path.dirname(os.path.abspath(__file__))


def seeds_of(arg):
    if '-' in arg:
        a, b = arg.split('-')
        return list(range(int(a), int(b) + 1))
    return [int(x) for x in arg.split(',')]


def at(rows, t):
    return min(rows, key=lambda r: abs(r[0] - t))[1]


def measure(arm, seed):
    D = json.load(open(f'{H}/runs/{arm}-{seed}.val.json'))
    fr = D['franchise_rows']
    end = fr[-1][1]
    p = [v[0] for v in end.values()]
    out = {'p sd': st.pstdev(p), 'p sd y10': st.pstdev(v[0] for v in at(fr, 10.0).values()),
           'max |p|': max(abs(x) for x in p), 'n |p|>2': sum(1 for x in p if abs(x) > 2),
           'SAFE p': end['SAFE'][0] if 'SAFE' in end else math.nan}
    last = D['rows'][-1][1]
    pe = sorted(v[1] / v[3] for v in last.values() if v[3] > 0)
    out['P/E max'] = pe[-1]
    out['P/E p90'] = pe[int(0.9 * (len(pe) - 1))]
    caps = [v[5] for v in last.values()]
    out['top cap share'] = max(caps) / sum(caps)
    out['P/F max'] = max(v[1] / v[2] for v in last.values() if v[2] > 0)
    top = max(end.items(), key=lambda kv: abs(kv[1][0]))
    out['_top'] = f'{top[0]} {top[1][0]:+.2f}'
    out['_pe'] = max(((k, v[1] / v[3]) for k, v in last.items() if v[3] > 0), key=lambda kv: kv[1])[0]
    return out


def main():
    seeds = seeds_of(sys.argv[1])
    arms = sys.argv[2:]
    per = {a: {s: measure(a, s) for s in seeds if os.path.exists(f'{H}/runs/{a}-{s}.val.json')} for a in arms}
    keys = [k for k in next(iter(per[arms[0]].values())) if not k.startswith('_')]
    print(f"{'measure':14s}" + ''.join(f'{a + " mean (se)":>22s}' for a in arms)
          + ''.join(f'{a + " - " + arms[0]:>34s}' for a in arms[1:]))
    for k in keys:
        line = f'{k:14s}'
        for a in arms:
            m = mean_se([v[k] for v in per[a].values()])
            line += f'{m.mean:+12.4f} ({m.se:.4f})'
        for a in arms[1:]:
            pr = paired({s: v[k] for s, v in per[arms[0]].items()}, {s: v[k] for s, v in per[a].items()})
            line += f'{fmt(pr, 4):>34s}'
        print(line)
    print('\nper seed: SAFE p | largest |p| name | highest P/E name')
    for s in seeds:
        print(f'seed {s}: ' + ' || '.join(
            f"{a} {per[a][s]['SAFE p']:+.2f} | {per[a][s]['_top']} | {per[a][s]['_pe']} {per[a][s]['P/E max']:.0f}"
            for a in arms if s in per[a]))


if __name__ == '__main__':
    main()

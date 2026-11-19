"""Per-firm moves between two arms: whole-run median ROE (financials) or ROIC proxy (operating), mean over seeds.

python3 moat_firms.py base moat   (from this directory); prints the 8 largest rises and falls and their spread.
"""

import statistics as st
import sys

from scorecard import load, split_adjusted, max_drawdown


def firm_levels(run):
    out = {}
    for t, f in run['final'].items():
        q = [r for r in f['q'] if r.get('rev', 0) > 0 or r.get('eq', 0) > 0]
        if len(q) < 8:
            continue
        fin = run['meta'].get(t, {}).get('fin', False)
        if fin:
            v = [4 * r['ni'] / r['eq'] for r in q if r['eq'] > 0]
        else:
            v = [3 * r['ebit'] / (r['eq'] + r['debt'] - r['cash']) for r in q if r['eq'] + r['debt'] - r['cash'] > 0]
        if v:
            out[t] = (st.median(v), 'fin' if fin else 'ops', max_drawdown(split_adjusted(q)))
    return out


def main():
    a, b = sys.argv[1:3]
    ra, rb = load(a), load(b)
    seeds = sorted(set(ra) & set(rb))
    diffs, dda, ddb = {}, {}, {}
    for s in seeds:
        la, lb = firm_levels(ra[s]), firm_levels(rb[s])
        for t in set(la) & set(lb):
            diffs.setdefault(t, []).append((lb[t][0] - la[t][0], la[t][1]))
            dda.setdefault(t, []).append(la[t][2])
            ddb.setdefault(t, []).append(lb[t][2])
    rows = sorted((st.mean(d for d, _ in v), t, v[0][1]) for t, v in diffs.items() if len(v) == len(seeds))
    for g in ('ops', 'fin'):
        ds = [d for d, _, k in rows if k == g]
        print(f'{g}: {len(ds)} firms, return-level diff median {st.median(ds):+.4f}, p10 {sorted(ds)[len(ds) // 10]:+.4f}, '
              f'p90 {sorted(ds)[9 * len(ds) // 10]:+.4f}')
    print('largest falls:', ', '.join(f'{t}({k}) {d:+.3f}' for d, t, k in rows[:8]))
    print('largest rises:', ', '.join(f'{t}({k}) {d:+.3f}' for d, t, k in rows[-8:]))
    dd = sorted((st.mean(ddb[t]) - st.mean(dda[t]), t) for t in dda if len(dda[t]) == len(seeds))
    print('worst-drawdown deepened most:', ', '.join(f'{t} {st.mean(dda[t]):+.2f}->{st.mean(ddb[t]):+.2f}' for _, t in dd[:6]))


if __name__ == '__main__':
    main()

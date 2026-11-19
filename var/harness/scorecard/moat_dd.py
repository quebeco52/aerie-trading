"""Drawdown >80% attribution between two arms: paired counts per run, and per firm the seeds where only one arm crossed.

python3 moat_dd.py base moat   (from this directory).
"""

import os
import sys
from collections import Counter

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se, paired, fmt  # noqa: E402
from scorecard import load, split_adjusted, max_drawdown  # noqa: E402


def deep(run):
    """Firms whose worst split-adjusted drawdown is below -80%, with the same filter as scorecard.py."""
    out = {}
    for t, f in run['final'].items():
        q = [r for r in f['q'] if r.get('rev', 0) > 0]
        if len(q) < 8:
            continue
        out[t] = max_drawdown(split_adjusted(q))
    return out


def main():
    a, b = sys.argv[1], sys.argv[2]
    ra, rb = load(a), load(b)
    seeds = sorted(set(ra) & set(rb))
    da = {s: deep(ra[s]) for s in seeds}
    db = {s: deep(rb[s]) for s in seeds}
    cnt = lambda d: {s: sum(1 for v in d[s].values() if v < -0.80) for s in seeds}
    for name, fa, fb in [('dd>80% count', cnt(da), cnt(db)),
                         ('defaulters', {s: len(ra[s]['defaults']) for s in seeds},
                          {s: len(rb[s]['defaults']) for s in seeds}),
                         ('deaths', {s: len(ra[s]['dead']) for s in seeds}, {s: len(rb[s]['dead']) for s in seeds})]:
        ma, mb = mean_se(fa.values()), mean_se(fb.values())
        print(f'{name:14s} {a} {ma.mean:.2f} ({ma.se:.2f})  {b} {mb.mean:.2f} ({mb.se:.2f})  diff {fmt(paired(fa, fb), 2)}')
    ca, cb = cnt(da), cnt(db)
    print('per seed ' + a + '/' + b + ': ' + ' '.join(f's{s} {ca[s]}/{cb[s]}' for s in seeds))
    only_b, only_a, both = Counter(), Counter(), Counter()
    for s in seeds:
        for t in set(da[s]) | set(db[s]):
            xa, xb = da[s].get(t, 0) < -0.80, db[s].get(t, 0) < -0.80
            if xa and xb:
                both[t] += 1
            elif xb:
                only_b[t] += 1
            elif xa:
                only_a[t] += 1
    firms = sorted(set(only_a) | set(only_b), key=lambda t: (only_a[t] - only_b[t], t))
    print(f'firm: seeds >80% only in {b} / only in {a} / both (net)')
    print('  ' + ', '.join(f'{t} {only_b[t]}/{only_a[t]}/{both[t]} ({only_b[t] - only_a[t]:+d})' for t in firms))
    for t in ['SKUA'] + [t for t in firms[:4] if t != 'SKUA']:
        xa = {s: da[s].get(t, 0.0) for s in seeds if t in da[s] and t in db[s]}
        xb = {s: db[s].get(t, 0.0) for s in seeds if t in da[s] and t in db[s]}
        print(f'  {t} worst drawdown {mean_se(xa.values()).mean:+.2f} -> {mean_se(xb.values()).mean:+.2f}  '
              f'diff {fmt(paired(xa, xb), 2)}; deeper in {sum(xb[s] < xa[s] for s in xa)}/{len(xa)} seeds')


if __name__ == '__main__':
    main()

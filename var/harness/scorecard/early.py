"""Opening records and REIT dividends in the first years.
Usage (from this directory): python3 early.py <arm> [<arm>...]
- Opening records >= 5y lost by the first quarter's report (streak below the opening one) and by the end of year 1,
  by business model.
- REITs in years 0-2: quarters where the declared DPS sits on the legal floor (within 0.5% of 0.9 x the quarter's net
  income per share; 0.9 is the assumed minimum distribution ratio), mid-year rises, and DPS falls.
"""
import os
import sys
from collections import Counter

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from payout import load, at, dps_adj  # noqa: E402

REIT = 'ReitBusinessModel'
FLOOR = 0.9


def main():
    for arm in sys.argv[1:]:
        runs = load(arm)
        n, q1, y1 = 0, Counter(), Counter()
        reit = Counter()
        for s, r in sorted(runs.items()):
            for tk, m in r['meta'].items():
                rows = r['final'][tk]['q']
                st0 = m.get('st0') or 0
                if st0 >= 5 and rows:
                    n += 1
                    if (rows[0].get('streak') or 0) < st0:
                        q1[m['model'].replace('BusinessModel', '')] += 1
                    e1 = at(rows, 1.0)
                    if e1 is not None and (e1.get('streak') or 0) < st0:
                        y1[m['model'].replace('BusinessModel', '')] += 1
                if m['model'] != REIT:
                    continue
                reit['firm-runs'] += 1
                reit['opening records >=5'] += st0 >= 5
                adj, _ = dps_adj(rows)
                for i, row in enumerate(rows):
                    if row['t'] > 2.0:
                        break
                    nips = row['ni'] / row['shares'] if row['shares'] > 0 else 0.0
                    if row['dps'] > 0 and nips > 0 and abs(row['dps'] - FLOOR * nips) <= 0.005 * row['dps']:
                        reit['quarters on the floor'] += 1
                        reit['  of which quarter 1'] += i == 0
                    if i and adj[i - 1] > 0:
                        g = adj[i] / adj[i - 1] - 1
                        reit['DPS falls >0.5%'] += g < -0.005
                        reit['DPS rises'] += g > 1e-4
                    reit['quarters'] += 1
        fmt = lambda c: ', '.join(f'{k} {v}' for k, v in c.most_common()) or 'none'
        print(f'{arm}: {n} opening records >= 5y over {len(runs)} seeds')
        print(f'  lost by the first report: {sum(q1.values())}  ({fmt(q1)})')
        print(f'  lost by end of year 1:    {sum(y1.values())}  ({fmt(y1)})')
        print('  REITs years 0-2: ' + ', '.join(f'{k} {v}' for k, v in reit.items()))


if __name__ == '__main__':
    main()

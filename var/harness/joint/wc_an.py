#!/usr/bin/env python3
"""wc_an.py <seeds> <armA> <armB>: the trade cycle, leases and credit of the firms whose working capital and leases are
declared by stream, arm A against arm B, seed-paired. Balances are shares of annualised revenue, averaged over each
firm's reports; write-downs and receivable provisions are summed over the run as a share of its revenue."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
A, B = sys.argv[2], sys.argv[3]
FIRMS = ['WEAV', 'JDAW', 'HUMM', 'TICK', 'SHRK', 'BREW', 'SWFT', 'LARK', 'PHIL', 'SGRB', 'PINT', 'CROP', 'SHER']
RATING = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']


def firm_stats(arm, seed):
    fin = json.loads(open(f"{H}/runs/{arm}-{seed}.jsonl").readline())['final']
    out = {}
    for t in FIRMS:
        v = fin.get(t)
        if not v or not v['q']:
            continue
        q = [r for r in v['q'] if r['rev'] > 0]
        share = lambda k: 100 * sum(r.get(k, 0.0) / (4 * r['rev']) for r in q) / len(q)
        rev = sum(r['rev'] for r in q)
        last = v['q'][-1]
        nd = [r['ndE'] for r in q if r.get('ndE') is not None and math.isfinite(r['ndE'])]
        out[t] = {
            'recv': share('recv'), 'inv': share('inv'), 'pay': share('pay'), 'lease': share('lease'),
            'invwd bp': 1e4 * sum(r.get('invwd', 0.0) for r in q) / rev,
            'recprov bp': 1e4 * sum(r.get('recprov', 0.0) for r in q) / rev,
            'ND/EBITDA': st.median(nd) if nd else float('nan'),
            'rating notch end': RATING.index(last['rating']) if last['rating'] in RATING else float('nan'),
            'equity/assets %': 100 * last['eq'] / last['ta'] if last['ta'] else float('nan'),
            'dead': 1.0 if v['dead'] else 0.0,
        }
    return out


data = {arm: {s: firm_stats(arm, s) for s in seeds} for arm in (A, B)}
keys = ['recv', 'inv', 'pay', 'lease', 'invwd bp', 'recprov bp', 'ND/EBITDA', 'equity/assets %', 'rating notch end', 'dead']
print(f"{len(seeds)} seeds, {A} -> {B}: mean over seeds (paired difference, SE)")
for t in FIRMS:
    cells = []
    for k in keys:
        pairs = [(data[A][s][t][k], data[B][s][t][k]) for s in seeds if t in data[A][s] and t in data[B][s]
                 and math.isfinite(data[A][s][t][k]) and math.isfinite(data[B][s][t][k])]
        if not pairs:
            cells.append(f"{k} n/a")
            continue
        a = sum(p[0] for p in pairs) / len(pairs)
        b = sum(p[1] for p in pairs) / len(pairs)
        d = [p[1] - p[0] for p in pairs]
        se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
        cells.append(f"{k} {a:.2f}->{b:.2f} ({se:.2f})")
    print(f"{t}: " + '; '.join(cells))

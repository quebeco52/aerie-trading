#!/usr/bin/env python3
"""k_an.py <seeds> <armA> <armB>: batch K (POOL capital target, SWAN lore ROE 0.22 -> 0.16). Unpaired A vs B:
per seed CAGR of the summed market cap (price x shares, first vs last report row) of firms with >= 70 report rows,
excluding SWAN and POOL; failures, Ch11, payment defaults, rescue raises per run; board (div+bb)/NI+ and CW dividend
yield (as j_an.py C); SWAN/POOL opening price and cap (tick 1 of ticks.csv). Diff se = sqrt(sa^2/na + sb^2/nb).
Base arm 'baseK' seeds 1-8 are symlinks to fixH runs."""
import collections, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
A, B = sys.argv[2:4]
EXCL = {'SWAN', 'POOL'}


def per_seed(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    fin = r['final']
    c0 = c1 = 0.0; t0 = []; t1 = []
    for tk, v in fin.items():
        q = v['q']
        if tk in EXCL or len(q) < 70:
            continue
        c0 += q[0]['price'] * q[0]['shares']; c1 += q[-1]['price'] * q[-1]['shares']
        t0.append(q[0]['t']); t1.append(q[-1]['t'])
    yrs = st.mean(t1) - st.mean(t0)
    o = {'mcap': (c1 / c0) ** (1 / yrs) - 1}
    o['fail'] = len(r['dead'] or []); o['ch11'] = len(r['reorgs'] or []); o['pdef'] = len(r['defaults'] or [])
    o['resc'] = sum(1 for es in r['events'].values() for e in es
                    if 'stave off bankruptcy' in e['d'] and 'Issued new shares' in e['d'])
    pd = pn = 0.0; yd, yc = collections.defaultdict(float), collections.defaultdict(float)
    for tk, v in fin.items():
        q = v['q']
        if not q:
            continue
        ni = sum(x['ni'] for x in q)
        if ni > 0:
            pn += ni; pd += sum(x['div'] + x['bb'] for x in q)
        for x in q:
            yd[int(x['t'])] += x['div']
        for y in set(int(x['t']) for x in q):
            last = [x for x in q if int(x['t']) == y][-1]; yc[y] += last['price'] * last['shares']
    o['pay'] = pd / pn; o['cwdy'] = st.mean([yd[y] / yc[y] for y in yc if yc[y] > 0 and y < 20])
    with open(f'{H}/runs/{arm}-{s}.ticks.csv') as f:
        hdr = f.readline().strip().split(','); row = f.readline().strip().split(',')
    for tk in ('SWAN', 'POOL'):
        p = float(row[hdr.index(tk + '_p')]); n = float(row[hdr.index(tk + '_n')])
        o[tk + '_p0'] = p; o[tk + '_cap0'] = p * n / 1e9
        q = fin[tk]['q']
        o[tk + '_div'] = sum(x['div'] for x in q) / 1e9; o[tk + '_bb'] = sum(x['bb'] for x in q) / 1e9
    return o


res = {arm: [per_seed(arm, s) for s in seeds] for arm in (A, B)}
rows = [('mcap', 'board mkt-cap CAGR ex SWAN/POOL %', 100), ('fail', 'failures/run', 1), ('ch11', 'Ch11/run', 1),
        ('pdef', 'payment defaults/run', 1), ('resc', 'rescue raises/run', 1), ('pay', 'board (div+bb)/NI+', 1),
        ('cwdy', 'CW dividend yield %', 100), ('SWAN_p0', 'SWAN opening price', 1), ('SWAN_cap0', 'SWAN opening cap $B', 1),
        ('POOL_p0', 'POOL opening price', 1), ('POOL_cap0', 'POOL opening cap $B', 1),
        ('SWAN_div', 'SWAN dividends $B/run', 1), ('SWAN_bb', 'SWAN buybacks $B/run', 1),
        ('POOL_div', 'POOL dividends $B/run', 1), ('POOL_bb', 'POOL buybacks $B/run', 1)]
print(f'| quantity | {A} mean (sd) | {B} mean (sd) | diff (se) | t | n |')
print('|---|---|---|---|---|---|')
for k, lab, m in rows:
    a = [m * x[k] for x in res[A]]; b = [m * x[k] for x in res[B]]
    sa, sb = st.stdev(a), st.stdev(b); d = st.mean(b) - st.mean(a); se = math.sqrt(sa ** 2 / len(a) + sb ** 2 / len(b))
    t = d / se if se > 0 else float('nan')
    print(f'| {lab} | {st.mean(a):.3g} ({sa:.2g}) | {st.mean(b):.3g} ({sb:.2g}) | {d:+.3g} ({se:.2g}) | {t:+.2f} | {len(a)}/{len(b)} |')
print('\nper-seed mkt-cap CAGR %: ' + A + ' ' + ' '.join(f"{100 * x['mcap']:.1f}" for x in res[A]) +
      ' | ' + B + ' ' + ' '.join(f"{100 * x['mcap']:.1f}" for x in res[B]))

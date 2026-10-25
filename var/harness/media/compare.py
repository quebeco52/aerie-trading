#!/usr/bin/env python3
"""BELL side by side across arms (seeds 1-4): per-seed means of the behaviour table, pooled slump quarters, political
share by quarter of the term. Usage: compare.py live tuned. Full-market seeds do not pair across arms: indicative."""
import json, math, statistics as st, sys
from pathlib import Path

P = Path(__file__).parent
ARMS = sys.argv[1:] or ['live', 'tuned']
ORDER = [a + b for a in ('AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C') for b in ('+', '', '-')] + ['D']
S3 = ('carriage_fees', 'airtime_advertising', 'political_advertising')
T = {}


def put(arm, m, v):
    T.setdefault(m, {}).setdefault(arm, []).append(v)


for arm in ARMS:
    slump_rev, slump_eb, all_rev, all_eb = [], [], [], []
    pol = {}
    for s in range(1, 5):
        d = json.load(open(P / 'runs' / f'{arm}-s{s}.json'))
        o, q, daily = d['opening']['BELL'], d['q']['BELL'], d['daily']
        idx = [r['i'] for r in daily]
        put(arm, 'open revenue $B', o['rev'] / 1e9); put(arm, 'open margin %', o['om'] * 100)
        put(arm, 'open P/E', o['px'] / o['eps']); put(arm, 'open cap tick 1 $B', d['cap1']['BELL'] / 1e9)
        rev = [r['rev'] for r in q]; om = [r['om'] for r in q]; ni = [r['ni'] for r in q]; sh = [r['sh'] for r in q]
        eb = [r['ebit'] for r in q]
        ny = len(q) // 4
        ann = [sum(rev[4 * y:4 * y + 4]) for y in range(ny)]
        put(arm, 'revenue CAGR %', ((ann[-1] / ann[0]) ** (1 / (ny - 1)) - 1) * 100)
        put(arm, 'margin mean %', st.fmean(om) * 100); put(arm, 'margin min %', min(om) * 100)
        put(arm, 'margin max %', max(om) * 100)
        eps_y = [sum(ni[4 * y:4 * y + 4]) / sh[4 * y + 3] for y in range(ny)]
        put(arm, 'EPS growth SD %', st.stdev([(b - a) / abs(a) for a, b in zip(eps_y, eps_y[1:])]) * 100)
        px, adj = [], 1.0
        for i, r in enumerate(daily):
            if i and daily[i - 1]['BELL_sh'] > 0:
                ratio = r['BELL_sh'] / daily[i - 1]['BELL_sh']
                if ratio > 1.2 or ratio < 0.8:
                    adj *= ratio
            px.append(r['BELL'] * adj)
        lr = [math.log(b / a) for a, b in zip(px, px[1:])]
        put(arm, 'price vol %', st.stdev(lr) * math.sqrt(len(daily) / ny) * 100)
        rw = [math.log(px[i + 5] / px[i]) for i in range(0, len(px) - 5, 5)]
        mw = [math.log(idx[i + 5] / idx[i]) for i in range(0, len(idx) - 5, 5)]
        ma, mb = st.fmean(rw), st.fmean(mw)
        put(arm, 'weekly beta', sum((x - ma) * (y - mb) for x, y in zip(rw, mw)) / (len(rw) - 1) / st.variance(mw))
        peak, dd = px[0], 0.0
        for p in px:
            peak = max(peak, p); dd = min(dd, p / peak - 1)
        put(arm, 'max drawdown %', dd * 100)
        dps = [r['div'] / r['sh'] for r in q]
        put(arm, 'dividend cuts', sum(1 for a, b in zip(dps, dps[1:]) if a > 0 and b < 0.9 * a))
        put(arm, 'worst rating (index)', max(ORDER.index(r['rating']) if r['rating'] in ORDER else 99 for r in q))
        bt = [r for r in q if r['ceps'] is not None and r['reps'] is not None]
        put(arm, 'EPS beat rate %', st.fmean(1.0 if r['reps'] > r['ceps'] else 0.0 for r in bt) * 100)
        air = [r['streams']['airtime_advertising'] for r in q]
        ay = [math.log(b / a) for a, b in zip(air, air[4:])]
        g4 = [r['gap'] for r in q][4:]
        put(arm, 'airtime yoy SD %', st.stdev(ay) * 100)
        put(arm, 'corr(airtime yoy, gap EMA)', st.correlation(ay, g4))
        put(arm, 'blackouts / 20y', len(d.get('blackouts', {}).get('BELL', [])))
        put(arm, 'slump quarters', sum(1 for v in g4 if v < -0.015))
        yoy = [b / a - 1 for a, b in zip(rev, rev[4:])]
        eby = [(b - a) / abs(a) for a, b in zip(eb, eb[4:])]
        all_rev += yoy; all_eb += eby
        slump_rev += [yoy[i] for i, v in enumerate(g4) if v < -0.015]
        slump_eb += [eby[i] for i, v in enumerate(g4) if v < -0.015]
        for r in q:
            pos = int(math.floor(round(r['tt'] * 4, 6)) - 1) % 16
            pol.setdefault(pos, []).append(r['streams']['political_advertising'] / sum(r['streams'][k] for k in S3))
    T.setdefault('slump rev yoy - mean % (pooled)', {})[arm] = [(st.fmean(slump_rev) - st.fmean(all_rev)) * 100] if slump_rev else [float('nan')]
    T.setdefault('slump EBIT yoy - mean % (pooled)', {})[arm] = [(st.fmean(slump_eb) - st.fmean(all_eb)) * 100] if slump_eb else [float('nan')]
    T.setdefault('_pol', {})[arm] = pol

print(f'{"BELL, seeds 1-4 mean":34s}' + ''.join(f'{a:>12s}' for a in ARMS))
for m, by in T.items():
    if m == '_pol':
        continue
    cells = []
    for a in ARMS:
        v = st.fmean(by[a])
        cells.append(f'{ORDER[round(max(by[a]))] if m.startswith("worst") else f"{v:.2f}":>12s}')
    print(f'{m:34s}' + ''.join(cells))
for a in ARMS:
    pol = T['_pol'][a]
    print(f'political % by term quarter, {a}: q0-11 max {max(max(pol[p]) for p in pol if p < 12) * 100:.3f}; '
          + ' '.join(f'q{p} {st.fmean(pol[p]) * 100:.2f}' for p in range(12, 16)))

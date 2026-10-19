#!/usr/bin/env python3
"""tpy.py: B360 vs B3600 slump moments over all quarters from year 0 (no burn-in), against the live dump."""
import json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
ARMS = sys.argv[1:] or ['B360', 'B3600']
SEEDS = range(1, 9)
W = 0.8358
LIVE = {'gap%': (-0.60, 'le'), 'gap<-2': (0.36, 'ge'), 'deep_q': (29, 'ge'), 'pol_floor': (0.18, 'ge'),
        'fed_floor': (0.33, 'ge'), 'fgap%': (-0.97, 'le'), 'push_dd': (-19.8, 'le')}
COLS = ['quarters', 'gap%', 'gap<-2', 'deep_q', 'neg_q', 'pol_floor', 'fed_floor', 'fgap%', 'fneg_q', 'push_tot',
        'push_dd', 'worst_rgq%', 'bad']


def run_len(xs, pred):
    best = cur = 0
    for x in xs:
        cur = cur + 1 if pred(x) else 0
        best = max(best, cur)
    return best


def stats(path):
    rows = [json.loads(l) for l in open(path) if l.strip()]
    rows = [r for r in rows if r['t'] > 0][:72]
    g = [r['gap'] for r in rows]
    fg = [r['fgap'] for r in rows]
    bad = sum(1 for r in rows for k in ('gap', 'fgap', 'policy', 'rgq') if not isinstance(r.get(k), (int, float)) or math.isnan(r[k]))
    cum, peak, dd, c = [], 0.0, 0.0, 0.0
    for r in rows:
        c += r['dmain'] * 0.25 * W * 100
        peak = max(peak, c)
        dd = min(dd, c - peak)
    n = len(rows)
    return {'quarters': n, 'gap%': sum(g) / n * 100, 'gap<-2': sum(x < -0.02 for x in g) / n,
            'deep_q': run_len(g, lambda x: x < -0.015), 'neg_q': run_len(g, lambda x: x < 0),
            'pol_floor': sum(r['policy'] <= 0.0025 for r in rows) / n, 'fed_floor': sum(r['rf'] <= 0.0025 for r in rows) / n,
            'fgap%': sum(fg) / n * 100, 'fneg_q': run_len(fg, lambda x: x < 0), 'push_tot': c, 'push_dd': dd,
            'worst_rgq%': min(r['rgq'] for r in rows) * 100, 'bad': bad}


def fmt(v):
    return f'{v:8.2f}' if isinstance(v, float) else f'{v:8d}'


res = {}
print('Per run (72 quarters from year 0, no burn-in; push in pp of gap)')
print(f'{"run":10s}' + ''.join(f'{c:>10s}' for c in COLS))
for a in ARMS:
    res[a] = []
    for s in SEEDS:
        p = f'{H}/runs/{a}-{s}.macro.jsonl'
        if not os.path.exists(p) or os.path.getsize(p) == 0:
            continue
        st = stats(p)
        res[a].append(st)
        print(f'{a + "-" + str(s):10s}' + ''.join(f'{fmt(st[c]):>10s}' for c in COLS))

print('\nSummary: mean [min, max] over seeds; reach = seeds at or beyond the live value')
print(f'{"quantity":12s}{"live":>8s}' + ''.join(f'{a + " mean [min, max] reach":>36s}' for a in ARMS) + f'{"diff (se) t":>24s}')
for c in COLS[1:-1]:
    line = f'{c:12s}' + (f'{LIVE[c][0]:8.2f}' if c in LIVE else f'{"":>8s}')
    ms = []
    for a in ARMS:
        xs = [r[c] for r in res[a]]
        if not xs:
            line += f'{"-":>36s}'; ms.append(None); continue
        m = sum(xs) / len(xs)
        v = sum((x - m) ** 2 for x in xs) / (len(xs) - 1) if len(xs) > 1 else 0.0
        reach = ''
        if c in LIVE:
            lv, d = LIVE[c]
            reach = f'{sum(1 for x in xs if (x <= lv if d == "le" else x >= lv))}/{len(xs)}'
        line += f'{m:9.2f} [{min(xs):7.2f},{max(xs):7.2f}] {reach:>5s}  '
        ms.append((m, v, len(xs)))
    if len(ms) == 2 and all(ms):
        d = ms[1][0] - ms[0][0]
        se = math.sqrt(ms[0][1] / ms[0][2] + ms[1][1] / ms[1][2])
        line += f'{d:9.2f} ({se:5.2f}) {d / se if se > 0 else 0:5.1f}'
    print(line)

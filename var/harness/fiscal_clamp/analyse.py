#!/usr/bin/env python3
"""Corporate-tax clamp: how often the unclamped target leaves [MIN, MAX], what it is made of then, and its distribution.
python3 analyse.py [arm ...]  (default A B); reads runs/<arm>-<seed>.jsonl, prints the tables only."""
import glob, json, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))


def pct(xs, ps):
    xs = sorted(xs)
    if not xs:
        return ['-'] * len(ps)
    return [xs[min(len(xs) - 1, int(p / 100 * len(xs)))] for p in ps]


def mse(xs):
    n = len(xs)
    return (st.mean(xs), st.stdev(xs) / n ** 0.5 if n > 1 else float('nan'))


for arm in sys.argv[1:] or ['A', 'B']:
    files = sorted(glob.glob(f'{H}/runs/{arm}-*.jsonl'))
    files = [f for f in files if not f.endswith('summary.jsonl') and '-99.' not in f]
    if not files:
        continue
    rows, lo_sh, hi_sh, lo_ever, hi_ever, years = [], [], [], 0, 0, []
    for f in files:
        r = [json.loads(l) for l in open(f)]
        if not r:
            continue
        rows += r
        lo = sum(x['target'] < x['min'] for x in r) / len(r)
        hi = sum(x['target'] > x['max'] for x in r) / len(r)
        lo_sh.append(lo); hi_sh.append(hi)
        lo_ever += lo > 0; hi_ever += hi > 0
        years.append(r[-1]['t'])
    n = len(lo_sh)
    print(f'== Arm {arm}: {n} seeds, {min(years):.0f}-{max(years):.0f} years, {len(rows)} quarters')
    print('1. binding share | pooled | mean(se) across seeds | max seed | seeds ever')
    for name, sh, ever, key in (('below MIN', lo_sh, lo_ever, 'lo'), ('above MAX', hi_sh, hi_ever, 'hi')):
        m, s = mse(sh)
        pooled = sum((x['target'] < x['min']) if key == 'lo' else (x['target'] > x['max']) for x in rows) / len(rows)
        print(f'   {name} | {pooled:.4%} | {m:.4%} ({s:.4%}) | {max(sh):.3%} | {ever}/{n}')
    print('2. decomposition at binding quarters (means; law/stab/stance sum with base 0.17 to target)')
    keys = ['target', 'law', 'stab', 'stance', 'bohn', 'deficit', 'cut', 'net_debt', 'gap_ema', 'rate']
    print('   side | n | ' + ' | '.join(keys) + ' | funded')
    sets = {'below': [x for x in rows if x['target'] < x['min']], 'above': [x for x in rows if x['target'] > x['max']],
            'all': rows}
    for side, b in sets.items():
        if not b:
            print(f'   {side} | 0'); continue
        print(f'   {side} | {len(b)} | ' + ' | '.join(f"{st.mean(x[k] for x in b):+.4f}" for k in keys)
              + f" | {sum(x['funded'] for x in b) / len(b):.2f}")
    for side, b in (('below', sets['below']), ('above', sets['above'])):
        if b:
            d = [(x['min'] - x['target']) if side == 'below' else (x['target'] - x['max']) for x in b]
            p = pct(d, [50, 90, 99])
            print(f'   depth past bound ({side}) p50/p90/p99/max: ' + '/'.join(f'{v:.4f}' for v in p) + f'/{max(d):.4f}')
            sb = sorted({x['seed'] for x in b})
            print(f'   {side}: seeds {sb}; first t {min(x["t"] for x in b):.1f}y')
    print('3. distribution p1/p5/p50/p95/p99 (min, max)')
    for k in ('target', 'rate'):
        v = [x[k] for x in rows]
        print(f'   {k} | ' + '/'.join(f'{q:.4f}' for q in pct(v, [1, 5, 50, 95, 99])) + f' ({min(v):.4f}, {max(v):.4f})')
    for k in ('stab', 'stance', 'law', 'net_debt'):
        v = [x[k] for x in rows]
        print(f'   {k} | ' + '/'.join(f'{q:+.4f}' for q in pct(v, [1, 5, 50, 95, 99])))

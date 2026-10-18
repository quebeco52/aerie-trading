#!/usr/bin/env python3
"""wr_recovery.py: slump recoveries per arm, paired vs A (runs/W<arm>-<seed>.macro.jsonl). Env SEEDS (1-16), BURN (2).

Episode: the quarterly gap falls below -2%; it ends when the gap is back to >= 0. Trough = the episode's minimum.
Per episode: quarters trough -> gap >= 0 (censored episodes, which never close, are counted and dropped from that row),
gap rise over the 8 quarters after the trough (dropped if the run ends first), and over trough..trough+8 the mean
policy - (r* + 2%), headline and supercore inflation and wage growth. Seed value = mean over its episodes; a seed with
no episode in either arm of a pair drops from that pair.
"""
import json, math, os, statistics as st
H = os.path.dirname(os.path.abspath(__file__))
def seeds(s):
    out = []
    for p in s.split(','):
        a, _, b = p.partition('-'); out += list(range(int(a), int(b or a) + 1))
    return out
SEEDS = seeds(os.environ.get('SEEDS', '1-16')); BURN = float(os.environ.get('BURN', 2)); ARMS = ['A', 'B', 'C']
def mean(x): return sum(x) / len(x) if x else None

def episodes(rows):
    g = [r['gap'] for r in rows]; out = []; i = 0
    while i < len(g):
        if g[i] < -0.02:
            j = i
            while j < len(g) and g[j] < 0: j += 1
            seg = range(i, j); tr = min(seg, key=lambda k: g[k])
            out.append((tr, j if j < len(g) else None)); i = j + 1
        else:
            i += 1
    return out

def seed_stats(arm, s):
    p = f'{H}/runs/W{arm}-{s}.macro.jsonl'
    if not os.path.exists(p): return None
    rows = [json.loads(l) for l in open(p)]; rows = [r for r in rows if r['t'] > BURN + 1e-9]
    ep = episodes(rows); n = len(rows)
    q0 = [end - tr for tr, end in ep if end is not None]
    rise = [rows[tr + 8]['gap'] - rows[tr]['gap'] for tr, _ in ep if tr + 8 < n]
    win = [rows[tr:tr + 9] for tr, _ in ep if tr + 8 < n]
    f = lambda key: [mean([key(r) for r in w]) for w in win]
    return {
        'episodes': len(ep), 'censored': sum(1 for _, e in ep if e is None), 'trough gap %': mean([100 * rows[tr]['gap'] for tr, _ in ep]),
        'q trough->0': mean(q0), 'rise 8q pp': mean([100 * v for v in rise]),
        'policy-(r*+2%) 8q pp': mean([100 * v for v in f(lambda r: r['policy'] - r['rstar'] - 0.02)]),
        'infl 8q %': mean([100 * v for v in f(lambda r: r['infl'])]), 'supercore 8q %': mean([100 * v for v in f(lambda r: r['supercore'])]),
        'wage growth 8q %': mean([100 * v for v in f(lambda r: r['wg_avg'])]),
        'wage-target 8q pp': mean([100 * v for v in f(lambda r: r['wg_avg'] - r['wtgt_avg'])]),
    }

data = {a: {s: seed_stats(a, s) for s in SEEDS} for a in ARMS}
keys = list(next(v for v in data['A'].values() if v))
print(f"slump recoveries (gap < -2%), years {BURN:g}+ ; arm cells: mean over seeds; pair cells: Δ (se, t) n")
print(f"{'quantity':24s}" + ''.join(f'{a:>8s}' for a in ARMS) + ''.join(f'{b + "-A":>24s}' for b in ARMS[1:]))
for k in keys:
    cells = []
    for a in ARMS:
        v = [data[a][s][k] for s in SEEDS if data[a][s] and data[a][s][k] is not None]
        cells.append(f'{(sum(v) if k in ("episodes", "censored") else mean(v)) or 0:8.2f}')
    pr = []
    for b in ARMS[1:]:
        d = [data[b][s][k] - data['A'][s][k] for s in SEEDS if data[b][s] and data['A'][s] and data[b][s][k] is not None and data['A'][s][k] is not None]
        if len(d) > 1:
            m = mean(d); se = st.stdev(d) / math.sqrt(len(d)); pr.append(f'{m:+.2f} ({se:.2f}, {m / se if se else 0:+.1f}) {len(d):2d}')
        else: pr.append(f'{"n/a":>24s}')
    print(f'{k:24s}' + ''.join(cells) + ''.join(f'{c:>24s}' for c in pr))
print('episodes, censored: totals over seeds')

#!/usr/bin/env python3
"""dc_analyse.py: drag compensators, paired B-A (runs/D<arm>-<seed>.macro.jsonl from dc_run.sh); fork of gb_analyse.py.

Env SEEDS (1-8), BURN (2 years dropped), TARGET (0.02 inflation target). Arms A B C D; paired B-A, C-A, D-A, D-C.
Channel attribution of the mean gap: the gap is W*y plus level parts (supply, nx, fin, inv). The demand gap y has
own pull (KALDOR_MOMENTUM - KALDOR_AUTOMATIC_STABILISER) = -1.44/yr, so in the mean W*E[y] = 4*sum_k E[gc_k]/1.44 over
the domestic channels k other than momentum and the stabiliser (gc is W*rate*dt over a quarter, so 4*gc is W*rate a
year), plus the clamp. Level parts enter at their mean level. The closure row compares that sum with the measured gap.
"""
import json, math, os, statistics as st

H = os.path.dirname(os.path.abspath(__file__))
def seeds(s):
    out = []
    for p in s.split(','):
        a, _, b = p.partition('-'); out += list(range(int(a), int(b or a) + 1))
    return out
SEEDS = seeds(os.environ.get('SEEDS', '1-8')); BURN = float(os.environ.get('BURN', 2)); TGT = float(os.environ.get('TARGET', 0.02))
ARMS = ['A', 'B']; PAIRS = [('B', 'A')]
PULL = 1.5 - 0.06
LEVEL = {'productivitySupply': 'supply', 'netExports': 'nx', 'financeOutput': 'fin', 'inventoryInvestment': 'inv', 'spendingTiming': 'tim'}
SKIP = {'momentum', 'automaticStabiliser'}

def mean(x): return sum(x) / len(x) if x else float('nan')
def skew(x):
    m = mean(x); s = st.pstdev(x); return mean([(v - m) ** 3 for v in x]) / s ** 3 if s > 0 else 0.0
def dti_ar1(rows):
    # year-end DTI (last row of each simulated year), annual change, its lag-1 autocorrelation
    ye = {}
    for r in rows: ye[int(r['t'] - 1e-9)] = r['dti']
    v = [ye[k] for k in sorted(ye)]; d = [v[i] - v[i - 1] for i in range(1, len(v))]
    return acf(d, 1) if len(d) > 3 else float('nan')
def acf(x, k):
    m = mean(x); d = sum((v - m) ** 2 for v in x)
    return sum((x[i] - m) * (x[i + k] - m) for i in range(len(x) - k)) / d if d > 0 else 0.0

def seed_stats(arm, seed):
    p = f'{H}/runs/D{arm}-{seed}.macro.jsonl'
    if not os.path.exists(p): return None
    rows = [json.loads(l) for l in open(p)]
    rows = [r for r in rows if r['t'] > BURN + 1e-9]
    if not rows: return None
    g = [r['gap'] for r in rows]; W = rows[0]['W']; yrs = rows[-1]['t'] - BURN
    rec = [bool(r['rec']) for r in rows]; onsets = sum(1 for i in range(1, len(rec)) if rec[i] and not rec[i - 1])
    o = {
        'gap mean %': 100 * mean(g), 'gap median %': 100 * st.median(g), 'share gap>0': mean([v > 0 for v in g]),
        'share gap<-3%': mean([v < -0.03 for v in g]), 'DTI chg AR1 (annual)': dti_ar1(rows), 'gap sd %': 100 * st.pstdev(g), 'gap skew': skew(g), 'gap acf4': acf(g, 4),
        'infl % (inst)': 100 * mean([r['infl'] for r in rows]), 'infl EMA %': 100 * mean([r['infl_ema'] for r in rows]),
        'supercore %': 100 * mean([r['supercore'] for r in rows]), 'core goods %': 100 * mean([r['core_goods'] for r in rows]),
        'infl sd %': 100 * st.pstdev([r['infl'] for r in rows]),
        'u - NAIRU pp': 100 * mean([r['u'] - r['nairu'] for r in rows]),
        'policy - (r*+2%) pp': 100 * mean([r['policy'] - r['rstar'] - TGT for r in rows]),
        'rule target - (r*+2%) pp': 100 * mean([r['target'] - r['rstar'] - TGT for r in rows]),
        'r* %': 100 * mean([r['rstar'] for r in rows]),
        'recessions /decade': 10 * onsets / yrs, 'blow-up (|gap|>15%)': float(max(abs(v) for v in g) > 0.15),
        # levers
        'corp tax rate %': 100 * mean([r['tax_rate'] for r in rows]), 'net tax rate %': 100 * mean([r['net_tax'] for r in rows]),
        'public spending share %': 100 * mean([r['spend_law'] for r in rows]), 'labour force growth %': 100 * mean([r['lfg'] for r in rows]),
        'cyclical migration %': 100 * mean([r['mig'] for r in rows]), 'reserve draw share %': 100 * mean([r['draw_share'] for r in rows]),
        'fund draw/GDP %': 100 * mean([r['fund_draw'] for r in rows]), 'debt target %': 100 * mean([r['debt_target'] for r in rows]),
        'primary deficit/GDP %': 100 * mean([r['primary'] for r in rows]), 'debt/GDP %': 100 * mean([r['debt'] for r in rows]),
        'consolidation tax share': mean([r['consol'] for r in rows]),
    }
    ch = {}
    for r in rows:
        for k, v in r['gc'].items(): ch[k] = ch.get(k, 0.0) + v
    n = len(rows); att = {}
    for k, v in ch.items():
        if k in SKIP: continue
        att[k] = 100 * mean([r[LEVEL[k]] for r in rows]) if k in LEVEL else 100 * 4 * (v / n) / PULL
    att['clamp'] = 100 * 4 * mean([r['gc_clamp'] for r in rows]) / PULL
    o['_gc'] = {k: 100 * v / n for k, v in ch.items()}  # raw mean gc, pp per quarter
    o['_att'] = att
    o['W*y measured %'] = 100 * mean([r['gap'] - r['supply'] - r['nx'] - r['fin'] - r['inv'] - r['tim'] for r in rows])
    o['W*y predicted %'] = sum(v for k, v in att.items() if k not in LEVEL)
    o['gap predicted %'] = sum(att.values())
    return o

data = {a: {s: seed_stats(a, s) for s in SEEDS} for a in ARMS}
ok = [s for s in SEEDS if all(data[a][s] for a in ARMS)]
def col(a, k, sub=None): return [data[a][s][sub][k] if sub else data[a][s][k] for s in ok]
def pair(b, a, k, sub=None):
    d = [x - y for x, y in zip(col(b, k, sub), col(a, k, sub))]
    m = mean(d); se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    return f'{m:+.3f} ({se:.3f}, {m / se if se > 0 else 0:+.1f})'

print(f'n = {len(ok)} paired seeds {ok[0]}-{ok[-1]}, years {BURN:g}+ ; cells: Δ (se, t)')
hdr = f"{'quantity':28s}" + ''.join(f'{a:>9s}' for a in ARMS) + ''.join(f'{b + "-" + a:>23s}' for b, a in PAIRS)
print(hdr)
keys = [k for k in data['A'][ok[0]] if not k.startswith('_')]
for k in keys:
    print(f'{k:28s}' + ''.join(f'{mean(col(a, k)):9.3f}' for a in ARMS) + ''.join(f'{pair(b, a, k):>23s}' for b, a in PAIRS))
print('\nChannel attribution of the mean gap, pp of GDP (domestic: 4*mean gc/1.44; level parts: mean level)')
allk = sorted({k for a in ARMS for s in ok for k in data[a][s]['_att']}, key=lambda k: mean(col('A', k, '_att')) if all(k in data['A'][s]['_att'] for s in ok) else 0)
for a in ARMS:
    for s in ok:
        for k in allk: data[a][s]['_att'].setdefault(k, 0.0)
print(hdr)
for k in allk:
    print(f'{k:28s}' + ''.join(f'{mean(col(a, k, "_att")):9.3f}' for a in ARMS) + ''.join(f'{pair(b, a, k, "_att"):>23s}' for b, a in PAIRS))
print('\nRaw mean gc per quarter, pp (stabiliser and momentum included)')
for k in ['automaticStabiliser', 'momentum']:
    print(f'{k:28s}' + ''.join(f'{mean([data[a][s]["_gc"].get(k, 0.0) for s in ok]):9.4f}' for a in ARMS))

#!/usr/bin/env python3
"""Ten-year decomposition: python3 y10_decomp.py <arm> [seeds]. Reads runs/<arm>-<seed>.macro.jsonl (DECOMP=1 rows),
drops year 1, splits y10 into the pieces calculateYieldCurve() adds and reports, per piece, sd, ACF4 and its share of
var(y10) and of var(4-quarter change of y10) (cov(piece, total)/var(total), shares sum to one)."""
import json, math, os, sys
H = os.path.dirname(os.path.abspath(__file__))
L1 = (1 - math.exp(-3.0)) / 3.0                       # slope load, lambda 0.30, tau 10
L2 = (1 - math.exp(-7.3)) / 7.3 - math.exp(-7.3)      # curvature 1, lambda 0.73
L4 = (1 - math.exp(-1.5)) / 1.5 - math.exp(-1.5)      # curvature 2, lambda 0.15
OWN, GLB = math.sqrt(1 - 0.45 ** 2), 0.45
BASE_TP = 0.0115

def acf(x, k):
    n = len(x); m = sum(x) / n; v = sum((a - m) ** 2 for a in x)
    return sum((x[i] - m) * (x[i + k] - m) for i in range(n - k)) / v if v > 0 else float('nan')
def cov(a, b):
    ma, mb = sum(a) / len(a), sum(b) / len(b); return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / (len(a) - 1)
def sd(x): return math.sqrt(cov(x, x))
def d4(x): return [x[i + 4] - x[i] for i in range(len(x) - 4)]
def d1(x): return [x[i + 1] - x[i] for i in range(len(x) - 1)]
def detrend(x):
    n = len(x); mt = (n - 1) / 2; mx = sum(x) / n
    b = sum((i - mt) * (v - mx) for i, v in enumerate(x)) / sum((i - mt) ** 2 for i in range(n))
    return [v - mx - b * (i - mt) for i, v in enumerate(x)]
def corr(a, b): return cov(a, b) / (sd(a) * sd(b))

def pieces(r):
    be_part = 0.5 * (r['rstar'] + r['be10']) * (1 - L1)
    pnr_part = 0.5 * r['pnr'] * (1 - L1)
    pol = r['policy'] * L1
    stance = 0.85 * (r['target'] - r['policy']) * L2
    gap_b2 = 0.15 * r['gap'] * L2
    path = r['path'] * L2
    fisc = r['b3'] * L4
    tp_shock = OWN * r['tp_shock'] + GLB * r['ftp_shock']
    tp_regime = BASE_TP + OWN * (r['tp_regime'] - BASE_TP) + GLB * (r['ftp_regime'] - BASE_TP)
    other_tp = r['base_tp'] - tp_shock - tp_regime          # sovereign spread, IRP, cyclical gap, flight, floor
    built = r['level'] * (1 - L1) + (r['level'] + r['b1']) * L1 + r['b2'] * L2 + fisc + r['base_tp']
    return {'r*+BE (level)': be_part, 'perceived neutral (level)': pnr_part, 'policy x L1': pol,
            'target-policy (b2)': stance, 'gap (b2)': gap_b2, 'path shock (b2)': path, 'fiscal b3': fisc,
            'TP shock': tp_shock, 'TP regime': tp_regime, 'TP other': other_tp, 'habitat/floor': r['y10'] - built}

arm = sys.argv[1]
seeds = [int(s) for s in (sys.argv[2].split(',') if len(sys.argv) > 2 else '1,2,3,4'.split(','))]
agg = {}
for s in seeds:
    rows = [json.loads(l) for l in open(f'{H}/runs/{arm}-{s}.macro.jsonl') if l.strip()]
    rows = [r for r in rows if r['t'] > 1.0 + 1e-9]
    y = [r['y10'] for r in rows]; P = [pieces(r) for r in rows]
    chk = max(abs(sum(p.values()) - v) for p, v in zip(P, y))
    tot = {'y10': y, 'tp10': [r['tp10'] for r in rows], 'rn10': [r['rn10'] for r in rows], 'be10': [r['be10'] for r in rows],
           'policy': [r['policy'] for r in rows], 'target': [r['target'] for r in rows], 'ig': [r['ig'] for r in rows]}
    for k, v in tot.items():
        a = agg.setdefault(k, {'sd': [], 'acf4': [], 'acf4_dt': [], 'sd_dq': [], 'mean': []})
        a['sd'].append(100 * sd(v)); a['acf4'].append(acf(v, 4)); a['acf4_dt'].append(acf(detrend(v), 4))
        a['sd_dq'].append(100 * sd(d1(v))); a['mean'].append(100 * sum(v) / len(v))
    agg.setdefault('corr(d4 tp10, d4 rn10)', {'v': []})['v'].append(corr(d4(tot['tp10']), d4(tot['rn10'])))
    for k in P[0]:
        c = [p[k] for p in P]
        a = agg.setdefault('~' + k, {'sd': [], 'acf4': [], 'var_share': [], 'd4_share': []})
        a['sd'].append(100 * sd(c)); a['acf4'].append(acf(c, 4) if sd(c) > 1e-9 else 0.0)
        a['var_share'].append(cov(c, y) / cov(y, y)); a['d4_share'].append(cov(d4(c), d4(y)) / cov(d4(y), d4(y)))
    print(f'seed {s}: closure max err {chk:.2e}, y10 acf4 {acf(y, 4):.2f}, sd(d4 y10) {100 * sd(d4(y)):.2f}pp')
print(f'{arm}, n={len(seeds)} seeds, means across seeds')
for k, a in agg.items():
    print(f'{k:30s} ' + '  '.join(f'{m} {sum(v) / len(v):+.3f}' for m, v in a.items()))

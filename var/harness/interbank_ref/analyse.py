#!/usr/bin/env python3
"""analyse.py <seeds> [runs_dir] [stress=0.004] [tpy=360]: before vs after tables for the interbank reference rate change.

Prints only the final tables. ROE = sum of the window's quarterly net income / mean tangible equity; target =
max(opening market cost of equity, baseline ROE), as resolveCalibrationRoe() strikes it."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
RUNS = sys.argv[2] if len(sys.argv) > 2 else os.path.join(H, 'runs')
STRESS = float(sys.argv[3]) if len(sys.argv) > 3 else 0.004
TPY = int(sys.argv[4]) if len(sys.argv) > 4 else 360
FIN = json.load(open(os.path.join(H, 'runs', 'fin_map.json')))
LENDERS = 'LAKE RIVR PLVR STRK EGRT BNTH POOL TALN'.split()
ARMS = ['before', 'after']
LADDER = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D']


def notch(r):
    r = (r or '').rstrip('+-')
    return LADDER.index(r) if r in LADDER else None


def load(arm, s):
    with open(os.path.join(RUNS, f'{arm}-{s}.jsonl')) as f:
        return json.loads(f.readline())


def roe(q, lo, hi):
    w = [x for x in q if lo < x['t'] <= hi]
    if len(w) < 2:
        return None
    eq = st.mean(x['teq'] for x in w)
    return sum(x['ni'] for x in w) * (4.0 / len(w)) / eq if eq > 0 else None


def mse(v):
    v = [x for x in v if x is not None]
    if not v:
        return 'n/a'
    if len(v) == 1:
        return f'{v[0]:+.3g}'
    return f'{st.mean(v):+.3g} ({st.stdev(v) / math.sqrt(len(v)):.2g})'


R = {a: {s: load(a, s) for s in seeds} for a in ARMS}
years = R['after'][seeds[0]]['years']
mid = years / 2
out = []
p = out.append

# 5. Pairing: the first macro row where the arms differ.
p('== Pairing: first quarter the macro path differs (policy, interbank, gap, inflation, u) ==')
for s in seeds:
    mb, ma = R['before'][s]['macro'], R['after'][s]['macro']
    first = next((x['t'] for x, y in zip(mb, ma) if any(abs(x[k] - y[k]) > 1e-12 for k in ('pol', 'ib', 'gap', 'infl', 'u'))), None)
    p(f'seed {s}: identical at tick 1: {mb[0] == ma[0]}; first differing quarter-row t={first}')

# 1. Lenders: ROE - target, pp.
p(f'\n== Lender ROE minus target (pp), windows year 1 (0,1], mid ({mid - 1:g},{mid:g}], end ({years - 1:g},{years:g}] ==')
p('ticker target | yr1 before  after  | mid before  after  | end before  after  | d_end mean (se)')
for t in LENDERS:
    cells, dend = [], []
    tgt = None
    for lo, hi in ((0, 1), (mid - 1, mid), (years - 1, years)):
        for a in ARMS:
            v = []
            for s in seeds:
                r = R[a][s]
                q = r['final'][t]['q']
                tg = max(r['mcoe0'][1], q[0]['broe']) if q else None
                tgt = tg
                x = roe(q, lo, hi)
                v.append(None if x is None or r['final'][t]['dead'] else 100 * (x - tg))
            cells.append(mse(v))
    for s in seeds:
        xb = roe(R['before'][s]['final'][t]['q'], years - 1, years)
        xa = roe(R['after'][s]['final'][t]['q'], years - 1, years)
        if xb is not None and xa is not None:
            dend.append(100 * (xa - xb))
    p(f'{t:5s} {100 * tgt:5.1f}% | ' + ' | '.join(f'{cells[i]:>14s} {cells[i + 1]:>14s}' for i in (0, 2, 4)) + f' | {mse(dend)}')
p('per-seed end-window ROE - target (pp), before/after:')
for t in LENDERS:
    row = []
    for s in seeds:
        z = []
        for a in ARMS:
            r = R[a][s]
            q = r['final'][t]['q']
            x = roe(q, years - 1, years)
            z.append('dead' if r['final'][t]['dead'] else ('n/a' if x is None else f'{100 * (x - max(r["mcoe0"][1], q[0]["broe"])):+.1f}'))
        row.append('/'.join(z))
    p(f'  {t:5s} ' + '  '.join(f's{s}:{c}' for s, c in zip(seeds, row)))

# 2. Lenders in stress.
p(f'\n== Lender pre-tax profit in quarters with interbank EMA > {100 * STRESS:.2f}% ==')
p('seed | stress q before after | lender PTI/q (all lenders, bn) before after | non-stress PTI/q before after')
for s in seeds:
    c = []
    for a in ARMS:
        qs = [x for t in LENDERS for x in R[a][s]['final'][t]['q']]
        st_ = [x for x in qs if x['ib'] > STRESS]
        ns = [x for x in qs if x['ib'] <= STRESS]
        nq = len({x['t'] // 0.25 for x in st_})
        c.append((nq, sum(x['pti'] for x in st_) / max(1, nq) / 1e9, sum(x['pti'] for x in ns) / max(1, len({x['t'] // 0.25 for x in ns})) / 1e9))
    p(f'{s:4d} | {c[0][0]:3d} {c[1][0]:3d} | {c[0][1]:9.2f} {c[1][1]:9.2f} | {c[0][2]:9.2f} {c[1][2]:9.2f}')
for a in ARMS:
    ibmax = max(x['ib'] for s in seeds for x in R[a][s]['macro'])
    p(f'{a}: interbank EMA max {100 * ibmax:.2f}% over all seeds')

# 3. Non-financial firms.
p('\n== Non-financial firms (per seed: before / after) ==')
NF = [t for t, v in FIN.items() if not v[2]]
rows = {k: {a: [] for a in ARMS} for k in ('int', 'icr_med', 'icr_min', 'icr_p5', 'down', 'up', 'deflt', 'dead', 'closed_stress', 'closed_any')}
for a in ARMS:
    for s in seeds:
        r = R[a][s]
        ints, icrs, down, up, cs, ca = 0.0, [], 0, 0, set(), set()
        for t in NF:
            q = r['final'][t]['q']
            ints += sum(x['int'] for x in q)
            icrs += [x['icr'] for x in q if x.get('icr') is not None and x['int'] > 0 and x['t'] > 1]
            for x, y in zip(q, q[1:]):
                nx, ny = notch(x['rating']), notch(y['rating'])
                if nx is not None and ny is not None:
                    down += ny > nx
                    up += ny < nx
            cs |= {t for x in q if x['ib'] > STRESS and x['open'] is False}
            ca |= {t for x in q if x['open'] is False}
        rows['int'][a].append(ints / years / 1e9)
        rows['icr_med'][a].append(st.median(icrs))
        rows['icr_min'][a].append(min(icrs))
        rows['icr_p5'][a].append(sorted(icrs)[len(icrs) // 20])
        rows['down'][a].append(down)
        rows['up'][a].append(up)
        rows['deflt'][a].append(sum(1 for t in r['defaults'] if t in NF))
        rows['dead'][a].append(sum(1 for t in r['dead'] if t in NF))
        rows['closed_stress'][a].append(len(cs))
        rows['closed_any'][a].append(len(ca))
labels = {'int': 'interest expense, bn/yr', 'icr_med': 'ICR median (firm-qtrs, yr>1)', 'icr_p5': 'ICR 5th pct',
          'icr_min': 'ICR minimum', 'down': 'downgrades (notches)', 'up': 'upgrades (notches)', 'deflt': 'firms in payment default',
          'dead': 'bankruptcies', 'closed_stress': 'firms shut out, stress qtr', 'closed_any': 'firms shut out, any qtr'}
p('quantity | ' + ' | '.join(f's{s}' for s in seeds) + ' | mean before | mean after | d mean (se)')
for k, lab in labels.items():
    b, a_ = rows[k]['before'], rows[k]['after']
    d = [y - x for x, y in zip(b, a_)]
    p(f'{lab:30s} | ' + ' | '.join(f'{x:.3g}/{y:.3g}' for x, y in zip(b, a_)) + f' | {mse(b)} | {mse(a_)} | {mse(d)}')

# 4. Market sanity.
p('\n== Market sanity ==')
m = {k: {a: [] for a in ARMS} for k in ('vol', 'mdd', 'nonfin', 'jerr', 'neg_eq', 'pol_lo', 'pol_hi', 'spr_hi', 'all_dead')}
for a in ARMS:
    for s in seeds:
        r = R[a][s]
        idx = r['idx'][1:]
        m['vol'][a].append(st.pstdev(idx) * math.sqrt(TPY) * 100)
        lvl, peak, mdd = 1.0, 1.0, 0.0
        for x in idx:
            lvl *= 1 + x
            peak = max(peak, lvl)
            mdd = max(mdd, 1 - lvl / peak)
        m['mdd'][a].append(100 * mdd)
        m['nonfin'][a].append(r['nonfinite'])
        m['jerr'][a].append(1 if r.get('json_error') else 0)
        m['neg_eq'][a].append(sum(1 for t, f in r['final'].items() if not f['dead'] and f['q'] and f['q'][-1]['eq'] < 0))
        m['pol_lo'][a].append(100 * min(x['pol'] for x in r['macro']))
        m['pol_hi'][a].append(100 * max(x['pol'] for x in r['macro']))
        m['spr_hi'][a].append(max((x['spread'] for f in r['final'].values() for x in f['q'] if x['spread'] == x['spread']), default=0) * 100)
        m['all_dead'][a].append(len(r['dead']))
labels = {'vol': 'index ann. vol %', 'mdd': 'index max drawdown %', 'nonfin': 'non-finite price/cap ticks', 'jerr': 'NaN/INF in record',
          'neg_eq': 'live firms, equity<0 at end', 'pol_lo': 'policy rate min %', 'pol_hi': 'policy rate max %',
          'spr_hi': 'max firm credit spread %', 'all_dead': 'bankruptcies (all)'}
p('quantity | ' + ' | '.join(f's{s}' for s in seeds) + ' | mean before | mean after | d mean (se)')
for k, lab in labels.items():
    b, a_ = m[k]['before'], m[k]['after']
    d = [y - x for x, y in zip(b, a_)]
    p(f'{lab:30s} | ' + ' | '.join(f'{x:.3g}/{y:.3g}' for x, y in zip(b, a_)) + f' | {mse(b)} | {mse(a_)} | {mse(d)}')
print('\n'.join(out))

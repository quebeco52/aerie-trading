#!/usr/bin/env python3
"""analyse.py <seeds> [runs_dir] [tpy=360]: before vs after tables for the credit-cycle opening.

Per arm, mean (se) over seeds; seeds do not pair across arms. Lender charge-offs over the through-the-cycle charge on
the gross book, lender ROE (net income over tangible equity) over its calibration target, the non-financial
receivables provision, and the board index's drawdown and volatility."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
RUNS = sys.argv[2] if len(sys.argv) > 2 else os.path.join(H, 'runs')
TPY = int(sys.argv[3]) if len(sys.argv) > 3 else 360
ARMS = ['before', 'after']
LENDERS = 'LAKE RIVR PLVR STRK EGRT BNTH TALN'.split()


def mse(v, scale=1.0, fmt='{:.3f}'):
    v = [x for x in v if x is not None]
    if len(v) < 2:
        return 'n/a'
    return (fmt + ' ({:.3f})').format(scale * st.mean(v), scale * st.stdev(v) / math.sqrt(len(v)))


def load(arm, s):
    with open(os.path.join(RUNS, f'{arm}-{s}.jsonl')) as f:
        rec = json.loads(f.readline())
    with open(os.path.join(RUNS, f'{arm}-{s}.lq.json')) as f:
        lq = json.load(f)
    return rec, lq


R = {a: {s: load(a, s) for s in seeds} for a in ARMS}
years = int(R['after'][seeds[0]][0]['years'])
out = []
p = out.append


def window(rows, t, lo, hi):
    return [r for r in rows if r['tk'] == t and lo < r['t'] <= hi]


# 1. Charge-offs over the through-the-cycle charge, all lenders pooled (charge-off weighted), by year.
p('== Lender charge-offs / TTC charge on the gross book, all lenders pooled, by year: mean (se) over seeds ==')
p('year | ' + ' | '.join(f'{a:>15s}' for a in ARMS) + ' | macro household EMA/trend, corporate EMA/trend (before / after)')
for y in range(1, min(years, 5) + 1):
    cells, mac = [], []
    for a in ARMS:
        v, rd, cd = [], [], []
        for s in seeds:
            rows = R[a][s][1]['rows']
            nco = ttc = 0.0
            for t in LENDERS:
                for r in window(rows, t, y - 1, y):
                    nco += r['nco']
                    ttc += r['mdl']['ttc'] / 4 * (r['gross'] + r['nco'])
                    if t == 'LAKE':
                        rd.append(r['mac']['rdE'] / r['mac']['rdT'])
                        cd.append(r['mac']['cdE'] / r['mac']['cdT'])
            v.append(nco / ttc if ttc > 0 else None)
        cells.append(mse(v))
        mac.append(f'{st.mean(rd):.3f}, {st.mean(cd):.3f}')
    p(f'{y:4d} | ' + ' | '.join(f'{c:>15s}' for c in cells) + ' | ' + ' / '.join(mac))
p('per lender, years 1-5 (before -> after):')
for t in LENDERS:
    c = []
    for a in ARMS:
        v = []
        for s in seeds:
            w = window(R[a][s][1]['rows'], t, 0, 5)
            ttc = sum(r['mdl']['ttc'] / 4 * (r['gross'] + r['nco']) for r in w)
            v.append(sum(r['nco'] for r in w) / ttc if ttc > 0 else None)
        c.append(mse(v))
    p(f'  {t:5s} {c[0]} -> {c[1]}')

# 2. Lender ROE over target by year.
p('\n== Lender NI x 4 / mean tangible equity / calibration target, by year: mean (se) over seeds ==')
p('lender arm    | ' + ' '.join(f'{"yr" + str(y):>13s}' for y in range(1, years + 1)))
pooled = {a: {y: [] for y in range(1, years + 1)} for a in ARMS}
for t in LENDERS:
    for a in ARMS:
        cells = []
        for y in range(1, years + 1):
            v = []
            for s in seeds:
                rec, lq = R[a][s]
                tgt = lq['calib'][t].get('roe_tgt')
                w = window(lq['rows'], t, y - 1, y)
                if tgt and w and not rec['final'][t]['dead']:
                    v.append(sum(r['ni'] for r in w) * 4 / len(w) / st.mean(r['te'] for r in w) / tgt)
            cells.append(mse(v, fmt='{:.2f}').replace(' (', '(').replace(')', ')'))
            pooled[a][y].append(st.mean(v) if v else None)
        p(f'{t:5s} {a:6s} | ' + ' '.join(f'{c:>13s}' for c in cells))
p('all lenders, equal-weighted mean of the per-lender means:')
for a in ARMS:
    p(f'      {a:6s} | ' + ' '.join(f'{st.mean([x for x in pooled[a][y] if x is not None]):>13.3f}' for y in range(1, years + 1)))

# 3. Non-financial receivables provision.
p('\n== Non-financial receivables provision, bn a year and % of receivables: mean (se) over seeds ==')
p('year | ' + ' | '.join(f'{a + " bn":>17s} {a + " %rec":>17s}' for a in ARMS))
for y in [1, 2, 3, 4, 5, 6, 7, 8][:years]:
    cells = []
    for a in ARMS:
        bn, pct = [], []
        for s in seeds:
            nf = [x for x in R[a][s][1]['nonfin'] if y - 1 < x[0] <= y]
            prov = sum(x[2] for x in nf)
            rec = sum(x[3] for x in nf) / 4
            bn.append(prov / 1e9)
            pct.append(100 * prov / rec if rec > 0 else None)
        cells.append(f'{mse(bn, fmt="{:.3f}"):>17s} {mse(pct, fmt="{:.3f}"):>17s}')
    p(f'{y:4d} | ' + ' | '.join(cells))

# 4. Board.
p('\n== Board: index max drawdown and annualised vol, bankruptcies: mean (se) over seeds ==')
for a in ARMS:
    mdd, vol, dead = [], [], []
    for s in seeds:
        idx = R[a][s][0]['idx'][1:]
        lvl = peak = 1.0
        m = 0.0
        for x in idx:
            lvl *= 1 + x
            peak = max(peak, lvl)
            m = max(m, 1 - lvl / peak)
        mdd.append(100 * m)
        vol.append(100 * st.pstdev(idx) * math.sqrt(TPY))
        dead.append(len(R[a][s][0]['dead']))
    p(f'{a:6s}: max drawdown {mse(mdd, fmt="{:.1f}")}%, vol {mse(vol, fmt="{:.1f}")}%, bankruptcies {mse(dead, fmt="{:.2f}")}')
print('\n'.join(out))

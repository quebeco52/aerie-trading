#!/usr/bin/env python3
"""Behaviour table for NCKR, WALL, NJAR (new) beside WATCH, OSPR from runs/<arm>-s<seed>.json. Usage: analyse.py [arm].
Each row prints min..max across seeds and the mean."""
import glob, json, math, statistics as st, sys
from pathlib import Path

arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
NSEED = int(sys.argv[2]) if len(sys.argv) > 2 else 99
runs = sorted(glob.glob(str(Path(__file__).parent / 'runs' / f'{arm}-s[0-9]*.json')), key=lambda p: int(p.split('-s')[-1][:-5]))[:NSEED]
D = [json.load(open(p)) for p in runs]
FIRMS = ['NCKR', 'WALL', 'NJAR', 'WATCH', 'OSPR']
ORDER = [a + b for a in ('AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C') for b in ('+', '', '-')] + ['D']
TPY = 360
rows = {}
PCT = set()


def put(m, f, v, pct=False):
    rows.setdefault(m, {}).setdefault(f, []).append(v)
    if pct:
        PCT.add(m)


def corr(a, b):
    return st.correlation(a, b) if len(a) > 2 and st.pstdev(a) > 0 and st.pstdev(b) > 0 else float('nan')


def slope(x, y):
    mx, my = st.fmean(x), st.fmean(y)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sum((a - mx) ** 2 for a in x)


def rvol(a):
    n, s, ss = a
    return math.sqrt(max(0.0, ss / n - (s / n) ** 2) * TPY) if n > 10 else float('nan')


board_ratio = []
for d in D:
    B = d['board']
    ratios = [rvol(d['rv'][t]) / B[t]['sigma'] for t in d['rv'] if B.get(t, {}).get('sigma', 0) > 0 and t not in d['dead']]
    board_ratio.append(st.median(ratios))
    idx = [r['i'] for r in d['daily']]
    for f in FIRMS:
        o, q, b = d['opening'][f], d['q'][f], B[f]
        put('open cap bn', f, d['cap1'][f] / 1e9)
        put('open P/E', f, b['px'] / b['eps'] if b['eps'] > 0 else float('nan'))
        put('open P/B', f, b['px'] / b['bvps'])
        pe = [r['px'] / r['eps'] for r in q if r['eps'] > 0]
        late = [r['px'] / r['eps'] for r in q[-40:] if r['eps'] > 0]
        put('P/E median y3-30', f, st.median([r['px'] / r['eps'] for r in q[8:] if r['eps'] > 0]))
        put('P/E median last 10y', f, st.median(late))
        put('P/B last q', f, q[-1]['px'] / q[-1]['bvps'] if q[-1]['bvps'] > 0 else float('nan'))
        rev = [r['rev'] for r in q]; om = [r['om'] for r in q]; ni = [r['ni'] for r in q]
        ny = len(q) // 4
        ann = [sum(rev[4 * y:4 * y + 4]) for y in range(ny)]
        put('rev CAGR', f, (ann[-1] / ann[0]) ** (1 / (ny - 1)) - 1, True)
        put('om mean', f, st.fmean(om), True); put('om min', f, min(om), True); put('om max', f, max(om), True)
        put('om last 5y mean', f, st.fmean(om[-20:]), True)
        put('loss q share', f, sum(1 for x in ni if x < 0) / len(ni), True)
        rv = rvol(d['rv'][f])
        put('realised vol', f, rv, True); put('configured vol', f, b['sigma'], True)
        put('rv / configured', f, rv / b['sigma'])
        cap = [r[f] for r in d['daily']]
        rw = [math.log(cap[i + 5] / cap[i]) for i in range(TPY, len(cap) - 5, 5) if cap[i] > 0 and cap[i + 5] > 0]
        mw = [math.log(idx[i + 5] / idx[i]) for i in range(TPY, len(idx) - 5, 5) if cap[i] > 0 and cap[i + 5] > 0]
        put('beta wk (cfg)', f, slope(mw, rw))
        rows.setdefault('beta cfg', {}).setdefault(f, []).append(b['beta'])
        peak, dd = cap[0], 0.0
        for p in cap:
            peak = max(peak, p); dd = min(dd, p / peak - 1)
        put('maxDD (mkt cap)', f, dd, True)
        put('worst rating', f, ORDER[max(ORDER.index(r['rating']) if r['rating'] in ORDER else len(ORDER) - 1 for r in q)])
        put('open rating', f, o['rating'])
        put('end D/E', f, q[-1]['debt'] / q[-1]['eq'] if q[-1]['eq'] > 0 else float('nan'))
        put('dead/dflt/reorg', f, f"{int(f in d['dead'])}/{(d.get('defaults') or {}).get(f, 0)}/{sum(1 for r in d.get('reorgs', []) if r['t'] == f)}")
        put('distress q', f, sum(1 for r in q if r['qid'] or r['pd']))
        put('treasury distress lines', f, len((d.get('distress_lines') or {}).get(f, [])))
        for s in ('cash_heist', 'security_breach', 'geopolitical_conflict', 'client_file_leak', 'prison_disturbance'):
            put(s, f, d['shocks'].get(f, {}).get(s, 0) if isinstance(d['shocks'], dict) else 0)
        names = sorted({k for r in q for k in r['streams']}, key=lambda k: -q[0]['streams'].get(k, 0))
        put('streams', f, '/'.join(n[:6] for n in names))
        for y0 in ((0, 9, 19, 29) if f == 'WATCH' else (0, 29)):
            rr = q[4 * y0:4 * y0 + 4]
            tot = sum(v for r in rr for v in r['streams'].values())
            put(f'mix y{y0 + 1}', f, '/'.join(f"{sum(r['streams'].get(n, 0) for r in rr) / tot:.2f}" for n in names))
        if f == 'WALL':
            occ = [r['kpi']['prison_occupancy'] for r in q]
            put('occupancy min..max', f, f"{min(occ):.3f}..{max(occ):.3f}")
            put('occ q at <=85% floor', f, sum(1 for x in occ if x <= 0.85 + 1e-9) / len(occ), True)
            put('occ q at 100% cap', f, sum(1 for x in occ if x >= 1.0 - 1e-9) / len(occ), True)
            cr = [r['kpi']['concession_rate_index'] for r in q]
            put('concession min..max', f, f"{min(cr):.3f}..{max(cr):.3f}")
            put('concession y1/5/10/20/30', f, '/'.join(f'{cr[min(len(cr) - 1, 4 * y - 1)]:.3f}' for y in (1, 5, 10, 20, 30)))
            g = [r['gsi'] for r in q]
            put('gov spending idx min..max', f, f"{min(g):.1f}..{max(g):.1f}")
            ug = [r['ugap'] for r in q]
            put('unemp gap min..max pp', f, f"{min(ug) * 100:.2f}..{max(ug) * 100:.2f}")
            ts = list(range(len(q)))
            lc = [math.log(r['streams']['custody']) for r in q]
            b = slope(ts, lc)
            put('corr(custody detrended, ugap)', f, corr([y - b * t for y, t in zip(lc, ts)], ug))
            put('corr(custody detr, ugap lag 6q)', f, corr([y - b * t for y, t in zip(lc[6:], ts[6:])], ug[:-6]))
            put('corr(occupancy, ugap lag 6q)', f, corr(occ[6:], ug[:-6]))
        if f != 'NCKR':
            continue
        for y0 in (0, 9, 19, 29):
            rr = q[4 * y0:4 * y0 + 4]
            tot = sum(r['streams']['cash_services'] + r['streams']['vaults'] for r in rr)
            put(f'cash share y{y0 + 1}', f, sum(r['streams']['cash_services'] for r in rr) / tot, True)
        cv = [r['kpi']['cash_volume_index'] for r in q]
        put('corr(cash_vol_idx, ib spread-trend)', f, corr(cv, [r['ib'] for r in q]))
        put('corr(cash_vol_idx, dom demand gap)', f, corr(cv, [r['ddg'] for r in q]))
        put('cash_vol_idx p5..p95', f, f"{sorted(cv)[len(cv) // 20]:.3f}..{sorted(cv)[-len(cv) // 20 - 1]:.3f}")
        g = [r['gold'] for r in q]
        put('gold idx min..max', f, f"{min(g):.0f}..{max(g):.0f}")
        vt = [r['streams']['vaults'] for r in q]
        put('corr(vault_value_idx, gold)', f, corr([r['kpi']['vault_value_index'] for r in q], g))
        # Elasticity of vault revenue to gold over the quarter: log vault revenue on log gold, with a time trend
        # removed from both (the plan grows).
        ts = list(range(len(q)))
        ly, lg = [math.log(v) for v in vt], [math.log(x) for x in g]
        ry = [y - slope(ts, ly) * t for y, t in zip(ly, ts)]; rg = [y - slope(ts, lg) * t for y, t in zip(lg, ts)]
        put('elast(vault rev, gold) detrended', f, slope(rg, ry))
        put('vault rev yoy SD', f, st.stdev([b / a - 1 for a, b in zip(vt, vt[4:])]), True)


def fmt(m, v):
    return v if isinstance(v, str) else (f'{v * 100:6.1f}%' if m in PCT else f'{v:7.2f}')


print(f'arm {arm}, seeds {[d["seed"] for d in D]}, years {len(D[0]["q"]["NCKR"]) / 4:.0f}, deaths {[d["dead"] for d in D]}')
print(f'board median realised/configured vol per seed (after y1, split-free): ' + ' '.join(f'{x:.2f}' for x in board_ratio))
print('board deaths/defaults/reorgs/strict distress lines per seed: ' + ' '.join(f"{len(d['dead'])}/{sum((d.get('defaults') or {}).values()) if isinstance(d.get('defaults'), dict) else len(d.get('defaults') or [])}/{len(d.get('reorgs') or [])}/{sum((d.get('distress_all') or {}).values())}" for d in D))
print('NJAR geopolitical_conflict per seed: ' + ' '.join(str(d['shocks'].get('NJAR', {}).get('geopolitical_conflict', 0) if isinstance(d['shocks'], dict) else 0) for d in D))
for f in FIRMS:
    print(f'\n== {f} ({D[0]["board"][f]["industry"]}) ==   per seed ... | mean')
    for m, byf in rows.items():
        if f in byf:
            vs = byf[f]
            mean = '' if isinstance(vs[0], str) else fmt(m, st.fmean(x for x in vs if x == x))
            print(f'{m:36s} ' + ' '.join(f'{fmt(m, v):>10s}' for v in vs) + f'  | {mean}')

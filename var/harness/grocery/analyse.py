#!/usr/bin/env python3
"""Behaviour table for SPRW (grocer), SGRB and LARK from runs/<arm>-s<seed>.json. Usage: analyse.py [arm]."""
import glob, json, math, statistics as st, sys
from pathlib import Path

arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
runs = sorted(glob.glob(str(Path(__file__).parent / 'runs' / f'{arm}-s[0-9]*.json')), key=lambda p: int(p.split('-s')[-1][:-5]))
D = [json.load(open(p)) for p in runs]
FIRMS = ['SPRW', 'SGRB', 'LARK']
ORDER = [a + b for a in ('AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C') for b in ('+', '', '-')] + ['D']
rows, pooled = {}, {}
nan_hits = 0


def put(m, f, v):
    rows.setdefault(m, {}).setdefault(f, []).append(v)


def corr(a, b):
    return st.correlation(a, b) if len(a) > 2 and st.pstdev(a) > 0 and st.pstdev(b) > 0 else float('nan')


def pct(xs, p):
    s = sorted(xs); k = (len(s) - 1) * p / 100; lo = int(k)
    return s[lo] + (s[min(lo + 1, len(s) - 1)] - s[lo]) * (k - lo)


for d in D:
    daily = d['daily']; idx = [r['i'] for r in daily]
    for f in FIRMS:
        o, q = d['opening'][f], d['q'][f]
        put('open rev bn', f, o['rev'] / 1e9); put('open om', f, o['om'])
        put('open P/E', f, o['px'] / o['eps'] if o['eps'] else float('nan')); put('open P/B', f, o['px'] / o['bvps'])
        put('open cap bn', f, d['cap1'][f] / 1e9)
        rev = [r['rev'] for r in q]; om = [r['om'] for r in q]; ni = [r['ni'] for r in q]; sh = [r['sh'] for r in q]
        eb = [r['ebit'] for r in q]
        nan_hits += sum(not math.isfinite(x) for x in rev + om + ni)
        ny = len(q) // 4
        ann = [sum(rev[4 * y:4 * y + 4]) for y in range(ny)]
        put('rev CAGR', f, (ann[-1] / ann[0]) ** (1 / (ny - 1)) - 1)
        put('om mean', f, st.fmean(om)); put('om min', f, min(om)); put('om max', f, max(om))
        put('neg EBIT q share', f, sum(1 for x in eb if x < 0) / len(eb))
        eps_y = [sum(ni[4 * y:4 * y + 4]) / sh[4 * y + 3] for y in range(ny)]
        put('EPS g SD', f, st.stdev([(b - a) / abs(a) for a, b in zip(eps_y, eps_y[1:]) if a != 0]))
        put('neg annual EPS years', f, sum(1 for e in eps_y if e <= 0))
        px, adj = [], 1.0
        for i, r in enumerate(daily):
            if i and daily[i - 1][f + '_sh'] > 0:
                ratio = r[f + '_sh'] / daily[i - 1][f + '_sh']
                if ratio > 1.2 or ratio < 0.8:
                    adj *= ratio
            px.append(r[f] * adj)
        lr = [math.log(b / a) for a, b in zip(px, px[1:]) if a > 0 and b > 0]
        put('vol', f, st.stdev(lr) * math.sqrt(len(daily) / ny))
        rw = [math.log(px[i + 5] / px[i]) for i in range(0, len(px) - 5, 5)]
        mw = [math.log(idx[i + 5] / idx[i]) for i in range(0, len(idx) - 5, 5)]
        ma, mb = st.fmean(rw), st.fmean(mw)
        put('beta wk', f, sum((x - ma) * (y - mb) for x, y in zip(rw, mw)) / (len(rw) - 1) / st.variance(mw))
        peak, dd = px[0], 0.0
        for p in px:
            peak = max(peak, p); dd = min(dd, p / peak - 1)
        put('maxDD', f, dd)
        dps = [r['div'] / r['sh'] for r in q]
        put('div cuts', f, sum(1 for a, b in zip(dps, dps[1:]) if a > 0 and b < 0.9 * a))
        put('distress q', f, sum(1 for r in q if r['qid'] or r['pd']))
        put('worst rating', f, ORDER[max(ORDER.index(r['rating']) if r['rating'] in ORDER else len(ORDER) - 1 for r in q)])
        put('dead/default/reorg', f, f"{int(f in d['dead'])}/{(d.get('defaults') or {}).get(f, 0)}/{sum(1 for r in d.get('reorgs', []) if r['t'] == f)}")
        lines = (d.get('distress_lines') or {}).get(f, [])
        put('rescue/distress lines', f, sum(1 for l in lines if 'rescue' in l['d'].lower()) if lines else 0)
        put('treasury distress lines', f, len(lines))
        pe8 = [r['px'] / r['eps'] for r in q[8:] if r['eps'] > 0]
        put('P/E settle (y3-20 med)', f, st.median(pe8))
        put('P/E open - settle', f, o['px'] / o['eps'] - st.median(pe8))
        bt = [r for r in q if r['ceps'] is not None and r['reps'] is not None]
        put('EPS beat rate', f, st.fmean(1.0 if r['reps'] > r['ceps'] else 0.0 for r in bt))
        yoy = [b / a - 1 for a, b in zip(rev, rev[4:])]
        eby = [(b - a) / abs(a) for a, b in zip(eb, eb[4:]) if True]
        g = [r['gap'] for r in q]; g4 = g[4:]
        P = pooled.setdefault(f, {'sr': [], 'se': [], 'ar': [], 'ae': [], 'n': 0})
        P['ar'] += yoy; P['ae'] += eby
        for i, v in enumerate(g4):
            if v < -0.015:
                P['sr'].append(yoy[i]); P['se'].append(eby[i]); P['n'] += 1
        # Slump margin change (robust to a near-zero EBIT base) and the fuel pass-through.
        for i, v in enumerate(g4):
            P.setdefault('dom_a', []).append(om[i + 4] - om[i])
            if v < -0.015:
                P.setdefault('dom_s', []).append(om[i + 4] - om[i])
        if f != 'SPRW':
            continue
        S2 = ('groceries', 'fuel_forecourts')
        t0 = sum(q[0]['streams'][s] for s in S2); tE = sum(r['streams'][s] for r in q[-4:] for s in S2)
        put('share open g/f', f, '/'.join(f"{q[0]['streams'][s] / t0:.3f}" for s in S2))
        put('share end4q g/f', f, '/'.join(f"{sum(r['streams'][s] for r in q[-4:]) / tE:.3f}" for s in S2))
        put('zero stream quarters', f, sum(1 for r in q for s in S2 if r['streams'][s] <= 0))
        for k in ('basket_volume_index', 'shelf_price_index'):
            xs = [r['kpi'][k] for r in q]
            put(f'{k} p5/p95', f, f'{pct(xs, 5):.3f}/{pct(xs, 95):.3f}')
        put('war onsets / 20y', f, len(d.get('wars', {}).get(f, [])))
        # War quarters: the shelf index over the farm-price shelf level (war 1 - 0.02 x 0.8, peace 1 + 0.02 x 0.2).
        wp = [r['kpi']['shelf_price_index'] / (1 + 0.15 * (r['agri'] - 100) / 100) for r in q]
        war = [x < 1.0 - 0.006 for x in wp]
        put('war quarter share', f, st.fmean(1.0 if w else 0.0 for w in war))
        wm = [om[i] for i in range(len(q)) if war[i]]; pm = [om[i] for i in range(len(q)) if not war[i]]
        put('om war - peace pp', f, (st.fmean(wm) - st.fmean(pm)) * 100 if wm else float('nan'))
        e = [r['ecp'] / 0.025 for r in q]
        de = [b - a for a, b in zip(e, e[4:])]
        put('rel energy price p5/p95', f, f'{pct(e, 5):+.3f}/{pct(e, 95):+.3f}')
        put('corr(rev yoy, d4 energy)', f, corr(yoy, de))
        put('corr(EBIT yoy, d4 energy)', f, corr([(b - a) / st.fmean(abs(x) for x in eb) for a, b in zip(eb, eb[4:])], de))
        fuel = [r['streams']['fuel_forecourts'] for r in q]
        put('corr(fuel rev yoy, d4 energy)', f, corr([b / a - 1 for a, b in zip(fuel, fuel[4:])], de))
        put('corr(basket idx, gapEma)', f, corr([r['kpi']['basket_volume_index'] for r in q], g))

PCT = {'open om', 'rev CAGR', 'om mean', 'om min', 'om max', 'neg EBIT q share', 'EPS g SD', 'vol', 'maxDD', 'EPS beat rate'}


def fmt(m, v):
    return v if isinstance(v, str) else (f'{v * 100:6.1f}%' if m in PCT else f'{v:7.2f}')


print(f'arm {arm}, seeds {[d["seed"] for d in D]}, NaN hits {nan_hits}, deaths {[d["dead"] for d in D]}')
for f in FIRMS:
    print(f'\n== {f} ({D[0]["opening"][f]["industry"]}) ==')
    for m, byf in rows.items():
        if f in byf:
            vs = byf[f]
            mean = '' if isinstance(vs[0], str) else fmt(m, st.fmean(x for x in vs if x == x))
            print(f'{m:30s} ' + ' '.join(f'{fmt(m, v):>14s}' for v in vs) + f'   | {mean}')
    P = pooled[f]
    if P['n']:
        print(f"slump quarters pooled {P['n']}: rev yoy - mean {(st.fmean(P['sr']) - st.fmean(P['ar'])) * 100:+.2f}pp, "
              f"EBIT yoy - mean {(st.fmean(P['se']) - st.fmean(P['ae'])) * 100:+.2f}pp, "
              f"yoy margin change - mean {(st.fmean(P['dom_s']) - st.fmean(P['dom_a'])) * 100:+.2f}pp")
json.dump({'rows': rows, 'pooled': {f: {'n': P['n'], 'rev': (st.fmean(P['sr']) - st.fmean(P['ar'])) * 100 if P['n'] else None,
           'ebit': (st.fmean(P['se']) - st.fmean(P['ae'])) * 100 if P['n'] else None,
           'dom': (st.fmean(P['dom_s']) - st.fmean(P['dom_a'])) * 100 if P.get('dom_s') else None} for f, P in pooled.items()}},
          open(Path(__file__).parent / 'runs' / f'{arm}-summary.json', 'w'))

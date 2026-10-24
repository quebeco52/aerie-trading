"""Behaviour table for the consulting firms from runs/<arm>-s<seed>.json. Usage: analyse.py [arm]. Plain Python."""
import glob, json, math, statistics as st, sys
from pathlib import Path

arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
runs = sorted(glob.glob(str(Path(__file__).parent / 'runs' / f'{arm}-s*.json')), key=lambda p: int(p.split('-s')[-1][:-5]))
D = [json.load(open(p)) for p in runs]
FIRMS = ['GDWT', 'TSTN', 'AVCT', 'CLAW', 'LYRE']
rows = {}


def put(metric, firm, v):
    rows.setdefault(metric, {}).setdefault(firm, []).append(v)


def corr(a, b):
    return st.correlation(a, b) if len(a) > 2 and st.pstdev(a) > 0 and st.pstdev(b) > 0 else float('nan')


def pct(xs, p):
    s = sorted(xs)
    k = (len(s) - 1) * p / 100
    lo = int(k)
    return s[lo] + (s[min(lo + 1, len(s) - 1)] - s[lo]) * (k - lo)


def cov(a, b):
    ma, mb = st.fmean(a), st.fmean(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / (len(a) - 1)


ORDER = [a + b for a in ('AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C') for b in ('+', '', '-')] + ['D']
nan_hits = 0
for d in D:
    daily = d['daily']
    idx = [r['i'] for r in daily]
    for f in FIRMS:
        o = d['opening'][f]
        q = d['q'][f]
        put('open rev bn', f, o['rev'] / 1e9)
        put('open om', f, o['om'])
        put('open P/E', f, o['px'] / o['eps'] if o['eps'] else float('nan'))
        put('open P/B', f, o['px'] / o['bvps'])
        if 'cap1' in d:
            caps = sorted(d['cap1'].values(), reverse=True)
            put('open mkt value bn', f, d['cap1'][f] / 1e9)
            put('open mkt value rank', f, f"{caps.index(d['cap1'][f]) + 1}/{len(caps)}")
        rev = [r['rev'] for r in q]; om = [r['om'] for r in q]; ni = [r['ni'] for r in q]; sh = [r['sh'] for r in q]
        nan_hits += sum(not math.isfinite(x) for x in rev + om + ni)
        ny = len(q) // 4
        ann = [sum(rev[4 * y:4 * y + 4]) for y in range(ny)]
        put('rev CAGR', f, (ann[-1] / ann[0]) ** (1 / (ny - 1)) - 1)
        put('om mean', f, st.fmean(om)); put('om min', f, min(om)); put('om max', f, max(om))
        eps_y = [sum(ni[4 * y:4 * y + 4]) / sh[4 * y + 3] for y in range(ny)]
        put('EPS g SD', f, st.stdev([(b - a) / abs(a) for a, b in zip(eps_y, eps_y[1:])]))
        # Split-adjusted price: a share-count jump over 20% in a day rescales the earlier prices.
        px, adj = [], 1.0
        for i, r in enumerate(daily):
            if i and f + '_sh' in r and daily[i - 1][f + '_sh'] > 0:
                ratio = r[f + '_sh'] / daily[i - 1][f + '_sh']
                if ratio > 1.2 or ratio < 0.8:
                    adj *= ratio
            px.append(r[f] * adj)
        lr = [math.log(b / a) for a, b in zip(px, px[1:])]
        put('vol', f, st.stdev(lr) * math.sqrt(len(daily) / ny))
        rw = [math.log(px[i + 5] / px[i]) for i in range(0, len(px) - 5, 5)]
        mw = [math.log(idx[i + 5] / idx[i]) for i in range(0, len(idx) - 5, 5)]
        put('beta wk', f, cov(rw, mw) / st.variance(mw))
        peak, dd = px[0], 0.0
        for p in px:
            peak = max(peak, p); dd = min(dd, p / peak - 1)
        put('maxDD', f, dd)
        dps = [r['div'] / r['sh'] for r in q]
        put('div cuts', f, sum(1 for a, b in zip(dps, dps[1:]) if a > 0 and b < 0.9 * a))
        put('distress q', f, sum(1 for r in q if r['qid'] or r['pd']))
        put('worst rating', f, max((r['rating'] or 'D' for r in q), key=lambda s: ORDER.index(s) if s in ORDER else 99))
        put('dead', f, 1 if f in d['dead'] else 0)
        sec = o['sector']
        s0 = q[0]['rev'] / d['agg'][str(round(q[0]['t'], 2))]['sec'][sec]
        s1 = q[-1]['rev'] / d['agg'][str(round(q[-1]['t'], 2))]['sec'][sec]
        put('sector rev share 0->end', f, f'{s0:.3f}->{s1:.3f}')
        if f not in ('GDWT', 'TSTN', 'AVCT'):
            continue
        b2b = [r['kpi']['book_to_bill'] for r in q]
        put('b2b p5', f, pct(b2b, 5)); put('b2b p95', f, pct(b2b, 95))
        bq = [r['kpi']['backlog_quarters'] for r in q]
        put('backlog q min', f, min(bq)); put('backlog q max', f, max(bq))
        for s in ('strategy_advisory', 'technology_programmes', 'risk_and_restructuring', 'audit_and_tax'):
            if s + '_backlog_quarters' not in q[0]['kpi']:
                continue
            sbq = [r['kpi'][s + '_backlog_quarters'] for r in q]
            put(f'{s[:4]} backlog q min/max', f, f'{min(sbq):.2f}/{max(sbq):.2f}')
        gap = [r['gap'] for r in q]; spr = [r['spread'] for r in q]
        sb = [r['kpi']['strategy_advisory_book_to_bill'] for r in q]
        rb = [r['kpi']['risk_and_restructuring_book_to_bill'] for r in q]
        put('corr(strat b2b, gap)', f, corr(sb, gap))
        sbook = [r['kpi']['strategy_advisory_book_to_bill'] * r['streams'].get('strategy_advisory', 0) for r in q]
        put('corr(strat bookings yoy, gap)', f, corr([math.log(b / a) for a, b in zip(sbook, sbook[4:])], gap[4:]))
        put('corr(risk b2b, spread)', f, corr(rb, spr))
        put('corr(firm b2b, gap)', f, corr(b2b, gap))
        put('corr(firm b2b, spread)', f, corr(b2b, spr))
        yoy = [b / a - 1 for a, b in zip(rev, rev[4:])]
        niy = [(b - a) / abs(a) for a, b in zip(ni, ni[4:])]
        s4 = spr[4:]; cut = pct(s4, 90)
        top = [i for i, v in enumerate(s4) if v >= cut]
        put('stress spread pp', f, st.fmean(s4[i] for i in top) * 100)
        put('stress rev yoy - mean', f, st.fmean(yoy[i] for i in top) - st.fmean(yoy))
        put('stress NI yoy - mean', f, st.fmean(niy[i] for i in top) - st.fmean(niy))
        g4 = gap[4:]; gcut = pct(g4, 10)
        low = [i for i, v in enumerate(g4) if v <= gcut]
        put('slump gap', f, st.fmean(g4[i] for i in low))
        put('slump rev yoy - mean', f, st.fmean(yoy[i] for i in low) - st.fmean(yoy))
        k = 4 + min(range(len(b2b) - 8), key=lambda i: b2b[4 + i])
        put('worst b2b', f, b2b[k])
        for h in (2, 4):
            put(f'd om pp +{h}q', f, (om[k + h] - om[k]) * 100)
            put(f'd rev % +{h}q', f, (rev[k + h] / rev[k] - 1) * 100)

PCT = {'open om', 'rev CAGR', 'om mean', 'om min', 'om max', 'EPS g SD', 'vol', 'maxDD',
       'stress rev yoy - mean', 'stress NI yoy - mean', 'open om', 'slump rev yoy - mean', 'slump gap'}


def fmt(m, v):
    if isinstance(v, str):
        return v
    return f'{v * 100:6.1f}%' if m in PCT else f'{v:7.2f}'


print(f'arm {arm}, seeds {[d["seed"] for d in D]}, NaN hits {nan_hits}, deaths {[d["dead"] for d in D]}')
for f in FIRMS:
    print(f'\n== {f} ({D[0]["opening"][f]["industry"]}) ==')
    for m, byf in rows.items():
        if f in byf:
            vs = byf[f]
            mean = '' if isinstance(vs[0], str) else fmt(m, st.fmean(x for x in vs if x == x))
            print(f'{m:30s} ' + ' '.join(f'{fmt(m, v):>12s}' for v in vs) + f'   | {mean}')

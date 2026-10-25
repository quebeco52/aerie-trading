"""Behaviour table for the broadcaster BELL and LYRE from runs/<arm>-s<seed>.json. Usage: analyse.py [arm]. Plain Python."""
import glob, json, math, statistics as st, sys
from pathlib import Path

arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
runs = sorted(glob.glob(str(Path(__file__).parent / 'runs' / f'{arm}-s*.json')), key=lambda p: int(p.split('-s')[-1][:-5]))
D = [json.load(open(p)) for p in runs]
FIRMS = ['BELL', 'LYRE']
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
POL = {}
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
        pe = [r['px'] / r['eps'] for r in q if r['eps'] > 0]
        put('P/E settle (y3-20 med)', f, st.median([r['px'] / r['eps'] for r in q[8:] if r['eps'] > 0]))
        put('P/E open - settle', f, o['px'] / o['eps'] - rows['P/E settle (y3-20 med)'][f][-1])
        put('neg EPS quarters', f, sum(1 for r in q if r['eps'] <= 0))
        bt = [r for r in q if r['ceps'] is not None and r['reps'] is not None]
        put('EPS beat rate', f, st.fmean(1.0 if r['reps'] > r['ceps'] else 0.0 for r in bt))
        put('median rev surprise', f, st.median(r['rev'] / r['crev'] - 1 for r in q if r['crev']))
        yoy = [b / a - 1 for a, b in zip(rev, rev[4:])]
        eb = [r['ebit'] for r in q]
        eby = [(b - a) / abs(a) for a, b in zip(eb, eb[4:])]
        g4 = [r['gap'] for r in q][4:]
        low = [i for i, v in enumerate(g4) if v < -0.015]
        put('slump quarters (gap<-1.5%)', f, len(low))
        if low:
            put('slump rev yoy - mean', f, st.fmean(yoy[i] for i in low) - st.fmean(yoy))
            put('slump EBIT yoy - mean', f, st.fmean(eby[i] for i in low) - st.fmean(eby))
        if f != 'BELL':
            continue
        S3 = ('carriage_fees', 'airtime_advertising', 'political_advertising')
        tot0 = sum(q[0]['streams'][s] for s in S3)
        put('share open c/a/p', f, '/'.join(f"{q[0]['streams'][s] / tot0:.3f}" for s in S3))
        totE = sum(r['streams'][s] for r in q[-16:] for s in S3)
        put('share last term c/a/p', f, '/'.join(f"{sum(r['streams'][s] for r in q[-16:]) / totE:.3f}" for s in S3))
        neg = sum(1 for r in q for s in S3 if r['streams'][s] < 0)
        zero_ca = sum(1 for r in q for s in S3[:2] if r['streams'][s] <= 0)
        put('neg/zero c+a stream q', f, f'{neg}/{zero_ca}')
        for k in ('audience_index', 'carriage_fee_index', 'upfront_price_index'):
            xs = [r['kpi'][k] for r in q]
            put(f'{k[:-6]} p5/p95', f, f'{pct(xs, 5):.3f}/{pct(xs, 95):.3f}')
        bo = d.get('blackouts', {}).get(f, [])
        put('blackouts / 20y', f, len(bo))
        # Position of the covered quarter in the four-year term, 0..15 (15 = the last quarter before the vote).
        for r in q:
            pos = int(math.floor(round(r['tt'] * 4, 6)) - 1) % 16
            tot = sum(r['streams'][s] for s in S3)
            POL.setdefault(pos, []).append(r['streams']['political_advertising'] / tot)
        air = [r['streams']['airtime_advertising'] for r in q]
        put('corr(airtime yoy, gapEma)', f, corr([math.log(b / a) for a, b in zip(air, air[4:])], g4))
        put('corr(audience, log EPU)', f, corr([r['kpi']['audience_index'] for r in q], [math.log(r['epu'] / 100) for r in q]))
        put('EPU ema p5/p95', f, f"{pct([r['epu'] for r in q], 5):.0f}/{pct([r['epu'] for r in q], 95):.0f}")

PCT = {'open om', 'rev CAGR', 'om mean', 'om min', 'om max', 'EPS g SD', 'vol', 'maxDD', 'slump rev yoy - mean',
       'slump EBIT yoy - mean', 'EPS beat rate', 'median rev surprise'}


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
print('\nBELL political share of revenue by quarter of the term (0 = first after the vote, 15 = last before it):')
print(' '.join(f'{p:>2d}:{st.fmean(POL[p]) * 100:5.2f}%' for p in sorted(POL)))
print(f'max share in quarters 0-11: {max(max(POL[p]) for p in POL if p < 12) * 100:.3f}%')

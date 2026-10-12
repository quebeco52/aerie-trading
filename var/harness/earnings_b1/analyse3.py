#!/usr/bin/env python3
"""Batch-3 table: new arm vs base, paired on seeds, plus vs a reference arm. Usage: ARMS=ref,base,new analyse3.py [seeds...]"""
import json, math, os, sys, statistics as st
H = os.environ.get('H') or os.path.dirname(os.path.abspath(__file__))
SEC = json.load(open(H + '/sectors.json'))
NAN = float('nan')


def load(arm, s):
    return json.loads(open(H + '/runs/' + arm + '-' + str(s) + '.jsonl').readline())


def ols(xs, ys):
    mx, my = st.mean(xs), st.mean(ys)
    return sum((a - mx) * (b - my) for a, b in zip(xs, ys)) / sum((a - mx) ** 2 for a in xs)


def ols2(x1, x2, y):
    """Coefficients on x1 and x2 in y = a + b1 x1 + b2 x2 (demeaned normal equations)."""
    m1, m2, my = st.mean(x1), st.mean(x2), st.mean(y)
    a = [u - m1 for u in x1]; b = [u - m2 for u in x2]; c = [u - my for u in y]
    s11 = sum(u * u for u in a); s22 = sum(u * u for u in b); s12 = sum(u * v for u, v in zip(a, b))
    s1y = sum(u * v for u, v in zip(a, c)); s2y = sum(u * v for u, v in zip(b, c))
    det = s11 * s22 - s12 * s12
    return (s22 * s1y - s12 * s2y) / det, (s11 * s2y - s12 * s1y) / det


def rv(nf, lo, hi):
    return sum(x['rev'] for q in nf.values() for x in q if lo < x['t'] <= hi)


def ng(nf, lo, hi):
    return st.mean([x['ngdp'] for q in nf.values() for x in q if lo < x['t'] <= hi])


def metrics(r):
    f = r['final']
    fy = sum(len(v['q']) for v in f.values()) / 4.0
    m = dict()
    nf = dict((t, v['q']) for t, v in f.items() if not SEC.get(t, dict()).get('fin', False))
    has = any('defl' in x for q in nf.values() for x in q)
    byq = dict()
    defl = dict()
    ngq = dict()
    for t, q in nf.items():
        for x in q:
            k = int(round(x['t'] * 4))
            byq.setdefault(k, dict())[t] = x['rev']
            if has:
                defl.setdefault(k, []).append(x['defl'])
                ngq.setdefault(k, []).append(x['ngdp'])
    if has:
        xs, ys, zs = [], [], []
        for k in sorted(byq):
            if k - 8 in byq:
                a, b = byq[k - 8], byq[k]
                common = [t for t in a if t in b and a[t] > 0 and b[t] > 0 and abs(math.log(b[t] / a[t])) < math.log(2)]
                if len(common) > 10:
                    ys.append(math.log(sum(b[t] for t in common) / sum(a[t] for t in common)))
                    xs.append(math.log(st.mean(defl[k]) / st.mean(defl[k - 8])))
                    zs.append(math.log(st.mean(ngq[k]) / st.mean(ngq[k - 8])) - xs[-1])
        m['T1 rev pass-through slope'] = ols(xs, ys) if len(xs) > 3 else NAN
        m['T1 slope | real GDP control'] = ols2(xs, zs, ys)[0]
        m['T1 real-GDP coef'] = ols2(xs, zs, ys)[1]
        m['T1 mean 2y dlog deflator'] = st.mean(xs)
        m['T1 mean 2y dlog revenue'] = st.mean(ys)
        m['T2 rev/nGDP yr15 over yr1'] = (rv(nf, 14, 15) / ng(nf, 14, 15)) / (rv(nf, 0, 1) / ng(nf, 0, 1))
    else:
        for k in ('T1 rev pass-through slope', 'T1 mean 2y dlog deflator', 'T1 mean 2y dlog revenue', 'T2 rev/nGDP yr15 over yr1'):
            m[k] = NAN
    opens = sum(1 for v in f.values() for i, x in enumerate(v['q']) if x['qid'] and (i == 0 or not v['q'][i - 1]['qid']))
    m['T3 Ch7+Ch11 /100fy'] = 100 * (len(r['dead']) + len(r['reorgs'])) / fy
    m['T3 default openings /100fy'] = 100 * opens / fy
    if has:
        for lo, hi, lab in ((0, 3, '1-3'), (10, 15, '11-15')):
            ct = sum(x['ctax'] for q in nf.values() for x in q if lo < x['t'] <= hi)
            bt = sum(x['tax'] for q in nf.values() for x in q if lo < x['t'] <= hi)
            m['T4 cash/book tax yrs ' + lab] = ct / bt if bt else NAN
        loss = [x['ni'] / x['pti'] for q in nf.values() for x in q if x['pti'] < 0]
        m['T4 loss qtrs /100fy'] = 100 * len(loss) / fy
        m['T4 loss share NI/PTI in [0.6,0.95)'] = sum(0.6 <= a < 0.95 for a in loss) / len(loss) if loss else NAN
        m['T4 loss share NI/PTI in [0.95,1.05]'] = sum(0.95 <= a <= 1.05 for a in loss) / len(loss) if loss else NAN
        gw = [(x['gwi'], x['gap']) for v in f.values() for x in v['q'] if x['gwi'] > 0]
        m['T5 GW impairments count'] = len(gw)
        m['T5 GW count gap below -1pc'] = sum(g < -0.01 for _, g in gw)
        m['T5 GW count gap above +1pc'] = sum(g > 0.01 for _, g in gw)
        m['T5 GW bn total'] = sum(a for a, _ in gw) / 1e9
        m['T5 GW bn gap below -1pc'] = sum(a for a, g in gw if g < -0.01) / 1e9
        for yr in (5, 15):
            rows = [x for q in nf.values() for x in q if yr - 1 < x['t'] <= yr and x['rev'] > 0]
            m['T6 median EBIT margin yr' + str(yr)] = st.median(x['ebit'] / x['rev'] for x in rows)
            m['T6 median ROIC ttm yr' + str(yr)] = st.median(x['roic'] for x in rows)
    ep = [(x['reps'], x['ceps']) for v in f.values() for x in v['q'] if abs(x['ceps']) > 1e-9]
    m['T6 EPS beat rate'] = sum(a > c for a, c in ep) / len(ep)
    return m


def row(k, X, Y, S):
    d = [Y[s][k] - X[s][k] for s in S if k in X[s] and not (math.isnan(Y[s][k]) or math.isnan(X[s][k]))]
    if len(d) < 2:
        return NAN, NAN, NAN, len(d)
    md = st.mean(d)
    se = st.stdev(d) / math.sqrt(len(d))
    return md, se, (md / se if se > 0 else NAN), len(d)


def avg(M, k, S):
    v = [M[s][k] for s in S if k in M[s] and not math.isnan(M[s][k])]
    return st.mean(v) if v else NAN


seeds = [int(a) for a in sys.argv[1:]] or list(range(1, 17))
# ARMS=<reference>,<base>,<new>: the new arm is paired against the base (main t) and the reference (last column).
REF, BASE, NEW = (os.environ.get('ARMS') or 'before,b2,b3').split(',')
M = dict(((REF, dict()), (BASE, dict()), (NEW, dict())))
for s in seeds:
    try:
        got = dict((a, metrics(load(a, s))) for a in M)
    except (FileNotFoundError, json.JSONDecodeError):
        continue
    for a in M:
        M[a][s] = got[a]
S = sorted(M[NEW])
print('seeds', S)
FMT_H = '%-36s %8s %8s %8s | %8s %7s %6s %3s | %8s %6s'
FMT_R = '%-36s %8.4f %8.4f %8.4f | %8.4f %7.4f %6.1f %3d | %8.4f %6.1f'
print(FMT_H % ('quantity', REF, BASE, NEW, NEW + '-' + BASE, 'SE', 't', 'n', NEW + '-' + REF[:3], 't'))
for k in M[NEW][S[0]]:
    d1, se1, t1, n = row(k, M[BASE], M[NEW], S)
    d0, se0, t0, _ = row(k, M[REF], M[NEW], S)
    print(FMT_R % (k, avg(M[REF], k, S), avg(M[BASE], k, S), avg(M[NEW], k, S), d1, se1, t1, n, d0, t0))

#!/usr/bin/env python3
"""analyse_val.py [seeds] [A] [B] [tag]: catch-up valuation check, B - A paired on seeds (default head, val).

Realised vol = sqrt(mean squared tick log return x tpy) per live name over the run (idle.json `rv`), against its
configured (seed) vol. P/E = price / eps over firm-quarters with eps > 0, t >= 10. Catch-up = idle.json column 9
(Valuation::capacityCatchUpGrowth on the filed gap, at the FVX cost of equity). FV change = log change of x_fv between a
firm's consecutive reports; revision = change in the industry's belief m + v/2 over the same quarters (volatile
industries = drift 0.09 in src/Data/Company/IndustryOutlooks.php).
"""
import json, math, os, re, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
P = os.path.abspath(os.path.join(H, '..', '..', '..'))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4,5,6,7,8').split(',')]
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('head', 'val')
tag = sys.argv[4] if len(sys.argv) > 4 else '-oc'
BAD = {'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D', 'SD'}
table = {m.group(1): float(m.group(2)) for m in re.finditer(r"'([^']+)' => \['drift_vol' => ([0-9.]+)",
                                                            open(f'{P}/src/Data/Company/IndustryOutlooks.php').read())}
VOLATILE = {i for i, d in table.items() if d >= 0.09}


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, max(0, int(math.ceil(p * len(xs))) - 1))]


def corr(xs, ys):
    mx, my = st.fmean(xs), st.fmean(ys)
    sxy = sum((x - mx) * (y - my) for x, y in zip(xs, ys))
    return sxy / math.sqrt(sum((x - mx) ** 2 for x in xs) * sum((y - my) ** 2 for y in ys))


def run(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}{tag}.jsonl').readline())
    k = json.load(open(f'{H}/runs/{arm}-{s}{tag}.idle.json'))
    oq = json.load(open(f'{H}/runs/{arm}-{s}{tag}.outlook.json'))['quarters']
    tpy = k['tpy']
    rv, over = [], 0
    for tk, (ss, n, cfg, _) in k['rv'].items():
        if n < tpy or r['final'].get(tk, {}).get('dead'):
            continue
        v = math.sqrt(ss / n * tpy)
        rv.append(v)
        over += v > 1.1 * cfg
    allq = [x for f in r['final'].values() for x in f['q']]
    pe = [x['price'] / x['eps'] for x in allq if x['t'] >= 10 and x['eps'] > 0]
    out = {
        'median realised vol': st.median(rv),
        'p90 realised vol': pct(rv, 0.9),
        'share vol > 1.1 x configured': over / len(rv),
        'board cap y20 ($tn)': sum(f['q'][-1]['price'] * f['q'][-1]['shares'] for f in r['final'].values() if f['q'] and not f['dead']) / 1e12,
        'median P/E y10-20': st.median(pe),
        'defaults+deaths': len(r['defaults']) + len(r['dead']),
        'share B or worse': sum(1 for x in allq if x['rating'] in BAD) / len(allq),
    }
    belief = {}
    for _, q, rows in oq:
        for ind, rec in rows.items():
            belief[(ind, q)] = rec[1] + rec[2] / 2.0
    qs = sorted({q for _, q, _ in oq})

    def b_at(ind, t):
        q = max([x for x in qs if x <= int(t * 4 + 1e-9)], default=None)
        return belief.get((ind, q))
    rows = sorted(k['rows'], key=lambda x: (x[1], x[0]))
    catch, fv_dx, rev = [], [], []
    for a, b in zip(rows, rows[1:]):
        if len(b) > 8 and b[8] is not None and b[0] >= 10:
            catch.append(b[8])
        if a[1] != b[1] or b[2] not in VOLATILE or len(b) < 10 or not a[9] or not b[9] or a[9] <= 0 or b[9] <= 0:
            continue
        ba, bb = b_at(b[2], a[0]), b_at(b[2], b[0])
        if ba is None or bb is None:
            continue
        fv_dx.append(math.log(b[9] / a[9]))
        rev.append(bb - ba)
    return out, catch, fv_dx, rev


ra = {s: run(A, s) for s in seeds}
rb = {s: run(B, s) for s in seeds}
print(f'quantity | {A} | {B} | {B}-{A} (se) t n')
for key in ra[seeds[0]][0]:
    a = [ra[s][0][key] for s in seeds]
    b = [rb[s][0][key] for s in seeds]
    print(f'{key} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')

for arm, rr in ((A, ra), (B, rb)):
    c = [x for s in seeds for x in rr[s][1]]
    if c:
        per = [st.pstdev(rr[s][1]) for s in seeds if len(rr[s][1]) > 1]
        print(f'\n{arm}: catch-up growth y10-20, firm-quarters {len(c)}: mean {st.fmean(c):+.4f}, cross-sectional sd '
              f'{st.pstdev(c):.4f} (per-seed sd {min(per):.4f}-{max(per):.4f}), p1 {pct(c, .01):+.4f}, p99 {pct(c, .99):+.4f}')
    fx = [x for s in seeds for x in rr[s][2]]
    rv = [x for s in seeds for x in rr[s][3]]
    if len(fx) > 2 and st.pstdev(rv) > 0:
        per = [corr(rr[s][2], rr[s][3]) for s in seeds if len(rr[s][2]) > 2 and st.pstdev(rr[s][3]) > 0]
        sdx = st.pstdev(fx)
        beta = corr(fx, rv) * sdx / st.pstdev(rv)
        print(f'{arm}: volatile industries, corr(FV log change, belief revision) pooled {corr(fx, rv):+.3f} over {len(fx)} '
              f'firm-quarters (per seed {min(per):+.3f}..{max(per):+.3f}); slope {beta:+.2f}; sd FV change {sdx:.3f}, sd revision {st.pstdev(rv):.3f}')

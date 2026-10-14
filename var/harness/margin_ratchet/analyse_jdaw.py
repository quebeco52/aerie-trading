#!/usr/bin/env python3
"""10-14 WEAV refit + JDAW: arm `jdaw` (tree) vs cached `after` (10-14 sector-drift SAM), unpaired.
TTM at year Y = the 4 quarters with t in (Y-1, Y]; GDP_usd = 12e12 x mean nominal_gdp_index of those quarters.
Usage: analyse_jdaw.py [seeds A (after)] [seeds B (jdaw)], e.g. 1-48 1-8."""
import json, math, os, sys, statistics as st
D = os.path.dirname(os.path.abspath(__file__))
GDP0 = 12.0e12  # MacroEngine::DISTRICT_GDP_USD; nominalGdpIndex starts at 1.0
def rng(s): a, b = s.split('-'); return range(int(a), int(b) + 1)
SA = rng(sys.argv[1]) if len(sys.argv) > 1 else range(1, 49)
SB = rng(sys.argv[2]) if len(sys.argv) > 2 else range(1, 9)
def ttm(q, y, k='rev'):
    w = [x for x in q if y - 1 < x['t'] <= y]
    return (sum(x[k] for x in w), st.mean(x['ngdp'] for x in w)) if len(w) == 4 else (None, None)
def row(r):
    f = r['final']; o = {}
    wq = f['WEAV']['q']
    for y in (1, 16, 30):
        rev, g = ttm(wq, y); o[f'w{y}'] = 100 * rev / (GDP0 * g) if rev else None
    cap = {t: x['px'] * x['sh'] for t, x in f.items() if not x['dead']}
    o['wcap'] = 100 * cap.get('WEAV', 0) / sum(cap.values())
    o['jcap'] = 100 * cap.get('JDAW', 0) / sum(cap.values()) if 'JDAW' in f else None
    e30, _ = ttm(wq, 30, 'ebit'); r30, _ = ttm(wq, 30)
    o['wom30'] = 100 * e30 / r30 if r30 else None
    o['fail'] = len(r['dead']) + len(r['reorgs'])
    if 'JDAW' in f:
        jq = f['JDAW']['q']
        for y in (1, 30):
            jr, _ = ttm(jq, y); wr, _ = ttm(wq, y); je, _ = ttm(jq, y, 'ebit')
            o[f'jr{y}'] = jr / wr if jr and wr else None
            o[f'jm{y}'] = 100 * je / jr if jr else None
        o['jdead'] = int('JDAW' in r['dead'] or any('JDAW' in json.dumps(x) for x in r['reorgs']))
    return o
def load(arm, seeds):
    out = []
    for s in seeds:
        p = f'{D}/runs/{arm}-{s}.jsonl'
        if os.path.exists(p): out.append(row(json.loads(open(p).readline())))
    return out
def ms(xs):
    xs = [x for x in xs if x is not None]
    if not xs: return None
    return st.mean(xs), (st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')), len(xs), min(xs), max(xs)
A, B = load('after', SA), load('jdaw', SB)
print(f'{"quantity":36s} {"after (cached)":>22s} {"jdaw (tree)":>22s} {"diff (se)":>16s} {"t":>6s}')
for k, l in (('w1', 'WEAV TTM rev / GDP y1, %'), ('w16', 'WEAV TTM rev / GDP y16, %'), ('w30', 'WEAV TTM rev / GDP y30, %'),
             ('wcap', 'WEAV share of board cap end, %'), ('wom30', 'WEAV TTM EBIT margin y30, %'),
             ('jr1', 'JDAW / WEAV TTM rev y1'), ('jr30', 'JDAW / WEAV TTM rev y30'),
             ('jm1', 'JDAW TTM EBIT margin y1, %'), ('jm30', 'JDAW TTM EBIT margin y30, %'),
             ('jcap', 'JDAW share of board cap end, %'), ('jdead', 'JDAW bankrupt/reorg (runs)'), ('fail', 'failures per run (bk+reorg)')):
    a, b = ms([o.get(k) for o in A]), ms([o.get(k) for o in B])
    fa = f'{a[0]:7.3f} ({a[1]:.3f}) n{a[2]}' if a else f'{"-":>22s}'
    fb = f'{b[0]:7.3f} ({b[1]:.3f}) n{b[2]}' if b else f'{"-":>22s}'
    d = f'{b[0]-a[0]:+8.3f} ({math.hypot(a[1], b[1]):.3f}) {(b[0]-a[0])/math.hypot(a[1], b[1]):6.2f}' if a and b else ''
    print(f'{l:36s} {fa:>22s} {fb:>22s} {d}')
    if b and k in ('w1', 'w16', 'w30', 'jr30', 'jm30'): print(f'{"":36s} {"range":>22s} {b[3]:10.3f}..{b[4]:.3f}')
print(f'jdaw seeds {list(SB)[0]}-{list(SB)[-1]} found {len(B)}; after seeds found {len(A)}')

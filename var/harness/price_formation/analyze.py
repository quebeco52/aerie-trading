#!/usr/bin/env python3
"""analyze.py <runs-dir>: paired BEFORE/AFTER table of price-formation targets (years 2..Y), plus AFTER-only 2c numbers."""
import glob, json, math, os, statistics as st, sys

d = sys.argv[1]
def load(arm):
    out = {}
    for f in glob.glob(os.path.join(d, arm + '-*.json')):
        try:
            r = json.load(open(f))
        except Exception:
            continue
        out[r['seed']] = r
    return out

def med(x): return st.median(x) if x else float('nan')

def per_seed(r):
    q = [x for x in r['q'] if x['t'] > 1.0 + 1e-9 and x['lpf'] is not None]
    lpf = [x['lpf'] for x in q]; sp = [x['y10'] - x['pol'] for x in q]
    mx, my = st.mean(sp), st.mean(lpf)
    slope = sum((a - mx) * (b - my) for a, b in zip(sp, lpf)) / sum((a - mx) ** 2 for a in sp)
    pe = st.mean([x['pe'] for x in q if x['pe'] is not None])
    y10 = st.mean([x['y10'] for x in q])
    shares, pooled, ertn, en, ann, vol = [], [], 0.0, 0, [], []
    for t, ys in r['ny'].items():
        yrs = {int(k): v for k, v in ys.items() if int(k) >= 2}
        if not yrs: continue
        sh = [v['se2'] / v['s2'] for v in yrs.values() if v['s2'] > 0]
        if sh: shares.append(st.mean(sh))
        s2 = sum(v['s2'] for v in yrs.values())
        if s2 > 0: pooled.append(sum(v['se2'] for v in yrs.values()) / s2)
        ertn += sum(v['se'] for v in yrs.values()); en += sum(v['ne'] for v in yrs.values())
        vol += [math.sqrt(v['s2']) for v in yrs.values()]
        if t not in r['dead'] and len(yrs) == int(r['years']) - 1:
            ann.append(math.exp(sum(v['s'] for v in yrs.values()) / len(yrs)) - 1.0)
    return {'a_lpf': my, 'b_slope': slope, 'c_pe': pe, 'erp': med(ann) - y10,
            'vshare': med(shares), 'vshare_pooled': med(pooled), 'earn_ret': ertn / max(1, en), 'vol': med(vol),
            'dead': len(r['dead']), 'splits': r['splits'], 'beat': r.get('beats', 0) / max(1, r.get('reports', 0))}

XA = sys.argv[2] if len(sys.argv) > 2 else 'before'
YA = sys.argv[3] if len(sys.argv) > 3 else 'after'
B, A = load(XA), load(YA)
seeds = sorted(set(A) & set(B))
pb = {s: per_seed(B[s]) for s in seeds}; pa = {s: per_seed(A[s]) for s in seeds}
n = len(seeds)
print('seeds n=%d: %s  years=%s tpy=%s' % (n, seeds, A[seeds[0]]['years'], A[seeds[0]]['tpy']))
print('%-28s %9s %9s %9s %8s %7s  range(diff)' % ('quantity', XA, YA, YA + '-' + XA, 'se', 't'))
for k, lab in [('a_lpf', '(a) med log(P/FV)'), ('b_slope', '(b) slope lpf~(y10-pol)'), ('c_pe', '(c) med trailing P/E'),
               ('erp', 'ERP: med ann TR - y10'), ('vol', 'med name-yr realized vol'), ('earn_ret', '(2) mean log ret, earn tick'), ('vshare', 'var share on earn ticks'), ('dead', 'bankruptcies/seed'), ('splits', 'splits/seed'), ('beat', 'published beat rate')]:
    b = [pb[s][k] for s in seeds]; a = [pa[s][k] for s in seeds]; dd = [x - y for x, y in zip(a, b)]
    se = st.stdev(dd) / math.sqrt(n) if n > 1 else float('nan')
    t = st.mean(dd) / se if (se == se and se > 0) else float('nan')
    print('%-28s %9.4f %9.4f %9.4f %8.4f %7.2f  [%.4f, %.4f]' % (lab, st.mean(b), st.mean(a), st.mean(dd), se, t, min(dd), max(dd)))
print(YA + ' only (mean over seeds, se across seeds, range); ' + XA + ' mean for context:')
for k, lab in [('vshare', '2c var share on earn ticks (med of name mean-annual)'), ('vshare_pooled', '2c var share, pooled yrs (med)'),
               ('earn_ret', '2c board-mean log ret, earn tick')]:
    a = [pa[s][k] for s in seeds]; b = [pb[s][k] for s in seeds]
    se = st.stdev(a) / math.sqrt(n) if n > 1 else float('nan')
    print(('  %-52s %9.5f se %.5f  [%.5f, %.5f]  ' + XA + ' %.5f') % (lab, st.mean(a), se, min(a), max(a), st.mean(b)))

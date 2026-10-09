#!/usr/bin/env python3
"""pair.py <before-glob> <after-glob>: paired table over seeds combining analyze/decompose/growth per-seed stats (needs PF_X+PF_G runs)."""
import glob, json, math, os, statistics as st, sys
H = os.path.dirname(os.path.abspath(__file__))
argv = sys.argv; sys.argv = ['x', '.']
def grab(fname, cut):
    ns = {}; s = open(os.path.join(H, fname)).read(); exec(s[:s.index(cut)], ns); return ns
A = grab('analyze.py', 'XA = sys.argv'); D = grab('decompose.py', 'runs = sorted('); G = grab('growth.py', 'runs = sorted(')
MAXG = 0.05
def stats(r):
    o = {}; o.update(A['per_seed'](r)); o.update(D['seed_stats'](r)); o.update(G['seed_stats'](r))
    q = r['q']; i0 = next(i for i, x in enumerate(q) if abs(x['t'] - 1.0) < 1e-6)
    n = m = 0
    for rows in r['x'].values():
        for k in range(i0 + 1, min(len(rows), len(q))):
            n += 1; m += 1 if rows[k]['out'] >= MAXG - 1e-12 else 0
    o['maxbind'] = m / n
    return o
def load(g):
    out = {}
    for f in glob.glob(g):
        r = json.load(open(f)); out[r['seed']] = stats(r)
    return out
B, F = load(argv[1]), load(argv[2]); seeds = sorted(set(B) & set(F)); n = len(seeds)
keys = [('Aiii', '(1) cap-wt index geo TR - y10'), ('c_pe', '(2) median trailing P/E'), ('gap_cw', '(3) gap NI growth - g, cap-wt'),
        ('Ai', 'median-name premium'), ('g_md', 'assumed g, median'), ('g_cw', 'assumed g, cap-wt'), ('bind', 'fundable cap binds'),
        ('maxbind', 'MAX_EXPECTED_GROWTH binds'), ('gap_md', 'gap NI growth - g, median'), ('d_ni_cw', 'NI growth, cap-wt'),
        ('d_shrink_md', 'share-count shrink, median'), ('d_shrink_cw', 'share-count shrink, cap-wt'), ('ix_D', 'dividend yield (log), index'),
        ('md_D', 'dividend yield (log), median name'), ('a_lpf', 'median log(P/FV)'), ('vol', 'median realized vol'), ('dead', 'bankruptcies/seed')]
print('seeds n=%d %s' % (n, seeds))
print('%-34s %9s %9s %9s %8s %7s' % ('quantity', 'BEFORE', 'AFTER', 'diff', 'se', 't'))
for k, lab in keys:
    b = [B[s][k] for s in seeds]; a = [F[s][k] for s in seeds]; dd = [x - y for x, y in zip(a, b)]
    se = st.stdev(dd) / math.sqrt(n); t = st.mean(dd) / se if se > 0 else float('nan')
    print('%-34s %9.4f %9.4f %9.4f %8.4f %7.2f' % (lab, st.mean(b), st.mean(a), st.mean(dd), se, t))

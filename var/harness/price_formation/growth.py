#!/usr/bin/env python3
"""growth.py <runs-dir> [arm]: fair value's assumed growth vs delivered growth (PF_G runs, years 2..Y)."""
import glob, json, math, os, statistics as st, sys
from collections import defaultdict

d = sys.argv[1]; arm = sys.argv[2] if len(sys.argv) > 2 else 'cg'
def med(x): return st.median(x) if x else float('nan')
def wmean(v, w): return sum(a * b for a, b in zip(v, w)) / sum(w)
def slope(ys):
    n = len(ys); xs = list(range(n)); mx = (n - 1) / 2; my = sum(ys) / n
    return sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sum((x - mx) ** 2 for x in xs)
def corr(a, b):
    ma, mb = st.mean(a), st.mean(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / math.sqrt(sum((x - ma) ** 2 for x in a) * sum((y - mb) ** 2 for y in b))
def rank(v):
    s = sorted(range(len(v)), key=lambda i: v[i]); r = [0] * len(v)
    for k, i in enumerate(s): r[i] = k
    return r

bygroup = defaultdict(list)
def seed_stats(r):
    q = r['q']; T = r['years'] - 1.0
    i0 = next(i for i, x in enumerate(q) if abs(x['t'] - 1.0) < 1e-6); i1 = len(q) - 1
    dead = dict(r['dead']) if isinstance(r['dead'], dict) else {}
    X = r['x']; surv = {t: rows for t, rows in X.items() if t not in dead and len(rows) > i1}
    o = {}
    # 1. assumed growth, per quarter cross-sections
    gm, gw, om, ow, cm, cw, bind, nq = [], [], [], [], [], [], 0, 0
    for k in range(i0 + 1, i1 + 1):
        al = [t for t, rows in X.items() if len(rows) > k]
        caps = [X[t][k]['px'] * X[t][k]['sh'] for t in al]
        g = [X[t][k]['g'] for t in al]; out = [X[t][k]['out'] for t in al]
        cap = [max(0.0, X[t][k]['er'] * (1 - max(0.0, min(1.0, X[t][k]['pay'])))) for t in al]
        gm.append(med(g)); gw.append(wmean(g, caps)); om.append(med(out)); ow.append(wmean(out, caps)); cm.append(med(cap)); cw.append(wmean(cap, caps))
        for a, b in zip(cap, out):
            nq += 1; bind += 1 if a < b else 0
    o.update(g_md=st.mean(gm), g_cw=st.mean(gw), out_md=st.mean(om), out_cw=st.mean(ow), cap_md=st.mean(cm), cap_cw=st.mean(cw), bind=bind / nq)
    secs = [st.mean(rows[k]['sec'] for k in range(i0 + 1, i1 + 1)) for rows in surv.values()]
    o['sec_default'] = sum(1 for s in secs if abs(s - 0.02) < 1e-9); o['sec_n'] = len(secs)
    o['sec_md'] = med(secs); o['sec_min'] = min(secs); o['sec_max'] = max(secs)
    # 2-3. delivered growth per firm
    F = {}
    for t, rows in surv.items():
        a, b = rows[i0], rows[i1]; W = rows[i0:i1 + 1]
        f = math.log(b['cf'] / a['cf'])
        npos = [x['ni'] for x in W if x['ni'] > 0]
        ni_g = slope([math.log(x['ni']) for x in W]) * 4 if len(npos) == len(W) else (math.log(b['ni'] / a['ni']) / T if a['ni'] > 0 and b['ni'] > 0 else None)
        rev_g = slope([math.log(x['rev']) for x in W]) * 4 if all(x['rev'] > 0 for x in W) else None
        eps_g = slope([math.log(x['eps']) + math.log(x['cf'] / a['cf']) for x in W]) * 4 if all(x['eps'] > 0 for x in W) else None
        shrink = -(math.log(b['sh'] / a['sh']) - f) / T
        g_ass = math.log(1 + st.mean(x['g'] for x in W[1:]))
        F[t] = dict(ni=ni_g, rev=rev_g, eps=eps_g, shrink=shrink, g=g_ass, cap0=a['px'] * a['sh'],
                    tr=(b['cr'] - a['cr']) / T, bm=a['bm'])
    for k in ('ni', 'rev', 'eps', 'shrink'):
        v = [(x[k], x['cap0']) for x in F.values() if x[k] is not None]
        o['d_' + k + '_md'] = med([a for a, _ in v]); o['d_' + k + '_cw'] = wmean([a for a, _ in v], [c for _, c in v]); o['d_' + k + '_n'] = len(v)
    gaps = [(x['ni'] - x['g'], x['cap0'], x['tr'], x['bm'], (x['eps'] - x['g']) if x['eps'] is not None else None) for x in F.values() if x['ni'] is not None]
    o['gap_md'] = med([a for a, *_ in gaps]); o['gap_cw'] = wmean([a for a, *_ in gaps], [c for _, c, *_ in gaps])
    ge = [(e, c) for _, c, _, _, e in gaps if e is not None]
    o['gapeps_md'] = med([e for e, _ in ge]); o['gapeps_cw'] = wmean([e for e, _ in ge], [c for _, c in ge])
    o['corr_gap_tr'] = corr([a for a, *_ in gaps], [x for _, _, x, *_ in gaps])
    o['spear_gap_tr'] = corr(rank([a for a, *_ in gaps]), rank([x for _, _, x, *_ in gaps]))
    for a, _, _, bm, _ in gaps: bygroup[bm].append(a)
    # 4. retention arithmetic, survivors, flows in the window
    names = list(surv)
    ni = dv = bb = 0.0; gw0 = gw1 = None
    for t in names:
        for fl in r['flows'].get(t, []):
            if 1.0 < fl['t'] <= r['years'] + 1e-9:
                ni += fl['ni']; dv += fl['div']; bb += fl['bb']
    eqs = [sum(X[t][k]['eq'] for t in names) for k in range(i0, i1 + 1)]
    eq_avg = st.mean(eqs); deq = eqs[-1] - eqs[0]
    o['roe'] = ni / T / eq_avg; o['payout'] = (dv + bb) / ni; o['div_po'] = dv / ni; o['bb_po'] = bb / ni
    o['retained'] = (ni - dv - bb) / T / eq_avg; o['other'] = (deq - (ni - dv - bb)) / T / eq_avg; o['deq'] = deq / T / eq_avg
    o['eq_g'] = math.log(eqs[-1] / eqs[0]) / T
    ni0 = sum(X[t][i0]['ni'] for t in names); ni1 = sum(X[t][i1]['ni'] for t in names)
    o['agg_ni_g'] = math.log(ni1 / ni0) / T
    o['ngdp_g'] = math.log(q[i1]['ngdp'] / q[i0]['ngdp']) / T
    return o

runs = sorted(glob.glob(os.path.join(d, arm + '-*.json')), key=lambda f: int(f.rsplit('-', 1)[1].split('.')[0]))
S = []
for f in runs:
    r = json.load(open(f)); o = seed_stats(r); o['seed'] = r['seed']; S.append(o)
keys = [('g_md', '1 assumed g, median name'), ('g_cw', '1 assumed g, cap-weighted'), ('out_md', '1 outlook leg, median'), ('out_cw', '1 outlook leg, cap-wt'),
        ('cap_md', '1 fundable cap ER*(1-payout), median'), ('cap_cw', '1 fundable cap, cap-wt'), ('bind', '1 share name-qtrs cap binds'),
        ('sec_md', '1 secularGrowth median'), ('sec_min', '1 secularGrowth min'), ('sec_max', '1 secularGrowth max'), ('sec_default', '1 names at 0.02 default'), ('sec_n', '1 names'),
        ('d_ni_md', '2 NI growth (log), median firm'), ('d_ni_cw', '2 NI growth, cap-wt'), ('d_ni_n', '2 firms with NI growth'),
        ('d_rev_md', '2 revenue growth, median'), ('d_rev_cw', '2 revenue growth, cap-wt'), ('d_eps_md', '2 EPS/share growth, median'), ('d_eps_cw', '2 EPS/share growth, cap-wt'),
        ('d_eps_n', '2 firms EPS>0 throughout'), ('d_shrink_md', '2 share-count shrink, median'), ('d_shrink_cw', '2 share-count shrink, cap-wt'),
        ('gap_md', '3 gap NI - log(1+g), median'), ('gap_cw', '3 gap NI, cap-wt'), ('gapeps_md', '3 gap EPS/share - g, median'), ('gapeps_cw', '3 gap EPS/share, cap-wt'),
        ('corr_gap_tr', '3 corr(gap, firm log TR) Pearson'), ('spear_gap_tr', '3 corr(gap, firm log TR) Spearman'),
        ('roe', '4 board ROE (NI/avg book)'), ('payout', '4 total payout (div+bb)/NI'), ('div_po', '4 dividend payout'), ('bb_po', '4 buyback payout'),
        ('retained', '4 ROE*(1-payout) = retained/book'), ('other', '4 other equity flows/book'), ('deq', '4 dBook/avg book'), ('eq_g', '4 book growth (log)'),
        ('agg_ni_g', '4 aggregate NI growth (log)'), ('ngdp_g', '  nominal GDP growth (log)')]
print('arm %s, seeds %s, years 2-20' % (arm, [o['seed'] for o in S]))
print('%-40s %9s %8s  %s' % ('quantity', 'mean', 'se', 'per seed'))
for k, lab in keys:
    v = [o[k] for o in S]
    se = st.stdev(v) / math.sqrt(len(v)) if len(v) > 1 else float('nan')
    print('%-40s %9.4f %8.4f  %s' % (lab, st.mean(v), se, ' '.join('%.3f' % x for x in v)))
print('gap NI - g by business model (pooled firm-seeds): mean, sd, n')
for bm, v in sorted(bygroup.items(), key=lambda kv: -st.mean(kv[1])):
    print('  %-28s %7.4f %7.4f %4d' % (bm, st.mean(v), st.stdev(v) if len(v) > 1 else 0.0, len(v)))

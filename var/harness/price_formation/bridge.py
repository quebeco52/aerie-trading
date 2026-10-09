#!/usr/bin/env python3
"""bridge.py <runs-dir> [arm]: corporate growth audit from PF_B runs: NI vs GDP, revenue split, gap by group, book-equity bridge, M&A."""
import glob, json, math, os, statistics as st, sys
from collections import defaultdict

d = sys.argv[1]; arm = sys.argv[2] if len(sys.argv) > 2 else 'cb'
def med(x): return st.median(x) if x else float('nan')
def wmean(v, w): return sum(a * b for a, b in zip(v, w)) / sum(w) if v else float('nan')
def slope(xs, ys):
    mx = st.mean(xs); my = st.mean(ys)
    return sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sum((x - mx) ** 2 for x in xs)
def trend(rows, key):
    if not all(r[key] > 0 for r in rows): return None
    return slope(list(range(len(rows))), [math.log(r[key]) for r in rows]) * 4

BM, SEC, TOP = defaultdict(list), defaultdict(list), defaultdict(list)
FLOWS = ['ni', 'div', 'bb', 'sbc', 'issue', 'exec_other', 'tr_resid', 'asl', 'imp', 'marks', 'ma_eq', 'dv_eq', 'tick_other', 'op']
def seed_stats(r):
    q = r['q']; T = r['years'] - 1.0
    i0 = next(i for i, x in enumerate(q) if abs(x['t'] - 1.0) < 1e-6); i1 = len(q) - 1
    dead = dict(r['dead']) if isinstance(r['dead'], dict) else {}
    X = r['x']; S = {t: rows for t, rows in X.items() if t not in dead and len(rows) > i1}
    B = r['bridge']; o = {}
    agg = lambda k, i: sum(rows[i][k] for rows in S.values())
    o['agg_rev_g'] = math.log(agg('rev', i1) / agg('rev', i0)) / T
    o['agg_ni_g'] = math.log(agg('ni', i1) / agg('ni', i0)) / T
    o['agg_margin_chg'] = o['agg_ni_g'] - o['agg_rev_g']
    o['ngdp_g'] = math.log(q[i1]['ngdp'] / q[i0]['ngdp']) / T
    o['rgdp_g'] = math.log((q[i1]['ngdp'] / q[i1]['defl']) / (q[i0]['ngdp'] / q[i0]['defl'])) / T
    o['defl_g'] = math.log(q[i1]['defl'] / q[i0]['defl']) / T
    for k in ('ic', 'ppe', 'ta'):
        o['agg_%s_g' % k] = math.log(agg(k, i1) / agg(k, i0)) / T
    o['agg_turn_chg'] = o['agg_rev_g'] - o['agg_ic_g']
    # per firm
    F = {}
    for t, rows in S.items():
        W = rows[i0:i1 + 1]
        f = dict(rev=trend(W, 'rev'), ni=trend(W, 'ni'), ic=trend(W, 'ic'), ppe=trend(W, 'ppe'), ta=trend(W, 'ta'), w=W[0]['rev'], bm=W[0]['bm'], sec=W[0]['sector'])
        ipl = [x for x in r['flows'].get(t + '#ipl', []) if 1.0 < x['t'] <= r['years'] + 1e-9 and x['ipl'] > 0]
        f['price'] = slope([x['t'] for x in ipl], [math.log(x['ipl']) for x in ipl]) if len(ipl) >= 8 else None
        F[t] = f
        if f['rev'] is not None:
            BM[f['bm']].append(f['rev'] - o['ngdp_g']); SEC[f['sec']].append(f['rev'] - o['ngdp_g'])
    def both(name, fn):
        v = [(fn(f), f['w']) for f in F.values() if fn(f) is not None]
        o[name + '_md'] = med([a for a, _ in v]); o[name + '_rw'] = wmean([a for a, _ in v], [w for _, w in v]); o[name + '_n'] = len(v)
    both('rev_g', lambda f: f['rev']); both('ni_g', lambda f: f['ni'])
    both('margin_chg', lambda f: f['ni'] - f['rev'] if f['ni'] is not None and f['rev'] is not None else None)
    both('rev_gap', lambda f: f['rev'] - o['ngdp_g'] if f['rev'] is not None else None)
    both('ic_g', lambda f: f['ic']); both('turn_chg', lambda f: f['rev'] - f['ic'] if f['rev'] is not None and f['ic'] is not None else None)
    both('price_g', lambda f: f['price']); both('vol_g', lambda f: f['rev'] - f['price'] if f['rev'] is not None and f['price'] is not None else None)
    # bridge: years 2..Y, survivors
    Y = int(r['years']); tot = defaultdict(float); open_sum = 0.0; close_open = 0.0
    capex = dep = 0.0; firm_nonret = {}
    for t in S:
        rows = X[t]; nonret = 0.0
        for y in range(2, Y + 1):
            b = B.get(t, {}).get(str(y), {}); g = lambda k: b.get(k, 0.0)
            e_open = rows[4 * (y - 1) - 1]['eq']; e_close = rows[4 * y - 1]['eq']
            open_sum += e_open; close_open += e_close - e_open
            fl = dict(ni=g('r_ni'), div=-g('r_div'), bb=-g('r_bb'), sbc=g('r_sbc'), issue=g('tr_raised'), exec_other=g('tr_exec') - g('tr_raised'),
                      tr_resid=g('tr_fin') - (g('r_ni') + g('r_sbc') - g('r_div') - g('r_bb') - g('r_asl')), asl=-g('r_asl'), imp=-g('r_imp'),
                      marks=g('earn') - g('tr_exec') - g('tr_fin') + g('r_imp'), ma_eq=g('ma_eq'), dv_eq=g('dv_eq'),
                      tick_other=g('tick') - g('earn') - g('ma_eq') - g('dv_eq'), op=g('op'))
            for k, v in fl.items(): tot[k] += v
            tot['aoci'] += 0.0
            nonret += g('tick') + g('op') - (g('r_ni') - g('r_div') - g('r_bb'))
            capex += g('r_capex'); dep += g('r_dep')
            for k in ('ma_n', 'ma_rev', 'ma_gw', 'ma_spent', 'dv_n', 'dv_rev', 'r_rev'): tot[k] += g(k)
        firm_nonret[t] = nonret
    for k in FLOWS: o['b_' + k] = tot[k] / open_sum
    o['b_retained'] = (tot['ni'] + tot['div'] + tot['bb']) / open_sum
    o['b_nonret'] = sum(tot[k] for k in FLOWS if k not in ('ni', 'div', 'bb')) / open_sum
    o['b_dbook'] = close_open / open_sum
    o['b_unexpl'] = (close_open - sum(tot[k] for k in FLOWS)) / open_sum
    o['capex_dep'] = capex / dep
    tn = sum(firm_nonret.values())
    for t, v in firm_nonret.items(): TOP[t].append(v / tn if tn else 0.0)
    o['ma_per_yr'] = tot['ma_n'] / T; o['dv_per_yr'] = tot['dv_n'] / T
    o['ma_rev_pct'] = tot['ma_rev'] / tot['r_rev']; o['dv_rev_pct'] = tot['dv_rev'] / tot['r_rev']
    o['ma_spent_book'] = tot['ma_spent'] / open_sum; o['ma_gw_book'] = tot['ma_gw'] / open_sum
    return o

runs = sorted(glob.glob(os.path.join(d, arm + '-*.json')), key=lambda f: int(f.rsplit('-', 1)[1].split('.')[0]))
S = []
for f in runs:
    r = json.load(open(f)); o = seed_stats(r); o['seed'] = r['seed']; S.append(o)
def show(keys):
    for k, lab in keys:
        v = [o[k] for o in S]; se = st.stdev(v) / math.sqrt(len(v)) if len(v) > 1 else float('nan')
        print('%-46s %9.4f %8.4f' % (lab, st.mean(v), se))
print('arm %s, seeds %s, years 2-20 (survivors)' % (arm, [o['seed'] for o in S]))
print('%-46s %9s %8s' % ('quantity', 'mean', 'se'))
show([('agg_ni_g', '1 aggregate NI growth (log)'), ('agg_rev_g', '1 aggregate revenue growth'), ('agg_margin_chg', '1 aggregate net-margin change'),
      ('ngdp_g', '1 nominal GDP growth'), ('rgdp_g', '1 real GDP growth'), ('defl_g', '1 GDP deflator growth'),
      ('rev_g_md', '1 firm revenue growth, median'), ('rev_g_rw', '1 firm revenue growth, rev-wt'), ('ni_g_md', '1 firm NI growth, median'), ('ni_g_rw', '1 firm NI growth, rev-wt'),
      ('margin_chg_md', '1 firm margin change, median'), ('margin_chg_rw', '1 firm margin change, rev-wt'), ('rev_gap_md', '1 revenue - nominal GDP, median'), ('rev_gap_rw', '1 revenue - nominal GDP, rev-wt'),
      ('agg_ic_g', '2 aggregate invested capital growth'), ('agg_ppe_g', '2 aggregate net PP&E growth'), ('agg_ta_g', '2 aggregate total assets growth'), ('agg_turn_chg', '2 aggregate turnover change (rev - IC)'),
      ('ic_g_md', '2 firm IC growth, median'), ('ic_g_rw', '2 firm IC growth, rev-wt'), ('turn_chg_md', '2 firm turnover change, median'), ('turn_chg_rw', '2 firm turnover change, rev-wt'),
      ('price_g_md', '2 industry price level growth, median'), ('price_g_rw', '2 industry price level growth, rev-wt'), ('price_g_n', '2 firms with price level'),
      ('vol_g_md', '2 volume (rev - price), median'), ('vol_g_rw', '2 volume, rev-wt'), ('capex_dep', '2 capex / depreciation, board'),
      ('b_dbook', '4 dBook / opening book (per yr)'), ('b_retained', '4 retained = NI - div - bb'), ('b_ni', '4   NI'), ('b_div', '4   dividends'), ('b_bb', '4   buybacks'),
      ('b_nonret', '4 non-retained total'), ('b_sbc', '4   stock compensation (APIC)'), ('b_issue', '4   equity issuance'), ('b_exec_other', '4   other in treasury strategy'),
      ('b_tr_resid', '4   treasury finalize residual'), ('b_asl', '4   asset-sale loss (OCI)'), ('b_imp', '4   goodwill impairment'), ('b_marks', '4   AFS + anchor-stake marks'),
      ('b_ma_eq', '4   M&A equity (stock-paid deals)'), ('b_dv_eq', '4   divestiture gain/loss'), ('b_tick_other', '4   other inside the tick'), ('b_op', '4   operator (reorgs etc.)'),
      ('b_unexpl', '4 unexplained (check ~0)'),
      ('ma_per_yr', '5 acquisitions per year (board)'), ('ma_rev_pct', '5 acquired revenue / board revenue (per yr)'), ('dv_per_yr', '5 divestitures per year'),
      ('dv_rev_pct', '5 divested revenue / board revenue'), ('ma_spent_book', '5 M&A spend / opening book (per yr)'), ('ma_gw_book', '5 goodwill booked / opening book')])
print('revenue growth - nominal GDP by business model (pooled firm-seeds): top 10, bottom 5')
L = sorted(((st.mean(v), len(v), k) for k, v in BM.items()), reverse=True)
for row in L[:10] + [None] + L[-5:]:
    print('  ...' if row is None else '  %-28s %7.4f %4d' % (row[2], row[0], row[1]))
print('by sector:')
for m_, n_, k in sorted(((st.mean(v), len(v), k) for k, v in SEC.items()), reverse=True):
    print('  %-28s %7.4f %4d' % (k, m_, n_))
print('top 5 firms by share of board non-retained equity flows (mean over seeds):')
for k, v in sorted(TOP.items(), key=lambda kv: -st.mean(kv[1]))[:5]:
    print('  %-8s %6.3f' % (k, st.mean(v)))

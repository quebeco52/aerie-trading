#!/usr/bin/env python3
"""decompose.py <runs-dir> [arm]: equity-premium decomposition from PF_X runs (years 2..Y). Prints per-seed rows and mean +- se."""
import glob, json, math, os, statistics as st, sys

d = sys.argv[1]; arm = sys.argv[2] if len(sys.argv) > 2 else 'cx'
def med(x): return st.median(x) if x else float('nan')

def seed_stats(r):
    q = r['q']; T = r['years'] - 1.0
    i0 = next(i for i, x in enumerate(q) if abs(x['t'] - 1.0) < 1e-6); i1 = len(q) - 1
    win = [x for x in q if x['t'] > 1.0 + 1e-9]
    y10 = st.mean(x['y10'] for x in win); y10e = st.mean(x['y10e'] for x in win)
    X = r['x']; dead = dict(r['dead']) if isinstance(r['dead'], dict) else {}
    o = {}
    # A(i), A(ii)
    surv = {t: rows for t, rows in X.items() if t not in dead and len(rows) > i1}
    g = {t: math.exp((rows[i1]['cr'] - rows[i0]['cr']) / T) - 1 for t, rows in surv.items()}
    o['Ai'] = med(list(g.values())) - y10
    gd = list(g.values()) + [-1.0 for t, y in dead.items() if y > 1.0]
    o['Aii'] = med(gd) - y10
    o['ndead'] = sum(1 for y in dead.values() if y > 1.0)
    # A(iii) cap-weighted quarterly index + its decomposition
    L = 0.0; terms = dict(D=0.0, EPS=0.0, PE=0.0, FVneg=0.0, PFV=0.0); idx_q = []
    coe_cw, coe_md, floor_n, nq, betas = [], [], 0, 0, []
    for k in range(i0, i1):
        alive = [t for t, rows in X.items() if len(rows) > k and rows[k]['px'] > 0]
        cap = {t: X[t][k]['px'] * X[t][k]['sh'] for t in alive}; tot = sum(cap.values())
        gross = 0.0
        for t in alive:
            w = cap[t] / tot; a = X[t][k]
            if len(X[t]) <= k + 1:
                continue  # died in the quarter: -100%
            b = X[t][k + 1]; f = math.log(b['cf'] / a['cf'])
            gross += w * math.exp(b['cr'] - a['cr'])
            terms['D'] += w * (b['cd'] - a['cd'])
            dfv = math.log(b['fv'] / a['fv']) + f if a['fv'] > 0 and b['fv'] > 0 else 0.0
            terms['PFV'] += w * ((b['cr'] - b['cd']) - (a['cr'] - a['cd']) - dfv)
            if a['eps'] > 0 and b['eps'] > 0 and a['fv'] > 0 and b['fv'] > 0:
                de = math.log(b['eps'] / a['eps']) + f
                terms['EPS'] += w * de; terms['PE'] += w * (dfv - de)
            else:
                terms['FVneg'] += w * dfv
        lr = math.log(gross); L += lr; idx_q.append(lr)
    o['Aiii'] = math.exp(L / T) - 1 - y10
    o['ix_logTR'] = L / T
    for kk, v in terms.items(): o['ix_' + kk] = v / T
    o['ix_jensen'] = L / T - sum(terms.values()) / T
    o['ix_drag'] = 0.5 * st.pvariance(idx_q) * 4
    # B: name-level (survivors with EPS>0, FV>0 at both ends), equal-weighted mean
    nm = {k: [] for k in ('L', 'D', 'EPS', 'PE', 'PFV')}
    for t, rows in surv.items():
        a, b = rows[i0], rows[i1]
        if min(a['eps'], b['eps'], a['fv'], b['fv']) <= 0: continue
        f = math.log(b['cf'] / a['cf'])
        Lt = (b['cr'] - a['cr']) / T; Dt = (b['cd'] - a['cd']) / T
        fv = (math.log(b['fv'] / a['fv']) + f) / T; e = (math.log(b['eps'] / a['eps']) + f) / T
        nm['L'].append(Lt); nm['D'].append(Dt); nm['EPS'].append(e); nm['PE'].append(fv - e); nm['PFV'].append(Lt - Dt - fv)
    for k, v in nm.items():
        o['ew_' + k] = st.mean(v); o['md_' + k] = med(v)
    o['ew_n'] = len(nm['L'])
    o['md_Llog_all'] = med([(rows[i1]['cr'] - rows[i0]['cr']) / T for rows in surv.values()])
    # C: CAPM side, name-quarters in the window
    for k in range(i0 + 1, i1 + 1):
        alive = [t for t, rows in X.items() if len(rows) > k]
        ex = [X[t][k]['coe'] - q[k]['y10e'] for t in alive]
        caps = [X[t][k]['px'] * X[t][k]['sh'] for t in alive]
        coe_md.append(med(ex)); coe_cw.append(sum(c * e for c, e in zip(caps, ex)) / sum(caps))
        for t in alive:
            nq += 1; floor_n += 1 if X[t][k]['mr'] >= X[t][k]['capm'] - 1e-12 and X[t][k]['mr'] > 0 else 0
            betas.append(X[t][k]['lb'])
    o['coe_md'] = st.mean(coe_md); o['coe_cw'] = st.mean(coe_cw); o['floor'] = floor_n / nq; o['beta_md'] = med(betas)
    o['erp_macro'] = st.mean(x['erp'] for x in win)
    s2 = []
    for t, ys in r['ny'].items():
        v = [y['s2'] for k2, y in ys.items() if int(k2) >= 2]
        if v: s2.append(st.mean(v))
    o['name_drag'] = 0.5 * med(s2)
    o['exp_md'] = o['coe_md'] - o['name_drag']; o['exp_cw'] = o['coe_cw'] - o['ix_drag']
    # D: macro
    o['ngdp_g'] = math.log(q[i1]['ngdp'] / q[i0]['ngdp']) / T
    o['infl'] = st.mean(x['infl'] for x in win); o['y10'] = y10; o['y10e'] = y10e
    names = [t for t in surv]
    ni0 = sum(X[t][i0]['ni'] for t in names); ni1 = sum(X[t][i1]['ni'] for t in names)
    eq0 = sum(X[t][i0]['eq'] for t in names); eq1 = sum(X[t][i1]['eq'] for t in names)
    mc0 = sum(X[t][i0]['px'] * X[t][i0]['sh'] for t in names); mc1 = sum(X[t][i1]['px'] * X[t][i1]['sh'] for t in names)
    o['ni_g'] = math.log(ni1 / ni0) / T if ni0 > 0 and ni1 > 0 else float('nan')
    o['eq_g'] = math.log(eq1 / eq0) / T; o['mcap_g'] = math.log(mc1 / mc0) / T
    sh0 = sum(X[t][i0]['sh'] * X[t][i0]['cf'] ** -1 for t in names)
    return o

runs = sorted(glob.glob(os.path.join(d, arm + '-*.json')), key=lambda f: int(f.rsplit('-', 1)[1].split('.')[0]))
S = []
for f in runs:
    r = json.load(open(f)); o = seed_stats(r); o['seed'] = r['seed']; S.append(o)
keys = [('Ai', 'A(i) median survivor geo TR - y10'), ('Aii', 'A(ii) incl. failed as -100%'), ('Aiii', 'A(iii) cap-wt index geo TR - y10'),
        ('ndead', '  failures in years 2-20'),
        ('ix_logTR', 'B idx: log TR / yr'), ('ix_D', 'B idx: dividend (log)'), ('ix_EPS', 'B idx: TTM EPS growth'), ('ix_PE', 'B idx: FV/EPS growth'),
        ('ix_FVneg', 'B idx: FV growth, EPS<=0 names'), ('ix_PFV', 'B idx: dlog(P/FV)'), ('ix_jensen', 'B idx: diversification (residual)'),
        ('ew_n', 'B names: n (EPS>0 both ends)'), ('ew_L', 'B names EW: log TR / yr'), ('ew_D', 'B names EW: dividend'), ('ew_EPS', 'B names EW: EPS growth'),
        ('ew_PE', 'B names EW: FV/EPS growth'), ('ew_PFV', 'B names EW: dlog(P/FV)'), ('md_L', 'B names median: log TR'), ('md_D', 'B names median: dividend'),
        ('md_EPS', 'B names median: EPS growth'), ('md_PE', 'B names median: FV/EPS growth'), ('md_PFV', 'B names median: dlog(P/FV)'),
        ('coe_md', 'C CoE - y10Ema, median name'), ('coe_cw', 'C CoE - y10Ema, cap-weighted'), ('floor', 'C floor binds (share name-qtrs)'),
        ('beta_md', 'C median levered beta'), ('erp_macro', 'C macro ERP, time mean'), ('name_drag', 'C median name sigma^2/2'), ('ix_drag', 'C index sigma^2/2'),
        ('exp_md', 'C expected geo excess, median name'), ('exp_cw', 'C expected geo excess, index'),
        ('ngdp_g', 'D nominal GDP growth (log)'), ('infl', 'D inflation, mean'), ('y10', 'D y10, mean'), ('y10e', 'D y10Ema, mean'),
        ('ni_g', 'D board TTM NI growth (log)'), ('eq_g', 'D board book equity growth'), ('mcap_g', 'D board market cap growth')]
print('arm %s, seeds %s, years 2-%d' % (arm, [o['seed'] for o in S], 20))
print('%-40s %9s %8s  %s' % ('quantity', 'mean', 'se', 'per seed'))
for k, lab in keys:
    v = [o[k] for o in S]
    se = st.stdev(v) / math.sqrt(len(v)) if len(v) > 1 else float('nan')
    print('%-40s %9.4f %8.4f  %s' % (lab, st.mean(v), se, ' '.join('%.4f' % x for x in v)))

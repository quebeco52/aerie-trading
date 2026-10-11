"""paired.py: per-seed paired differences (after - before) for median R2, count R2<0.1, and realised betas of named names."""
import json, statistics as st, math
D = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/betas/runs/'
def realised(arm, s):
    d = json.load(open(D + f'{arm}-{s}.json')); snaps = d['snap']; out = {}
    rm = []; ri = {t: [] for t in snaps[0]}
    for a, b in zip(snaps[:-1], snaps[1:]):
        live = [t for t in a if not a[t][2] and not b[t][2]]
        rm.append(sum(b[t][0]*b[t][1] for t in live) / sum(a[t][0]*a[t][1] for t in live) - 1)
        for t in a:
            if a[t][2] or b[t][2]: ri[t].append(None); continue
            k = b[t][1] / a[t][1]
            ri[t].append((b[t][0]*b[t][1])/(a[t][0]*a[t][1]) - 1 if (k > 1.5 or k < 0.67) else b[t][0]/a[t][0] - 1)
    for t, ys in ri.items():
        p = [(x, y) for x, y in zip(rm, ys) if y is not None]
        mx = st.mean(q[0] for q in p); my = st.mean(q[1] for q in p)
        cxy = sum((q[0]-mx)*(q[1]-my) for q in p); cxx = sum((q[0]-mx)**2 for q in p); cyy = sum((q[1]-my)**2 for q in p)
        out[t] = (cxy/cxx, cxy*cxy/(cxx*cyy))
    o = d['open']; W = sum(v['cap'] for v in o.values())
    infeas = sum(1 for v in o.values() if (v['blev']*0.15/v['vol'])**2 > 0.8)
    return out, {'capw_beta': sum(v['cap']*v['blev'] for v in o.values())/W, 'board_vol': st.pstdev(rm)*12**0.5, 'infeasible': infeas,
                 'pe_med': st.median(v['pe'] for v in o.values() if v['pe'])}
NAMES = ['BIRD', 'WADE', 'PLZA', 'LOON', 'LYRE', 'SWFT', 'OSPR', 'VULT']
rows = {}
for s in ['1', '2', '3']:
    rb, xb = realised('before', s); ra, xa = realised('after', s)
    r = {'medR2': st.median(v[1] for v in ra.values()) - st.median(v[1] for v in rb.values()),
         'nLowR2': sum(1 for v in ra.values() if v[1] < 0.1) - sum(1 for v in rb.values() if v[1] < 0.1),
         'board_vol': xa['board_vol'] - xb['board_vol']}
    for n in NAMES: r['b_' + n] = ra[n][0] - rb[n][0]
    r['_b'] = {'medR2': st.median(v[1] for v in rb.values()), 'nLowR2': sum(1 for v in rb.values() if v[1] < 0.1), 'board_vol': xb['board_vol'], **{'b_'+n: rb[n][0] for n in NAMES}, 'infeas': xb['infeasible'], 'capw': xb['capw_beta']}
    r['_a'] = {'medR2': st.median(v[1] for v in ra.values()), 'nLowR2': sum(1 for v in ra.values() if v[1] < 0.1), 'board_vol': xa['board_vol'], **{'b_'+n: ra[n][0] for n in NAMES}, 'infeas': xa['infeasible'], 'capw': xa['capw_beta']}
    rows[s] = r
print('infeasible opening configs (beta_L*0.15/vol)^2>0.8: before', rows['1']['_b']['infeas'], 'after', rows['1']['_a']['infeas'])
print('cap-weighted opening levered beta: before %.3f after %.3f' % (rows['1']['_b']['capw'], rows['1']['_a']['capw']))
print(f"{'quantity':12} {'before':>8} {'after':>8} {'delta':>8} {'SE':>7} {'t':>6}  (n=3 paired seeds)")
for k in ['medR2', 'nLowR2', 'board_vol'] + ['b_' + n for n in NAMES]:
    d = [rows[s][k] for s in rows]; m = st.mean(d); se = st.stdev(d)/math.sqrt(len(d))
    print(f"{k:12} {st.mean(rows[s]['_b'][k] for s in rows):8.3f} {st.mean(rows[s]['_a'][k] for s in rows):8.3f} {m:+8.3f} {se:7.3f} {m/se if se>0 else float('inf'):6.1f}")

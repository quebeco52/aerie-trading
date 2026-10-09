#!/usr/bin/env python3
"""IBIS descriptive table from runs/s*.jsonl (BiotechHarnessTest). Prints the final table only."""
import json, glob, math, os, statistics as st
H = os.path.dirname(os.path.abspath(__file__))
runs = [json.loads(open(f).readline()) for f in sorted(glob.glob(H + '/runs/s*.jsonl'))]
n = len(runs)
def ms(xs):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    if not xs: return 'n/a'
    m = sum(xs) / len(xs); se = (st.stdev(xs) / math.sqrt(len(xs))) if len(xs) > 1 else float('nan')
    return f'{m:.4f} ({se:.4f}) n={len(xs)}'
def pct(xs, p):
    xs = sorted(xs); k = (len(xs) - 1) * p; f = math.floor(k); c = min(f + 1, len(xs) - 1)
    return xs[f] + (xs[c] - xs[f]) * (k - f)
def p1090(xs): return f'p10 {pct(xs,.1):.4f} p90 {pct(xs,.9):.4f}' if xs else ''
EV = {'BIOTECH_DRUG_APPROVAL': 'approval', 'BIOTECH_TRIAL_SETBACK': 'setback', 'BIOTECH_PATENT_CLIFF': 'cliff'}
def evname(e):
    if e is None: return 'none'
    for k, v in EV.items():
        if k.lower() in str(e).lower() or str(e).lower() == v or k.split('_', 1)[1].lower() in str(e).lower(): return v
    return 'other:' + str(e)
out = []
P = out.append
yrs = runs[0]['years']; tpy = runs[0]['tpy']
P(f'n seeds {n}, {yrs:g} years, {tpy} ticks/year; IBIS reports per seed {[len(r["ibis_q"]) for r in runs]}; IBIS dead in {sum(1 for r in runs if "IBIS" in r["dead"])} seeds')
P(f'event labels seen: {sorted(set(str(q["ev"]) for r in runs for q in r["ibis_q"]))}')
# 1 event rates
P('\n1. events per year (mean (se) across seeds)')
for v in ['approval', 'setback', 'cliff']:
    P(f'  {v:9s} ' + ms([sum(1 for q in r['ibis_q'] if evname(q['ev']) == v) / yrs for r in runs]))
# 2 franchise
P('\n2. state:commercial_franchise at year end')
for y in [5, 10, 20, 30]:
    xs = []
    for r in runs:
        qs = [q for q in r['ibis_q'] if q['tick'] <= y * tpy]
        if qs: xs.append(qs[-1]['fr'])
    P(f'  y{y:2d} {ms(xs)} {p1090(xs)} max {max(xs):.4f}')
P(f'  max seen any quarter {max(q["fr"] for r in runs for q in r["ibis_q"]):.4f}')
ks = [q['ks'] for r in runs for q in r['ibis_q']]
P(f'  known_commercial_shift min {min(ks):.4f} max {max(ks):.4f} mean {sum(ks)/len(ks):.4f} (pooled n={len(ks)})')
# 3 utilization
P('\n3. deseasonalized utilization u/sf')
ud = lambda q: q['u'] / max(0.01, q['sf'])
P('  mean          ' + ms([st.mean(ud(q) for q in r['ibis_q']) for r in runs]))
P('  share >1      ' + ms([st.mean(ud(q) > 1 for q in r['ibis_q']) for r in runs]))
ot = [st.mean(0.6 * (ud(q) - 1) ** 1.5 for q in r['ibis_q'] if ud(q) > 1) for r in runs if any(ud(q) > 1 for q in r['ibis_q'])]
P('  mean 0.6(u-1)^1.5 in u>1 quarters ' + ms(ot))
P('  share raw u at 1.5 clamp ' + ms([st.mean(q['u'] >= 1.5 - 1e-9 for q in r['ibis_q']) for r in runs]))
P('  share u/sf <0.95 (NRV trigger) ' + ms([st.mean(ud(q) < 0.95 for q in r['ibis_q']) for r in runs]))
P(f'  pooled {p1090([ud(q) for r in runs for q in r["ibis_q"]])}, seasonal factors seen {sorted(set(round(q["sf"],3) for r in runs for q in r["ibis_q"]))[:6]}')
# 4 protected share around window close
P('\n4. patent_protected_share around erosion-window close (prev loe>=11 -> 0)')
c0, c1, cprev = [], [], []
for r in runs:
    qs = r['ibis_q']
    for i in range(1, len(qs)):
        if qs[i-1]['loe'] >= 11 and qs[i]['loe'] == 0:
            cprev.append(qs[i-1]['ps']); c0.append(qs[i]['ps'])
            if i + 1 < len(qs): c1.append(qs[i+1]['ps'])
P(f'  closings {len(c0)}; prior quarter mean {st.mean(cprev) if cprev else float("nan"):.4f}; closing quarter mean {st.mean(c0) if c0 else float("nan"):.4f} [{min(c0, default=0):.4f},{max(c0, default=0):.4f}]; next quarter mean {st.mean(c1) if c1 else float("nan"):.4f} [{min(c1, default=0):.4f},{max(c1, default=0):.4f}]')
P(f'  max loe persisted {max(q["loe"] for r in runs for q in r["ibis_q"])}')
# 5 margins
P('\n5. EBIT/revenue by window; stock operatingMargin vs ceiling')
for a, b in [(1, 5), (11, 15), (26, 30)]:
    sel = lambda r: [q for q in r['ibis_q'] if (a - 1) * tpy < q['tick'] <= b * tpy and q['rev'] > 0]
    pool = [q['ebit'] / q['rev'] for r in runs for q in sel(r)]
    P(f'  y{a}-{b} EBIT/rev ' + ms([st.mean(q['ebit'] / q['rev'] for q in sel(r)) for r in runs if sel(r)]) + ' ' + p1090(pool)
      + f' | om {ms([st.mean(q["om"] for q in sel(r)) for r in runs if sel(r)])} ceil {ms([st.mean(q["ceil"] for q in sel(r)) for r in runs if sel(r)])}'
      + f' | share om>ceil {st.mean(q["om"] > q["ceil"] + 1e-9 for r in runs for q in sel(r)):.3f}')
P('  om-ceil all quarters ' + ms([st.mean(q['om'] - q['ceil'] for q in r['ibis_q']) for r in runs]))
# 6 CAGR
P('\n6. CAGR year 1 -> year 30 (annual sums / means)')
rc, gc = [], []
for r in runs:
    y1 = [q for q in r['ibis_q'] if q['tick'] <= tpy]; yl = [q for q in r['ibis_q'] if q['tick'] > (yrs - 1) * tpy]
    span = yrs - 1
    rc.append((sum(q['rev'] for q in yl) / sum(q['rev'] for q in y1)) ** (1 / span) - 1)
    gc.append((st.mean(q['ngdp'] for q in yl) / st.mean(q['ngdp'] for q in y1)) ** (1 / span) - 1)
P('  revenue      ' + ms(rc)); P('  nominal GDP  ' + ms(gc)); P('  difference   ' + ms([a - b for a, b in zip(rc, gc)]))
# 7 surprises and returns
P('\n7. revenue surprise by quarter type: model-expected (rev/exp-1) | consensus (rev/aexp-1); excess log return vs board ex-IBIS')
for v in ['approval', 'setback', 'cliff', 'none']:
    s1 = [q['rev'] / q['exp'] - 1 for r in runs for q in r['ibis_q'] if evname(q['ev']) == v and q['exp'] > 0]
    s2 = [q['rev'] / q['aexp'] - 1 for r in runs for q in r['ibis_q'] if evname(q['ev']) == v and q['aexp'] > 0]
    d1, d90 = [], []
    for r in runs:
        px, bd = r['ibis_px'], r['board']
        for q in r['ibis_q']:
            if evname(q['ev']) != v: continue
            i = q['tick'] - 1
            if i >= 1 and px[i - 1] > 0 and px[i] > 0:
                d1.append(math.log(px[i] / px[i - 1]) - math.log(bd[i] / bd[i - 1]))
            j = min(len(px) - 1, i + 89)
            if i >= 1 and j > i and px[j] > 0:
                d90.append(math.log(px[j] / px[i - 1]) - math.log(bd[j] / bd[i - 1]))
    P(f'  {v:9s} surprise {ms(s1)} | consensus {ms(s2)}')
    P(f'  {"":9s} excess ret report tick {ms(d1)} | 90 ticks from report {ms(d90)}')
# 8 reimbursement shift
P('\n8. reimbursementShift')
re = [q['reimb'] for r in runs for q in r['ibis_q']]
P('  mean ' + ms([st.mean(q['reimb'] for q in r['ibis_q']) for r in runs]) + ' ' + p1090(re) + f' min {min(re):.5f} max {max(re):.5f}')
pairs = [(q['reimb'], q['rev'] / q['exp'] - 1) for r in runs for q in r['ibis_q'] if q['ev'] is None and q['exp'] > 0]
if len(set(p[0] for p in pairs)) > 1:
    P(f'  corr with no-event surprise (pooled n={len(pairs)}): {st.correlation([p[0] for p in pairs], [p[1] for p in pairs]):.3f}')
else:
    P('  corr: reimbursementShift constant, undefined')
P('  share quarters revenue beats consensus ' + ms([st.mean(q['rev'] > q['aexp'] for q in r['ibis_q']) for r in runs]))
# 9 reinvestment ratio
P('\n9. reinvestmentRatio passed to applyAssetDepreciationDecay')
rr = [q['rr'] for r in runs for q in r['ibis_q'] if q['rr'] is not None]
P(f'  captured {len(rr)} of {sum(len(r["ibis_q"]) for r in runs)} quarters; mean ' + ms([st.mean(q['rr'] for q in r['ibis_q'] if q['rr'] is not None) for r in runs]) + ' ' + p1090(rr))
P('  share <1 ' + ms([st.mean(q['rr'] < 1 for q in r['ibis_q'] if q['rr'] is not None) for r in runs]) + '  share >1 ' + ms([st.mean(q['rr'] > 1 for q in r['ibis_q'] if q['rr'] is not None) for r in runs]))
P('  cumulative om change from method ' + ms([sum(q['om1'] - q['om0'] for q in r['ibis_q'] if q['rr'] is not None) for r in runs]))
P('  om first->last report ' + ms([r['ibis_q'][-1]['om'] - r['ibis_q'][0]['om0'] for r in runs]))
print('\n'.join(out))

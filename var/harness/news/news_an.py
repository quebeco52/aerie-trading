#!/usr/bin/env python3
"""Per-year story and headline counts by category from runs/news-<seed>.jsonl (NewsCountHarnessTest)."""
import json, glob, math, os, sys, statistics as st
H = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/news/' + os.environ.get('RUNS', 'runs_v1')
Y0 = float(sys.argv[1]) if len(sys.argv) > 1 else 0.0
Y1 = float(sys.argv[2]) if len(sys.argv) > 2 else 5.0
YEARS = Y1 - Y0
SEEDS = set(int(x) for x in sys.argv[3].split(',')) if len(sys.argv) > 3 else None
CATS = ['earnings','mna','debt','bankruptcy','reorganization','shock','analyst','governance','split','index','income','district','economy','government','general']
files = sorted(glob.glob(H + '/news-*.jsonl'), key=lambda f: int(f.split('-')[-1].split('.')[0]))
per = []  # per-seed dicts
ratios, mna_rows, earn_z, shocks, types_general, debt_rows, topics = [], [], [], [], {}, [], {}
for f in files:
    if SEEDS is not None and int(f.split('-')[-1].split('.')[0]) not in SEEDS: continue
    rows = [json.loads(l) for l in open(f)]
    if not rows or rows[-1]['y'] < Y1 - 0.05: continue
    rows = [r for r in rows if Y0 < r['y'] <= Y1]
    d = {c: [0, 0] for c in CATS + ['TOTAL']}
    for r in rows:
        c = r['c'] if r['c'] in CATS else 'general'
        d[c][0] += 1; d[c][1] += r['h']; d['TOTAL'][0] += 1; d['TOTAL'][1] += r['h']
        if c == 'mna' and r.get('deal') and r.get('cap'): mna_rows.append((r['ty'], r['deal'] / r['cap'], r['ch'], r['deal']))
        if c == 'earnings' and 'z' in r: earn_z.append(r['z'])
        if c == 'shock' and r['ch'] is not None: shocks.append(abs(r['ch']))
        if c == 'general': types_general[r['ty']] = types_general.get(r['ty'], 0) + 1
        if c in ('economy', 'government'): topics[(c, r.get('topic'))] = topics.get((c, r.get('topic')), 0) + 1
    d['_mna_ty'] = {}
    for r in rows:
        if r['c'] == 'mna': d['_mna_ty'][r['ty']] = d['_mna_ty'].get(r['ty'], 0) + 1
    per.append(d)
n = len(per)
def ms(xs):
    m = sum(xs) / len(xs); se = (st.stdev(xs) / math.sqrt(len(xs))) if len(xs) > 1 else float('nan'); return m, se, min(xs), max(xs)
print(f'n = {n} seeds, years {Y0:g}-{Y1:g}, per simulated year (mean +- se across seeds; range of stories/yr)')
print(f"{'category':15s} {'stories/yr':>16s} {'range':>13s} {'headlines/yr':>16s} {'hl share':>8s}")
for c in CATS + ['TOTAL']:
    s = ms([p[c][0] / YEARS for p in per]); h = ms([p[c][1] / YEARS for p in per])
    share = sum(p[c][1] for p in per) / max(1, sum(p[c][0] for p in per))
    print(f"{c:15s} {s[0]:8.1f} +- {s[1]:5.1f} {s[2]:6.1f}-{s[3]:6.1f} {h[0]:8.1f} +- {h[1]:5.1f} {share:8.1%}")
def q(xs, p):
    xs = sorted(xs); k = (len(xs) - 1) * p; i = int(k); return xs[i] + (xs[min(i + 1, len(xs) - 1)] - xs[i]) * (k - i)
deals = [x for x in mna_rows if x[0] != 'DIVESTITURE']; div = [x for x in mna_rows if x[0] == 'DIVESTITURE']
for name, rs in (('acquisitions', deals), ('divestitures', div)):
    if not rs: continue
    r = [x[1] for x in rs]
    print(f"\nmna {name}: n={len(rs)} deals; deal $ / acquirer cap (prior tick) q10 {q(r,.1):.3f} q50 {q(r,.5):.3f} q90 {q(r,.9):.3f}")
    for thr in (0.05, 0.10, 0.20, 0.30):
        print(f"  share >= {thr:.0%} of cap: {sum(1 for x in r if x >= thr)/len(r):.1%}  (-> {sum(1 for x in r if x >= thr)/n/YEARS:.1f}/yr)")
    a = [abs(x[2]) for x in rs]
    print(f"  |announcement move| % q10 {q(a,.1):.2f} q50 {q(a,.5):.2f} q90 {q(a,.9):.2f}")
tys = {}
for p in per:
    for k, v in p['_mna_ty'].items(): tys[k] = tys.get(k, 0) + v
print('mna by type /yr:', {k: round(v / n / YEARS, 1) for k, v in sorted(tys.items(), key=lambda kv: -kv[1])})
if earn_z:
    print(f"\nearnings: n={len(earn_z)}; share |z|>=1.96 {sum(1 for z in earn_z if z >= 1.96)/len(earn_z):.1%}; z q50 {q(earn_z,.5):.2f} q90 {q(earn_z,.9):.2f}; share >=2.58 {sum(1 for z in earn_z if z >= 2.58)/len(earn_z):.1%}; >=3 {sum(1 for z in earn_z if z >= 3)/len(earn_z):.1%}")
if shocks:
    print(f"shock: n={len(shocks)}; share |move|>10% {sum(1 for s in shocks if s > 10)/len(shocks):.1%}; q50 {q(shocks,.5):.2f}% q90 {q(shocks,.9):.2f}%")
print('general types:', types_general)
print('district topics /yr:', {f'{k[0]}:{k[1]}': round(v / n / YEARS, 2) for k, v in sorted(topics.items(), key=lambda kv: -kv[1])})

# Alternative rules, per seed (mean +- se of headlines/yr)
alt = {'mna deal >= 10% of cap': [], 'mna deal >= 5% of cap': [], 'earnings z >= 2.58': [], 'earnings z >= 3.0': [], 'all headlines, mna >= 10% cap': []}
for f in files:
    if SEEDS is not None and int(f.split('-')[-1].split('.')[0]) not in SEEDS: continue
    rows = [json.loads(l) for l in open(f)]
    rows = [r for r in rows if Y0 < r['y'] <= Y1]
    m10 = sum(1 for r in rows if r['c'] == 'mna' and r.get('cap') and r.get('deal', 0) / r['cap'] >= 0.10)
    m05 = sum(1 for r in rows if r['c'] == 'mna' and r.get('cap') and r.get('deal', 0) / r['cap'] >= 0.05)
    allh = sum(r['h'] for r in rows if r['c'] != 'mna') + m10
    alt['mna deal >= 10% of cap'].append(m10 / YEARS); alt['mna deal >= 5% of cap'].append(m05 / YEARS)
    alt['earnings z >= 2.58'].append(sum(1 for r in rows if r['c'] == 'earnings' and r.get('z', 0) >= 2.58) / YEARS)
    alt['earnings z >= 3.0'].append(sum(1 for r in rows if r['c'] == 'earnings' and r.get('z', 0) >= 3.0) / YEARS)
    alt['all headlines, mna >= 10% cap'].append(allh / YEARS)
print('\nalternative rules (headlines/yr, mean +- se):')
for k, v in alt.items():
    m, se, lo, hi = ms(v); print(f'  {k:32s} {m:7.1f} +- {se:4.1f}')
big = sorted([x for x in mna_rows if x[0] == 'DIVESTITURE'], key=lambda x: -x[1])[:5]
print('largest divestitures (sale/cap, move%, $B):', [(round(x[1], 2), x[2], round(x[3] / 1e9, 1)) for x in big])

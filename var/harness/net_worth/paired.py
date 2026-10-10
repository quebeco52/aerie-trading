"""Paired WORTH-NET fiscal moments from the full-market runs (POLITICS=1, FISCAL=1 MACRO_OUT).

    python3 paired.py <runs dir holding net-<seed>.macro.jsonl and worth-<seed>.macro.jsonl>

Targets named before the run: mean gross debt/GDP, mean sovereign spread (bp), mean net-tax rate. Also: the debt the
WORTH rules read (net debt less the fund's equities and rescue holdings), fund/GDP, share of quarters with a spread,
share at the 2.5 ceiling, debt at the end, gap sd, and interest paid (debt x spread is not booked here; see primary).
"""
import glob, json, math, statistics as st, sys

D = sys.argv[1]


def worth(r):
    return r['net_debt'] - (r['fund'] - (r['debt'] - r['net_debt'])) - r['rescue_assets']


def load(arm):
    out = {}
    for f in glob.glob(f'{D}/{arm}-*.macro.jsonl'):
        rows = [json.loads(l) for l in open(f)]
        q = [r for r in rows if r['t'] > 0.01]
        if not q:
            continue
        out[rows[0]['seed']] = {
            'gross debt': st.mean(r['debt'] for r in q),
            'spread bp': 1e4 * st.mean(r['spread'] for r in q),
            'net tax %': 100 * st.mean(r['net_tax'] for r in q),
            'gfsm net debt': st.mean(r['net_debt'] for r in q),
            'worth debt': st.mean(worth(r) for r in q),
            'fund/gdp': st.mean(r['fund'] for r in q),
            'spread>0 %': 100 * sum(r['spread'] > 1e-4 for r in q) / len(q),
            'ceiling %': 100 * sum(r['debt'] > 2.49 for r in q) / len(q),
            'debt end': q[-1]['debt'],
            'primary def %': 100 * st.mean(r['primary'] for r in q),
            'gap sd %': 100 * st.pstdev([r['gap'] for r in q]),
            'years': q[-1]['t'],
        }
    return out


a, b = load('net'), load('worth')
seeds = sorted(set(a) & set(b))
yrs = min(min(a[s]['years'] for s in seeds), min(b[s]['years'] for s in seeds))
print(f"full market, POLITICS=1, seeds paired {len(seeds)}, years >= {yrs:.0f}")
print(f"{'quantity':<15s} {'NET':>8s} {'WORTH':>8s} {'diff':>8s} {'se':>7s} {'t':>7s}")
for k in ('gross debt', 'spread bp', 'net tax %', 'gfsm net debt', 'worth debt', 'fund/gdp', 'spread>0 %',
          'ceiling %', 'debt end', 'primary def %', 'gap sd %'):
    x = [a[s][k] for s in seeds]
    y = [b[s][k] for s in seeds]
    d = [q - p for p, q in zip(x, y)]
    se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    t = st.mean(d) / se if se and se > 0 else float('nan')
    print(f"{k:<15s} {st.mean(x):8.3f} {st.mean(y):8.3f} {st.mean(d):+8.3f} {se:7.3f} {t:+7.2f}")

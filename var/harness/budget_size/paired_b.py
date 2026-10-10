"""Paired NEW-OLD fiscal moments from the full-market runs (POLITICS=1, FISCAL=1 MACRO_OUT).

    python3 paired_b.py <runs dir holding new-<seed>.macro.jsonl and old-<seed>.macro.jsonl>

Per seed, over the quarterly rows (the tick-1 founding row excluded): mean gross debt/GDP, share of quarters with
gross debt above the 0.90 brake line, mean primary deficit/GDP, mean net-tax rate, and debt at the end. Founding row:
the tick-1 primary deficit. Prints arm means, the paired difference, its se and t.
"""
import glob, json, math, re, statistics as st, sys

D = sys.argv[1]


def load(arm):
    out = {}
    for f in glob.glob(f'{D}/{arm}-*.macro.jsonl'):
        rows = [json.loads(l) for l in open(f)]
        q = [r for r in rows if r['t'] > 0.01]
        if not q:
            continue
        out[rows[0]['seed']] = {
            'debt mean': st.mean(r['debt'] for r in q),
            'debt end': q[-1]['debt'],
            'share >0.90 %': 100 * sum(r['debt'] > 0.90 for r in q) / len(q),
            'primary def %': 100 * st.mean(r['primary'] for r in q),
            'net tax %': 100 * st.mean(r['net_tax'] for r in q),
            'gap sd %': 100 * st.pstdev([r['gap'] for r in q]),
            'founding pd bp': 1e4 * rows[0]['primary'] if rows[0]['t'] < 0.01 else float('nan'),
            'years': q[-1]['t'],
        }
    return out


o, n = load('old'), load('new')
seeds = sorted(set(o) & set(n))
yrs = min(min(o[s]['years'] for s in seeds), min(n[s]['years'] for s in seeds))
print(f"B: full market, POLITICS=1, seeds paired {len(seeds)}, years >= {yrs:.0f}")
print(f"{'quantity':<16s} {'OLD .21':>8s} {'NEW .15':>8s} {'NEW-OLD':>8s} {'se':>7s} {'t':>7s}")
for k in ('debt mean', 'debt end', 'share >0.90 %', 'primary def %', 'net tax %', 'gap sd %', 'founding pd bp'):
    a = [o[s][k] for s in seeds]
    b = [n[s][k] for s in seeds]
    d = [y - x for x, y in zip(a, b)]
    se = st.stdev(d) / math.sqrt(len(d))
    t = st.mean(d) / se if se > 0 else float('inf')
    print(f"{k:<16s} {st.mean(a):8.3f} {st.mean(b):8.3f} {st.mean(d):+8.3f} {se:7.3f} {t:+7.2f}")

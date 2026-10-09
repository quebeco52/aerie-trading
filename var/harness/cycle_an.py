"""The cycle against the CBO record: python3 var/harness/cycle_an.py <dir> <arm> [arm ...]

CreditMacroProbeTest quarter-average gaps (burn-in dropped), pooled over seeds: sd, sd of quarterly moves, the share
of quarters below -3% and -2%, ACF4, credit crises a century. CBO (FRED GDPC1 / GDPPOT, current vintage):
1985-2019 sd 1.62, moves 0.52, <-3% 8.6%, <-2% 17.1%, ACF4 .66 (one crisis, 2008); 1985-2007 sd 1.21, <-3% 0%,
<-2% 3.3%, ACF4 .54 (none); 1949-2019 sd 2.33, moves 0.88, <-3% 10.6%.
"""
import glob, json, statistics as st, sys

D = sys.argv[1]
BURN = 10.0


def acf(x, lag):
    m = st.mean(x)
    return sum((x[i] - m) * (x[i + lag] - m) for i in range(len(x) - lag)) / sum((a - m) ** 2 for a in x)


print(f"{'arm':16s}   sd   moves  <-3%   <-2%  ACF4  crises/c")
for arm in sys.argv[2:]:
    by = {}
    for p in glob.glob(f'{D}/{arm}-*.json'):
        for r in json.load(open(p)):
            if r['t'] > BURN:
                by.setdefault(r['seed'], []).append(r)
    if not by:
        print(f"{arm}: no rows")
        continue
    g = [100 * r['gap_avg'] for q in by.values() for r in q]
    mv = [100 * (b['gap_avg'] - a['gap_avg']) for q in by.values() for a, b in zip(q, q[1:])]
    a4 = st.mean(acf([100 * r['gap_avg'] for r in q], 4) for q in by.values())
    years = sum(q[-1]['t'] - q[0]['t'] for q in by.values())
    crises = sum(len({r['crisis'] for r in q if r['crisis'] and r['crisis'] > BURN}) for q in by.values())
    print(f"{arm:16s} {st.pstdev(g):5.2f} {st.pstdev(mv):6.2f} {100 * sum(x < -3 for x in g) / len(g):5.1f} {100 * sum(x < -2 for x in g) / len(g):6.1f} {a4:+5.2f} {100 * crises / years:7.2f}")
print("CBO 1985-2019     1.62   0.52   8.6   17.1  +0.66   (one crisis)")
print("CBO 1985-2007     1.21   0.47   0.0    3.3  +0.54   (none)")

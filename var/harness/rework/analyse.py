#!/usr/bin/env python3
"""analyse.py <runs dir> <arm> [<arm2>]: per-arm moments across seeds (mean ± SE); with two arms, paired Δ and t."""
import json, sys, glob, math, statistics as st

BURN = 40  # quarters (10 years)

def moments(r):
    q = lambda f: [x for x in r[f][BURN:] if x is not None] if r.get(f) else []
    m = {}
    gap, u, pi, pr = q('outputGap'), q('unemploymentRate'), q('inflation'), q('policyRate')
    m['gap mean %'] = 100 * st.mean(gap); m['gap sd %'] = 100 * st.pstdev(gap)
    m['unemp mean %'] = 100 * st.mean(u); m['unemp sd %'] = 100 * st.pstdev(u)
    m['infl mean %'] = 100 * st.mean(pi); m['infl sd %'] = 100 * st.pstdev(pi)
    d = [b - a for a, b in zip(pr, pr[1:])]
    m['rate dq sd bp'] = 1e4 * st.pstdev(d)
    m['rate mean %'] = 100 * st.mean(pr)
    debt = q('sovereignDebtToGdp')
    m['debt mean %'] = 100 * st.mean(debt); m['debt max %'] = 100 * max(debt)
    sp = q('sovereignRiskSpread'); m['spread mean bp'] = 1e4 * st.mean(sp) if sp else float('nan')
    m['EBP mean bp'] = 1e4 * st.mean(q('excessBondPremium'))
    m['TED mean bp'] = 1e4 * st.mean(q('interbankLiquiditySpread'))
    m['sentiment mean'] = st.mean(q('consumerSentimentIndex'))
    m['M2 mean %'] = 100 * st.mean(q('moneySupplyGrowth'))
    rec = q('recessionDeclared')
    if not rec and r.get('sahmRecessionIndicator'):
        rec = [1 if x is not None and x >= 0.005 else 0 for x in r['sahmRecessionIndicator'][BURN:]]
    m['recession share %'] = 100 * st.mean(rec) if rec else float('nan')
    crises = len({x for x in q('lastCreditCrisisAt') if x >= 0})
    m['crises /100y'] = crises * 100 / (len(gap) / 4)
    m['max |gap| %'] = 100 * max(abs(x) for x in gap)
    return m

def load(d, arm):
    out = {}
    for p in glob.glob(f'{d}/{arm}_*.jsonl'):
        r = json.loads(open(p).read().splitlines()[0]); out[r['seed']] = moments(r)
    return out

d, arms = sys.argv[1], sys.argv[2:]
data = [load(d, a) for a in arms]
keys = list(next(iter(data[0].values())).keys())
hdr = f"{'moment':22}" + ''.join(f"{a + ' (n=' + str(len(x)) + ')':>24}" for a, x in zip(arms, data))
if len(arms) == 2: hdr += f"{'Δ':>10}{'t':>7}"
print(hdr)
for k in keys:
    row = f'{k:22}'
    for x in data:
        v = [s[k] for s in x.values() if not math.isnan(s[k])]
        se = st.stdev(v) / math.sqrt(len(v)) if len(v) > 1 else 0
        row += f"{st.mean(v):>15.3f} ± {se:<6.3f}" if v else f"{'n/a':>24}"
    if len(arms) == 2:
        seeds = sorted(set(data[0]) & set(data[1]))
        dd = [data[1][s][k] - data[0][s][k] for s in seeds if not (math.isnan(data[0][s][k]) or math.isnan(data[1][s][k]))]
        if len(dd) > 1:
            se = st.stdev(dd) / math.sqrt(len(dd)); mu = st.mean(dd)
            row += f"{mu:>10.3f}{(mu / se if se else float('inf')):>7.1f}"
    print(row)

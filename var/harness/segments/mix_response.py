#!/usr/bin/env python3
"""mix_response.py [seeds a-b] [A] [B]: does the tilt move the mix? Per report, the segment's revenue share in B minus
in A on the same seed and report, against the log tilt B filed the report before. Prints the table only."""
import json, math, os, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import mean_se

H = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'runs')
lo, hi = (sys.argv[1] if len(sys.argv) > 1 else '1-16').split('-')
seeds = range(int(lo), int(hi) + 1)
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('head', 'seg')
T = ['SKYL', 'TERN', 'ERNE', 'WEAV', 'JDAW', 'BIRD', 'RAVN', 'OWLS', 'TRIV']
STREAM = {'satellite_internet': 'satellite_broadband', 'air_cargo': 'air_cargo', 'consumer_devices': 'consumer_terminals',
          'ads_and_cloud': 'digital_ads_cloud', 'merchant_power': 'unregulated_merchant', 'terminals': 'defensive_staples',
          'industrial': 'industrial_manufacturing', 'consumer': 'defensive_staples'}


def corr(x, y):
    if len(x) < 4:
        return float('nan')
    mx, my = st.fmean(x), st.fmean(y)
    sx, sy = math.sqrt(sum((a - mx) ** 2 for a in x)), math.sqrt(sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / (sx * sy) if sx > 0 and sy > 0 else float('nan')


data = {s: {a: json.load(open(f'{H}/{a}-{s}.seg.json'))['rows'] for a in (A, B)} for s in seeds}
print(f'Mix response, {B} vs {A}, same seed and report: share_{B} - share_{A} against the log tilt {B} filed the report before')
print('ticker:segment | corr per seed, mean (se) | pooled slope d share / log tilt | mean |log tilt| | mean share ' + A)
for tk in T:
    rows = {s: {a: [r for r in data[s][a] if r['tk'] == tk] for a in (A, B)} for s in seeds}
    keys = [k for k in rows[seeds[0]][B][0]['beliefs'] if k != 'main']
    for k in keys:
        cs, X, Y, sh = [], [], [], []
        for s in seeds:
            a, b = rows[s][A], rows[s][B]
            x, y = [], []
            for i in range(2, min(len(a), len(b))):
                ra, rb = sum(a[i]['segrev'].values()), sum(b[i]['segrev'].values())
                tilt = b[i - 1]['tilts'].get(STREAM[k])
                if ra <= 0 or rb <= 0 or not tilt:
                    continue
                x.append(math.log(tilt))
                y.append(b[i]['segrev'].get(k, 0) / rb - a[i]['segrev'].get(k, 0) / ra)
                sh.append(a[i]['segrev'].get(k, 0) / ra)
            c = corr(x, y)
            if not math.isnan(c):
                cs.append(c)
            X += x
            Y += y
        n, m, se = mean_se(cs)
        mx, my = st.fmean(X), st.fmean(Y)
        sl = sum((p - mx) * (q - my) for p, q in zip(X, Y)) / sum((p - mx) ** 2 for p in X)
        print(f'{tk}:{k} | {m:+.2f} ({se:.2f}) | {sl:+.3f} | {st.fmean(abs(v) for v in X):.3f} | {st.fmean(sh):.3f}')

"""Cumulative purchases multiplier from GovtImpulseProbeTest: python3 var/harness/govt_irf_an.py out.json [more.json ...]

Ramey & Zubairy (2018) multiplier: sum over h quarters of dY/Y over sum of dG/Y, the shocked run minus the base run on
the same draws. Purchases are TAX (0.21) of GDP at the baseline index, so dG/Y = TAX * d(index)/100.
US targets (NBER w20719, Table 1-2, linear model): news shock 2y 0.76 (0.10), 4y 0.84 (0.09);
Blanchard-Perotti shock 2y 0.54, 4y 0.78. The engine has no anticipation, so Blanchard-Perotti is its analogue.
The pooled ratio of means is reported with a seed-bootstrap standard error.
"""
import json, random, statistics as st, sys

TAX = 0.21
files = sys.argv[1:]
seeds = []
for f in files:
    d = json.load(open(f))
    for s, r in d['runs'].items():
        if 'base' in r and 'shock' in r:
            dy = [b['gap'] - a['gap'] for a, b in zip(r['base'], r['shock'])]
            dg = [TAX * (b['gov'] - a['gov']) / 100.0 for a, b in zip(r['base'], r['shock'])]
            dp = [100 * (b['policy'] - a['policy']) for a, b in zip(r['base'], r['shock'])]
            seeds.append((dy, dg, dp))
n = len(seeds)
H = len(seeds[0][0])
print(f"{n} seeds, {H} quarters")


def mult(sample, h):
    y = sum(sum(s[0][:h]) for s in sample)
    g = sum(sum(s[1][:h]) for s in sample)
    return y / g


random.seed(1)
boots = [[random.choice(seeds) for _ in range(n)] for _ in range(300)]
print(" h   cum mult  (se)    gap resp pp  dG %GDP  policy pp")
for h in range(1, H + 1):
    m = mult(seeds, h)
    se = st.pstdev(mult(b, h) for b in boots)
    gy = 100 * st.mean(s[0][h - 1] for s in seeds)
    gg = 100 * st.mean(s[1][h - 1] for s in seeds)
    gp = st.mean(s[2][h - 1] for s in seeds)
    tag = {8: '  <- 2y (BP 0.54, news 0.76 +- 0.10)', 16: '  <- 4y (BP 0.78, news 0.84 +- 0.09)'}.get(h, '')
    print(f"{h:2d}   {m:6.3f}  ({se:.3f})   {gy:+7.3f}    {gg:6.3f}   {gp:+6.1f}{tag}")

# Channel decomposition: the cumulative gap response split by drift channel (probe windows are the quarter's
# integrated drift), shocked minus base, averaged over seeds.
chans = {}
for f in files:
    d = json.load(open(f))
    for s, r in d['runs'].items():
        if 'c' not in r['base'][0]:
            continue
        for q, (a, b) in enumerate(zip(r['base'], r['shock'])):
            for k in b['c']:
                chans.setdefault(k, [[] for _ in range(H)])[q].append(b['c'][k] - a['c'].get(k, 0.0))
if chans:
    print("\ncumulative gap response by channel, pp (shock - base), at quarters 4 / 8 / 12 / 16 / 20")
    rows = []
    for k, qs in chans.items():
        cum, acc = [], 0.0
        for q in range(H):
            acc += 100 * st.mean(qs[q])
            cum.append(acc)
        rows.append((k, cum))
    rows.sort(key=lambda r: -abs(r[1][15]))
    for k, cum in rows:
        if max(abs(v) for v in cum) > 0.002:
            print(f"  {k:28s} " + ' '.join(f"{cum[h - 1]:+7.3f}" for h in (4, 8, 12, 16, 20)))
    tax = [st.mean(100 * (b['tax'] - a['tax']) for a, b in ((r['base'][q], r['shock'][q]) for d2 in [json.load(open(f)) for f in files] for r in d2['runs'].values())) for q in (3, 7, 11, 15, 19)]
    print("  tax rate response pp          " + ' '.join(f"{v:+7.3f}" for v in tax))

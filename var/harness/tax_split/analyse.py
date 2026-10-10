#!/usr/bin/env python3
"""analyse.py [seeds]: paired NEW-OLD table from runs/<arm>-<seed>.macro.jsonl (quarterly MACRO_OUT rows, BOARD_NI=1)."""
import json, math, os, sys
from statistics import mean, pstdev

H = os.path.dirname(os.path.abspath(__file__))
BURN = 1.0  # years dropped at the start


def rows(arm, seed):
    p = f"{H}/runs/{arm}-{seed}.macro.jsonl"
    if not os.path.exists(p):
        return None
    return [r for r in (json.loads(l) for l in open(p)) if r["t"] > BURN - 1e-9]


def ols(x, y):
    mx, my = mean(x), mean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    sxy = sum((a - mx) * (b - my) for a, b in zip(x, y))
    syy = sum((b - my) ** 2 for b in y)
    return sxy / sxx, sxy / math.sqrt(sxx * syy)


def stats(r):
    gap = [x["gap"] for x in r]
    g, gx = [], []
    for a, b in zip(r, r[4:]):
        if a["board_n"] == b["board_n"] and a["board_tni"] > 0:
            g.append(b["board_tni"] / a["board_tni"] - 1.0)
            gx.append(b["gap"])
    beta, corr = ols(gx, g)
    lf = [math.log(x["float"]) for x in r if x["float"] and x["float"] > 0]
    lc = [math.log(x["cap"]) for x in r if x["cap"] and x["cap"] > 0]
    dl = lambda v: [b - a for a, b in zip(v, v[1:])]
    return {
        "gap mean": mean(gap), "gap sd": pstdev(gap), "debt mean": mean(x["debt"] for x in r),
        "corp rate sd": pstdev(x["tax_rate"] for x in r), "corp rate mean": mean(x["tax_rate"] for x in r),
        "net tax sd": pstdev(x["net_tax"] for x in r),
        "NI yoy beta": beta, "NI yoy corr": corr, "NI yoy sd": pstdev(g),
        "float vol ann": pstdev(dl(lf)) * 2.0, "cap vol ann": pstdev(dl(lc)) * 2.0,
    }


seeds = sys.argv[1] if len(sys.argv) > 1 else "1-16"
lo, _, hi = seeds.partition("-")
S = range(int(lo), int(hi or lo) + 1)
per = {}
for s in S:
    a, b = rows("new", s), rows("old", s)
    if a and b:
        per[s] = (stats(a), stats(b))
n = len(per)
yrs = max(x["t"] for x in rows("new", next(iter(per))))
print(f"n={n} paired seeds, {yrs:.0f} years, first {BURN:.0f}y dropped")
print(f"{'quantity':16s} {'NEW':>9s} {'OLD':>9s} {'NEW-OLD':>9s} {'se':>8s} {'t':>6s}  range(diff)")
for k in per[next(iter(per))][0]:
    a = [per[s][0][k] for s in per]
    b = [per[s][1][k] for s in per]
    d = [x - y for x, y in zip(a, b)]
    se = pstdev(d) * math.sqrt(n / (n - 1)) / math.sqrt(n)
    t = mean(d) / se if se > 0 else float("nan")
    print(f"{k:16s} {mean(a):9.4f} {mean(b):9.4f} {mean(d):+9.4f} {se:8.4f} {t:6.2f}  [{min(d):+.4f}, {max(d):+.4f}]")

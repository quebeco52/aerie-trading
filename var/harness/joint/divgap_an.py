#!/usr/bin/env python3
"""divgap_an.py <seeds> <arm>...: report-tick log return regressed on the dividend yield paid at the report (slope ~ -1
when fair value loses the full dividend), plus total buybacks and dividends per arm."""
import csv, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
a, _, b = sys.argv[1].partition('-')
seeds = list(range(int(a), int(b or a) + 1))


def one(arm, s):
    out = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())
    tpy = out['tpy']
    with open(f"{H}/runs/{arm}-{s}.ticks.csv") as f:
        rd = csv.reader(f)
        hdr = next(rd)
        tk = [h[:-2] for h in hdr[1::2]]
        rows = [list(map(float, r)) for r in rd]
    col = {t: i for i, t in enumerate(tk)}
    pts, bb, div = [], 0.0, 0.0
    for t, v in out['final'].items():
        if t not in col:
            continue
        i = col[t]
        for r in v['q']:
            bb += r.get('bb', 0.0)
            div += r.get('div', 0.0)
            k = int(round(r['t'] * tpy))
            if k < 1 or k >= len(rows):
                continue
            p0, p1 = rows[k - 1][1 + 2 * i], rows[k][1 + 2 * i]
            sh0, sh1 = rows[k - 1][2 + 2 * i], rows[k][2 + 2 * i]
            if p0 <= 0 or p1 <= 0 or sh0 <= 0 or abs(sh1 / sh0 - 1) > 0.2 or r.get('div', 0.0) <= 0:
                continue
            y = r['div'] / sh1 / p0
            if y > 0.2:
                continue
            pts.append((y, math.log(p1 / p0)))
    return pts, bb, div


for arm in sys.argv[2:]:
    slopes, bbs, divs = [], [], []
    for s in seeds:
        pts, bb, div = one(arm, s)
        if len(pts) > 20:
            mx = st.mean(p[0] for p in pts); my = st.mean(p[1] for p in pts)
            sxx = sum((p[0] - mx) ** 2 for p in pts)
            slopes.append(sum((p[0] - mx) * (p[1] - my) for p in pts) / sxx if sxx > 0 else float('nan'))
        bbs.append(bb / 1e9); divs.append(div / 1e9)
    se = st.stdev(slopes) / math.sqrt(len(slopes)) if len(slopes) > 1 else float('nan')
    print(f"{arm}: report-tick return on dividend yield, slope mean {st.mean(slopes):+.2f} (se {se:.2f}, n {len(slopes)}); "
          f"buybacks {st.mean(bbs):.0f}B/seed (sd {st.pstdev(bbs):.0f}); dividends {st.mean(divs):.0f}B/seed (sd {st.pstdev(divs):.0f})")

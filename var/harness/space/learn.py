#!/usr/bin/env python3
"""SKYL market-size learning: per seed true M, the estimate path and belief sd, outcomes and their rank against true
M; price reaction to estimate revisions; launch-revenue dip when the estimate jumps. Usage: learn.py [arm]."""
import json, math, statistics as st, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import io, contextlib
with contextlib.redirect_stdout(io.StringIO()):
    import per_seed as ps

arm = sys.argv[1] if len(sys.argv) > 1 else 'learn'
P = Path(__file__).parent
files = sorted(P.glob(f'runs/{arm}-s[0-9]*.json'), key=lambda p: int(p.stem.split('-s')[-1]))
ORDER = ps.ORDER


def rank(xs):
    o = sorted(range(len(xs)), key=lambda i: xs[i])
    r = [0.0] * len(xs)
    for k, i in enumerate(o):
        r[i] = k
    return r


def spearman(a, b):
    return st.correlation(rank(a), rank(b))


rows, dx, xr, xr5, dl = [], [], [], [], []
print(f'{"seed":>4s} {"M/2":>5s}  estimate at y4 y6 y8 y10 y14 y20        {"sd10":>5s} {"sd20":>5s} {"real/des":>8s} {"cap x":>6s} {"worst":>5s} {"loss q":>6s} {"bb share max":>12s}')
for f in files:
    d = json.load(open(f))
    q, daily = d['q']['SKYL'], d['daily']
    s = d['seed']
    ny = len(q) // 4
    tpy = round(len(daily) / ny)
    st_ = [r['regime'] for r in q]
    M = next((x['state:broadband_market_size'] for x in st_ if 'state:broadband_market_size' in x), float('nan')) / 2.0
    est = [r['kpi'].get('broadband_market_estimate', float('nan')) for r in q]
    sd = [math.sqrt(x.get('state:broadband_market_log_variance', float('nan'))) for x in st_]
    pr = ps.seed_row(arm, s)
    capx = q[-1]['cap'] / d['cap1']['SKYL']
    bbmax = max(r['streams']['satellite_broadband'] / r['rev'] for r in q)
    rows.append((M, pr['real/des y20'], capx))
    pick = lambda xs, y: xs[4 * y - 1]
    print(f'{s:4d} {M:5.2f}  ' + ' '.join(f'{pick(est, y):.2f}' for y in (4, 6, 8, 10, 14, 20))
          + f'   {pick(sd, 10):5.3f} {pick(sd, 20):5.3f} {pr["real/des y20"]:8.2f} {capx:6.1f} {pr["worst"]:>5s} {pr["loss q"]:6d} {bbmax:12.2f}')
    # Revision response: log change in the estimate between reports against the SKYL return over the board, over the
    # whole quarter and over the report day and the 4 days after it; the launch line's log change beside it.
    for i in range(1, len(q)):
        if not (est[i] > 0 and est[i - 1] > 0):
            continue
        dle = math.log(est[i] / est[i - 1])
        k0, k1 = round(q[i - 1]['t'] * tpy) - 1, min(len(daily) - 1, round(q[i]['t'] * tpy) - 1)
        r0, r5 = max(0, k1 - 1), min(len(daily) - 1, k1 + 5)
        if k0 < 0:
            continue
        dx.append(dle)
        xr.append(math.log(daily[k1]['SKYL'] / daily[k0]['SKYL']) - math.log(daily[k1]['i'] / daily[k0]['i']))
        xr5.append(math.log(daily[r5]['SKYL'] / daily[r0]['SKYL']) - math.log(daily[r5]['i'] / daily[r0]['i']))
        dl.append(math.log(q[i]['streams']['launch_services'] / q[i - 1]['streams']['launch_services'])
                  if q[i - 1]['streams']['launch_services'] > 0 and q[i]['streams']['launch_services'] > 0 else float('nan'))


def slope(x, y):
    mx, my = st.fmean(x), st.fmean(y)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sum((a - mx) ** 2 for a in x)


Ms, rd, cx = zip(*rows)
print(f'\nSpearman(true M, real/designed y20) {spearman(Ms, rd):+.2f}; Spearman(true M, cap x y20) {spearman(Ms, cx):+.2f}; n {len(rows)}')
print(f'real/designed y20 mean {st.fmean(rd):.2f} range {min(rd):.2f}-{max(rd):.2f}; cap x y20 mean {st.fmean(cx):.1f} range {min(cx):.1f}-{max(cx):.1f}')
print(f'revisions: n {len(dx)}, sd of quarterly d log estimate {st.pstdev(dx):.3f}, max {max(dx):+.3f}, min {min(dx):+.3f}')
print(f'  quarter excess return: corr {st.correlation(dx, xr):+.2f}, slope {slope(dx, xr):+.2f}')
print(f'  report day..+5d excess return: corr {st.correlation(dx, xr5):+.2f}, slope {slope(dx, xr5):+.2f}')
pairs = [(a, b) for a, b in zip(dx, dl) if b == b]
print(f'  launch revenue qoq log change: corr {st.correlation(*zip(*pairs)):+.2f}, slope {slope(*zip(*pairs)):+.2f}; '
      f'in the 5%% largest upward revisions mean {st.fmean(b for a, b in sorted(pairs)[-max(1, len(pairs) // 20):]):+.3f} vs all {st.fmean(b for a, b in pairs):+.3f}')

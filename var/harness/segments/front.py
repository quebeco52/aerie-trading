#!/usr/bin/env python3
"""front.py <seeds> ARM: front-running check for market entries. For each entry (ventures rows with 'entry'), the
entrant's split-adjusted log price return from its report at entry to 8 reports later, less the equal-weighted mean of
its sector's other firms over the same dates (each at its report nearest those dates); placebo = same firm, the
report one year (4 reports) before. Sectors from src/Data/Company/InitialMarket.php. Prints the final table only.
"""
import json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
P = os.path.join(H, '..', '..', '..', 'src', 'Data', 'Company', 'InitialMarket.php')
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
ARM = sys.argv[2]
src = open(P).read()
SECTOR = {}
for m in re.finditer(r"'ticker' => '([A-Z]+)'(.*?)'sector' => '([^']+)'", src, re.S):
    SECTOR[m.group(1)] = m.group(3)


def adjusted(q):
    """[(t, split-adjusted price)] from a firm's quarter log."""
    out, f = [], 1.0
    for i, x in enumerate(q):
        if i:
            r = x['shares'] / q[i - 1]['shares'] if q[i - 1]['shares'] else 1.0
            if (r >= 1.5 and abs(r - round(r)) < 1e-6) or (0 < r <= 0.67 and abs(1 / r - round(1 / r)) < 1e-6):
                f *= r
        out.append((x['t'], x['price'] * f))
    return out


def at(series, t):
    best = min(series, key=lambda p: abs(p[0] - t)) if series else None
    return best[1] if best and abs(best[0] - t) < 0.2 else None


def excess(fin, tk, t0, t1):
    s = adjusted(fin[tk]['q'])
    a, b = at(s, t0), at(s, t1)
    if not a or not b or a <= 0 or b <= 0:
        return None
    peers = []
    for p, v in fin.items():
        if p == tk or SECTOR.get(p) != SECTOR.get(tk) or v['dead']:
            continue
        ps = adjusted(v['q'])
        x, y = at(ps, t0), at(ps, t1)
        if x and y and x > 0 and y > 0:
            peers.append(math.log(y / x))
    return math.log(b / a) - st.fmean(peers) if peers else None


ev, pl = [], []
for s in seeds:
    fin = json.loads(open(f'{H}/runs/{ARM}-{s}.jsonl').read().splitlines()[-1])['final']
    for row in json.load(open(f'{H}/runs/{ARM}-{s}.seg.json'))['ventures']:
        if not row['entry'] or row['tk'] not in fin:
            continue
        t0 = row['t']
        e = excess(fin, row['tk'], t0, t0 + 2.0)
        if e is not None:
            ev.append(e)
        if t0 - 1.0 > 0:
            p = excess(fin, row['tk'], t0 - 1.0, t0 + 1.0)
            if p is not None:
                pl.append(p)
ms = lambda xs: (st.fmean(xs), st.stdev(xs) / math.sqrt(len(xs)), len(xs)) if len(xs) > 1 else (float('nan'),) * 3
m, se, n = ms(ev)
mp, sep, npl = ms(pl)
print(f'{ARM}: entrant excess log return over its sector, reports 0-8 from entry: mean {m:+.4f} (se {se:.4f}, n {n}); '
      f'placebo (one year earlier): {mp:+.4f} (se {sep:.4f}, n {npl}); difference {m - mp:+.4f} (se {math.hypot(se, sep):.4f})')

"""The opening and the first years: payers raising in years 1-3 (declared DPS, Q4 on Q4) with their median raise, and
the opening price, yield and board cap, paired across arms.
Usage (from this directory): python3 opening.py <arm_a> <arm_b> [...]   (paired against the first arm)
"""
import os
import statistics as st
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from payout import load, dps_adj  # noqa: E402
from stats import paired, fmt  # noqa: E402


def measures(run):
    out = {}
    first = {tk: f['q'][0] for tk, f in run['final'].items() if f['q']}
    for y in (1, 2, 3):
        up, n, g = 0, 0, []
        for tk, f in run['final'].items():
            rows = f['q']
            adj, _ = dps_adj(rows)
            i0 = max((i for i, r in enumerate(rows) if r['t'] <= y - 1 + 1e-6), default=0 if y == 1 else None)
            i1 = max((i for i, r in enumerate(rows) if r['t'] <= y + 1e-6), default=None)
            if i0 is None or i1 is None or adj[i0] <= 0 or adj[i1] <= 0:
                continue
            n += 1
            if adj[i1] > adj[i0] * (1 + 1e-4):
                up += 1
                g.append(adj[i1] / adj[i0] - 1)
        out[f'raising y{y}'] = up / max(1, n)
        out[f'median raise y{y}'] = st.median(g) if g else 0.0
    yields = [4 * r['dps'] / r['price'] for r in first.values() if (r.get('dps') or 0) > 0 and r['price'] > 0]
    out['median opening yield (payers)'] = st.median(yields)
    out['opening board cap ($tn)'] = sum(r['price'] * r['shares'] for r in first.values()) / 1e12
    out['opening cap / GDP'] = run['macro'][0][2] / (run['gdp_usd'] * run['macro'][0][1]) if run.get('macro') else 0.0
    return out


def main():
    arms = sys.argv[1:]
    data = {a: {s: measures(r) for s, r in load(a).items()} for a in arms}
    print(f'{"":32}' + ''.join(f'{a:>10}' for a in arms) + ''.join(f'   {b}-{arms[0]} (se) t n' for b in arms[1:]))
    for k in data[arms[0]][min(data[arms[0]])]:
        line = f'  {k:30}' + ''.join(f'{st.mean(d[s][k] for s in d):10.3f}' for d in data.values())
        for b in arms[1:]:
            line += '   ' + fmt(paired({s: data[arms[0]][s][k] for s in data[arms[0]]}, {s: data[b][s][k] for s in data[b]}))
        print(line)


if __name__ == '__main__':
    main()

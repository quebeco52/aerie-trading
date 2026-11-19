"""Dividend stickiness per run, paired across arms: payer firm-years that raise, hold flat or fall (Q4-on-Q4 DPS,
split-adjusted), the mean raise among raisers, the roll share of payers at y20, the token share of streak years and the
median cash / total assets of payers (firm-years).
Usage (from this directory): python3 sticky.py <arm_a> <arm_b> [<arm_c>]   (paired b-a and c-b)
"""
import os
import statistics as st
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from payout import load, dps_adj  # noqa: E402
from pay2 import roll, TIERS  # noqa: E402
from stats import paired, fmt  # noqa: E402

FLAT = 1e-4


def measures(run):
    years = run['years']
    up = flat = down = 0
    raises, cash = [], []
    for tk, f in run['final'].items():
        rows = f['q']
        adj, _ = dps_adj(rows)
        for y in range(2, years + 1):
            i0 = max((i for i, r in enumerate(rows) if r['t'] <= y - 1 + 1e-6), default=None)
            i1 = max((i for i, r in enumerate(rows) if r['t'] <= y + 1e-6), default=None)
            if i0 is None or i1 is None or i1 - i0 < 3 or adj[i0] <= 0 or adj[i1] <= 0:
                continue
            g = adj[i1] / adj[i0] - 1.0
            if g > FLAT:
                up += 1
                raises.append(g)
            elif g < -FLAT:
                down += 1
            else:
                flat += 1
            if rows[i1]['ta'] > 0 and not rows[i1]['fin']:
                cash.append(rows[i1]['cash'] / rows[i1]['ta'])
    n = max(1, up + flat + down)
    tiers, o, _, _ = roll(run)
    return {
        'raise share': up / n, 'flat share': flat / n, 'fall share': down / n,
        'mean raise of raisers': st.mean(raises) if raises else 0.0,
        'mean raise, capped at 50%': st.mean(min(x, 0.5) for x in raises) if raises else 0.0,
        'median raise of raisers': st.median(raises) if raises else 0.0,
        'roll % payers y20': sum(tiers[20][t] for t, _ in TIERS) / tiers[20]['payers'],
        'token share of streak yrs': o['token raises/run'] / max(1, o['listed FY raises/run']),
        'median cash/assets (op. payers)': st.median(cash) if cash else 0.0,
    }


def main():
    arms = sys.argv[1:]
    data = {a: {s: measures(r) for s, r in load(a).items()} for a in arms}
    print(f'{"":34}' + ''.join(f'{a:>9}' for a in arms) + ''.join(f'   {b}-{a} (se) t n' for a, b in zip(arms, arms[1:])))
    for k in data[arms[0]][min(data[arms[0]])]:
        line = f'  {k:32}' + ''.join(f'{st.mean(d[s][k] for s in d):9.3f}' for d in data.values())
        for a, b in zip(arms, arms[1:]):
            line += '   ' + fmt(paired({s: data[a][s][k] for s in data[a]}, {s: data[b][s][k] for s in data[b]}))
        print(line)


if __name__ == '__main__':
    main()

"""Large DPS falls split by whether the firm opened as a champion (seeded streak >= 25), paired across arms.
Usage (from this directory): python3 cuts.py <ref_arm_with_st0> <arm>...   (the first arm supplies the opening streaks)
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from payout import load, dps_adj  # noqa: E402
from stats import paired, fmt  # noqa: E402


def falls(run, champs):
    out = {'champ10': 0, 'other10': 0, 'champ05': 0, 'other05': 0}
    for tk, f in run['final'].items():
        g = 'champ' if tk in champs else 'other'
        adj, _ = dps_adj(f['q'])
        for a, b in zip(adj, adj[1:]):
            if a <= 0:
                continue
            fall = b / a - 1.0
            out[g + '05'] += fall < -0.005
            out[g + '10'] += fall < -0.10
    return out


def main():
    ref = load(sys.argv[1])
    arms = sys.argv[2:]
    champs = {s: {tk for tk, m in r['meta'].items() if (m.get('st0') or 0) >= 25} for s, r in ref.items()}
    print('opening champions per seed: ' + ', '.join(f's{s} {len(c)}' for s, c in champs.items()))
    print('  ' + ' '.join(sorted(champs[min(champs)])))
    data = {a: {s: falls(r, champs[s]) for s, r in load(a).items() if s in champs} for a in arms}
    for k in ('champ10', 'other10', 'champ05', 'other05'):
        line = f'  {k:8}' + ''.join(f' {a} {sum(d[s][k] for s in d) / len(d):7.2f}' for a, d in data.items())
        base = data[arms[0]]
        for a in arms[1:]:
            line += f' | {a}-{arms[0]} ' + fmt(paired({s: base[s][k] for s in base}, {s: data[a][s][k] for s in data[a]}), 2)
        print(line)


if __name__ == '__main__':
    main()

"""Year-by-year paths for the new firms against peers: is a drift the new model's, or the engine's for every firm?

Usage: python3 -I var/harness/new_firms/paths.py SEED [SEED...]
"""
import json, os, statistics as st, sys

HERE = os.path.dirname(os.path.abspath(__file__))


def load(seed):
    with open(os.path.join(HERE, 'runs', f"{os.environ.get('ARM', 'live')}-{seed}.jsonl")) as f:
        return json.loads(f.readline())['final']


def yearly(q, key):
    """Mean of a quarterly field per simulated year (rows carry t in years)."""
    by = {}
    for row in q:
        v = row.get(key)
        if isinstance(v, (int, float)):
            by.setdefault(int(row['t']), []).append(float(v))
    return {y: st.mean(v) for y, v in sorted(by.items())}


for seed in sys.argv[1:]:
    final = load(seed)
    print(f'=== seed {seed}')
    for t in ['BOWR', 'FNCH', 'CNDR']:
        q = final[t]['q']
        rev, om, sh, eq = yearly(q, 'rev'), yearly(q, 'om'), yearly(q, 'shares'), yearly(q, 'eq')
        bb, ni, hsm = yearly(q, 'bb'), yearly(q, 'ni'), yearly(q, 'hsm')
        exp = yearly(q, 'exp')
        ys = [y for y in (0, 4, 8, 12, 16, 19) if y in rev]
        print(f'{t}: year ' + ' '.join(f'{y:>7d}' for y in ys))
        print('   rev/y0  ' + ' '.join(f'{rev[y] / rev[0]:7.2f}' for y in ys))
        print('   exp/y0  ' + ' '.join(f'{exp[y] / exp[0]:7.2f}' for y in ys))
        print('   om      ' + ' '.join(f'{om[y]:7.3f}' for y in ys))
        if hsm:
            print('   hsm     ' + ' '.join(f'{hsm.get(y, float("nan")):7.3f}' for y in ys))
        print('   sh/y0   ' + ' '.join(f'{sh[y] / sh[0]:7.2f}' for y in ys))
        print('   bb/ni   ' + ' '.join(f'{(bb[y] / ni[y]) if ni[y] else float("nan"):7.2f}' for y in ys))
    gold, metals = yearly(final['FNCH']['q'], 'gold'), yearly(final['FNCH']['q'], 'metals')
    print('   gold    ' + ' '.join(f'{gold[y]:7.1f}' for y in ys))
    print('   metals  ' + ' '.join(f'{metals[y]:7.1f}' for y in ys))

    # Board spread: share count and expected revenue at year 16 over year 0, every live non-financial firm.
    shr, exr = [], []
    for t, f in final.items():
        q = f.get('q') or []
        if f.get('dead') or not q or q[0].get('fin'):
            continue
        sh, ex = yearly(q, 'shares'), yearly(q, 'exp')
        if 0 in sh and 16 in sh and sh[0] and ex.get(0):
            shr.append((sh[16] / sh[0], t))
            exr.append((ex[16] / ex[0], t))
    shr.sort(); exr.sort()
    print(f'board shares y16/y0: min {shr[0]} p25 {shr[len(shr) // 4][0]:.2f} median {shr[len(shr) // 2][0]:.2f} max {shr[-1]}')
    print('   lowest five: ' + ', '.join(f'{t} {v:.2f}' for v, t in shr[:5]))
    print(f'board expected revenue y16/y0: median {exr[len(exr) // 2][0]:.2f}; lowest five: ' + ', '.join(f'{t} {v:.2f}' for v, t in exr[:5]))

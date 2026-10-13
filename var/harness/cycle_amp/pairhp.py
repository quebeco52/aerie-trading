"""Paired HP-cycle comparison: B minus A per seed, targets then guards.
Usage: python3 pairhp.py A B. Env SEEDS (1-16), BURN (5), YEARS (45), LAM (6.25).
Annual level = mean of the year's 4 quarters of pot x (1 + gap), years BURN..YEARS-1 (t floored), log, HP filter."""
import json, math, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from hplib import cyc

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
BURN = int(os.environ.get('BURN', 5)); YEARS = int(os.environ.get('YEARS', 45)); LAM = float(os.environ.get('LAM', 6.25))
ELB = 0.0011


def seeds():
    out = []
    for part in os.environ.get('SEEDS', '1-16').split(','):
        a, _, b = part.partition('-'); out += list(range(int(a), int(b or a) + 1))
    return out


def mean(x): return sum(x) / len(x)
def sd(x):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / (len(x) - 1))


def metrics(arm, s):
    p = f'{H}/runs/{arm}-{s}.macro.jsonl'
    if not os.path.exists(p): return None
    rows = [json.loads(l) for l in open(p)]
    if not rows or rows[-1]['t'] < YEARS - 1e-6: return None
    byy = {}
    for r in rows:
        y = int(r['t'])
        if BURN <= y < YEARS: byy.setdefault(y, []).append(r['pot'] * (1 + r['gap']))
    lv = [mean(v) for k, v in sorted(byy.items()) if len(v) >= 4]
    hsd, hmin, _ = cyc(lv, LAM)
    lvl = {}
    for r in rows: lvl.setdefault(math.ceil(r['t'] - 1e-9), []).append(r['pot'] * (1 + r['gap']))
    gv = [100 * (mean(lvl[y]) / mean(lvl[y - 1]) - 1) for y in range(BURN + 1, YEARS + 1)]
    q = [r for r in rows if r['t'] > BURN + 1e-9]
    ua = [100 * mean([r['u'] for r in rows if math.ceil(r['t'] - 1e-9) == y]) for y in range(BURN + 1, YEARS + 1)]
    return {
        f'HP{LAM:g} cycle sd %': hsd, f'HP{LAM:g} cycle min %': hmin,
        'negative years /40': 40 / (YEARS - BURN) * sum(v < 0 for v in gv),
        'gap sd % (quarterly)': 100 * sd([r['gap'] for r in q]),
        'u annual sd pp': sd(ua),
        'policy at floor share': mean([r['policy'] <= ELB for r in q]),
        'inflation mean %': 100 * mean([r['infl'] for r in q]),
        'nx sd %': 100 * sd([r['nx'] for r in q]),
        'mainland gap sd %': 100 * sd([r['fgap'] for r in q]),
        'growth sd %': sd(gv),
    }


data = {s: (metrics(A, s), metrics(B, s)) for s in seeds()}
ok = {s: v for s, v in data.items() if v[0] and v[1]}
L = [f'{B} minus {A}, paired by seed, seeds ok {len(ok)}/{len(data)} (short: {[s for s in data if s not in ok] or "none"})',
     f'{"quantity":24s} {A:>8s} {B:>8s} {"diff":>8s} {"se":>7s} {"t":>6s} {"n":>3s}']
for i, k in enumerate(next(iter(ok.values()))[0]):
    if i == 0: L.append('-- targets (cycle min is a reported extra)')
    if k == 'u annual sd pp': L.append('-- guards')
    pa = [v[0][k] for v in ok.values()]; pb = [v[1][k] for v in ok.values()]
    d = [b - a for a, b in zip(pa, pb)]
    se = sd(d) / math.sqrt(len(d)); t = mean(d) / se if se > 0 else float('nan')
    L.append(f'{k:24s} {mean(pa):8.3f} {mean(pb):8.3f} {mean(d):8.3f} {se:7.3f} {t:6.2f} {len(d):3d}')
print('\n'.join(L))

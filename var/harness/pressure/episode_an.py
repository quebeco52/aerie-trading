# episode_an.py <run dir> [tree ...]: each arm of run.sh minus its tree's base arm, paired by seed, at the quarters the
# give-in constants were fitted on (MonetaryPolicySubsystem::PRESSURE_*: rate -30bp at 3q and inflation +0.5pp at 6q,
# Gavin & Manger 2023; price level +2.9% at 4y, Drechsel 2024 Fig. 7) plus the 10-year price level and the peaks.
# "avg" is the rand arm post-stratified on the episode length each seed drew (geometric, CONTINUATION 0.60), so the
# mean is over the length distribution itself rather than over the lengths these seeds happened to draw.
import glob, json, math, os, statistics as st, sys
from collections import defaultdict

D = sys.argv[1]; T0 = float(os.environ.get('STEP_AT', 10)); CONT = 0.60
trees = sys.argv[2:] or ['head', 'new']

def load(path):
    rows = {}
    for line in open(path):
        r = json.loads(line); rows[round(r['t'] - T0, 3)] = r
    return rows

runs = defaultdict(dict)
for f in glob.glob(f'{D}/*.jsonl'):
    arm, seed = os.path.basename(f)[:-6].rsplit('_', 1)
    runs[arm][int(seed)] = load(f)

def at(r, q):
    return r.get(round(q / 4, 3))

def diffs(a, b):
    """One seed's response: (rate@3q bp, infl@6q pp, price@16q %, price@40q %, peak b/e pp, trough rate bp, peak rate bp, peak gap drop pp, episode quarters)."""
    q3a, q3b, q6a, q6b = at(a, 3), at(b, 3), at(a, 6), at(b, 6)
    p = lambda q: 100 * (math.log(at(a, q)['defl']) - math.log(at(b, q)['defl'])) if at(a, q) and at(b, q) else float('nan')
    qs = [q for q in range(1, 49) if at(a, q) and at(b, q)]
    be = max(100 * (at(a, q)['be'] - at(b, q)['be']) for q in qs)
    rate = [1e4 * (at(a, q)['rate'] - at(b, q)['rate']) for q in qs]
    gap = min(100 * (at(a, q)['gap'] - at(b, q)['gap']) for q in qs)
    length = sum(1 for q in range(0, 48) if at(a, q) and at(a, q).get('conc', 0) > 0.5)
    return (1e4 * (q3a['rate'] - q3b['rate']), 100 * (q6a['infl'] - q6b['infl']), p(16), p(40), be, min(rate), max(rate), gap, length)

COLS = ['rate@3q', 'infl@6q', 'P@4y', 'P@10y', 'peakBE', 'minRate', 'maxRate', 'gapDrop', 'L']
FMT = ['%7.1f', '%7.3f', '%6.2f', '%6.2f', '%6.2f', '%7.0f', '%7.0f', '%7.2f', '%4.1f']

def show(label, rows):
    if not rows:
        return
    m = [st.mean(c) for c in zip(*rows)]
    se = [st.stdev(c) / math.sqrt(len(rows)) if len(rows) > 1 else float('nan') for c in zip(*rows)]
    print(f'{label:>12s} n={len(rows):3d} ' + ' '.join((FMT[i] % m[i]) + ' ±' + ('%.2f' % se[i]) for i in range(len(COLS))))

print('targets: rate@3q -30bp, infl@6q +0.50pp, P@4y +2.9% (G&M 2023; Drechsel 2024). Columns:', ' '.join(COLS))
for tree in trees:
    base = runs.get(f'{tree}-base')
    if not base:
        continue
    for arm in sorted(a for a in runs if a.startswith(tree + '-') and a != f'{tree}-base'):
        rows = [diffs(runs[arm][s], base[s]) for s in sorted(runs[arm]) if s in base]
        show(arm, rows)
        if arm.endswith('-rand'):
            by = defaultdict(list)
            for r in rows:
                by[min(int(r[-1]), 4)].append(r)
            weight = {L: (1 - CONT) * CONT ** (L - 1) for L in (1, 2, 3)}
            weight[4] = CONT ** 3
            if all(by.get(L) for L in weight):
                strat = [sum(weight[L] * st.mean(c[i] for c in by[L]) for L in weight) for i in range(len(COLS))]
                print(f'{tree + "-avg":>12s}       ' + ' '.join((FMT[i] % strat[i]) + '      ' for i in range(len(COLS))))
    # The fixed-length arms weighted by the length distribution: L7 midway between L6 and L8, the tail past 8 on L8.
    fixed = {int(a.split('-L')[1]): [diffs(runs[a][s], base[s]) for s in sorted(runs[a]) if s in base]
             for a in runs if a.startswith(tree + '-L')}
    if all(L in fixed for L in (1, 2, 3, 4, 5, 6, 8)):
        mean = {L: [st.mean(c) for c in zip(*rows)] for L, rows in fixed.items()}
        mean[7] = [(x + y) / 2 for x, y in zip(mean[6], mean[8])]
        w = {L: (1 - CONT) * CONT ** (L - 1) for L in range(1, 8)}
        w[8] = CONT ** 7
        avg = [sum(w[L] * mean[L][i] for L in w) for i in range(len(COLS))]
        print(f'{tree + "-wavg":>12s}       ' + ' '.join((FMT[i] % avg[i]) + '      ' for i in range(len(COLS))))
    off = runs.get(f'{tree}-off')
    if off:
        gap = max(abs(off[s][t]['rate'] - base[s][t]['rate']) for s in off if s in base for t in off[s] if t in base[s])
        print(f'{tree}: max |rate(off) - rate(base)| = {gap:.2e} (0: the baseline is the economy irf.sh fitted against)')

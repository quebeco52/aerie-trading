#!/usr/bin/env python3
"""Fixed debt target: python3 fixdebt_an.py runs/<stage> [from_year to_year]. Reads {new,head}/off_<seed>.jsonl
(gate.php off arm with FIXED_DEBT=<target>,<pace>). Per seed: first year debt reaches the target from above, the
furthest it falls below within 15 years after, and steady-state moments over the window (default years 30-60).
Prints mean over seeds per arm and the paired tree - HEAD mean and SE."""
import json, sys, glob, os, math, statistics as st

stage = sys.argv[1]
lo = float(sys.argv[2]) if len(sys.argv) > 2 else 30.0
hi = float(sys.argv[3]) if len(sys.argv) > 3 else 60.0


def metrics(path):
    rows = [json.loads(l) for l in open(path)]
    tgt = rows[-1]['dtgt']
    cross = next((r['t'] for r in rows if r['debt'] <= tgt), math.nan)
    if cross == cross:
        over = max(0.0, max(100 * (tgt - r['debt']) for r in rows if cross <= r['t'] <= cross + 15))
    else:
        over = math.nan
    w = [r for r in rows if lo < r['t'] <= hi]
    dev = [100 * (r['debt'] - tgt) for r in w]
    return {
        'first crossing yr': cross,
        'overshoot 15y pp': over,
        f'mean dev pp {lo:g}-{hi:g}': st.mean(dev),
        'mean |dev| pp': st.mean(abs(d) for d in dev),
        'sd dev pp': st.pstdev(dev),
        'share |dev|>2pp': sum(abs(d) > 2 for d in dev) / len(dev),
        'prim bal % (all)': st.mean(-100 * r['def'] for r in rows),
        'prim bal % (win)': st.mean(-100 * r['def'] for r in w),
        'gap sd % (win)': st.pstdev(100 * r['gap'] for r in w),
        'rate sd pp (win)': st.pstdev(100 * r['rate'] for r in w),
        'debt max % (all)': max(100 * r['debt'] for r in rows),
        'debt min % (all)': min(100 * r['debt'] for r in rows),
    }


arms = {}
for arm in ('head', 'new'):
    for f in sorted(glob.glob(os.path.join(stage, arm, 'off_*.jsonl'))):
        seed = int(f.rsplit('_', 1)[1].split('.')[0])
        arms.setdefault(arm, {})[seed] = metrics(f)
seeds = sorted(set(arms['head']) & set(arms['new']))
keys = list(arms['head'][seeds[0]].keys())
print(f"n={len(seeds)} seeds; window years {lo:g}-{hi:g}; mean over seeds")
print(f"{'quantity':24s} {'HEAD':>8s} {'tree':>8s} {'diff':>8s} {'se':>7s} {'t':>6s} {'tree range':>16s}")
for k in keys:
    h = [arms['head'][s][k] for s in seeds]
    n = [arms['new'][s][k] for s in seeds]
    d = [b - a for a, b in zip(h, n) if a == a and b == b]
    se = st.stdev(d) / len(d) ** 0.5 if len(d) > 1 else math.nan
    t = st.mean(d) / se if se and se == se else math.nan
    hm = st.mean([x for x in h if x == x]) if any(x == x for x in h) else math.nan
    nm = st.mean([x for x in n if x == x]) if any(x == x for x in n) else math.nan
    rng = f"{min(n):.2f}..{max(n):.2f}"
    print(f"{k:24s} {hm:8.3f} {nm:8.3f} {st.mean(d) if d else math.nan:8.3f} {se:7.3f} {t:6.2f} {rng:>16s}")

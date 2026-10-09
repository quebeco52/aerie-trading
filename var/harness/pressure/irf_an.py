# irf_an.py <run dir>: mean over paired seeds of concede minus off, by quarters after the episode begins (year 10).
# rate in bp, infl / anchor / breakeven in pp, price level (GDP deflator) and gap in %.
import json, sys, glob, statistics as st, math
D = sys.argv[1]; T0 = 10.0
def load(arm):
    rows = {}
    for f in glob.glob(f'{D}/{arm}_*.jsonl'):
        for line in open(f):
            r = json.loads(line); rows.setdefault(r['s'], {})[round(r['t'], 3)] = r
    return rows
off, on = load('off'), load('concede')
seeds = sorted(set(off) & set(on))
print('seeds', len(seeds))
print(f"{'qtrs':>5s} {'rate bp':>8s} {'se':>5s} {'infl pp':>8s} {'se':>5s} {'anchor':>7s} {'b/e pp':>7s} {'price %':>8s} {'gap pp':>7s}")
out = {}
for q in [1, 2, 3, 4, 5, 6, 8, 10, 12, 16, 20, 28, 40, 48]:
    t = round(T0 + q / 4, 3); d = []
    for s in seeds:
        a, b = on[s].get(t), off[s].get(t)
        if a is None or b is None:
            continue
        d.append((1e4 * (a['rate'] - b['rate']), 100 * (a['infl'] - b['infl']), 100 * (a['anchor'] - b['anchor']), 100 * (a['be'] - b['be']),
                  100 * (math.log(a['defl']) - math.log(b['defl'])), 100 * (a['gap'] - b['gap'])))
    if not d:
        continue
    m = [st.mean(c) for c in zip(*d)]; se = [st.pstdev(c) / math.sqrt(len(d)) for c in zip(*d)]
    out[q] = m
    print(f"{q:5d} {m[0]:8.1f} {se[0]:5.1f} {m[1]:8.3f} {se[1]:5.3f} {m[2]:7.3f} {m[3]:7.3f} {m[4]:8.3f} {m[5]:7.3f}")
print('FIT rate@3q %.1f bp (target -30), infl@6q %.3f pp (target +0.50)' % (out[3][0], out[6][1]))

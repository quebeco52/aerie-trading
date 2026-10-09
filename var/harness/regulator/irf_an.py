# irf_an.py <run dir> [step at]: mean over paired seeds of step minus off, by quarters after the step starts.
# gap in pp of output, hdti / house in % (log), sloos in net-tightening points, built in pp of RWA.
import json, sys, glob, statistics as st, math
D = sys.argv[1]; T0 = float(sys.argv[2]) if len(sys.argv) > 2 else 10.0
def load(arm):
    rows = {}
    for f in glob.glob(f'{D}/{arm}_*.jsonl'):
        for line in open(f):
            r = json.loads(line); rows.setdefault(r['s'], {})[round(r['t'], 3)] = r
    return rows
off, step = load('off'), load('step')
seeds = sorted(set(off) & set(step))
print('seeds', len(seeds))
print(f"{'qtrs':>5s} {'req':>6s} {'built':>6s} {'sloos':>7s} {'gap pp':>8s} {'se':>6s} {'hdti %':>7s} {'house %':>8s} {'rate bp':>8s}")
trough = (0, 0.0)
for q in [1, 2, 3, 4, 6, 8, 10, 12, 14, 16, 18, 20, 24, 28, 32, 36, 40, 44, 48]:
    t = round(T0 + q / 4, 3); d = []
    for s in seeds:
        a, b = step[s].get(t), off[s].get(t)
        if a is None or b is None:
            continue
        d.append((a['req'] - b['req'], a['built'] - b['built'], a['sloos'] - b['sloos'], 100 * (a['gap'] - b['gap']),
                  100 * (math.log(a['hdti']) - math.log(b['hdti'])), 100 * (math.log(a['house']) - math.log(b['house'])), 1e4 * (a['rate'] - b['rate'])))
    if not d:
        continue
    m = [st.mean(c) for c in zip(*d)]; se = st.pstdev([x[3] for x in d]) / math.sqrt(len(d))
    print(f"{q:5d} {m[0]*100:6.2f} {m[1]*100:6.3f} {m[2]:7.4f} {m[3]:8.3f} {se:6.3f} {m[4]:7.3f} {m[5]:8.3f} {m[6]:8.1f}")
    if m[3] < trough[1]:
        trough = (q, m[3])
print('trough %.3f pp at %d quarters' % (trough[1], trough[0]))

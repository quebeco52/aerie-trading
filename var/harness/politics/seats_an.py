"""The Diet on two founding seat sets, paired by seed: the formation and vote targets PoliticsEngineTest's calibration
check holds (testTheDietStaysBalancedAtItsCalibratedVolatility), single-party majorities, rumps, fragmentation, and the
normal-weighted variogram (walk_an.py's selection).
python3 seats_an.py 'formation/walk_new_*.jsonl' 'formation/seats_new_*.jsonl' [until-year]"""
import json, glob, sys, math, collections, statistics as st
MAJORITY = 151
UNTIL = float(sys.argv[3]) if len(sys.argv) > 3 else 1e9
TARGETS = {'retried': (0.26, 0.04), 'talk days': (30.0, 3.0), 'minority': (0.74, 0.06), 'single party': (0.48, 0.04),
           'largest in': (0.76, 0.04), 'two largest': (None, 0.05), 'one-party majority': (None, 0.05), 'pedersen': (13.3, 1.5)}

def load(pattern):
    by = collections.defaultdict(list)
    for f in glob.glob(pattern):
        for line in open(f):
            r = json.loads(line)
            if not r.get('fall') and r['t'] <= UNTIL:
                by[r['s']].append(r)
    return {s: sorted(v, key=lambda r: r['t']) for s, v in by.items()}

def stats(votes):
    n = len(votes); out = collections.Counter(); days = []; vol = []; eff = []; top2 = []
    for i, r in enumerate(votes):
        seats = r['seats']; cab = r['cabinet']; log = r.get('log') or []
        order = sorted(seats, key=seats.get, reverse=True)
        out['retried'] += len(log) > 1
        days.append(log[-1]['day'] if log else 0.0)
        out['minority'] += sum(seats[p] for p in cab) < MAJORITY
        out['single party'] += len(cab) == 1
        out['largest in'] += order[0] in cab
        out['two largest'] += order[0] in cab and order[1] in cab
        out['one-party majority'] += max(seats.values()) >= MAJORITY
        out['party under 1%'] += sum(1 for v in r['shares'].values() if v < 0.01)
        eff.append(1 / sum(v * v for v in r['shares'].values()))
        top2.append(seats[order[0]] + seats[order[1]])
        if i: vol.append(50 * sum(abs(r['shares'][p] - votes[i - 1]['shares'][p]) for p in seats))
    res = {k: out[k] / n for k in ('retried', 'minority', 'single party', 'largest in', 'two largest', 'one-party majority')}
    res['talk days'] = st.mean(days); res['pedersen'] = st.mean(vol); res['parties under 1% per vote'] = out['party under 1%'] / n
    res['effective parties'] = st.mean(eff); res['top two seats'] = st.mean(top2)
    # Variogram, as walk_an.py: parties averaging 5%, results of 1% or more, half mean squared log change times mean share.
    mean = {p: st.mean(v['shares'][p] for v in votes) for p in votes[0]['shares']}
    for g in (4, 20, 40):
        xs = [mean[p] * (math.log(b['shares'][p]) - math.log(a['shares'][p])) ** 2 / 2
              for i, a in enumerate(votes) for b in votes[i + 1:] if round(b['t'] - a['t']) == g
              for p in mean if mean[p] >= 0.05 and a['shares'][p] >= 0.01 and b['shares'][p] >= 0.01]
        res[f'variogram {g}y x1000'] = 1000 * st.mean(xs) if xs else float('nan')
    return res

old, new = load(sys.argv[1]), load(sys.argv[2])
seeds = sorted(set(old) & set(new))
so = {s: stats(old[s]) for s in seeds}; sn = {s: stats(new[s]) for s in seeds}
print(f"seeds {len(seeds)}, votes per seed {st.mean(len(new[s]) for s in seeds):.0f}")
for k in so[seeds[0]]:
    a = [so[s][k] for s in seeds]; b = [sn[s][k] for s in seeds]; d = [y - x for x, y in zip(a, b)]
    se = st.stdev(d) / math.sqrt(len(d))
    tgt = TARGETS.get(k)
    ref = '' if tgt is None else (f"   target < {tgt[1]}" if tgt[0] is None else f"   target {tgt[0]} ± {tgt[1]}")
    print(f"{k:>26}: old {st.mean(a):7.3f}  new {st.mean(b):7.3f}  diff {st.mean(d):+7.3f} (se {se:.3f}, t {st.mean(d) / se if se > 0 else 0:+.1f}){ref}")

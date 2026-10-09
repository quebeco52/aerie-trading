"""The Diet's vote variogram against ParlGov's (walk_fit.py), paired by seed: half the mean squared change in log share
between elections g years apart, times the party's mean share over the run, at the target gaps; and Pedersen volatility.
python3 walk_an.py 'formation/walk_base_*.jsonl' 'formation/walk_new_*.jsonl' [until year]"""
import json, glob, sys, math, collections, statistics as st
GAPS = (4, 8, 20, 40, 80)
WEST = {4: 7.3, 8: 13.3, 20: 20.3, 40: 32.0}
NORDIC = {4: 7.0, 8: 10.8, 20: 19.9, 40: 22.4}

def per_seed(pattern, until=1e9):
    rows = collections.defaultdict(list)
    for f in glob.glob(pattern):
        for line in open(f):
            r = json.loads(line)
            if not r.get('fall') and r['t'] <= until:
                rows[r['s']].append((r['t'], r['shares']))
    out = {}
    for seed, votes in rows.items():
        votes.sort()
        # As the ParlGov fit selects (vote_fit.py): parties averaging 5% of the vote over the span, results of 1% or more.
        mean = {p: st.mean(v[p] for _, v in votes) for p in votes[0][1]}
        parties = [p for p in mean if mean[p] >= 0.05]
        gaps = collections.defaultdict(list)
        for i, (ta, a) in enumerate(votes):
            for tb, b in votes[i + 1:]:
                g = round(tb - ta)
                if g in GAPS:
                    for p in parties:
                        if a[p] >= 0.01 and b[p] >= 0.01:
                            gaps[g].append(mean[p] * (math.log(b[p]) - math.log(a[p])) ** 2 / 2)
        vol = [50 * sum(abs(b[p] - a[p]) for p in a) for (_, a), (_, b) in zip(votes, votes[1:])]
        out[seed] = {**{g: 1000 * st.mean(v) for g, v in gaps.items()}, 'pedersen': st.mean(vol)}
    return out

UNTIL = float(sys.argv[3]) if len(sys.argv) > 3 else 1e9
base, new = per_seed(sys.argv[1], UNTIL), per_seed(sys.argv[2], UNTIL)
seeds = sorted(set(base) & set(new))
print(f"seeds {len(seeds)}")
for key in [k for k in list(GAPS) + ['pedersen'] if all(k in base[s] and k in new[s] for s in seeds)]:
    d = [new[s][key] - base[s][key] for s in seeds if key in base[s] and key in new[s]]
    b = st.mean(base[s][key] for s in seeds if key in base[s]); n = st.mean(new[s][key] for s in seeds if key in new[s])
    se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    ref = f"   West {WEST[key]:.1f}  Nordic {NORDIC[key]:.1f}" if key in WEST else ''
    print(f"{str(key):>9}: base {b:6.2f}  new {n:6.2f}  diff {st.mean(d):+6.2f} (se {se:.2f}, t {(st.mean(d) / se if se > 0 else 0.0):+.1f}){ref}")

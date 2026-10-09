"""The Diet's vote shares against ParlGov's (vote_fit.py): the same half mean squared change in log share times the normal vote between
elections g years apart, the Pedersen volatility, and where the two big parties' seats go over the run.
python3 vote_sim.py 'formation/run13_*.jsonl'"""
import json, glob, sys, math, collections, statistics as st
by_seed = collections.defaultdict(list)
for f in glob.glob(sys.argv[1]):
    for line in open(f):
        r = json.loads(line)
        if not r.get('fall'): by_seed[r['s']].append(r)
NORMAL = {'civic': 75 / 300, 'vanguard': 80 / 300, 'iron_harbor': 35 / 300, 'exchange': 30 / 300, 'chartists': 20 / 300, 'common_lot': 15 / 300, 'free_port': 25 / 300, 'bastion_guilds': 20 / 300}
gaps = collections.defaultdict(list); vol = []; big = collections.defaultdict(list); loss = []
for runs in by_seed.values():
    runs.sort(key=lambda r: r['t'])
    for i, a in enumerate(runs):
        for b in runs[i + 1:i + 11]:
            g = round(b['t'] - a['t'])
            for p in a['shares']:
                gaps[g].append(NORMAL[p] * (math.log(b['shares'][p]) - math.log(a['shares'][p])) ** 2 / 2)
        if i: vol.append(50 * sum(abs(a['shares'][p] - runs[i - 1]['shares'][p]) for p in a['shares'])); loss.append(-a['incumbentSwing'])
        big[int(a['t'] // 25) * 25].append(a['seats']['civic'] + a['seats']['vanguard'])
print('gap (x1000, times normal vote): ' + '  '.join(f"{g}:{1000 * st.mean(v):.1f}" for g, v in sorted(gaps.items())))
print('ParlGov Nordic model (phi .905, short 1.2, lasting 19.1): ' + '  '.join(f"{g}:{1.2 + 19.1 * (1 - .905 ** g):.1f}" for g in sorted(gaps)))
print(f"observed incumbent loss {100 * st.mean(loss):.2f}pp")
print(f"volatility {st.mean(vol):.2f}")
print('civic+vanguard seats by 25y: ' + '  '.join(f"{t}:{st.mean(v):.0f}" for t, v in sorted(big.items())))

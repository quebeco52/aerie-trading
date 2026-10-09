"""Financial-centre arms (fc_arms.sh / fc_run.sh): python3 var/harness/fc_an.py [dir] [arms...]

Per arm, from CreditMacroProbeTest quarter rows (burn-in dropped):
  crises per century and the credit gap at onset (JST post-war panel: 2.2 a century-country; US 2, UK 3, CH 2 in 1950-2016),
  the output-gap path after a crisis against the four quarters before it, averaged over crises (JRST 2021 Table 8: a
  financial recession runs 1.3 / 4.0 / 6.0 / 6.5 / 6.8 pp below a normal one at years 1-5), and the unconditional cycle.
Seed-level means with seed-bootstrap standard errors; arms share seeds, so differences are paired.
"""
import glob, json, math, os, random, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
D = sys.argv[1] if len(sys.argv) > 1 else os.path.join(H, 'fc_runs')
ARMS = sys.argv[2:] or ['base', 'noccyb', 'fc', 'fcnoccyb']
BURN = 10.0


def load(arm):
    by = {}
    for p in sorted(glob.glob(f'{D}/{arm}-*.json')):
        for r in json.load(open(p)):
            by.setdefault(r['seed'], []).append(r)
    return by


def seed_stats(q):
    q = [r for r in q if r['t'] > BURN]
    years = (q[-1]['t'] - q[0]['t']) + 0.25
    onsets = sorted({r['crisis'] for r in q if r['crisis'] is not None and r['crisis'] > BURN})
    idx = {}
    for i, r in enumerate(q):
        for t0 in onsets:
            if t0 not in idx and r['t'] >= t0:
                idx[t0] = i
    paths, gaps_at = [], []
    for t0, i in idx.items():
        if i < 4 or i + 20 >= len(q):
            continue
        pre = st.mean(x['gap'] for x in q[i - 4:i])
        paths.append([100 * (q[i + 4 * y]['gap'] - pre) if y else 0.0 for y in range(6)])
        gaps_at.append(100 * q[i]['credit_gap'])
    gap = [100 * r['gap'] for r in q]
    return {
        'crises_per_century': 100 * len(onsets) / years,
        'credit_gap_at_crisis': st.mean(gaps_at) if gaps_at else float('nan'),
        'paths': paths,
        'gap_sd': st.pstdev(gap),
        'slump_pct': 100 * sum(g < -3 for g in gap) / len(gap),
        'credit_gap_sd': 100 * st.pstdev(r['credit_gap'] for r in q),
        'credit_gap_p90': 100 * sorted(r['credit_gap'] for r in q)[int(0.9 * len(q))],
        'hazard_mean': 100 * st.mean(r['hazard'] for r in q),
    }


def boot_se(vals, n=300):
    random.seed(7)
    vals = [v for v in vals if v == v]
    return st.pstdev(st.mean(random.choice(vals) for _ in vals) for _ in range(n)) if len(vals) > 1 else float('nan')


S = {a: {s: seed_stats(q) for s, q in load(a).items()} for a in ARMS}
S = {a: v for a, v in S.items() if v}
keys = ['crises_per_century', 'credit_gap_at_crisis', 'hazard_mean', 'credit_gap_sd', 'credit_gap_p90', 'gap_sd', 'slump_pct']
print(f"{D}: " + ', '.join(f"{a} {len(v)} seeds" for a, v in S.items()))
print(f"\n{'':24s}" + ''.join(f"{a:>18s}" for a in S))
for k in keys:
    cells = []
    for a in S:
        v = [x[k] for x in S[a].values()]
        vv = [x for x in v if x == x]
        cells.append(f"{st.mean(vv):8.3f} ({boot_se(v):.3f})" if vv else f"{'-':>8s}        ")
    print(f"{k:24s}" + ''.join(f"{c:>18s}" for c in cells))

print("\noutput gap after a crisis, pp vs the four quarters before (years 1-5; crises pooled)")
for a in S:
    ps = [p for x in S[a].values() for p in x['paths']]
    if ps:
        m = [st.mean(p[y] for p in ps) for y in range(1, 6)]
        print(f"  {a:10s} n {len(ps):4d}  " + '  '.join(f"y{y} {m[y - 1]:+.2f}" for y in range(1, 6)) + f"   sum {sum(m):+.2f}")

if 'base' in S:
    print("\npaired vs base (seed differences)")
    for a in S:
        if a == 'base':
            continue
        for k in ('crises_per_century', 'gap_sd', 'slump_pct', 'credit_gap_sd'):
            d = [S[a][s][k] - S['base'][s][k] for s in S[a] if s in S['base']]
            se = st.stdev(d) / math.sqrt(len(d))
            print(f"  {a:10s} {k:22s} {st.mean(d):+7.3f} ± {se:.3f}  z {st.mean(d) / se if se else float('nan'):+5.1f}")

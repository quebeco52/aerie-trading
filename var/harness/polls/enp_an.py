"""Party-system concentration and vote/cabinet moments, paired NEW - HEAD, one value per seed (formation_run.php rows).
By 40-year block: effective number of parties by vote (1 / sum s^2, mean over the block's elections) and the largest
party's vote share (per-seed 90th percentile over the block's elections; pooled p90 beside it). Then the form_an2.py
moments plus the share of votes giving one party a majority, mean/SD of days from a vote to its government (single-party
majorities count 0 days, as CoalitionFormation::talks returns) and the share of talks needing a second attempt.
python3 enp_an.py <runs dir> [block years=40] [years=200]"""
import json, glob, sys, os, statistics as st, math
d = sys.argv[1]; B = float(sys.argv[2]) if len(sys.argv) > 2 else 40.0; Y = float(sys.argv[3]) if len(sys.argv) > 3 else 200.0
TERM, MAJ = 4.0, 151
blocks = [(lo, lo + B) for lo in [i * B for i in range(int(Y // B))]]
p90 = lambda xs: sorted(xs)[int(0.9 * (len(xs) - 1))]
size = lambda r: (lambda p: (r['seats'][p], r['shares'][p]))
def moments(path):
    rows = [json.loads(l) for l in open(path)]
    el = sorted((r for r in rows if not r.get('fall')), key=lambda r: r['t'])
    out, pooled = {}, {}
    for lo, hi in blocks:
        b = [r for r in el if lo < r['t'] <= hi]
        out[f"ENP y{lo:.0f}-{hi:.0f}"] = st.mean(1 / sum(v * v for v in r['shares'].values()) for r in b)
        tops = [max(r['shares'].values()) for r in b]
        out[f"top p90 y{lo:.0f}-{hi:.0f}"] = p90(tops)
        pooled[f"top p90 y{lo:.0f}-{hi:.0f}"] = tops
    out['Pedersen volatility'] = st.mean(50 * sum(abs(b['shares'][p] - a['shares'][p]) for p in b['shares']) for a, b in zip(el, el[1:]))
    out['cabinet loss at vote (pp)'] = st.mean(-100 * b['incumbentSwing'] for b in el[1:])
    out['minority cabinet share'] = sum(bool(r['support']) for r in rows) / len(rows)
    out['single-party share'] = sum(len(r['cabinet']) == 1 for r in el) / len(el)
    out['largest party in cabinet'] = sum(max(r['seats'], key=size(r)) in r['cabinet'] for r in el) / len(el)
    out['terms ended early'] = len({int(r['t'] // TERM) for r in rows if r.get('fall')}) / len(el)
    out['one-party majority votes'] = sum(max(r['seats'].values()) >= MAJ for r in el) / len(el)
    days = [r['log'][-1]['day'] if r['log'] else 0.0 for r in el]
    out['days to govt, mean'] = st.mean(days)
    out['days to govt, SD'] = st.pstdev(days)
    talks = [r for r in el if r['log']]
    out['talks needing 2nd attempt'] = sum(len(r['log']) > 1 for r in talks) / len(talks)
    return out, pooled, days
seeds = sorted(int(os.path.basename(f)[4:-6]) for f in glob.glob(f"{d}/new_*.jsonl") if os.path.exists(f"{d}/head_{os.path.basename(f)[4:]}"))
H = {s: moments(f"{d}/head_{s}.jsonl") for s in seeds}; N = {s: moments(f"{d}/new_{s}.jsonl") for s in seeds}
print(f"n = {len(seeds)} seeds, paired NEW - HEAD; per-seed values, se of the paired difference")
print(f"{'quantity':28s} {'HEAD':>8s} {'NEW':>8s} {'diff':>8s} {'se':>7s} {'t':>6s}  {'HEAD rng':>13s} {'NEW rng':>13s}")
for k in H[seeds[0]][0]:
    h = [H[s][0][k] for s in seeds]; n = [N[s][0][k] for s in seeds]; dd = [b - a for a, b in zip(h, n)]
    se = st.stdev(dd) / math.sqrt(len(dd))
    t = st.mean(dd) / se if se > 0 else float('nan')
    print(f"{k:28s} {st.mean(h):8.3f} {st.mean(n):8.3f} {st.mean(dd):+8.3f} {se:7.3f} {t:+6.2f}  {min(h):6.2f}-{max(h):6.2f} {min(n):6.2f}-{max(n):6.2f}")
print("pooled over seeds: largest vote share p90 by block, HEAD / NEW")
for k in H[seeds[0]][1]:
    print(f"  {k:24s} {p90([x for s in seeds for x in H[s][1][k]]):.3f} / {p90([x for s in seeds for x in N[s][1][k]]):.3f}")
for name, M in (('HEAD', H), ('NEW', N)):
    alld = [x for s in seeds for x in M[s][2]]
    print(f"pooled days to govt {name}: mean {st.mean(alld):.1f} sd {st.pstdev(alld):.1f} n {len(alld)} (FORMATION_MEAN_DAYS 33.7)")
print("drift within arm, last block - first block (se over seeds)")
for q in ('ENP', 'top p90'):
    a, b = f"{q} y{blocks[0][0]:.0f}-{blocks[0][1]:.0f}", f"{q} y{blocks[-1][0]:.0f}-{blocks[-1][1]:.0f}"
    for name, M in (('HEAD', H), ('NEW', N)):
        dd = [M[s][0][b] - M[s][0][a] for s in seeds]
        print(f"  {q:8s} {name:4s} {st.mean(dd):+.3f} ({st.stdev(dd) / math.sqrt(len(dd)):.3f})")

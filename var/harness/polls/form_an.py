"""Paired NEW - HEAD vote moments from form.sh's formation_run.php rows, one value per seed:
Pedersen volatility (vote_sim.py's), observed cabinet-party loss at the vote (pp, -incumbentSwing), minority-cabinet share
of formations at elections (formation_report.py's: a non-empty support list). python3 form_an.py <runs dir> [burn years]"""
import json, glob, sys, os, statistics as st, math
d = sys.argv[1]; burn = float(sys.argv[2]) if len(sys.argv) > 2 else 0.0
def moments(path):
    rows = [json.loads(l) for l in open(path)]
    el = sorted((r for r in rows if not r.get('fall') and r['t'] >= burn), key=lambda r: r['t'])
    vol = [50 * sum(abs(b['shares'][p] - a['shares'][p]) for p in b['shares']) for a, b in zip(el, el[1:])]
    loss = [-100 * b['incumbentSwing'] for b in el[1:]]
    form = [r for r in rows if r['t'] >= burn]
    return st.mean(vol), st.mean(loss), sum(bool(r['support']) for r in form) / len(form), len(el)
seeds = sorted(int(os.path.basename(f)[4:-6]) for f in glob.glob(f"{d}/new_*.jsonl") if os.path.exists(f"{d}/head_{os.path.basename(f)[4:]}"))
H = {s: moments(f"{d}/head_{s}.jsonl") for s in seeds}; N = {s: moments(f"{d}/new_{s}.jsonl") for s in seeds}
print(f"n = {len(seeds)} seeds, elections per seed ~{st.mean(H[s][3] for s in seeds):.0f}, burn {burn:g}y")
print(f"{'quantity':26s} {'HEAD':>8s} {'NEW':>8s} {'diff':>8s} {'se':>7s} {'t':>6s}  {'range of diff':>16s}")
for i, name in enumerate(['Pedersen volatility', 'cabinet loss at vote (pp)', 'minority cabinet share']):
    h = [H[s][i] for s in seeds]; n = [N[s][i] for s in seeds]; dd = [b - a for a, b in zip(h, n)]
    se = st.stdev(dd) / math.sqrt(len(dd))
    print(f"{name:26s} {st.mean(h):8.3f} {st.mean(n):8.3f} {st.mean(dd):+8.3f} {se:7.3f} {st.mean(dd) / se:+6.2f}  [{min(dd):+.3f}, {max(dd):+.3f}]")

"""Paired NEW - HEAD vote and cabinet moments from form.sh's formation_run.php rows, one value per seed.
Adds to form_an.py: terms with a fall (formation_report.py's), single-party and largest-party-in-cabinet shares of
election formations, and the vote change (pp) of an outgoing coalition's leading party (its largest by seats) in terms
whose campaign growth gap was above vs below trend. python3 form_an2.py <runs dir> [burn years]"""
import json, glob, sys, os, statistics as st, math
d = sys.argv[1]; burn = float(sys.argv[2]) if len(sys.argv) > 2 else 0.0
TERM = 4.0
def size(r):
    return lambda p: (r['seats'][p], r['shares'][p])
def moments(path):
    rows = [json.loads(l) for l in open(path)]
    rows = [r for r in rows if r['t'] >= burn]
    el = sorted((r for r in rows if not r.get('fall')), key=lambda r: r['t'])
    vol = [50 * sum(abs(b['shares'][p] - a['shares'][p]) for p in b['shares']) for a, b in zip(el, el[1:])]
    loss = [-100 * b['incumbentSwing'] for b in el[1:]]
    minority = sum(bool(r['support']) for r in rows) / len(rows)
    fall_terms = {int(r['t'] // TERM) for r in rows if r.get('fall')}
    falls = len(fall_terms) / len(el)
    single = sum(len(r['cabinet']) == 1 for r in el) / len(el)
    largest = sum(max(r['seats'], key=size(r)) in r['cabinet'] for r in el) / len(el)
    up, down = [], []
    for a, b in zip(el, el[1:]):
        out = b['outgoing']
        if len(out) < 2:
            continue
        pm = max(out, key=size(a))
        dv = 100 * (b['shares'][pm] - a['shares'][pm])
        (up if b['growthGap'] > 0 else down).append(dv)
    pmu = st.mean(up) if up else float('nan'); pmd = st.mean(down) if down else float('nan')
    return [st.mean(vol), st.mean(loss), falls, minority, single, largest, pmu, pmd, pmu - pmd, len(el)]
names = ['Pedersen volatility', 'cabinet loss at vote (pp)', 'terms ended by a fall', 'minority cabinet share',
         'single-party share', 'largest party in cabinet', 'PM-party dv, growth > trend', 'PM-party dv, growth < trend',
         'PM-party dv, above - below']
seeds = sorted(int(os.path.basename(f)[4:-6]) for f in glob.glob(f"{d}/new_*.jsonl") if os.path.exists(f"{d}/head_{os.path.basename(f)[4:]}"))
H = {s: moments(f"{d}/head_{s}.jsonl") for s in seeds}; N = {s: moments(f"{d}/new_{s}.jsonl") for s in seeds}
print(f"n = {len(seeds)} seeds, elections per seed ~{st.mean(H[s][-1] for s in seeds):.0f}, burn {burn:g}y")
print(f"{'quantity':30s} {'HEAD':>8s} {'NEW':>8s} {'diff':>8s} {'se':>7s} {'t':>6s}")
for i, name in enumerate(names):
    ok = [s for s in seeds if not (math.isnan(H[s][i]) or math.isnan(N[s][i]))]
    h = [H[s][i] for s in ok]; n = [N[s][i] for s in ok]; dd = [b - a for a, b in zip(h, n)]
    se = st.stdev(dd) / math.sqrt(len(dd))
    print(f"{name:30s} {st.mean(h):8.3f} {st.mean(n):8.3f} {st.mean(dd):+8.3f} {se:7.3f} {st.mean(dd) / se:+6.2f}")

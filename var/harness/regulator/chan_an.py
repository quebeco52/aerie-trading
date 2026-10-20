# chan_an.py <run dir> [step at]: paired differences by seed, for the +1pp requirement step and its three channels.
# Rows: step - off, and each channel's contribution step - noX (or, where the noX tree's own no-step arm offX exists,
# (step - off) - (noX - offX), which nets out what the zeroed channel does to the CCyB-only baseline).
# Columns: real GDP (log, % ; pot * (1 + gap)) peak of the mean path and its quarter, mean over years 0-4 (q1-16) and
# 5-8 (q17-32); output gap pp mean q1-16; household debt/income log % at q16; SLOOS points mean q1-8;
# pre: mean |log GDP diff| over the 4 quarters before the step (0 when the arms share a path until the step).
# A pair is flagged when the crisis dates after the step differ between its arms; GDP columns are repeated without them.
import json, sys, glob, statistics as st, math, os
D = sys.argv[1]; T0 = float(sys.argv[2]) if len(sys.argv) > 2 else 10.0
def load(arm):
    rows = {}
    for f in glob.glob(f'{D}/{arm}_*.jsonl'):
        for line in open(f):
            r = json.loads(line); rows.setdefault(r['s'], {})[round(r['t'], 3)] = r
    return rows
arms = {a: load(a) for a in ['off', 'step', 'step4', 'noBridges', 'noPremium', 'noSLOOS', 'offBridges', 'offPremium', 'offSLOOS']}
t = lambda q: round(T0 + q / 4, 3)
lg = lambda r: 100 * math.log(r['pot'] * (1 + r['gap']))
def crises(rows):
    return sorted({round(r['lastcrisis'], 2) for r in rows.values() if r['lastcrisis'] > T0 - 0.01})
def series(a, b, s, f):
    return {q: f(a[s][t(q)]) - f(b[s][t(q)]) for q in range(-4, 33) if t(q) in a[s] and t(q) in b[s]}
def stats(x):
    return (st.mean(x), st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan'), len(x))
def pairs(spec):
    # spec: list of (plus, minus) arm pairs whose differences are summed with sign
    seeds = sorted(set.intersection(*[set(arms[a]) & set(arms[b]) for a, b, _ in spec]))
    out = []
    for s in seeds:
        d = {}
        for a, b, sign in spec:
            for k, f in [('y', lg), ('gap', lambda r: 100 * r['gap']), ('hdti', lambda r: 100 * math.log(r['hdti'])), ('sloos', lambda r: r['sloos'])]:
                for q, v in series(arms[a], arms[b], s, f).items():
                    d.setdefault(k, {}).setdefault(q, 0.0); d[k][q] += sign * v
        flag = any(crises(arms[a][s]) != crises(arms[b][s]) for a, b, _ in spec)
        out.append((s, d, flag))
    return out
def row(name, spec):
    P = pairs(spec)
    if not P:
        return
    def col(sel):
        y = [p[1]['y'] for p in sel]
        if not y:
            return '  (none)'
        path = {q: st.mean(v[q] for v in y) for q in range(1, 33)}
        qp = min(path, key=path.get)
        pk = stats([v[qp] for v in y]); a = stats([st.mean(v[q] for q in range(1, 17)) for v in y]); b = stats([st.mean(v[q] for q in range(17, 33)) for v in y])
        return f"{pk[0]:7.3f} ({pk[1]:.3f}) q{qp:<2d} {a[0]:7.3f} ({a[1]:.3f}) {b[0]:7.3f} ({b[1]:.3f}) n={len(y)}"
    gap = stats([st.mean(p[1]['gap'][q] for q in range(1, 17)) for p in P])
    hd = stats([p[1]['hdti'][16] for p in P]); sl = stats([st.mean(p[1]['sloos'][q] for q in range(1, 9)) for p in P])
    pre = st.mean(st.mean(abs(p[1]['y'][q]) for q in range(-3, 1)) for p in P)
    flags = [p[0] for p in P if p[2]]
    print(f"{name:22s} all : {col(P)} | gap {gap[0]:6.3f} ({gap[1]:.3f}) hdti4 {hd[0]:6.2f} ({hd[1]:.2f}) sloos02 {sl[0]:6.3f} ({sl[1]:.3f}) pre {pre:.3f} crisis-split {flags}")
    if flags:
        print(f"{'':22s} kept: {col([p for p in P if not p[2]])}")
print(f"{'':22s}       logGDP% peak (se) q    yrs0-4 (se)      yrs5-8 (se)")
row('step - off', [('step', 'off', 1)])
row('step4 - off', [('step4', 'off', 1)])
for ch in ['Bridges', 'Premium', 'SLOOS']:
    if arms['off' + ch]:
        row(f'{ch} (DiD)', [('step', 'off', 1), (f'no{ch}', f'off{ch}', -1)])
    row(f'{ch} (step-no{ch})', [('step', f'no{ch}', 1)])
for ch in ['Bridges', 'Premium', 'SLOOS']:
    row(f'no{ch} - off', [(f'no{ch}', 'off', 1)])

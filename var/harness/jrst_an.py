"""Financial versus normal recessions, as Jorda, Richter, Schularick & Taylor (2021) measure them:
python3 var/harness/jrst_an.py <dir> <arm> [arm ...]

On CreditMacroProbeTest rows (fc_run.sh): annual GDP = mean over the year of potential x (1 + quarter-average gap);
a peak is a year above both neighbours (annual Bry-Boschan); a recession is financial when a credit crisis starts
within two years of its peak (JST 2013). The local projection 100 x (log y_{p+h} - log y_p) = mu_h + gamma_h x financial,
h = 1..5, pooled over seeds with seed-clustered standard errors. JRST Table 8 (full sample, controls), for scale:
normal mu_h -1.79 -0.24 +2.04 +3.74 +5.21; financial excess gamma_h -1.28 (0.58) -4.04 (0.95) -5.95 (0.82) -6.52 (1.20)
-6.76 (0.90). The engine's GDP is not per capita and its trend grows at its own rate, so mu_h is read for shape;
gamma_h is the target. J weights gamma_h by JRST's standard errors.
"""
import glob, json, math, os, statistics as st, sys

D = sys.argv[1]
BURN = 10
MU = [-1.79, -0.24, 2.04, 3.74, 5.21]
GAMMA = [-1.28, -4.04, -5.95, -6.52, -6.76]
GAMMA_SE = [0.58, 0.95, 0.82, 1.20, 0.90]


def load(arm):
    by = {}
    for p in sorted(glob.glob(f'{D}/{arm}-*.json')):
        for r in json.load(open(p)):
            by.setdefault(r['seed'], []).append(r)
    return by


def episodes(rows):
    years = {}
    for r in rows:
        y = int(r['t'] - 1e-9)
        years.setdefault(y, []).append(r['pot'] * (1.0 + r['gap_avg']))
    ys = sorted(y for y, v in years.items() if len(v) == 4)
    lev = {y: math.log(st.mean(years[y])) for y in ys}
    crisis_years = {int(r['crisis']) for r in rows if r['crisis'] is not None and r['crisis'] > 0}
    out = []
    for y in ys:
        if y < BURN or y - 1 not in lev or y + 5 not in lev:
            continue
        if lev[y] > lev[y - 1] and lev[y] > lev[y + 1]:
            fin = any(abs(c - y) <= 2 for c in crisis_years)
            out.append((fin, [100 * (lev[y + h] - lev[y]) for h in range(1, 6)]))
    return out


def lp(by):
    """OLS of each horizon on [1, financial], seed-clustered sandwich."""
    res = []
    for h in range(5):
        X, Y, G = [], [], []
        for seed, eps in by.items():
            for fin, path in eps:
                X.append((1.0, 1.0 if fin else 0.0))
                Y.append(path[h])
                G.append(seed)
        n = len(Y)
        sxx = [[sum(x[i] * x[j] for x in X) for j in range(2)] for i in range(2)]
        det = sxx[0][0] * sxx[1][1] - sxx[0][1] * sxx[1][0]
        inv = [[sxx[1][1] / det, -sxx[0][1] / det], [-sxx[1][0] / det, sxx[0][0] / det]]
        sxy = [sum(x[i] * y for x, y in zip(X, Y)) for i in range(2)]
        b = [inv[i][0] * sxy[0] + inv[i][1] * sxy[1] for i in range(2)]
        meat = [[0.0, 0.0], [0.0, 0.0]]
        for g in set(G):
            s = [sum(x[i] * (y - b[0] * x[0] - b[1] * x[1]) for x, y, gg in zip(X, Y, G) if gg == g) for i in range(2)]
            for i in range(2):
                for j in range(2):
                    meat[i][j] += s[i] * s[j]
        V = [[sum(inv[i][k] * meat[k][l] * inv[l][j] for k in range(2) for l in range(2)) for j in range(2)] for i in range(2)]
        res.append((b, [math.sqrt(max(0.0, V[i][i])) for i in range(2)], n))
    return res


for arm in sys.argv[2:]:
    by = {s: episodes(rows) for s, rows in load(arm).items()}
    nrec = sum(len(e) for e in by.values())
    nfin = sum(1 for e in by.values() for f, _ in e if f)
    if nfin < 3:
        print(f"{arm}: {nrec} recessions, {nfin} financial: too few")
        continue
    r = lp(by)
    J = sum(((r[h][0][1] - GAMMA[h]) / GAMMA_SE[h]) ** 2 for h in range(5))
    print(f"{arm}: {nrec} recessions, {nfin} financial ({100 * nfin / nrec:.0f}%)  J_gamma {J:.1f}")
    print("   normal mu_h    " + '  '.join(f"{r[h][0][0]:+6.2f}" for h in range(5)) + "   JRST " + ' '.join(f"{m:+.2f}" for m in MU))
    print("   financial gam  " + '  '.join(f"{r[h][0][1]:+6.2f}" for h in range(5)) + "   JRST " + ' '.join(f"{g:+.2f}" for g in GAMMA))
    print("   (se)           " + '  '.join(f"({r[h][1][1]:4.2f})" for h in range(5)))

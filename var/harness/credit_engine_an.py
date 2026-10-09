"""Credit round A/B: shape table, credit gauges, and the data's local projections run on the engine's own output.

    python3 credit_engine_an.py <shape dir> base new ...

Shape: an episode is an excursion past +/-2%, zero crossing to zero crossing (the CBO table). The local
projections use quarterly AVERAGES of the gap and the premium, the same spec as credit_fit.py step 9.
"""
import glob, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
D = sys.argv[1]
FIT = json.load(open(os.path.join(H, 'credit_fit.json')))
IG_CAP, HY_CAP, SLOOS_CAP, VOL_TARGET_CAP = 0.065, 0.25, 0.85, 0.45


def episodes(g, sign):
    out, i, n = [], 0, len(g)
    while i < n:
        if sign * g[i] > 0:
            j = i
            while j < n and sign * g[j] > 0:
                j += 1
            seg = g[i:j]
            peak = max(seg) if sign > 0 else min(seg)
            if abs(peak) >= 0.02 and i > 0 and j < n:
                k = seg.index(peak)
                out.append({'depth': abs(peak), 'descent_q': k + 1, 'recovery_q': len(seg) - k - 1, 'start': i, 'trough': i + k})
            i = j
        else:
            i += 1
    return out


def inverse(A):
    p = len(A)
    M = [A[i][:] + [1.0 if i == j else 0.0 for j in range(p)] for i in range(p)]
    for c in range(p):
        pv = max(range(c, p), key=lambda r: abs(M[r][c]))
        M[c], M[pv] = M[pv], M[c]
        d = M[c][c]
        M[c] = [x / d for x in M[c]]
        for r in range(p):
            if r != c:
                f = M[r][c]
                M[r] = [a - f * b for a, b in zip(M[r], M[c])]
    return [row[p:] for row in M]


def ols(Y, X):
    n, p = len(Y), len(X[0])
    inv = inverse([[sum(X[i][a] * X[i][b] for i in range(n)) for b in range(p)] for a in range(p)])
    Xty = [sum(X[i][a] * Y[i] for i in range(n)) for a in range(p)]
    b = [sum(inv[a][c] * Xty[c] for c in range(p)) for a in range(p)]
    return b, [Y[i] - sum(b[j] * X[i][j] for j in range(p)) for i in range(n)]


def local_projections(by):
    """Gap and premium responses to a +1pp premium innovation (gap ordered first), pooled over seeds."""
    inn = {}
    Y, X, keys = [], [], []
    for seed, q in by.items():
        g = [r['gap_avg'] for r in q]
        e = [r['p_avg'] for r in q]
        for i in range(2, len(q)):
            Y.append(e[i])
            X.append([1.0, e[i - 1], e[i - 2], g[i], g[i - 1], g[i - 2]])
            keys.append((seed, i))
    _, res = ols(Y, X)
    inn = dict(zip(keys, res))
    out = {}
    for h in [0, 1, 2, 4, 6, 8, 12]:
        Yg, Yp, X = [], [], []
        for seed, q in by.items():
            g = [100 * r['gap_avg'] for r in q]
            e = [100 * r['p_avg'] for r in q]
            for i in range(2, len(q) - h):
                Yg.append(g[i + h] - g[i - 1])
                Yp.append(e[i + h])
                X.append([1.0, 100 * inn[(seed, i)], g[i - 1], g[i - 1] - g[i - 2], e[i - 1]])
        bg, _ = ols(Yg, X)
        bp, _ = ols(Yp, X)
        out[h] = (bg[1], bp[1])
    return out


for arm in sys.argv[2:]:
    runs = [json.load(open(f)) for f in sorted(glob.glob(f'{D}/{arm}-*.json')) if not f.endswith('-0.json')]
    rows = [r for run in runs for r in run if r['t'] > 10]
    by = {}
    for r in rows:
        by.setdefault(r['seed'], []).append(r)
    gaps = [r['gap'] for r in rows]
    m, s = st.mean(gaps), st.pstdev(gaps)
    skew = st.mean([((v - m) / s) ** 3 for v in gaps])
    kurt = st.mean([((v - m) / s) ** 4 for v in gaps]) - 3
    years = len(rows) / 4
    busts = [dict(e, seed=seed) for seed, q in by.items() for e in episodes([r['gap'] for r in q], -1)]
    booms = [e for q in by.values() for e in episodes([r['gap'] for r in q], +1)]
    deep = [e for e in busts if e['depth'] > 0.04]
    print(f"== {arm}: {len(by)} seeds, {years:.0f} years")
    print(f"  sd {s * 100:.2f}%  skew {skew:+.2f}  excess kurtosis {kurt:+.2f}  in +/-1% {sum(abs(v) < 0.01 for v in gaps) / len(gaps) * 100:.0f}%  "
          f"|gap|>3% {sum(abs(v) > 0.03 for v in gaps) / len(gaps) * 100:.1f}%  below -3% {sum(v < -0.03 for v in gaps) / len(gaps) * 100:.1f}%   (CBO: 2.31, -0.32, +0.69, 37%, 20.6%)")
    print(f"  busts: 1 per {years / len(busts):.1f}y  depth {st.mean(e['depth'] for e in busts) * 100:.2f}% worst {max(e['depth'] for e in busts) * 100:.2f}%  "
          f"deep (>4%) {len(deep) / len(busts) * 100:.0f}%  descent {st.mean(e['descent_q'] for e in busts):.1f}q ({st.mean(e['depth'] / (e['descent_q'] / 4) for e in busts) * 100:.2f}pp/yr)  "
          f"recovery {st.mean(e['recovery_q'] for e in busts):.1f}q   (CBO: 7.7y, 4.31%, 8.83, 40%, 4.7q 3.67pp/yr, 10.1q)")
    print(f"  booms: 1 per {years / len(booms):.1f}y  depth {st.mean(e['depth'] for e in booms) * 100:.2f}%  climb {st.mean(e['depth'] / (e['descent_q'] / 4) for e in booms) * 100:.2f}pp/yr   (CBO: 11.0y)")
    longest = 0
    for q in by.values():
        run = 0
        for r in q:
            run = run + 1 if r['gap'] < -0.04 else 0
            longest = max(longest, run)
    print(f"  longest stretch below -4%: {longest}q")

    # --- credit gauges ---
    n = len(rows)
    at = lambda key, cap: sum(r[key] >= cap - 1e-9 for r in rows) / n * 100
    crises = len({(r['seed'], r['crisis']) for r in rows if r['crisis'] > 10})
    print(f"  at cap: IG {at('ig', IG_CAP):.1f}%  HY {at('hy', HY_CAP):.1f}%  SLOOS {at('sloos', SLOOS_CAP):.1f}%  vol>=44% {sum(r['vol'] >= 0.44 for r in rows) / n * 100:.1f}% of quarters")
    print(f"  IG mean {st.mean(r['ig'] for r in rows) * 100:.2f}% (base 1.30) p99 {sorted(r['ig'] for r in rows)[int(0.99 * n)] * 100:.2f}  HY mean {st.mean(r['hy'] for r in rows) * 100:.2f}% max {max(r['hy'] for r in rows) * 100:.1f}  "
          f"vol mean {st.mean(r['vol'] for r in rows) * 100:.1f}%  TED mean {st.mean(r['ted'] for r in rows) * 100:.2f}%  SLOOS mean {st.mean(r['sloos'] for r in rows):+.3f}  FCI mean {st.mean(r['fci'] for r in rows):+.2f}")
    print(f"  default rate mean {st.mean(r['dflt'] for r in rows) * 100:.2f}% (Moody's 1.6)  p99 {sorted(r['dflt'] for r in rows)[int(0.99 * n)] * 100:.1f}%  max {max(r['dflt'] for r in rows) * 100:.1f}% (2009 all-rated 5.4)  "
          f"crises {crises} = {crises / years * 100:.1f}/century")
    prem = [r['p_avg'] for r in rows]
    if any(prem):
        pm, ps = st.mean(prem), st.pstdev(prem)
        psk = st.mean([((v - pm) / ps) ** 3 for v in prem])
        pku = st.mean([((v - pm) / ps) ** 4 for v in prem]) - 3
        ar = [sum((q[i]['p_avg'] - pm) * (q[i - 1]['p_avg'] - pm) for i in range(1, len(q))) for q in by.values()]
        ar1 = sum(ar) / sum(sum((r['p_avg'] - pm) ** 2 for r in q[:-1]) for q in by.values())
        d = FIT['data_ebp_ex_gfc']
        print(f"  premium (quarter avg): mean {pm * 100:+.2f}pp sd {ps * 100:.2f} skew {psk:+.2f} exkurt {pku:+.2f} AR1 {ar1:.3f}   "
              f"(GZ ex-GFC: sd {d['sd'] * 100:.2f} skew {d['skew']:+.2f} exkurt {d['exkurt']:+.2f} AR1 0.834; all: sd 0.51 skew +2.58)")
        # premium peak lead over the trough, and how much of it is gone two quarters after its peak, per deep bust
        leads, fades = [], []
        for e in deep:
            q = by[e['seed']]
            lo, hi = max(0, e['start'] - 4), e['trough'] + 1
            k = max(range(lo, hi), key=lambda i: q[i]['p_avg'])
            leads.append(e['trough'] - k)
            if k + 2 < len(q) and q[k]['p_avg'] > 0:
                fades.append(1 - q[k + 2]['p_avg'] / q[k]['p_avg'])
        if leads:
            print(f"  deep busts: premium peaks {st.mean(leads):.1f}q before the trough (data: 1-3q), {st.mean(fades) * 100:.0f}% of it gone 2q later")
        lp = local_projections(by)
        dirf = FIT['irf']
        print("  LP, gap response to +1pp premium (engine | data): " + '  '.join(f"{h}q {g:+.2f}|{dirf[str(h)][0]:+.2f}" for h, (g, p) in lp.items() if str(h) in dirf))
        print("  LP, premium's own response (engine | data 1.00 1.13 0.91 _ 0.36 0.16 0.01 _): " + '  '.join(f"{h}q {p:.2f}" for h, (g, p) in lp.items()))

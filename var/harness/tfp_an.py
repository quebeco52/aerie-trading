"""Output-gap shape table (the memory's CBO comparison) for one or more probe arms, plus the productivity share.

    python3 tfp_an.py <shape dir> base new ...

An episode is an excursion past +/-2%, from the zero crossing before it to the one after (as in the CBO table).
"""
import glob, json, math, statistics as st, sys

D = sys.argv[1]


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
                out.append({'depth': abs(peak), 'descent_q': k + 1, 'recovery_q': len(seg) - k - 1})
            i = j
        else:
            i += 1
    return out


for arm in sys.argv[2:]:
    runs = [json.load(open(f)) for f in sorted(glob.glob(f'{D}/{arm}-*.json'))]
    rows = [r for run in runs for r in run if r['t'] > 10]           # 10-year burn-in
    by = {}
    for r in rows:
        by.setdefault(r['seed'], []).append(r)
    gaps = [r['gap'] for r in rows]
    m, s = st.mean(gaps), st.pstdev(gaps)
    skew = st.mean([((v - m) / s) ** 3 for v in gaps])
    kurt = st.mean([((v - m) / s) ** 4 for v in gaps]) - 3
    years = sum(len(q) for q in by.values()) / 4
    busts = [e for q in by.values() for e in episodes([r['gap'] for r in q], -1)]
    booms = [e for q in by.values() for e in episodes([r['gap'] for r in q], +1)]
    sup = [r.get('supply', 0.0) for r in rows]
    dem = [r['gap'] - r.get('supply', 0.0) for r in rows]
    print(f"== {arm}: {len(by)} seeds, {years:.0f} years")
    print(f"  sd {s * 100:.2f}%  skew {skew:+.2f}  excess kurtosis {kurt:+.2f}  in +/-1% {sum(abs(v) < 0.01 for v in gaps) / len(gaps) * 100:.0f}%  |gap|>3% {sum(abs(v) > 0.03 for v in gaps) / len(gaps) * 100:.1f}%")
    if busts:
        print(f"  busts: 1 per {years / len(busts):.1f}y  depth {st.mean(e['depth'] for e in busts) * 100:.2f}% worst {max(e['depth'] for e in busts) * 100:.2f}%  "
              f"descent {st.mean(e['descent_q'] for e in busts):.1f}q ({st.mean(e['depth'] / (e['descent_q'] / 4) for e in busts) * 100:.2f}pp/yr)  recovery {st.mean(e['recovery_q'] for e in busts):.1f}q")
    if booms:
        print(f"  booms: 1 per {years / len(booms):.1f}y  depth {st.mean(e['depth'] for e in booms) * 100:.2f}%  descent {st.mean(e['depth'] / (e['descent_q'] / 4) for e in booms) * 100:.2f}pp/yr")
    if any(sup):
        c = sum((a - st.mean(sup)) * (b - st.mean(dem)) for a, b in zip(sup, dem)) / len(sup)
        print(f"  supply part sd {st.pstdev(sup) * 100:.2f}%  demand part sd {st.pstdev(dem) * 100:.2f}%  supply share of gap variance {(st.pvariance(sup) + c) / st.pvariance(gaps) * 100:.0f}%  corr {c / (st.pstdev(sup) * st.pstdev(dem)):+.2f}")
    print(f"  inflation {st.mean(r['infl'] for r in rows) * 100:.2f}% sd {st.pstdev([r['infl'] for r in rows]) * 100:.2f}  u {st.mean(r['u'] for r in rows) * 100:.2f}% sd {st.pstdev([r['u'] for r in rows]) * 100:.2f}  "
          f"policy {st.mean(r['policy'] for r in rows) * 100:.2f}%  10y {st.mean(r['y10'] for r in rows) * 100:.2f}%  r* sd {st.pstdev([r.get('rstar', 0) for r in rows]) * 100:.2f}  real wage gap sd {st.pstdev([r['rwg'] for r in rows]) * 100:.2f}%")

#!/usr/bin/env python3
"""analyse.py [seeds]: NEW vs BASE bank-rescue event study and run moments from runs/<arm>-<seed>.jsonl. Prints one table."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
BRAKE = 0.90
CEIL = 2.5


def load(arm, seed):
    p = os.path.join(H, 'runs', f'{arm}-{seed}.jsonl')
    if not os.path.exists(p):
        return None
    return [json.loads(l) for l in open(p) if l.strip()]


def crises(rows):
    out, seen = [], -1
    for i, r in enumerate(rows):
        c = r['crisis_at']
        if c is not None and c >= 0 and c != seen:
            seen = c
            out.append((c, i))
    return out


def at(rows, t):
    best = min(rows, key=lambda r: abs(r['t'] - t))
    return best if abs(best['t'] - t) <= 0.26 else None


def seed_stats(rows):
    ev = []
    tmax = rows[-1]['t']
    for c, i in crises(rows):
        pre = [r for r in rows if r['t'] < c]
        if not pre:
            continue
        pre = pre[-1]
        post5 = at(rows, c + 5) if c + 5 <= tmax else None
        sp3 = [r['spread'] for r in rows if c < r['t'] <= c + 3]
        a10 = at(rows, c + 10) if c + 10 <= tmax else None
        peak_a = max((r['rescue_assets'] for r in rows if c <= r['t'] <= c + 1), default=0.0)
        ev.append({
            'dd5': post5['debt'] - pre['debt'] if post5 else None,
            'sp3': st.mean(sp3) if c + 3 <= tmax and sp3 else None,
            'cost': rows[i]['rescue_cost'], 'bc': pre['bank_credit'],
            'a_ratio': (a10['rescue_assets'] / peak_a) if a10 and peak_a > 1e-9 else None,
        })
    d = [r['debt'] for r in rows]
    return {
        'ev': ev, 'mean_debt': st.mean(d), 'max_debt': max(d), 'brake': sum(x > BRAKE for x in d) / len(d),
        'ceil_q': sum(x >= CEIL - 1e-6 for x in d), 'spread': st.mean(r['spread'] for r in rows),
        'max_assets': max(r['rescue_assets'] for r in rows), 'end_assets': rows[-1]['rescue_assets'], 'years': tmax,
    }


def mse(x):
    x = [v for v in x if v is not None]
    if not x:
        return float('nan'), float('nan'), 0
    return st.mean(x), (st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan')), len(x)


def pct(x, q):
    x = sorted(x)
    return x[min(len(x) - 1, int(q * (len(x) - 1) + 0.5))] if x else float('nan')


seeds = range(1, int(sys.argv[1]) + 1) if len(sys.argv) > 1 else range(1, 17)
S = {a: {} for a in ('new', 'base')}
for a in S:
    for s in seeds:
        r = load(a, s)
        if r:
            S[a][s] = seed_stats(r)
both = sorted(set(S['new']) & set(S['base']))
print(f"seeds with both arms: {len(both)}  years: {min(S['new'][s]['years'] for s in both):.0f}")


def perseed(key, sc=1.0):
    return {a: {s: (None if S[a][s][key] is None else S[a][s][key] * sc) for s in both} for a in S}


def evmean(a, key, sc=1.0):
    return {s: (lambda v: st.mean(v) * sc if v else None)([e[key] for e in S[a][s]['ev'] if e[key] is not None]) for s in both}


def row(name, per):
    m = {a: mse([per[a][s] for s in both]) for a in S}
    d = [per['new'][s] - per['base'][s] for s in both if per['new'][s] is not None and per['base'][s] is not None]
    dm, dse, n = mse(d)
    t = dm / dse if dse and dse == dse and dse > 0 else float('nan')
    print(f"{name:<28} {m['new'][0]:>9.4f} ({m['new'][1]:.4f}) {m['base'][0]:>9.4f} ({m['base'][1]:.4f}) {dm:>9.4f} ({dse:.4f}) t={t:>5.2f} n={n}")


print(f"{'quantity (per-seed means)':<28} {'NEW (se)':>18} {'BASE (se)':>18} {'NEW-BASE (se)':>18}")
row('debt +5y - pre-crisis', {a: evmean(a, 'dd5') for a in S})
row('share qtrs debt>0.90', perseed('brake'))
row('spread 3y post (bp)', {a: evmean(a, 'sp3', 1e4) for a in S})
row('spread run avg (bp)', perseed('spread', 1e4))
row('mean gross debt', perseed('mean_debt'))
row('max gross debt', perseed('max_debt'))

print('event level (all crises, se = sd/sqrt(n_events), not clustered by seed):')
for a in S:
    ev = [e for s in both for e in S[a][s]['ev']]
    dd, se, n = mse([e['dd5'] for e in ev])
    sp, sps, ns = mse([e['sp3'] * 1e4 if e['sp3'] is not None else None for e in ev])
    print(f"  {a:<4} crises={len(ev):>3} (/seed-century {len(ev) / len(both):.2f})  debt+5y={dd:.4f} ({se:.4f}) n={n}  spread3y={sp:.1f}bp ({sps:.1f}) n={ns}")
ev = [e for s in both for e in S['new'][s]['ev']]
c = [e['cost'] for e in ev]
bc = [e['bc'] for e in ev]
ar = [e['a_ratio'] for e in ev if e['a_ratio'] is not None]
print(f"NEW rescue cost/GDP: mean {mse(c)[0]:.4f} ({mse(c)[1]:.4f}) median {pct(c, .5):.4f} p90 {pct(c, .9):.4f} max {max(c) if c else float('nan'):.4f} n={len(c)}")
print(f"bank credit/GDP at crisis (NEW, qtr before): mean {mse(bc)[0]:.3f} median {pct(bc, .5):.3f} min {min(bc) if bc else 0:.3f} max {max(bc) if bc else 0:.3f}")
print(f"NEW rescue assets 10y after / peak: median {pct(ar, .5):.3f} max {max(ar) if ar else float('nan'):.3f} n={len(ar)} (exp(-10/4.2)=0.092 before GDP erosion)")
for a in S:
    print(f"{a}: max debt {max(S[a][s]['max_debt'] for s in both):.3f}  quarters at 2.5 ceiling {sum(S[a][s]['ceil_q'] for s in both)}  "
          f"max rescue assets {max(S[a][s]['max_assets'] for s in both):.4f}  mean end assets {st.mean(S[a][s]['end_assets'] for s in both):.4f}")

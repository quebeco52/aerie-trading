"""Engine asymmetry moments against asym_fit.json: python3 asym_an.py <dir> <tag>

Premium: the sign-split projection of the gap on premium innovations (asym_fit.py spec) on <tag>-*.json
(CreditMacroProbeTest quarter averages). Monetary: Barnichon-Matthes' forced funds-rate path, tightening
<tag>bmp-*.json (SCALE>0) and easing <tag>bmn-*.json (SCALE<0) from RatePathProbeTest PATHSRC=bm: peak
unemployment per 100bp each way; Tenreyro-Thwaites' split of the tightening response by the pre-shock growth state.
Prints J_prem (h 1-16, both signs) and J_mon (BM peaks).
"""
import glob, json, math, os, sys
H = os.path.dirname(os.path.abspath(__file__))
A = json.load(open(os.path.join(H, 'asym_fit.json')))
D, TAG = sys.argv[1], sys.argv[2]
exec(open(os.path.join(H, 'credit_engine_an.py')).read().split('def local_projections')[0].split('for arm in sys.argv')[0])


def sign_lp(by):
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
    for h in [1, 2, 4, 6, 8, 10, 12, 16]:
        Yg, X = [], []
        for seed, q in by.items():
            g = [100 * r['gap_avg'] for r in q]
            e = [100 * r['p_avg'] for r in q]
            for i in range(2, len(q) - h):
                v = 100 * inn[(seed, i)]
                Yg.append(g[i + h] - g[i - 1])
                X.append([1.0, max(v, 0.0), min(v, 0.0), g[i - 1], g[i - 1] - g[i - 2], e[i - 1]])
        b, _ = ols(Yg, X)
        out[h] = (b[1], -b[2])
    return out


by = {}
for f in sorted(glob.glob(f'{D}/{TAG}-[0-9]*.json')):
    for r in json.load(open(f)):
        if r['t'] > 10:
            by.setdefault(r['seed'], []).append(r)
J_prem, cells = 0.0, []
if by:
    lp = sign_lp(by)
    for h, (adv, fav) in lp.items():
        t = A['premium_sign'][str(h)]
        J_prem += ((adv - t['adverse']) / t['adverse_se']) ** 2 + ((fav - t['favorable']) / t['favorable_se']) ** 2
        cells.append(f"{h}q {adv:+.2f}/{t['adverse']:+.2f} fav {fav:+.2f}/{t['favorable']:+.2f}")
    print('premium sign LP engine/US (adverse; favorable per 1pp easing): ' + '  '.join(cells) + f" | J_prem {J_prem:.2f}")


def responses(pattern):
    out = []
    for f in sorted(glob.glob(pattern)):
        d = json.load(open(f))
        for seed, pair in d['runs'].items():
            out.append((pair.get('growth7q', 0.0), [100 * (p_['u'] - b_['u']) / d['scale'] for p_, b_ in zip(pair['path'], pair['base'])],
                        [100 * (p_['gap'] - b_['gap']) / d['scale'] for p_, b_ in zip(pair['path'], pair['base'])]))
    return out


tight, ease = responses(f'{D}/{TAG}bmp-*.json'), responses(f'{D}/{TAG}bmn-*.json')
J_mon = 0.0
if tight and ease:
    mean = lambda rs, k: [sum(r[k][h] for r in rs) / len(rs) for h in range(len(rs[0][k]))]
    ut, ue = mean(tight, 1), mean(ease, 1)       # per 100bp of TIGHTENING (easing runs divided by a negative scale)
    a_plus = max(ut)
    a_minus = max(ue)  # easing lowers u, so per unit of (negative) scale its fall reads positive
    m = A['monetary']
    J_mon = ((a_plus - m['u_peak_tight']) / m['u_peak_tight_se']) ** 2 + ((a_minus - m['u_peak_ease']) / m['u_peak_ease_se']) ** 2
    gt, ge = mean(tight, 2), mean(ease, 2)
    print(f"monetary (BM path, per 100bp): u peak tightening {a_plus:+.3f} at {ut.index(a_plus)}q / BM {m['u_peak_tight']:.2f}; easing {a_minus:+.3f} at {ue.index(a_minus)}q / BM {m['u_peak_ease']:.2f}; "
          f"ratio {a_minus / a_plus if a_plus else float('nan'):.2f} (BM 0.32-0.38) | gap trough tightening {min(gt):+.2f}, easing {min(ge):+.2f} | J_mon {J_mon:.2f}")
    allg = sorted(r[0] for r in tight)
    cut = allg[max(0, int(0.2 * len(allg)) - 1)]
    rec = [r for r in tight if r[0] <= cut]
    exp_ = [r for r in tight if r[0] > cut]
    if rec and exp_:
        print(f"    TT check, tightening gap trough by pre-shock growth: expansions {min(mean(exp_, 2)):+.2f} (n {len(exp_)}), recessions {min(mean(rec, 2)):+.2f} (n {len(rec)}) | TT GDP -1.0 vs ~0")
print(f"J_asym {J_prem + J_mon:.2f}")

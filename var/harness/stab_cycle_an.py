"""Score stab_cycle.sh points: python3 var/harness/stab_cycle_an.py <tag> [tag ...]

Per tag (StabCycleProbeTest rows, first BURN years dropped):
  HP gap persistence: log GDP = log potential + log(1 + quarter-average gap), HP(1600) per seed, ACF pooled over seeds,
    against Norway's mainland HP gap 1978-2019 (hp_gap.py); J_acf weights lags 4/8/12 by Norway's Bartlett se.
    The nostab arm is the US engine and is read against the US 1985-2019 HP gap instead.
  IMF Norway 2025 Table 5 on the annual discretionary balance (stab_an.py's regression): gap +0.450 (0.194),
    lagged change -0.452 (0.093); J_imf the same weighting.
"""
import glob, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, H)
import hp_gap

D = os.environ.get('STAB_DIR', os.path.join(H, 'stab_cycle'))
BURN = 25.0
src = open(os.path.join(H, 'stab_an.py')).read().split('\narms = {a: load(a)')[0]
lib = {'__file__': os.path.join(H, 'stab_an.py'), '__name__': 'lib'}
sys_argv, sys.argv = sys.argv, [sys.argv[0], D]
exec(compile(src, 'stab_an', 'exec'), lib)
sys.argv = sys_argv
annual, ols_fe_cluster = lib['annual'], lib['ols_fe_cluster']

LAGS = hp_gap.LAGS
_, nor = hp_gap.norway()
_, us = hp_gap.usa('1985-01-01')
NOR = {L: hp_gap.acf(nor, L) for L in LAGS}
US = {L: hp_gap.acf(us, L) for L in LAGS}


def bartlett(ref, n, lag):
    return math.sqrt((1 + 2 * sum(ref[L] ** 2 for L in LAGS if L < lag)) / n)


SE_NOR = {L: bartlett(NOR, len(nor), L) for L in LAGS}
SE_US = {L: bartlett(US, len(us), L) for L in LAGS}
IMF = ((0.450, 0.194), (-0.452, 0.093))


def load(tag):
    runs = {}
    for p in sorted(glob.glob(f'{D}/{tag}-*.json')):
        for seed, rows in json.load(open(p)).items():
            runs[seed] = [r for r in rows if r['t'] > BURN]
    return runs


def score(tag):
    runs = load(tag)
    if not runs:
        return None
    acfs = {L: [] for L in LAGS}
    sds, raw4 = [], []
    groups = []
    stabs = []
    for rows in runs.values():
        c = hp_gap.hp_cycle([math.log(r['pot']) + math.log(1 + r['gap']) for r in rows])
        for L in LAGS:
            acfs[L].append(hp_gap.acf(c, L))
        sds.append(100 * st.pstdev(c))
        raw4.append(hp_gap.acf([r['gap'] for r in rows], 4))
        stabs += [100 * r['stab'] for r in rows]
        a = annual({'quarters': rows})
        Y, X = [], []
        for p2, p1, cur in zip(a, a[1:], a[2:]):
            Y.append(cur['B'] - p1['B'])
            X.append([cur['gap'], p1['B'] - p2['B']])
        groups.append((Y, X))
    b, se, n = ols_fe_cluster(groups)
    acf = {L: st.mean(v) for L, v in acfs.items()}
    ref, rse, rname = (US, SE_US, 'US') if tag == 'nostab' else (NOR, SE_NOR, 'NOR')
    j_acf = sum(((acf[L] - ref[L]) / rse[L]) ** 2 for L in (4, 8, 12))
    j_imf = sum(((b[i] - IMF[i][0]) / IMF[i][1]) ** 2 for i in range(2))
    return {'tag': tag, 'n': len(runs), 'acf': acf, 'sd': st.mean(sds), 'raw4': st.mean(raw4), 'imf': b, 'imf_se': se,
            'stab_sd': st.pstdev(stabs), 'j_acf': j_acf, 'j_imf': j_imf, 'ref': rname}


print("reference HP ACF  NOR 1978-2019: " + ' '.join(f"{L}q {NOR[L]:+.2f}" for L in LAGS))
print("                  US  1985-2019: " + ' '.join(f"{L}q {US[L]:+.2f}" for L in LAGS))
for tag in sys.argv[1:]:
    s = score(tag)
    if s is None:
        print(f"{tag}: no rows")
        continue
    print(f"{tag:14s} n{s['n']:3d} HP ACF " + ' '.join(f"{L}q {s['acf'][L]:+.2f}" for L in LAGS)
          + f" | sd {s['sd']:.2f} rawACF4 {s['raw4']:+.2f} D sd {s['stab_sd']:.2f}"
          + f" | IMF gap {s['imf'][0]:+.3f} lag {s['imf'][1]:+.3f} | J_acf({s['ref']}) {s['j_acf']:5.2f} J_imf {s['j_imf']:5.2f}")

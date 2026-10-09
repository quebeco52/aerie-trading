#!/usr/bin/env python3
"""Lenders' pre-provision economics against the rate level, the curve slope and the cycle.

Reads EXISTING output only: runs/qw/after-<seed>.jsonl (seeds 1-16, 20 y, 360 tpy, working tree = HEAD 0071ab6 plus a
comment-only change, macro path replayed from runs/qw/rec-<seed>). One row per lender-quarter from final[t].q.
python3 nii_sensitivity.py > nii_sensitivity_seeds1-16.out

Definitions (every rate annualized, x4, as % of the row's gross earning assets `ea`):
  rev    revenue (the back-solved NII stream + fees)
  int    interest expense on deposits + wholesale (report line, quarterly)
  opex   rev - ebit - prov: every operating charge above EBIT except the provision (cost ratio x revenue,
         depreciation, severance, and for the three banks the curve "NIM squeeze", which sits in the cost ratio)
  sqz    banks only: NIM squeeze backed out of the reported NIM, nii - int - nim x (ea - allow) / 4
  ppnr   ebit + prov - int: pre-provision profit before interest earned on excess treasury cash (not in the rows)
  nim    reported NIM (banks only; on NET earning assets, as the report computes it)
  netrev rev - int (all six; the only margin the non-bank lenders carry, since TALN/STRK/POOL report no NIM)
Regressors (pp): pol_ema = policyRateEma reconstructed from the quarterly spot `pol` samples with the engine's own
0.25 y EMA (weight 1 - e^-1 per quarter, opened at 0.034) -- the rows record the spot rate only; slope = y10 EMA - y2
EMA (the pair the bank model reads); gap = outputGapEma. OLS pooled across seeds, CR1 standard errors clustered by
seed (G = 16), plus the same fit with seed fixed effects.
"""
import glob, json, math, re, statistics as st

H = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/bank_credit/runs/qw'
LENDERS = ['LAKE', 'RIVR', 'PLVR', 'TALN', 'STRK', 'POOL']
BANKS = {'LAKE', 'RIVR', 'PLVR'}
SEEDS = range(1, 17)
EMA_W = 1.0 - math.exp(-0.25 / 0.25)   # MacroAggregateSubsystem::STANDARD_EMA_HORIZON_YEARS = 0.25, sampled quarterly
OPEN_POL = 0.034                       # MacroStateDTO::OPENING_POLICY_RATE
WORST = 8


def load():
    out = {}
    for f in glob.glob(f'{H}/after-*.jsonl'):
        m = re.search(r'/after-(\d+)\.jsonl$', f)
        if m and int(m.group(1)) in SEEDS:
            out[int(m.group(1))] = json.loads(open(f).read().strip().splitlines()[-1])
    return out


def rows(rec, t):
    q = rec['final'][t]['q']
    ema, out = OPEN_POL, []
    for x in q:
        ema += EMA_W * (x['pol'] - ema)
        ea = x['ea']
        if ea <= 0:
            continue
        a = 400.0 / ea
        r = {
            'rev': a * x['rev'], 'int': a * x['int'], 'opex': a * (x['rev'] - x['ebit'] - x['prov']),
            'ppnr': a * (x['ebit'] + x['prov'] - x['int']), 'netrev': a * (x['rev'] - x['int']),
            'prov': a * x['prov'],
            'pol_ema': 100 * ema, 'pol': 100 * x['pol'], 'slope': 100 * (x['y10'] - x['y2']), 'gap': 100 * x['gap'],
        }
        if t in BANKS and x['nim'] is not None:
            r['nim'] = 100 * x['nim']
            sq = x['nii'] - x['int'] - x['nim'] * (ea - x['allow']) / 4.0
            r['sqz'] = a * sq
            r['opex_x'] = r['opex'] - r['sqz']
        out.append(r)
    return out


# --- small dense linear algebra (no numpy in the sandbox) ---
def inv(m):
    n = len(m)
    a = [list(r) + [1.0 if i == j else 0.0 for j in range(n)] for i, r in enumerate(m)]
    for c in range(n):
        p = max(range(c, n), key=lambda r: abs(a[r][c]))
        a[c], a[p] = a[p], a[c]
        pv = a[c][c]
        a[c] = [v / pv for v in a[c]]
        for r in range(n):
            if r != c:
                f = a[r][c]
                a[r] = [v - f * w for v, w in zip(a[r], a[c])]
    return [r[n:] for r in a]


def ols_cluster(data, y, xs, fe=False):
    """data: list of (seed, row). Returns coef, CR1 se (clustered by seed), n, G."""
    obs = [(s, r) for s, r in data if y in r and all(k in r for k in xs)]
    if fe:  # within transformation by seed
        by = {}
        for s, r in obs:
            by.setdefault(s, []).append(r)
        mean = {s: {k: st.mean(r[k] for r in rs) for k in [y] + xs} for s, rs in by.items()}
        X = [[r[k] - mean[s][k] for k in xs] for s, r in obs]
        Y = [r[y] - mean[s][y] for s, r in obs]
    else:
        X = [[1.0] + [r[k] for k in xs] for s, r in obs]
        Y = [r[y] for s, r in obs]
    S = [s for s, r in obs]
    n, k = len(Y), len(X[0])
    XtX = [[sum(X[i][a] * X[i][b] for i in range(n)) for b in range(k)] for a in range(k)]
    Xty = [sum(X[i][a] * Y[i] for i in range(n)) for a in range(k)]
    B = inv(XtX)
    beta = [sum(B[a][b] * Xty[b] for b in range(k)) for a in range(k)]
    e = [Y[i] - sum(X[i][a] * beta[a] for a in range(k)) for i in range(n)]
    G = sorted(set(S))
    meat = [[0.0] * k for _ in range(k)]
    for g in G:
        u = [sum(X[i][a] * e[i] for i in range(n) if S[i] == g) for a in range(k)]
        for a in range(k):
            for b in range(k):
                meat[a][b] += u[a] * u[b]
    kk = k + (len(G) if fe else 0)
    c = len(G) / (len(G) - 1) * (n - 1) / (n - kk)
    V = [[c * sum(B[a][i] * meat[i][j] * B[j][b] for i in range(k) for j in range(k)) for b in range(k)] for a in range(k)]
    se = [math.sqrt(V[a][a]) for a in range(k)]
    if not fe:
        beta, se = beta[1:], se[1:]
    ybar = st.mean(Y)
    r2 = 1 - sum(v * v for v in e) / sum((v - ybar) ** 2 for v in Y)
    return beta, se, n, len(G), r2


def main():
    recs = load()
    seeds = sorted(recs)
    r0 = recs[seeds[0]]
    print(f'Lender pre-provision economics vs rates, slope, cycle. runs/qw/after-*: seeds {seeds[0]}-{seeds[-1]} '
          f'(n={len(seeds)}), {r0["years"]} y, tpy {r0["tpy"]}, replay {r0["replay"]}')
    print('All rates % of gross earning assets, annualized. Regressors in pp. SE clustered by seed (CR1).')
    data = {t: [(s, r) for s in seeds for r in rows(recs[s], t)] for t in LENDERS}
    dead = {t: sum(1 for s in seeds if recs[s]['final'][t]['dead']) for t in LENDERS}

    print('\n1. LEVELS: pooled mean / sd of lender-quarters; [se] = sd of the 16 seed means / 4')
    keys = [('rev', 'revenue'), ('int', 'interest expense'), ('opex', 'opex (rev-EBIT-prov)'), ('sqz', '  of which NIM squeeze'),
            ('ppnr', 'PPNR (EBIT+prov-int)'), ('nim', 'reported NIM (net EA)'), ('netrev', 'rev - int'), ('prov', 'provision')]
    print(f'{"":24s}' + ''.join(f'{t:>22s}' for t in LENDERS))
    for k, lab in keys:
        cells = []
        for t in LENDERS:
            v = [r[k] for s, r in data[t] if k in r]
            if not v:
                cells.append('n/a')
                continue
            sm = [st.mean(r[k] for s2, r in data[t] if s2 == s and k in r) for s in seeds]
            cells.append(f'{st.mean(v):.2f}/{st.stdev(v):.2f} [{st.stdev(sm) / math.sqrt(len(sm)):.2f}]')
        print(f'{lab:24s}' + ''.join(f'{c:>22s}' for c in cells))
    print(f'{"lender-quarters":24s}' + ''.join(f'{len(data[t]):>22d}' for t in LENDERS))
    print(f'{"failed seeds":24s}' + ''.join(f'{dead[t]:>22d}' for t in LENDERS))
    print(f'{"regressor mean/sd":24s} pol_ema {st.mean(r["pol_ema"] for s, r in data["LAKE"]):.2f}/{st.stdev([r["pol_ema"] for s, r in data["LAKE"]]):.2f}'
          f'  slope {st.mean(r["slope"] for s, r in data["LAKE"]):.2f}/{st.stdev([r["slope"] for s, r in data["LAKE"]]):.2f}'
          f'  gap {st.mean(r["gap"] for s, r in data["LAKE"]):.2f}/{st.stdev([r["gap"] for s, r in data["LAKE"]]):.2f}')

    xs = ['pol_ema', 'slope', 'gap']
    print('\n2. OLS y = a + b1 pol_ema + b2 slope + b3 gap, pooled; coef (clustered se); FE = with seed fixed effects')
    deps = [('nim', 'NIM'), ('netrev', 'rev-int'), ('ppnr', 'PPNR/EA'), ('opex', 'opex/EA'), ('opex_x', 'opex ex-sqz'),
            ('sqz', 'NIM squeeze'), ('rev', 'revenue/EA'), ('int', 'int exp/EA')]
    print(f'{"lender":6s} {"y":12s} {"b_pol":>16s} {"b_slope":>16s} {"b_gap":>16s} {"R2":>5s} {"n_q":>5s} {"G":>3s} {"FE b_pol":>16s}')
    for t in LENDERS:
        for y, lab in deps:
            if not any(y in r for s, r in data[t]):
                continue
            b, se, n, g, r2 = ols_cluster(data[t], y, xs)
            bf, sf, *_ = ols_cluster(data[t], y, xs, fe=True)
            cells = ''.join(f'{f"{bb:+.3f} ({ss:.3f})":>16s}' for bb, ss in zip(b, se))
            print(f'{t:6s} {lab:12s} {cells} {r2:5.2f} {n:5d} {g:3d} {f"{bf[0]:+.3f} ({sf[0]:.3f})":>16s}')
        print()

    print('3. ONE-REGRESSOR SLOPES on pol_ema (pp of EA per pp of rate), clustered se; and on the spot policy rate')
    print(f'{"lender":6s} {"y":12s} {"on pol_ema":>16s} {"on spot pol":>16s}')
    for t in LENDERS:
        for y, lab in [('opex', 'opex/EA'), ('opex_x', 'opex ex-sqz'), ('nim', 'NIM'), ('netrev', 'rev-int'), ('ppnr', 'PPNR/EA')]:
            if not any(y in r for s, r in data[t]):
                continue
            b1, s1, *_ = ols_cluster(data[t], y, ['pol_ema'])
            b2, s2, *_ = ols_cluster(data[t], y, ['pol'])
            print(f'{t:6s} {lab:12s} {f"{b1[0]:+.3f} ({s1[0]:.3f})":>16s} {f"{b2[0]:+.3f} ({s2[0]:.3f})":>16s}')

    print(f'\n4. PPNR CYCLICALITY: per seed, mean PPNR/EA over the {WORST} lowest-gap quarters minus the seed median PPNR/EA')
    print(f'{"lender":6s} {"median":>8s} {"worst-8":>8s} {"diff (se)":>16s} {"diff/median %":>14s} {"range":>18s} {"gap worst-8":>12s}  n')
    for t in LENDERS:
        d, med, wv, rel, gw = [], [], [], [], []
        for s in seeds:
            rs = [r for s2, r in data[t] if s2 == s]
            m = st.median(r['ppnr'] for r in rs)
            w = st.mean(r['ppnr'] for r in sorted(rs, key=lambda r: r['gap'])[:WORST])
            gw.append(st.mean(r['gap'] for r in sorted(rs, key=lambda r: r['gap'])[:WORST]))
            d.append(w - m); med.append(m); wv.append(w); rel.append(100 * (w - m) / m)
        se = st.stdev(d) / math.sqrt(len(d))
        print(f'{t:6s} {st.mean(med):8.2f} {st.mean(wv):8.2f} {f"{st.mean(d):+.3f} ({se:.3f})":>16s} {st.mean(rel):+14.1f} '
              f'{f"[{min(d):+.2f}, {max(d):+.2f}]":>18s} {st.mean(gw):12.2f} {len(d):2d}')


if __name__ == '__main__':
    main()

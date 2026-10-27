#!/usr/bin/env python3
"""crash.py <arm> <seeds>: anatomy of the board's drawdowns from the valuation snapshots (runs/<arm>-<seed>.val.json)
and, when present, the quarterly macro rows (runs/<arm>-<seed>.macro.jsonl, `crash` arm).

Board, per quarter, over names live at both ends, weighted by float cap at the start of the quarter:
  index = float-weighted split-adjusted price index (price return); channels = weighted per-name quarter sums of the
  14 Probe price channels (they sum to the total log return incl. dividends); dlogF = weighted per-name d log fair value
  per share (split-adjusted); d g = index log price return - dlogF = sum(channels) - div - dlogF.
  Board sums: P = sum(price x shares), F = sum(F x shares), NI = sum(EPS x shares) (trailing); g_board = log(P / F).
Episodes: index drawdowns >= DD (env, 0.25). Neutral = last quarter before the peak with g_board at or below the seed's
median; build-up neutral -> peak, fall peak -> trough, rebound trough -> trough + 4 q.
Also: what the common component of the gap loads on (quarterly), and how reliable the rebound is without look-ahead.
"""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
ARM = sys.argv[1]
arg = sys.argv[2]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
DD = float(os.environ.get('DD', '0.25'))
CH = {'mkt': [0], 'jS': [6], 'sec': [1], 'idio': [2], 'jI': [5], 'pull': [3], 'flow': [9], 'fund': [10],
      'news': [7, 8, 11, 12, 13], 'brk': [4]}


def ols(x, y):
    mx, my = st.fmean(x), st.fmean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    if sxx <= 0 or syy <= 0:
        return float('nan'), float('nan')
    b = sum((a - mx) * (c - my) for a, c in zip(x, y)) / sxx
    return b, 1 - sum((c - my - b * (a - mx)) ** 2 for a, c in zip(x, y)) / syy


def mols(X, y):
    k = len(X[0]); m = k + 1; n = len(y)
    A = [[1.0] + list(r) for r in X]
    M = [[sum(A[i][p] * A[i][q] for i in range(n)) for q in range(m)] + [sum(A[i][p] * y[i] for i in range(n))] for p in range(m)]
    for c in range(m):
        piv = max(range(c, m), key=lambda r: abs(M[r][c])); M[c], M[piv] = M[piv], M[c]
        for r in range(m):
            if r != c:
                f = M[r][c] / M[c][c]; M[r] = [a - f * b for a, b in zip(M[r], M[c])]
    beta = [M[p][m] / M[p][p] for p in range(m)]
    res = [y[i] - sum(beta[p] * A[i][p] for p in range(m)) for i in range(n)]
    my = st.fmean(y)
    r2 = 1 - sum(r * r for r in res) / sum((v - my) ** 2 for v in y)
    return beta[1:], r2


def board(path):
    v = json.load(open(path))
    rows = v['rows']
    out = []
    prev = None
    lidx = 0.0
    for t, names in rows:
        rec = {'t': t, 'P': sum(r[1] * r[4] for r in names.values()), 'F': sum(r[2] * r[4] for r in names.values()),
               'NI': sum(r[3] * r[4] for r in names.values()), 'n': len(names)}
        if prev is not None:
            W = 0.0; ret = 0.0; ch = {k: 0.0 for k in CH}; dF = 0.0; dP = 0.0
            for n, r in names.items():
                p = prev.get(n)
                if not p or p[0] <= 0 or r[0] <= 0 or p[2] <= 0 or r[2] <= 0:
                    continue
                w = p[5]
                W += w
                ret += w * (r[0] / p[0] - 1)
                dP += w * math.log(r[0] / p[0])
                dF += w * math.log((r[2] * r[0] / r[1]) / (p[2] * p[0] / p[1]))
                for k, cols in CH.items():
                    ch[k] += w * sum(r[6 + c] for c in cols)
            if W > 0:
                lidx += math.log(1 + ret / W)
                rec.update({'dP': dP / W, 'dF': dF / W, 'ch': {k: x / W for k, x in ch.items()}})
                rec['div'] = sum(rec['ch'].values()) - rec['dP']
        rec['L'] = lidx
        rec['g'] = math.log(rec['P'] / rec['F'])
        out.append(rec)
        prev = names
    return out


def macro(path):
    if not os.path.exists(path):
        return {}
    m = {}
    for line in open(path):
        r = json.loads(line)
        m[round(r['t'] * 4)] = r
    return m


def seg(B, a, b, M):
    """Sums over quarters a+1 .. b."""
    s = {'dlogIdx': B[b]['L'] - B[a]['L'], 'dlogF': sum(B[j]['dF'] for j in range(a + 1, b + 1)),
         'dg': sum(B[j]['dP'] - B[j]['dF'] for j in range(a + 1, b + 1)), 'div': sum(B[j]['div'] for j in range(a + 1, b + 1)),
         'dlogNI': math.log(B[b]['NI'] / B[a]['NI']) if B[a]['NI'] > 0 and B[b]['NI'] > 0 else float('nan'),
         'dlogF_board': math.log(B[b]['F'] / B[a]['F']), 'g0': B[a]['g'], 'g1': B[b]['g'], 'q': b - a}
    for k in CH:
        s[k] = sum(B[j]['ch'][k] for j in range(a + 1, b + 1))
    s['dlogF/NI'] = s['dlogF_board'] - s['dlogNI']
    qa, qb = round(B[a]['t'] * 4), round(B[b]['t'] * 4)
    if qa in M and qb in M:
        for k in ('y10', 'credit', 'gap', 'vol', 'erp', 'mood', 'policy', 'infl', 'tips'):
            if k in M[qa] and k in M[qb]:
                s['d_' + k] = M[qb][k] - M[qa][k]
        s['crisis'] = max(M[q].get('crisis', 0) for q in range(qa, qb + 1) if q in M)
    return s


episodes = []
quarters = []
for sd in seeds:
    B = board(f'{H}/runs/{ARM}-{sd}.val.json')
    M = macro(f'{H}/runs/{ARM}-{sd}.macro.jsonl')
    q0 = next(i for i, r in enumerate(B) if r['t'] >= 1.0 - 1e-6)
    gmed = st.median(r['g'] for r in B[q0:])
    # drawdowns on the index (log), from year 1
    pk = q0
    i = q0
    while i < len(B):
        if B[i]['L'] >= B[pk]['L']:
            pk = i; i += 1; continue
        if B[i]['L'] - B[pk]['L'] <= math.log(1 - DD):
            # find the trough before recovery to the peak
            tr = i; j = i
            while j < len(B) and B[j]['L'] < B[pk]['L']:
                if B[j]['L'] < B[tr]['L']:
                    tr = j
                j += 1
            nb = pk
            while nb > q0 and B[nb]['g'] > gmed:
                nb -= 1
            crossing = i
            episodes.append({'seed': sd, 'peak': B[pk]['t'], 'trough': B[tr]['t'], 'neutral': B[nb]['t'], 'dd': math.exp(B[tr]['L'] - B[pk]['L']) - 1,
                             'build': seg(B, nb, pk, M), 'fall': seg(B, pk, tr, M),
                             'reb': seg(B, tr, min(tr + 4, len(B) - 1), M) if tr + 4 < len(B) else None,
                             'from_cross': (math.exp(B[crossing + 4]['L'] - B[crossing]['L']) - 1) if crossing + 4 < len(B) else None,
                             'gmed': gmed})
            pk = j if j < len(B) else len(B) - 1
            i = j
            continue
        i += 1
    for k in range(q0 + 1, len(B)):
        r = B[k]
        q = round(r['t'] * 4)
        quarters.append({'seed': sd, 'k': k, 'g': B[k - 1]['g'], 'gmed': gmed, 'dP': r['dP'], 'dF': r['dF'], 'mkt': r['ch']['mkt'] + r['ch']['jS'],
                         'pull': r['ch']['pull'], 'next4': (B[k + 3]['L'] - B[k - 1]['L']) if k + 3 < len(B) else None,
                         'm': (M.get(q), M.get(q - 1))})

fmt = lambda x: '   nan' if x is None or x != x else f'{x:+.2f}'
print(f'## Crash anatomy, {ARM}, seeds {seeds}, drawdowns >= {DD:.0%} on the float-weighted index (year 1 skipped)')
print(f'{len(episodes)} episodes in {len(seeds) * 19} seed-years')
cols = ['dlogIdx', 'dlogF', 'dg', 'mkt', 'jS', 'sec', 'idio', 'jI', 'pull', 'flow', 'fund', 'news', 'brk', 'div', 'dlogNI', 'dlogF/NI', 'd_y10', 'd_credit', 'd_gap', 'd_vol', 'd_erp', 'd_mood']
print('seed peak trough dd | phase q g0->g1 | ' + ' '.join(f'{c:>8s}' for c in cols))
for e in episodes:
    for ph in ('build', 'fall', 'reb'):
        s = e[ph]
        if s is None:
            continue
        print(f"{e['seed']:>2} {e['peak']:5.2f} {e['trough']:5.2f} {e['dd']:+.0%} | {ph:5s} {s['q']:2d} {s['g0']:+.2f}->{s['g1']:+.2f} | "
              + ' '.join(f"{fmt(s.get(c)):>8s}" for c in cols))
# means by phase
print('mean by phase (log units; macro deltas in level units)')
for ph in ('build', 'fall', 'reb'):
    ss = [e[ph] for e in episodes if e[ph] is not None]
    print(f'  {ph:5s} n={len(ss):2d} q={st.fmean(s["q"] for s in ss):4.1f} | ' + ' '.join(
        f'{c}={st.fmean(s[c] for s in ss if s.get(c) is not None and s.get(c) == s.get(c)):+.3f}' for c in cols if any(s.get(c) is not None for s in ss)))

# common component of the gap: quarterly board market channel (mkt + jS) and dF on macro changes
Q = [q for q in quarters if q['m'][0] and q['m'][1]]
if Q:
    keys = ['y10', 'credit', 'gap', 'vol', 'erp', 'mood']
    X = [[q['m'][0][k] - q['m'][1][k] for k in keys] for q in Q]
    for lab, ys in (('market channel (mkt+jS)', [q['mkt'] for q in Q]), ('d log F', [q['dF'] for q in Q]),
                    ('d log P (index)', [q['dP'] for q in Q]), ('d g', [q['dP'] - q['dF'] for q in Q])):
        b, r2 = mols(X, ys)
        print(f'quarterly {lab:24s} on d(' + ','.join(keys) + '): ' + ' '.join(f'{k} {v:+.2f}' for k, v in zip(keys, b)) + f'  R2 {r2:.2f}  sd {st.pstdev(ys):.3f}')
    # single-factor correlations
    for k in keys:
        x = [q['m'][0][k] - q['m'][1][k] for q in Q]
        print(f'  corr with d{k:7s}: market channel {ols(x, [q["mkt"] for q in Q])[1] ** 0.5 * (1 if ols(x, [q["mkt"] for q in Q])[0] > 0 else -1):+.2f}, '
              f'd log F {ols(x, [q["dF"] for q in Q])[1] ** 0.5 * (1 if ols(x, [q["dF"] for q in Q])[0] > 0 else -1):+.2f}, '
              f'd g {ols(x, [q["dP"] - q["dF"] for q in Q])[1] ** 0.5 * (1 if ols(x, [q["dP"] - q["dF"] for q in Q])[0] > 0 else -1):+.2f}')
    xl = [q['m'][1].get('mz_latent', 0.0) for q in Q]
    print(f'  market channel vs the market factor latent at the quarter start: corr {ols(xl, [q["mkt"] for q in Q])[1] ** 0.5:.2f}')

# rebound reliability without look-ahead
allnext = [q['next4'] for q in quarters if q['next4'] is not None]
print(f'unconditional next-4q index log return: mean {st.fmean(allnext):+.3f}, share > 0 {sum(1 for x in allnext if x > 0) / len(allnext):.0%}, n {len(allnext)}')
for thr in (-0.10, -0.20, -0.30):
    sel = [q['next4'] for q in quarters if q['next4'] is not None and q['g'] - q['gmed'] <= thr]
    if sel:
        print(f'  board g at least {-thr:.2f} below its median: next-4q mean {st.fmean(sel):+.3f}, share > 0 {sum(1 for x in sel if x > 0) / len(sel):.0%}, n {len(sel)}')
cross = [e['from_cross'] for e in episodes if e['from_cross'] is not None]
if cross:
    print(f'  from the quarter the index first closes {DD:.0%} below its peak: next-4q mean {st.fmean(math.log(1 + c) for c in cross):+.3f}, share > 0 {sum(1 for c in cross if c > 0) / len(cross):.0%}, n {len(cross)}')
trough = [e['reb']['dlogIdx'] for e in episodes if e['reb']]
if trough:
    print(f'  from the trough (look-ahead): next-4q mean {st.fmean(trough):+.3f}, share > 0 {sum(1 for x in trough if x > 0) / len(trough):.0%}, n {len(trough)}')
gx = [q['g'] - q['gmed'] for q in quarters if q['next4'] is not None]
b, r2 = ols(gx, allnext)
print(f'  next-4q index log return on board g (demeaned per seed): slope {b:+.2f}, R2 {r2:.2f}, n {len(gx)}')

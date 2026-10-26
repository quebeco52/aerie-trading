#!/usr/bin/env python3
"""analyse.py [seeds] [A] [B]: segment rework phase 2, B - A paired (default head seg). Prints the final tables only.

Relative outlook of a segment at a report = its industry belief - log(sum share * exp(belief)) at the shares filed the
report before (computed the same way in both arms, so HEAD is the no-tilt control). The tilt it drives applies at the
next report, so corr(d share_k, rel_{k-1}) is the lagged test.
"""
import json, math, os, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt, mean_se

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1] if len(sys.argv) > 1 else '1-8'
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('head', 'seg')
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']
BAD = set(ORDER[ORDER.index('B'):])
TICKERS = ['SKYL', 'TERN', 'ERNE', 'WEAV', 'JDAW', 'BIRD', 'RAVN', 'OWLS', 'TRIV']


def corr(x, y):
    if len(x) < 4:
        return float('nan')
    mx, my = st.fmean(x), st.fmean(y)
    sx = math.sqrt(sum((a - mx) ** 2 for a in x))
    sy = math.sqrt(sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / (sx * sy) if sx > 0 and sy > 0 else float('nan')


def blend(shares, beliefs):
    w = sum(s for k, s in shares.items() if s > 0 and k in beliefs)
    return math.log(sum(s * math.exp(beliefs[k]) for k, s in shares.items() if s > 0 and k in beliefs) / w) if w > 0 else beliefs.get('main', 0.0)


def load(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').read().splitlines()[-1])
    k = json.load(open(f'{H}/runs/{arm}-{s}.idle.json'))
    g = json.load(open(f'{H}/runs/{arm}-{s}.seg.json'))
    return r, k, g


def board(r, k):
    out = {}
    fin = r['final']
    out['cap y20 ($tn)'] = sum(f['q'][-1]['price'] * f['q'][-1]['shares'] for f in fin.values() if f['q'] and not f['dead']) / 1e12
    pe = [x['price'] / x['eps'] for f in fin.values() for x in f['q'] if 10 < x['t'] <= 20 and x['eps'] > 0]
    out['median P/E y10-20'] = st.median(pe)
    rv = [math.sqrt(a / n * k['tpy']) for tk, (a, n, c, _) in k['rv'].items() if n >= k['tpy'] and not fin[tk]['dead']]
    out['median realised vol (split-adj)'] = st.median(rv)
    out['defaults+deaths'] = len(r['defaults']) + len(r['dead'])
    allq = [x for f in fin.values() for x in f['q']]
    out['B-or-worse share'] = sum(1 for x in allq if x['rating'] in BAD) / len(allq)
    nd = []
    for f in fin.values():
        if not f['q'] or f['q'][0]['fin']:
            continue
        y = [x for x in f['q'] if 19 < x['t'] <= 20]
        e = sum(x['ebitda'] for x in y)
        if len(y) >= 4 and e > 0:
            nd.append((y[-1]['debt'] - y[-1]['cash']) / e)
    out['median ND/EBITDA y20'] = st.median(nd)
    return out


def segs(g):
    by = {}
    for row in g['rows']:
        by.setdefault(row['tk'], []).append(row)
    return by


def ticker_stats(rows, r, tk):
    out = {}
    q = r['final'][tk]['q']
    ttm = lambda t0, t1: sum(x['rev'] for x in q if t0 < x['t'] <= t1)
    out['rev'] = ttm(19, 20)
    out['rev1'] = ttm(0, 1.01)
    out['om'] = st.fmean(x['om'] for x in q) if q else float('nan')
    pe = [x['price'] / x['eps'] for x in q if 10 < x['t'] <= 20 and x['eps'] > 0]
    out['pe'] = st.median(pe) if pe else float('nan')
    out['events'] = (tk in (r['defaults'] or {})) + sum(1 for e in r['reorgs'] if e['t'] == tk) + (tk in r['dead'])
    keys = [x for x in rows[0]['beliefs'] if x != 'main'] if rows else []
    out['keys'] = keys
    # share series from segment revenue (both arms; the HEAD arm files no shares)
    sh = [{kk: v / sum(row['segrev'].values()) for kk, v in row['segrev'].items()} if sum(row['segrev'].values()) > 0 else {} for row in rows]
    out['share_end'] = {kk: st.fmean(s.get(kk, 0.0) for s, row in zip(sh, rows) if row['t'] > 19) for kk in keys}
    out['share_path'] = {kk: [st.fmean([s.get(kk, 0.0) for s, row in zip(sh, rows) if y - 1 < row['t'] <= y] or [float('nan')]) for y in (1, 5, 10, 15, 20)] for kk in keys}
    out['corr'] = {}
    for kk in keys:
        ds, rel = [], []
        for i in range(1, len(rows)):
            if not sh[i - 1] or not sh[i]:
                continue
            b = blend(sh[i - 1], rows[i - 1]['beliefs'])
            ds.append(sh[i].get(kk, 0.0) - sh[i - 1].get(kk, 0.0))
            rel.append(rows[i - 1]['beliefs'][kk] - b)
        out['corr'][kk] = corr(rel, ds)
        out.setdefault('rel', {})[kk] = st.fmean(rel[len(rel) // 2:]) if rel else float('nan')
    # revenue yoy log growth vs 4q change in the computed blend (OWLS/TRIV question)
    gy, db = [], []
    for i in range(4, len(rows)):
        if rows[i]['rev'] > 0 and rows[i - 4]['rev'] > 0 and sh[i - 4] and sh[i]:
            gy.append(math.log(rows[i]['rev'] / rows[i - 4]['rev']))
            db.append(blend(sh[i - 1], rows[i - 1]['beliefs']) - blend(sh[i - 5] if i >= 5 else sh[0], rows[i - 5]['beliefs'] if i >= 5 else rows[0]['beliefs']))
    out['corr_rev_blend'] = corr(db, gy)
    return out


def checks(rows):
    c = {'sum_share_err': 0.0, 'segrev_err': 0.0, 'tilt_min': float('inf'), 'tilt_max': 0.0, 'blend_out': 0, 'blend_n': 0, 'blend_err': 0.0}
    for row in rows:
        if row['shares']:
            c['sum_share_err'] = max(c['sum_share_err'], abs(sum(row['shares'].values()) - 1.0))
        if row['rev'] > 0:
            c['segrev_err'] = max(c['segrev_err'], abs(sum(row['segrev'].values()) - row['rev']) / row['rev'])
        for v in row['tilts'].values():
            c['tilt_min'], c['tilt_max'] = min(c['tilt_min'], v), max(c['tilt_max'], v)
        if row['blend'] is not None and row['tilts']:
            bl = list(row['beliefs'].values())
            c['blend_n'] += 1
            c['blend_out'] += not (min(bl) - 1e-9 <= row['blend'] <= max(bl) + 1e-9)
    return c


D = {arm: {s: load(arm, s) for s in seeds} for arm in (A, B)}
print(f'## 1. Board ({B} - {A}, paired, n={len(seeds)})')
print(f'quantity | {A} | {B} | diff (se) t n')
bA = {s: board(*D[A][s][:2]) for s in seeds}
bB = {s: board(*D[B][s][:2]) for s in seeds}
for key in bA[seeds[0]]:
    a, b = [bA[s][key] for s in seeds], [bB[s][key] for s in seeds]
    print(f'{key} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')

T = {arm: {s: segs(D[arm][s][2]) for s in seeds} for arm in (A, B)}
S = {arm: {s: {tk: ticker_stats(T[arm][s].get(tk, []), D[arm][s][0], tk) for tk in TICKERS} for s in seeds} for arm in (A, B)}

print(f'\n## 2a. Mix response: corr(d share_k, rel outlook_k-1) per seed, mean (se); share at y19-20, {B} - {A}')
print(f'ticker:segment | corr {A} | corr {B} | mean rel outlook y10-20 {B} | share y20 {A} | share y20 {B} | diff (se) t n')
for tk in TICKERS:
    for kk in S[A][seeds[0]][tk]['keys']:
        ca = [S[A][s][tk]['corr'][kk] for s in seeds if not math.isnan(S[A][s][tk]['corr'][kk])]
        cb = [S[B][s][tk]['corr'][kk] for s in seeds if not math.isnan(S[B][s][tk]['corr'][kk])]
        ma, mb = mean_se(ca) if len(ca) > 1 else (len(ca), float('nan'), float('nan')), mean_se(cb) if len(cb) > 1 else (len(cb), float('nan'), float('nan'))
        a = [S[A][s][tk]['share_end'][kk] for s in seeds]
        b = [S[B][s][tk]['share_end'][kk] for s in seeds]
        print(f'{tk}:{kk} | {ma[1]:+.3f} ({ma[2]:.3f}) | {mb[1]:+.3f} ({mb[2]:.3f}) | {st.fmean(S[B][s][tk]['rel'][kk] for s in seeds):+.3f} | {st.fmean(a):.3f} | {st.fmean(b):.3f} | {fmt(paired(a, b), 3)}')

print('\n## 2b. Identities and 4. oddities (segment arm, all seeds)')
allrows = [row for s in seeds for row in D[B][s][2]['rows']]
c = checks(allrows)
lk = {kk: sum(D[B][s][2]['leaks'][kk] for s in seeds) for kk in ('momentum', 'ledger', 'checked')}
lkA = {kk: sum(D[A][s][2]['leaks'][kk] for s in seeds) for kk in ('momentum', 'ledger', 'checked')}
print(f'max |sum shares - 1| {c["sum_share_err"]:.2e}; max |sum segment rev - rev|/rev {c["segrev_err"]:.2e}')
print(f'unsegmented reports checked {lk["checked"]}: with a segment key {lk["momentum"]}, ledger gap {lk["ledger"]} ({A}: {lkA["momentum"]}, {lkA["ledger"]})')
print(f'tilt range {c["tilt_min"]:.3f} - {c["tilt_max"]:.3f}; blend outside [min, max] of segment beliefs: {c["blend_out"]} of {c["blend_n"]}')
for tk in TICKERS:
    rows = [row for s in seeds for row in T[B][s].get(tk, [])]
    tl = [v for row in rows for v in row['tilts'].values()]
    lg = [row['lgap'] for row in rows if row['lgap'] is not None]
    print(f'{tk}: tilts p1/p50/p99 {sorted(tl)[len(tl) // 100]:.3f}/{st.median(tl):.3f}/{sorted(tl)[-1 - len(tl) // 100]:.3f} max {max(tl):.3f}; ledger gap rows {len(lg)}' + (f' range {min(lg):+.3f}..{max(lg):+.3f}' if lg else ''))

print(f'\n## 2c. SKYL satellite internet share by year (1, 5, 10, 15, 20), mean over seeds; {B} - {A} at y20')
for arm in (A, B):
    p = [[S[arm][s]['SKYL']['share_path']['satellite_internet'][i] for s in seeds] for i in range(5)]
    print(f'{arm} | ' + ' | '.join(f'{st.fmean(x):.3f}' for x in p))

print(f'\n## 2d/3. Nine companies, {B} - {A} paired')
print('ticker | log rev y20 ratio (se) t | rev growth/yr A / B | op margin diff | P/E y10-20 A / B | events A / B | corr(rev yoy, d blend) A / B')
for tk in TICKERS:
    lr = [math.log(S[B][s][tk]['rev'] / S[A][s][tk]['rev']) for s in seeds if S[A][s][tk]['rev'] > 0 and S[B][s][tk]['rev'] > 0]
    gA = [math.log(S[A][s][tk]['rev'] / S[A][s][tk]['rev1']) / 19 for s in seeds if S[A][s][tk]['rev'] > 0 and S[A][s][tk]['rev1'] > 0]
    gB = [math.log(S[B][s][tk]['rev'] / S[B][s][tk]['rev1']) / 19 for s in seeds if S[B][s][tk]['rev'] > 0 and S[B][s][tk]['rev1'] > 0]
    om = paired([S[A][s][tk]['om'] for s in seeds], [S[B][s][tk]['om'] for s in seeds])
    peA = [S[A][s][tk]['pe'] for s in seeds if not math.isnan(S[A][s][tk]['pe'])]
    peB = [S[B][s][tk]['pe'] for s in seeds if not math.isnan(S[B][s][tk]['pe'])]
    cA = [S[A][s][tk]['corr_rev_blend'] for s in seeds if not math.isnan(S[A][s][tk]['corr_rev_blend'])]
    cB = [S[B][s][tk]['corr_rev_blend'] for s in seeds if not math.isnan(S[B][s][tk]['corr_rev_blend'])]
    n, m, se = mean_se(lr)
    print(f'{tk} | {m:+.3f} ({se:.3f}) t {m / se if se else float("nan"):.1f} | {st.fmean(gA):+.4f} / {st.fmean(gB):+.4f} | {fmt(om, 4)} | '
          f'{st.median(peA) if peA else float("nan"):.1f} / {st.median(peB) if peB else float("nan"):.1f} | '
          f'{sum(S[A][s][tk]["events"] for s in seeds)} / {sum(S[B][s][tk]["events"] for s in seeds)} | '
          f'{st.fmean(cA) if cA else float("nan"):+.2f} / {st.fmean(cB) if cB else float("nan"):+.2f}')

#!/usr/bin/env python3
"""analyse3.py [seeds] [A] [B]: segment rework phase 3 (plant per segment), B - A paired (default head seg).

Reads runs/<arm>-<seed>.{jsonl,idle.json,seg.json}. Prints the final tables only. Section numbers follow the phase-3
question (board, identities, segments on their own markets, opening, segment-industry peers, the eight companies).
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
EIGHT = ['TERN', 'ERNE', 'WEAV', 'JDAW', 'BIRD', 'RAVN', 'OWLS', 'TRIV']
PEERS = ['CANV', 'PENG', 'HUMM', 'DIPR', 'KTWK', 'RIVE', 'SNDR', 'ALCA', 'LARK']
JOINT = {('TERN', 'air_cargo')}
nan = float('nan')
Y = float(os.environ.get('YEARS', 20))  # run length; windows below are the last year and y10-Y


def median(xs):
    xs = list(xs)
    return st.median(xs) if xs else nan


def corr(x, y):
    if len(x) < 4:
        return nan
    mx, my = st.fmean(x), st.fmean(y)
    sx = math.sqrt(sum((a - mx) ** 2 for a in x))
    sy = math.sqrt(sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / (sx * sy) if sx > 0 and sy > 0 else nan


def ms(xs):
    xs = [x for x in xs if not math.isnan(x)]
    m = mean_se(xs)
    return f'{m.mean:+.3f} ({m.se:.3f})' if len(xs) > 1 else 'n/a'


def load(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').read().splitlines()[-1])
    k = json.load(open(f'{H}/runs/{arm}-{s}.idle.json'))
    g = json.load(open(f'{H}/runs/{arm}-{s}.seg.json'))
    return r, k, g


def ttm(q, t0, t1):
    return sum(x['rev'] for x in q if t0 < x['t'] <= t1)


def events(r, tk):
    return (tk in (r['defaults'] or {})) + sum(1 for e in r['reorgs'] if e['t'] == tk) + (tk in r['dead'])


def nd_ebitda(q):
    y = [x for x in q if Y - 1 < x['t'] <= Y]
    e = sum(x['ebitda'] for x in y)
    return (y[-1]['debt'] - y[-1]['cash']) / e if len(y) >= 4 and e > 0 else nan


def board(r, k):
    out = {}
    fin = r['final']
    out['cap y20 ($tn)'] = sum(f['q'][-1]['price'] * f['q'][-1]['shares'] for f in fin.values() if f['q'] and not f['dead']) / 1e12
    out['median P/E y10-20'] = median([x['price'] / x['eps'] for f in fin.values() for x in f['q'] if Y / 2 < x['t'] <= Y and x['eps'] > 0])
    out['median realised vol (split-adj)'] = median([math.sqrt(a / n * k['tpy']) for tk, (a, n, c, _) in k['rv'].items() if n >= k['tpy'] and not fin[tk]['dead']])
    out['defaults+deaths'] = len(r['defaults']) + len(r['dead'])
    allq = [x for f in fin.values() for x in f['q']]
    out['B-or-worse share'] = sum(1 for x in allq if x['rating'] in BAD) / len(allq)
    out['median ND/EBITDA y20'] = median([v for f in fin.values() if f['q'] and not f['q'][0]['fin'] for v in [nd_ebitda(f['q'])] if not math.isnan(v)])
    cpi = sorted((row[0], row[5]) for row in k['rows'])
    c0 = st.fmean(c for t, c in cpi if t <= 1.0)
    c1 = st.fmean(c for t, c in cpi if t > Y - 1)
    r0 = sum(ttm(f['q'], 0, 1.0) for f in fin.values())
    r1 = sum(ttm(f['q'], Y - 1, Y) for f in fin.values())
    out['real revenue growth /yr'] = (math.log(r1 / r0) - math.log(c1 / c0)) / (Y - 1)
    return out


def by_ticker(g):
    out = {}
    for row in g['rows']:
        out.setdefault(row['tk'], []).append(row)
    return out


def ipl_by_quarter(k):
    """industry -> quarter index -> list of peer industry_price_level (firms outside the eight)."""
    out = {}
    for row in k['rows']:
        if row[1] in EIGHT or row[4] is None:
            continue
        out.setdefault(row[2], {}).setdefault(int(row[0] * 4), []).append(row[4])
    return out


D = {arm: {s: load(arm, s) for s in seeds} for arm in (A, B)}
T = {arm: {s: by_ticker(D[arm][s][2]) for s in seeds} for arm in (A, B)}

print(f'## 1. Board ({B} - {A}, paired, n={len(seeds)})')
print(f'quantity | {A} | {B} | diff (se) t n')
bA = {s: board(*D[A][s][:2]) for s in seeds}
bB = {s: board(*D[B][s][:2]) for s in seeds}
for key in bA[seeds[0]]:
    a, b = [bA[s][key] for s in seeds], [bB[s][key] for s in seeds]
    print(f'{key} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')

print(f'\n## 2. Identities ({B} arm, every report of the eight after the books open, all seeds)')
viol = {'sum kappa': 0.0, 'rev: |sum rec rev - 4 rev| / 4 rev': 0.0, 'cap: max |cap_s / sum cap - kappa_prev_s|': 0.0}
n_rep = n_rev = n_cap = tilt_left = zero_k = 0
off = set()
captot = []
for s in seeds:
    for tk in EIGHT:
        prev = None
        for row in T[B][s].get(tk, []):
            if row['kappa']:
                n_rep += 1
                viol['sum kappa'] = max(viol['sum kappa'], abs(sum(row['kappa'].values()) - 1.0))
                tilt_left += bool(row['tilts'])
                zero_k += sum(1 for v in row['kappa'].values() if v <= 0.0)
                ticks = {v['tick'] for v in row['recs'].values()}
                if len(ticks) == 1 and row['rev'] > 0:
                    n_rev += 1
                    viol['rev: |sum rec rev - 4 rev| / 4 rev'] = max(viol['rev: |sum rec rev - 4 rev| / 4 rev'], abs(sum(v['rev'] for v in row['recs'].values()) - 4 * row['rev']) / (4 * row['rev']))
                tot = sum(v['cap'] for v in row['recs'].values())
                if prev is not None and prev['kappa'] and tot > 0 and len(ticks) == 1:
                    n_cap += 1
                    # Segments whose industry clears no market (regulated, trading, holding) keep no plant on the
                    # ledger (capacity 0): the split is checked over the segments that do.
                    on = {seg: kp for seg, kp in prev['kappa'].items() if row['recs'].get(seg, {}).get('cap', 0.0) > 0}
                    off.update(f'{tk}:{seg}' for seg in prev['kappa'] if seg not in on)
                    for seg, kp in on.items():
                        c = row['recs'][seg]['cap']
                        viol['cap: max |cap_s / sum cap - kappa_prev_s|'] = max(viol['cap: max |cap_s / sum cap - kappa_prev_s|'], abs(c / tot - kp / sum(on.values())))
                    if row['ipl'] and row['idle'] is not None and row['idle'] < 1:
                        captot.append(tot * row['ipl'] * (1 - row['idle']) / (4 * row['rev']))
            prev = row
for key, v in viol.items():
    print(f'{key}: max {v:.2e}')
print(f'segments with no plant on the ledger (capacity 0, split checked over the rest): {sorted(off)}')
print(f'reports with books open {n_rep} (rev check {n_rev}, cap check {n_cap}); segments with kappa <= 0: {zero_k}; reports with a carry:segment_tilt key after opening: {tilt_left}')
if captot:
    cs = sorted(captot)
    print(f'context: sum cap x price x load / (4 rev) median {median(cs):.3f} (p5 {cs[len(cs) // 20]:.3f}, p95 {cs[-1 - len(cs) // 20]:.3f})')
for arm in (A, B):
    sl = [x for s in seeds for x in D[arm][s][2].get('slash', [])]
    bad = sorted({x for x in sl if x.split('|')[1].split('/')[0] not in EIGHT})
    print(f'{arm}: TICKER/seg records {len(sl)} over {len(seeds)} seeds, owner outside the eight: {len(bad)} {bad[:5]}')
lk = {kk: sum(D[B][s][2]['leaks'][kk] for s in seeds) for kk in ('momentum', 'ledger', 'checked')}
print(f'unsegmented reports {lk["checked"]}: with a carry:segment_* key {lk["momentum"]}')

print(f'\n## 3. Segments on their own markets ({B} arm)')
print('ticker:segment | kappa median y1 / y5 / y10 / y20 | corr(d4 log kappa, d4 belief own) | (… main) | corr(d4 log rec rev, d4 truth own) | (… main) | corr(rel sales/plant, next d log kappa ratio) | d log kappa ratio/q: peer P<1 vs >=1 (n)')
for tk in EIGHT:
    segs = sorted({seg for s in seeds for row in T[B][s].get(tk, []) for seg in row['kappa']}, key=lambda x: x != 'main')
    for seg in segs:
        path = []
        for y in (1, 5, 10, 20) if Y >= 20 else (1, Y):
            v = [st.fmean(vals) for s in seeds for vals in [[row['kappa'][seg] for row in T[B][s].get(tk, []) if row['kappa'] and y - 1 < row['t'] <= y]] if vals]
            path.append(median(v) if v else nan)
        ck_o, ck_m, cr_o, cr_m, cg = [], [], [], [], []
        for s in seeds:
            rows = [row for row in T[B][s].get(tk, []) if row['kappa'] and seg in row['recs'] and 'main' in row['recs']]
            x1, x2, y1, z1, z2, w1, gx, gy = [], [], [], [], [], [], [], []
            for i in range(4, len(rows)):
                a, b = rows[i], rows[i - 4]
                y1.append(math.log(a['kappa'][seg] / b['kappa'][seg]) if a['kappa'][seg] > 0 and b['kappa'][seg] > 0 else 0.0)
                x1.append(a['beliefs'].get(seg, 0.0) - b['beliefs'].get(seg, 0.0))
                x2.append(a['beliefs']['main'] - b['beliefs']['main'])
                if a['recs'][seg]['rev'] > 0 and b['recs'][seg]['rev'] > 0 and None not in (a['truths'].get(seg), b['truths'].get(seg)):
                    w1.append(math.log(a['recs'][seg]['rev'] / b['recs'][seg]['rev']))
                    z1.append(a['truths'][seg] - b['truths'][seg])
                    # main industry with no outlook (regulated, trading, holding): no main column
                    z2.append(a['truths']['main'] - b['truths']['main'] if None not in (a['truths'].get('main'), b['truths'].get('main')) else 0.0)
            ck_o.append(corr(x1, y1)); ck_m.append(corr(x2, y1))
            cr_o.append(corr(z1, w1)); cr_m.append(corr(z2, w1))
            if seg != 'main':
                for i in range(len(rows) - 1):
                    a, b = rows[i], rows[i + 1]
                    rs, rm = a['recs'][seg], a['recs']['main']
                    if min(rs['cap'], rm['cap'], rs['rev'], rm['rev'], b['kappa'].get(seg, 0.0), b['kappa'].get('main', 0.0), a['kappa'].get('main', 0.0)) <= 0:  # conglomerates' main holds no plant
                        continue
                    dk = math.log(b['kappa'][seg] / b['kappa']['main']) - math.log(a['kappa'][seg] / a['kappa']['main'])
                    gx.append(math.log((rs['rev'] / rs['cap']) / (rm['rev'] / rm['cap'])))
                    gy.append(dk)
                cg.append(corr(gx, gy))
        print(f'{tk}:{seg}{" (joint)" if (tk, seg) in JOINT else ""} | ' + ' / '.join(f'{x:.3f}' for x in path)
              + f' | {ms(ck_o)} | {ms(ck_m)} | {ms(cr_o)} | {ms(cr_m)} | ' + (ms(cg) if seg != 'main' else '-'))

# Glut test with peer prices: segment industry's peer median industry_price_level in the quarter.
print('\nglut test: mean d log(kappa_s / kappa_main) per report when the segment industry\'s peer median price level < 1 vs >= 1')
SEGIND = {}
# segment industries come from the plan; read them from the beliefs keys via the run's slash list
for s in seeds:
    for x in D[B][s][2].get('slash', []):
        ind, key = x.split('|')
        SEGIND[tuple(key.split('/'))] = ind
for (tk, seg), ind in sorted(SEGIND.items()):
    lo, hi = [], []
    for s in seeds:
        ipl = ipl_by_quarter(D[B][s][1]).get(ind, {})
        rows = [row for row in T[B][s].get(tk, []) if row['kappa'] and seg in row['kappa']]
        for i in range(len(rows) - 1):
            a, b = rows[i], rows[i + 1]
            if min(a['kappa'][seg], a['kappa'].get('main', 0.0), b['kappa'].get(seg, 0.0), b['kappa'].get('main', 0.0)) <= 0:  # conglomerates' main holds no plant
                continue
            p = ipl.get(int(a['t'] * 4)) or ipl.get(int(a['t'] * 4) - 1)
            if not p:
                continue
            dk = math.log(b['kappa'][seg] / b['kappa']['main']) - math.log(a['kappa'][seg] / a['kappa']['main'])
            (lo if median(p) < 1.0 else hi).append(dk)
    f = lambda xs: f'{st.fmean(xs) * 1e3:+.2f}e-3 (n {len(xs)})' if xs else 'n/a'
    print(f'{tk}:{seg} [{ind}] | P<1 {f(lo)} | P>=1 {f(hi)}')

print(f'\n## 4. At opening ({B} arm): median across seeds [min, max]')
print('ticker | segment a_s | firm addressable share before -> after | main record share before -> after | size penalty before -> after | flags')
for tk in EIGHT:
    op = {}
    for s in seeds:
        rows = T[B][s].get(tk, [])
        i = next((j for j, row in enumerate(rows) if row['kappa']), None)
        if i is None or i == 0:
            continue
        a, b = rows[i - 1], rows[i]
        for seg, rec in b['recs'].items():
            op.setdefault(seg, []).append(rec['as'])
        for key, va, vb in (('addr', a['addr'], b['addr']), ('mainas', a['recs'].get('main', {}).get('as'), b['recs'].get('main', {}).get('as')), ('pen', a['pen'], b['pen'])):
            if va is not None and vb is not None:
                op.setdefault(key + '0', []).append(va)
                op.setdefault(key + '1', []).append(vb)
    m = lambda k: f'{median(op[k]):.4f}' if op.get(k) else 'n/a'
    segtxt = ', '.join(f'{seg} {median(v):.4f} [{min(v):.4f}, {max(v):.4f}]' for seg, v in op.items() if seg not in ('addr0', 'addr1', 'mainas0', 'mainas1', 'pen0', 'pen1'))
    flags = [seg for seg, v in op.items() if seg not in ('addr0', 'addr1', 'mainas0', 'mainas1', 'pen0', 'pen1') and (max(v) > 0.5 or min(v) < 0.001)]
    print(f'{tk} | {segtxt} | {m("addr0")} -> {m("addr1")} | {m("mainas0")} -> {m("mainas1")} | {m("pen0")} -> {m("pen1")} | {flags or "-"}')

print(f'\n## 5. Segment-industry peers, {B} - {A} paired')
print('ticker | median price level A / B | diff (se) t | p5 price level A / B | diff (se) t | log rev y20 ratio (se) t | events A / B')
for tk in PEERS:
    v = {}
    for arm in (A, B):
        for s in seeds:
            p = sorted(row[4] for row in D[arm][s][1]['rows'] if row[1] == tk and row[4] is not None)
            v.setdefault(arm + 'm', []).append(median(p) if p else nan)
            v.setdefault(arm + 'p', []).append(p[len(p) // 20] if p else nan)
    lr = [math.log(ttm(D[B][s][0]['final'][tk]['q'], Y - 1, Y) / ttm(D[A][s][0]['final'][tk]['q'], Y - 1, Y)) for s in seeds
          if tk in D[A][s][0]['final'] and ttm(D[A][s][0]['final'][tk]['q'], Y - 1, Y) > 0 and ttm(D[B][s][0]['final'][tk]['q'], Y - 1, Y) > 0]
    ml = mean_se(lr)
    ev = [sum(events(D[arm][s][0], tk) for s in seeds) for arm in (A, B)]
    print(f'{tk} | {st.fmean(v[A + "m"]):.4f} / {st.fmean(v[B + "m"]):.4f} | {fmt(paired(v[A + "m"], v[B + "m"]), 4)} | {st.fmean(v[A + "p"]):.4f} / {st.fmean(v[B + "p"]):.4f} | {fmt(paired(v[A + "p"], v[B + "p"]), 4)} | '
          f'{ml.mean:+.3f} ({ml.se:.3f}) t {ml.mean / ml.se if ml.se else nan:.1f} n {ml.n} | {ev[0]} / {ev[1]}')

print(f'\n## 6. The eight, {B} - {A} paired')
print('ticker | log rev y20 ratio (se) t | op margin diff (se) t | P/E y10-20 median A / B diff (se) t | ND/EBITDA y20 diff (se) t | events A / B (seeds)')
for tk in EIGHT:
    q = {arm: {s: D[arm][s][0]['final'][tk]['q'] for s in seeds} for arm in (A, B)}
    lr = [math.log(ttm(q[B][s], Y - 1, Y) / ttm(q[A][s], Y - 1, Y)) for s in seeds if ttm(q[A][s], Y - 1, Y) > 0 and ttm(q[B][s], Y - 1, Y) > 0]
    ml = mean_se(lr)
    om = paired([st.fmean(x['om'] for x in q[A][s]) for s in seeds], [st.fmean(x['om'] for x in q[B][s]) for s in seeds])
    pe = {arm: [median(v) if v else nan for s in seeds for v in [[x['price'] / x['eps'] for x in q[arm][s] if Y / 2 < x['t'] <= Y and x['eps'] > 0]]] for arm in (A, B)}
    nd = paired({s: nd_ebitda(q[A][s]) for s in seeds if not math.isnan(nd_ebitda(q[A][s])) and not math.isnan(nd_ebitda(q[B][s]))}, {s: nd_ebitda(q[B][s]) for s in seeds})
    evs = {arm: [s for s in seeds if events(D[arm][s][0], tk)] for arm in (A, B)}
    print(f'{tk} | {ml.mean:+.3f} ({ml.se:.3f}) t {ml.mean / ml.se if ml.se else nan:.1f} n {ml.n} | {fmt(om, 4)} | {median([x for x in pe[A] if not math.isnan(x)]):.1f} / {median([x for x in pe[B] if not math.isnan(x)]):.1f} {fmt(paired(pe[A], pe[B]), 2)} | {fmt(nd, 3)} | {evs[A]} / {evs[B]}')

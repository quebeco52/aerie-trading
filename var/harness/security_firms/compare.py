#!/usr/bin/env python3
"""Paired arms for the revenue-mix overlap rule in IndustryShareLedger. Usage: compare.py [arm A] [arm B].
Default A = full (mixOverlap() = 1.0, the old rule), B = overlap (working tree); differences are B - A per seed.
Prints (a) the five security firms, (b) every firm in an industry with two or more listed firms, (c) board counts."""
import glob, json, math, statistics as st, sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt  # noqa: E402

A = sys.argv[1] if len(sys.argv) > 1 else 'full'
B = sys.argv[2] if len(sys.argv) > 2 else 'overlap'
here = Path(__file__).parent / 'runs'


def load(arm):
    out = {}
    for p in glob.glob(str(here / f'{arm}-s[0-9]*.json')):
        d = json.load(open(p))
        out[d['seed']] = d
    return out


RA, RB = load(A), load(B)
seeds = sorted(set(RA) & set(RB))
FIRMS = ['NCKR', 'WALL', 'NJAR', 'WATCH', 'OSPR']


def metrics(d, t):
    """Revenue growth per year (first to last full year), mean operating margin, mean |rival drain| per quarter."""
    rows = d['all'].get(t) or []
    if t in d['dead'] or len(rows) < 12:
        return None
    rev = [r[1] for r in rows]
    ny = len(rows) // 4
    ann = [sum(rev[4 * y:4 * y + 4]) for y in range(ny)]
    if ann[0] <= 0 or ann[-1] <= 0:
        return None
    return {'g': (ann[-1] / ann[0]) ** (1 / (ny - 1)) - 1, 'om': st.fmean(r[2] for r in rows),
            'drain': st.fmean(abs(r[3]) for r in rows)}


M = {arm: {s: {t: metrics(R[s], t) for t in R[s]['all']} for s in seeds} for arm, R in ((A, RA), (B, RB))}


def series(arm, t, k):
    return {s: M[arm][s][t][k] for s in seeds if M[arm][s].get(t) and M[A][s].get(t) and M[B][s].get(t)}


def ms(xs):
    xs = list(xs.values())
    return st.fmean(xs) if xs else float('nan')


print(f'arms {A} -> {B} (diff = {B} - {A}), seeds {seeds}, years {len(RA[seeds[0]]["q"]["NCKR"]) / 4:.0f}')
print('\n(a) security firms          ' + f'{A:>10s} {B:>10s}   diff (se) t n')
for t in FIRMS:
    for k, lab, sc in (('g', 'rev growth/yr %', 100), ('om', 'mean op margin %', 100), ('drain', 'mean |drain| bp/q', 1e4)):
        a, b = series(A, t, k), series(B, t, k)
        n, dm, se, tt = paired(a, b)
        print(f'{t:5s} {lab:20s} {ms(a) * sc:10.3f} {ms(b) * sc:10.3f}   {dm * sc:+.3f} ({se * sc:.3f}) t {tt:5.2f} n {n}')

# (b) industries with two or more listed firms on the seeded board
board = RA[seeds[0]]['board']
ind = {}
for t, b in board.items():
    ind.setdefault(b['industry'], []).append(t)
multi = sorted(t for ts in ind.values() if len(ts) >= 2 for t in ts)
sec_ind = board['WATCH']['industry']
res = []
for t in multi:
    a, b = series(A, t, 'g'), series(B, t, 'g')
    if len(a) < 2:
        continue
    n, dm, se, tt = paired(a, b)
    da, db = series(A, t, 'drain'), series(B, t, 'drain')
    res.append((t, board[t]['industry'], n, dm, se, tt, ms(da), ms(db), paired(da, db)))
out = [r for r in res if r[1] != sec_ind]
print(f'\n(b) multi-firm industries: {len(ind) and sum(1 for v in ind.values() if len(v) >= 2)} industries, {len(res)} firms '
      f'({len(out)} outside {sec_ind})')
for lab, rr in (('all multi-firm', res), ('outside security', out)):
    d = [r[3] for r in rr]
    big = max(rr, key=lambda r: abs(r[3]))
    bigt = max(rr, key=lambda r: abs(r[5]) if r[5] == r[5] else 0)
    print(f'  {lab:17s} median per-firm diff in rev growth {st.median(d) * 100:+.3f}pp/yr; mean |diff| {st.fmean(abs(x) for x in d) * 100:.3f}pp; '
          f'largest |diff| {big[0]} ({big[1]}) {big[3] * 100:+.3f}pp (se {big[4] * 100:.3f}, t {big[5]:.2f}); largest |t| {bigt[0]} t {bigt[5]:.2f}; '
          f'|t|>=2: {sum(1 for r in rr if abs(r[5]) >= 2)} of {len(rr)} (chance ~{0.05 * len(rr):.1f})')
    # Across-firm mean of the per-seed mean |drain|, paired by seed.
    def avg(arm):
        return {s: st.fmean(M[arm][s][r[0]]['drain'] for r in rr if M[arm][s].get(r[0]) and M[A][s].get(r[0]) and M[B][s].get(r[0])) for s in seeds}
    print(f'  {"":17s} mean |rival drain| bp/q: {A} {ms(avg(A)) * 1e4:.2f}, {B} {ms(avg(B)) * 1e4:.2f}, diff {fmt(paired(avg(A), avg(B)))} (x1e-4)')
    gm = lambda arm: {s: st.fmean(M[arm][s][r[0]]['g'] for r in rr if M[arm][s].get(r[0]) and M[A][s].get(r[0]) and M[B][s].get(r[0])) for s in seeds}
    print(f'  {"":17s} mean rev growth across firms: diff {fmt(paired(gm(A), gm(B)))}')
print('\n  per industry (outside security): industry firms | mean diff rev growth pp | mean |drain| bp A -> B')
byi = {}
for r in out:
    byi.setdefault(r[1], []).append(r)
for i, rr in sorted(byi.items()):
    print(f'  {i[:34]:34s} {len(rr):2d} | {st.fmean(r[3] for r in rr) * 100:+.3f} | {st.fmean(r[6] for r in rr) * 1e4:.2f} -> {st.fmean(r[7] for r in rr) * 1e4:.2f}')


def counts(d):
    dfl = d.get('defaults') or {}
    return {'deaths': len(d['dead']), 'defaults': sum(dfl.values()) if isinstance(dfl, dict) else len(dfl),
            'reorgs': len(d.get('reorgs') or []), 'distress': sum((d.get('distress_all') or {}).values())}


print('\n(c) board counts per seed-run        ' + f'{A:>8s} {B:>8s}   diff (se) t n')
for k in ('deaths', 'defaults', 'reorgs', 'distress'):
    a = {s: counts(RA[s])[k] for s in seeds}
    b = {s: counts(RB[s])[k] for s in seeds}
    print(f'  {k:32s} {ms(a):8.2f} {ms(b):8.2f}   {fmt(paired(a, b))}')

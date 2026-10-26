#!/usr/bin/env python3
"""wave2.py <seeds> A B [PL]: wave 2 of segments (B) against the tree before it (A); PL = arm with the ovr_pl
price/load log (for segment prices in industries with no listed firm). Prints the final tables only.

New companies: books-open year, kappa by segment y1/5/10/20, segment margins vs company margin, real revenue growth
both arms, P/E y10-20, cap y20, events and whole-segment sales; outlooks opened by wave 2 (first quarter per seed) and
the belief step of existing industries at that quarter vs their usual step and vs A; identities on every segmented
report; board paired; wave-1 caps; segment prices in the new industries (from PL).
"""
import json, math, os, re, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
PL = sys.argv[4] if len(sys.argv) > 4 else None
NEW = ['GDWT', 'TSTN', 'AVCT', 'HUMM', 'STAR', 'FULM', 'KSTL', 'GULL']
WAVE1 = ['SKYL', 'TERN', 'ERNE', 'WEAV', 'JDAW', 'BIRD', 'RAVN', 'OWLS', 'TRIV']
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']
BAD = set(ORDER[ORDER.index('B'):])
nan = float('nan')


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


def load(a, s):
    r = json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1])
    g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
    for row in g['rows']:
        for key in ('kappa', 'recs', 'smar', 'segrev'):
            row[key] = row.get(key) or {}
    k = json.load(open(f'{H}/runs/{a}-{s}.idle.json'))
    p = f'{H}/runs/{a}-{s}.outlook.json'
    o = json.load(open(p)) if os.path.exists(p) else None
    return r, g, k, o


D = {(a, s): load(a, s) for a in (A, B) + ((PL,) if PL else ()) for s in seeds}
rows = lambda a, s, tk: [r for r in D[a, s][1]['rows'] if r['tk'] == tk]
win = lambda rs, y: [r for r in rs if y - 1 < r['t'] <= y]


def cpi(a, s, y):
    return med([row[5] for row in D[a, s][2]['rows'] if y - 1 < row[0] <= y])


def rev(a, s, tk, y):
    return sum(x['rev'] for x in D[a, s][0]['final'][tk]['q'] if y - 1 < x['t'] <= y)


def growth(a, s, tk, y0, y1):
    r0, r1 = rev(a, s, tk, y0), rev(a, s, tk, y1)
    return (math.log(r1 / r0) - math.log(cpi(a, s, y1) / cpi(a, s, y0))) / (y1 - y0) if r0 > 0 and r1 > 0 else nan


def events(a, s, tk):
    r = D[a, s][0]
    return (tk in (r['defaults'] or {})) or (tk in r['dead']) or any(e['t'] == tk for e in r['reorgs'])


def cap20(a, s, tk):
    q = D[a, s][0]['final'][tk]['q']
    return q[-1]['price'] * q[-1]['shares'] / 1e9 if q and q[-1]['t'] > 19 else nan


print(f'## Wave 2 of segments, {B} vs {A}, n={len(seeds)} (medians over seeds)')
print('\n### 1. New companies')
for tk in NEW:
    opened = [min([r['t'] for r in rows(B, s, tk) if r['kappa']] or [nan]) for s in seeds]
    segs = sorted({k for s in seeds for r in rows(B, s, tk) for k in r['kappa']}, key=lambda k: k != 'main')
    kap = {seg: [med([st.fmean(r['kappa'].get(seg, 0.0) for r in win(rows(B, s, tk), y) if r['kappa']) for s in seeds if any(r['kappa'] for r in win(rows(B, s, tk), y))]) for y in (1, 5, 10, 20)] for seg in segs}  # reports with the books open
    mar = {seg: [med([med([r['smar'].get(seg) for r in win(rows(B, s, tk), y)]) for s in seeds]) for y in (1, 20)] for seg in segs}
    fmar = [med([med([r.get('fmar') for r in win(rows(B, s, tk), y)]) for s in seeds]) for y in (1, 20)]
    om = {a: med([st.fmean(x['om'] for x in D[a, s][0]['final'][tk]['q']) for s in seeds]) for a in (A, B)}
    g = {a: (med([growth(a, s, tk, 1, 10) for s in seeds]), med([growth(a, s, tk, 10, 20) for s in seeds])) for a in (A, B)}
    pe = {a: med([x['price'] / x['eps'] for s in seeds for x in D[a, s][0]['final'][tk]['q'] if 10 < x['t'] <= 20 and x['eps'] > 0]) for a in (A, B)}
    cp = {a: [cap20(a, s, tk) for s in seeds] for a in (A, B)}
    ev = {a: [s for s in seeds if events(a, s, tk)] for a in (A, B)}
    sales = [f"s{s} y{d['t']:.1f} {m.group(1)}" for s in seeds for d in D[B, s][1].get('deals', []) if d['tk'] == tk
             for m in [re.search(r' sold its (.+?) business for ', d['d'])] if m and 'division' not in m.group(1)]
    flags = []
    for seg in segs:
        if seg != 'main' and kap[seg][0] == kap[seg][0] and kap[seg][3] < 0.5 * kap[seg][0]:
            flags.append(f'{seg} kappa y20 < half opening')
    if tk == 'GULL':
        if abs(om[B] / om[A] - 1) > 0.15:
            flags.append(f'margin moved {om[B] / om[A] - 1:+.0%}')
        if abs(med(cp[B]) / med(cp[A]) - 1) > 0.15:
            flags.append(f'cap moved {med(cp[B]) / med(cp[A]) - 1:+.0%}')
    lc = [math.log(b / a) for a, b in zip(cp[A], cp[B]) if a == a and b == b and a > 0 and b > 0]
    print(f"{tk}: books open y{med(opened):.2f}; kappa y1/5/10/20 " + '; '.join(f"{seg} {'/'.join(f'{v:.3f}' for v in kap[seg])}" for seg in segs)
          + '; segment margin y1/y20 ' + '; '.join(f"{seg} {mar[seg][0]:.3f}/{mar[seg][1]:.3f}" for seg in segs) + f'; company margin {fmar[0]:.3f}/{fmar[1]:.3f}')
    print(f"    real growth y1-10 / y10-20 {A} {g[A][0]:+.3f}/{g[A][1]:+.3f}, {B} {g[B][0]:+.3f}/{g[B][1]:+.3f}; op margin {om[A]:.3f} -> {om[B]:.3f}; P/E y10-20 {pe[A]:.1f} -> {pe[B]:.1f}; "
          f"cap y20 {med(cp[A]):.0f} -> {med(cp[B]):.0f} bn (log ratio {fmt(paired([0.0] * len(lc), lc), 3)}); events {ev[A]} -> {ev[B]}; whole sales {sales or 'none'}; flags {flags or '-'}")

print('\n### 2. Outlooks opened by wave 2, and existing industries\' belief step at that quarter')
belief = lambda rec: rec[1] + rec[2] / 2.0
for s in seeds:
    oa, ob = D[A, s][3], D[B, s][3]
    if not oa or not ob:
        print(f's{s}: no outlook log')
        continue
    ia = set().union(*[set(q[2]) for q in oa['quarters']])
    first = {}
    for t, qn, inds in ob['quarters']:
        for ind in inds:
            if ind not in ia and ind not in first:
                first[ind] = (qn, t)
    qa = {q[1]: q[2] for q in oa['quarters']}
    qb = {q[1]: q[2] for q in ob['quarters']}
    qs = sorted(set(qa) & set(qb))
    steps = [abs(belief(qb[q][i]) - belief(qb[p][i])) for p, q in zip(qs, qs[1:]) for i in qb[q] if i in ia and i in qb[p]]
    usual = sorted(steps)[int(0.95 * len(steps))] if steps else nan
    out = []
    for qn in sorted({v[0] for v in first.values()}):
        if qn - 1 not in qb or qn not in qb or qn not in qa or qn - 1 not in qa:
            continue
        stepb = max(abs(belief(qb[qn][i]) - belief(qb[qn - 1][i])) for i in qb[qn] if i in ia and i in qb[qn - 1])
        diff = max(abs((belief(qb[qn][i]) - belief(qb[qn - 1][i])) - (belief(qa[qn][i]) - belief(qa[qn - 1][i]))) for i in qb[qn] if i in ia and i in qa[qn] and i in qa[qn - 1] and i in qb[qn - 1])
        out.append(f'q{qn}: max existing step {stepb:.4f}, max step diff vs {A} {diff:.4f}')
    print(f"s{s}: opened " + ', '.join(f'{i} q{v[0]} (y{v[1]:.2f})' for i, v in sorted(first.items(), key=lambda x: x[1])) + f'; usual p95 step {usual:.4f}; ' + '; '.join(out))

print(f'\n### 3. Identities, every report of the segmented companies in {B}')
n = 0
e1 = e2 = 0.0
tks = set()
for s in seeds:
    for r in D[B, s][1]['rows']:
        if not r['kappa']:
            continue
        tks.add(r['tk'])
        n += 1
        e1 = max(e1, abs(sum(r['kappa'].values()) - 1))
        if r['rev'] > 0:
            e2 = max(e2, abs(sum(v['rev'] for v in r['recs'].values()) - 4 * r['rev']) / (4 * r['rev']))
owners = {x.split('|')[1].split('/')[0] for s in seeds for x in D[B, s][1].get('slash', [])}
print(f'{n} reports over {len(tks)} companies; max |sum kappa - 1| {e1:.1e}; max |record revenue - 4 rev| / 4 rev {e2:.1e}; TICKER/seg owners outside the segmented set: {sorted(owners - set(NEW + WAVE1)) or "none"}; unsegmented reports with a segment key {sum(D[B, s][1]["leaks"]["momentum"] for s in seeds)}')

print(f'\n### 4. Board, {B} - {A}, paired n={len(seeds)}')
def board(a, s):
    r, k = D[a, s][0], D[a, s][2]
    fin = r['final']
    allq = [x for v in fin.values() for x in v['q']]
    return {'cap y20 tn': sum(v['q'][-1]['price'] * v['q'][-1]['shares'] for v in fin.values() if v['q'] and not v['dead']) / 1e12,
            'defaults+deaths': len(r['defaults']) + len(r['dead']),
            'B-or-worse share': sum(1 for x in allq if x['rating'] in BAD) / len(allq),
            'median P/E y10-20': st.median([x['price'] / x['eps'] for x in allq if 10 < x['t'] <= 20 and x['eps'] > 0]),
            'median split-adj vol': st.median([math.sqrt(a_ / n_ * k['tpy']) for tk, (a_, n_, c, _) in k['rv'].items() if n_ >= k['tpy'] and not fin[tk]['dead']])}
bA = {s: board(A, s) for s in seeds}
bB = {s: board(B, s) for s in seeds}
for key in bA[seeds[0]]:
    a, b = [bA[s][key] for s in seeds], [bB[s][key] for s in seeds]
    print(f'{key} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')
print('wave-1 cap y20, log ratio B/A: ' + '; '.join(f"{tk} {fmt(paired([0.0] * len(lr), lr), 3)}" for tk in WAVE1
      for lr in [[math.log(cap20(B, s, tk) / cap20(A, s, tk)) for s in seeds if cap20(A, s, tk) > 0 and cap20(B, s, tk) > 0]]))

if PL:
    print(f'\n### 5. Segment prices in the new industries ({PL}: ovr_pl log), median over seeds by year y2/y5/y10/y15/y20 [p5 over all reports]')
    for tk in NEW:
        segs = sorted({k for s in seeds for r in rows(PL, s, tk) if r.get('pl') for k in r['pl']['prices']})
        cells = []
        for seg in segs:
            path = [med([med([r['pl']['prices'].get(seg) for r in win(rows(PL, s, tk), y) if r.get('pl')]) for s in seeds]) for y in (2, 5, 10, 15, 20)]
            allp = sorted(r['pl']['prices'][seg] for s in seeds for r in rows(PL, s, tk) if r.get('pl') and seg in r['pl']['prices'])
            lo = sum(1 for s in seeds for r in rows(PL, s, tk) if r.get('pl') for v in [r['pl']['loads'].get(seg)] if v is not None and v < 1 - 1e-9)
            cells.append(f"{seg} {'/'.join(f'{v:.3f}' for v in path)} [p5 {allp[len(allp) // 20]:.3f}; loads<1 {lo}]" if allp else f'{seg} n/a')
        print(f'{tk}: ' + '; '.join(cells))

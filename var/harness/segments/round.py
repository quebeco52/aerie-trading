#!/usr/bin/env python3
"""round.py <seeds> A B [C]: conglomerate (OWLS, TRIV) books per arm, and the TERN distress trace across arms.

OWLS/TRIV: opening (first four reports) and y20 annual revenue, EBIT margin, interest income, net income, ROE
(net income / report equity), P/E and cap (y20 from the run's quarter log), distress seeds; segment kappa and margins
y1/y20. TERN: seeds with an event of default per arm; for each such seed, the year before the first default against
the same seed in the other arms and against the arm's median over seeds without one: cargo price and load, passenger
(main) load, op margin, net debt / EBITDA, cargo revenue share (segment prices/loads need the ovr_* SegmentBooks copy).
"""
import json, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
ARMS = sys.argv[2:]
nan = float('nan')


def med(xs):
    xs = [x for x in xs if x == x]
    return st.median(xs) if xs else nan


R, G = {}, {}
for a in ARMS:
    for s in seeds:
        R[a, s] = json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1])
        g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
        for row in g['rows']:
            for key in ('kappa', 'recs', 'smar', 'segrev'):
                row[key] = row.get(key) or {}
        G[a, s] = g
rows = lambda a, s, tk: [r for r in G[a, s]['rows'] if r['tk'] == tk]


def distress(a, s, tk):
    ev = [e['t'] for e in R[a, s]['events'].get(tk, []) if 'event of default' in e['d'] and 'cured' not in e['d']]
    return min(ev) if ev else None


def books(rs):
    if len(rs) < 4:
        return None
    rev = sum(r['rev'] for r in rs)
    eq = st.fmean(r['eq'] for r in rs)
    ni = sum(r['ni'] for r in rs)
    return {'rev': rev / 1e9, 'ebit': sum(r['ebit'] for r in rs) / rev if rev else nan, 'ii': sum(r['ii'] for r in rs) / 1e9,
            'ni': ni / 1e9, 'roe': ni / eq if eq > 0 else nan}


print(f'## Conglomerates, n={len(seeds)} (median over seeds)')
print('ticker arm | opening rev bn / EBIT margin / interest income bn / NI bn / ROE | y20 same | P/E y20 | cap y20 bn | distress seeds')
for tk in ('OWLS', 'TRIV'):
    for a in ARMS:
        o = [books(rows(a, s, tk)[:4]) for s in seeds]
        e = [books([r for r in rows(a, s, tk) if 19 < r['t'] <= 20]) for s in seeds]
        f = lambda bs: ' / '.join(f"{med([b[k] for b in bs if b]):.3f}" if k in ('ebit', 'roe') else f"{med([b[k] for b in bs if b]):.1f}" for k in ('rev', 'ebit', 'ii', 'ni', 'roe'))
        pe, cap = [], []
        for s in seeds:
            q = [x for x in R[a, s]['final'][tk]['q'] if 19 < x['t'] <= 20]
            if q:
                pe += [x['price'] / x['eps'] for x in q if x['eps'] > 0]
                cap.append(q[-1]['price'] * q[-1]['shares'] / 1e9)
        print(f"{tk} {a} | {f(o)} | {f(e)} | {med(pe):.1f} | {med(cap):.0f} | {[s for s in seeds if distress(a, s, tk) is not None]}")
    a = ARMS[-1] if len(ARMS) < 3 else ARMS[1]
    for seg in ('main', 'industrial', 'consumer'):
        k = [[med([r['kappa'].get(seg, nan) for s in seeds for r in rows(a, s, tk) if y - 1 < r['t'] <= y and r['kappa']])] for y in (1, 20)]
        m = [[med([r['smar'].get(seg, nan) for s in seeds for r in rows(a, s, tk) if y - 1 < r['t'] <= y and r['smar']])] for y in (1, 20)]
        print(f'  {a} {tk}:{seg} kappa y1 {k[0][0]:.3f} y20 {k[1][0]:.3f}; margin y1 {m[0][0]:.3f} y20 {m[1][0]:.3f}')

print(f'\n## TERN distress (first event of default) per arm, n={len(seeds)}')
fail = {a: {s: distress(a, s, 'TERN') for s in seeds if distress(a, s, 'TERN') is not None} for a in ARMS}
for a in ARMS:
    print(f'{a}: {len(fail[a])} seeds {sorted(fail[a])} (first default year {", ".join(f"s{s} y{t:.1f}" for s, t in sorted(fail[a].items()))})')


def window(a, s, t0, t1):
    rs = [r for r in rows(a, s, 'TERN') if t0 < r['t'] <= t1]
    if not rs:
        return None
    pl = [r['pl'] for r in rs if r.get('pl')]
    eb = sum(r['ebitda'] for r in rs)
    tot = [sum(r['segrev'].values()) for r in rs]
    return {
        'cargo P': st.fmean(p['prices'].get('air_cargo', nan) for p in pl) if pl else nan,
        'cargo L': st.fmean(p['loads'].get('air_cargo', nan) for p in pl) if pl else nan,
        'pax L': st.fmean(p['loads'].get('main', nan) for p in pl) if pl else nan,
        'pax P': st.fmean(p['prices'].get('main', nan) for p in pl) if pl else nan,
        'op margin': st.fmean(r['om'] for r in rs),
        'ND/EBITDA': rs[-1]['nd'] / eb if eb > 0 else nan,
        'cargo rev share': st.fmean(r['segrev'].get('air_cargo', 0.0) / t for r, t in zip(rs, tot) if t > 0),
    }


print('\nyear before the first default (failing seed) | same seed and window in each arm | arm median over non-failing seeds, same window')
keys = ['cargo P', 'cargo L', 'pax P', 'pax L', 'op margin', 'ND/EBITDA', 'cargo rev share']
for a in ARMS:
    for s, t in sorted(fail[a].items()):
        line = []
        for b in ARMS:
            w = window(b, s, t - 1.0, t)
            ok = [window(b, z, t - 1.0, t) for z in seeds if z not in fail[b]]
            base = {k: med([x[k] for x in ok if x]) for k in keys}
            line.append(f'{b}: ' + ', '.join(f'{k} {w[k]:.3f}' if w else f'{k} n/a' for k in keys) + ' || others ' + ', '.join(f'{base[k]:.3f}' for k in keys))
        print(f'{a} s{s} y{t:.1f}:\n    ' + '\n    '.join(line))

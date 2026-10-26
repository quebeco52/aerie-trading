#!/usr/bin/env python3
"""div4.py <seeds> A B: venture outcomes (5b-2 with growth paths and abandonment), B vs A. Reads the VENTURE rows
(with 'exit') and the idle rows. Sections: outcomes by closeness, successful ventures' kappa/share, abandoned ventures'
kappa after exit, biggest pivots, target-industry prices after successful growth, identities after exit and at
entry. Run div2.py for entries, board and the base identities, and front.py for front-running.
"""
import json, math, os, statistics as st, sys
from collections import defaultdict

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
nan = float('nan')


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


def q(xs, p):
    xs = sorted(x for x in xs if x is not None and x == x)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else nan


V, K, KA = {}, {}, {}
for s in seeds:
    V[s] = json.load(open(f'{H}/runs/{B}-{s}.seg.json'))['ventures']
    K[s] = json.load(open(f'{H}/runs/{B}-{s}.idle.json'))['rows']
    KA[s] = json.load(open(f'{H}/runs/{A}-{s}.idle.json'))['rows']

ven = []
for s in seeds:
    for row in V[s]:
        if row['entry']:
            seg = row['entry']['segment']
            path = [x for x in V[s] if x['tk'] == row['tk'] and x['t'] >= row['t'] and any(k.endswith(seg) for k in x['vk'])]
            ab = next((x['t'] for x in path if f'carry:segment_venture_abandoned:{seg}' in x['vk'] or (x.get('exit') or {}).get('segment') == seg), None)
            ven.append({'s': s, 'tk': row['tk'], 't': row['t'], 'seg': seg, 'target': row['entry']['industry'], 'home': row['industry'],
                        'close': row['entry']['closeness'], 'path': path, 'ab': ab, 'last': V[s][-1]['t'] if V[s] else 20.0})


def at(path, t, tol=0.13):
    w = [x for x in path if abs(x['t'] - t) <= tol]
    return w[0] if w else None


def share(x, seg):
    tot = sum(x['streams'].values())
    return x['streams'].get(seg, 0.0) / tot if tot else nan


print(f'## Venture outcomes, {B} (n={len(seeds)}, {len(ven)} ventures)')
print('\n### 2a. Abandoned within 4 / 6 / 10 years of entry, by closeness (ventures with that much time left)')
for c in sorted({v['close'] for v in ven}, reverse=True) + ['all']:
    vs = [v for v in ven if c == 'all' or v['close'] == c]
    cells = []
    for y in (4, 6, 10):
        el = [v for v in vs if v['t'] + y <= 20.0]
        cells.append(f'{sum(1 for v in el if v["ab"] is not None and v["ab"] - v["t"] <= y) / len(el):.0%} of {len(el)}' if el else 'n/a')
    print(f'closeness {c}: ' + ' | '.join(cells))
yrs = [v['ab'] - v['t'] for v in ven if v['ab'] is not None]
print(f'abandoned {len(yrs)} of {len(ven)}; years to abandonment median {med(yrs):.1f}, p10 {q(yrs, .1):.1f}, p90 {q(yrs, .9):.1f}')

print('\n### 2b. Successful ventures (not abandoned, gate building at +8 y): kappa / revenue share at +4 / +8 / +12 y')
succ = []
for v in ven:
    x8 = at(v['path'], v['t'] + 8)
    if v['ab'] is None and x8 and x8['vk'].get(f'carry:segment_builds:{v["seg"]}') == 1:
        succ.append(v)
print(f'{len(succ)} successful of {sum(1 for v in ven if v["t"] + 8 <= 20)} with 8 years left')
for y in (4, 8, 12):
    k = [x['kappa'].get(v['seg'], 0.0) for v in succ for x in [at(v['path'], v['t'] + y)] if x]
    sh = [share(x, v['seg']) for v in succ for x in [at(v['path'], v['t'] + y)] if x]
    print(f'+{y} y (n {len(k)}): kappa median {med(k):.3f} p75 {q(k, .75):.3f} p90 {q(k, .9):.3f} max {max(k, default=nan):.3f}; '
          f'revenue share median {med(sh):.3f} p75 {q(sh, .75):.3f} p90 {q(sh, .9):.3f} max {max(sh, default=nan):.3f}')

print('\n### 2c. Abandoned ventures: kappa at abandonment and +4 / +8 y after')
for d in (0, 4, 8):
    k = [x['kappa'].get(v['seg'], 0.0) for v in ven if v['ab'] is not None for x in [at(v['path'], v['ab'] + d)] if x]
    # after the venture leaves the books its rows may stop: count those as 0 when the firm still reports
    gone = sum(1 for v in ven if v['ab'] is not None and v['ab'] + d <= v['last'] and at(v['path'], v['ab'] + d) is None)
    print(f'+{d} y: kappa median {med(k):.4f} p90 {q(k, .9):.4f} (n {len(k)}; {gone} more already off the books)')

print('\n### 3. Biggest pivots: revenue share at the venture\'s last report')
last = sorted(((share(v['path'][-1], v['seg']), v) for v in ven if v['path']), key=lambda x: -x[0])
for sh, v in last[:5]:
    print(f"s{v['s']} {v['tk']}: {v['home']} -> {v['target']} (closeness {v['close']}), entered y{v['t']:.1f}, revenue share {sh:.3f} at y{v['path'][-1]['t']:.1f}, kappa {v['path'][-1]['kappa'].get(v['seg'], 0):.3f}")
print(f'ventures above 50% of parent revenue at any report: {sum(1 for v in ven if any(share(x, v["seg"]) > 0.5 for x in v["path"]))}')

print('\n### 4. Successful ventures: venture price at +4 / +8 y vs entry; incumbents vs A over the same windows')
for y in (4, 8):
    vp, dd = [], []
    for v in succ:
        x0, x1 = at(v['path'], v['t'] + 1, 0.3), at(v['path'], v['t'] + y)
        p0 = (x0 or {}).get('segs', {}).get(v['seg'], {}).get('price') if x0 else None
        p1 = (x1 or {}).get('segs', {}).get(v['seg'], {}).get('price') if x1 else None
        if p0 and p1:
            vp.append(p1 - p0)
        win = lambda rows, t0, t1: [r[4] for r in rows if r[2] == v['target'] and r[1] != v['tk'] and r[4] is not None and t0 <= r[0] <= t1]
        b, a_ = win(K[v['s']], v['t'] - 1, v['t']), win(K[v['s']], v['t'] + y - 1, v['t'] + y)
        bA, aA = win(KA[v['s']], v['t'] - 1, v['t']), win(KA[v['s']], v['t'] + y - 1, v['t'] + y)
        if b and a_ and bA and aA:
            dd.append((st.fmean(a_) - st.fmean(b)) - (st.fmean(aA) - st.fmean(bA)))
    print(f'+{y} y: venture price change vs year 1 median {med(vp):+.4f} (n {len(vp)}); incumbents\' price change vs {A} median {med(dd):+.4f} mean {st.fmean(dd) if dd else nan:+.4f} (n {len(dd)})')

print('\n### 7. Identities: kappa growth after abandonment; entries while another venture is pending or learning (gap >= 1)')
grow = []
for v in ven:
    if v['ab'] is None:
        continue
    after = [x['kappa'].get(v['seg'], 0.0) for x in v['path'] if x['t'] >= v['ab']]
    for a_, b_ in zip(after, after[1:]):
        if b_ > a_ * (1 + 1e-9) + 1e-12:
            grow.append((v['s'], v['tk'], round(b_ - a_, 5)))
print(f'reports where an abandoned venture\'s kappa rose: {len(grow)} {grow[:5]}')
bad = []
for s in seeds:
    for i, row in enumerate(V[s]):
        if not row['entry']:
            continue
        seg = row['entry']['segment']
        prev = [x for x in V[s] if x['tk'] == row['tk'] and x['t'] < row['t']]
        p = prev[-1] if prev and row['t'] - prev[-1]['t'] < 0.3 else None
        if p:
            for k, val in p['vk'].items():
                if k.startswith('carry:segment_pending:') or (k.startswith('carry:segment_learning:') and 'learning_start' not in k and val >= 1.0
                                                               and f"carry:segment_venture_abandoned:{k.split('learning:')[1]}" not in p['vk']):
                    bad.append((s, row['tk'], row['t'], k.split(':', 2)[2][:40], round(val, 3) if isinstance(val, float) else val))
print(f'entries made while the previous report showed a pending venture or a learning gap >= 1: {len(bad)} {bad[:5]}')

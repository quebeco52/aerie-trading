"""compare16.py <armA> <armB>[,<armC>...] <seeds>: paired effects (B - A) for the speed check, y5 on.

Car-maker price bands, board price spread and under-build G, failures/reorgs (listed with the firm's industry
price at the event), board median P/E, cheap-minus-dear E/P quintile next-year return, default firm-sweeps, and
FALC/EIDR revenue growth less trend nominal GDP growth. Run from var/harness/autoshort.
"""
import json, math, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt

A, Bs, seeds = sys.argv[1], sys.argv[2].split(','), [int(s) for s in sys.argv[3].split(',')]


def load(arm, s):
    return json.load(open(f'runs/{arm}-{s}.short.json')), json.loads(open(f'runs/{arm}-{s}.jsonl').readline())


def industry_price(d, ticker, year):
    """The firm's own industry price level at its last report before the event, else its industry's peers'."""
    own = [r for r in d['rows'] if r[1] == ticker and r[0] <= year]
    if own:
        return own[-1][2], own[-1][3]
    return '?', None


def ep_spread_and_pe(o):
    """Board median P/E (y5+, eps > 0) and the mean over quarters of Q5-Q1 (cheap minus dear) next-year return."""
    pes, by_q = [], {}
    for tk, f in o['final'].items():
        q = f['q']
        for i, r in enumerate(q):
            if r['t'] < 5 or r['price'] <= 0:
                continue
            if r['eps'] > 0:
                pes.append(r['price'] / r['eps'])
            if i + 4 < len(q):
                n = q[i + 4]
                split = n['shares'] / r['shares'] if r['shares'] > 0 else 1.0
                split = split if split > 1.5 else 1.0  # a split or large issue; split-adjust only clear splits
                dps = sum(x['div'] / x['shares'] for x in q[i + 1:i + 5] if x['shares'] > 0)
                ret = (n['price'] * split + dps) / r['price'] - 1.0
                by_q.setdefault(round(r['t'] * 4), []).append((r['eps'] / r['price'], ret))
    spreads = []
    for k, xs in by_q.items():
        if len(xs) < 20:
            continue
        xs.sort()
        m = len(xs) // 5
        spreads.append(sum(r for _, r in xs[-m:]) / m - sum(r for _, r in xs[:m]) / m)
    pes.sort()
    return pes[len(pes) // 2], sum(spreads) / len(spreads)


def metrics(arm, s, events):
    d, o = load(arm, s)
    late = [r for r in d['rows'] if r[0] >= 5]
    ps = sorted(r[3] for r in late)
    auto = [r[3] for r in late if r[2] == 'Auto Manufacturers']
    G = [r[6] for r in late if r[6] is not None]
    t0 = min(r[0] for r in d['rows'])
    g0 = [r[14] for r in d['rows'] if r[0] - t0 < 1.0]
    t1 = max(r[0] for r in d['rows'])
    g1 = [r[14] for r in d['rows'] if t1 - r[0] < 1.0]
    gdp = 100 * math.log((sum(g1) / len(g1)) / (sum(g0) / len(g0))) / (t1 - t0)
    pe, spread = ep_spread_and_pe(o)
    m = {
        'auto firm P': sum(auto) / len(auto),
        'auto q share P>1.05': sum(p > 1.05 for p in auto) / len(auto),
        'auto q share P<0.95': sum(p < 0.95 for p in auto) / len(auto),
        'board mean |log P|': sum(abs(math.log(p)) for p in ps) / len(ps),
        'board P p5': ps[int(.05 * len(ps))], 'board P p95': ps[int(.95 * len(ps))],
        'board mean G (under-build)': sum(G) / len(G),
        'deaths+reorgs': len(o['dead']) + len(o['reorgs']),
        'median P/E y5+': pe,
        'E/P Q5-Q1 next-yr ret': spread,
        'default firm-sweeps': sum((o['defaults'] or {}).values()),
        'defaulting firms': len(o['defaults']),
    }
    for tk in ('FALC', 'EIDR'):
        q = o['final'][tk]['q']
        yrs = q[-1]['t'] - q[3]['t']
        m[f'{tk} rev - GDP %/yr'] = 100 * math.log(sum(x['rev'] for x in q[-4:]) / sum(x['rev'] for x in q[0:4])) / yrs - gdp
    for tk, yr in (o['dead'] or {}).items():
        events.append((arm, s, tk, 'BANKRUPT', yr, *industry_price(d, tk, yr)))
    for r in o['reorgs']:
        events.append((arm, s, r['t'], 'REORG', r['year'], *industry_price(d, r['t'], r['year'])))
    return m


events = []
ma = {s: metrics(A, s, events) for s in seeds}
short_seeds = [s for s in seeds if ma[s]['auto firm P'] > 1.0]
for B in Bs:
    mb = {s: metrics(B, s, events) for s in seeds}
    print(f'\n{B} - {A}, n {len(seeds)} seeds')
    for k in ma[seeds[0]]:
        a = {s: ma[s][k] for s in seeds}; b = {s: mb[s][k] for s in seeds}
        print(f'{k:28s} {A} {sum(a.values())/len(a):9.4f}  {B} {sum(b.values())/len(b):9.4f}  diff {fmt(paired(a, b))}')
    a = {s: ma[s]['auto firm P'] for s in short_seeds}; b = {s: mb[s]['auto firm P'] for s in short_seeds}
    if len(a) >= 2:
        print(f'{"auto P | head shortage":28s} {A} {sum(a.values())/len(a):9.4f}  {B} {sum(b.values())/len(b):9.4f}  diff {fmt(paired(a, b))}')
print(f'\nhead auto-shortage seeds (mean auto P > 1): {short_seeds}')
print('events (arm seed firm type year industry industryP-at-last-report; >1.03 shortage, <0.97 glut):')
for e in sorted(set(events), key=lambda e: (e[0], e[1], e[4])):
    p = e[6]
    tag = '?' if p is None else ('shortage' if p > 1.03 else 'glut' if p < 0.97 else 'balanced')
    print(f'  {e[0]:6s} s{e[1]:<3d}{e[2]:6s}{e[3]:9s}y{e[4]:<6}{e[5]:32s}{"" if p is None else f"{p:.3f}"} {tag}')

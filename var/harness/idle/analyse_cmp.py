#!/usr/bin/env python3
"""analyse_cmp.py: TERN (and FALC) under HEAD (`cmphead`) vs `cmpgate`, section m.

Window per seed: up to the first distress event in either arm (first quarter in default, or failure), else year 10;
both arms are summarised over the same window. Writes the quarterly detail to cmp_detail.out and prints the
per-seed summary and the hypothesis tests. Debt raised by purpose from event texts ("Issued $X in bonds for
recapitalization" = buybacks, "... for expansion" = capex, other borrowing = rest).
"""
import json, math, os, re, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
RUNS = [('TERN', [1, 2, 3, 4, 5, 10, 13, 16]), ('FALC', [1, 3])]
ARMS = ('cmphead', 'cmpgate')
AMT = re.compile(r'\$([\d.,]+)\s*([BMK]?)')
MULT = {'B': 1e9, 'M': 1e6, 'K': 1e3, '': 1.0}
BAD = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']


def money(text):
    m = AMT.search(text)
    return float(m.group(1).replace(',', '')) * MULT[m.group(2)] if m else 0.0


def load(arm, s, tk):
    r = json.loads(open(f'{H}/runs/{arm}-{s}-oc.jsonl').readline())
    k = json.load(open(f'{H}/runs/{arm}-{s}-oc.idle.json'))
    q = r['final'][tk]['q']
    ev = next((x['t'] for x in q if x['qid'] > 0), None)
    if ev is None and tk in r['dead']:
        ev = q[-1]['t']
    gate = [p for p in k['probe'] if p['kind'] == 'gate' and p['ticker'] == tk]
    bb = [p for p in k['probe'] if p['kind'] == 'bb' and p['ticker'] == tk]
    return r, q, ev, gate, bb, r['events'].get(tk, [])


def near(log, t):
    c = [p for p in log if p['time'] <= t + 1e-6]
    return c[-1] if c else None


def ttm(q, t, key):
    rows = [x for x in q if t - 1 < x['t'] <= t + 1e-6]
    return sum(x[key] for x in rows) if len(rows) == 4 else None


def summary(arm, s, tk, w):
    r, q, ev, gate, bb, events = load(arm, s, tk)
    rows = [x for x in q if x['t'] <= w + 1e-6]
    g = [p for p in gate if p['time'] <= w]
    b = [p for p in bb if p['time'] <= w]
    out = {'event': ev}
    out['gate pass share'] = sum(1 for p in g if p['ronp'] >= p['hurdle']) / len(g) if g else float('nan')
    out['growth capex $bn'] = sum(p['growth'] for p in g) / 1e9
    out['maint capex $bn'] = sum(p['maint'] for p in g) / 1e9
    out['mean WACC'] = st.fmean(p['wacc'] for p in g) if g else float('nan')
    out['mean CoE'] = st.fmean(p['coe'] for p in g) if g else float('nan')
    out['mean levered beta'] = st.fmean(p['beta'] for p in g) if g else float('nan')
    out['mean D/E (health)'] = st.fmean(p['de'] for p in g) if g else float('nan')
    fv = [x['x_fv'] / x['price'] for x in rows if x.get('x_fv') and x['price'] > 0]
    out['mean FV/price'] = st.fmean(fv) if fv else float('nan')
    out['mean cap $bn'] = st.fmean(x['price'] * x['shares'] for x in rows) / 1e9
    out['recap-trigger quarters'] = sum(1 for p in b if p['underlev'])
    out['valuation-trigger quarters'] = sum(1 for p in b if p['valuation'])
    out['buybacks $bn'] = sum(x['bb'] for x in rows) / 1e9
    out['dividends $bn'] = sum(x['div'] for x in rows) / 1e9
    e = [x for x in events if x['t'] <= w + 1e-6]
    out['bonds for recap $bn'] = sum(money(x['d']) for x in e if 'bonds for recapitalization' in x['d']) / 1e9
    out['bonds for expansion $bn'] = sum(money(x['d']) for x in e if 'bonds for expansion' in x['d']) / 1e9
    out['other borrowing $bn'] = sum(money(x['d']) for x in e if re.match(r'(Drew|Borrowed|Forced to borrow)', x['d'])) / 1e9
    for lag, name in ((1.0, 'w-1y'), (0.0, 'w')):
        t = w - lag
        x = min(q, key=lambda y: abs(y['t'] - t))
        eb = ttm(q, x['t'], 'ebitda')
        it = ttm(q, x['t'], 'int')
        out[f'ND/EBITDA {name}'] = (x['debt'] - x['cash']) / eb if eb and eb > 0 else float('nan')
        out[f'EBIT/int {name}'] = ttm(q, x['t'], 'ebit') / it if it and it > 0 else float('nan')
        out[f'rating {name}'] = x['rating']
        out[f'output gap {name}'] = x.get('gap', float('nan'))
    return out, (r, q, ev, gate, bb, events)


detail = open(f'{H}/cmp_detail.out', 'w')
tests = {}
for tk, seeds in RUNS:
    print(f'\n## {tk}')
    for s in seeds:
        evs = {a: load(a, s, tk)[2] for a in ARMS}
        cand = [e for e in evs.values() if e is not None]
        w = min(cand) if cand else 10.0
        sa = {a: summary(a, s, tk, w) for a in ARMS}
        print(f'seed {s}: first event HEAD {evs["cmphead"]}, gate {evs["cmpgate"]}; window to y{w:.2f}')
        for key in sa['cmphead'][0]:
            if key == 'event':
                continue
            a, b = sa['cmphead'][0][key], sa['cmpgate'][0][key]
            if isinstance(a, float):
                print(f'  {key} | {a:.3f} | {b:.3f}')
                tests.setdefault((tk, key), []).append((a, b, s, cand != []))
            else:
                print(f'  {key} | {a} | {b}')
        for a in ARMS:
            r, q, ev, gate, bb, events = sa[a][1]
            detail.write(f'\n{tk} seed {s} {a} (event {ev}); t | price | FV | cap $bn | mgmt PE | P/nEPS | WACC | CoE | beta | D/E | '
                         f'RONP | hurdle | growth | maint | underlev | valuation | bb | div | ND | ND/EBITDA | ICR(health) | cov | '
                         f'rating | spread | pl | load | filed gap\n')
            for x in q:
                if x['t'] > min(w + 1.0, 20):
                    break
                g, b = near(gate, x['t']), near(bb, x['t'])
                eb = ttm(q, x['t'], 'ebitda')
                detail.write(
                    f"{x['t']:.2f} | {x['price']:.1f} | {x.get('x_fv', 0):.1f} | {x['price'] * x['shares'] / 1e9:.1f} | "
                    f"{b['fvpe'] if b else float('nan'):.1f} | {b['pe'] if b and b['pe'] else float('nan'):.1f} | "
                    f"{g['wacc'] if g else float('nan'):.3f} | {g['coe'] if g else float('nan'):.3f} | {g['beta'] if g else float('nan'):.2f} | "
                    f"{g['de'] if g else float('nan'):.2f} | {g['ronp'] if g else float('nan'):.3f} | {g['hurdle'] if g else float('nan'):.3f} | "
                    f"{(g['growth'] / 1e9) if g else 0:.2f} | {(g['maint'] / 1e9) if g else 0:.2f} | {int(b['underlev']) if b else '-'} | "
                    f"{int(b['valuation']) if b else '-'} | {x['bb'] / 1e9:.2f} | {x['div'] / 1e9:.2f} | {(x['debt'] - x['cash']) / 1e9:.1f} | "
                    f"{((x['debt'] - x['cash']) / eb) if eb and eb > 0 else float('nan'):.2f} | {b['icr'] if b else float('nan'):.1f} | "
                    f"{b['cov'] if b else '-'} | {x['rating']} | {x['spread']:.4f} | {g['pl'] if g else 1:.3f} | {g['load'] if g else 1:.3f} | "
                    f"{(g['filed'] or 0) if g else 0:+.3f}\n")
detail.close()

print('\n## Paired across TERN seeds (gate - HEAD, same window)')
for key in ('gate pass share', 'growth capex $bn', 'mean WACC', 'mean CoE', 'mean levered beta', 'mean D/E (health)', 'mean FV/price',
            'mean cap $bn', 'recap-trigger quarters', 'buybacks $bn', 'bonds for recap $bn', 'bonds for expansion $bn',
            'other borrowing $bn', 'ND/EBITDA w-1y', 'ND/EBITDA w', 'EBIT/int w-1y'):
    v = [(a, b) for a, b, s, e in tests.get(('TERN', key), []) if not (math.isnan(a) or math.isnan(b))]
    ve = [(a, b) for a, b, s, e in tests.get(('TERN', key), []) if e and not (math.isnan(a) or math.isnan(b))]
    if len(v) > 1:
        p = paired([a for a, _ in v], [b for _, b in v])
        pe = paired([a for a, _ in ve], [b for _, b in ve]) if len(ve) > 1 else None
        print(f'{key} | all seeds {fmt(p, 3)} | event seeds ' + (fmt(pe, 3) if pe else '-'))

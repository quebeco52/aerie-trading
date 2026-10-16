"""compare.py <armA> <armB> [seeds]: per-seed means (se) of the divestiture and distress quantities, arm A vs B, with the
se of the difference taken unpaired (whole-ticker seeds diverge). Usage: python3 -I compare.py r3v4d r3v5 1-8"""
import json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
spec = sys.argv[3] if len(sys.argv) > 3 else '1-8'
seeds = list(range(int(spec.split('-')[0]), int(spec.split('-')[1]) + 1)) if '-' in spec else [int(x) for x in spec.split(',')]
NAMED = ('WADE', 'BIRD', 'BRKW', 'CASC', 'DIPR', 'FALC', 'SKUA')
BR = ('dying', 'distressed', 'premium')


def load(arm):
    out = {}
    for s in seeds:
        with open(f'{H}/runs/{arm}-{s}.jsonl') as fh:
            for line in fh:
                r = json.loads(line)
                if 'final' in r:
                    out[s] = r
                    break
    return out


def ms(xs):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    if not xs:
        return float('nan'), float('nan'), 0
    return st.mean(xs), (st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')), len(xs)


def cascades(xs):
    """Firms' 3-year windows from a sale that leave retained plant < 0.40 (counted once per starting sale run)."""
    n, i = 0, 0
    while i < len(xs):
        kept, j = 1.0, i
        while j < len(xs) and xs[j]['t'] - xs[i]['t'] <= 3.0:
            kept *= 1 - xs[j]['f']
            j += 1
        if kept < 0.40:
            n += 1
            i = j
        else:
            i += 1
    return n


def per_seed(r):
    v = {}
    qs_all = [(tk, f, q) for tk, f in r['final'].items() for q in f['q']]
    cl = [q for _, _, q in qs_all if 'dRet' in q]
    v['cls: dying'] = sum(bool(q['dDy']) for q in cl) / len(cl)
    v['cls: distressed, not dying'] = sum(bool(q['dDis']) and not q['dDy'] for q in cl) / len(cl)
    v['cls: raw distressed or dying'] = sum(bool(q['dDisRaw']) or bool(q['dDyRaw']) for q in cl) / len(cl)
    v['cls: episode open (firm-q)'] = sum(bool(q.get('dEp')) for q in cl) / len(cl)
    open_ppe = sum(f['q'][0]['ppe'] for f in r['final'].values() if f['q'])
    bytk = {}
    for x in r['sales']:
        bytk.setdefault(x['tk'], []).append(x)
    for b in BR + ('all',):
        xs = [x for x in r['sales'] if b == 'all' or x['br'] == b]
        v[f'sales {b}: n'] = len(xs)
        v[f'sales {b}: plant sold / opening'] = sum(x['f'] * x['pre']['ppe'] for x in xs) / open_ppe
    v['cascades (plant < 0.40 in 3y)'] = sum(cascades(xs) for xs in bytk.values())
    v['episodes open at y20 (firms)'] = sum(1 for f in r['final'].values() if f['q'] and f['q'][-1].get('dEp'))
    # distress elsewhere
    ev = [(tk, e['d']) for tk, es in r['events'].items() for e in es]
    v['default events (failed to repay)'] = sum(d.startswith('Failed to repay') for _, d in ev)
    v['firms ever in payment default'] = len(r['defaults'])
    v['reorganizations'] = len(r['reorgs'])
    v['firm failures'] = len(r['dead'])
    v['loss quarters, share'] = sum(q['ni'] < 0 for _, _, q in qs_all) / len(qs_all)
    nf = [q for _, f, q in qs_all if not q.get('fin') and q.get('covL') and q.get('ndE') is not None]
    v['over covenant (ND/EBITDA>limit), share'] = sum(q['ndE'] > q['covL'] for q in nf) / len(nf)
    v['equity raises (all SEOs)'] = sum(d.startswith('Issued new shares') for _, d in ev)
    v['  of which emergency or deleveraging'] = sum(d.startswith('Issued new shares') and re.search('emergency|liquidity crisis|debt burden', d) is not None for _, d in ev)
    v['bond-market shut-outs'] = sum(d.startswith('Shut out of the bond market') for _, d in ev)
    v['revolver refused'] = sum(d.startswith('Lenders refused') for _, d in ev)
    mx = []
    for tk, f in r['final'].items():
        xs = [q['ndE'] for q in f['q'] if not q.get('fin') and q.get('ndE') is not None and math.isfinite(q['ndE'])]
        if xs:
            mx.append(max(xs))
    mx.sort()
    v['max ND/EBITDA, p90 across firms'] = mx[int(0.9 * (len(mx) - 1))] if mx else float('nan')
    # board
    g = [f['q'][-1]['ppe'] / f['q'][0]['ppe'] for f in r['final'].values() if f['q'] and not f['dead'] and f['q'][0]['ppe'] > 0]
    v['median firm plant multiple y20'] = st.median(g)
    m0 = sum(f['q'][0]['price'] * f['q'][0]['shares'] for f in r['final'].values() if f['q'])
    m1 = sum(f['q'][-1]['price'] * f['q'][-1]['shares'] for f in r['final'].values() if f['q'] and not f['dead'])
    v['board mcap y20 / opening'] = m1 / m0
    return v, bytk


def pooled(runs):
    xs = [dict(x, seed=s) for s, r in runs.items() for x in r['sales']]
    out = {}
    for s, r in runs.items():
        bytk = {}
        for x in r['sales']:
            bytk.setdefault(x['tk'], []).append(x)
        for tk, ys in bytk.items():
            for i, y in enumerate(ys):
                y['rep3'] = any(0 < z['t'] - y['t'] <= 3.0 for z in ys[i + 1:])
    for b in BR + ('all',):
        ys = [x for s, r in runs.items() for x in r['sales'] if b == 'all' or x['br'] == b]
        if not ys:
            out[b] = None
            continue
        pb = [x['px'] / x['bookEq'] for x in ys if x['bookEq'] > 0]
        out[b] = (len(ys), st.mean(x['f'] for x in ys), st.median(pb) if pb else float('nan'), sum(x['rep3'] for x in ys) / len(ys),
                  st.median(x.get('tcr', x['ret']) for x in ys), st.median(x['hur'] for x in ys))
    return out


ra, rb = load(A), load(B)
pa = {s: per_seed(r) for s, r in ra.items()}
pb_ = {s: per_seed(r) for s, r in rb.items()}
print(f'== Divestiture fix: {A} -> {B}, seeds {spec} (n={len(ra)}/{len(rb)}), per-seed mean (se); diff se unpaired')
print(f'{"quantity":42}{A:>16}{B:>16}{"diff (se)":>18}{"t":>6}')
for k in pa[seeds[0]][0]:
    a = ms(pa[s][0][k] for s in pa)
    b = ms(pb_[s][0][k] for s in pb_)
    d = b[0] - a[0]
    sed = math.sqrt((a[1] if a[1] == a[1] else 0) ** 2 + (b[1] if b[1] == b[1] else 0) ** 2)
    dd = 3 if abs(a[0]) < 1 and abs(b[0]) < 1 else 2
    t = d / sed if sed > 0 else float('nan')
    print(f'{k:42}{a[0]:9.{dd}f} ({a[1]:.{dd}f}){b[0]:9.{dd}f} ({b[1]:.{dd}f}){d:+9.{dd}f} ({sed:.{dd}f}){t:6.1f}')

print(f'\n== Sales by branch, pooled over seeds: n, mean fraction, median price/book, share repeated within 3y, median return read, median hurdle')
print(f'{"branch":11}' + ''.join(f'{arm + " " + c:>12}' for arm in (A, B) for c in ('n', 'f', 'p/b', 'rep3y')) + f'{"ret "+A:>10}{"hur "+A:>10}{"ret "+B:>10}{"hur "+B:>10}')
qa, qb = pooled(ra), pooled(rb)
for b in BR + ('all',):
    row = f'{b:11}'
    for q in (qa, qb):
        x = q[b]
        row += f'{x[0]:12d}{x[1]:12.3f}{x[2]:12.2f}{x[3]:12.2f}' if x else f'{0:12d}' + ' ' * 36
    for q in (qa, qb):
        x = q[b]
        row += f'{x[4]:10.3f}{x[5]:10.3f}' if x else ' ' * 20
    print(row)

print(f'\n== Episodes open at year 20 ({B}): seed: firms')
for s, r in rb.items():
    print(f'  {s}: ' + ', '.join(tk for tk, f in r['final'].items() if f['q'] and f['q'][-1].get('dEp')))

print(f'\n== Named firms, per seed mean: sales, plant multiple y20 (min-max), default events, firms-seeds in payment default, reorgs, failures')
print(f'{"tk":6}' + ''.join(f'{arm + " " + c:>14}' for arm in (A, B) for c in ('sales', 'ppe x', 'range', 'dflt ev', 'in dflt', 'reorg', 'dead')))
for tk in NAMED:
    row = f'{tk:6}'
    for runs in (ra, rb):
        sal = [sum(x['tk'] == tk for x in r['sales']) for r in runs.values()]
        mult = [r['final'][tk]['q'][-1]['ppe'] / r['final'][tk]['q'][0]['ppe'] for r in runs.values() if tk in r['final'] and r['final'][tk]['q']]
        dev = sum(e['d'].startswith('Failed to repay') for r in runs.values() for e in r['events'].get(tk, []))
        ind = sum(tk in r['defaults'] for r in runs.values())
        reo = sum(x['t'] == tk for r in runs.values() for x in r['reorgs'])
        dead = sum(tk in r['dead'] for r in runs.values())
        row += f'{st.mean(sal):14.2f}{st.mean(mult) if mult else float("nan"):14.2f}{(f"{min(mult):.2f}-{max(mult):.2f}" if mult else "-"):>14}{dev:14d}{ind:14d}{reo:14d}{dead:14d}'
    print(row)

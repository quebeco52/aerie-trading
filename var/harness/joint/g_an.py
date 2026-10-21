#!/usr/bin/env python3
"""g_an.py <seeds> <armA> <armB>: batch G (covenant-breach capex gate funded from internal cash).
B "Forced to borrow ... penalty rates", "Failed to repay", "Shut out of the bond market" and revolver-cover events:
  count and $B by firm (top 15) per arm.
C constrained non-financial firm-quarters (head False or icr < 2.0; Reit 1.05): share by firm (top 15), and capex/dep
  median in constrained vs unconstrained quarters for those firms.
D growth: board revenue CAGR (firms alive with full history), median per-model revenue CAGR, and for named firms
  revenue and PP&E $B/q at y0/10/20 and EBIT margin mean.
E SINK capex $B/q median while crude < 75, peak revolver drawn/committed, min cash; WADE capex/dep after a
  'Grid Failure Wildfire' event.
Values are mean [min..max] over seeds. Board counts come from analyse.py, defaults from fragile_an.py."""
import collections, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:]
MODELS = json.load(open(f'{H}/runs/ticker_models.json'))
NAMED = ['ELDE', 'CASC', 'FALC', 'DIPR', 'BOWR', 'SINK', 'IBHI', 'ALBT', 'WADE']
EVT = {'penalty': 'Forced to borrow', 'missed': 'Failed to repay', 'shut out': 'Shut out of the bond market',
       'revolver cover': 'committed revolving credit facility to cover'}


def rng(xs, f='{:.2f}'):
    xs = [x for x in xs if x is not None and not (isinstance(x, float) and math.isnan(x))]
    if not xs:
        return 'n/a'
    return (f + ' [' + f + '..' + f + ']').format(st.mean(xs), min(xs), max(xs))


def med(xs):
    xs = [x for x in xs if x is not None and math.isfinite(x)]
    return st.median(xs) if xs else float('nan')


def at(q, y):
    return min(q, key=lambda x: abs(x['t'] - y)) if y else q[0]


def constrained(x, tk):
    thr = 1.05 if MODELS.get(tk, '').startswith('Reit') else 2.0
    return (x.get('head') is False) or (x.get('icr') is not None and x['icr'] < thr)


def cagr(q):
    if len(q) < 16:
        return None
    a = sum(x['rev'] for x in q[:4]); b = sum(x['rev'] for x in q[-4:]); yrs = q[-2]['t'] - q[1]['t']
    return (b / a) ** (1 / yrs) - 1 if a > 0 and b > 0 and yrs > 0 else None


def bil(d):
    m = re.search(r'\$([0-9.]+)B', d)
    return float(m.group(1)) if m else 0.0


A = {}
for arm in arms:
    ev = {k: collections.defaultdict(lambda: [0, 0.0]) for k in EVT}
    cq = collections.defaultdict(lambda: [0, 0]); cr = collections.defaultdict(lambda: ([], []))
    boardc, modelc = [], collections.defaultdict(list)
    named = collections.defaultdict(lambda: collections.defaultdict(list))
    sink, wade = collections.defaultdict(list), []
    for s in seeds:
        r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
        fin = r['final']
        for tk, L in (r.get('events') or {}).items():
            for e in L:
                for k, pat in EVT.items():
                    if pat in e['d']:
                        ev[k][tk][0] += 1; ev[k][tk][1] += bil(e['d'])
        n = len(max((v['q'] for v in fin.values()), key=len))
        a0 = b0 = 0.0
        for tk, v in fin.items():
            q = v['q']
            if not q:
                continue
            if len(q) == n and not v['dead']:
                a0 += sum(x['rev'] for x in q[:4]); b0 += sum(x['rev'] for x in q[-4:])
            g = cagr(q)
            if g is not None:
                modelc[MODELS.get(tk, '?').replace('BusinessModel', '')].append(g)
            if q[0].get('fin'):
                continue
            for x in q:
                c = constrained(x, tk)
                cq[tk][0] += c; cq[tk][1] += 1
                if x.get('dep', 0) > 0:
                    cr[tk][0 if c else 1].append(x['capex'] / x['dep'])
            if tk in NAMED:
                for y in (0, 10, 20):
                    named[tk][f'rev{y}'].append(at(q, y)['rev'] / 1e9); named[tk][f'ppe{y}'].append(at(q, y)['ppe'] / 1e9)
                named[tk]['om'].append(st.mean(x['ebit'] / x['rev'] for x in q if x['rev'] > 0))
                named[tk]['cagr'].append(g)
            if tk == 'SINK':
                bust = [x['capex'] / 1e9 for x in q if x.get('crude', 999) < 75]
                sink['capex bust'].append(med(bust) if bust else None)
                sink['capex rest'].append(med([x['capex'] / 1e9 for x in q if x.get('crude', 0) >= 75]))
                sink['bust quarters'].append(len(bust))
                sink['peak drawn/commit'].append(max((x['rev_drawn'] or 0) / x['rev_commit'] for x in q if x.get('rev_commit')))
                sink['peak drawn $B'].append(max((x['rev_drawn'] or 0) for x in q) / 1e9)
                sink['min cash $B'].append(min(x['cash'] for x in q) / 1e9)
            if tk == 'WADE':
                tg = [x['t'] for x in q if x.get('evt') and 'Grid Failure Wildfire' in str(x['evt'])]
                if tg:
                    after = [x['capex'] / x['dep'] for x in q if x['t'] >= tg[0] and x['dep'] > 0]
                    pre = [x['capex'] / x['dep'] for x in q if x['t'] < tg[0] and x['dep'] > 0]
                    wade.append((s, tg[0], med(pre), med(after), len(tg)))
        yrs = (n - 4) / 4
        boardc.append((b0 / a0) ** (1 / yrs) - 1)
    A[arm] = dict(ev=ev, cq=cq, cr=cr, boardc=boardc, modelc=modelc, named=named, sink=sink, wade=wade)

for k in EVT:
    print(f'\n## B {k} events: count / $B by firm (top 15), total over {len(seeds)} seeds')
    for arm in arms:
        d = A[arm]['ev'][k]
        tot = sum(v[0] for v in d.values()); tb = sum(v[1] for v in d.values())
        top = sorted(d.items(), key=lambda kv: -kv[1][1] or -kv[1][0])[:15]
        print(f'{arm:6s} total {tot} / ${tb:.1f}B: ' + ', '.join(f'{t} {v[0]}/{v[1]:.1f}' for t, v in top))

print('\n## C constrained non-fin quarters: share % by firm (top 15 by max over arms); capex/dep median constrained | unconstrained')
tops = sorted({t for arm in arms for t in A[arm]['cq']},
              key=lambda t: -max(A[a]['cq'][t][0] / max(A[a]['cq'][t][1], 1) for a in arms))[:15]
print(f"{'firm':5s} " + ' '.join(f'{a + " share":>11s} {a + " cx/dep c|u":>20s}' for a in arms))
for t in tops:
    cells = []
    for a in arms:
        c, nq = A[a]['cq'][t]; cc, uu = A[a]['cr'][t]
        cells.append(f'{100 * c / max(nq, 1):11.1f} {med(cc):9.2f} | {med(uu):8.2f}')
    print(f'{t:5s} ' + ' '.join(cells))
for a in arms:
    tc = sum(v[0] for v in A[a]['cq'].values()); tn = sum(v[1] for v in A[a]['cq'].values())
    print(f'{a}: board constrained share {100 * tc / tn:.1f}% of {tn} non-fin firm-quarters')

print('\n## D growth')
for a in arms:
    mc = A[a]['modelc']
    print(f"{a:6s} board revenue CAGR % {rng([100 * x for x in A[a]['boardc']])}; median per-model CAGR % "
          f"{100 * st.median(st.mean(v) for v in mc.values()):.2f}")
print(f"{'firm':5s} {'arm':6s} {'rev y0/10/20 $B/q':>28s} {'ppe y0/10/20 $B':>28s} {'EBIT m %':>8s} {'CAGR %':>7s}")
for t in NAMED:
    for a in arms:
        d = A[a]['named'][t]
        if not d:
            continue
        rv = '/'.join(f"{st.mean(d[f'rev{y}']):.2f}" for y in (0, 10, 20))
        pp = '/'.join(f"{st.mean(d[f'ppe{y}']):.1f}" for y in (0, 10, 20))
        print(f"{t:5s} {a:6s} {rv:>28s} {pp:>28s} {100 * st.mean(d['om']):8.1f} {100 * st.mean(x for x in d['cagr'] if x is not None):7.2f}"
              f"  (ppe y20 min {min(d['ppe20']):.1f}, rev y20 min {min(d['rev20']):.2f})")

print('\n## E SINK and WADE')
for a in arms:
    sk = A[a]['sink']
    print(f'{a:6s} SINK ' + '; '.join(f'{k} {rng(v)}' for k, v in sk.items()))
    print(f'{a:6s} WADE grid failure (seed, t, capex/dep median before | after, n events): ' +
          (', '.join(f's{s} y{t:.1f} {b:.2f}|{c:.2f} ({k})' for s, t, b, c, k in A[a]['wade']) or 'none'))

#!/usr/bin/env python3
"""Price-war penalty removal: SKUA and LOON per arm and seed, plus board movers between two arms.

Usage: python3 -I compare_r3_pricewar.py <armA> <armB> [seeds...]   (HEALTH=1 runs of NewFirmsHarnessTest.php)
War quarters are those whose `reg.price_war` clock is > 0. Plant sales are a proxy: quarters where reported PP&E
falls more than 10% on the quarter (the harness does not capture DIVESTITURE event lines).
"""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
seeds = [int(s) for s in sys.argv[3:]] or [1, 2, 3, 4]


def load(arm, s):
    return json.loads(open(os.path.join(H, 'runs', f'{arm}-{s}.jsonl')).readline())


def onsets(xs):
    return sum(1 for i, x in enumerate(xs) if x and (i == 0 or not xs[i - 1]))


def firm(r, t):
    q = r['final'][t]['q']
    ev = [e['d'].lower() for e in r['events'].get(t, [])]
    war = [x['reg'].get('price_war', 0) > 0 for x in q]
    omw = [x['om'] for x, w in zip(q, war) if w]
    omp = [x['om'] for x, w in zip(q, war) if not w]
    nde = [x['ndE'] for x in q if x.get('ndE') is not None and math.isfinite(x['ndE'])]
    icr = [x['icr'] for x in q if x.get('icr') is not None and math.isfinite(x['icr'])]
    ppe = [x['ppe'] for x in q]
    return {
        'p50': st.median(x['om'] for x in q),
        'war': st.mean(omw) if omw else float('nan'), 'peace': st.mean(omp),
        'cost': (st.mean(omw) - st.mean(omp)) if omw else float('nan'),
        'loss': st.mean(x['ni'] < 0 for x in q),
        'nde': max(nde), 'brq': sum(1 for x in q if x.get('ndE') is not None and x.get('covL') and x['ndE'] > x['covL']),
        'icr': min(icr) if icr else float('nan'),
        'shut': sum('shut out' in d for d in ev), 'emerg': sum('emergency' in d for d in ev),
        'dflt': sum(('default' in d or 'chapter' in d or 'liquidat' in d) and 'cured' not in d for d in ev),
        'plant': sum(1 for a, b in zip(ppe, ppe[1:]) if a > 0 and b < 0.9 * a),
        'wars': onsets(war), 'wshare': st.mean(war), 'dead': int(r['final'][t]['dead']),
    }


R = {(arm, s): load(arm, s) for arm in (A, B) for s in seeds}
cols = [('p50', 'op margin p50', '%.3f'), ('war', 'mean om, war qtrs', '%.3f'), ('peace', 'mean om, peace qtrs', '%.3f'),
        ('cost', 'war - peace (margin pts)', '%+.3f'), ('loss', 'loss-quarter share', '%.3f'), ('nde', 'peak ND/EBITDA', '%.2f'),
        ('brq', 'quarters over covenant', '%d'), ('icr', 'min interest cover', '%.2f'), ('shut', 'bond shut-outs', '%d'),
        ('emerg', 'emergency equity raises', '%d'), ('plant', 'PP&E drops >10% (plant-sale proxy)', '%d'),
        ('dflt', 'default lines', '%d'), ('dead', 'dead', '%d'), ('wars', 'price wars (onsets)', '%d'), ('wshare', 'war-quarter share', '%.3f')]
print('%-36s' % 'seed' + ''.join(('%s-%d' % (arm[-2:], s)).rjust(8) for s in seeds for arm in (A, B)) + ('mean ' + A[-2:]).rjust(9) + ('mean ' + B[-2:]).rjust(9))
for t in ('SKUA', 'LOON'):
    print(t)
    F = {k: firm(R[k], t) for k in R}
    for key, lab, f in cols:
        cells = ''.join((f % F[(arm, s)][key]).rjust(8) for s in seeds for arm in (A, B))
        means = ''.join(('%.3f' % st.mean(F[(arm, s)][key] for s in seeds)).rjust(9) for arm in (A, B))
        print(' %-35s' % lab + cells + means)

print('Board')
for s in seeds:
    a, b = R[(A, s)], R[(B, s)]
    print(' seed %d failures %s %d %s / %s %d %s; defaults %s / %s' % (s, A, len(a['dead']), ','.join(a['dead']) or '-', B, len(b['dead']),
          ','.join(b['dead']) or '-', a.get('defaults') or '-', b.get('defaults') or '-'))
# Other firms: change in median op margin and in log final price, per seed, then mean over seeds.
mv = []
for t in R[(A, seeds[0])]['final']:
    if t in ('SKUA', 'LOON'):
        continue
    dm, dp = [], []
    for s in seeds:
        fa, fb = R[(A, s)]['final'].get(t), R[(B, s)]['final'].get(t)
        if not fa or not fb or not fa['q'] or not fb['q']:
            continue
        dm.append(st.median(x['om'] for x in fb['q']) - st.median(x['om'] for x in fa['q']))
        if fa['px'] > 0 and fb['px'] > 0 and not fa['dead'] and not fb['dead']:
            dp.append(math.log(fb['px'] / fa['px']))
    if dm:
        mv.append((t, st.mean(dm), max(dm, key=abs), st.mean(dp) if dp else float('nan')))
am = [abs(x[1]) for x in mv]
ap = [abs(x[3]) for x in mv if math.isfinite(x[3])]
print(' other firms %d: mean |d median om| %.4f, max %.4f; mean |d log px y20| %.3f' % (len(mv), st.mean(am), max(am), st.mean(ap)))
print(' largest movers by |d median om| (ticker, mean d, largest single-seed d, mean d log px):')
for t, m, mx, p in sorted(mv, key=lambda x: -abs(x[1]))[:6]:
    print('   %-6s %+.4f %+.4f %+.3f' % (t, m, mx, p))
for t, m, mx, p in sorted([x for x in mv if math.isfinite(x[3])], key=lambda x: -abs(x[3]))[:4]:
    print('   px   %-6s %+.4f %+.4f %+.3f' % (t, m, mx, p))

# First war episode: arms are identical up to its onset (HEAD vs working tree), so this pairs exactly.
print('First war episode, paired (mean om over the episode in arm A, its quarters in arm B)')
for t in ('SKUA', 'LOON'):
    d = []
    for s in seeds:
        qa, qb = R[(A, s)]['final'][t]['q'], R[(B, s)]['final'][t]['q']
        w = [x['reg'].get('price_war', 0) > 0 for x in qa]
        i0 = next((i for i, x in enumerate(w) if x), None)
        if i0 is None:
            continue
        i1 = next((i for i in range(i0, len(w)) if not w[i]), len(w))
        a, b = st.mean(x['om'] for x in qa[i0:i1]), st.mean(x['om'] for x in qb[i0:i1])
        pre = st.mean(x['om'] for x in qa[max(0, i0 - 4):i0])
        d.append(b - a)
        print('  %s seed %d q%d-%d: pre-war %.3f  %s %.3f  %s %.3f  diff %+.3f' % (t, s, i0, i1 - 1, pre, A, a, B, b, b - a))
    print('  %s mean diff %+.4f se %.4f n %d' % (t, st.mean(d), st.stdev(d) / math.sqrt(len(d)), len(d)))

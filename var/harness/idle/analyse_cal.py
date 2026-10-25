#!/usr/bin/env python3
"""analyse_cal.py [seeds] [arm] [refarm] [tag]: calibrated-outlook check.

1. Per drift category (read from ovr_cal's IndustryOutlooks table): median and max |b - x| over all industry-quarters,
   and the SD of exp(x) across industries at the last quarter (pooled over seeds), with the random-walk reference.
2. Glut share: industry-quarters (quarter = floor(4t)) in which any listed firm reported idle plant, arm vs refarm.
3. Events (defaults, reorganisations, failures) with industry, year, true level and belief, for arm and refarm.
"""
import json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4,5,6,7,8').split(',')]
arm = sys.argv[2] if len(sys.argv) > 2 else 'cal'
ref = sys.argv[3] if len(sys.argv) > 3 else 'off'
tag = sys.argv[4] if len(sys.argv) > 4 else '-oc'

table = {m.group(1): float(m.group(2)) for m in re.finditer(r"'([^']+)' => \['drift_vol' => ([0-9.]+)",
                                                            open(f'{H}/ovr_cal/Data/Company/IndustryOutlooks.php').read())}
CAT = {0.025: 'staple', 0.04: 'steady', 0.06: 'shifting', 0.09: 'volatile'}
cat = lambda ind: CAT.get(table.get(ind, 0.0), 'off')

err, ex20 = {}, {}
for s in seeds:
    q = json.load(open(f'{H}/runs/{arm}-{s}{tag}.outlook.json'))['quarters']
    for _, _, rows in q:
        for ind, r in rows.items():
            err.setdefault(cat(ind), []).append(abs(r[1] + r[2] / 2.0 - r[0]))
    for ind, r in q[-1][2].items():
        ex20.setdefault(cat(ind), []).append(math.exp(r[0]))
print(f'category | industries | median |b-x| | max |b-x| | sd exp(x) y20 | random-walk ref')
for c, d in [('staple', 0.025), ('steady', 0.04), ('shifting', 0.06), ('volatile', 0.09), ('off', 0.0)]:
    if c in err:
        n = len(ex20[c]) // len(seeds)
        print(f'{c} | {n} | {st.median(err[c]):.3f} | {max(err[c]):.3f} | {st.pstdev(ex20[c]):.3f} | {d * math.sqrt(20):.3f}')


def glut(a, s):
    rows = json.load(open(f'{H}/runs/{a}-{s}{tag}.idle.json'))['rows']
    iq, idle = set(), set()
    for r in rows:
        if r[4] is None:
            continue
        key = (r[2], int(r[0] * 4))
        iq.add(key)
        if r[3]:
            idle.add(key)
    return len(idle) / len(iq)


g = {a: [glut(a, s) for s in seeds] for a in (ref, arm)}
d = [y - x for x, y in zip(g[ref], g[arm])]
se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
print(f'\nglut share of industry-quarters | {ref} {st.fmean(g[ref]):.4f} | {arm} {st.fmean(g[arm]):.4f} | '
      f'{st.fmean(d):+.4f} ({se:.4f}) t {st.fmean(d) / se if se else float("nan"):.1f} n {len(d)}')

print('\nevents: arm seed firm industry (category): kind year (true, belief)')
for a in (ref, arm):
    for s in seeds:
        r = json.loads(open(f'{H}/runs/{a}-{s}{tag}.jsonl').readline())
        ind = {x[1]: x[2] for x in json.load(open(f'{H}/runs/{a}-{s}{tag}.idle.json'))['rows']}
        oq = json.load(open(f'{H}/runs/{a}-{s}{tag}.outlook.json'))['quarters']

        def tb(i, t):
            c = [rows[i] for tt, _, rows in oq if tt <= t and i in rows]
            return f'true {math.exp(c[-1][0]):.2f}, belief {math.exp(c[-1][1] + c[-1][2] / 2):.2f}' if c else 'not tracked'
        ev = {}
        for tk in (r['defaults'] or {}):
            q = r['final'][tk]['q']
            ev.setdefault(tk, []).append(('default', next((x['t'] for x in q if x['qid'] > 0), q[-1]['t'])))
        for e in r['reorgs']:
            ev.setdefault(e['t'], []).append(('reorg', e['year']))
        for tk in r['dead']:
            ev.setdefault(tk, []).append(('failed', r['final'][tk]['q'][-1]['t']))
        for tk, es in ev.items():
            i = ind.get(tk, '?')
            print(f'{a} s{s} {tk} {i} ({cat(i)}): ' + '; '.join(f'{k} y{t:.1f} ({tb(i, t)})' for k, t in es))

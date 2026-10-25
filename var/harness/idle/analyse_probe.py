#!/usr/bin/env python3
"""analyse_probe.py [seed] [ticker] [y0] [y1]: per-year growth-capex gate and funding for one firm, from the `probe`
arm (ovr_probe gate log in runs/probe-<seed>.idle.json, quarter rows in runs/probe-<seed>.jsonl). Also checks that
the probe run's OUT record equals the fix arm's (probe read-only)."""
import json, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seed = int(sys.argv[1]) if len(sys.argv) > 1 else 3
tk = sys.argv[2] if len(sys.argv) > 2 else 'FALC'
y0, y1 = (int(sys.argv[3]), int(sys.argv[4])) if len(sys.argv) > 4 else (2, 9)

r = json.loads(open(f'{H}/runs/probe-{seed}.jsonl').readline())
f = json.loads(open(f'{H}/runs/fix-{seed}.jsonl').readline())
r.pop('secs', None); f.pop('secs', None)
print(f'probe read-only (probe vs fix OUT, seed {seed}): {r == f}')
tpy = r['tpy']
g = json.load(open(f'{H}/runs/probe-{seed}.idle.json'))['probe']
q = r['final'][tk]['q']
print(f'{tk} seed {seed} | roic | RONP | hurdle | wacc | gate margin min | gate pass q | growth $bn | maint $bn | '
      f'sched $bn | budget $bn | funds $bn | div+bb $bn | NI $bn | net debt $bn | EBIT/int | rating end')
for y in range(y0, y1 + 1):
    gs = [x for x in g if y - 1 < x['tick'] / tpy <= y]
    qs = [x for x in q if y - 1 < x['t'] <= y]
    if not gs or not qs:
        continue
    m = lambda k: st.fmean(x[k] for x in gs)
    sm = lambda k: sum(x[k] for x in gs) / 1e9
    margin = min(x['ronp'] - x['hurdle'] for x in gs)
    passed = sum(1 for x in gs if x['ronp'] >= x['hurdle'])
    budget = sum(x['budget'] or 0.0 for x in gs) / 1e9
    intr = sum(x['int'] for x in qs)
    cov = sum(x['ebit'] for x in qs) / intr if intr > 0 else float('inf')
    print(f"y{y} | {m('roic'):.3f} | {m('ronp'):.3f} | {m('hurdle'):.3f} | {m('wacc'):.3f} | {margin:+.3f} | "
          f"{passed}/{len(gs)} | {sm('growth'):.2f} | {sm('maint'):.2f} | {sm('sched'):.2f} | {budget:.2f} | "
          f"{sm('funds'):.2f} | {sum(x['div'] + x['bb'] for x in qs) / 1e9:.2f} | {sum(x['ni'] for x in qs) / 1e9:.2f} | "
          f"{(qs[-1]['debt'] - qs[-1]['cash']) / 1e9:.1f} | {cov:.2f} | {qs[-1]['rating']}")
